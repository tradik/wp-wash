<?php
/**
 * Admin UI for managing multiple Shopify store connections (#216).
 *
 * Registers the "Connections" manager page and a hidden, URL-routable settings
 * page per additional connection, and handles adding (manual + OAuth), removing,
 * enabling/disabling, and saving per-connection sync settings. Kept separate from
 * WashAdmin (SRP) — WashAdmin owns the primary single-store settings page.
 */
if (!defined('ABSPATH')) {
    exit;
}

class WashConnectionsAdmin {

    /** Manager page slug. */
    const PAGE = 'wash-connections';

    /**
     * Register the admin hooks. The primary settings page (WashAdmin) is untouched,
     * so single-store installs see no change until a second connection is added.
     */
    public function init() {
        add_action('admin_menu', [$this, 'registerPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handleOAuthAddCallback']);
        add_action('admin_post_wash_add_connection', [$this, 'handleManualAdd']);
        add_action('admin_post_wash_remove_connection', [$this, 'handleRemove']);
        add_action('admin_post_wash_toggle_connection', [$this, 'handleToggle']);
        add_action('admin_post_wash_save_connection_settings', [$this, 'handleSaveConnectionSettings']);
    }

    /**
     * Enqueue the OAuth-connect script on the manager page and localise the dynamic
     * values (nonce, app slug, site name) — keeps the view free of inline <script>.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueueAssets($hook) {
        if ($hook !== 'settings_page_' . self::PAGE) {
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
            'managerBase'   => admin_url('options-general.php'),
            'page'          => self::PAGE,
            'nonce'         => wp_create_nonce('wash_connect_add'),
            'appSlug'       => (new WashApiClient())->getShopifyAppSlug(),
            'siteName'      => get_bloginfo('name'),
            'enterStoreMsg' => __('Enter the store domain first.', 'wash-blog-manager'),
        ));
    }

    /** Settings/notice URL for a specific connection (DRY for the redirect handlers). */
    private function connectionUrl($id, $flag) {
        return admin_url('options-general.php?page=wash-conn-' . $id . '&' . $flag . '=1');
    }

    /**
     * Per-connection sync settings — the single source of truth shared by the
     * settings view and the save handler (DRY). Credentials (store/api_key/http)
     * are part of the connection identity and are not edited here; site-wide
     * options (block_robots, uninstall_remove_data, consent) stay global.
     *
     * @return array<string,string> option key => type ('text'|'checkbox'|'csv')
     */
    public static function settingsSchema() {
        return array(
            'wash_post_types'             => 'csv',
            'wash_sync_on_publish'        => 'checkbox',
            'wash_sync_metafields'        => 'checkbox',
            'wash_hreflangs_enabled'      => 'checkbox',
            'wash_authors_enabled'        => 'checkbox',
            'wash_files_enabled'          => 'checkbox',
            'wash_rewrite_internal_urls'  => 'checkbox',
            'wash_blocks_enabled'         => 'checkbox',
            'wash_sync_blocks_meta'       => 'checkbox',
            'wash_sync_blocks_css'        => 'checkbox',
            'wash_transform_rankmath_faq' => 'checkbox',
            'wash_redirections_enabled'   => 'checkbox',
            'wash_sync_inline'            => 'checkbox',
        );
    }

    /**
     * Register the manager page plus a hidden settings page per additional
     * connection. The hidden page uses the `wash-conn-<id>` slug that
     * WashContext::activeId() routes on, so its form reads/writes that
     * connection's namespaced options.
     */
    public function registerPages() {
        add_options_page(
            __('Wash Connections', 'wash-blog-manager'),
            __('Wash: Connections', 'wash-blog-manager'),
            'manage_options',
            self::PAGE,
            [$this, 'renderManager']
        );

        foreach (WashConnection::all() as $id => $conn) {
            if (!empty($conn['isPrimary'])) {
                continue;
            }
            /* translators: %s: Shopify store domain */
            $pageTitle = sprintf(__('Wash: %s', 'wash-blog-manager'), $conn['store']);
            add_submenu_page(
                '', // hidden: registered for URL routing only, not shown in any menu
                $pageTitle,
                '',
                'manage_options',
                'wash-conn-' . $id,
                [$this, 'renderConnectionSettings']
            );
        }
    }

    /** Render the connections manager (list + add forms). */
    public function renderManager() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include_once WASH_PLUGIN_DIR . 'admin/views/connections-page.php';
    }

    /** Render a single additional connection's sync settings. */
    public function renderConnectionSettings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $washConnectionId = WashContext::activeId();
        if (WashConnection::isPrimary($washConnectionId) || !WashConnection::exists($washConnectionId)) {
            wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
            exit;
        }
        // The view recomputes the id + connection from WashContext (kept off this
        // scope to avoid an "unused local" smell — include() shares scope anyway).
        include_once WASH_PLUGIN_DIR . 'admin/views/connection-settings-page.php';
    }

    /** Manual "paste credentials" add. */
    public function handleManualAdd() {
        $this->guard('wash_add_connection');

        $store  = isset($_POST['store']) ? sanitize_text_field(wp_unslash($_POST['store'])) : '';
        $apiKey = isset($_POST['apiKey']) ? sanitize_text_field(wp_unslash($_POST['apiKey'])) : '';
        if ($store === '' || $apiKey === '') {
            $this->redirectManager('error', 'missing');
        }

        $appUrl   = isset($_POST['appUrl']) ? esc_url_raw(wp_unslash($_POST['appUrl'])) : '';
        $httpUser = isset($_POST['httpUser']) ? sanitize_text_field(wp_unslash($_POST['httpUser'])) : '';
        $httpPass = isset($_POST['httpPass']) ? sanitize_text_field(wp_unslash($_POST['httpPass'])) : '';

        $id = WashConnection::add($store, $apiKey, $appUrl, $httpUser, $httpPass);
        wp_safe_redirect($this->connectionUrl($id, 'added'));
        exit;
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

    /** Save an additional connection's sync settings through WashContext. */
    public function handleSaveConnectionSettings() {
        $this->guard('wash_save_connection_settings');
        $id = isset($_POST['washConnectionId']) ? sanitize_key(wp_unslash($_POST['washConnectionId'])) : '';
        if (!WashConnection::exists($id) || WashConnection::isPrimary($id)) {
            $this->redirectManager('error', 'badconn');
        }
        WashContext::setActiveId($id);

        foreach (self::settingsSchema() as $key => $type) {
            if ($type === 'checkbox') {
                WashContext::updateOption($key, (isset($_POST[$key]) && $_POST[$key] === '1') ? '1' : '0');
                continue;
            }
            $raw = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
            WashContext::updateOption($key, $raw);
        }

        wp_safe_redirect($this->connectionUrl($id, 'saved'));
        exit;
    }

    /**
     * Complete an OAuth add: the Shopify app redirects back to the manager page
     * with the new store's api_key + store, carrying our wash_add marker and the
     * one-time nonce. Mirrors WashAdmin::handleCallback() but ADDS a connection
     * instead of overwriting the primary credentials.
     */
    public function handleOAuthAddCallback() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing guard only; the wash_connect_add nonce is verified below before any write.
        if (!isset($_GET['page'], $_GET['wash_callback'], $_GET['wash_add']) || $_GET['page'] !== self::PAGE) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = isset($_GET['wash_state']) ? sanitize_text_field(wp_unslash($_GET['wash_state'])) : '';
        if (!wp_verify_nonce($state, 'wash_connect_add')) {
            $this->redirectManager('error', 'state');
        }
        $apiKey = isset($_GET['api_key']) ? sanitize_text_field(wp_unslash($_GET['api_key'])) : '';
        $store  = isset($_GET['store']) ? sanitize_text_field(wp_unslash($_GET['store'])) : '';
        if ($apiKey === '' || $store === '') {
            $this->redirectManager('error', 'missing');
        }

        $id = WashConnection::add($store, $apiKey);
        wp_safe_redirect($this->connectionUrl($id, 'added'));
        exit;
    }

    /** Shared capability + nonce gate for admin-post handlers. */
    private function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'wash-blog-manager'), '', array('response' => 403));
        }
        check_admin_referer($action);
    }

    /** Redirect back to the manager with a status flag. */
    private function redirectManager($key, $value) {
        wp_safe_redirect(add_query_arg($key, $value, admin_url('options-general.php?page=' . self::PAGE)));
        exit;
    }
}
