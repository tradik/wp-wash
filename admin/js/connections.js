/**
 * Connections manager (#216, #252, #285).
 * Dynamic values are injected via wp_localize_script as window.washConnData.
 *
 * Two behaviours:
 *  1. "New connection" button reveals the (hidden-by-default) connect panel.
 *  2. Every .wash-connect-form (primary first connect, or add another store)
 *     redeems the pasted connect code over AJAX and follows the returned
 *     redirect. The store is never typed in WordPress; the code identifies it.
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
                var codeInput = newPanel.querySelector('.wash-connect-code-input');
                if (codeInput) {
                    codeInput.focus();
                }
            }
        });
    }

    // 2. Connect-code forms.
    var consent = document.getElementById('wash-consent-checkbox');

    function fieldValue(form, selector) {
        var el = form.querySelector(selector);
        return el ? el.value.trim() : '';
    }

    function bindForm(form) {
        var btn = form.querySelector('.wash-connect-code-btn');
        var codeInput = form.querySelector('.wash-connect-code-input');
        var errorBox = form.querySelector('.wash-connect-error');
        if (!btn || !codeInput) {
            return;
        }
        var label = btn.innerHTML;

        // Gate on the data-processing consent tick. When consent was already
        // recorded the box is checked+disabled, so the button starts enabled; the
        // consent AJAX save itself is handled in settings-page.php.
        function gate() {
            btn.disabled = !!consent && !consent.checked;
        }
        if (consent) {
            gate();
            consent.addEventListener('change', gate);
        }

        function showError(message) {
            errorBox.textContent = message;
            errorBox.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = label;
        }

        function connect() {
            var code = codeInput.value.trim();
            if (!code) {
                showError(data.enterCodeMsg || 'Paste the connect code first.');
                codeInput.focus();
                return;
            }
            errorBox.style.display = 'none';
            btn.disabled = true;
            btn.textContent = data.connectingMsg || 'Connecting…';

            var body = new FormData();
            body.append('action', 'wash_redeem_connect_code');
            body.append('nonce', data.nonce);
            body.append('mode', form.getAttribute('data-mode'));
            body.append('code', code);
            body.append('appUrl', fieldValue(form, '.wash-connect-appurl'));
            body.append('httpUser', fieldValue(form, '.wash-connect-httpuser'));
            body.append('httpPass', fieldValue(form, '.wash-connect-httppass'));

            fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json && json.success && json.data && json.data.redirect) {
                        window.location.href = json.data.redirect;
                        return;
                    }
                    showError((json && json.data && json.data.message) || data.failedMsg);
                })
                .catch(function () {
                    showError(data.failedMsg || 'Could not reach the server. Please try again.');
                });
        }

        btn.addEventListener('click', connect);
        // Enter in the code field must connect, not submit the surrounding
        // settings form the primary connect panel is nested in.
        codeInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (!btn.disabled) {
                    connect();
                }
            }
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.wash-connect-form'), bindForm);
})();
