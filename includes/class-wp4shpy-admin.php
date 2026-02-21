<?php
/**
 * Admin settings page for wp4shpy.
 * Provides UI for configuring Shopify App connection and sync preferences.
 */
class wp4shpyAdmin {

    /** @var wp4shpyApiClient */
    private $apiClient;

    public function __construct() {
        $this->apiClient = new wp4shpyApiClient();
    }

    /**
     * Initialize admin hooks.
     */
    public function init() {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleCallback']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_wp4shpy_test_connection', [$this, 'ajaxTestConnection']);
        add_action('wp_ajax_wp4shpy_disconnect', [$this, 'ajaxDisconnect']);
        // Blog mappings AJAX handlers
        add_action('wp_ajax_wp4shpy_get_mappings', [$this, 'ajaxGetMappings']);
        add_action('wp_ajax_wp4shpy_get_shopify_blogs', [$this, 'ajaxGetShopifyBlogs']);
        add_action('wp_ajax_wp4shpy_create_mapping', [$this, 'ajaxCreateMapping']);
        add_action('wp_ajax_wp4shpy_update_mapping', [$this, 'ajaxUpdateMapping']);
        add_action('wp_ajax_wp4shpy_delete_mapping', [$this, 'ajaxDeleteMapping']);
        // Preserve tab after settings save
        add_filter('wp_redirect', [$this, 'preserveTabAfterSave']);
        // Shopify preview link in post editor
        add_action('post_submitbox_misc_actions', [$this, 'renderShopifyPreviewLink']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        // Redirect Preview button to Shopify when synced
        add_filter('preview_post_link', [$this, 'filterPreviewLink'], 10, 2);

        // Posts list table: Shopify sync status column
        add_filter('manage_posts_columns', [$this, 'addShopifyColumn']);
        add_filter('manage_pages_columns', [$this, 'addShopifyColumn']);
        add_action('manage_posts_custom_column', [$this, 'renderShopifyColumn'], 10, 2);
        add_action('manage_pages_custom_column', [$this, 'renderShopifyColumn'], 10, 2);

        // Row actions: Sync to Shopify
        add_filter('post_row_actions', [$this, 'addShopifyRowActions'], 10, 2);
        add_filter('page_row_actions', [$this, 'addShopifyRowActions'], 10, 2);

        // Handle manual sync action
        add_action('admin_init', [$this, 'handleManualSync']);

        // AJAX handler for manual sync
        add_action('wp_ajax_wp4shpy_manual_sync', [$this, 'ajaxManualSync']);
    }

    /**
     * Preserve the active tab after saving settings.
     */
    public function preserveTabAfterSave($location) {
        if (strpos($location, 'options-general.php?page=wp4shpy') !== false) {
            if (isset($_POST['_wp_http_referer_tab']) && !empty($_POST['_wp_http_referer_tab'])) {
                $tab = sanitize_key($_POST['_wp_http_referer_tab']);
                $location = add_query_arg('tab', $tab, $location);
            }
        }
        return $location;
    }

    /**
     * Add plugin settings page to WP admin menu.
     */
    public function addMenuPage() {
        add_options_page(
            __('wp4shpy Settings', 'wp4shpy'),
            __('wp4shpy', 'wp4shpy'),
            'manage_options',
            'wp4shpy',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Register plugin settings with the Settings API.
     */
    public function registerSettings() {
        $settings = [
            'wp4shpy_shopify_store',
            'wp4shpy_api_key',
            'wp4shpy_api_secret',
            'wp4shpy_http_user',
            'wp4shpy_http_pass',
            'wp4shpy_sync_on_publish',
            'wp4shpy_sync_images',
            'wp4shpy_sync_metafields',
            'wp4shpy_post_types',
            'wp4shpy_block_robots',
            'wp4shpy_meta_wp_core',
            'wp4shpy_meta_yoast',
            'wp4shpy_meta_rankmath',
            'wp4shpy_meta_acf',
            'wp4shpy_hreflangs_enabled',
            'wp4shpy_authors_enabled',
            'wp4shpy_files_enabled',
            'wp4shpy_webp_convert',
            'wp4shpy_rewrite_internal_urls',
        ];

        foreach ($settings as $setting) {
            register_setting('wp4shpy_settings', $setting, [
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }

        // Author mappings array (user_id => author_name)
        register_setting('wp4shpy_settings', 'wp4shpy_author_mappings', [
            'sanitize_callback' => [$this, 'sanitizeAuthorMappings'],
        ]);

        // Allowed file types array
        register_setting('wp4shpy_settings', 'wp4shpy_allowed_file_types', [
            'sanitize_callback' => [$this, 'sanitizeAllowedFileTypes'],
        ]);
    }

    /**
     * Sanitize allowed file types array.
     *
     * @param array $input Raw input from form.
     * @return array Sanitized file types.
     */
    public function sanitizeAllowedFileTypes($input) {
        if (!is_array($input)) {
            return ['images'];
        }

        $allowedTypes = ['images', 'pdf', 'video', 'audio', 'documents'];
        $sanitized = [];

        foreach ($input as $type) {
            $type = sanitize_key($type);
            if (in_array($type, $allowedTypes, true)) {
                $sanitized[] = $type;
            }
        }

        return !empty($sanitized) ? $sanitized : ['images'];
    }

    /**
     * Sanitize author mappings array.
     *
     * @param array $input Raw input from form.
     * @return array Sanitized mappings.
     */
    public function sanitizeAuthorMappings($input) {
        if (!is_array($input)) {
            return [];
        }

        $sanitized = [];
        foreach ($input as $userId => $authorName) {
            $userId = absint($userId);
            $authorName = sanitize_text_field(trim($authorName));
            if ($userId > 0 && !empty($authorName)) {
                $sanitized[$userId] = $authorName;
            }
        }

        return $sanitized;
    }

    /**
     * Handle callback from Shopify app with credentials.
     */
    public function handleCallback() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wp4shpy') {
            return;
        }
        if (!isset($_GET['wp4shpy_callback'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        // Receive credentials from Shopify app callback
        if (isset($_GET['api_key']) && isset($_GET['api_secret'])) {
            $apiKey = sanitize_text_field($_GET['api_key']);
            $apiSecret = sanitize_text_field($_GET['api_secret']);

            if (!empty($apiKey) && !empty($apiSecret)) {
                update_option('wp4shpy_api_key', $apiKey);
                update_option('wp4shpy_api_secret', $apiSecret);

                if (isset($_GET['store'])) {
                    update_option('wp4shpy_shopify_store', sanitize_text_field($_GET['store']));
                }

                // Redirect to clean URL
                wp_redirect(admin_url('options-general.php?page=wp4shpy&connected=1'));
                exit;
            }
        }
    }

    /**
     * Enqueue admin CSS and JS.
     * CSS: loaded on settings page and posts/pages list.
     * JS: loaded only on settings page.
     */
    public function enqueueAssets($hook) {
        $pluginUrl = wp4shpy_plugin_url();

        // Load CSS on posts list and settings page
        if (in_array($hook, ['edit.php', 'settings_page_wp4shpy'], true)) {
            wp_enqueue_style(
                'wp4shpy-admin',
                $pluginUrl . 'admin/css/admin.css',
                [],
                wp4shpy_VERSION . '.' . filemtime(wp4shpy_PLUGIN_DIR . 'admin/css/admin.css')
            );
        }

        // Load JS only on settings page
        if ($hook !== 'settings_page_wp4shpy') {
            return;
        }

        wp_enqueue_script(
            'wp4shpy-admin',
            $pluginUrl . 'admin/js/admin.js',
            ['jquery'],
            wp4shpy_VERSION . '.2.' . time(),
            true
        );

        wp_localize_script('wp4shpy-admin', 'wp4shpyAdmin', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('wp4shpy_nonce'),
            'disconnectNonce' => wp_create_nonce('wp4shpy_disconnect'),
            'i18n'            => [
                'testing'           => __('Testing connection...', 'wp4shpy'),
                'success'           => __('Connection successful!', 'wp4shpy'),
                'error'             => __('Connection failed: ', 'wp4shpy'),
                'confirmDisconnect' => __('Are you sure you want to disconnect from Shopify?', 'wp4shpy'),
            ],
        ]);
    }

    /**
     * Render the settings page.
     */
    public function renderSettingsPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include wp4shpy_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * AJAX handler for testing Shopify App connection.
     */
    public function ajaxTestConnection() {
        check_ajax_referer('wp4shpy_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $result = $this->apiClient->testConnection();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for disconnecting from Shopify App.
     */
    public function ajaxDisconnect() {
        check_ajax_referer('wp4shpy_disconnect', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        delete_option('wp4shpy_api_key');
        delete_option('wp4shpy_api_secret');

        wp_send_json_success();
    }

    /**
     * AJAX handler for getting blog mappings from Shopify App.
     */
    public function ajaxGetMappings() {
        check_ajax_referer('wp4shpy_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $result = $this->apiClient->get('/api/blog-mappings');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['mappings' => $result['mappings'] ?? []]);
    }

    /**
     * AJAX handler for getting Shopify blogs list.
     */
    public function ajaxGetShopifyBlogs() {
        check_ajax_referer('wp4shpy_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $result = $this->apiClient->get('/api/shopify-blogs');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['blogs' => $result['blogs'] ?? []]);
    }

    /**
     * AJAX handler for creating a blog mapping.
     */
    public function ajaxCreateMapping() {
        check_ajax_referer('wp4shpy_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $data = [
            'ruleType'      => sanitize_text_field($_POST['ruleType'] ?? 'default'),
            'ruleValue'     => sanitize_text_field($_POST['ruleValue'] ?? ''),
            'shopifyBlogId' => sanitize_text_field($_POST['shopifyBlogId'] ?? ''),
            'newBlogName'   => sanitize_text_field($_POST['newBlogName'] ?? ''),
            'priority'      => absint($_POST['priority'] ?? 0),
        ];

        $result = $this->apiClient->post('/api/blog-mappings', $data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success([
            'mapping'     => $result['mapping'] ?? null,
            'blogCreated' => !empty($result['blogCreated']),
        ]);
    }

    /**
     * AJAX handler for updating a blog mapping.
     */
    public function ajaxUpdateMapping() {
        check_ajax_referer('wp4shpy_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $mappingId = sanitize_text_field($_POST['mappingId'] ?? '');
        if (empty($mappingId)) {
            wp_send_json_error(__('Mapping ID is required', 'wp4shpy'));
        }

        $data = [
            'ruleType'      => sanitize_text_field($_POST['ruleType'] ?? 'default'),
            'ruleValue'     => sanitize_text_field($_POST['ruleValue'] ?? ''),
            'shopifyBlogId' => sanitize_text_field($_POST['shopifyBlogId'] ?? ''),
            'priority'      => absint($_POST['priority'] ?? 0),
        ];

        $result = $this->apiClient->post('/api/blog-mappings/' . $mappingId, $data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['mapping' => $result['mapping'] ?? null]);
    }

    /**
     * AJAX handler for deleting a blog mapping.
     */
    public function ajaxDeleteMapping() {
        check_ajax_referer('wp4shpy_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $mappingId = sanitize_text_field($_POST['mappingId'] ?? '');
        if (empty($mappingId)) {
            wp_send_json_error(__('Mapping ID is required', 'wp4shpy'));
        }

        $result = $this->apiClient->delete('/api/blog-mappings/' . $mappingId);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success();
    }

    /**
     * Render "View on Shopify" link in the classic editor publish box.
     *
     * @param WP_Post $post Current post object.
     */
    public function renderShopifyPreviewLink($post) {
        $shopifyUrl = $this->getShopifyArticleUrl($post->ID);
        if (!$shopifyUrl) {
            return;
        }

        ?>
        <div class="misc-pub-section wp4shpy-shopify-link">
            <span class="dashicons dashicons-store" style="color: #96bf48;"></span>
            <a href="<?php echo esc_url($shopifyUrl); ?>" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4; text-decoration: none;">
                <?php esc_html_e('View on Shopify', 'wp4shpy'); ?>
                <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-bottom;"></span>
            </a>
        </div>
        <?php
    }

    /**
     * Enqueue block editor assets for Gutenberg "View on Shopify" link.
     */
    public function enqueueBlockEditorAssets() {
        global $post;
        if (!$post) {
            return;
        }

        $shopifyUrl = $this->getShopifyArticleUrl($post->ID);
        if (!$shopifyUrl) {
            return;
        }

        wp_add_inline_script('wp-edit-post', $this->getBlockEditorShopifyLinkScript($shopifyUrl), 'after');
    }

    /**
     * Get Shopify article URL for a given WordPress post.
     *
     * @param int $postId WordPress post ID.
     * @return string|null Shopify article URL or null if not synced.
     */
    private function getShopifyArticleUrl($postId) {
        $shopifyStore = get_option('wp4shpy_shopify_store', '');
        if (empty($shopifyStore)) {
            return null;
        }

        $shopifyArticleId = get_post_meta($postId, '_wp4shpy_article_id', true);
        $shopifyBlogId = get_post_meta($postId, '_wp4shpy_blog_id', true);
        if (empty($shopifyArticleId)) {
            return null;
        }

        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        // For pages: /pages/{handle}
        if ($post->post_type === 'page') {
            return 'https://' . $shopifyStore . '.myshopify.com/pages/' . $post->post_name;
        }

        // For posts: /blogs/{blog-handle}/{article-handle}
        // We need to get blog handle from Shopify. For now, use a default pattern.
        // The blog handle is stored in sync meta or we can query it.
        $blogHandle = get_post_meta($postId, '_wp4shpy_blog_handle', true);
        if (empty($blogHandle)) {
            $blogHandle = 'news'; // fallback
        }

        return 'https://' . $shopifyStore . '.myshopify.com/blogs/' . $blogHandle . '/' . $post->post_name;
    }

    /**
     * Generate inline JS for adding Shopify link to Gutenberg editor.
     *
     * @param string $shopifyUrl The Shopify article URL.
     * @return string JavaScript code.
     */
    private function getBlockEditorShopifyLinkScript($shopifyUrl) {
        $label = esc_js(__('View on Shopify', 'wp4shpy'));
        $escapedUrl = esc_js($shopifyUrl);

        return <<<JS
(function() {
    if (typeof wp === 'undefined' || !wp.plugins || !wp.editPost || !wp.element) {
        return;
    }

    var registerPlugin = wp.plugins.registerPlugin;
    var PluginPostStatusInfo = wp.editPost.PluginPostStatusInfo;
    var createElement = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    registerPlugin('wp4shpy-shopify-link', {
        render: function() {
            return createElement(
                PluginPostStatusInfo,
                { className: 'wp4shpy-shopify-link-panel' },
                createElement(
                    'a',
                    {
                        href: '{$escapedUrl}',
                        target: '_blank',
                        rel: 'noopener noreferrer',
                        style: {
                            display: 'flex',
                            alignItems: 'center',
                            gap: '6px',
                            color: '#5c6ac4',
                            textDecoration: 'none',
                            padding: '8px 0'
                        }
                    },
                    createElement('span', {
                        className: 'dashicons dashicons-store',
                        style: { color: '#96bf48' }
                    }),
                    '{$label}',
                    createElement('span', {
                        className: 'dashicons dashicons-external',
                        style: { fontSize: '14px' }
                    })
                )
            );
        }
    });
})();
JS;
    }

    /**
     * Filter preview link to point to Shopify when post is synced.
     *
     * @param string  $previewLink Default preview URL.
     * @param WP_Post $post        Post object.
     * @return string Modified preview URL or original.
     */
    public function filterPreviewLink($previewLink, $post) {
        $shopifyUrl = $this->getShopifyArticleUrl($post->ID);
        if ($shopifyUrl) {
            return $shopifyUrl;
        }
        return $previewLink;
    }

    /**
     * Add Shopify sync status column to posts/pages list.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function addShopifyColumn($columns) {
        $newColumns = [];
        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;
            if ($key === 'title') {
                $newColumns['wp4shpy_sync'] = '<span class="dashicons dashicons-store" title="' . esc_attr__('Shopify Sync', 'wp4shpy') . '" style="color: #96bf48;"></span>';
            }
        }
        return $newColumns;
    }

    /**
     * Render Shopify sync status column content.
     *
     * @param string $column  Column name.
     * @param int    $postId  Post ID.
     */
    public function renderShopifyColumn($column, $postId) {
        if ($column !== 'wp4shpy_sync') {
            return;
        }

        $shopifyArticleId = get_post_meta($postId, '_wp4shpy_article_id', true);
        $syncedAt = get_post_meta($postId, '_wp4shpy_synced_at', true);
        $blogHandle = get_post_meta($postId, '_wp4shpy_blog_handle', true);

        if (!empty($shopifyArticleId)) {
            $shopifyUrl = $this->getShopifyArticleUrl($postId);
            $title = sprintf(
                __('Synced to Shopify on %s', 'wp4shpy'),
                $syncedAt ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($syncedAt)) : __('unknown date', 'wp4shpy')
            );
            echo '<a href="' . esc_url($shopifyUrl) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($title) . '">';
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>';
            echo '</a>';
        } else {
            $post = get_post($postId);
            if ($post->post_status === 'publish') {
                echo '<span class="dashicons dashicons-minus" style="color: #ddd;" title="' . esc_attr__('Not synced', 'wp4shpy') . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-hidden" style="color: #ccc;" title="' . esc_attr__('Draft - will sync when published', 'wp4shpy') . '"></span>';
            }
        }
    }

    /**
     * Add Shopify sync actions to post row actions.
     *
     * @param array   $actions Existing row actions.
     * @param WP_Post $post    Post object.
     * @return array Modified row actions.
     */
    public function addShopifyRowActions($actions, $post) {
        if (!$this->apiClient->isConfigured()) {
            return $actions;
        }

        $allowedTypes = array_map('trim', explode(',', get_option('wp4shpy_post_types', 'post,page')));
        if (!in_array($post->post_type, $allowedTypes, true)) {
            return $actions;
        }

        $shopifyArticleId = get_post_meta($post->ID, '_wp4shpy_article_id', true);

        if (!empty($shopifyArticleId)) {
            // Already synced - show "View on Shopify" link
            $shopifyUrl = $this->getShopifyArticleUrl($post->ID);
            $actions['wp4shpy_view'] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4;">%s</a>',
                esc_url($shopifyUrl),
                __('View on Shopify', 'wp4shpy')
            );
        }

        if ($post->post_status === 'publish') {
            // Show "Sync to Shopify" action
            $syncUrl = wp_nonce_url(
                add_query_arg([
                    'action' => 'wp4shpy_sync',
                    'post_id' => $post->ID,
                ], admin_url('edit.php')),
                'wp4shpy_sync_' . $post->ID
            );
            $actionLabel = !empty($shopifyArticleId) ? __('Re-sync to Shopify', 'wp4shpy') : __('Sync to Shopify', 'wp4shpy');
            $actions['wp4shpy_sync'] = sprintf(
                '<a href="%s" style="color: #96bf48;">%s</a>',
                esc_url($syncUrl),
                $actionLabel
            );
        }

        return $actions;
    }

    /**
     * Handle manual sync action from posts list.
     */
    public function handleManualSync() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'wp4shpy_sync') {
            return;
        }
        if (!isset($_GET['post_id'])) {
            return;
        }

        $postId = absint($_GET['post_id']);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wp4shpy_sync_' . $postId)) {
            wp_die(__('Security check failed', 'wp4shpy'));
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_die(__('You do not have permission to sync this post', 'wp4shpy'));
        }

        $post = get_post($postId);
        if (!$post || $post->post_status !== 'publish') {
            wp_redirect(add_query_arg('wp4shpy_error', 'not_published', wp_get_referer() ?: admin_url('edit.php')));
            exit;
        }

        // Trigger sync
        $sync = new wp4shpySync();
        $sync->onSavePost($postId, $post, true);

        // Check if sync was successful
        $shopifyArticleId = get_post_meta($postId, '_wp4shpy_article_id', true);
        $result = !empty($shopifyArticleId) ? 'synced' : 'error';

        wp_redirect(add_query_arg('wp4shpy_result', $result, wp_get_referer() ?: admin_url('edit.php')));
        exit;
    }

    /**
     * AJAX handler for manual sync.
     */
    public function ajaxManualSync() {
        check_ajax_referer('wp4shpy_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'wp4shpy'));
        }

        $postId = absint($_POST['post_id'] ?? 0);
        if (!$postId) {
            wp_send_json_error(__('Invalid post ID', 'wp4shpy'));
        }

        $post = get_post($postId);
        if (!$post) {
            wp_send_json_error(__('Post not found', 'wp4shpy'));
        }

        // Trigger sync
        $sync = new wp4shpySync();
        $sync->onSavePost($postId, $post, true);

        // Check result
        $shopifyArticleId = get_post_meta($postId, '_wp4shpy_article_id', true);
        if (!empty($shopifyArticleId)) {
            wp_send_json_success([
                'shopifyArticleId' => $shopifyArticleId,
                'shopifyUrl' => $this->getShopifyArticleUrl($postId),
            ]);
        } else {
            wp_send_json_error(__('Sync failed', 'wp4shpy'));
        }
    }
}
