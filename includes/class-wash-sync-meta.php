<?php
/**
 * Per-connection sync state kept in post meta.
 *
 * Every key here records how a post relates to ONE Shopify store (article id,
 * blog, CDN files, redirects, last sync). When the primary connection moves to a
 * different store, that state describes the old store: the Posts list would show
 * a green "synced" icon and the next save would try to update an article that is
 * not there. Clearing it makes every post start fresh against the new store (#294).
 *
 * @package Wash
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashSyncMeta {
    /**
     * Base meta keys that WashContext::metaKey() scopes per connection.
     */
    const KEYS = array(
        '_wash_article_id', '_wash_page_id', '_wash_blog_id', '_wash_blog_handle',
        '_wash_handle', '_wash_source_handle', '_wash_shopify_url',
        '_wash_synced_at', '_wash_sync_status', '_wash_sync_error', '_wash_sync_error_at',
        '_wash_sync_pending', '_wash_queued_at', '_wash_job_id', '_wash_no_mapping',
        '_wash_skip_autosync', '_wash_file_id', '_wash_cdn_url', '_wash_image_warnings',
        '_wash_redirect_created', '_wash_redirect_to',
    );

    /**
     * Whether a reconnect points at a different store than before. A first
     * connect (no previous store) is not a switch, and "mystore" equals
     * "mystore.myshopify.com".
     *
     * @param string $previous Store stored before the reconnect
     * @param string $next     Store returned by the app
     * @return bool
     */
    public static function isDifferentStore($previous, $next) {
        $previous = self::normalizeStore($previous);
        return $previous !== '' && $previous !== self::normalizeStore($next);
    }

    /**
     * Delete one connection's sync state from every post.
     *
     * @param string $connectionId WashConnection id (WashConnection::PRIMARY for the primary)
     * @return void
     */
    public static function clearForConnection($connectionId) {
        WashContext::setActiveId($connectionId);
        foreach (self::KEYS as $base) {
            delete_post_meta_by_key(WashContext::metaKey($base));
        }
        WashContext::setActiveId(null);
    }

    /**
     * @param string $store Handle or domain
     * @return string Lower-case handle without ".myshopify.com"
     */
    private static function normalizeStore($store) {
        $store = strtolower(trim((string) $store));
        $suffix = '.myshopify.com';
        if (substr($store, -strlen($suffix)) === $suffix) {
            $store = substr($store, 0, -strlen($suffix));
        }
        return $store;
    }
}
