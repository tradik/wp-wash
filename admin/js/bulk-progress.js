/**
 * Bulk Sync Progress page driver.
 *
 * Loops AJAX batches against `wash_bulk_sync_batch`, updates the progress bar,
 * lists per-item errors, then redirects back to the posts list with a summary.
 *
 * Dynamic config (token, nonce, total, redirect, ajax URL, i18n) is injected by
 * PHP via wp_localize_script() as `WashBulkProgress` — see renderBulkProgressPage().
 */
(function () {
    'use strict';

    var cfg = window.WashBulkProgress || {};
    var TOKEN = cfg.token;
    var NONCE = cfg.nonce;
    var TOTAL = parseInt(cfg.total, 10) || 0;
    var FINAL = cfg.finalRedirect;
    var URL = cfg.ajaxUrl;
    var i18n = cfg.i18n || {};

    var $progress = document.getElementById('wash-bulk-progress');
    var $done = document.getElementById('wash-bulk-done');
    var $status = document.getElementById('wash-bulk-status');
    var $errors = document.getElementById('wash-bulk-errors');
    var $cancel = document.getElementById('wash-bulk-cancel');

    if (!$progress || !$status) {
        return;
    }

    var totalProcessed = 0;
    var totalErrors = 0;
    var cancelled = false;

    if ($cancel) {
        $cancel.addEventListener('click', function () { cancelled = true; });
    }

    async function runBatch(offset) {
        var body = new URLSearchParams();
        body.append('action', 'wash_bulk_sync_batch');
        body.append('token', TOKEN);
        body.append('nonce', NONCE);
        body.append('offset', String(offset));
        var res = await fetch(URL, { method: 'POST', credentials: 'same-origin', body: body });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        var json = await res.json();
        if (!json.success) throw new Error(json.data || 'Unknown error');
        return json.data;
    }

    async function loop() {
        var offset = 0;
        while (offset < TOTAL) {
            if (cancelled) return;
            try {
                var r = await runBatch(offset);
                totalProcessed += (r.processed || 0);
                totalErrors += (r.errors || 0);
                offset = r.nextOffset;
                $progress.value = offset;
                if ($done) $done.textContent = String(offset);
                $status.lastChild.textContent = ' — ' + (r.done ? (i18n.done || 'done') : (i18n.working || 'working…'));
                if (r.errorMessages && r.errorMessages.length && $errors) {
                    r.errorMessages.forEach(function (m) {
                        var li = document.createElement('li');
                        li.textContent = m;
                        $errors.appendChild(li);
                    });
                }
                if (r.done) break;
            } catch (err) {
                if ($errors) {
                    var li = document.createElement('li');
                    li.textContent = String(err);
                    $errors.appendChild(li);
                }
                totalErrors++;
                break;
            }
        }
        var url = FINAL
            .replace('__processed__', String(totalProcessed))
            .replace('__errors__', String(totalErrors));
        setTimeout(function () { window.location.href = url; }, 1500);
    }

    loop();
})();
