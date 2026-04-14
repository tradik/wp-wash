/**
 * Wash Search & Replace tab — manages rules CRUD via AJAX.
 * Features: rule types (string/url/shortcode/block), drag-and-drop reorder, overlap detection.
 */
(function ($) {
    "use strict";

    const i18n = (key, fallback) => (washAdmin.i18n && washAdmin.i18n[key]) || fallback;

    const RULE_TYPES = [
        { value: "string", label: "Text", icon: "Aa", color: "#6b7280", placeholder: "old text", replacePlaceholder: "new text" },
        { value: "url", label: "URL", icon: "🔗", color: "#2563eb", placeholder: "https://old-domain.com", replacePlaceholder: "https://new-domain.com" },
        { value: "shortcode", label: "Shortcode", icon: "[ ]", color: "#7c3aed", placeholder: "[newsletter id=\"1\"]", replacePlaceholder: '<div class="form">%content%</div>' },
        { value: "block", label: "Block", icon: "▦", color: "#ea580c", placeholder: "wp:custom-block", replacePlaceholder: '<div class="block">%content%</div>' },
    ];

    // Suggestion data (loaded once, cached by backend for 10 min)
    var wpSuggestions = { urls: [], domains: [], shortcodes: [] };
    var shopifySuggestions = { links: [] };

    $(document).ready(function () {
        const tbody = $("#wash-sr-tbody");
        const addBtn = $("#wash-sr-add-rule");
        const saveBtn = $("#wash-sr-save-rules");
        const statusEl = $("#wash-sr-save-status");

        if (!tbody.length) return;

        // Build "Add" dropdown
        buildAddButtons();

        loadRules();
        loadSuggestions();
        saveBtn.on("click", saveRules);

        // Enable drag-and-drop reordering
        tbody.sortable({
            handle: ".wash-sr-drag",
            items: ".wash-sr-row",
            axis: "y",
            cursor: "grabbing",
            placeholder: "wash-sr-placeholder",
            tolerance: "pointer",
            update: function () {
                renumberRows();
                detectOverlaps();
                markDirty();
            },
        });

        function buildAddButtons() {
            var container = addBtn.parent();
            addBtn.remove();

            var wrap = $('<div class="wash-sr-add-wrap" style="display:inline-flex;gap:6px;"></div>');
            RULE_TYPES.forEach(function (t) {
                var btn = $('<button type="button" class="button button-secondary wash-sr-add-type" data-type="' + t.value + '" title="' + escAttr(t.label) + '">' +
                    '<span style="margin-right:4px;">' + t.icon + '</span>' +
                    escHtml(t.label) +
                    '</button>');
                btn.on("click", function () {
                    appendRuleRow("", "", t.value);
                    removeEmptyMessage();
                    renumberRows();
                    detectOverlaps();
                    markDirty();
                });
                wrap.append(btn);
            });

            container.prepend(wrap);
        }

        function loadRules() {
            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "GET",
                data: {
                    action: "wash_get_search_replace_rules",
                    nonce: washAdmin.nonce,
                },
                success: function (response) {
                    $("#wash-sr-loading").remove();
                    if (response.success && response.data.rules.length > 0) {
                        response.data.rules.forEach(function (rule) {
                            appendRuleRow(rule.search, rule.replace, rule.type || "string");
                        });
                    } else {
                        showEmptyMessage();
                    }
                    renumberRows();
                    detectOverlaps();
                },
                error: function () {
                    $("#wash-sr-loading").remove();
                    showEmptyMessage();
                },
            });
        }

        function loadSuggestions() {
            // Load WordPress content links
            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "GET",
                data: { action: "wash_scan_content_links", nonce: washAdmin.nonce },
                success: function (res) {
                    if (res.success) {
                        wpSuggestions = res.data;
                        renderWpRefList(res.data);
                    }
                },
            });

            // Load Shopify links
            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "GET",
                data: { action: "wash_get_shopify_links", nonce: washAdmin.nonce },
                success: function (res) {
                    if (res.success) {
                        shopifySuggestions = res.data;
                        renderShopifyRefList(res.data);
                    }
                },
            });
        }

        function getSearchSuggestions(type) {
            switch (type) {
                case "url":
                    return (wpSuggestions.domains || []).concat(wpSuggestions.urls || []);
                case "shortcode": {
                    var sc = wpSuggestions.shortcodes || [];
                    // Include both full shortcodes and bare tag names for easy matching
                    var tags = {};
                    var items = [];
                    sc.forEach(function (s) {
                        items.push({ value: s.full, label: s.full + " (" + s.count + "x)" });
                        if (!tags[s.tag]) {
                            tags[s.tag] = true;
                            items.push({ value: "[" + s.tag + "]", label: "[" + s.tag + "] — tag name" });
                        }
                    });
                    return items;
                }
                case "block":
                    return (wpSuggestions.blocks || []).map(function (b) {
                        var label = b.name + (b.count > 0 ? " (" + b.count + "x in content)" : " (registered)");
                        return { value: b.name, label: label };
                    });
                default:
                    return (wpSuggestions.urls || []).concat(
                        (wpSuggestions.shortcodes || []).map(function (s) { return s.full; }),
                        (wpSuggestions.blocks || []).map(function (b) { return b.name; })
                    );
            }
        }

        function getReplaceSuggestions(type) {
            if (type === "url") {
                return (shopifySuggestions.links || []).map(function (l) {
                    return { value: l.url, label: l.label + " (" + l.type + ")" };
                });
            }
            return (shopifySuggestions.links || []).map(function (l) {
                return { value: l.url, label: l.label + " (" + l.type + ")" };
            });
        }

        function attachAutocomplete(textarea, getSuggestionsFn) {
            var wrap = textarea.parent();
            if (wrap.find(".wash-sr-suggest").length) return;

            var dropdown = $('<div class="wash-sr-suggest" style="display:none;position:absolute;z-index:100;background:#fff;border:1px solid #ccc;border-radius:4px;max-height:180px;overflow-y:auto;width:100%;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>');
            wrap.css("position", "relative");
            wrap.append(dropdown);

            textarea.on("input focus", function () {
                var val = textarea.val().toLowerCase();
                if (val.length < 2) {
                    dropdown.hide();
                    return;
                }

                // Get suggestions dynamically (data may have loaded after row was created)
                var suggestions = getSuggestionsFn();
                var items = [];
                suggestions.forEach(function (s) {
                    var text = typeof s === "string" ? s : s.value;
                    var label = typeof s === "string" ? s : s.label;
                    if (text.toLowerCase().includes(val)) {
                        items.push({ text: text, label: label });
                    }
                });

                if (items.length === 0) {
                    dropdown.hide();
                    return;
                }

                dropdown.html("");
                items.slice(0, 15).forEach(function (item) {
                    var opt = $('<div class="wash-sr-suggest-item" style="padding:6px 10px;cursor:pointer;font-size:12px;font-family:monospace;border-bottom:1px solid #f0f0f0;"></div>');
                    opt.text(item.label);
                    opt.on("mousedown", function (e) {
                        e.preventDefault();
                        textarea.val(item.text);
                        dropdown.hide();
                        textarea.trigger("input");
                    });
                    opt.on("mouseenter", function () { $(this).css("background", "#f0f6fc"); });
                    opt.on("mouseleave", function () { $(this).css("background", "#fff"); });
                    dropdown.append(opt);
                });
                dropdown.show();
            });

            textarea.on("blur", function () {
                setTimeout(function () { dropdown.hide(); }, 200);
            });
        }

        function renderWpRefList(data) {
            var el = $("#wash-sr-wp-list");
            var total = (data.urls || []).length + (data.shortcodes || []).length + (data.blocks || []).length + (data.domains || []).length;
            $("#wash-sr-wp-count").text("(" + total + ")");

            var html = "";

            if ((data.domains || []).length > 0) {
                html += '<div style="margin-bottom:8px;"><strong style="color:#2563eb;">Domains (' + data.domains.length + ')</strong></div>';
                data.domains.forEach(function (d) {
                    html += '<div style="padding:2px 0;"><code style="font-size:11px;background:#e5e7eb;padding:1px 5px;border-radius:3px;cursor:pointer;" class="wash-sr-ref-copy">' + escHtml(d) + '</code></div>';
                });
            }

            if ((data.urls || []).length > 0) {
                html += '<div style="margin:8px 0;"><strong style="color:#2563eb;">URLs (' + data.urls.length + ')</strong></div>';
                data.urls.slice(0, 50).forEach(function (u) {
                    html += '<div style="padding:2px 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><code style="font-size:11px;background:#e5e7eb;padding:1px 5px;border-radius:3px;cursor:pointer;" class="wash-sr-ref-copy" title="' + escAttr(u) + '">' + escHtml(u.length > 60 ? u.substring(0, 57) + '...' : u) + '</code></div>';
                });
                if (data.urls.length > 50) {
                    html += '<div style="color:#999;font-size:11px;">... +' + (data.urls.length - 50) + ' more</div>';
                }
            }

            if ((data.shortcodes || []).length > 0) {
                html += '<div style="margin:8px 0;"><strong style="color:#7c3aed;">Shortcodes (' + data.shortcodes.length + ')</strong></div>';
                data.shortcodes.forEach(function (s) {
                    html += '<div style="padding:2px 0;"><code style="font-size:11px;background:#f3e8ff;padding:1px 5px;border-radius:3px;cursor:pointer;" class="wash-sr-ref-copy">' + escHtml(s.full) + '</code> <span style="color:#999;font-size:10px;">' + s.count + 'x</span></div>';
                });
            }

            if ((data.blocks || []).length > 0) {
                var used = data.blocks.filter(function (b) { return b.count > 0; });
                html += '<div style="margin:8px 0;"><strong style="color:#ea580c;">Blocks (' + data.blocks.length + ', ' + used.length + ' in use)</strong></div>';
                data.blocks.forEach(function (b) {
                    var style = b.count > 0 ? 'background:#fed7aa;' : 'background:#f3f4f6;color:#999;';
                    html += '<div style="padding:2px 0;"><code style="font-size:11px;' + style + 'padding:1px 5px;border-radius:3px;cursor:pointer;" class="wash-sr-ref-copy">' + escHtml(b.name) + '</code>';
                    if (b.count > 0) html += ' <span style="color:#ea580c;font-size:10px;">' + b.count + 'x</span>';
                    html += '</div>';
                });
            }

            if (!html) html = '<span style="color:#999;">No content found</span>';

            el.html(html);

            // Click to copy
            el.find(".wash-sr-ref-copy").on("click", function () {
                navigator.clipboard.writeText($(this).text());
                var orig = $(this).css("background");
                $(this).css("background", "#bbf7d0");
                var self = $(this);
                setTimeout(function () { self.css("background", orig); }, 500);
            });
        }

        function renderShopifyRefList(data) {
            var links = data.links || [];
            $("#wash-sr-shopify-count").text("(" + links.length + ")");
            var el = $("#wash-sr-shopify-list");

            if (links.length === 0) {
                el.html('<span style="color:#999;">No Shopify links available</span>');
                return;
            }

            // Group by type
            var groups = {};
            links.forEach(function (l) {
                var t = l.type || "other";
                if (!groups[t]) groups[t] = [];
                groups[t].push(l);
            });

            var typeColors = { product: "#16a34a", collection: "#2563eb", blog: "#ea580c", article: "#7c3aed", page: "#6b7280" };
            var html = "";

            Object.keys(groups).sort().forEach(function (type) {
                var items = groups[type];
                var color = typeColors[type] || "#999";
                html += '<div style="margin-bottom:8px;"><strong style="color:' + color + ';">' + type.charAt(0).toUpperCase() + type.slice(1) + 's (' + items.length + ')</strong></div>';
                items.forEach(function (l) {
                    html += '<div style="padding:2px 0;"><code style="font-size:11px;background:#e5e7eb;padding:1px 5px;border-radius:3px;cursor:pointer;" class="wash-sr-ref-copy">' + escHtml(l.url) + '</code> <span style="color:#666;font-size:10px;">' + escHtml(l.label) + '</span></div>';
                });
            });

            el.html(html);

            el.find(".wash-sr-ref-copy").on("click", function () {
                navigator.clipboard.writeText($(this).text());
                var orig = $(this).css("background");
                $(this).css("background", "#bbf7d0");
                var self = $(this);
                setTimeout(function () { self.css("background", orig); }, 500);
            });
        }

        function getTypeConfig(type) {
            return RULE_TYPES.find(function (t) { return t.value === type; }) || RULE_TYPES[0];
        }

        function appendRuleRow(search, replace, type) {
            var cfg = getTypeConfig(type || "string");

            var typeBadge = '<span class="wash-sr-type-badge" style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:' + cfg.color + ';" title="' + escAttr(cfg.label) + '">' + cfg.icon + ' ' + escHtml(cfg.label) + '</span>';

            var searchPlaceholder = cfg.placeholder;
            var replacePlaceholder = cfg.replacePlaceholder;

            // For shortcode/block, add %content% hint
            var contentHint = "";
            if (type === "shortcode" || type === "block") {
                contentHint = '<div class="wash-sr-hint" style="margin-top:4px;font-size:11px;color:#7c3aed;">%content% = ' + i18n("srContentVar", "inner content (HTML stripped)") + '</div>';
            }

            var row = $(
                '<tr class="wash-sr-row" data-type="' + escAttr(cfg.value) + '">' +
                    '<td class="wash-sr-drag" title="' + escAttr(i18n("srDrag", "Drag to reorder")) + '" style="cursor:grab;text-align:center;color:#999;font-size:16px;user-select:none;">☰</td>' +
                    '<td class="wash-sr-num"></td>' +
                    '<td>' + typeBadge + '</td>' +
                    '<td><textarea class="wash-sr-search" rows="2" placeholder="' + escAttr(searchPlaceholder) + '">' + escHtml(search) + "</textarea></td>" +
                    '<td style="text-align:center;color:#999;font-size:18px;">→</td>' +
                    '<td><textarea class="wash-sr-replace" rows="2" placeholder="' + escAttr(replacePlaceholder) + '">' + escHtml(replace) + "</textarea>" + contentHint + "</td>" +
                    '<td style="text-align:center;">' +
                    '<button type="button" class="button wash-sr-remove" title="' + escAttr(i18n("delete", "Delete")) + '">' +
                    '<span class="dashicons dashicons-trash" style="vertical-align:text-bottom;color:#d63638;"></span>' +
                    "</button></td>" +
                    "</tr>"
            );

            row.find(".wash-sr-remove").on("click", function () {
                if (confirm(i18n("srRemoveConfirm", "Remove this rule?"))) {
                    row.fadeOut(200, function () {
                        row.remove();
                        renumberRows();
                        detectOverlaps();
                        markDirty();
                        if (tbody.find(".wash-sr-row").length === 0) {
                            showEmptyMessage();
                        }
                    });
                }
            });

            row.find(".wash-sr-search, .wash-sr-replace").on("input", function () {
                detectOverlaps();
                markDirty();
            });

            tbody.append(row);

            // Attach autocomplete suggestions (lazy — fetches current data on each keystroke)
            var ruleType = cfg.value;
            attachAutocomplete(row.find(".wash-sr-search"), function () { return getSearchSuggestions(ruleType); });
            attachAutocomplete(row.find(".wash-sr-replace"), function () { return getReplaceSuggestions(ruleType); });
        }

        function renumberRows() {
            tbody.find(".wash-sr-row").each(function (i) {
                $(this).find(".wash-sr-num").text(i + 1);
            });
        }

        // ── Overlap Detection ──

        function detectOverlaps() {
            var rows = tbody.find(".wash-sr-row");
            var searches = [];

            rows.each(function () {
                var el = $(this).find(".wash-sr-search");
                var val = el.val().trim();
                searches.push({ el: el, row: $(this), val: val });
            });

            rows.find(".wash-sr-search").css({ borderColor: "", backgroundColor: "" });
            rows.find(".wash-sr-overlap-badge").remove();

            for (var i = 0; i < searches.length; i++) {
                if (!searches[i].val) continue;
                for (var j = i + 1; j < searches.length; j++) {
                    if (!searches[j].val) continue;

                    var a = searches[i].val;
                    var b = searches[j].val;
                    var overlap = false;
                    var reason = "";

                    if (a === b) {
                        overlap = true;
                        reason = i18n("srOverlapDuplicate", "Duplicate rule");
                    } else if (a.includes(b)) {
                        overlap = true;
                        reason = "#" + (i + 1) + " contains #" + (j + 1);
                    } else if (b.includes(a)) {
                        overlap = true;
                        reason = "#" + (j + 1) + " contains #" + (i + 1);
                    }

                    if (overlap) {
                        markOverlap(searches[i], reason);
                        markOverlap(searches[j], reason);
                    }
                }
            }
        }

        function markOverlap(item, reason) {
            item.el.css({ borderColor: "#d63638", backgroundColor: "#fcf0f0" });
            if (!item.row.find(".wash-sr-overlap-badge").length) {
                item.el.after(
                    '<span class="wash-sr-overlap-badge" style="display:inline-block;margin-top:4px;padding:2px 8px;background:#d63638;color:#fff;font-size:11px;border-radius:3px;" title="' + escAttr(reason) + '">⚠ ' + escHtml(reason) + "</span>"
                );
            }
        }

        // ── Empty state, save, helpers ──

        function showEmptyMessage() {
            if (tbody.find("#wash-sr-empty").length) return;
            tbody.append(
                '<tr id="wash-sr-empty"><td colspan="7" style="text-align:center;padding:20px;color:#666;">' +
                    i18n("srNoRules", 'No rules defined yet.') +
                    "</td></tr>"
            );
        }

        function removeEmptyMessage() {
            $("#wash-sr-empty").remove();
        }

        function markDirty() {
            if (window.washMarkDirty) washMarkDirty("searchreplace");
        }

        function saveRules() {
            var rules = [];
            tbody.find(".wash-sr-row").each(function () {
                var search = $(this).find(".wash-sr-search").val();
                var replace = $(this).find(".wash-sr-replace").val();
                var type = $(this).attr("data-type") || "string";
                if (search !== "") {
                    rules.push({ search: search, replace: replace, type: type });
                }
            });

            saveBtn.prop("disabled", true);
            statusEl.html('<span style="color:#666;">' + i18n("srSaving", "Saving...") + "</span>");

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_save_search_replace_rules",
                    nonce: washAdmin.nonce,
                    rules: rules,
                },
                success: function (response) {
                    if (response.success) {
                        if (window.washClearDirty) washClearDirty("searchreplace");
                        statusEl.html('<span style="color:#00a32a;">✓ ' + i18n("srSaved", "Rules saved.") + "</span>");
                        setTimeout(function () {
                            statusEl.fadeOut(300, function () { statusEl.html("").show(); });
                        }, 2000);
                    } else {
                        statusEl.html('<span style="color:#d63638;">✗ ' + i18n("srSaveFailed", "Failed: ") + (response.data || "") + "</span>");
                    }
                },
                error: function () {
                    statusEl.html('<span style="color:#d63638;">✗ ' + i18n("networkError", "Network error") + "</span>");
                },
                complete: function () {
                    saveBtn.prop("disabled", false);
                },
            });
        }

        function escHtml(str) {
            var div = document.createElement("div");
            div.appendChild(document.createTextNode(str || ""));
            return div.innerHTML;
        }

        function escAttr(str) {
            return escHtml(str).replace(/"/g, "&quot;");
        }
    });
})(jQuery);
