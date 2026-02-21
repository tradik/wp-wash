<?php
/**
 * Handles plugin activation and deactivation.
 * Creates required database tables and default options.
 */
class wp4shpyActivator {

    /**
     * Run on plugin activation.
     * Sets up default options and creates sync tracking table.
     */
    public static function activate() {
        $defaults = [
            'wp4shpy_app_url'    => '',
            'wp4shpy_api_key'    => '',
            'wp4shpy_api_secret' => '',
            'wp4shpy_sync_on_publish' => '1',
            'wp4shpy_sync_images'     => '1',
            'wp4shpy_sync_metafields' => '1',
            'wp4shpy_post_types'      => 'post',
            'wp4shpy_blog_name'       => get_bloginfo('name'),
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        self::createSyncTable();
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Create the local sync tracking table.
     */
    private static function createSyncTable() {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wp4shpy_sync';
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
}
