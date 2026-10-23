<?php
/**
 * Admin UI for managing multiple Shopify store connections (#216).
 *
 * Registers the "Connections" manager page and a hidden, URL-routable settings
 * page per additional connection, and handles removing,
 * enabling/disabling, and saving per-connection sync settings. Kept separate from
 * WashAdmin (SRP) — WashAdmin owns the primary single-store settings page.
 */
if (!defined('ABSPATH')) {
    exit;
}

class WashConnectionsAdmin {

    /**
     * The single multi-store hub: the Connection tab of the primary Manager page
     * (WashAdmin's `wash` page). There is no separate "Connections" page anymore —
     * the list + add-store flow live here, and per-store settings hang off it as
     * routable Settings submenu items (#252).
     *
     * @param string $key   Optional status flag name (added/removed/error/…).
     * @param string $value Optional status flag value.
     * @return string Admin URL of the hub, with the flag appended when given.
     */
    private static function hubUrl($key = '', $value = '') {
        $url = admin_url('options-general.php?page=wash&tab=connection');
        return $key === '' ? $url : add_query_arg($key, $value, $url);
    }

    /**
     * Register the admin hooks. The primary settings page (WashAdmin) is untouched,
     * so single-store installs see no change until a second connection is added.
     */
    public function init() {
        add_action('admin_menu', [$this, 'registerPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_wash_remove_connection', [$this, 'handleRemove']);
        add_action('admin_post_wash_toggle_connection', [$this, 'handleToggle']);

        // Background plan-downgrade enforcement for merchants who never open the page.
        add_action('wash_enforce_plan', [$this, 'enforcePlanDowngrade']);
        if (!wp_next_scheduled('wash_enforce_plan')) {
            wp_schedule_event(time() + 60, 'daily', 'wash_enforce_plan');
        }
    }

    /**
     * Enqueue the connect-code script on the manager page and localise the dynamic
     * values (AJAX URL, nonce, messages) — keeps the view free of inline <script>.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueueAssets($hook) {
        // The Store Connections hub (with the "New connection" button) renders on the
        // primary Manager page AND on every additional store's settings page
        // (full-plugin-per-store, #252/#264), so the connect-code script must load on
        // all of them — otherwise the button is dead on the per-store pages. The hook
        // suffixes share the 'settings_page_wash' prefix: 'settings_page_wash' for the
        // primary and 'settings_page_wash-conn-<id>' for each additional store.
        if (strpos((string) $hook, 'settings_page_wash') !== 0) {
            return;
        }
        wp_enqueue_script(
            'wash-connections',
            washPluginUrl() . 'admin/js/connections.js',
            array(),
            WASH_VERSION . '.' . filemtime(WASH_PLUGIN_DIR . 'admin/js/connections.js'),
            true
        );
        wp_localize_script('wash-connections', 'washConnData', array(
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce(WashConnectCode::NONCE),
            'enterCodeMsg'  => __('Paste the connect code from Wash in your Shopify admin.', 'wash-blog-manager'),
            'connectingMsg' => __('Connecting…', 'wash-blog-manager'),
            'failedMsg'     => __('Could not connect. Please try again.', 'wash-blog-manager'),
        ));
    }

    /** Settings/notice URL for a specific additional connection, e.g. after adding it. */
    public static function connectionUrl($id, $flag) {
        return admin_url('options-general.php?page=wash-conn-' . $id . '&' . $flag . '=1');
    }

    /**
     * Register one routable Settings submenu item per ADDITIONAL connection (#252).
     *
     * The agreed #216 nav model: a single store changes nothing (only WashAdmin's
     * "Wash: Manager" shows); once a second store exists, each extra store appears
     * under Settings as its own "Wash: <domain>" item linking straight to that
     * store's sync settings. The slug is `wash-conn-<id>`, which WashContext::activeId()
     * routes on, so the form reads/writes that connection's namespaced options.
     *
     * Registering under the real `options-general.php` parent (not the old hidden
     * `''` parent) is what makes `?page=wash-conn-<id>` actually route — the bug
     * behind the dead "Settings" links.
     */
    public function registerPages() {
        foreach (WashConnection::all() as $id => $conn) {
            if (!empty($conn['isPrimary'])) {
                continue;
            }
            /* translators: %s: Shopify store domain */
            $pageTitle = sprintf(__('Wash: %s', 'wash-blog-manager'), $conn['store']);
            add_submenu_page(
                'options-general.php',
                $pageTitle,
                self::menuLabel($conn['store']),
                'manage_options',
                'wash-conn-' . $id,
                [$this, 'renderConnectionSettings']
            );
        }
    }

    /**
     * Settings-submenu label for an additional connection: "Wash: <domain>", with
     * the domain truncated to ~20 chars (#216) so a long myshopify host can't blow
     * out the menu width.
     *
     * @param string $store Shopify store domain.
     * @return string
     */
    private static function menuLabel($store) {
        $store = (string) $store;
        if ($store === '') {
            $store = __('(no domain)', 'wash-blog-manager');
        } elseif (mb_strlen($store) > 20) {
            $store = mb_substr($store, 0, 19) . '…';
        }
        /* translators: %s: Shopify store domain (truncated to fit the menu) */
        return sprintf(__('Wash: %s', 'wash-blog-manager'), $store);
    }

    /**
     * Whether the merchant's plan permits additional stores. Multi-store is gated on
     * the PRIMARY connection's plan (Business/Enterprise). Fail-open on an API error
     * (consistent with WashApiClient::hasFeature) so a transient outage never blocks
     * a legitimate paying merchant.
     *
     * @return bool
     */
    public static function planAllowsMultiStore() {
        WashContext::setActiveId(WashConnection::PRIMARY);
        $info = WashContext::apiClient()->getPlanInfo();
        WashContext::setActiveId(null);

        if (!is_array($info)) {
            return true;
        }
        $plan = strtoupper((string) (isset($info['plan']) ? $info['plan'] : ''));
        return in_array($plan, array('BUSINESS', 'ENTERPRISE'), true);
    }

    /**
     * Disable every additional connection when the plan no longer qualifies for
     * multi-store (downgrade). The sync fan-out skips disabled connections, so this
     * stops content reaching the extra stores. Re-upgrading re-enables them by hand.
     */
    public static function enforcePlanDowngrade() {
        if (self::planAllowsMultiStore()) {
            return;
        }
        foreach (WashConnection::all() as $id => $conn) {
            if (empty($conn['isPrimary']) && !empty($conn['enabled'])) {
                WashConnection::setEnabled($id, false);
            }
        }
    }

    /** Render a single additional connection's sync settings. */
    public function renderConnectionSettings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $washConnectionId = WashContext::activeId();
        if (WashConnection::isPrimary($washConnectionId) || !WashConnection::exists($washConnectionId)) {
            wp_safe_redirect(self::hubUrl());
            exit;
        }
        // Render the SAME full tabbed plugin UI as the primary, scoped to this
        // store (#252): WashContext is already pinned to this connection, so every
        // wash_* read/write and API call inside the view targets it. One plugin,
        // independent settings per store.
        WashContext::setActiveId($washConnectionId);
        include_once WASH_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /** Remove an additional connection. */
    public function handleRemove() {
        $this->guard('wash_remove_connection');
        $id = isset($_POST['washConnectionId']) ? sanitize_key(wp_unslash($_POST['washConnectionId'])) : '';
        WashConnection::remove($id);
        $this->redirectManager('removed', '1');
    }

    /** Enable/disable an additional connection. */
    public function handleToggle() {
        $this->guard('wash_toggle_connection');
        $id      = isset($_POST['washConnectionId']) ? sanitize_key(wp_unslash($_POST['washConnectionId'])) : '';
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        WashConnection::setEnabled($id, $enabled);
        $this->redirectManager('toggled', $enabled ? '1' : '0');
    }

    /** Shared capability + nonce gate for admin-post handlers. */
    private function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'wash-blog-manager'), '', array('response' => 403));
        }
        check_admin_referer($action);
    }

    /** Redirect back to the multi-store hub (Manager → Connection tab) with a status flag. */
    private function redirectManager($key, $value) {
        wp_safe_redirect(self::hubUrl($key, $value));
        exit;
    }
}
