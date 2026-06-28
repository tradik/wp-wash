/**
 * Bulk Sync Progress page driver.
 *
 * Loops AJAX batches against `wash_bulk_sync_batch`, updates the progress bar,
 * lists per-item errors, then redirects back to the posts list with a summary.
 *
 * Resilience (#253): a single batch request that fails — almost always a gateway
 * timeout (504) on a slow batch — is RETRIED a few times, then SKIPPED rather than
 * aborting the whole run. The server processes each batch with ignore_user_abort(),
 * so a timed-out batch was still applied server-side; abandoning the remaining
 * hundreds of posts (the old behaviour) was the actual user-visible bug. Re-publish
 * is idempotent, so even a re-processed batch is harmless.
 *
 * Dynamic config (token, nonce, total, batchSize, redirect, ajax URL, i18n) is
 * injected by PHP via wp_localize_script() as `WashBulkProgress` — see
 * renderBulkProgressPage().
 */
(function () {
    'use strict';

    var cfg = window.WashBulkProgress || {};
    var TOKEN = cfg.token;
    var NONCE = cfg.nonce;
    var TOTAL = parseInt(cfg.total, 10) || 0;
    var BATCH = Math.max(1, parseInt(cfg.batchSize, 10) || 10);
    var FINAL = cfg.finalRedirect;
    var URL = cfg.ajaxUrl;
    var i18n = cfg.i18n || {};
    var MAX_RETRIES = 2;

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

    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function pushError(message) {
        if (!$errors) {
            return;
        }
        var li = document.createElement('li');
        li.textContent = message;
        $errors.appendChild(li);
    }

    function paint(offset, working) {
        var shown = Math.min(offset, TOTAL);
        $progress.value = shown;
        if ($done) {
            $done.textContent = String(shown);
        }
        $status.lastChild.textContent = ' — ' + (working ? (i18n.working || 'working…') : (i18n.done || 'done'));
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

    // Run one batch with retries. Returns the batch payload, or null when the
    // request keeps failing (caller then skips ahead instead of aborting).
    async function runBatchResilient(offset) {
        for (var attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            try {
                return await runBatch(offset);
            } catch (err) {
                if (attempt >= MAX_RETRIES) {
                    pushError(String(err) + ' — skipped ' + BATCH + ' (still processing server-side)');
                    totalErrors++;
                    return null;
                }
                await sleep(1000 * (attempt + 1));
            }
        }
        return null;
    }

    async function loop() {
        var offset = 0;
        while (offset < TOTAL && !cancelled) {
            var r = await runBatchResilient(offset);
            if (cancelled) return;

            if (r) {
                totalProcessed += (r.processed || 0);
                totalErrors += (r.errors || 0);
                if (r.errorMessages && r.errorMessages.length) {
                    r.errorMessages.forEach(pushError);
                }
                // Guard: never let a malformed / non-advancing response stall the
                // loop — always move forward by at least one batch.
                var next = (typeof r.nextOffset === 'number' && r.nextOffset > offset)
                    ? r.nextOffset
                    : offset + BATCH;
                offset = next;
                paint(offset, !r.done);
                if (r.done) break;
            } else {
                // Failed batch: advance past it and keep going.
                offset += BATCH;
                paint(offset, offset < TOTAL);
            }
        }

        var url = FINAL
            .replace('__processed__', String(totalProcessed))
            .replace('__errors__', String(totalErrors));
        setTimeout(function () { window.location.href = url; }, 1500);
    }

    loop();
})();
