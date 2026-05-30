<?php
/**
 * Uninstall script for Wash: Blog Manager.
 * Runs when the plugin is deleted via the WordPress admin.
 * Cleans up all plugin options and database tables.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wash_optionKeys = [
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
    'wash_auto_clear_errors',
    'wash_sync_inline',
    'wash_consent_accepted',
    'wash_consent_accepted_at',
];

if (is_multisite()) {
    $wash_siteIds = get_sites(['fields' => 'ids', 'number' => 1000]);
    foreach ($wash_siteIds as $wash_siteId) {
        switch_to_blog($wash_siteId);
        foreach ($wash_optionKeys as $wash_key) {
            delete_option($wash_key);
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall-time schema cleanup; DROP TABLE cannot use prepared values for the identifier, $wpdb->prefix is trusted, and caching is irrelevant on a teardown query.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wash_sync");
        restore_current_blog();
    }
} else {
    foreach ($wash_optionKeys as $wash_key) {
        delete_option($wash_key);
    }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above; same teardown pattern for single-site installs.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wash_sync");
}

// Clean up post meta across all posts
delete_metadata('post', 0, '_wash_article_id', '', true);
delete_metadata('post', 0, '_wash_blog_id', '', true);
delete_metadata('post', 0, '_wash_blog_handle', '', true);
delete_metadata('post', 0, '_wash_synced_at', '', true);
delete_metadata('post', 0, '_wash_file_id', '', true);
delete_metadata('post', 0, '_wash_cdn_url', '', true);
delete_metadata('post', 0, '_wash_sync_status', '', true);
delete_metadata('post', 0, '_wash_sync_error', '', true);
delete_metadata('post', 0, '_wash_sync_error_at', '', true);
delete_metadata('post', 0, '_wash_queued_at', '', true);
delete_metadata('post', 0, '_wash_job_id', '', true);

// Clear scheduled events
wp_clear_scheduled_hook('wash_clear_stale_errors');
wp_clear_scheduled_hook('wash_ping_shopify');
