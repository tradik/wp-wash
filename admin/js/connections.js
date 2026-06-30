/**
 * Connections manager (#216, #252).
 * Dynamic values are injected via wp_localize_script as window.washConnData.
 *
 * Two behaviours:
 *  1. "New connection" button reveals the (hidden-by-default) connect panel — the
 *     same panel the primary store uses above.
 *  2. "Connect to Shopify" redirects into the Shopify-hosted OAuth add flow.
 */
(function () {
    'use strict';

    var data = window.washConnData || {};

    // 1. Toggle the hidden "new connection" panel.
    var newBtn = document.getElementById('wash-new-connection-btn');
    var newPanel = document.getElementById('wash-new-connection');
    if (newBtn && newPanel) {
        newBtn.addEventListener('click', function () {
            var hidden = newPanel.style.display === 'none' || newPanel.style.display === '';
            newPanel.style.display = hidden ? 'block' : 'none';
            newBtn.setAttribute('aria-expanded', hidden ? 'true' : 'false');
            if (hidden) {
                var storeInput = document.getElementById('wash-oauth-store');
                if (storeInput) {
                    storeInput.focus();
                }
            }
        });
    }

    // 2. OAuth "Connect to Shopify" for the new store.
    var btn = document.getElementById('wash-oauth-connect');
    if (!btn) {
        return;
    }

    // Gate the connect button on the data-processing consent tick, mirroring the
    // primary panel. When consent was already recorded the box is checked+disabled,
    // so the button is enabled from the start; the AJAX save itself is handled by
    // the shared consent handler in settings-page.php.
    var consent = document.getElementById('wash-consent-checkbox');
    function gateConnect() {
        btn.disabled = !!consent && !consent.checked;
    }
    if (consent) {
        gateConnect();
        consent.addEventListener('change', gateConnect);
    }

    btn.addEventListener('click', function () {
        var input = document.getElementById('wash-oauth-store');
        var store = ((input && input.value) || '')
            .trim()
            .replace(/^https?:\/\//, '')
            .replace(/\/.*$/, '')
            .replace(/\.myshopify\.com$/i, '');

        if (!store) {
            window.alert(data.enterStoreMsg || 'Enter the store domain first.');
            return;
        }

        var returnUrl = encodeURIComponent(
            data.managerBase + '?page=' + data.page +
            '&wash_callback=1&wash_add=1&wash_state=' + data.nonce
        );
        var siteUrl = encodeURIComponent(window.location.protocol + '//' + window.location.host);
        var siteName = encodeURIComponent(data.siteName || '');

        window.location.href = 'https://admin.shopify.com/store/' + store +
            '/apps/' + data.appSlug + '/app/connections' +
            '?action=connect&return_url=' + returnUrl +
            '&site_url=' + siteUrl + '&site_name=' + siteName;
    });
})();
