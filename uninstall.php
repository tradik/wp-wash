<?php
/**
 * Uninstall script for Wash: Blog Manager.
 * Runs when the plugin is deleted via the WordPress admin.
 * Cleans up all plugin options and database tables.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$optionKeys = [
    'wash_shopify_store',
    'wash_api_key',
    'wash_http_user',
    'wash_http_pass',
    'wash_sync_on_publish',
    'wash_files_enabled',
    'wash_sync_metafields',
    'wash_post_types',
    'wash_block_robots',
    'wash_meta_wp_core',
    'wash_meta_yoast',
    'wash_meta_rankmath',
    'wash_meta_acf',
    'wash_meta_field_config',
    'wash_hreflangs_enabled',
];

if (is_multisite()) {
    $siteIds = get_sites(['fields' => 'ids', 'number' => 1000]);
    foreach ($siteIds as $siteId) {
        switch_to_blog($siteId);
        foreach ($optionKeys as $key) {
            delete_option($key);
        }
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wash_sync");
        restore_current_blog();
    }
} else {
    foreach ($optionKeys as $key) {
        delete_option($key);
    }
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wash_sync");
}

// Clean up post meta across all posts
delete_metadata('post', 0, '_wash_article_id', '', true);
delete_metadata('post', 0, '_wash_blog_id', '', true);
delete_metadata('post', 0, '_wash_synced_at', '', true);
delete_metadata('post', 0, '_wash_file_id', '', true);
delete_metadata('post', 0, '_wash_cdn_url', '', true);
