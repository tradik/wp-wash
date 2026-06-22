<?php
/**
 * Connection registry for multi-store support (#216).
 *
 * One WordPress install can push to several Shopify stores, each an isolated
 * connection (own credentials, settings, sync state). The registry lives in a
 * single serialized option, `wash_connections`, keyed by a short connection id.
 *
 * Zero-migration invariant: the `primary` connection is a THIN POINTER whose
 * credentials are NOT stored in the registry — they stay in the historical
 * unprefixed options (`wash_api_key`, `wash_shopify_store`, `wash_app_url`,
 * `wash_http_user`, `wash_http_pass`). So an existing single-store install is
 * untouched: `get('primary')` returns exactly today's values, and the registry
 * option may be absent entirely until a second store is added.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashConnection {

    /** Literal id of the always-present primary connection. */
    const PRIMARY = 'primary';

    /** Option holding the serialized registry of additional connections. */
    const STORAGE_OPTION = 'wash_connections';

    /**
     * Synthesize the primary connection from the historical unprefixed options.
     * Credentials are read live every time so they never diverge from the
     * single-store config. A registry entry, if present, only contributes
     * non-credential metadata (createdAt); `enabled` is forced true — primary
     * is never disabled (decision #4).
     *
     * @return array<string,mixed>
     */
    private static function primaryEntry() {
        $stored = get_option(self::STORAGE_OPTION, array());
        $meta   = (is_array($stored) && isset($stored[self::PRIMARY]) && is_array($stored[self::PRIMARY]))
            ? $stored[self::PRIMARY] : array();

        return array(
            'id'        => self::PRIMARY,
            'store'     => get_option('wash_shopify_store', ''),
            'apiKey'    => get_option('wash_api_key', ''),
            'appUrl'    => get_option('wash_app_url', ''),
            'httpUser'  => get_option('wash_http_user', ''),
            'httpPass'  => get_option('wash_http_pass', ''),
            'enabled'   => true,
            'isPrimary' => true,
            'createdAt' => isset($meta['createdAt']) ? (int) $meta['createdAt'] : 0,
        );
    }

    /**
     * Read the stored registry, returning only well-formed non-primary entries
     * keyed by id (each normalized with id/isPrimary).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function storedConnections() {
        $stored = get_option(self::STORAGE_OPTION, array());
        $out    = array();
        if (is_array($stored)) {
            foreach ($stored as $id => $conn) {
                if ($id === self::PRIMARY || !is_array($conn) || !empty($conn['isPrimary'])) {
                    continue;
                }
                $conn['id']        = (string) $id;
                $conn['isPrimary'] = false;
                $out[(string) $id] = $conn;
            }
        }
        return $out;
    }

    /**
     * Get a single connection by id, or null if it does not exist.
     *
     * @param string $id
     * @return array<string,mixed>|null
     */
    public static function get($id) {
        if ($id === self::PRIMARY) {
            return self::primaryEntry();
        }
        $stored = self::storedConnections();
        return isset($stored[$id]) ? $stored[$id] : null;
    }

    /**
     * All connections, primary first.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all() {
        return array(self::PRIMARY => self::primaryEntry()) + self::storedConnections();
    }

    /** @param string $id */
    public static function exists($id) {
        return self::get($id) !== null;
    }

    /** @param string $id */
    public static function isPrimary($id) {
        return $id === self::PRIMARY;
    }

    /**
     * Find a connection id by its API key (primary included). The callback/media
     * endpoint use this to route an incoming X-Wash-Key request to the connection
     * that owns the key, so per-connection meta/options resolve correctly.
     *
     * @param string $apiKey
     * @return string|null Connection id, or null when no connection owns the key.
     */
    public static function getByApiKey($apiKey) {
        if ((string) $apiKey === '') {
            return null;
        }
        foreach (self::all() as $id => $conn) {
            if (!empty($conn['apiKey']) && hash_equals((string) $conn['apiKey'], (string) $apiKey)) {
                return $id;
            }
        }
        return null;
    }

    /** Total connection count (always >= 1 — primary). */
    public static function count() {
        return count(self::all());
    }

    /** Count of enabled connections. */
    public static function enabledCount() {
        $n = 0;
        foreach (self::all() as $conn) {
            if (!empty($conn['enabled'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Register a new (non-primary) connection and return its id. The caller is
     * responsible for plan-gating (Business/Enterprise) and validating the
     * credentials — this only persists the entry.
     *
     * @return string New connection id.
     */
    public static function add($store, $apiKey, $appUrl = '', $httpUser = '', $httpPass = '') {
        $stored = get_option(self::STORAGE_OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        do {
            $id = strtolower(wp_generate_password(6, false));
        } while ($id === self::PRIMARY || isset($stored[$id]));

        $stored[$id] = array(
            'id'        => $id,
            'store'     => (string) $store,
            'apiKey'    => (string) $apiKey,
            'appUrl'    => (string) $appUrl,
            'httpUser'  => (string) $httpUser,
            'httpPass'  => (string) $httpPass,
            'enabled'   => true,
            'isPrimary' => false,
            'createdAt' => time(),
        );
        update_option(self::STORAGE_OPTION, $stored);
        return $id;
    }

    /**
     * Enable/disable a non-primary connection (downgrade pauses, never deletes —
     * decision #4). Primary cannot be toggled. Returns false if the id is
     * primary or unknown.
     */
    public static function setEnabled($id, $enabled) {
        if ($id === self::PRIMARY) {
            return false;
        }
        $stored = get_option(self::STORAGE_OPTION, array());
        if (!is_array($stored) || !isset($stored[$id])) {
            return false;
        }
        $stored[$id]['enabled'] = (bool) $enabled;
        update_option(self::STORAGE_OPTION, $stored);
        return true;
    }

    /**
     * Remove a non-primary connection from the registry. Primary is refused.
     * Per-connection data (prefixed options/meta/transients, sync-table rows)
     * is purged by the phases that create it; in this foundation phase only the
     * registry entry exists, so removing it is the complete teardown.
     */
    public static function remove($id) {
        if ($id === self::PRIMARY) {
            return false;
        }
        $stored = get_option(self::STORAGE_OPTION, array());
        if (!is_array($stored) || !isset($stored[$id])) {
            return false;
        }
        unset($stored[$id]);
        update_option(self::STORAGE_OPTION, $stored);
        return true;
    }
}
