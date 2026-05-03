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
        // Background bulk-publish dispatch — runs sync in a separate request,
        // immune to per-request PHP/Apache/nginx/FastCGI timeouts.
        add_action('wash_async_sync_post', [$this, 'asyncSyncPost']);
        add_action('wp_ajax_wash_test_connection', [$this, 'ajaxTestConnection']);
        add_action('wp_ajax_wash_disconnect', [$this, 'ajaxDisconnect']);
        add_action('wp_ajax_wash_debug_session', [$this, 'ajaxDebugSession']);
        add_action('wp_ajax_wash_reset_session', [$this, 'ajaxResetSession']);
        add_action('wp_ajax_wash_fix_session', [$this, 'ajaxFixSession']);
        add_action('wp_ajax_wash_reset_to_defaults', [$this, 'ajaxResetToDefaults']);
        add_action('wp_ajax_wash_sync_settings_from_shopify', [$this, 'ajaxSyncSettingsFromShopify']);
        // Blog mappings AJAX handlers
        add_action('wp_ajax_wash_get_mappings', [$this, 'ajaxGetMappings']);
        add_action('wp_ajax_wash_get_shopify_blogs', [$this, 'ajaxGetShopifyBlogs']);
        add_action('wp_ajax_wash_get_shopify_markets', [$this, 'ajaxGetShopifyMarkets']);
        add_action('wp_ajax_wash_get_shop_locales', [$this, 'ajaxGetShopLocales']);
        add_action('wp_ajax_wash_get_shop_domains', [$this, 'ajaxGetShopDomains']);
        add_action('wp_ajax_wash_create_mapping', [$this, 'ajaxCreateMapping']);
        add_action('wp_ajax_wash_update_mapping', [$this, 'ajaxUpdateMapping']);
        add_action('wp_ajax_wash_delete_mapping', [$this, 'ajaxDeleteMapping']);
        // Meta fields AJAX handlers
        add_action('wp_ajax_wash_detect_meta_fields', [$this, 'ajaxDetectMetaFields']);
        add_action('wp_ajax_wash_save_meta_field_config', [$this, 'ajaxSaveMetaFieldConfig']);
        // Redirections AJAX handlers
        add_action('wp_ajax_wash_get_shopify_redirections', [$this, 'ajaxGetShopifyRedirections']);
        add_action('wp_ajax_wash_generate_redirections', [$this, 'ajaxGenerateRedirections']);
        add_action('wp_ajax_wash_sync_redirections', [$this, 'ajaxSyncRedirections']);
        add_action('wp_ajax_wash_fetch_wp_redirections', [$this, 'ajaxFetchWpRedirections']);
        add_action('wp_ajax_wash_save_redirections_settings', [$this, 'ajaxSaveRedirectionsSettings']);
        add_action('wp_ajax_wash_reorder_mappings', [$this, 'ajaxReorderMappings']);
        // Search & Replace AJAX handlers
        add_action('wp_ajax_wash_get_search_replace_rules', [$this, 'ajaxGetSearchReplaceRules']);
        add_action('wp_ajax_wash_save_search_replace_rules', [$this, 'ajaxSaveSearchReplaceRules']);
        add_action('wp_ajax_wash_scan_content_links', [$this, 'ajaxScanContentLinks']);
        add_action('wp_ajax_wash_get_shopify_links', [$this, 'ajaxGetShopifyLinks']);
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

        // Bulk actions for posts
        add_filter('bulk_actions-edit-post', [$this, 'addBulkActions']);
        add_filter('bulk_actions-edit-page', [$this, 'addBulkActions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handleBulkActions'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handleBulkActions'], 10, 3);

        // AJAX handler for manual sync
        add_action('wp_ajax_wash_manual_sync', [$this, 'ajaxManualSync']);

        // AJAX handler for sync status polling
        add_action('wp_ajax_wash_check_sync_status', [$this, 'ajaxCheckSyncStatus']);

        // Admin notices for sync results
        add_action('admin_notices', [$this, 'renderSyncNotices']);

        // Backup settings to Shopify App after settings update
        add_action('update_option_wash_sync_on_publish', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_sync_metafields', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_post_types', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_block_robots', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_meta_field_config', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_hreflangs_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_authors_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_author_mappings', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_files_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_webp_convert', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_allowed_file_types', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_rewrite_internal_urls', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_redirections_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_blocks_enabled', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_sync_blocks_meta', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_sync_blocks_css', [$this, 'scheduleSettingsBackup']);
        add_action('update_option_wash_search_replace_rules', [$this, 'scheduleSettingsBackup']);

        // Run the actual backup after all settings are updated (at shutdown)
        add_action('shutdown', [$this, 'runScheduledSettingsBackup']);
    }

    /**
     * Schedule settings backup to run at shutdown.
     * This prevents multiple API calls when multiple settings are updated at once.
     */
    public function scheduleSettingsBackup() {
        // Skip if restore is in progress (prevents backup loop from Shopify push)
        global $washRestoreInProgress;
        if (!empty($washRestoreInProgress)) {
            return;
        }

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
            washLog('[wash] Settings backup failed: ' . $result->get_error_message());
        } else {
            washLog('[wash] Settings backed up to Shopify App');
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
        $textSettings = [
            'wash_shopify_store',
            'wash_api_key',
            'wash_http_user',
            'wash_http_pass',
            'wash_post_types',
            'wash_allowed_html_tags',
        ];

        $checkboxSettings = [
            'wash_sync_on_publish',
            'wash_sync_metafields',
            'wash_block_robots',
            'wash_hreflangs_enabled',
            'wash_authors_enabled',
            'wash_files_enabled',
            'wash_webp_convert',
            'wash_rewrite_internal_urls',
            'wash_blocks_enabled',
            'wash_sync_blocks_meta',
            'wash_sync_blocks_css',
            'wash_redirections_enabled',
        ];

        foreach ($textSettings as $setting) {
            register_setting('wash_settings', $setting, [
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }

        foreach ($checkboxSettings as $setting) {
            register_setting('wash_settings', $setting, [
                'sanitize_callback' => [$this, 'sanitizeCheckbox'],
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
     * Sanitize checkbox value.
     * Unchecked checkboxes are not sent in HTML forms,
     * so absent values must be treated as '0'.
     *
     * @param mixed $input Raw input from form.
     * @return string '1' or '0'.
     */
    public function sanitizeCheckbox($input) {
        return $input === '1' ? '1' : '0';
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
        if (isset($_GET['api_key'])) {
            $apiKey = sanitize_text_field($_GET['api_key']);

            if (!empty($apiKey)) {
                update_option('wash_api_key', $apiKey);

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
            washLog('[wash] Failed to restore settings: ' . $response->get_error_message());
            return;
        }

        if (!empty($response['success']) && !empty($response['settings'])) {
            $this->apiClient->restoreSettings($response['settings']);
            washLog('[wash] Settings restored from backup');
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
                // Read-only mode
                'readOnlyMode'          => __('You do not have permission to modify Wash settings. Contact your administrator for access.', 'wash'),
                // Unlock/Lock redirections
                'unlock'                => __('Unlock', 'wash'),
                'lock'                  => __('Lock', 'wash'),
                'unlockToSync'          => __('Unlock to enable sync', 'wash'),
                'unlockWarningTitle'    => __('⚠️ Warning: Editing Redirections', 'wash'),
                'unlockWarning1'        => __('Changes to redirections directly affect your Shopify store.', 'wash'),
                'unlockWarning2'        => __('Incorrect redirections can break links and harm SEO.', 'wash'),
                'unlockWarning3'        => __('Make sure you know what you are doing.', 'wash'),
                'unlockConfirm'         => __('Do you want to unlock editing?', 'wash'),
                // Verify redirections
                'verifyLineError'       => __('Line', 'wash'),
                'verifyInvalidFormat'   => __('Invalid format - missing separator (→, ->, =>)', 'wash'),
                'verifyFromSlash'       => __('Source path should start with /', 'wash'),
                'verifyToSlash'         => __('Target path should start with / or http', 'wash'),
                'verifyDuplicate'       => __('Duplicate source path', 'wash'),
                'verifyFirstAt'         => __('first at line', 'wash'),
                'verifyLoop'            => __('Redirect loop - source equals target', 'wash'),
                'verifySuccess'         => __('Syntax valid!', 'wash'),
                'verifyRedirects'       => __('redirections', 'wash'),
                'verifyComments'        => __('comments', 'wash'),
                'verifyValidLines'      => __('valid', 'wash'),
                'verifyErrors'          => __('Errors', 'wash'),
                'verifyWarnings'        => __('Warnings', 'wash'),
                'saved'                 => __('Saved', 'wash'),
                // Sync from Shopify
                'syncFromShopify'       => __('Sync from Shopify', 'wash'),
                'syncingFromShopify'    => __('Syncing...', 'wash'),
                'confirmSyncFromShopify' => __('This will overwrite your local settings with settings from Shopify App. Continue?', 'wash'),
                'syncFromShopifySuccess' => __('Settings synced from Shopify App successfully.', 'wash'),
                'failedSyncFromShopify' => __('Failed to sync settings: ', 'wash'),
                // Search & Replace
                'srSaving'              => __('Saving...', 'wash'),
                'srSaved'               => __('Rules saved.', 'wash'),
                'srSaveFailed'          => __('Failed to save rules: ', 'wash'),
                'srNoRules'             => __('No rules defined yet. Click "Add Rule" to create one.', 'wash'),
                'srRemoveConfirm'       => __('Remove this rule?', 'wash'),
                'srDrag'                => __('Drag to reorder', 'wash'),
                'srOverlapDuplicate'    => __('Duplicate rule', 'wash'),
                'srOverlapContains'     => __('Overlapping rule', 'wash'),
            ],
        ]);

        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_script(
            'wash-search-replace',
            $pluginUrl . 'admin/js/search-replace.js',
            ['jquery', 'jquery-ui-sortable', 'wash-admin'],
            WASH_VERSION . '.' . filemtime(WASH_PLUGIN_DIR . 'admin/js/search-replace.js'),
            true
        );
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
     * AJAX handler for detecting available meta fields.
     * Scans recent posts and returns field list, preserving existing sync toggles.
     */
    public function ajaxDetectMetaFields() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $metafields = new WashMetafields();
        $detected = $metafields->detectAvailableFields();
        $existing = $metafields->getFieldConfig();

        // Build lookup of existing sync preferences
        $existingMap = [];
        foreach ($existing as $field) {
            $existingMap[$field['namespace'] . '.' . $field['key']] = $field['syncEnabled'] ?? false;
        }

        // Merge: preserve user's syncEnabled for known fields, new fields default to false
        foreach ($detected as &$field) {
            $id = $field['namespace'] . '.' . $field['key'];
            $field['syncEnabled'] = $existingMap[$id] ?? false;
        }
        unset($field);

        $metafields->saveFieldConfig($detected);

        // Backup to Shopify immediately
        $this->apiClient->backupSettings();

        wp_send_json_success(['fields' => $detected]);
    }

    /**
     * AJAX handler for saving the full meta field config.
     * Receives JSON array of field descriptors with syncEnabled flags.
     */
    public function ajaxSaveMetaFieldConfig() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $raw = wp_unslash($_POST['config'] ?? '');
        $config = json_decode($raw, true);

        if (!is_array($config)) {
            wp_send_json_error(__('Invalid field config', 'wash'));
        }

        $metafields = new WashMetafields();
        $metafields->saveFieldConfig($config);

        // Backup to Shopify immediately (don't rely on shutdown hook in AJAX)
        $backupResult = $this->apiClient->backupSettings();
        $backed = !is_wp_error($backupResult);

        wp_send_json_success(['saved' => count($config), 'synced' => $backed]);
    }

    /**
     * AJAX handler for resetting all settings to defaults.
     */
    public function ajaxResetToDefaults() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        // Default settings as documented in configuration.md
        $defaults = [
            // Sync Settings
            'wash_sync_on_publish'   => '1',       // Enabled
            'wash_post_types'        => 'post,page',
            'wash_block_robots'      => '1',       // Enabled

            // Files & Media
            'wash_files_enabled'     => '1',       // Enabled
            'wash_webp_convert'      => '0',       // Disabled
            'wash_allowed_file_types' => ['images'],

            // Metafields
            'wash_sync_metafields'   => '1',       // Enabled
            'wash_meta_field_config' => '[]',      // Empty — triggers re-detection

            // Gutenberg Blocks
            'wash_blocks_enabled'    => '1',       // Enabled
            'wash_sync_blocks_meta'  => '1',       // Enabled
            'wash_sync_blocks_css'   => '0',       // Disabled

            // Advanced Features
            'wash_hreflangs_enabled'     => '0',   // Disabled
            'wash_authors_enabled'       => '0',   // Disabled
            'wash_rewrite_internal_urls' => '1',   // Enabled
            'wash_allowed_html_tags'     => '',    // Empty
            'wash_redirections_enabled'  => '0',   // Disabled

            // Clear author mappings
            'wash_author_mappings'   => [],
        ];

        // Update each option to its default value
        foreach ($defaults as $option => $value) {
            update_option($option, $value);
        }

        // Backup the reset settings to Shopify App
        $this->scheduleSettingsBackup();
        $this->runScheduledSettingsBackup();

        wp_send_json_success([
            'message' => __('All settings have been reset to defaults.', 'wash'),
        ]);
    }

    /**
     * AJAX handler for syncing settings from Shopify App to WordPress.
     * Pulls settings stored in Shopify and applies them locally.
     */
    public function ajaxSyncSettingsFromShopify() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $response = $this->apiClient->getBackedUpSettings();

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        if (empty($response['success']) || empty($response['settings'])) {
            wp_send_json_error(__('No settings found in Shopify App. Save settings in Shopify first.', 'wash'));
        }

        $this->apiClient->restoreSettings($response['settings']);

        wp_send_json_success([
            'message' => __('Settings synced from Shopify App successfully.', 'wash'),
        ]);
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
     * AJAX handler for fetching Shopify markets with locales.
     */
    public function ajaxGetShopifyMarkets() {
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $result = $this->apiClient->get('/api/shopify-markets');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['markets' => $result['markets'] ?? []]);
    }

    /**
     * AJAX handler for fetching published shop-level locales (no market scope).
     * Used by the blog mappings UI to expose the "🌍 Global (no market)" group.
     */
    public function ajaxGetShopLocales() {
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $result = $this->apiClient->get('/api/shop-locales');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['locales' => $result['locales'] ?? []]);
    }

    /**
     * AJAX handler for getting shop domains.
     */
    public function ajaxGetShopDomains() {
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $result = $this->apiClient->get('/api/shop-domains');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['domains' => $result['domains'] ?? []]);
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
            'destination'    => sanitize_text_field($_POST['destination'] ?? 'article'),
            'wpPostTypes'    => sanitize_text_field($_POST['wpPostTypes'] ?? 'post'),
            'ruleType'       => sanitize_text_field($_POST['ruleType'] ?? 'default'),
            'ruleValue'      => sanitize_text_field($_POST['ruleValue'] ?? ''),
            'shopifyBlogId'  => sanitize_text_field($_POST['shopifyBlogId'] ?? ''),
            'newBlogName'    => sanitize_text_field($_POST['newBlogName'] ?? ''),
            'priority'       => absint($_POST['priority'] ?? 0),
            'marketId'       => sanitize_text_field($_POST['marketId'] ?? ''),
            'shopifyLocale'  => sanitize_text_field($_POST['shopifyLocale'] ?? ''),
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
            'destination'    => sanitize_text_field($_POST['destination'] ?? 'article'),
            'wpPostTypes'    => sanitize_text_field($_POST['wpPostTypes'] ?? 'post'),
            'ruleType'       => sanitize_text_field($_POST['ruleType'] ?? 'default'),
            'ruleValue'      => sanitize_text_field($_POST['ruleValue'] ?? ''),
            'shopifyBlogId'  => sanitize_text_field($_POST['shopifyBlogId'] ?? ''),
            'priority'       => absint($_POST['priority'] ?? 0),
            'marketId'       => sanitize_text_field($_POST['marketId'] ?? ''),
            'shopifyLocale'  => sanitize_text_field($_POST['shopifyLocale'] ?? ''),
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
     * AJAX handler for reordering blog mappings (drag-and-drop priority).
     */
    public function ajaxReorderMappings() {
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $updates = json_decode(stripslashes($_POST['updates'] ?? '[]'), true);
        if (!is_array($updates)) {
            wp_send_json_error(__('Invalid data', 'wash'));
        }

        $errors = [];
        foreach ($updates as $update) {
            $id = sanitize_text_field($update['id'] ?? '');
            $priority = intval($update['priority'] ?? 0);
            if (empty($id)) {
                continue;
            }

            $result = $this->apiClient->post('/api/blog-mappings/' . $id, [
                'priority' => $priority,
            ]);

            if (is_wp_error($result)) {
                $errors[] = $id . ': ' . $result->get_error_message();
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(implode(', ', $errors));
        }

        wp_send_json_success(['updated' => count($updates)]);
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
        $allowedTypes = array_map('trim', explode(',', get_option('wash_post_types', 'post,page')));

        $posts = get_posts([
            'post_type'      => $allowedTypes,
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
     * AJAX handler to save redirections settings.
     */
    public function ajaxSaveRedirectionsSettings() {
        check_ajax_referer('wash_redirections', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0';
        update_option('wash_redirections_enabled', $enabled);

        wp_send_json_success([
            'message' => __('Settings saved', 'wash'),
            'enabled' => $enabled,
        ]);
    }

    /**
     * AJAX handler: get search & replace rules.
     */
    public function ajaxGetSearchReplaceRules() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $rules = get_option('wash_search_replace_rules', []);
        if (!is_array($rules)) {
            $rules = [];
        }

        wp_send_json_success(['rules' => $rules]);
    }

    /**
     * AJAX handler: save search & replace rules.
     */
    public function ajaxSaveSearchReplaceRules() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $rawRules = isset($_POST['rules']) ? $_POST['rules'] : [];
        if (!is_array($rawRules)) {
            $rawRules = [];
        }

        $validTypes = ['string', 'url', 'shortcode', 'block'];
        $sanitized = [];
        foreach ($rawRules as $rule) {
            $search = isset($rule['search']) ? wp_kses_post($rule['search']) : '';
            $replace = isset($rule['replace']) ? wp_kses_post($rule['replace']) : '';
            $type = isset($rule['type']) && in_array($rule['type'], $validTypes, true) ? $rule['type'] : 'string';

            if ($search === '') {
                continue;
            }

            $sanitized[] = [
                'search'  => $search,
                'replace' => $replace,
                'type'    => $type,
            ];
        }

        update_option('wash_search_replace_rules', $sanitized);

        wp_send_json_success([
            'message' => __('Rules saved', 'wash'),
            'rules'   => $sanitized,
        ]);
    }

    /**
     * AJAX: scan all published content for URLs, domains, shortcodes.
     * Cached for 10 minutes to avoid repeated DB queries.
     */
    public function ajaxScanContentLinks() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $cached = get_transient('wash_content_links');
        if ($cached !== false) {
            wp_send_json_success($cached);
            return;
        }

        $postTypes = array_map('trim', explode(',', get_option('wash_post_types', 'post,page')));

        $posts = get_posts([
            'post_type'      => $postTypes,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $urls = [];
        $domains = [];
        $shortcodes = [];
        $blocks = [];

        foreach ($posts as $postId) {
            $content = get_post_field('post_content', $postId);
            if (empty($content)) {
                continue;
            }

            // Extract URLs from all contexts: attributes, inline CSS, standalone text
            $urlPatterns = [
                '/(?:href|src|srcset|action|poster|data-[\w-]+)=["\']([^"\']+)["\']/i', // HTML attributes
                '/url\(["\']?([^"\')\s]+)["\']?\)/i',                                   // CSS url()
                '/(?:^|[\s>])((https?:\/\/[^\s<"\']+))/m',                               // Standalone URLs
            ];
            foreach ($urlPatterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[1] as $url) {
                        $url = trim($url);
                        if (empty($url) || $url === '#' || strpos($url, 'data:') === 0 || strlen($url) < 5) {
                            continue;
                        }
                        $urls[$url] = ($urls[$url] ?? 0) + 1;

                        // Extract domain
                        $parsed = wp_parse_url($url);
                        if (!empty($parsed['host'])) {
                            $scheme = ($parsed['scheme'] ?? 'https') . '://';
                            $domain = $scheme . $parsed['host'];
                            $domains[$domain] = ($domains[$domain] ?? 0) + 1;
                        }
                    }
                }
            }

            // Also extract internal relative paths (/page/, /category/slug/)
            if (preg_match_all('/href=["\'](\/[^"\']+)["\']/i', $content, $matches)) {
                foreach ($matches[1] as $path) {
                    $path = trim($path);
                    if ($path !== '/' && strlen($path) > 1) {
                        $urls[site_url($path)] = ($urls[site_url($path)] ?? 0) + 1;
                    }
                }
            }

            // Extract shortcodes
            if (preg_match_all('/\[(\w+)(?:\s[^\]]*?)?\]/', $content, $matches)) {
                foreach ($matches[0] as $idx => $full) {
                    $tag = $matches[1][$idx];
                    $shortcodes[$full] = [
                        'tag'   => $tag,
                        'full'  => $full,
                        'count' => ($shortcodes[$full]['count'] ?? 0) + 1,
                    ];
                }
            }

            // Extract Gutenberg blocks (including embeds with provider info)
            if (preg_match_all('/<!--\s*wp:([a-z0-9\-\/]+)(?:\s(\{[^}]*\})|\s*-->)/i', $content, $matches)) {
                foreach ($matches[1] as $idx => $blockName) {
                    $key = 'wp:' . $blockName;
                    $blocks[$key] = ($blocks[$key] ?? 0) + 1;

                    // For embeds, also add provider-specific variant
                    if ($blockName === 'embed' && !empty($matches[2][$idx])) {
                        $attrs = json_decode($matches[2][$idx], true);
                        if (!empty($attrs['providerNameSlug'])) {
                            $providerKey = 'wp:embed/' . $attrs['providerNameSlug'];
                            $blocks[$providerKey] = ($blocks[$providerKey] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        // Add all registered block types from WordPress
        $registry = \WP_Block_Type_Registry::get_instance();
        foreach ($registry->get_all_registered() as $name => $block) {
            $key = 'wp:' . $name;
            if (!isset($blocks[$key])) {
                $blocks[$key] = 0; // registered but not used in content
            }
        }

        // Add known embed providers as wp:embed/provider
        $embedProviders = [
            'youtube', 'vimeo', 'dailymotion', 'flickr', 'smugmug', 'scribd',
            'twitter', 'soundcloud', 'spotify', 'imgur', 'issuu', 'mixcloud',
            'ted', 'animoto', 'tumblr', 'kickstarter', 'reverbnation',
            'videopress', 'reddit', 'speakerdeck', 'amazon', 'someecards',
            'tiktok', 'pinterest', 'wolframcloud', 'pocketcasts', 'anghami',
            'bluesky', 'canva', 'wordpress-tv',
        ];
        foreach ($embedProviders as $provider) {
            $key = 'wp:embed/' . $provider;
            if (!isset($blocks[$key])) {
                $blocks[$key] = 0;
            }
        }

        // Sort: used blocks first, then alphabetically
        uksort($blocks, function ($a, $b) use ($blocks) {
            if ($blocks[$a] > 0 && $blocks[$b] === 0) return -1;
            if ($blocks[$a] === 0 && $blocks[$b] > 0) return 1;
            return strcmp($a, $b);
        });

        // Sort by frequency
        arsort($urls);
        arsort($domains);

        $result = [
            'urls'       => array_slice(array_keys($urls), 0, 200),
            'domains'    => array_slice(array_keys($domains), 0, 50),
            'shortcodes' => array_values($shortcodes),
            'blocks'     => array_map(function ($name, $count) {
                return ['name' => $name, 'count' => $count];
            }, array_keys($blocks), array_values($blocks)),
            'postCount'  => count($posts),
        ];

        set_transient('wash_content_links', $result, 600); // 10 minutes

        wp_send_json_success($result);
    }

    /**
     * AJAX: get available Shopify links (products, pages, blogs, collections).
     */
    public function ajaxGetShopifyLinks() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash'));
        }

        $cached = get_transient('wash_shopify_links');
        if ($cached !== false) {
            wp_send_json_success($cached);
            return;
        }

        $links = [];

        // Products
        $products = $this->apiClient->get('/api/shopify-products');
        if (!is_wp_error($products) && !empty($products['products'])) {
            foreach ($products['products'] as $p) {
                $handle = $p['handle'] ?? '';
                if ($handle) {
                    $links[] = [
                        'url'  => '/products/' . $handle,
                        'label' => $p['title'] ?? $handle,
                        'type' => 'product',
                    ];
                }
            }
        }

        // Collections
        $collections = $this->apiClient->get('/api/shopify-collections');
        if (!is_wp_error($collections) && !empty($collections['collections'])) {
            foreach ($collections['collections'] as $c) {
                $handle = $c['handle'] ?? '';
                if ($handle) {
                    $links[] = [
                        'url'  => '/collections/' . $handle,
                        'label' => $c['title'] ?? $handle,
                        'type' => 'collection',
                    ];
                }
            }
        }

        // Blogs + synced articles
        $blogs = $this->apiClient->get('/api/shopify-blogs');
        if (!is_wp_error($blogs) && !empty($blogs['blogs'])) {
            foreach ($blogs['blogs'] as $b) {
                $handle = $b['handle'] ?? '';
                if ($handle) {
                    $links[] = [
                        'url'  => '/blogs/' . $handle,
                        'label' => $b['title'] ?? $handle,
                        'type' => 'blog',
                    ];
                }
            }
        }

        // Synced articles (from WordPress sync logs)
        $syncedPosts = get_posts([
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [['key' => '_wash_article_id', 'compare' => 'EXISTS']],
            'fields'         => 'ids',
        ]);
        foreach ($syncedPosts as $postId) {
            $blogHandle = get_post_meta($postId, '_wash_blog_handle', true) ?: 'news';
            $slug = get_post_field('post_name', $postId);
            $title = get_the_title($postId);
            if ($slug) {
                $links[] = [
                    'url'  => '/blogs/' . $blogHandle . '/' . $slug,
                    'label' => $title,
                    'type' => 'article',
                ];
            }
        }

        // Synced pages
        $syncedPages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [['key' => '_wash_article_id', 'compare' => 'EXISTS']],
            'fields'         => 'ids',
        ]);
        foreach ($syncedPages as $pageId) {
            $slug = get_post_field('post_name', $pageId);
            $title = get_the_title($pageId);
            if ($slug) {
                $links[] = [
                    'url'  => '/pages/' . $slug,
                    'label' => $title,
                    'type' => 'page',
                ];
            }
        }

        // Standard Shopify pages
        $defaultPages = [
            ['url' => '/', 'label' => 'Homepage', 'type' => 'page'],
            ['url' => '/cart', 'label' => 'Cart', 'type' => 'page'],
            ['url' => '/collections/all', 'label' => 'All Products', 'type' => 'collection'],
            ['url' => '/account', 'label' => 'Account', 'type' => 'page'],
            ['url' => '/search', 'label' => 'Search', 'type' => 'page'],
        ];
        $links = array_merge($links, $defaultPages);

        $result = [
            'links' => $links,
        ];

        set_transient('wash_shopify_links', $result, 600); // 10 minutes

        wp_send_json_success($result);
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

            if (preg_match('/^(.+?)\s*(?:->|→|=>)\s*(.+?)(?:\s+#.*)?$/', $line, $matches)) {
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
        $syncError = get_post_meta($postId, '_wash_sync_error', true);
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
        } elseif (!empty($syncError) && strpos($syncError, 'PENDING_SOURCE') !== false) {
            // Translation post waiting for its master language version to sync first.
            $masterPostId = preg_match('/WP post #(\d+)/', $syncError, $m) ? (int) $m[1] : 0;
            $title = $masterPostId
                ? sprintf(__('Waiting — master post #%d must sync to Shopify first', 'wash'), $masterPostId)
                : __('Waiting — master language version must sync to Shopify first', 'wash');
            echo '<span class="dashicons dashicons-clock" style="color: #2271b1;" title="' . esc_attr($title) . '"></span>';
        } elseif (!empty($syncError)) {
            // Any other persisted sync failure (Shop session not found, Handle taken, USAGE_LIMIT_EXCEEDED, etc).
            $shortError = function_exists('mb_strimwidth') ? mb_strimwidth($syncError, 0, 120, '…') : substr($syncError, 0, 120);
            $title = sprintf(__('Sync failed: %s', 'wash'), $shortError);
            echo '<span class="dashicons dashicons-warning" style="color: #d63638;" title="' . esc_attr($title) . '"></span>';
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
        // Per-post edit screen: surface async sync failures so the merchant
        // sees them on the next visit. Auto-sync (on save_post) finishes after
        // the WordPress UI has already shown its "Post published" notice, so a
        // queue-side failure used to vanish silently into _wash_sync_error meta.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->base === 'post' && !empty($_GET['post'])) {
            $postId = (int) $_GET['post'];
            $syncError = get_post_meta($postId, '_wash_sync_error', true);
            if (!empty($syncError)) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>Wash:</strong> ';
                echo esc_html__('Last sync to Shopify failed:', 'wash');
                echo ' ' . esc_html($syncError);
                echo '</p></div>';
            }
        }

        // Show persistent notice when plugin is not connected to Shopify
        if (!$this->apiClient->isConfigured()) {
            $settingsUrl = admin_url('options-general.php?page=wash');
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>Wash:</strong> ';
            printf(
                /* translators: %1$s = opening link tag, %2$s = closing link tag */
                esc_html__('Plugin is not connected to Shopify. Posts will not sync until you %1$sconfigure the connection%2$s.', 'wash'),
                '<a href="' . esc_url($settingsUrl) . '">',
                '</a>'
            );
            echo '</p></div>';
        }

        // Handle bulk action notices
        if (isset($_GET['wash_bulk_action'])) {
            $this->renderBulkActionNotice();
            return;
        }

        // Handle bulk error notices
        if (isset($_GET['wash_bulk_error'])) {
            $error = sanitize_key($_GET['wash_bulk_error']);
            if ($error === 'not_configured') {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Wash:</strong> ';
                echo esc_html__('Plugin is not connected to Shopify. Please configure the connection first.', 'wash');
                echo '</p></div>';
            }
            return;
        }

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
     * Render admin notice for bulk actions.
     */
    private function renderBulkActionNotice() {
        $action = sanitize_key($_GET['wash_bulk_action']);
        $processed = absint($_GET['wash_bulk_processed'] ?? 0);
        $skipped = absint($_GET['wash_bulk_skipped'] ?? 0);
        $errors = absint($_GET['wash_bulk_errors'] ?? 0);

        $actionLabel = '';
        switch ($action) {
            case 'wash_publish':
                $actionLabel = __('published to Shopify', 'wash');
                break;
            case 'wash_republish':
                $actionLabel = __('re-published to Shopify', 'wash');
                break;
            case 'wash_unpublish':
                $actionLabel = __('unpublished from Shopify', 'wash');
                break;
            default:
                return;
        }

        $messages = [];

        if ($processed > 0) {
            $messages[] = sprintf(
                /* translators: 1: number of posts, 2: action performed */
                _n('%1$d post %2$s.', '%1$d posts %2$s.', $processed, 'wash'),
                $processed,
                $actionLabel
            );
        }

        if ($skipped > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of posts skipped */
                _n('%d post skipped.', '%d posts skipped.', $skipped, 'wash'),
                $skipped
            );
        }

        if ($errors > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of errors */
                _n('%d error occurred.', '%d errors occurred.', $errors, 'wash'),
                $errors
            );
        }

        if (empty($messages)) {
            return;
        }

        $class = $errors > 0 ? 'notice-warning' : ($processed > 0 ? 'notice-success' : 'notice-info');
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>Wash:</strong> ';
        echo esc_html(implode(' ', $messages));
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

    /**
     * Add bulk actions for Shopify sync to posts list.
     *
     * @param array $bulkActions Existing bulk actions.
     * @return array Modified bulk actions.
     */
    public function addBulkActions($bulkActions) {
        if (!$this->apiClient->isConfigured()) {
            return $bulkActions;
        }

        $bulkActions['wash_publish'] = __('Publish to Shopify', 'wash');
        $bulkActions['wash_republish'] = __('Re-publish to Shopify', 'wash');
        $bulkActions['wash_unpublish'] = __('Unpublish from Shopify', 'wash');

        return $bulkActions;
    }

    /**
     * Handle bulk actions for Shopify sync.
     *
     * @param string $redirectTo Redirect URL.
     * @param string $doaction   Action being performed.
     * @param array  $postIds    Array of post IDs.
     * @return string Modified redirect URL.
     */
    public function handleBulkActions($redirectTo, $doaction, $postIds) {
        washLog("[wash] handleBulkActions called: action={$doaction}, postIds=" . implode(',', $postIds));

        if (!in_array($doaction, ['wash_publish', 'wash_republish', 'wash_unpublish'], true)) {
            washLog("[wash] handleBulkActions: action not recognized, returning");
            return $redirectTo;
        }

        if (!$this->apiClient->isConfigured()) {
            washLog("[wash] handleBulkActions: API not configured");
            return add_query_arg('wash_bulk_error', 'not_configured', $redirectTo);
        }

        // Each post sync = HTTP roundtrip + metafields. Doing this synchronously
        // breaks at any host-level timeout (PHP max_execution_time, nginx
        // proxy_read_timeout, FastCGI request_terminate_timeout, etc).
        // Strategy: dispatch each sync via WP-Cron (single event, ASAP) so the
        // bulk action returns in milliseconds and each sync runs in its own
        // request — immune to host-level timeouts. Unpublish stays sync because
        // it must report errors to the user.
        $allowedTypes = array_map('trim', explode(',', get_option('wash_post_types', 'post,page')));
        washLog("[wash] handleBulkActions: allowedTypes=" . implode(',', $allowedTypes));
        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($postIds as $postId) {
            $post = get_post($postId);
            if (!$post) {
                $skipped++;
                continue;
            }

            if (!in_array($post->post_type, $allowedTypes, true)) {
                $skipped++;
                continue;
            }

            $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);

            switch ($doaction) {
                case 'wash_publish':
                    if (!empty($shopifyArticleId)) {
                        $skipped++;
                        continue 2;
                    }
                    if ($post->post_status !== 'publish') {
                        $skipped++;
                        continue 2;
                    }
                    $this->scheduleAsyncSync($postId);
                    $processed++;
                    break;

                case 'wash_republish':
                    if (empty($shopifyArticleId)) {
                        $skipped++;
                        continue 2;
                    }
                    if ($post->post_status !== 'publish') {
                        $skipped++;
                        continue 2;
                    }
                    $this->scheduleAsyncSync($postId);
                    $processed++;
                    break;

                case 'wash_unpublish':
                    if (empty($shopifyArticleId)) {
                        $skipped++;
                        continue 2;
                    }
                    $result = $this->unpublishFromShopify($postId, $post);
                    if (is_wp_error($result)) {
                        $errors++;
                    } else {
                        $processed++;
                    }
                    break;
            }
        }

        // Kick the cron loop so scheduled syncs start immediately rather than
        // waiting for the next public request to trigger wp-cron.
        if ($processed > 0 && in_array($doaction, ['wash_publish', 'wash_republish'], true)) {
            $this->triggerCronAsync();
        }

        $redirectTo = add_query_arg([
            'wash_bulk_action' => $doaction,
            'wash_bulk_processed' => $processed,
            'wash_bulk_skipped' => $skipped,
            'wash_bulk_errors' => $errors,
        ], $redirectTo);

        return $redirectTo;
    }

    /**
     * Schedule a single post sync via WP-Cron — runs in a separate request
     * so it cannot be killed by per-request timeouts (PHP, nginx, FastCGI, etc).
     *
     * @param int $postId Post ID.
     */
    private function scheduleAsyncSync($postId) {
        // Args must be array — WP-Cron passes it spread to the action handler.
        // Use postId as event arg so duplicate scheduling for the same post
        // collapses to a single cron event.
        if (!wp_next_scheduled('wash_async_sync_post', [(int) $postId])) {
            wp_schedule_single_event(time(), 'wash_async_sync_post', [(int) $postId]);
        }
    }

    /**
     * Background handler for scheduled bulk syncs.
     * Each call runs in its own request via WP-Cron — no shared timeout.
     *
     * @param int $postId Post ID.
     */
    public function asyncSyncPost($postId) {
        @set_time_limit(0);
        $post = get_post((int) $postId);
        if (!$post || $post->post_status !== 'publish') {
            washLog("[wash] asyncSyncPost: skipping {$postId} — not published");
            return;
        }
        washLog("[wash] asyncSyncPost: syncing {$postId}");
        $sync = new WashSync();
        $sync->syncManually((int) $postId, $post);
    }

    /**
     * Fire-and-forget HTTP request to wp-cron.php to start the cron loop now.
     * Without this, scheduled events wait for the next public page request.
     */
    private function triggerCronAsync() {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            // Site uses real cron — events will be picked up by system cron.
            return;
        }
        $cronUrl = site_url('wp-cron.php?doing_wp_cron=' . microtime(true));
        wp_remote_post($cronUrl, [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    /**
     * Unpublish a post from Shopify.
     *
     * @param int     $postId Post ID.
     * @param WP_Post $post   Post object.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    private function unpublishFromShopify($postId, $post) {
        $endpoint = $post->post_type === 'page' ? '/api/page-sync' : '/api/content-sync';
        washLog("[wash] unpublishFromShopify: postId={$postId}, endpoint={$endpoint}");

        $result = $this->apiClient->post($endpoint, [
            'action'   => 'delete',
            'wpPostId' => $postId,
            'wpSiteId' => get_current_blog_id(),
        ]);

        washLog("[wash] unpublishFromShopify result: " . print_r($result, true));

        if (is_wp_error($result)) {
            washLog("[wash] unpublishFromShopify error: " . $result->get_error_message());
            return $result;
        }

        // Check if API returned an error in the response
        if (is_array($result) && isset($result['error'])) {
            washLog("[wash] unpublishFromShopify API error: " . $result['error']);
            return new WP_Error('wash_api_error', $result['error']);
        }

        // Clear local sync meta
        delete_post_meta($postId, '_wash_article_id');
        delete_post_meta($postId, '_wash_blog_id');
        delete_post_meta($postId, '_wash_blog_handle');
        delete_post_meta($postId, '_wash_synced_at');
        delete_post_meta($postId, '_wash_sync_status');
        delete_post_meta($postId, '_wash_sync_error');

        washLog("[wash] unpublishFromShopify: success, cleared meta for postId={$postId}");
        return true;
    }
}
