<?php
/**
 * Connect a Shopify store with a one-time code (#285).
 *
 * Shopify App Store requirement 2.3.1 forbids asking merchants to type a
 * myshopify.com domain, so the connection starts in Shopify: the merchant opens
 * Wash → Connections in their Shopify admin, generates a code and pastes it
 * into the connect form here. This class redeems the code with the app
 * (server-to-server) and stores the credentials it returns, so the plugin
 * learns the store without ever asking for it.
 *
 * One AJAX action serves both forms rendered by connect-form.php:
 *   mode=primary — first store; writes the primary (unprefixed) credentials
 *   mode=add     — an additional store; adds a WashConnection (plan-gated)
 *
 * @package Wash
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashConnectCode {

    /** Nonce action shared with connections.js. */
    const NONCE = 'wash_connect_code';

    public function init() {
        add_action('wp_ajax_wash_redeem_connect_code', [$this, 'ajaxRedeem']);
    }

    /**
     * Redeem the posted code and reply with the URL to send the admin to.
     * Every exit is a wp_send_json_* call.
     */
    public function ajaxRedeem() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to connect a store.', 'wash-blog-manager')], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');

        if (get_option('wash_consent_accepted', '0') !== '1') {
            wp_send_json_error(['message' => __('Tick the data processing consent box first.', 'wash-blog-manager')]);
        }

        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
        if ($code === '') {
            wp_send_json_error(['message' => __('Paste the connect code from Wash in your Shopify admin.', 'wash-blog-manager')]);
        }

        $isAdd = isset($_POST['mode']) && sanitize_key(wp_unslash($_POST['mode'])) === 'add';
        if ($isAdd && !WashConnectionsAdmin::planAllowsMultiStore()) {
            wp_send_json_error(['message' => __('Connecting more than one store needs a higher Wash plan.', 'wash-blog-manager')]);
        }

        $connection = $isAdd ? self::postedAdvancedSettings() : WashConnection::get(WashConnection::PRIMARY);
        $result = (new WashApiClient($connection))->redeemConnectCode($code, self::siteOrigin(), get_bloginfo('name'));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $redirect = $isAdd ? self::addConnection($result, $connection) : self::connectPrimary($result);
        wp_send_json_success(['redirect' => $redirect]);
    }

    /**
     * Store the primary credentials; on a reconnect, pull the settings backup
     * the app kept. Mirrors what the redirect-based connect used to do. When the
     * site switches to a different store, the old store's per-post sync state
     * is cleared first.
     *
     * @param array $result Redeemed credentials
     * @return string Where to send the admin next
     */
    private static function connectPrimary(array $result) {
        $previousStore = (string) get_option('wash_shopify_store', '');
        $store = sanitize_text_field($result['store']);
        update_option('wash_api_key', sanitize_text_field($result['apiKey']));
        update_option('wash_shopify_store', $store);

        // Another store took over this site: the posts' sync state belongs to
        // the old one (#294). Reconnecting the same store keeps it.
        if (WashSyncMeta::isDifferentStore($previousStore, $store)) {
            WashSyncMeta::clearForConnection(WashConnection::PRIMARY);
            delete_transient(WashContext::transientKey('wash_plan_info'));
        }

        $redirect = admin_url('options-general.php?page=wash&connected=1');
        if (empty($result['isReauth'])) {
            return $redirect;
        }
        $restored = !empty($result['hasBackup']) && self::restoreBackup();
        return add_query_arg('restored', $restored ? '1' : '0', $redirect);
    }

    /**
     * Restore the settings backup into the primary connection, using a client
     * built after the new key was stored.
     *
     * @return bool True when settings were restored
     */
    private static function restoreBackup() {
        $client = new WashApiClient(WashConnection::get(WashConnection::PRIMARY));
        $response = $client->getBackedUpSettings();
        if (is_wp_error($response) || empty($response['success']) || empty($response['settings'])) {
            washLog('[wash] No settings restored after reconnect');
            return false;
        }
        $client->restoreSettings($response['settings']);
        return true;
    }

    /**
     * @param array $result     Redeemed credentials
     * @param array $connection App URL and HTTP Basic credentials from the form
     * @return string The new store's settings page
     */
    private static function addConnection(array $result, array $connection) {
        $id = WashConnection::add(
            sanitize_text_field($result['store']),
            sanitize_text_field($result['apiKey']),
            $connection['appUrl'],
            $connection['httpUser'],
            $connection['httpPass']
        );
        return WashConnectionsAdmin::connectionUrl($id, 'added');
    }

    /**
     * Optional "Advanced" fields of the add form (custom app URL, HTTP Basic).
     * Only called from ajaxRedeem() after check_ajax_referer() and the
     * capability check.
     */
    private static function postedAdvancedSettings() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajaxRedeem() before this runs.
        return [
            'appUrl'   => isset($_POST['appUrl']) ? esc_url_raw(wp_unslash($_POST['appUrl'])) : '',
            'httpUser' => isset($_POST['httpUser']) ? sanitize_text_field(wp_unslash($_POST['httpUser'])) : '',
            'httpPass' => isset($_POST['httpPass']) ? sanitize_text_field(wp_unslash($_POST['httpPass'])) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * This site's origin (scheme://host[:port]). The app matches an existing
     * connection by it, so a reconnect reuses the connection and its backup.
     */
    private static function siteOrigin() {
        $parts = wp_parse_url(get_site_url());
        $origin = $parts['scheme'] . '://' . $parts['host'];
        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }
}
