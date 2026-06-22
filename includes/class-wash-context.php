<?php
/**
 * Active-connection context for multi-store support (#216).
 *
 * Request-scoped accessor that every wash_* option / post-meta / transient read
 * routes through, so the plugin operates on the *currently selected* Shopify
 * connection instead of one global store. Preferred over WordPress
 * `option_{$key}` filters (those intercept globally, are bypassed by the
 * options.php save path, and are awkward to test).
 *
 * Zero-migration invariant: for the `primary` connection every key resolver
 * returns the historical UNPREFIXED key, so an existing single-store install
 * reads and writes exactly the same option/meta/transient names as before.
 * Additional connections get a `wash_c_<id>_` / `_<id>` namespace.
 *
 * apiClient() is added in Phase 1, once WashApiClient becomes connection-aware.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashContext {

    /** Memoized active connection id for this request (null = not yet resolved). */
    private static $activeId = null;

    /** @var array<string,WashApiClient> Memoized API clients, keyed by connection id. */
    private static $clients = array();

    /**
     * The active connection id. Resolution order:
     *   1. an explicit setActiveId() override (sync loop, AJAX),
     *   2. POSTed `washConnectionId` (settings save routing),
     *   3. the admin page slug `wash-conn-<id>`,
     *   4. `primary`.
     * Any candidate that is not a real connection falls back to `primary`.
     *
     * @return string
     */
    public static function activeId() {
        if (self::$activeId !== null) {
            return self::$activeId;
        }

        $id = WashConnection::PRIMARY;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- non-authoritative routing hint only; it selects which connection's settings are displayed/targeted, and the state-changing action (save/add/remove) is separately nonce- and capability-gated.
        if (isset($_POST['washConnectionId'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $id = sanitize_key(wp_unslash($_POST['washConnectionId']));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin page-slug routing only (which connection's screen to render); sanitized, no state change.
        } elseif (isset($_GET['page'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $page = sanitize_key(wp_unslash($_GET['page']));
            if (strpos($page, 'wash-conn-') === 0) {
                $id = substr($page, strlen('wash-conn-'));
            }
        }

        self::$activeId = WashConnection::exists($id) ? $id : WashConnection::PRIMARY;
        return self::$activeId;
    }

    /**
     * Force the active connection (e.g. the per-connection sync loop). Resets
     * any memoized per-connection state. Pass null to clear and re-resolve.
     *
     * @param string|null $id
     */
    public static function setActiveId($id) {
        self::$activeId = ($id === null) ? null : (string) $id;
        self::$clients  = array();
    }

    /**
     * Memoized WashApiClient bound to the active connection. The per-connection
     * sync loop calls setActiveId() then apiClient() to talk to each store with
     * its own credentials/app URL.
     *
     * @return WashApiClient
     */
    public static function apiClient() {
        $id = self::activeId();
        if (!isset(self::$clients[$id])) {
            self::$clients[$id] = new WashApiClient(WashConnection::get($id));
        }
        return self::$clients[$id];
    }

    /**
     * The active connection definition (id, store, apiKey, appUrl, httpUser,
     * httpPass, enabled, …). Use this for raw credentials — they do NOT live in
     * prefixed options for non-primary connections (they are held in the registry),
     * so WashContext::option('wash_api_key') would not find them. For primary this
     * returns the historical unprefixed credentials, so behaviour is unchanged.
     *
     * @return array<string,mixed>
     */
    public static function connection() {
        return WashConnection::get(self::activeId());
    }

    /**
     * Option name for the active connection: base key for primary, prefixed
     * `wash_c_<id>_<key>` otherwise.
     *
     * @param string $key
     * @return string
     */
    public static function optionKey($key) {
        $id = self::activeId();
        return ($id === WashConnection::PRIMARY) ? $key : 'wash_c_' . $id . '_' . $key;
    }

    /**
     * Post-meta key for the active connection: base key for primary, suffixed
     * `<base>_<id>` otherwise.
     *
     * @param string $base
     * @return string
     */
    public static function metaKey($base) {
        $id = self::activeId();
        return ($id === WashConnection::PRIMARY) ? $base : $base . '_' . $id;
    }

    /**
     * Transient key for the active connection: base for primary, suffixed
     * `<base>_<id>` otherwise.
     *
     * @param string $base
     * @return string
     */
    public static function transientKey($base) {
        // Transients share the same per-connection suffix scheme as post-meta.
        return self::metaKey($base);
    }

    /**
     * get_option for the active connection.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function option($key, $default = false) {
        return get_option(self::optionKey($key), $default);
    }

    /**
     * update_option for the active connection.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public static function updateOption($key, $value) {
        return update_option(self::optionKey($key), $value);
    }

    /**
     * delete_option for the active connection.
     *
     * @param string $key
     * @return bool
     */
    public static function deleteOption($key) {
        return delete_option(self::optionKey($key));
    }
}
