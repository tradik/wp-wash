/**
 * Wash Search & Replace tab — manages rules CRUD via AJAX.
 * Features: drag-and-drop reorder, overlap rule detection.
 */
(function ($) {
    "use strict";

    const i18n = (key, fallback) => (washAdmin.i18n && washAdmin.i18n[key]) || fallback;

    $(document).ready(function () {
        const tbody = $("#wash-sr-tbody");
        const addBtn = $("#wash-sr-add-rule");
        const saveBtn = $("#wash-sr-save-rules");
        const statusEl = $("#wash-sr-save-status");

        if (!tbody.length) return;

        loadRules();

        addBtn.on("click", function () {
            appendRuleRow("", "");
            removeEmptyMessage();
            renumberRows();
            detectOverlaps();
        });

        saveBtn.on("click", saveRules);

        // Enable drag-and-drop reordering via jQuery UI sortable
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
            },
        });

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
                            appendRuleRow(rule.search, rule.replace);
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

        function appendRuleRow(search, replace) {
            const row = $(
                '<tr class="wash-sr-row">' +
                    '<td class="wash-sr-drag" title="' + escAttr(i18n("srDrag", "Drag to reorder")) + '" style="cursor: grab; text-align: center; color: #999; font-size: 16px; user-select: none;">☰</td>' +
                    '<td class="wash-sr-num"></td>' +
                    '<td><textarea class="wash-sr-search" rows="2" placeholder="' + escAttr("[[shortcode]]") + '">' + escHtml(search) + "</textarea></td>" +
                    '<td style="text-align: center; color: #999; font-size: 18px;">→</td>' +
                    '<td><textarea class="wash-sr-replace" rows="2" placeholder="' + escAttr("<div>...</div>") + '">' + escHtml(replace) + "</textarea></td>" +
                    '<td style="text-align: center;">' +
                    '<button type="button" class="button wash-sr-remove" title="' + escAttr(i18n("delete", "Delete")) + '">' +
                    '<span class="dashicons dashicons-trash" style="vertical-align: text-bottom; color: #d63638;"></span>' +
                    "</button></td>" +
                    "</tr>"
            );

            row.find(".wash-sr-remove").on("click", function () {
                if (confirm(i18n("srRemoveConfirm", "Remove this rule?"))) {
                    row.fadeOut(200, function () {
                        row.remove();
                        renumberRows();
                        detectOverlaps();
                        if (tbody.find(".wash-sr-row").length === 0) {
                            showEmptyMessage();
                        }
                    });
                }
            });

            // Detect overlaps when search text changes
            row.find(".wash-sr-search").on("input", function () {
                detectOverlaps();
            });

            tbody.append(row);
        }

        function renumberRows() {
            tbody.find(".wash-sr-row").each(function (i) {
                $(this).find(".wash-sr-num").text(i + 1);
            });
        }

        // ────────────────────────────────────────────
        // Overlap Rule Detection
        // ────────────────────────────────────────────

        function detectOverlaps() {
            const rows = tbody.find(".wash-sr-row");
            const searches = [];

            // Collect all search values with their row references
            rows.each(function () {
                const el = $(this).find(".wash-sr-search");
                const val = el.val().trim();
                searches.push({ el: el, row: $(this), val: val });
            });

            // Clear all overlap markers
            rows.find(".wash-sr-search").css({
                borderColor: "",
                backgroundColor: "",
            });
            rows.find(".wash-sr-overlap-badge").remove();

            // Check each pair for overlaps
            for (let i = 0; i < searches.length; i++) {
                if (!searches[i].val) continue;

                for (let j = i + 1; j < searches.length; j++) {
                    if (!searches[j].val) continue;

                    const a = searches[i].val;
                    const b = searches[j].val;

                    let overlap = false;
                    let reason = "";

                    // Exact duplicate
                    if (a === b) {
                        overlap = true;
                        reason = i18n("srOverlapDuplicate", "Duplicate rule");
                    }
                    // One contains the other (partial overlap)
                    else if (a.includes(b)) {
                        overlap = true;
                        reason = i18n("srOverlapContains", "Rule #" + (i + 1) + " contains #" + (j + 1));
                    } else if (b.includes(a)) {
                        overlap = true;
                        reason = i18n("srOverlapContains", "Rule #" + (j + 1) + " contains #" + (i + 1));
                    }

                    if (overlap) {
                        markOverlap(searches[i], reason);
                        markOverlap(searches[j], reason);
                    }
                }
            }
        }

        function markOverlap(item, reason) {
            item.el.css({
                borderColor: "#d63638",
                backgroundColor: "#fcf0f0",
            });

            // Add badge if not already present
            if (!item.row.find(".wash-sr-overlap-badge").length) {
                item.el.after(
                    '<span class="wash-sr-overlap-badge" style="display: inline-block; margin-top: 4px; padding: 2px 8px; background: #d63638; color: #fff; font-size: 11px; border-radius: 3px;" title="' +
                        escAttr(reason) +
                        '">⚠ ' +
                        escHtml(reason) +
                        "</span>"
                );
            }
        }

        // ────────────────────────────────────────────
        // Empty state, save, helpers
        // ────────────────────────────────────────────

        function showEmptyMessage() {
            if (tbody.find("#wash-sr-empty").length) return;
            tbody.append(
                '<tr id="wash-sr-empty"><td colspan="6" style="text-align: center; padding: 20px; color: #666;">' +
                    i18n("srNoRules", 'No rules defined yet. Click "Add Rule" to create one.') +
                    "</td></tr>"
            );
        }

        function removeEmptyMessage() {
            $("#wash-sr-empty").remove();
        }

        function saveRules() {
            const rules = [];
            tbody.find(".wash-sr-row").each(function () {
                const search = $(this).find(".wash-sr-search").val();
                const replace = $(this).find(".wash-sr-replace").val();
                if (search !== "") {
                    rules.push({ search: search, replace: replace });
                }
            });

            saveBtn.prop("disabled", true);
            statusEl.html('<span style="color: #666;">' + i18n("srSaving", "Saving...") + "</span>");

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
                        statusEl.html('<span style="color: #00a32a;">✓ ' + i18n("srSaved", "Rules saved.") + "</span>");
                        setTimeout(function () {
                            statusEl.fadeOut(300, function () {
                                statusEl.html("").show();
                            });
                        }, 2000);
                    } else {
                        statusEl.html('<span style="color: #d63638;">✗ ' + i18n("srSaveFailed", "Failed to save rules: ") + (response.data || "") + "</span>");
                    }
                },
                error: function () {
                    statusEl.html('<span style="color: #d63638;">✗ ' + i18n("networkError", "Network error") + "</span>");
                },
                complete: function () {
                    saveBtn.prop("disabled", false);
                },
            });
        }

        function escHtml(str) {
            var div = document.createElement("div");
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function escAttr(str) {
            return escHtml(str).replace(/"/g, "&quot;");
        }
    });
})(jQuery);
