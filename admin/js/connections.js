/**
 * Connections manager: OAuth "Connect with Shopify" redirect (#216).
 * Dynamic values are injected via wp_localize_script as window.washConnData.
 */
(function () {
    'use strict';

    var data = window.washConnData || {};
    var btn = document.getElementById('wash-oauth-connect');
    if (!btn) {
        return;
    }

    btn.addEventListener('click', function () {
        var input = document.getElementById('wash-oauth-store');
        var store = ((input && input.value) || '')
            .trim()
            .replace(/^https?:\/\//, '')
            .replace(/\/.*$/, '');

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
