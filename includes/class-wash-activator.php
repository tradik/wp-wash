<?php
/**
 * Handles plugin activation and deactivation.
 * Creates required database tables and default options.
 */
class WashActivator {

    /**
     * Initialize hooks for version ping.
     */
    public static function init() {
        add_action('wash_ping_shopify', [self::class, 'sendVersionPing']);
        add_action('upgrader_process_complete', [self::class, 'onPluginUpdate'], 10, 2);
    }

    /**
     * Run on plugin activation.
     * Sets up default options and creates sync tracking table.
     */
    public static function activate() {
        $defaults = [
            'wash_app_url'    => '',
            'wash_api_key'    => '',
            'wash_sync_on_publish' => '1',
            'wash_sync_metafields' => '1',
            'wash_post_types'      => 'post,page',
            'wash_blog_name'       => get_bloginfo('name'),
            'wash_auto_clear_errors' => '1',
            'wash_sync_inline' => '0',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Migrate legacy wash_sync_images → wash_files_enabled (v1.17.7)
        $legacySyncImages = get_option('wash_sync_images');
        if ($legacySyncImages !== false) {
            if (get_option('wash_files_enabled') === false) {
                add_option('wash_files_enabled', $legacySyncImages);
            }
            delete_option('wash_sync_images');
        }

        // Clear OPcache to ensure new code is loaded
        self::clearOpcache();

        self::createSyncTable();
        flush_rewrite_rules();

        // Schedule ping to report plugin version (delayed to ensure settings are loaded)
        if (!wp_next_scheduled('wash_ping_shopify')) {
            wp_schedule_single_event(time() + 5, 'wash_ping_shopify');
        }

        // Daily cleanup of stale _wash_sync_error meta
        if (!wp_next_scheduled('wash_clear_stale_errors')) {
            wp_schedule_event(time() + 60, 'daily', 'wash_clear_stale_errors');
        }
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('wash_ping_shopify');
        wp_clear_scheduled_hook('wash_clear_stale_errors');
        flush_rewrite_rules();
    }

    /**
     * Called after plugin update via WordPress upgrader.
     *
     * @param WP_Upgrader $upgrader
     * @param array $options
     */
    public static function onPluginUpdate($upgrader, $options) {
        if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
            return;
        }

        // Check if our plugin was updated
        $ourPlugin = plugin_basename(WASH_PLUGIN_DIR . 'wash.php');
        $plugins = $options['plugins'] ?? [];

        if (in_array($ourPlugin, $plugins, true)) {
            // Clear OPcache to ensure new code is loaded immediately
            self::clearOpcache();
            washLog('[wash] OPcache cleared after plugin update to ' . WASH_VERSION);

            // Schedule ping after update
            if (!wp_next_scheduled('wash_ping_shopify')) {
                wp_schedule_single_event(time() + 5, 'wash_ping_shopify');
            }
        }
    }

    /**
     * Send version ping to Shopify App.
     */
    public static function sendVersionPing() {
        $apiKey = get_option('wash_api_key', '');
        if (empty($apiKey)) {
            return; // Not configured yet
        }

        $client = new WashApiClient();
        $client->ping();
    }

    /**
     * Create the local sync tracking table.
     * Public so that WashSync::logSync() can self-heal when the table
     * goes missing (e.g. a dev database restored without it, or the
     * activator hook was never fired during a manual code drop-in).
     */
    public static function createSyncTable() {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wash_sync';
        $charsetCollate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        $sql = "CREATE TABLE IF NOT EXISTS {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            postId BIGINT UNSIGNED NOT NULL,
            shopifyArticleId VARCHAR(255) DEFAULT NULL,
            shopifyBlogId VARCHAR(255) DEFAULT NULL,
            syncAction VARCHAR(20) NOT NULL,
            syncStatus VARCHAR(20) NOT NULL DEFAULT 'pending',
            errorMessage TEXT DEFAULT NULL,
            syncedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_postId (postId),
            KEY idx_syncStatus (syncStatus)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Clear OPcache for all plugin files.
     * Tries opcache_reset first, then invalidates individual files.
     */
    private static function clearOpcache() {
        if (!function_exists('opcache_get_status')) {
            return;
        }

        // Try full reset first
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Also invalidate individual plugin files (some hosts restrict opcache_reset)
        if (function_exists('opcache_invalidate')) {
            $pluginDir = defined('WASH_PLUGIN_DIR') ? WASH_PLUGIN_DIR : dirname(__DIR__) . '/';
            $files = glob($pluginDir . '*.php') ?: [];
            $includeFiles = glob($pluginDir . 'includes/*.php') ?: [];
            foreach (array_merge($files, $includeFiles) as $file) {
                @opcache_invalidate($file, true);
            }
        }
    }
}
