<?php
/**
 * Files & Media handler.
 * Manages media library integration and sync buttons for WordPress attachments.
 */
class wp4shpyFilesMedia {

    /** @var wp4shpyApiClient */
    private $apiClient;

    public function __construct() {
        $this->apiClient = new wp4shpyApiClient();
    }

    /**
     * Initialize hooks if Files & Media feature is enabled.
     */
    public function init() {
        if (get_option('wp4shpy_files_enabled', '1') !== '1') {
            return;
        }

        // Add sync column to media library
        add_filter('manage_media_columns', [$this, 'addSyncColumn']);
        add_action('manage_media_custom_column', [$this, 'renderSyncColumn'], 10, 2);

        // Add sync action to media row actions
        add_filter('media_row_actions', [$this, 'addSyncRowAction'], 10, 2);

        // Handle manual sync from media library
        add_action('admin_init', [$this, 'handleMediaSync']);

        // AJAX handler for media sync
        add_action('wp_ajax_wp4shpy_sync_media', [$this, 'ajaxSyncMedia']);

        // Add bulk action for media
        add_filter('bulk_actions-upload', [$this, 'addBulkSyncAction']);
        add_filter('handle_bulk_actions-upload', [$this, 'handleBulkSync'], 10, 3);

        // Admin notices for sync results
        add_action('admin_notices', [$this, 'showAdminNotices']);

        // Attachment detail view: add sync button to media modal
        add_filter('attachment_fields_to_edit', [$this, 'addAttachmentSyncField'], 10, 2);

        // Attachment edit page: add sync button in submitbox
        add_action('attachment_submitbox_misc_actions', [$this, 'renderAttachmentSyncButton']);

        // Enqueue media library scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueueMediaScripts']);
    }

    /**
     * Add Shopify sync column to media library.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function addSyncColumn($columns) {
        $columns['wp4shpy_sync'] = '<span class="dashicons dashicons-store" title="' . esc_attr__('Shopify Sync', 'wp4shpy') . '" style="color: #96bf48;"></span>';
        return $columns;
    }

    /**
     * Render sync status in media library column.
     *
     * @param string $columnName Column name.
     * @param int    $attachmentId Attachment ID.
     */
    public function renderSyncColumn($columnName, $attachmentId) {
        if ($columnName !== 'wp4shpy_sync') {
            return;
        }

        // Only show for images
        if (!wp_attachment_is_image($attachmentId)) {
            echo '<span class="dashicons dashicons-minus" style="color: #ddd;" title="' . esc_attr__('Not an image', 'wp4shpy') . '"></span>';
            return;
        }

        $shopifyFileId = get_post_meta($attachmentId, '_wp4shpy_file_id', true);
        $cdnUrl = get_post_meta($attachmentId, '_wp4shpy_cdn_url', true);

        if (!empty($shopifyFileId) && !empty($cdnUrl)) {
            echo '<a href="' . esc_url($cdnUrl) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__('Synced to Shopify CDN', 'wp4shpy') . '">';
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>';
            echo '</a>';
        } else {
            echo '<span class="dashicons dashicons-upload" style="color: #ccc;" title="' . esc_attr__('Not synced to Shopify', 'wp4shpy') . '"></span>';
        }
    }

    /**
     * Add sync action to media row actions.
     *
     * @param array   $actions Existing row actions.
     * @param WP_Post $post    Attachment post object.
     * @return array Modified row actions.
     */
    public function addSyncRowAction($actions, $post) {
        if (!$this->apiClient->isConfigured()) {
            return $actions;
        }

        if (!wp_attachment_is_image($post->ID)) {
            return $actions;
        }

        $shopifyFileId = get_post_meta($post->ID, '_wp4shpy_file_id', true);
        $syncUrl = wp_nonce_url(
            add_query_arg([
                'action' => 'wp4shpy_sync_media',
                'attachment_id' => $post->ID,
            ], admin_url('upload.php')),
            'wp4shpy_sync_media_' . $post->ID
        );

        $label = !empty($shopifyFileId) ? __('Re-sync to Shopify', 'wp4shpy') : __('Sync to Shopify', 'wp4shpy');
        $actions['wp4shpy_sync'] = sprintf(
            '<a href="%s" style="color: #96bf48;">%s</a>',
            esc_url($syncUrl),
            $label
        );

        // Show CDN link if synced
        if (!empty($shopifyFileId)) {
            $cdnUrl = get_post_meta($post->ID, '_wp4shpy_cdn_url', true);
            if ($cdnUrl) {
                $actions['wp4shpy_view'] = sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4;">%s</a>',
                    esc_url($cdnUrl),
                    __('View on Shopify CDN', 'wp4shpy')
                );
            }
        }

        return $actions;
    }

    /**
     * Handle manual media sync from upload.php.
     */
    public function handleMediaSync() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'wp4shpy_sync_media') {
            return;
        }
        if (!isset($_GET['attachment_id'])) {
            return;
        }

        $attachmentId = absint($_GET['attachment_id']);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wp4shpy_sync_media_' . $attachmentId)) {
            wp_die(__('Security check failed', 'wp4shpy'));
        }

        if (!current_user_can('upload_files')) {
            wp_die(__('You do not have permission to sync media', 'wp4shpy'));
        }

        // Trigger sync
        $result = $this->syncAttachment($attachmentId);

        $queryArg = $result ? 'wp4shpy_media_synced' : 'wp4shpy_media_error';
        wp_redirect(add_query_arg($queryArg, '1', admin_url('upload.php')));
        exit;
    }

    /**
     * AJAX handler for media sync.
     */
    public function ajaxSyncMedia() {
        check_ajax_referer('wp4shpy_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $attachmentId = absint($_POST['attachment_id'] ?? 0);
        if (!$attachmentId) {
            wp_send_json_error(__('Invalid attachment ID', 'wp4shpy'));
        }

        $result = $this->syncAttachment($attachmentId);

        if ($result) {
            $cdnUrl = get_post_meta($attachmentId, '_wp4shpy_cdn_url', true);
            wp_send_json_success([
                'cdnUrl' => $cdnUrl,
                'fileId' => get_post_meta($attachmentId, '_wp4shpy_file_id', true),
            ]);
        } else {
            wp_send_json_error(__('Sync failed', 'wp4shpy'));
        }
    }

    /**
     * Sync a single attachment to Shopify.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool Success status.
     */
    private function syncAttachment($attachmentId) {
        if (!wp_attachment_is_image($attachmentId)) {
            return false;
        }

        $imagesHandler = new wp4shpyImages();
        return $imagesHandler->syncImage($attachmentId);
    }

    /**
     * Add bulk sync action to media library.
     *
     * @param array $actions Existing bulk actions.
     * @return array Modified bulk actions.
     */
    public function addBulkSyncAction($actions) {
        if ($this->apiClient->isConfigured()) {
            $actions['wp4shpy_bulk_sync'] = __('Sync to Shopify', 'wp4shpy');
        }
        return $actions;
    }

    /**
     * Handle bulk sync action.
     *
     * @param string $redirectTo Redirect URL.
     * @param string $action     Action name.
     * @param array  $postIds    Selected post IDs.
     * @return string Modified redirect URL.
     */
    public function handleBulkSync($redirectTo, $action, $postIds) {
        if ($action !== 'wp4shpy_bulk_sync') {
            return $redirectTo;
        }

        $synced = 0;
        $failed = 0;

        foreach ($postIds as $attachmentId) {
            if (wp_attachment_is_image($attachmentId)) {
                if ($this->syncAttachment($attachmentId)) {
                    $synced++;
                } else {
                    $failed++;
                }
            }
        }

        return add_query_arg([
            'wp4shpy_bulk_synced' => $synced,
            'wp4shpy_bulk_failed' => $failed,
        ], $redirectTo);
    }

    /**
     * Show admin notices for sync results.
     */
    public function showAdminNotices() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'upload') {
            return;
        }

        if (isset($_GET['wp4shpy_media_synced'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e('Image synced to Shopify successfully!', 'wp4shpy');
            echo '</p></div>';
        }

        if (isset($_GET['wp4shpy_media_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            esc_html_e('Failed to sync image to Shopify.', 'wp4shpy');
            echo '</p></div>';
        }

        if (isset($_GET['wp4shpy_bulk_synced'])) {
            $synced = absint($_GET['wp4shpy_bulk_synced']);
            $failed = absint($_GET['wp4shpy_bulk_failed'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                esc_html__('Bulk sync completed: %d images synced, %d failed.', 'wp4shpy'),
                $synced,
                $failed
            );
            echo '</p></div>';
        }
    }

    /**
     * Add sync field to attachment detail view in media modal.
     *
     * @param array   $formFields Existing form fields.
     * @param WP_Post $post       Attachment post object.
     * @return array Modified form fields.
     */
    public function addAttachmentSyncField($formFields, $post) {
        if (!$this->apiClient->isConfigured()) {
            return $formFields;
        }

        if (!wp_attachment_is_image($post->ID)) {
            return $formFields;
        }

        $shopifyFileId = get_post_meta($post->ID, '_wp4shpy_file_id', true);
        $cdnUrl = get_post_meta($post->ID, '_wp4shpy_cdn_url', true);

        $buttonLabel = !empty($shopifyFileId) ? __('Re-sync to Shopify', 'wp4shpy') : __('Sync to Shopify', 'wp4shpy');
        $statusHtml = '';

        if (!empty($shopifyFileId) && !empty($cdnUrl)) {
            $statusHtml = sprintf(
                '<span class="wp4shpy-sync-status synced"><span class="dashicons dashicons-yes-alt"></span> %s</span>
                <a href="%s" target="_blank" rel="noopener noreferrer" class="wp4shpy-cdn-link">%s</a>',
                esc_html__('Synced', 'wp4shpy'),
                esc_url($cdnUrl),
                esc_html__('View on CDN', 'wp4shpy')
            );
        } else {
            $statusHtml = sprintf(
                '<span class="wp4shpy-sync-status not-synced"><span class="dashicons dashicons-upload"></span> %s</span>',
                esc_html__('Not synced', 'wp4shpy')
            );
        }

        $html = sprintf(
            '<div class="wp4shpy-attachment-sync" data-attachment-id="%d">
                <div class="wp4shpy-sync-info">%s</div>
                <button type="button" class="button wp4shpy-sync-btn" data-attachment-id="%d">
                    <span class="dashicons dashicons-store"></span> %s
                </button>
                <span class="spinner"></span>
            </div>',
            $post->ID,
            $statusHtml,
            $post->ID,
            esc_html($buttonLabel)
        );

        $formFields['wp4shpy_sync'] = [
            'label' => __('Shopify', 'wp4shpy'),
            'input' => 'html',
            'html'  => $html,
        ];

        return $formFields;
    }

    /**
     * Render sync button in attachment edit page submitbox.
     *
     * @param WP_Post $post Attachment post object.
     */
    public function renderAttachmentSyncButton($post) {
        if (!$this->apiClient->isConfigured()) {
            return;
        }

        if (!wp_attachment_is_image($post->ID)) {
            return;
        }

        $shopifyFileId = get_post_meta($post->ID, '_wp4shpy_file_id', true);
        $cdnUrl = get_post_meta($post->ID, '_wp4shpy_cdn_url', true);

        $syncUrl = wp_nonce_url(
            add_query_arg([
                'action' => 'wp4shpy_sync_media',
                'attachment_id' => $post->ID,
            ], admin_url('upload.php')),
            'wp4shpy_sync_media_' . $post->ID
        );

        $buttonLabel = !empty($shopifyFileId) ? __('Re-sync to Shopify', 'wp4shpy') : __('Sync to Shopify', 'wp4shpy');
        ?>
        <div class="misc-pub-section wp4shpy-attachment-sync-section">
            <span class="dashicons dashicons-store" style="color: #96bf48;"></span>
            <strong><?php esc_html_e('Shopify:', 'wp4shpy'); ?></strong>
            <?php if (!empty($shopifyFileId) && !empty($cdnUrl)) : ?>
                <span style="color: #46b450;"><?php esc_html_e('Synced', 'wp4shpy'); ?></span>
                <br>
                <a href="<?php echo esc_url($cdnUrl); ?>" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4;">
                    <?php esc_html_e('View on Shopify CDN', 'wp4shpy'); ?>
                    <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-bottom;"></span>
                </a>
            <?php else : ?>
                <span style="color: #ddd;"><?php esc_html_e('Not synced', 'wp4shpy'); ?></span>
            <?php endif; ?>
            <br>
            <a href="<?php echo esc_url($syncUrl); ?>" class="button button-small" style="margin-top: 5px;">
                <?php echo esc_html($buttonLabel); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Enqueue scripts for media library.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueueMediaScripts($hook) {
        if (!in_array($hook, ['upload.php', 'post.php', 'post-new.php'], true)) {
            return;
        }

        if (!$this->apiClient->isConfigured()) {
            return;
        }

        $pluginUrl = wp4shpy_plugin_url();

        wp_enqueue_style(
            'wp4shpy-media',
            $pluginUrl . 'admin/css/media.css',
            [],
            wp4shpy_VERSION . '.' . filemtime(wp4shpy_PLUGIN_DIR . 'admin/css/media.css')
        );

        wp_enqueue_script(
            'wp4shpy-media',
            $pluginUrl . 'admin/js/media.js',
            ['jquery', 'media-views'],
            wp4shpy_VERSION . '.' . filemtime(wp4shpy_PLUGIN_DIR . 'admin/js/media.js'),
            true
        );

        wp_localize_script('wp4shpy-media', 'wp4shpyMedia', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wp4shpy_nonce'),
            'i18n'    => [
                'syncing'     => __('Syncing...', 'wp4shpy'),
                'synced'      => __('Synced', 'wp4shpy'),
                'syncError'   => __('Sync failed', 'wp4shpy'),
                'syncBtn'     => __('Sync to Shopify', 'wp4shpy'),
                'resyncBtn'   => __('Re-sync to Shopify', 'wp4shpy'),
                'viewOnCdn'   => __('View on CDN', 'wp4shpy'),
                'notSynced'   => __('Not synced', 'wp4shpy'),
            ],
        ]);
    }
}
