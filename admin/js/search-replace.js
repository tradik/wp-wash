/**
 * Wash Search & Replace tab — manages rules CRUD via AJAX.
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
        });

        saveBtn.on("click", saveRules);

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
                        if (tbody.find(".wash-sr-row").length === 0) {
                            showEmptyMessage();
                        }
                    });
                }
            });

            tbody.append(row);
        }

        function renumberRows() {
            tbody.find(".wash-sr-row").each(function (i) {
                $(this).find(".wash-sr-num").text(i + 1);
            });
        }

        function showEmptyMessage() {
            if (tbody.find("#wash-sr-empty").length) return;
            tbody.append(
                '<tr id="wash-sr-empty"><td colspan="5" style="text-align: center; padding: 20px; color: #666;">' +
                    i18n("srNoRules", "No rules defined yet. Click \"Add Rule\" to create one.") +
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
