<?php
/**
 * Uninstall script for wp4shpy.
 * Runs when the plugin is deleted via the WordPress admin.
 * Cleans up all plugin options and database tables.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$optionKeys = [
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
];

if (is_multisite()) {
    $siteIds = get_sites(['fields' => 'ids', 'number' => 1000]);
    foreach ($siteIds as $siteId) {
        switch_to_blog($siteId);
        foreach ($optionKeys as $key) {
            delete_option($key);
        }
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wp4shpy_sync");
        restore_current_blog();
    }
} else {
    foreach ($optionKeys as $key) {
        delete_option($key);
    }
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wp4shpy_sync");
}

// Clean up post meta across all posts
delete_metadata('post', 0, '_wp4shpy_article_id', '', true);
delete_metadata('post', 0, '_wp4shpy_blog_id', '', true);
delete_metadata('post', 0, '_wp4shpy_synced_at', '', true);
delete_metadata('post', 0, '_wp4shpy_file_id', '', true);
delete_metadata('post', 0, '_wp4shpy_cdn_url', '', true);
