<?php
/**
 * Files & Media handler.
 * Manages media library integration and sync buttons for WordPress attachments.
 */
class WashFilesMedia {

    /** @var WashApiClient */
    private $apiClient;

    public function __construct() {
        $this->apiClient = new WashApiClient();
    }

    /**
     * Initialize hooks if Files & Media feature is enabled.
     */
    public function init() {
        if (get_option('wash_files_enabled', '1') !== '1') {
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
        add_action('wp_ajax_wash_sync_media', [$this, 'ajaxSyncMedia']);

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
        $columns['wash_sync'] = '<span class="dashicons dashicons-store" title="' . esc_attr__('Shopify Sync', 'wash') . '" style="color: #96bf48;"></span>';
        return $columns;
    }

    /**
     * Render sync status in media library column.
     *
     * @param string $columnName Column name.
     * @param int    $attachmentId Attachment ID.
     */
    public function renderSyncColumn($columnName, $attachmentId) {
        if ($columnName !== 'wash_sync') {
            return;
        }

        // Only show for images
        if (!wp_attachment_is_image($attachmentId)) {
            echo '<span class="dashicons dashicons-minus" style="color: #ddd;" title="' . esc_attr__('Not an image', 'wash') . '"></span>';
            return;
        }

        $shopifyFileId = get_post_meta($attachmentId, '_wash_file_id', true);
        $cdnUrl = get_post_meta($attachmentId, '_wash_cdn_url', true);

        if (!empty($shopifyFileId) && !empty($cdnUrl)) {
            echo '<a href="' . esc_url($cdnUrl) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__('Synced to Shopify CDN', 'wash') . '">';
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>';
            echo '</a>';
        } else {
            echo '<span class="dashicons dashicons-upload" style="color: #ccc;" title="' . esc_attr__('Not synced to Shopify', 'wash') . '"></span>';
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

        $shopifyFileId = get_post_meta($post->ID, '_wash_file_id', true);
        $syncUrl = wp_nonce_url(
            add_query_arg([
                'action' => 'wash_sync_media',
                'attachment_id' => $post->ID,
            ], admin_url('upload.php')),
            'wash_sync_media_' . $post->ID
        );

        $label = !empty($shopifyFileId) ? __('Re-sync to Shopify', 'wash') : __('Sync to Shopify', 'wash');
        $actions['wash_sync'] = sprintf(
            '<a href="%s" style="color: #96bf48;">%s</a>',
            esc_url($syncUrl),
            $label
        );

        // Show CDN link if synced
        if (!empty($shopifyFileId)) {
            $cdnUrl = get_post_meta($post->ID, '_wash_cdn_url', true);
            if ($cdnUrl) {
                $actions['wash_view'] = sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4;">%s</a>',
                    esc_url($cdnUrl),
                    __('View on Shopify CDN', 'wash')
                );
            }
        }

        return $actions;
    }

    /**
     * Handle manual media sync from upload.php.
     */
    public function handleMediaSync() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'wash_sync_media') {
            return;
        }
        if (!isset($_GET['attachment_id'])) {
            return;
        }

        $attachmentId = absint($_GET['attachment_id']);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wash_sync_media_' . $attachmentId)) {
            wp_die(__('Security check failed', 'wash'));
        }

        if (!current_user_can('upload_files')) {
            wp_die(__('You do not have permission to sync media', 'wash'));
        }

        // Trigger sync
        $result = $this->syncAttachment($attachmentId);

        $queryArg = $result ? 'wash_media_synced' : 'wash_media_error';
        wp_redirect(add_query_arg($queryArg, '1', admin_url('upload.php')));
        exit;
    }

    /**
     * AJAX handler for media sync.
     */
    public function ajaxSyncMedia() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $attachmentId = absint($_POST['attachment_id'] ?? 0);
        if (!$attachmentId) {
            wp_send_json_error(__('Invalid attachment ID', 'wash'));
        }

        $result = $this->syncAttachment($attachmentId);

        if ($result) {
            $cdnUrl = get_post_meta($attachmentId, '_wash_cdn_url', true);
            wp_send_json_success([
                'cdnUrl' => $cdnUrl,
                'fileId' => get_post_meta($attachmentId, '_wash_file_id', true),
            ]);
        } else {
            wp_send_json_error(__('Sync failed', 'wash'));
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

        $imagesHandler = new WashImages();
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
            $actions['wash_bulk_sync'] = __('Sync to Shopify', 'wash');
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
        if ($action !== 'wash_bulk_sync') {
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
            'wash_bulk_synced' => $synced,
            'wash_bulk_failed' => $failed,
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

        if (isset($_GET['wash_media_synced'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e('Image synced to Shopify successfully!', 'wash');
            echo '</p></div>';
        }

        if (isset($_GET['wash_media_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            esc_html_e('Failed to sync image to Shopify.', 'wash');
            echo '</p></div>';
        }

        if (isset($_GET['wash_bulk_synced'])) {
            $synced = absint($_GET['wash_bulk_synced']);
            $failed = absint($_GET['wash_bulk_failed'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                esc_html__('Bulk sync completed: %d images synced, %d failed.', 'wash'),
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

        $shopifyFileId = get_post_meta($post->ID, '_wash_file_id', true);
        $cdnUrl = get_post_meta($post->ID, '_wash_cdn_url', true);

        $buttonLabel = !empty($shopifyFileId) ? __('Re-sync to Shopify', 'wash') : __('Sync to Shopify', 'wash');
        $statusHtml = '';

        if (!empty($shopifyFileId) && !empty($cdnUrl)) {
            $statusHtml = sprintf(
                '<span class="wash-sync-status synced"><span class="dashicons dashicons-yes-alt"></span> %s</span>
                <a href="%s" target="_blank" rel="noopener noreferrer" class="wash-cdn-link">%s</a>',
                esc_html__('Synced', 'wash'),
                esc_url($cdnUrl),
                esc_html__('View on CDN', 'wash')
            );
        } else {
            $statusHtml = sprintf(
                '<span class="wash-sync-status not-synced"><span class="dashicons dashicons-upload"></span> %s</span>',
                esc_html__('Not synced', 'wash')
            );
        }

        $html = sprintf(
            '<div class="wash-attachment-sync" data-attachment-id="%d">
                <div class="wash-sync-info">%s</div>
                <button type="button" class="button wash-sync-btn" data-attachment-id="%d">
                    <span class="dashicons dashicons-store"></span> %s
                </button>
                <span class="spinner"></span>
            </div>',
            $post->ID,
            $statusHtml,
            $post->ID,
            esc_html($buttonLabel)
        );

        $formFields['wash_sync'] = [
            'label' => __('Shopify', 'wash'),
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

        $shopifyFileId = get_post_meta($post->ID, '_wash_file_id', true);
        $cdnUrl = get_post_meta($post->ID, '_wash_cdn_url', true);

        $syncUrl = wp_nonce_url(
            add_query_arg([
                'action' => 'wash_sync_media',
                'attachment_id' => $post->ID,
            ], admin_url('upload.php')),
            'wash_sync_media_' . $post->ID
        );

        $buttonLabel = !empty($shopifyFileId) ? __('Re-sync to Shopify', 'wash') : __('Sync to Shopify', 'wash');
        ?>
        <div class="misc-pub-section wash-attachment-sync-section">
            <span class="dashicons dashicons-store" style="color: #96bf48;"></span>
            <strong><?php esc_html_e('Shopify:', 'wash'); ?></strong>
            <?php if (!empty($shopifyFileId) && !empty($cdnUrl)) : ?>
                <span style="color: #46b450;"><?php esc_html_e('Synced', 'wash'); ?></span>
                <br>
                <a href="<?php echo esc_url($cdnUrl); ?>" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4;">
                    <?php esc_html_e('View on Shopify CDN', 'wash'); ?>
                    <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-bottom;"></span>
                </a>
            <?php else : ?>
                <span style="color: #ddd;"><?php esc_html_e('Not synced', 'wash'); ?></span>
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

        $pluginUrl = washPluginUrl();

        wp_enqueue_style(
            'wash-media',
            $pluginUrl . 'admin/css/media.css',
            [],
            WASH_VERSION . '.' . filemtime(WASH_PLUGIN_DIR . 'admin/css/media.css')
        );

        wp_enqueue_script(
            'wash-media',
            $pluginUrl . 'admin/js/media.js',
            ['jquery', 'media-views'],
            WASH_VERSION . '.' . filemtime(WASH_PLUGIN_DIR . 'admin/js/media.js'),
            true
        );

        wp_localize_script('wash-media', 'washMedia', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wash_nonce'),
            'i18n'    => [
                'syncing'     => __('Syncing...', 'wash'),
                'synced'      => __('Synced', 'wash'),
                'syncError'   => __('Sync failed', 'wash'),
                'syncBtn'     => __('Sync to Shopify', 'wash'),
                'resyncBtn'   => __('Re-sync to Shopify', 'wash'),
                'viewOnCdn'   => __('View on CDN', 'wash'),
                'notSynced'   => __('Not synced', 'wash'),
            ],
        ]);
    }
}
