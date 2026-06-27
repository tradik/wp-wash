<?php
/**
 * Admin settings page for Wash.
 * Provides UI for configuring Shopify App connection and sync preferences.
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

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
        add_action('wp_ajax_wash_save_consent', [$this, 'ajaxSaveConsent']);
        add_action('wp_ajax_wash_debug_session', [$this, 'ajaxDebugSession']);
        add_action('wp_ajax_wash_reset_session', [$this, 'ajaxResetSession']);
        add_action('wp_ajax_wash_fix_session', [$this, 'ajaxFixSession']);
        add_action('wp_ajax_wash_reset_to_defaults', [$this, 'ajaxResetToDefaults']);
        add_action('wp_ajax_wash_sync_settings_from_shopify', [$this, 'ajaxSyncSettingsFromShopify']);
        add_action('wp_ajax_wash_refresh_all_statuses', [$this, 'ajaxRefreshAllStatuses']);
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

        // Protect the primary (source) post of a translation group from deletion:
        // its Shopify article holds the locale translations, so deleting it would
        // orphan every translation. Denying delete_post hides the Trash link and
        // blocks single/bulk trash for that post until its translations are removed.
        add_filter('map_meta_cap', [$this, 'protectTranslationSourceCaps'], 10, 4);

        // Handle manual sync action
        add_action('admin_init', [$this, 'handleManualSync']);
        // Daily cron: clear old _wash_sync_error meta on currently-published posts
        add_action('wash_clear_stale_errors', [$this, 'clearStaleErrors']);
        // Hidden admin page: JS-driven progress view for inline bulk publish
        add_action('admin_menu', [$this, 'addBulkProgressPage'], 99);
        add_action('wp_ajax_wash_bulk_sync_batch', [$this, 'ajaxBulkSyncBatch']);

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
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- hooked to wp_redirect inside the Settings API redirect cycle, which has already verified the options.php nonce by the time this filter fires. The tab value is only used to add a query arg.
            if (isset($_POST['_wp_http_referer_tab']) && !empty($_POST['_wp_http_referer_tab'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                $tab = sanitize_key(wp_unslash($_POST['_wp_http_referer_tab']));
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
            __('Wash Settings', 'wash-blog-manager'),
            __('Wash: Manager', 'wash-blog-manager'),
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
            'wash_transform_rankmath_faq',
            'wash_redirections_enabled',
            'wash_auto_clear_errors',
            'wash_sync_inline',
            'wash_uninstall_remove_data',
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
     *
     * This is the post-OAuth handoff from the Shopify-hosted app to WordPress:
     * the user clicks "Connect" inside the Shopify admin and the Shopify app
     * server-side-redirects back to `wp-admin/options-general.php?page=wash&wash_callback=1&wash_state=...&api_key=...&store=...`.
     *
     * CSRF protection: the connect button embeds a `wash_connect` nonce in the
     * `return_url` it hands to the Shopify app, which redirects that URL back
     * verbatim (it only appends api_key/store via URL::searchParams.set). We
     * verify that nonce here before writing any credentials, so a forged link
     * cannot trick a logged-in admin into overwriting their stored API key.
     * Capability (`manage_options`) is checked in addition to the nonce.
     */
    public function handleCallback() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing guards only; the wash_connect nonce is verified below before any write.
        if (!isset($_GET['page']) || $_GET['page'] !== 'wash' || !isset($_GET['wash_callback'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        // CSRF gate: reject callbacks that don't carry the one-time nonce we issued.
        $state = isset($_GET['wash_state']) ? sanitize_text_field(wp_unslash($_GET['wash_state'])) : '';
        if (!wp_verify_nonce($state, 'wash_connect')) {
            wp_safe_redirect(admin_url('options-general.php?page=wash&connect_error=state'));
            exit;
        }

        // Receive credentials from Shopify app callback
        if (isset($_GET['api_key'])) {
            $apiKey = sanitize_text_field(wp_unslash($_GET['api_key']));

            if (!empty($apiKey)) {
                update_option('wash_api_key', $apiKey);

                if (isset($_GET['store'])) {
                    update_option('wash_shopify_store', sanitize_text_field(wp_unslash($_GET['store'])));
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
                wp_safe_redirect($redirectUrl);
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
                    'synced'  => __('Synced to Shopify', 'wash-blog-manager'),
                    'queued'  => __('Sync in progress...', 'wash-blog-manager'),
                    'error'   => __('Sync failed', 'wash-blog-manager'),
                    'success' => __('Post synced to Shopify successfully.', 'wash-blog-manager'),
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
                'testing'               => __('Testing connection...', 'wash-blog-manager'),
                'success'               => __('Connection successful!', 'wash-blog-manager'),
                'error'                 => __('Connection failed: ', 'wash-blog-manager'),
                'testConnection'        => __('Test Connection', 'wash-blog-manager'),
                // Disconnect
                'confirmDisconnect'     => __('Are you sure you want to disconnect from Shopify?', 'wash-blog-manager'),
                'failedDisconnect'      => __('Failed to disconnect. Please try again.', 'wash-blog-manager'),
                // Debug session
                'debugSession'          => __('Debug Session', 'wash-blog-manager'),
                'checking'              => __('Checking...', 'wash-blog-manager'),
                'networkErrorDebug'     => __('Network error - could not reach debug endpoint', 'wash-blog-manager'),
                // Reset session
                'resetSession'          => __('Reset Session', 'wash-blog-manager'),
                'resetting'             => __('Resetting...', 'wash-blog-manager'),
                'confirmReset'          => __('This will delete the Shopify session. You will need to re-open the app from Shopify Admin to re-authenticate. Continue?', 'wash-blog-manager'),
                'sessionResetSuccess'   => __('Session reset. Now open the app from Shopify Admin to re-authenticate.', 'wash-blog-manager'),
                'failedResetSession'    => __('Failed to reset session: ', 'wash-blog-manager'),
                'networkErrorReset'     => __('Network error - could not reach reset endpoint', 'wash-blog-manager'),
                'networkErrorResetAlert' => __('Network error while resetting session', 'wash-blog-manager'),
                // Redirections
                'loading'               => __('Loading...', 'wash-blog-manager'),
                'syncing'               => __('Syncing redirections...', 'wash-blog-manager'),
                'generating'            => __('Generating redirections...', 'wash-blog-manager'),
                'fetchingWp'            => __('Fetching WordPress redirections...', 'wash-blog-manager'),
                'syncSuccess'           => __('Redirections synced successfully!', 'wash-blog-manager'),
                'syncError'             => __('Failed to sync redirections: ', 'wash-blog-manager'),
                'failedLoadStats'       => __('Failed to load stats', 'wash-blog-manager'),
                'noRedirectionsFound'   => __('No redirections found in WordPress plugins (Redirection, RankMath, Yoast).', 'wash-blog-manager'),
                'importedFromWp'        => __('# Imported from WordPress plugins', 'wash-blog-manager'),
                'failedFetchRedirections' => __('Failed to fetch redirections: ', 'wash-blog-manager'),
                'networkErrorFetch'     => __('Network error while fetching WordPress redirections', 'wash-blog-manager'),
                'syncFromOldWebsite'    => __('Sync from Old Website', 'wash-blog-manager'),
                'noSyncedPosts'         => __('No synced posts found. Sync some posts first to generate redirections.', 'wash-blog-manager'),
                'generatedFromSynced'   => __('# Generated from synced posts', 'wash-blog-manager'),
                'failedGenerateRedirections' => __('Failed to generate redirections: ', 'wash-blog-manager'),
                'networkErrorGenerate'  => __('Network error while generating redirections', 'wash-blog-manager'),
                'generateFromSynced'    => __('Generate from Synced Posts', 'wash-blog-manager'),
                'failedLoadShopify'     => __('Failed to load Shopify redirections: ', 'wash-blog-manager'),
                'loadFromShopify'       => __('Load from Shopify', 'wash-blog-manager'),
                'noRedirectionsShopify' => __('No redirections found in Shopify.', 'wash-blog-manager'),
                'existingShopifyRedirects' => __('# Existing Shopify redirections', 'wash-blog-manager'),
                'networkErrorLoadShopify' => __('Network error while loading Shopify redirections', 'wash-blog-manager'),
                // Fix session
                'fixing'                => __('Fixing...', 'wash-blog-manager'),
                'sessionFixed'          => __('Session fixed!', 'wash-blog-manager'),
                'noSessionsNeedFix'     => __('No sessions needed fixing.', 'wash-blog-manager'),
                'networkErrorFix'       => __('Network error while fixing session', 'wash-blog-manager'),
                'fixSessionScopes'      => __('Fix Session Scopes', 'wash-blog-manager'),
                'failedFixSession'      => __('Failed to fix session: ', 'wash-blog-manager'),
                // Sync redirections
                'pleaseEnterRedirections' => __('Please enter redirections to sync.', 'wash-blog-manager'),
                'networkErrorSync'      => __('Network error while syncing redirections', 'wash-blog-manager'),
                'unknownError'          => __('Unknown error', 'wash-blog-manager'),
                'networkError'          => __('Network error', 'wash-blog-manager'),
                // Results labels
                'created'               => __('Created', 'wash-blog-manager'),
                'skippedExist'          => __('Skipped (already exist)', 'wash-blog-manager'),
                'failed'                => __('Failed', 'wash-blog-manager'),
                'totalInShopify'        => __('Total in Shopify', 'wash-blog-manager'),
                'remaining'             => __('Remaining', 'wash-blog-manager'),
                'errors'                => __('Errors', 'wash-blog-manager'),
                // translators: %d is the number of additional items not shown.
                'andMore'               => __('... and %d more', 'wash-blog-manager'),
                'tryAgain'              => __('Try loading redirections again.', 'wash-blog-manager'),
                // Mappings (inline JS in settings-page.php)
                'failedLoadMappings'    => __('Failed to load mappings', 'wash-blog-manager'),
                'edit'                  => __('Edit', 'wash-blog-manager'),
                'delete'                => __('Delete', 'wash-blog-manager'),
                // Read-only mode
                'readOnlyMode'          => __('You do not have permission to modify Wash settings. Contact your administrator for access.', 'wash-blog-manager'),
                // Unlock/Lock redirections
                'unlock'                => __('Unlock', 'wash-blog-manager'),
                'lock'                  => __('Lock', 'wash-blog-manager'),
                'unlockToSync'          => __('Unlock to enable sync', 'wash-blog-manager'),
                'unlockWarningTitle'    => __('⚠️ Warning: Editing Redirections', 'wash-blog-manager'),
                'unlockWarning1'        => __('Changes to redirections directly affect your Shopify store.', 'wash-blog-manager'),
                'unlockWarning2'        => __('Incorrect redirections can break links and harm SEO.', 'wash-blog-manager'),
                'unlockWarning3'        => __('Make sure you know what you are doing.', 'wash-blog-manager'),
                'unlockConfirm'         => __('Do you want to unlock editing?', 'wash-blog-manager'),
                // Verify redirections
                'verifyLineError'       => __('Line', 'wash-blog-manager'),
                'verifyInvalidFormat'   => __('Invalid format - missing separator (→, ->, =>)', 'wash-blog-manager'),
                'verifyFromSlash'       => __('Source path should start with /', 'wash-blog-manager'),
                'verifyToSlash'         => __('Target path should start with / or http', 'wash-blog-manager'),
                'verifyDuplicate'       => __('Duplicate source path', 'wash-blog-manager'),
                'verifyFirstAt'         => __('first at line', 'wash-blog-manager'),
                'verifyLoop'            => __('Redirect loop - source equals target', 'wash-blog-manager'),
                'verifySuccess'         => __('Syntax valid!', 'wash-blog-manager'),
                'verifyRedirects'       => __('redirections', 'wash-blog-manager'),
                'verifyComments'        => __('comments', 'wash-blog-manager'),
                'verifyValidLines'      => __('valid', 'wash-blog-manager'),
                'verifyErrors'          => __('Errors', 'wash-blog-manager'),
                'verifyWarnings'        => __('Warnings', 'wash-blog-manager'),
                'saved'                 => __('Saved', 'wash-blog-manager'),
                // Sync from Shopify
                'syncFromShopify'       => __('Sync from Shopify', 'wash-blog-manager'),
                'syncingFromShopify'    => __('Syncing...', 'wash-blog-manager'),
                'confirmSyncFromShopify' => __('This will overwrite your local settings with settings from Shopify App. Continue?', 'wash-blog-manager'),
                'syncFromShopifySuccess' => __('Settings synced from Shopify App successfully.', 'wash-blog-manager'),
                'failedSyncFromShopify' => __('Failed to sync settings: ', 'wash-blog-manager'),
                // Search & Replace
                'srSaving'              => __('Saving...', 'wash-blog-manager'),
                'srSaved'               => __('Rules saved.', 'wash-blog-manager'),
                'srSaveFailed'          => __('Failed to save rules: ', 'wash-blog-manager'),
                'srNoRules'             => __('No rules defined yet. Click "Add Rule" to create one.', 'wash-blog-manager'),
                'srRemoveConfirm'       => __('Remove this rule?', 'wash-blog-manager'),
                'srDrag'                => __('Drag to reorder', 'wash-blog-manager'),
                'srOverlapDuplicate'    => __('Duplicate rule', 'wash-blog-manager'),
                'srOverlapContains'     => __('Overlapping rule', 'wash-blog-manager'),
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        delete_option('wash_api_key');

        wp_send_json_success();
    }

    /**
     * AJAX handler for recording the merchant's explicit consent to send
     * WordPress content to the Tradik Wash service (wp.org Plugin Directory
     * guideline #10 — "Plugins may not gather data about a user without
     * explicit confirmation"). Persists a `wash_consent_accepted` flag plus
     * the timestamp so the audit trail survives a re-install. The Settings
     * page disables the "Connect to Shopify" button until this is `1`.
     */
    public function ajaxSaveConsent() {
        check_ajax_referer('wash_consent', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        update_option('wash_consent_accepted', '1');
        update_option('wash_consent_accepted_at', time());

        wp_send_json_success([
            'accepted_at' => (int) get_option('wash_consent_accepted_at', 0),
        ]);
    }

    /**
     * AJAX handler for debugging Shopify session.
     */
    public function ajaxDebugSession() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; structure validated by json_decode, scalar values normalised by map_deep(sanitize_text_field) below. AJAX is gated by check_ajax_referer + current_user_can('manage_options').
        $raw = wp_unslash($_POST['config'] ?? '');
        $config = json_decode($raw, true);

        if (!is_array($config)) {
            wp_send_json_error(__('Invalid field config', 'wash-blog-manager'));
        }

        $config = map_deep($config, 'sanitize_text_field');

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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            'wash_transform_rankmath_faq' => '1',  // Enabled

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
            'message' => __('All settings have been reset to defaults.', 'wash-blog-manager'),
        ]);
    }

    /**
     * AJAX handler for syncing settings from Shopify App to WordPress.
     * Pulls settings stored in Shopify and applies them locally.
     */
    public function ajaxSyncSettingsFromShopify() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $response = $this->apiClient->getBackedUpSettings();

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        if (empty($response['success']) || empty($response['settings'])) {
            wp_send_json_error(__('No settings found in Shopify App. Save settings in Shopify first.', 'wash-blog-manager'));
        }

        $this->apiClient->restoreSettings($response['settings']);

        wp_send_json_success([
            'message' => __('Settings synced from Shopify App successfully.', 'wash-blog-manager'),
        ]);
    }

    /**
     * AJAX handler for getting blog mappings from Shopify App.
     */
    public function ajaxGetMappings() {
        check_ajax_referer('wash_mappings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $data = [
            'destination'    => sanitize_text_field(wp_unslash($_POST['destination'] ?? 'article')),
            'wpPostTypes'    => sanitize_text_field(wp_unslash($_POST['wpPostTypes'] ?? 'post')),
            'ruleType'       => sanitize_text_field(wp_unslash($_POST['ruleType'] ?? 'default')),
            'ruleValue'      => sanitize_text_field(wp_unslash($_POST['ruleValue'] ?? '')),
            'shopifyBlogId'  => sanitize_text_field(wp_unslash($_POST['shopifyBlogId'] ?? '')),
            'newBlogName'    => sanitize_text_field(wp_unslash($_POST['newBlogName'] ?? '')),
            'priority'       => absint($_POST['priority'] ?? 0),
            'marketId'       => sanitize_text_field(wp_unslash($_POST['marketId'] ?? '')),
            'shopifyLocale'  => sanitize_text_field(wp_unslash($_POST['shopifyLocale'] ?? '')),
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $mappingId = sanitize_text_field(wp_unslash($_POST['mappingId'] ?? ''));
        if (empty($mappingId)) {
            wp_send_json_error(__('Mapping ID is required', 'wash-blog-manager'));
        }

        $data = [
            'destination'    => sanitize_text_field(wp_unslash($_POST['destination'] ?? 'article')),
            'wpPostTypes'    => sanitize_text_field(wp_unslash($_POST['wpPostTypes'] ?? 'post')),
            'ruleType'       => sanitize_text_field(wp_unslash($_POST['ruleType'] ?? 'default')),
            'ruleValue'      => sanitize_text_field(wp_unslash($_POST['ruleValue'] ?? '')),
            'shopifyBlogId'  => sanitize_text_field(wp_unslash($_POST['shopifyBlogId'] ?? '')),
            'priority'       => absint($_POST['priority'] ?? 0),
            'marketId'       => sanitize_text_field(wp_unslash($_POST['marketId'] ?? '')),
            'shopifyLocale'  => sanitize_text_field(wp_unslash($_POST['shopifyLocale'] ?? '')),
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $mappingId = sanitize_text_field(wp_unslash($_POST['mappingId'] ?? ''));
        if (empty($mappingId)) {
            wp_send_json_error(__('Mapping ID is required', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; the foreach below sanitizes each `id` (sanitize_text_field) and casts `priority` to int. AJAX is gated by check_ajax_referer + current_user_can('manage_options').
        $updates = json_decode(wp_unslash($_POST['updates'] ?? '[]'), true);
        if (!is_array($updates)) {
            wp_send_json_error(__('Invalid data', 'wash-blog-manager'));
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $result = $this->apiClient->get('/api/redirections');

        if (is_wp_error($result)) {
            // Try to get diagnosis for better error message
            $diagnosis = $this->apiClient->diagnose();
            $errorMsg = $result->get_error_message();

            if (!is_wp_error($diagnosis) && isset($diagnosis['fix'])) {
                $errorMsg .= "\n\n" . __('How to fix:', 'wash-blog-manager') . " " . $diagnosis['fix'];
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $redirections = [];
        $shopifyStore = WashContext::connection()['store'];
        $allowedTypes = array_map('trim', explode(',', WashContext::option('wash_post_types', 'post,page')));

        $posts = get_posts([
            'post_type'      => $allowedTypes,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required to enumerate already-synced posts (those carrying _wash_article_id meta) for a reconciliation pass; alternative would be denormalising into a custom table just for this admin-only batch operation.
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $redirectionsRaw = isset($_POST['redirections']) ? sanitize_textarea_field(wp_unslash($_POST['redirections'])) : '';

        if (empty($redirectionsRaw)) {
            wp_send_json_error(__('No redirections provided.', 'wash-blog-manager'));
        }

        $redirections = $this->parseRedirections($redirectionsRaw);

        if (empty($redirections)) {
            wp_send_json_error(__('No valid redirections found. Format: /old-path -> /new-path', 'wash-blog-manager'));
        }

        $result = $this->apiClient->post('/api/redirections', [
            'redirects' => $redirections,
        ]);

        if (is_wp_error($result)) {
            // Try to get diagnosis for better error message
            $diagnosis = $this->apiClient->diagnose();
            $errorMsg = $result->get_error_message();

            if (!is_wp_error($diagnosis) && isset($diagnosis['fix'])) {
                $errorMsg .= "\n\n" . __('How to fix:', 'wash-blog-manager') . " " . $diagnosis['fix'];
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 3rd-party (RankMath) table with no WP-API equivalent; admin-only batch read for redirect export.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($rankMathTable))) === $rankMathTable) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 3rd-party (RankMath) table; admin-only read. %i (identifier placeholder, WP 6.2+) keeps the table name properly escaped without string interpolation.
            $rankMathRedirects = $wpdb->get_results(
                $wpdb->prepare('SELECT sources, url_to FROM %i WHERE status = %s', $rankMathTable, 'active'),
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0';
        update_option('wash_redirections_enabled', $enabled);

        wp_send_json_success([
            'message' => __('Settings saved', 'wash-blog-manager'),
            'enabled' => $enabled,
        ]);
    }

    /**
     * AJAX handler: get search & replace rules.
     */
    public function ajaxGetSearchReplaceRules() {
        check_ajax_referer('wash_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $rules = WashContext::option('wash_search_replace_rules', []);
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array payload; the foreach below applies wp_kses_post() to search/replace strings and whitelist-validates `type`. AJAX is gated by check_ajax_referer + current_user_can('manage_options').
        $rawRules = isset($_POST['rules']) && is_array($_POST['rules'])
            ? wp_unslash($_POST['rules'])
            : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

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
            'message' => __('Rules saved', 'wash-blog-manager'),
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $cached = get_transient('wash_content_links');
        if ($cached !== false) {
            wp_send_json_success($cached);
            return;
        }

        $postTypes = array_map('trim', explode(',', WashContext::option('wash_post_types', 'post,page')));

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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
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
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only enumeration of synced posts (carrying _wash_article_id) to build a redirect map; meta_query is the right primitive here.
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
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see above; same pattern for synced pages.
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
                <?php esc_html_e('View on Shopify', 'wash-blog-manager'); ?>
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
        // Generate the URL from the merchant's blog-mapping rules: match the post
        // against its rules (by language / category) and build the storefront URL
        // with the correct market subfolder + blog/page path (#255). Computed
        // locally from cached rules — no per-post network call, no re-sync.
        return WashBlogRouter::storefrontUrl($postId);
    }

    /**
     * Generate inline JS for adding Shopify link to Gutenberg editor.
     *
     * @param string $shopifyUrl The Shopify article URL.
     * @return string JavaScript code.
     */
    private function getBlockEditorShopifyLinkScript($shopifyUrl) {
        $label = esc_js(__('View on Shopify', 'wash-blog-manager'));
        $escapedUrl = esc_js($shopifyUrl);

        $template = "(function() {\n"
            . "    if (typeof wp === 'undefined' || !wp.plugins || !wp.editPost || !wp.element) {\n"
            . "        return;\n"
            . "    }\n"
            . "\n"
            . "    var registerPlugin = wp.plugins.registerPlugin;\n"
            . "    var PluginPostStatusInfo = wp.editPost.PluginPostStatusInfo;\n"
            . "    var createElement = wp.element.createElement;\n"
            . "    var Fragment = wp.element.Fragment;\n"
            . "\n"
            . "    registerPlugin('wash-shopify-link', {\n"
            . "        render: function() {\n"
            . "            return createElement(\n"
            . "                PluginPostStatusInfo,\n"
            . "                { className: 'wash-shopify-link-panel' },\n"
            . "                createElement(\n"
            . "                    'a',\n"
            . "                    {\n"
            . "                        href: '%1\$s',\n"
            . "                        target: '_blank',\n"
            . "                        rel: 'noopener noreferrer',\n"
            . "                        style: {\n"
            . "                            display: 'flex',\n"
            . "                            alignItems: 'center',\n"
            . "                            gap: '6px',\n"
            . "                            color: '#5c6ac4',\n"
            . "                            textDecoration: 'none',\n"
            . "                            padding: '8px 0'\n"
            . "                        }\n"
            . "                    },\n"
            . "                    createElement('span', {\n"
            . "                        className: 'dashicons dashicons-store',\n"
            . "                        style: { color: '#96bf48' }\n"
            . "                    }),\n"
            . "                    '%2\$s',\n"
            . "                    createElement('span', {\n"
            . "                        className: 'dashicons dashicons-external',\n"
            . "                        style: { fontSize: '14px' }\n"
            . "                    })\n"
            . "                )\n"
            . "            );\n"
            . "        }\n"
            . "    });\n"
            . "})();\n";

        return sprintf($template, $escapedUrl, $label);
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
                $newColumns['wash_sync'] = '<span class="dashicons dashicons-store" title="' . esc_attr__('Shopify Sync', 'wash-blog-manager') . '" style="color: #96bf48;"></span>';
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
        $imageWarnings = get_post_meta($postId, '_wash_image_warnings', true);

        if (!empty($shopifyArticleId)) {
            $shopifyUrl = $this->getShopifyArticleUrl($postId);
            $title = sprintf(
                // translators: %s is the formatted date/time the post was synced to Shopify.
                __('Synced to Shopify on %s', 'wash-blog-manager'),
                $syncedAt ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($syncedAt)) : __('unknown date', 'wash-blog-manager')
            );
            echo '<a href="' . esc_url($shopifyUrl) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($title) . '">';
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>';
            echo '</a>';
            // The primary (source) of a translation group keeps its normal green
            // "synced" icon, plus a small "primary" badge: its Shopify article holds
            // every locale translation, so it is protected from deletion.
            if (self::isProtectedTranslationSource($postId)) {
                echo ' <span style="display:inline-block;font-size:9px;font-weight:700;line-height:1;padding:2px 5px;border-radius:3px;background:#2271b1;color:#fff;vertical-align:middle;text-transform:uppercase;letter-spacing:.3px;" title="' . esc_attr__('Primary (source) of a translation group — its Shopify article holds the locale translations and cannot be deleted while translations exist.', 'wash-blog-manager') . '">' . esc_html__('primary', 'wash-blog-manager') . '</span>';
            }
            // Partial success: some body images couldn't be hosted on Shopify and
            // are still served from the source host (#249).
            if (!empty($imageWarnings) && is_array($imageWarnings)) {
                $warnTitle = sprintf(
                    // translators: %1$d is the number of images; %2$s is a comma-separated filename list.
                    _n('%1$d image could not be uploaded to Shopify (still served from source): %2$s', '%1$d images could not be uploaded to Shopify (still served from source): %2$s', count($imageWarnings), 'wash-blog-manager'),
                    count($imageWarnings),
                    implode(', ', $imageWarnings)
                );
                echo ' <span class="dashicons dashicons-warning" style="color: #dba617;" title="' . esc_attr($warnTitle) . '"></span>';
            }
        } elseif ($syncStatus === 'queued') {
            $title = sprintf(
                // translators: %s is the formatted date/time the sync was queued.
                __('Sync queued on %s - processing...', 'wash-blog-manager'),
                $queuedAt ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($queuedAt)) : __('just now', 'wash-blog-manager')
            );
            echo '<span class="dashicons dashicons-update wash-syncing" style="color: #f0b849;" title="' . esc_attr($title) . '" data-post-id="' . esc_attr($postId) . '"></span>';
        } else {
            $post = get_post($postId);
            $isPublished = $post && $post->post_status === 'publish';
            // Stale error meta only matters for currently-published posts. Drafts/private/scheduled
            // get their own icons regardless of past sync attempts.
            if ($isPublished && !empty($syncError) && strpos($syncError, 'PENDING_SOURCE') !== false) {
                $masterPostId = preg_match('/WP post #(\d+)/', $syncError, $m) ? (int) $m[1] : 0;
                $title = $masterPostId
                    // translators: %d is the WordPress post ID of the master post that must sync first.
                    ? sprintf(__('Waiting — master post #%d must sync to Shopify first', 'wash-blog-manager'), $masterPostId)
                    : __('Waiting — master language version must sync to Shopify first', 'wash-blog-manager');
                echo '<span class="dashicons dashicons-clock" style="color: #2271b1;" title="' . esc_attr($title) . '"></span>';
            } elseif ($isPublished && !empty($syncError)) {
                $shortError = function_exists('mb_strimwidth') ? mb_strimwidth($syncError, 0, 120, '…') : substr($syncError, 0, 120);
                // translators: %s is the error message returned by the failed sync.
                $title = sprintf(__('Sync failed: %s', 'wash-blog-manager'), $shortError);
                echo '<span class="dashicons dashicons-warning" style="color: #d63638;" title="' . esc_attr($title) . '"></span>';
            } elseif ($isPublished && self::postHasNoMapping($postId)) {
                // No blog-mapping rule matches this post's language/category, so it
                // has no Shopify destination and is NOT published (#255). Flag it
                // clearly (colour + text, WCAG) so the merchant adds a rule.
                $lang  = WashSync::getPostLanguage($postId);
                $title = $lang
                    // translators: %s is the post's language code (e.g. "pl-pl").
                    ? sprintf(__('No blog-mapping rule for language "%s" — add a rule to publish this post to Shopify.', 'wash-blog-manager'), $lang)
                    : __('No matching blog-mapping rule — add a rule to publish this post to Shopify.', 'wash-blog-manager');
                echo '<span class="dashicons dashicons-warning" style="color: #b32d2e;" title="' . esc_attr($title) . '"></span> ';
                echo '<span style="color:#b32d2e;font-size:11px;font-weight:600;" title="' . esc_attr($title) . '">' . esc_html__('No mapping', 'wash-blog-manager') . '</span>';
            } elseif ($isPublished) {
                echo '<span class="dashicons dashicons-minus" style="color: #ddd;" title="' . esc_attr__('Not synced', 'wash-blog-manager') . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-hidden" style="color: #ccc;" title="' . esc_attr__('Draft - will sync when published', 'wash-blog-manager') . '"></span>';
            }
        }
    }

    /**
     * Whether a post has no matching blog-mapping rule (so it cannot be published
     * to Shopify). True if the sync gate already flagged it, or a live rule match
     * finds nothing (#255).
     *
     * @param int $postId
     * @return bool
     */
    private static function postHasNoMapping($postId) {
        if (get_post_meta($postId, WashContext::metaKey('_wash_no_mapping'), true)) {
            return true;
        }
        return !WashBlogRouter::hasMapping($postId);
    }

    /**
     * Translation-group state for a post: whether it is the primary (source), and
     * how many translations (children) hang off it. Reuses WashHreflangs's source
     * resolution (default-language post, else lowest ID). Cached per request.
     *
     * @param int $postId
     * @return array{isSource:bool, childCount:int, sourceId:int}
     */
    private static function translationSourceState($postId) {
        static $cache = [];
        $postId = (int) $postId;
        if (isset($cache[$postId])) {
            return $cache[$postId];
        }
        $state = ['isSource' => false, 'childCount' => 0, 'sourceId' => $postId];
        // Polylang: the source is the default-language post (else the lowest ID) —
        // the same rule WashHreflangs::getTranslationGroupSource uses, inlined here
        // with function-exists guards only so this per-row render never depends on
        // detectActivePlugin()/is_plugin_active() being loaded.
        if (function_exists('pll_get_post_translations') && function_exists('pll_default_language')) {
            $translations = pll_get_post_translations($postId);
            if (is_array($translations) && count($translations) > 1) {
                $defaultLang = pll_default_language('slug');
                $sourceId    = (int) ($translations[$defaultLang] ?? min(array_values($translations)));
                $state = [
                    'isSource'   => $sourceId === $postId,
                    'childCount' => count($translations) - 1,
                    'sourceId'   => $sourceId,
                ];
            }
        }
        $cache[$postId] = $state;
        return $state;
    }

    /**
     * Whether a post is the protected primary (source) of a multi-post translation
     * group — the master whose Shopify article holds the locale translations, so it
     * must not be deleted while translations still exist.
     *
     * @param int $postId
     * @return bool
     */
    private static function isProtectedTranslationSource($postId) {
        $state = self::translationSourceState($postId);
        return $state['isSource'] && $state['childCount'] > 0;
    }

    /**
     * Deny delete_post for a protected translation source so WordPress hides its
     * Trash link and blocks single/bulk trash. The merchant deletes the
     * translations first; then the source becomes deletable again.
     *
     * @param string[] $caps   Required primitive capabilities.
     * @param string   $cap    The meta capability being checked.
     * @param int      $userId User ID (unused).
     * @param array    $args   $args[0] is the post ID for delete_post.
     * @return string[]
     */
    public function protectTranslationSourceCaps($caps, $cap, $userId, $args) {
        if ($cap === 'delete_post' && !empty($args[0]) && self::isProtectedTranslationSource((int) $args[0])) {
            $caps[] = 'do_not_allow';
        }
        return $caps;
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

        $allowedTypes = array_map('trim', explode(',', WashContext::option('wash_post_types', 'post,page')));
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
                __('View on Shopify', 'wash-blog-manager')
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
            $actionLabel = !empty($shopifyArticleId) ? __('Re-sync to Shopify', 'wash-blog-manager') : __('Sync to Shopify', 'wash-blog-manager');
            $actions['wash_sync'] = sprintf(
                '<a href="%s" style="color: #96bf48;">%s</a>',
                esc_url($syncUrl),
                $actionLabel
            );
        }

        // No per-post "Refresh status" action here by design: reconciling sync
        // state is a bulk operation, exposed as "Refresh all sync statuses" on
        // Settings → Wash → Sync. A per-row refresh was noise — it implied each
        // post needed individual reconciliation, which was never the intent.

        // Primary (source) of a translation group: WordPress already hides the Trash
        // link (delete_post is denied), so explain WHY in its place.
        if (self::isProtectedTranslationSource($post->ID)) {
            unset($actions['trash'], $actions['delete']);
            $actions['wash_primary'] = '<span style="color:#646970;cursor:help;" title="' . esc_attr__('Primary (source) translation — its Shopify article holds the other languages. Delete the translations first; this post cannot be removed while they exist.', 'wash-blog-manager') . '">' . esc_html__('Primary — protected from deletion', 'wash-blog-manager') . '</span>';
        }

        return $actions;
    }

    /**
     * Reconcile one post's local sync meta with shopify-app's check-status entry.
     * Restores _wash_article_id/_wash_blog_id/_wash_blog_handle/_wash_synced_at
     * (and clears stale errors) when the article exists on Shopify; clears all of
     * them when it doesn't. Used by the bulk "Refresh all sync statuses" button
     * (Settings → Wash → Sync) via ajaxRefreshAllStatuses().
     *
     * @param int        $postId
     * @param array|null $status One `statuses` entry from /api/check-status.
     * @return string 'synced' when the article exists, otherwise 'not_synced'.
     */
    private function applyStatusToPostMeta($postId, $status) {
        if ($status && !empty($status['exists'])) {
            if (!empty($status['shopifyArticleId'])) {
                update_post_meta($postId, '_wash_article_id', $status['shopifyArticleId']);
            }
            if (!empty($status['shopifyBlogId'])) {
                update_post_meta($postId, '_wash_blog_id', $status['shopifyBlogId']);
            }
            if (!empty($status['shopifyBlogHandle'])) {
                update_post_meta($postId, '_wash_blog_handle', $status['shopifyBlogHandle']);
            }
            if (!empty($status['syncedAt'])) {
                update_post_meta($postId, '_wash_synced_at', $status['syncedAt']);
            }
            delete_post_meta($postId, '_wash_sync_error');
            delete_post_meta($postId, '_wash_sync_error_at');
            return 'synced';
        }
        delete_post_meta($postId, '_wash_article_id');
        delete_post_meta($postId, '_wash_blog_id');
        delete_post_meta($postId, '_wash_blog_handle');
        delete_post_meta($postId, '_wash_synced_at');
        delete_post_meta($postId, '_wash_sync_error');
        delete_post_meta($postId, '_wash_sync_error_at');
        return 'not_synced';
    }

    /**
     * AJAX: bulk "Refresh all statuses" — rebuild local sync meta for every
     * published post from shopify-app's source of truth (the SyncLog), without
     * re-syncing content. Recovers the synced/not-synced indicators after they
     * were lost (e.g. uninstall.php cleared _wash_* meta on a plugin delete).
     *
     * Two phases, driven by the JS loop in admin.js:
     *  - no `token`: enumerate candidate post IDs, cache them in a transient,
     *    return {token, total} so the client can show a progress bar.
     *  - `token` + `offset`: reconcile one batch (capped to the check-status
     *    100-id limit) and report progress.
     */
    public function ajaxRefreshAllStatuses() {
        check_ajax_referer('wash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }
        if (!$this->apiClient->isConfigured()) {
            wp_send_json_error(__('Not connected to Shopify.', 'wash-blog-manager'));
        }

        $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';

        // Prepare phase: enumerate published posts of the configured types once.
        if ($token === '') {
            $postTypes = array_map('trim', explode(',', WashContext::option('wash_post_types', 'post,page')));
            $ids = get_posts([
                'post_type'      => $postTypes,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            $ids = array_map('intval', $ids);
            $token = wp_generate_password(20, false, false);
            set_transient('wash_refresh_' . $token, $ids, HOUR_IN_SECONDS);
            wp_send_json_success(['token' => $token, 'total' => count($ids)]);
        }

        // Batch phase.
        $ids = get_transient('wash_refresh_' . $token);
        if (!is_array($ids)) {
            wp_send_json_error(__('Refresh session expired — please start again.', 'wash-blog-manager'));
        }
        $offset    = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        // Cap at the /api/check-status 100-id limit.
        $batchSize = min(100, (int) apply_filters('wash_refresh_batch_size', 50));
        $batch     = array_slice($ids, $offset, $batchSize);

        if (empty($batch)) {
            delete_transient('wash_refresh_' . $token);
            wp_send_json_success(['restored' => 0, 'notSynced' => 0, 'errors' => 0, 'nextOffset' => $offset, 'done' => true]);
        }

        $response = $this->apiClient->post('/api/check-status', [
            'wpPostIds' => array_values($batch),
            'wpSiteId'  => get_current_blog_id(),
        ]);

        $restored = 0;
        $notSynced = 0;
        $errors = 0;
        if (is_wp_error($response) || !is_array($response) || empty($response['success'])) {
            $errors = count($batch);
        } else {
            $statuses = (isset($response['statuses']) && is_array($response['statuses'])) ? $response['statuses'] : [];
            foreach ($batch as $postId) {
                // PHP normalises numeric-string array keys to int, so a single
                // int lookup covers both "10" and 10 from the JSON response.
                $status = $statuses[$postId] ?? null;
                if ($this->applyStatusToPostMeta($postId, $status) === 'synced') {
                    $restored++;
                } else {
                    $notSynced++;
                }
            }
        }

        $nextOffset = $offset + count($batch);
        $done       = $nextOffset >= count($ids);
        if ($done) {
            delete_transient('wash_refresh_' . $token);
        }

        wp_send_json_success([
            'restored'   => $restored,
            'notSynced'  => $notSynced,
            'errors'     => $errors,
            'nextOffset' => $nextOffset,
            'done'       => $done,
        ]);
    }

    /**
     * Cron handler: clear `_wash_sync_error` meta on published posts where the
     * error is older than the retention window (default 30 days, filterable).
     * Disabled if `wash_auto_clear_errors` option is '0'.
     */
    public function clearStaleErrors() {
        if (WashContext::option('wash_auto_clear_errors', '1') !== '1') {
            return;
        }

        $retentionDays = (int) apply_filters('wash_sync_error_retention_days', 30);
        if ($retentionDays < 1) {
            return;
        }

        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        $allowedTypes = array_map('trim', explode(',', WashContext::option('wash_post_types', 'post,page')));
        $typePlaceholders = implode(',', array_fill(0, count($allowedTypes), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $typePlaceholders is a comma-separated list of %s placeholders; every value (cutoff + post types) is bound through prepare() below.
        $sql = $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_wash_sync_error_at'
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wash_sync_error'
               AND pm2.meta_value < %s
               AND p.post_status = 'publish'
               AND p.post_type IN ({$typePlaceholders})",
            array_merge([$cutoff], $allowedTypes)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- daily cron sweep over postmeta; meta_query would require an awkward double-join via WP_Query and still hit the same indexes.
        $stalePostIds = $wpdb->get_col($sql);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $cleared = 0;
        foreach ($stalePostIds as $postId) {
            delete_post_meta((int) $postId, '_wash_sync_error');
            delete_post_meta((int) $postId, '_wash_sync_error_at');
            $cleared++;
        }
        if ($cleared > 0) {
            washLog("[wash] clearStaleErrors: cleared {$cleared} stale sync errors (older than {$retentionDays} days)");
        }
    }

    /**
     * Register the hidden Bulk Sync Progress page (parent=null = not shown in
     * the admin menu — only reachable via the redirect from handleBulkActions).
     */
    public function addBulkProgressPage() {
        add_submenu_page(
            null,
            __('Bulk Sync Progress', 'wash-blog-manager'),
            __('Bulk Sync Progress', 'wash-blog-manager'),
            'edit_posts',
            'wash-bulk-progress',
            [$this, 'renderBulkProgressPage']
        );
    }

    /**
     * Render the JS-driven progress UI for bulk publish/republish/unpublish.
     * Reads the post-id list from a one-shot transient (set by handleBulkActions),
     * shows a progress bar, and fires AJAX batches at wash_bulk_sync_batch.
     */
    public function renderBulkProgressPage() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('Permission denied', 'wash-blog-manager'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is a one-shot transient key set by handleBulkActions() after a nonced bulk submission; it's already capability-checked above and the transient expires on first read.
        $token = isset($_GET['token']) ? sanitize_key(wp_unslash($_GET['token'])) : '';
        $data  = $token ? get_transient('wash_bulk_' . $token) : null;
        if (!$data || empty($data['postIds'])) {
            wp_die(esc_html__('Bulk operation expired or not found. Re-run the bulk action from the posts list.', 'wash-blog-manager'));
        }

        $action     = $data['action'];
        $total      = count($data['postIds']);
        $skipped    = (int) ($data['skipped'] ?? 0);
        $skipInfo   = (string) ($data['skip'] ?? '');
        $returnUrl  = $data['returnUrl'] ?? admin_url('edit.php');
        $batchSize  = (int) apply_filters('wash_bulk_batch_size', 50);
        $ajaxUrl    = admin_url('admin-ajax.php');
        $nonce      = wp_create_nonce('wash_bulk_progress');

        $actionLabel = [
            'wash_publish'   => __('Publishing to Shopify', 'wash-blog-manager'),
            'wash_republish' => __('Re-publishing to Shopify', 'wash-blog-manager'),
            'wash_unpublish' => __('Unpublishing from Shopify', 'wash-blog-manager'),
        ][$action] ?? __('Processing', 'wash-blog-manager');

        $finalRedirect = add_query_arg([
            'wash_bulk_action'    => $action,
            'wash_bulk_processed' => '__processed__',
            'wash_bulk_skipped'   => $skipped,
            'wash_bulk_skip'      => $skipInfo,
            'wash_bulk_errors'    => '__errors__',
        ], $returnUrl);

        // wp.org compliance: enqueue the progress driver instead of an inline
        // script element. This hidden submenu page isn't covered by enqueueAssets(),
        // so register the asset here; called during page output it still prints
        // in the admin footer. Dynamic config is passed via wp_localize_script().
        wp_enqueue_script(
            'wash-bulk-progress',
            washPluginUrl() . 'admin/js/bulk-progress.js',
            [],
            WASH_VERSION . '.' . filemtime(WASH_PLUGIN_DIR . 'admin/js/bulk-progress.js'),
            true
        );
        wp_localize_script('wash-bulk-progress', 'WashBulkProgress', [
            'token'         => $token,
            'nonce'         => $nonce,
            'total'         => $total,
            'finalRedirect' => $finalRedirect,
            'ajaxUrl'       => $ajaxUrl,
            'i18n'          => [
                'done'    => __('done', 'wash-blog-manager'),
                'working' => __('working…', 'wash-blog-manager'),
            ],
        ]);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html($actionLabel); ?></h1>
            <p class="description">
                <?php
                echo esc_html(sprintf(
                    /* translators: %1$d total posts, %2$d batch size */
                    __('Processing %1$d posts in batches of %2$d. Do not close this tab until the bar reaches 100%%.', 'wash-blog-manager'),
                    $total,
                    $batchSize
                ));
                ?>
            </p>
            <div style="margin: 20px 0;">
                <progress id="wash-bulk-progress" value="0" max="<?php echo esc_attr($total); ?>" style="width: 100%; height: 24px;"></progress>
                <p id="wash-bulk-status" style="margin-top: 8px; font-size: 14px;">
                    <strong><span id="wash-bulk-done">0</span> / <?php echo esc_html($total); ?></strong>
                    — <?php esc_html_e('starting…', 'wash-blog-manager'); ?>
                </p>
                <ul id="wash-bulk-errors" style="color: #d63638; font-size: 13px;"></ul>
            </div>
            <p>
                <a href="<?php echo esc_url($returnUrl); ?>" id="wash-bulk-cancel" class="button">
                    <?php esc_html_e('Cancel and return', 'wash-blog-manager'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX: process one batch of N post IDs from the transient queue.
     * Each call is short (one batch) so it fits comfortably under any
     * PHP / nginx / FastCGI execution limit; the JS loop drives N batches.
     */
    public function ajaxBulkSyncBatch() {
        check_ajax_referer('wash_bulk_progress', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
        $data  = $token ? get_transient('wash_bulk_' . $token) : null;
        if (!$data || empty($data['postIds'])) {
            wp_send_json_error(__('Bulk session expired', 'wash-blog-manager'));
        }

        $offset    = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $batchSize = (int) apply_filters('wash_bulk_batch_size', 50);
        $batch     = array_slice($data['postIds'], $offset, $batchSize);
        $action    = $data['action'];

        if (empty($batch)) {
            delete_transient('wash_bulk_' . $token);
            wp_send_json_success(['processed' => 0, 'errors' => 0, 'nextOffset' => $offset, 'done' => true, 'errorMessages' => []]);
        }

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- AJAX bulk-sync batch can legitimately exceed default PHP max_execution_time when syncing many posts to Shopify; suppressed (@) because some hosts disable set_time_limit and we don't want to spam logs.
        @set_time_limit(0);
        ignore_user_abort(true);

        $processed = 0;
        $errors    = 0;
        $errorMsgs = [];
        $sync      = ($action !== 'wash_unpublish') ? new WashSync() : null;

        foreach ($batch as $postId) {
            $post = get_post((int) $postId);
            if (!$post) { $errors++; $errorMsgs[] = "Post #{$postId}: not found"; continue; }
            try {
                if ($action === 'wash_unpublish') {
                    $result = $this->unpublishFromShopify((int) $postId, $post);
                    if (is_wp_error($result)) {
                        $errors++;
                        $errorMsgs[] = "Post #{$postId}: " . $result->get_error_message();
                    } else {
                        $processed++;
                    }
                } else {
                    $sync->syncManually((int) $postId, $post);
                    $processed++;
                }
            } catch (Throwable $e) {
                $errors++;
                $errorMsgs[] = "Post #{$postId}: " . $e->getMessage();
            }
        }

        $nextOffset = $offset + count($batch);
        $done       = $nextOffset >= count($data['postIds']);
        if ($done) {
            delete_transient('wash_bulk_' . $token);
        }

        wp_send_json_success([
            'processed'     => $processed,
            'errors'        => $errors,
            'errorMessages' => array_slice($errorMsgs, 0, 5),
            'nextOffset'    => $nextOffset,
            'done'          => $done,
        ]);
    }

    /**
     * Handle manual sync action from posts list.
     */
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- action/post_id reads are dispatch guards; the actual security gate is the wp_verify_nonce() call further down (handler wp_die's on mismatch).
    public function handleManualSync() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'wash_sync') {
            return;
        }
        if (!isset($_GET['post_id'])) {
            return;
        }

        $postId = absint($_GET['post_id']);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'wash_sync_' . $postId)) {
            wp_die(esc_html__('Security check failed', 'wash-blog-manager'));
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You do not have permission to sync this post', 'wash-blog-manager'));
        }

        $post = get_post($postId);
        if (!$post || $post->post_status !== 'publish') {
            $referer = wp_get_referer();
            $redirectUrl = ($referer && strpos($referer, admin_url()) === 0) ? $referer : admin_url('edit.php');
            wp_safe_redirect(add_query_arg('wash_error', 'not_published', $redirectUrl));
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

        wp_safe_redirect(add_query_arg($redirectArgs, $redirectUrl));
        exit;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    /**
     * Render admin notices for sync results.
     *
     * This is a read-only admin notice handler: it inspects $_GET / current
     * post-edit screen to surface async sync state and post-redirect
     * confirmations. It performs no state mutation, so a WP nonce is not
     * applicable — every $_GET read below is either passed through
     * sanitize_key/sanitize_text_field/(int) before display or fed back into
     * a get_post_meta() lookup on the post the merchant is already viewing.
     */
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- see method docblock; read-only notice rendering.
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
                echo esc_html__('Last sync to Shopify failed:', 'wash-blog-manager');
                echo ' ' . esc_html($syncError);
                echo '</p></div>';
            }
        }

        // Setup notice when the plugin isn't connected. Scoped to the content
        // screens where it is actionable (post list + editor) and dismissible —
        // never shown dashboard-wide, so it doesn't hijack the admin (Guideline 11).
        if (!$this->apiClient->isConfigured()
            && $screen && in_array($screen->base, ['edit', 'post'], true)
        ) {
            $settingsUrl = admin_url('options-general.php?page=wash');
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>Wash:</strong> ';
            printf(
                /* translators: %1$s = opening link tag, %2$s = closing link tag */
                esc_html__('Plugin is not connected to Shopify. Posts will not sync until you %1$sconfigure the connection%2$s.', 'wash-blog-manager'),
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
            $error = sanitize_key(wp_unslash($_GET['wash_bulk_error']));
            if ($error === 'not_configured') {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Wash:</strong> ';
                echo esc_html__('Plugin is not connected to Shopify. Please configure the connection first.', 'wash-blog-manager');
                echo '</p></div>';
            }
            return;
        }

        if (!isset($_GET['wash_result'])) {
            return;
        }

        $result = sanitize_key(wp_unslash($_GET['wash_result']));
        $errorMsg = '';
        if (isset($_GET['wash_error_msg'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field is applied below; WPCS does not pierce the urldecode() wrapper to see the outer sanitizer.
            $rawErrorMsg = wp_unslash($_GET['wash_error_msg']);
            $errorMsg = sanitize_text_field(urldecode($rawErrorMsg));
        }
        $class = '';
        $message = '';
        $showUpgrade = false;

        switch ($result) {
            case 'synced':
                $class = 'notice-success';
                $message = __('Post synced to Shopify successfully.', 'wash-blog-manager');
                break;
            case 'queued':
                $class = 'notice-info';
                $message = __('Post queued for sync. It will appear on Shopify shortly.', 'wash-blog-manager');
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
                    $message = __('Failed to sync post to Shopify. Check the debug log for details.', 'wash-blog-manager');
                }
                break;
            case 'not_published':
                $class = 'notice-warning';
                $message = __('Only published posts can be synced to Shopify.', 'wash-blog-manager');
                break;
            default:
                return;
        }

        $shopifyStore = WashContext::connection()['store'];
        $upgradeUrl = $shopifyStore ? "https://{$shopifyStore}.myshopify.com/admin/apps/wash-1" : '';

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>Wash:</strong> ' . esc_html($message);
        if ($showUpgrade && $upgradeUrl) {
            echo ' <a href="' . esc_url($upgradeUrl) . '" target="_blank" rel="noopener noreferrer" style="text-decoration: underline;">';
            echo esc_html__('Upgrade your plan', 'wash-blog-manager');
            echo '</a>';
        }
        echo '</p></div>';
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    /**
     * Render admin notice for bulk actions.
     */
    private function renderBulkActionNotice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post-redirect admin notice rendered on GET; values are absint/sanitize_key normalised and only displayed in a notice, no side effects.
        $action = isset($_GET['wash_bulk_action']) ? sanitize_key(wp_unslash($_GET['wash_bulk_action'])) : '';
        if ($action === '') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $processed = isset($_GET['wash_bulk_processed']) ? absint($_GET['wash_bulk_processed']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $skipped = isset($_GET['wash_bulk_skipped']) ? absint($_GET['wash_bulk_skipped']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $errors = isset($_GET['wash_bulk_errors']) ? absint($_GET['wash_bulk_errors']) : 0;

        $actionLabel = '';
        switch ($action) {
            case 'wash_publish':
                $actionLabel = __('published to Shopify', 'wash-blog-manager');
                break;
            case 'wash_republish':
                $actionLabel = __('re-published to Shopify', 'wash-blog-manager');
                break;
            case 'wash_unpublish':
                $actionLabel = __('unpublished from Shopify', 'wash-blog-manager');
                break;
            default:
                return;
        }

        $messages = [];

        if ($processed > 0) {
            $messages[] = sprintf(
                /* translators: 1: number of posts, 2: action performed */
                _n('%1$d post %2$s.', '%1$d posts %2$s.', $processed, 'wash-blog-manager'),
                $processed,
                $actionLabel
            );
        }

        if ($skipped > 0) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post-redirect notice on GET; value is sanitised and only displayed.
            $skipInfo = isset($_GET['wash_bulk_skip']) ? sanitize_text_field(wp_unslash($_GET['wash_bulk_skip'])) : '';
            $reasons  = self::decodeSkipReasons($skipInfo);
            $messages[] = $reasons !== ''
                ? sprintf(
                    /* translators: 1: number of posts skipped, 2: comma-separated reason breakdown */
                    _n('%1$d post skipped (%2$s).', '%1$d posts skipped (%2$s).', $skipped, 'wash-blog-manager'),
                    $skipped,
                    $reasons
                )
                : sprintf(
                    /* translators: %d: number of posts skipped */
                    _n('%d post skipped.', '%d posts skipped.', $skipped, 'wash-blog-manager'),
                    $skipped
                );
        }

        if ($errors > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of errors */
                _n('%d error occurred.', '%d errors occurred.', $errors, 'wash-blog-manager'),
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
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $postId = absint($_POST['post_id'] ?? 0);
        if (!$postId) {
            wp_send_json_error(__('Invalid post ID', 'wash-blog-manager'));
        }

        $post = get_post($postId);
        if (!$post) {
            wp_send_json_error(__('Post not found', 'wash-blog-manager'));
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
                'message' => __('Sync job queued. The article will be synced shortly.', 'wash-blog-manager'),
            ]);
        } else {
            wp_send_json_error(__('Sync failed', 'wash-blog-manager'));
        }
    }

    /**
     * AJAX handler for checking sync status of multiple posts.
     * Used for real-time updates without page refresh.
     */
    public function ajaxCheckSyncStatus() {
        check_ajax_referer('wash_sync_status', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $postIds = isset($_POST['post_ids']) ? array_map('absint', (array) $_POST['post_ids']) : [];
        if (empty($postIds)) {
            wp_send_json_error(__('No posts to check', 'wash-blog-manager'));
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

        $bulkActions['wash_publish'] = __('Publish to Shopify', 'wash-blog-manager');
        $bulkActions['wash_republish'] = __('Re-publish to Shopify', 'wash-blog-manager');
        $bulkActions['wash_unpublish'] = __('Unpublish from Shopify', 'wash-blog-manager');

        return $bulkActions;
    }

    /**
     * Is this post synced to ANY enabled connection? Multistore-aware: a post
     * synced only to a secondary store stores its article id under a prefixed
     * meta key, so the bulk handler must check every connection, not just the
     * primary/unprefixed key (#255).
     *
     * @param int $postId
     * @return bool
     */
    private function isPostSyncedAnyConnection($postId) {
        $synced = false;
        foreach (WashConnection::all() as $connectionId => $connection) {
            if (empty($connection['enabled'])) {
                continue;
            }
            WashContext::setActiveId($connectionId);
            if (!empty(get_post_meta($postId, WashContext::metaKey('_wash_article_id'), true))) {
                $synced = true;
                break;
            }
        }
        WashContext::setActiveId(null);
        return $synced;
    }

    /**
     * Encode a skip-reason tally (e.g. ['unsynced'=>3,'nomapping'=>1]) into a
     * compact query-string token "unsynced-3.nomapping-1" for the post-redirect
     * notice. Only non-zero reasons are included.
     *
     * @param array<string,int> $skip
     * @return string
     */
    private static function encodeSkip($skip) {
        $parts = [];
        foreach ($skip as $key => $count) {
            if ((int) $count > 0) {
                $parts[] = $key . '-' . (int) $count;
            }
        }
        return implode('.', $parts);
    }

    /**
     * Decode the skip token into a human, translated breakdown for the notice,
     * e.g. "3 not synced to Shopify, 1 no blog-mapping rule" (#255).
     *
     * @param string $skipInfo
     * @return string '' when nothing to show
     */
    private static function decodeSkipReasons($skipInfo) {
        if ($skipInfo === '') {
            return '';
        }
        $labels = [
            'unsynced'      => __('not synced to Shopify', 'wash-blog-manager'),
            'notpublished'  => __('not published in WordPress', 'wash-blog-manager'),
            'alreadysynced' => __('already synced', 'wash-blog-manager'),
            'nomapping'     => __('no blog-mapping rule', 'wash-blog-manager'),
            'type'          => __('unsupported post type', 'wash-blog-manager'),
        ];
        $parts = [];
        foreach (explode('.', $skipInfo) as $chunk) {
            $kv = explode('-', $chunk);
            if (count($kv) === 2 && isset($labels[$kv[0]]) && (int) $kv[1] > 0) {
                $parts[] = (int) $kv[1] . ' ' . $labels[$kv[0]];
            }
        }
        return implode(', ', $parts);
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
        if (!in_array($doaction, ['wash_publish', 'wash_republish', 'wash_unpublish'], true)) {
            return $redirectTo;
        }

        if (!$this->apiClient->isConfigured()) {
            return add_query_arg('wash_bulk_error', 'not_configured', $redirectTo);
        }

        $allowedTypes = array_map('trim', explode(',', WashContext::option('wash_post_types', 'post,page')));

        // Pre-validate: split into "to act on" vs "skipped". Per-post action-specific
        // skip rules: already-synced for publish, never-synced for republish/unpublish,
        // not currently published for publish/republish.
        // Pre-validate with CATEGORISED skip reasons (no more silent skipping, #255)
        // and MULTISTORE-aware "is synced" (a post synced only to a secondary
        // connection must not look "never synced" to the bulk handler).
        $validIds = [];
        $skip = ['unsynced' => 0, 'notpublished' => 0, 'alreadysynced' => 0, 'nomapping' => 0, 'type' => 0];
        foreach ($postIds as $postId) {
            $post = get_post($postId);
            if (!$post || !in_array($post->post_type, $allowedTypes, true)) {
                $skip['type']++;
                continue;
            }
            $isSynced    = $this->isPostSyncedAnyConnection($postId);
            $isPublished = $post->post_status === 'publish';
            switch ($doaction) {
                case 'wash_publish':
                case 'wash_republish':
                    // Publish = sync the selected posts (create OR update — does NOT
                    // skip already-synced). Re-publish only touches already-synced.
                    if (!$isPublished)                                  { $skip['notpublished']++; continue 2; }
                    if ($doaction === 'wash_republish' && !$isSynced)   { $skip['unsynced']++;     continue 2; }
                    if (!WashBlogRouter::hasMapping($postId))           { $skip['nomapping']++;    continue 2; }
                    break;
                case 'wash_unpublish':
                    if (!$isSynced) { $skip['unsynced']++; continue 2; }
                    break;
            }
            $validIds[] = (int) $postId;
        }
        $skipped  = array_sum($skip);
        $skipInfo = self::encodeSkip($skip);

        // Nothing to do — show notice with the skip breakdown and return.
        if (empty($validIds)) {
            return add_query_arg([
                'wash_bulk_action' => $doaction,
                'wash_bulk_processed' => 0,
                'wash_bulk_skipped' => $skipped,
                'wash_bulk_skip' => $skipInfo,
                'wash_bulk_errors' => 0,
            ], $redirectTo);
        }

        $inlineMode = WashContext::option('wash_sync_inline', '1') === '1';

        if ($inlineMode) {
            // Hand off to a JS-driven progress page that AJAX-batches the work.
            // Each batch is its own short request, so no PHP / nginx / FastCGI
            // timeout can kill a 500-post migration; the user sees live progress.
            $token = wp_generate_password(20, false, false);
            set_transient('wash_bulk_' . $token, [
                'action'    => $doaction,
                'postIds'   => $validIds,
                'skipped'   => $skipped,
                'skip'      => $skipInfo,
                'returnUrl' => $redirectTo,
            ], HOUR_IN_SECONDS);

            return add_query_arg([
                'page'  => 'wash-bulk-progress',
                'token' => $token,
            ], admin_url('admin.php'));
        }

        // Cron mode: queue each sync as a WP-Cron event, return fast. Requires
        // WP-Cron to actually fire (broken on idle / password-protected sites —
        // see the wash_sync_inline setting).
        $processed = 0;
        $errors = 0;
        foreach ($validIds as $postId) {
            if ($doaction === 'wash_unpublish') {
                $result = $this->unpublishFromShopify($postId, get_post($postId));
                if (is_wp_error($result)) { $errors++; } else { $processed++; }
            } else {
                $this->scheduleAsyncSync($postId);
                $processed++;
            }
        }

        if ($processed > 0 && in_array($doaction, ['wash_publish', 'wash_republish'], true)) {
            $this->triggerCronAsync();
        }

        return add_query_arg([
            'wash_bulk_action' => $doaction,
            'wash_bulk_processed' => $processed,
            'wash_bulk_skipped' => $skipped,
            'wash_bulk_skip' => $skipInfo,
            'wash_bulk_errors' => $errors,
        ], $redirectTo);
    }

    /**
     * Schedule a single post sync via WP-Cron — runs in a separate request
     * so it cannot be killed by per-request timeouts (PHP, nginx, FastCGI, etc).
     *
     * @param int $postId Post ID.
     */
    private function scheduleAsyncSync($postId) {
        // Mark the post as queued immediately so the spinner icon shows in the
        // posts list before WP-Cron has a chance to fire the actual sync.
        // syncManually() will re-set these when the cron event runs.
        update_post_meta((int) $postId, '_wash_sync_status', 'queued');
        update_post_meta((int) $postId, '_wash_queued_at', current_time('mysql'));

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
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- async post-sync fires on save_post for a single post; the API round-trip + image download can exceed default max_execution_time on slow networks. Suppressed (@) because some hosts disable set_time_limit.
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
        // 0.01s was too aggressive — cURL often returned timeout before
        // even sending the request, so the cron loop never started.
        // 1s gives the socket time to open + push headers; blocking=false
        // means we don't wait for the response anyway.
        wp_remote_post($cronUrl, [
            'timeout'   => 1.0,
            'blocking'  => false,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- `https_local_ssl_verify` is WordPress core's documented filter for local SSL verification (https://developer.wordpress.org/reference/hooks/https_local_ssl_verify/); we apply the same filter that wp-cron.php applies, by design.
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

        $sync = new WashSync();
        $payload = [
            'action'   => 'delete',
            'wpPostId' => $postId,
            'wpSiteId' => get_current_blog_id(),
            'language' => $sync->getPostLanguage($postId),
        ];

        $hreflangs = new WashHreflangs();
        $group = $hreflangs->getTranslationGroupSource($postId);
        if ($group) {
            $payload['translationGroup'] = $group;
        }

        $result = $this->apiClient->post($endpoint, $payload);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- routed through washLog() which is gated by the WASH_DEBUG constant (off in production); the result array is helpful in debug output.
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

        // Clear local sync meta — the post is no longer on Shopify.
        foreach (['_wash_article_id', '_wash_blog_id', '_wash_blog_handle', '_wash_handle',
                  '_wash_synced_at', '_wash_sync_status', '_wash_sync_error',
                  '_wash_shopify_url', '_wash_storefront_subfolder'] as $key) {
            delete_post_meta($postId, WashContext::metaKey($key));
        }

        // Make the unpublish STICK: flag the post so auto-sync-on-save does not
        // immediately re-push it while it stays WordPress-published. A manual
        // "Sync to Shopify" clears this flag and re-publishes (#255).
        update_post_meta($postId, WashContext::metaKey('_wash_skip_autosync'), '1');

        washLog("[wash] unpublishFromShopify: success, cleared meta + set skip-autosync for postId={$postId}");
        return true;
    }
}
