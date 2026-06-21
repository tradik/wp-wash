<?php
/**
 * Uninstall script for Wash: Blog Manager.
 * Runs when the plugin is deleted via the WordPress admin.
 *
 * Data is preserved by default — it is only removed when the merchant explicitly
 * opted in via the "Remove all Wash data on uninstall" setting
 * (wash_uninstall_remove_data). This keeps a reinstall / accidental delete from
 * losing every post's Shopify mapping.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Purge all Wash data for the current blog. Only called when the merchant opted
 * in for that blog.
 *
 * Options and post-meta are removed with a single LIKE sweep each: it is complete
 * by construction (no key list to keep in sync with the rest of the plugin, so no
 * orphaned wash_* rows can be left behind — LOW-11, #172) and covers any future
 * keys automatically.
 */
function washPurgeCurrentBlog() {
    global $wpdb;

    // Every wash_* option (includes wash_uninstall_remove_data itself).
    $optionLike = $wpdb->esc_like('wash_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall-time teardown; caching is irrelevant once the plugin is gone.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $optionLike));

    // Every _wash_* post-meta key.
    $metaLike = $wpdb->esc_like('_wash_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall-time teardown; caching is irrelevant once the plugin is gone.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $metaLike));

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DROP TABLE cannot bind its identifier; $wpdb->prefix is trusted.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wash_sync");

    wp_clear_scheduled_hook('wash_clear_stale_errors');
    wp_clear_scheduled_hook('wash_ping_shopify');
}

if (is_multisite()) {
    $wash_siteIds = get_sites(['fields' => 'ids', 'number' => 1000]);
    foreach ($wash_siteIds as $wash_siteId) {
        switch_to_blog($wash_siteId);
        // Per-site opt-in: only purge sites whose admin asked to remove data.
        if (get_option('wash_uninstall_remove_data')) {
            washPurgeCurrentBlog();
        }
        restore_current_blog();
    }
} elseif (get_option('wash_uninstall_remove_data')) {
    washPurgeCurrentBlog();
}
