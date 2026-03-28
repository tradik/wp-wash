<?php
/**
 * Admin settings page for Wash.
 * Provides UI for configuring Shopify App connection and sync preferences.
 */
class WashAdmin {

    /** @var WashApiClient */
    private $apiClient;

    public function __construct() {
        $this->apiClient = new WashApiClient();
    }

    /**
     * Initialize admin hooks.
     */
    public function init() {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'handleCallback']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_wash_test_connection', [$this, 'ajaxTestConnection']);
        add_action('wp_ajax_wash_disconnect', [$this, 'ajaxDisconnect']);
        add_action('wp_ajax_wash_debug_session', [$this, 'ajaxDebugSession']);
        add_action('wp_ajax_wash_reset_session', [$this, 'ajaxResetSession']);
        add_action('wp_ajax_wash_fix_session', [$this, 'ajaxFixSession']);
        // Blog mappings AJAX handlers
        add_action('wp_ajax_wash_get_mappings', [$this, 'ajaxGetMappings']);
        add_action('wp_ajax_wash_get_shopify_blogs', [$this, 'ajaxGetShopifyBlogs']);
        add_action('wp_ajax_wash_create_mapping', [$this, 'ajaxCreateMapping']);
        add_action('wp_ajax_wash_update_mapping', [$this, 'ajaxUpdateMapping']);
        add_action('wp_ajax_wash_delete_mapping', [$this, 'ajaxDeleteMapping']);
        // Redirections AJAX handlers
        add_action('wp_ajax_wash_get_shopify_redirections', [$this, 'ajaxGetShopifyRedirections']);
        add_action('wp_ajax_wash_generate_redirections', [$this, 'ajaxGenerateRedirections']);
        add_action('wp_ajax_wash_sync_redirections', [$this, 'ajaxSyncRedirections']);
        add_action('wp_ajax_wash_fetch_wp_redirections', [$this, 'ajaxFetchWpRedirections']);
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
        add_action('wp_ajax_wash_manual_sync', [$this, 'ajaxManualSync']);

        // AJAX handler for sync status polling
        add_action('wp_ajax_wash_check_sync_status', [$this, 'ajaxCheckSyncStatus']);

        // Admin notices for sync results
        add_action('admin_notices', [$this, 'renderSyncNotices']);

        // Backup settings to Shopify App after settings update
        add_action('update_option_wash_sync_on_publish', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_sync_images', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_sync_metafields', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_post_types', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_block_robots', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_meta_wp_core', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_meta_yoast', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_meta_rankmath', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_meta_acf', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_hreflangs_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_authors_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_author_mappings', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_files_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_webp_convert', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_allowed_file_types', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_rewrite_internal_urls', [$this, 'scheduleSettingsBackup']);

        // Run the actual backup after all settings are updated (at shutdown)
        add_action('shutdown', [$this, 'runScheduledSettingsBackup']);
    }

    /**
     * Schedule settings backup to run at shutdown.
     * This prevents multiple API calls when multiple settings are updated at once.
     */
    public function scheduleSettingsBackup() {
        // Set a transient flag to indicate backup is needed
        set_transient('wash_settings_backup_pending', true, 60);
    }

    /**
     * Run the scheduled settings backup at shutdown.
     */
    public function runScheduledSettingsBackup() {
        if (!get_transient('wash_settings_backup_pending')) {
            return;
        }

        // Check if plugin is configured
        if (!$this->apiClient->isConfigured()) {
            delete_transient('wash_settings_backup_pending');
            return;
        }

        // Backup settings to Shopify App
        $result = $this->apiClient->backupSettings();

        if (is_wp_error($result)) {
            error_log('[wash] Settings backup failed: ' . $result->get_error_message());
        } else {
            error_log('[wash] Settings backed up to Shopify App');
        }

        delete_transient('wash_settings_backup_pending');
    }

    /**
     * Preserve the active tab after saving settings.
     */
    public function preserveTabAfterSave($location) {
        if (strpos($location, 'options-general.php?page=wash') !== false) {
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
            __('Wash Settings', 'wash'),
            __('Wash: Manager', 'wash'),
            'manage_options',
            'wash',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Register plugin settings with the Settings API.
     */
    public function registerSettings() {
        $settings = [
            'wash_shopify_store',
            'wash_api_key',
            'wash_api_secret',
            'wash_http_user',
            'wash_http_pass',
            'wash_sync_on_publish',
            'wash_sync_images',
            'wash_sync_metafields',
            'wash_post_types',
            'wash_block_robots',
            'wash_meta_wp_core',
            'wash_meta_yoast',
            'wash_meta_rankmath',
            'wash_meta_acf',
            'wash_hreflangs_enabled',
            'wash_authors_enabled',
            'wash_files_enabled',
            'wash_webp_convert',
            'wash_rewrite_internal_urls',
            'wash_rewrite_urls',
            'wash_allowed_html_tags',
            'wash_blocks_enabled',
            'wash_sync_blocks_meta',
            'wash_sync_blocks_css',
        ];

        foreach ($settings as $setting) {
            register_setting('wash_settings', $setting, [
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }

        // Author mappings array (user_id => author_name)
        register_setting('wash_settings', 'wash_author_mappings', [
            'sanitize_callback' => [$this, 'sanitizeAuthorMappings'],
        ]);

        // Allowed file types array
        register_setting('wash_settings', 'wash_allowed_file_types', [
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
        if (!isset($_GET['page']) || $_GET['page'] !== 'wash') {
            return;
        }
        if (!isset($_GET['wash_callback'])) {
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
                update_option('wash_api_key', $apiKey);
                update_option('wash_api_secret', $apiSecret);

                if (isset($_GET['store'])) {
                    update_option('wash_shopify_store', sanitize_text_field($_GET['store']));
                }

                // Check if this is a reauth with backup available
                $isReauth = isset($_GET['is_reauth']) && $_GET['is_reauth'] === '1';
                $hasBackup = isset($_GET['has_backup']) && $_GET['has_backup'] === '1';

                if ($isReauth && $hasBackup) {
                    // Restore settings from backup
                    $this->restoreSettingsFromBackup();
                }

                // Redirect to clean URL
                $redirectUrl = admin_url('options-general.php?page=wash&connected=1');
                if ($isReauth) {
                    $redirectUrl = add_query_arg('restored', $hasBackup ? '1' : '0', $redirectUrl);
                }
                wp_redirect($redirectUrl);
                exit;
            }
        }
    }

    /**
     * Restore plugin settings from Shopify App backup.
     * Called during reauth when backup is available.
     */
    private function restoreSettingsFromBackup() {
        $response = $this->apiClient->getBackedUpSettings();

        if (is_wp_error($response)) {
            error_log('[wash] Failed to restore settings: ' . $response->get_error_message());
            return;
        }

        if (!empty($response['success']) && !empty($response['settings'])) {
            $this->apiClient->restoreSettings($response['settings']);
            error_log('[wash] Settings restored from backup');
        }
    }

    /**
     * Enqueue admin CSS and JS.
     * CSS: loaded on settings page and posts/pages list.
     * JS: loaded only on settings page.
     */
    public function enqueueAssets($hook) {
        $pluginUrl = washPluginUrl();

        // Load CSS on posts list and settings page
        if (in_array($hook, ['edit.php', 'settings_page_wash'], true)) {
            wp_enqueue_style(
                'wash-admin',
                $pluginUrl . 'admin/css/admin.css',
                [],
                WASH_VERSION . '.' . filemtime(WASH_PLUGIN_DIR . 'admin/css/admin.css')
            );
        }

        // Load sync status JS on posts list page
        if ($hook === 'edit.php') {
            wp_enqueue_script(
                'wash-sync-status',
                $pluginUrl . 'admin/js/sync-status.js',
                ['jquery'],
                WASH_VERSION . '.' . filemtime(WASH_PLUGIN_DIR . 'admin/js/sync-status.js'),
                true
            );
            wp_localize_script('wash-sync-status', 'WashSyncStatus', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('wash_sync_status'),
                'i18n'    => [
                    'synced'  => __('Synced to Shopify', 'wash'),
                    'queued'  => __('Sync in progress...', 'wash'),
                    'error'   => __('Sync failed', 'wash'),
                    'success' => __('Post synced to Shopify successfully.', 'wash'),
                ],
            ]);
            return;
        }

        // Load JS only on settings page
        if ($hook !== 'settings_page_wash') {
            return;
        }

        wp_enqueue_script(
            'wash-admin',
            $pluginUrl . 'admin/js/admin.js',
            ['jquery'],
            WASH_VERSION . '.2.' . time(),
            true
        );

        wp_localize_script('wash-admin', 'washAdmin', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('wash_nonce'),
            'disconnectNonce'   => wp_create_nonce('wash_disconnect'),
            'redirectionsNonce' => wp_create_nonce('wash_redirections'),
            'i18n'              => [
                // Connection testing
                'testing'               => __('Testing connection...', 'wash'),
                'success'               => __('Connection successful!', 'wash'),
                'error'                 => __('Connection failed: ', 'wash'),
                'testConnection'        => __('Test Connection', 'wash'),
                // Disconnect
                'confirmDisconnect'     => __('Are you sure you want to disconnect from Shopify?', 'wash'),
                'failedDisconnect'      => __('Failed to disconnect. Please try again.', 'wash'),
                // Debug session
                'debugSession'          => __('Debug Session', 'wash'),
                'checking'              => __('Checking...', 'wash'),
                'networkErrorDebug'     => __('Network error - could not reach debug endpoint', 'wash'),
                // Reset session
                'resetSession'          => __('Reset Session', 'wash'),
                'resetting'             => __('Resetting...', 'wash'),
                'confirmReset'          => __('This will delete the Shopify session. You will need to re-open the app from Shopify Admin to re-authenticate. Continue?', 'wash'),
                'sessionResetSuccess'   => __('Session reset. Now open the app from Shopify Admin to re-authenticate.', 'wash'),
                'failedResetSession'    => __('Failed to reset session: ', 'wash'),
                'networkErrorReset'     => __('Network error - could not reach reset endpoint', 'wash'),
                'networkErrorResetAlert' => __('Network error while resetting session', 'wash'),
                // Redirections
                'loading'               => __('Loading...', 'wash'),
                'syncing'               => __('Syncing redirections...', 'wash'),
                'generating'            => __('Generating redirections...', 'wash'),
                'fetchingWp'            => __('Fetching WordPress redirections...', 'wash'),
                'syncSuccess'           => __('Redirections synced successfully!', 'wash'),
                'syncError'             => __('Failed to sync redirections: ', 'wash'),
                'failedLoadStats'       => __('Failed to load stats', 'wash'),
                'noRedirectionsFound'   => __('No redirections found in WordPress plugins (Redirection, RankMath, Yoast).', 'wash'),
                'importedFromWp'        => __('# Imported from WordPress plugins', 'wash'),
                'failedFetchRedirections' => __('Failed to fetch redirections: ', 'wash'),
                'networkErrorFetch'     => __('Network error while fetching WordPress redirections', 'wash'),
                'syncFromOldWebsite'    => __('Sync from Old Website', 'wash'),
                'noSyncedPosts'         => __('No synced posts found. Sync some posts first to generate redirections.', 'wash'),
                'generatedFromSynced'   => __('# Generated from synced posts', 'wash'),
                'failedGenerateRedirections' => __('Failed to generate redirections: ', 'wash'),
                'networkErrorGenerate'  => __('Network error while generating redirections', 'wash'),
                'generateFromSynced'    => __('Generate from Synced Posts', 'wash'),
                'failedLoadShopify'     => __('Failed to load Shopify redirections: ', 'wash'),
                'loadFromShopify'       => __('Load from Shopify', 'wash'),
                'noRedirectionsShopify' => __('No redirections found in Shopify.', 'wash'),
                'existingShopifyRedirects' => __('# Existing Shopify redirections', 'wash'),
                'networkErrorLoadShopify' => __('Network error while loading Shopify redirections', 'wash'),
                // Fix session
                'fixing'                => __('Fixing...', 'wash'),
                'sessionFixed'          => __('Session fixed!', 'wash'),
                'noSessionsNeedFix'     => __('No sessions needed fixing.', 'wash'),
                'networkErrorFix'       => __('Network error while fixing session', 'wash'),
                'fixSessionScopes'      => __('Fix Session Scopes', 'wash'),
                'failedFixSession'      => __('Failed to fix session: ', 'wash'),
                // Sync redirections
                'pleaseEnterRedirections' => __('Please enter redirections to sync.', 'wash'),
                'networkErrorSync'      => __('Network error while syncing redirections', 'wash'),
                'unknownError'          => __('Unknown error', 'wash'),
                'networkError'          => __('Network error', 'wash'),
                // Results labels
                'created'               => __('Created', 'wash'),
                'skippedExist'          => __('Skipped (already exist)', 'wash'),
                'failed'                => __('Failed', 'wash'),
                'totalInShopify'        => __('Total in Shopify', 'wash'),
                'remaining'             => __('Remaining', 'wash'),
                'errors'                => __('Errors', 'wash'),
                'andMore'               => __('... and %d more', 'wash'),
                'tryAgain'              => __('Try loading redirections again.', 'wash'),
                // Mappings (inline JS in settings-page.php)
                'failedLoadMappings'    => __('Failed to load mappings', 'wash'),
                'edit'                  => __('Edit', 'wash'),
                'delete'                => __('Delete', 'wash'),
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
        include WASH_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * AJAX handler for testing Shopify App connection.
     */
    public function ajaxTestConnection() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
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
        check_ajax_referer('wash_disconnect', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        delete_option('wash_api_key');
        delete_option('wash_api_secret');

        wp_send_json_success();
    }

    /**
     * AJAX handler for debugging Shopify session.
     */
    public function ajaxDebugSession() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $result = $this->apiClient->debugSession();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for resetting/deleting Shopify session.
     */
    public function ajaxResetSession() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $result = $this->apiClient->resetSession();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for fixing Shopify session scope.
     * This fixes the Token Exchange bug where only write_* scopes are stored.
     */
    public function ajaxFixSession() {
        check_ajax_referer('wash_redirections', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $result = $this->apiClient->fixSession();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for getting blog mappings from Shopify App.
     */
    public function ajaxGetMappings() {
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
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
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
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
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
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
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $mappingId = sanitize_text_field($_POST['mappingId'] ?? '');
        if (empty($mappingId)) {
            wp_send_json_error(__('Mapping ID is required', 'wash'));
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
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $mappingId = sanitize_text_field($_POST['mappingId'] ?? '');
        if (empty($mappingId)) {
            wp_send_json_error(__('Mapping ID is required', 'wash'));
        }

        $result = $this->apiClient->delete('/api/blog-mappings/' . $mappingId);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success();
    }

    /**
     * AJAX handler for getting existing Shopify redirections.
     */
    public function ajaxGetShopifyRedirections() {
        check_ajax_referer('wash_redirections', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $result = $this->apiClient->get('/api/redirections');

        if (is_wp_error($result)) {
            // Try to get diagnosis for better error message
            $diagnosis = $this->apiClient->diagnose();
            $errorMsg = $result->get_error_message();

            if (!is_wp_error($diagnosis) && isset($diagnosis['fix'])) {
                $errorMsg .= "\n\n" . __('How to fix:', 'wash') . " " . $diagnosis['fix'];
            }

            wp_send_json_error($errorMsg);
        }

        wp_send_json_success([
            'stats'      => $result['stats'] ?? [],
            'redirects'  => $result['redirects'] ?? [],
        ]);
    }

    /**
     * AJAX handler for generating redirections based on synced content.
     */
    public function ajaxGenerateRedirections() {
        check_ajax_referer('wash_redirections', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $redirections = [];
        $shopifyStore = get_option('wash_shopify_store', '');

        $posts = get_posts([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => '_wash_article_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($posts as $post) {
            $wpPermalink = get_permalink($post->ID);
            $wpPath = wp_parse_url($wpPermalink, PHP_URL_PATH);

            if ($post->post_type === 'page') {
                $shopifyPath = '/pages/' . $post->post_name;
            } else {
                $blogHandle = get_post_meta($post->ID, '_wash_blog_handle', true);
                if (empty($blogHandle)) {
                    $blogHandle = 'news';
                }
                $shopifyPath = '/blogs/' . $blogHandle . '/' . $post->post_name;
            }

            if ($wpPath && $wpPath !== $shopifyPath) {
                $redirections[] = [
                    'from'  => rtrim($wpPath, '/'),
                    'to'    => $shopifyPath,
                    'title' => $post->post_title,
                ];
            }
        }

        wp_send_json_success([
            'redirections' => $redirections,
            'count'        => count($redirections),
        ]);
    }

    /**
     * AJAX handler for syncing redirections to Shopify.
     */
    public function ajaxSyncRedirections() {
        check_ajax_referer('wash_redirections', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $redirectionsRaw = isset($_POST['redirections']) ? sanitize_textarea_field(wp_unslash($_POST['redirections'])) : '';

        if (empty($redirectionsRaw)) {
            wp_send_json_error(__('No redirections provided.', 'wash'));
        }

        $redirections = $this->parseRedirections($redirectionsRaw);

        if (empty($redirections)) {
            wp_send_json_error(__('No valid redirections found. Format: /old-path -> /new-path', 'wash'));
        }

        $result = $this->apiClient->post('/api/redirections', [
            'redirects' => $redirections,
        ]);

        if (is_wp_error($result)) {
            // Try to get diagnosis for better error message
            $diagnosis = $this->apiClient->diagnose();
            $errorMsg = $result->get_error_message();

            if (!is_wp_error($diagnosis) && isset($diagnosis['fix'])) {
                $errorMsg .= "\n\n" . __('How to fix:', 'wash') . " " . $diagnosis['fix'];
            }

            wp_send_json_error($errorMsg);
        }

        wp_send_json_success([
            'created'        => $result['created'] ?? 0,
            'skipped'        => $result['skipped'] ?? 0,
            'failed'         => $result['failed'] ?? 0,
            'remaining'      => $result['remaining'] ?? 0,
            'totalInShopify' => $result['totalInShopify'] ?? 0,
            'errors'         => $result['errors'] ?? [],
        ]);
    }

    /**
     * AJAX handler for fetching WordPress redirections from redirection plugins.
     */
    public function ajaxFetchWpRedirections() {
        check_ajax_referer('wash_redirections', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $redirections = [];

        if (class_exists('Red_Item')) {
            $items = \Red_Item::get_all();
            foreach ($items as $item) {
                if ($item->is_enabled() && $item->get_action_type() === 'url') {
                    $redirections[] = [
                        'from'   => $item->get_url(),
                        'to'     => $item->get_action_data()['url'] ?? '',
                        'source' => 'Redirection',
                    ];
                }
            }
        }

        global $wpdb;
        $rankMathTable = $wpdb->prefix . 'rank_math_redirections';
        if ($wpdb->get_var("SHOW TABLES LIKE '$rankMathTable'") === $rankMathTable) {
            $rankMathRedirects = $wpdb->get_results(
                "SELECT sources, url_to FROM $rankMathTable WHERE status = 'active'",
                ARRAY_A
            );
            foreach ($rankMathRedirects as $redirect) {
                $sources = maybe_unserialize($redirect['sources']);
                if (is_array($sources)) {
                    foreach ($sources as $source) {
                        $redirections[] = [
                            'from'   => $source['pattern'] ?? '',
                            'to'     => $redirect['url_to'],
                            'source' => 'RankMath',
                        ];
                    }
                }
            }
        }

        $yoastRedirectOption = get_option('wpseo-premium-redirects-base', []);
        if (!empty($yoastRedirectOption) && is_array($yoastRedirectOption)) {
            foreach ($yoastRedirectOption as $redirect) {
                $redirections[] = [
                    'from'   => $redirect['origin'] ?? '',
                    'to'     => $redirect['url'] ?? '',
                    'source' => 'Yoast Premium',
                ];
            }
        }

        wp_send_json_success([
            'redirections' => $redirections,
            'count'        => count($redirections),
        ]);
    }

    /**
     * Parse redirections from textarea content.
     * Expected format: /old-path -> /new-path (one per line)
     *
     * @param string $content Raw textarea content.
     * @return array Parsed redirections.
     */
    private function parseRedirections($content) {
        $redirections = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (preg_match('/^(.+?)\s*(?:->|→|=>)\s*(.+)$/', $line, $matches)) {
                $from = trim($matches[1]);
                $to = trim($matches[2]);

                if (!empty($from) && !empty($to)) {
                    if (strpos($from, '/') !== 0) {
                        $from = '/' . $from;
                    }
                    if (strpos($to, '/') !== 0 && strpos($to, 'http') !== 0) {
                        $to = '/' . $to;
                    }

                    $redirections[] = [
                        'from' => $from,
                        'to'   => $to,
                    ];
                }
            }
        }

        return $redirections;
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
        <div class="misc-pub-section wash-shopify-link">
            <span class="dashicons dashicons-store" style="color: #96bf48;"></span>
            <a href="<?php echo esc_url($shopifyUrl); ?>" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4; text-decoration: none;">
                <?php esc_html_e('View on Shopify', 'wash'); ?>
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
        $shopifyStore = get_option('wash_shopify_store', '');
        if (empty($shopifyStore)) {
            return null;
        }

        $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);
        $shopifyBlogId = get_post_meta($postId, '_wash_blog_id', true);
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
        $blogHandle = get_post_meta($postId, '_wash_blog_handle', true);
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
        $label = esc_js(__('View on Shopify', 'wash'));
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

    registerPlugin('wash-shopify-link', {
        render: function() {
            return createElement(
                PluginPostStatusInfo,
                { className: 'wash-shopify-link-panel' },
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
                $newColumns['wash_sync'] = '<span class="dashicons dashicons-store" title="' . esc_attr__('Shopify Sync', 'wash') . '" style="color: #96bf48;"></span>';
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
        if ($column !== 'wash_sync') {
            return;
        }

        $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);
        $syncedAt = get_post_meta($postId, '_wash_synced_at', true);
        $syncStatus = get_post_meta($postId, '_wash_sync_status', true);
        $queuedAt = get_post_meta($postId, '_wash_queued_at', true);

        if (!empty($shopifyArticleId)) {
            $shopifyUrl = $this->getShopifyArticleUrl($postId);
            $title = sprintf(
                __('Synced to Shopify on %s', 'wash'),
                $syncedAt ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($syncedAt)) : __('unknown date', 'wash')
            );
            echo '<a href="' . esc_url($shopifyUrl) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($title) . '">';
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>';
            echo '</a>';
        } elseif ($syncStatus === 'queued') {
            $title = sprintf(
                __('Sync queued on %s - processing...', 'wash'),
                $queuedAt ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($queuedAt)) : __('just now', 'wash')
            );
            echo '<span class="dashicons dashicons-update wash-syncing" style="color: #f0b849;" title="' . esc_attr($title) . '" data-post-id="' . esc_attr($postId) . '"></span>';
        } else {
            $post = get_post($postId);
            if ($post->post_status === 'publish') {
                echo '<span class="dashicons dashicons-minus" style="color: #ddd;" title="' . esc_attr__('Not synced', 'wash') . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-hidden" style="color: #ccc;" title="' . esc_attr__('Draft - will sync when published', 'wash') . '"></span>';
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

        $allowedTypes = array_map('trim', explode(',', get_option('wash_post_types', 'post,page')));
        if (!in_array($post->post_type, $allowedTypes, true)) {
            return $actions;
        }

        $shopifyArticleId = get_post_meta($post->ID, '_wash_article_id', true);

        if (!empty($shopifyArticleId)) {
            // Already synced - show "View on Shopify" link
            $shopifyUrl = $this->getShopifyArticleUrl($post->ID);
            $actions['wash_view'] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" style="color: #5c6ac4;">%s</a>',
                esc_url($shopifyUrl),
                __('View on Shopify', 'wash')
            );
        }

        if ($post->post_status === 'publish') {
            // Show "Sync to Shopify" action
            $syncUrl = wp_nonce_url(
                add_query_arg([
                    'action' => 'wash_sync',
                    'post_id' => $post->ID,
                ], admin_url('edit.php')),
                'wash_sync_' . $post->ID
            );
            $actionLabel = !empty($shopifyArticleId) ? __('Re-sync to Shopify', 'wash') : __('Sync to Shopify', 'wash');
            $actions['wash_sync'] = sprintf(
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
        if (!isset($_GET['action']) || $_GET['action'] !== 'wash_sync') {
            return;
        }
        if (!isset($_GET['post_id'])) {
            return;
        }

        $postId = absint($_GET['post_id']);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wash_sync_' . $postId)) {
            wp_die(__('Security check failed', 'wash'));
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_die(__('You do not have permission to sync this post', 'wash'));
        }

        $post = get_post($postId);
        if (!$post || $post->post_status !== 'publish') {
            $referer = wp_get_referer();
            $redirectUrl = ($referer && strpos($referer, admin_url()) === 0) ? $referer : admin_url('edit.php');
            wp_redirect(add_query_arg('wash_error', 'not_published', $redirectUrl));
            exit;
        }

        // Trigger manual sync (bypasses auto-sync option check)
        $sync = new WashSync();
        $sync->syncManually($postId, $post);

        // Check if sync was successful or queued
        $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);
        $syncStatus = get_post_meta($postId, '_wash_sync_status', true);
        $syncError = get_post_meta($postId, '_wash_sync_error', true);

        if (!empty($shopifyArticleId)) {
            $result = 'synced';
        } elseif ($syncStatus === 'queued') {
            $result = 'queued';
        } else {
            $result = 'error';
        }

        $redirectArgs = ['wash_result' => $result];
        if ($result === 'error' && !empty($syncError)) {
            $redirectArgs['wash_error_msg'] = urlencode($syncError);
        }

        // Determine redirect URL - prefer referer if it's an admin URL, otherwise use edit.php
        $referer = wp_get_referer();
        if ($referer && strpos($referer, admin_url()) === 0) {
            $redirectUrl = $referer;
        } else {
            // Build redirect to posts list for the post type
            $redirectUrl = admin_url('edit.php');
            if ($post->post_type !== 'post') {
                $redirectUrl = add_query_arg('post_type', $post->post_type, $redirectUrl);
            }
        }

        wp_redirect(add_query_arg($redirectArgs, $redirectUrl));
        exit;
    }

    /**
     * Render admin notices for sync results.
     */
    public function renderSyncNotices() {
        if (!isset($_GET['wash_result'])) {
            return;
        }

        $result = sanitize_key($_GET['wash_result']);
        $errorMsg = isset($_GET['wash_error_msg']) ? sanitize_text_field(urldecode($_GET['wash_error_msg'])) : '';
        $class = '';
        $message = '';
        $showUpgrade = false;

        switch ($result) {
            case 'synced':
                $class = 'notice-success';
                $message = __('Post synced to Shopify successfully.', 'wash');
                break;
            case 'queued':
                $class = 'notice-info';
                $message = __('Post queued for sync. It will appear on Shopify shortly.', 'wash');
                break;
            case 'error':
                $class = 'notice-error';
                if (!empty($errorMsg)) {
                    $message = $errorMsg;
                    // Check if this is a usage limit error
                    if (strpos($errorMsg, 'limit') !== false) {
                        $showUpgrade = true;
                    }
                } else {
                    $message = __('Failed to sync post to Shopify. Check the debug log for details.', 'wash');
                }
                break;
            case 'not_published':
                $class = 'notice-warning';
                $message = __('Only published posts can be synced to Shopify.', 'wash');
                break;
            default:
                return;
        }

        $shopifyStore = get_option('wash_shopify_store', '');
        $upgradeUrl = $shopifyStore ? "https://{$shopifyStore}.myshopify.com/admin/apps/wash-1" : '';

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>Wash:</strong> ' . esc_html($message);
        if ($showUpgrade && $upgradeUrl) {
            echo ' <a href="' . esc_url($upgradeUrl) . '" target="_blank" rel="noopener noreferrer" style="text-decoration: underline;">';
            echo esc_html__('Upgrade your plan', 'wash');
            echo '</a>';
        }
        echo '</p></div>';
    }

    /**
     * AJAX handler for manual sync.
     */
    public function ajaxManualSync() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $postId = absint($_POST['post_id'] ?? 0);
        if (!$postId) {
            wp_send_json_error(__('Invalid post ID', 'wash'));
        }

        $post = get_post($postId);
        if (!$post) {
            wp_send_json_error(__('Post not found', 'wash'));
        }

        // Trigger manual sync (bypasses auto-sync option check)
        $sync = new WashSync();
        $sync->syncManually($postId, $post);

        // Check result - could be synced immediately or queued for async processing
        $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);
        $syncStatus = get_post_meta($postId, '_wash_sync_status', true);
        $jobId = get_post_meta($postId, '_wash_job_id', true);

        if (!empty($shopifyArticleId)) {
            wp_send_json_success([
                'status' => 'synced',
                'shopifyArticleId' => $shopifyArticleId,
                'shopifyUrl' => $this->getShopifyArticleUrl($postId),
            ]);
        } elseif ($syncStatus === 'queued') {
            wp_send_json_success([
                'status' => 'queued',
                'jobId' => $jobId,
                'message' => __('Sync job queued. The article will be synced shortly.', 'wash'),
            ]);
        } else {
            wp_send_json_error(__('Sync failed', 'wash'));
        }
    }

    /**
     * AJAX handler for checking sync status of multiple posts.
     * Used for real-time updates without page refresh.
     */
    public function ajaxCheckSyncStatus() {
        check_ajax_referer('wash_sync_status', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $postIds = isset($_POST['post_ids']) ? array_map('absint', (array) $_POST['post_ids']) : [];
        if (empty($postIds)) {
            wp_send_json_error(__('No posts to check', 'wash'));
        }

        $results = [];
        foreach ($postIds as $postId) {
            $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);
            $syncStatus = get_post_meta($postId, '_wash_sync_status', true);
            $syncError = get_post_meta($postId, '_wash_sync_error', true);
            $syncedAt = get_post_meta($postId, '_wash_synced_at', true);

            if (!empty($shopifyArticleId)) {
                $results[$postId] = [
                    'status'     => 'synced',
                    'shopifyId'  => $shopifyArticleId,
                    'shopifyUrl' => $this->getShopifyArticleUrl($postId),
                    'syncedAt'   => $syncedAt,
                ];
            } elseif ($syncStatus === 'queued') {
                $results[$postId] = [
                    'status' => 'queued',
                ];
            } elseif (!empty($syncError)) {
                $results[$postId] = [
                    'status' => 'error',
                    'error'  => $syncError,
                ];
            } else {
                $results[$postId] = [
                    'status' => 'none',
                ];
            }
        }

        wp_send_json_success($results);
    }
}
