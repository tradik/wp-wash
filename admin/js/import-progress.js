/**
 * Reverse import ("Import from Shopify") page driver.
 *
 * On "Start import" it POSTs `wash_import_preview` to build the article id list
 * (returns a token + total), then loops `wash_import_batch` — updating the
 * progress bar and listing per-item errors — until done.
 *
 * Resilience mirrors bulk-progress.js (#253): a failed batch (usually a gateway
 * timeout on a slow media sideload) is retried a few times then SKIPPED rather
 * than aborting; the server processes each batch under ignore_user_abort().
 *
 * Config (ajaxUrl, nonce, washConnectionId, batchSize, i18n) is injected by PHP
 * via wp_localize_script() as `WashImport`. Every request carries
 * washConnectionId so WashContext targets the selected store (multi-store).
 */
(function () {
    'use strict';

    var cfg = window.WashImport || {};
    var AJAX = cfg.ajaxUrl;
    var NONCE = cfg.nonce;
    var CONN = cfg.washConnectionId || '';
    var BATCH = Math.max(1, parseInt(cfg.batchSize, 10) || 5);
    var i18n = cfg.i18n || {};
    var MAX_RETRIES = 2;

    var $start = document.getElementById('wash-import-start');
    var $blog = document.getElementById('wash-import-blog');
    var $type = document.getElementById('wash-import-type');
    var $progress = document.getElementById('wash-import-progress');
    var $done = document.getElementById('wash-import-done');
    var $total = document.getElementById('wash-import-total');
    var $status = document.getElementById('wash-import-status-text');
    var $errors = document.getElementById('wash-import-errors');

    if (!$start || !$blog || !$progress) {
        return;
    }

    var running = false;

    function checked(id) {
        var el = document.getElementById(id);
        return el && el.checked ? '1' : '';
    }

    function value(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
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

    function setStatus(text) {
        if ($status) {
            $status.textContent = text;
        }
    }

    function paint(offset, total) {
        var shown = Math.min(offset, total);
        $progress.max = total;
        $progress.value = shown;
        if ($done) {
            $done.textContent = String(shown);
        }
        if ($total) {
            $total.textContent = String(total);
        }
    }

    function body(action, extra) {
        var params = new URLSearchParams();
        params.append('action', action);
        params.append('nonce', NONCE);
        if (CONN) {
            params.append('washConnectionId', CONN);
        }
        Object.keys(extra || {}).forEach(function (key) {
            params.append(key, extra[key]);
        });
        return params;
    }

    async function post(action, extra) {
        var res = await fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body(action, extra) });
        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }
        var json = await res.json();
        if (!json.success) {
            throw new Error((json.data && json.data.message) || json.data || 'Unknown error');
        }
        return json.data;
    }

    function importType() {
        return $type ? $type.value : 'article';
    }

    function preview() {
        return post('wash_import_preview', {
            type: importType(),
            blogId: $blog.value,
            status: value('wash-import-status'),
            images: checked('wash-import-images'),
            metafields: checked('wash-import-metafields'),
            blogAsCategory: checked('wash-import-blog-category'),
            translations: checked('wash-import-translations'),
            skipImported: checked('wash-import-skip-imported'),
        });
    }

    // Blog + blog→category apply only to article imports; hide them for Pages.
    function applyTypeVisibility() {
        var articles = importType() === 'article';
        document.querySelectorAll('.wash-import-articles-only').forEach(function (el) {
            el.style.display = articles ? '' : 'none';
        });
    }

    // Run one batch with a few retries. Returns the batch payload, or null when it
    // keeps failing (gateway 504, or a 409 "locked" while another worker finishes the
    // same offset). The caller records the offset for a final retry pass rather than
    // silently skipping it (PLUGIN011) — re-processing is safe (server serializes per
    // token and dedups by article id).
    async function runBatchResilient(token, offset) {
        for (var attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            try {
                return await post('wash_import_batch', { token: token, offset: String(offset) });
            } catch (err) {
                if (attempt >= MAX_RETRIES) {
                    return null;
                }
                await sleep(1000 * (attempt + 1));
            }
        }
        return null;
    }

    async function run() {
        if (running) {
            return;
        }
        if (importType() === 'article' && !$blog.value) {
            setStatus(i18n.noBlog || 'Please choose a blog.');
            return;
        }
        running = true;
        $start.disabled = true;
        if ($errors) {
            $errors.innerHTML = '';
        }
        setStatus(i18n.preparing || 'Preparing…');

        var pv;
        try {
            pv = await preview();
        } catch (err) {
            setStatus((i18n.previewFail || 'Failed to prepare import: ') + err);
            running = false;
            $start.disabled = false;
            return;
        }

        var token = pv.token;
        var total = parseInt(pv.total, 10) || 0;
        paint(0, total);
        if (total === 0) {
            setStatus(i18n.empty || 'No articles found in this blog.');
            running = false;
            $start.disabled = false;
            return;
        }

        var offset = 0;
        var processed = 0;
        var errors = 0;
        var skipped = [];
        while (offset < total) {
            setStatus(i18n.working || 'Importing…');
            var r = await runBatchResilient(token, offset);
            if (r) {
                processed += (r.processed || 0);
                errors += (r.errors || 0);
                if (r.errorMessages && r.errorMessages.length) {
                    r.errorMessages.forEach(pushError);
                }
                offset = (typeof r.nextOffset === 'number' && r.nextOffset > offset) ? r.nextOffset : offset + BATCH;
                paint(offset, total);
                if (r.done) {
                    break;
                }
            } else {
                // Record the failed window for a final retry pass instead of silently
                // dropping it (PLUGIN011), then advance so the run completes.
                skipped.push(offset);
                offset += BATCH;
                paint(offset, total);
            }
        }

        // Final pass: retry the windows that failed. Re-processing is safe — the server
        // serializes per token and dedups by article id, so nothing is duplicated.
        if (skipped.length) {
            setStatus(i18n.retrying || 'Retrying skipped batches…');
            for (var s = 0; s < skipped.length; s++) {
                var rr = await runBatchResilient(token, skipped[s]);
                if (rr) {
                    processed += (rr.processed || 0);
                    errors += (rr.errors || 0);
                    if (rr.errorMessages && rr.errorMessages.length) {
                        rr.errorMessages.forEach(pushError);
                    }
                } else {
                    errors++;
                    pushError((i18n.batchFailed || 'Batch failed (not imported) at offset ') + skipped[s]);
                }
            }
        }

        setStatus((i18n.done || 'Done') + ' — ' + processed + ' ' + (i18n.imported || 'imported') + ', ' + errors + ' ' + (i18n.errorsLabel || 'errors'));
        running = false;
        $start.disabled = false;
    }

    // Populate the source-blog select from the same endpoint the Blogs tab uses
    // (its own nonce), so the whole feature lives in this enqueued file.
    async function loadBlogs() {
        var url = AJAX + '?action=wash_get_shopify_blogs&nonce=' + encodeURIComponent(cfg.blogsNonce || '');
        if (CONN) {
            url += '&washConnectionId=' + encodeURIComponent(CONN);
        }
        try {
            var res = await fetch(url, { credentials: 'same-origin' });
            var json = await res.json();
            if (!json.success) {
                return;
            }
            var blogs = (json.data && json.data.blogs) || [];
            blogs.forEach(function (blog) {
                var opt = document.createElement('option');
                opt.value = blog.id;
                opt.textContent = blog.title + ' (' + blog.handle + ')';
                $blog.appendChild(opt);
            });
        } catch (err) {
            setStatus((i18n.blogsFail || 'Could not load blogs: ') + err);
        }
    }

    $start.addEventListener('click', function (e) {
        e.preventDefault();
        run();
    });

    if ($type) {
        $type.addEventListener('change', applyTypeVisibility);
    }
    applyTypeVisibility();
    loadBlogs();
})();
