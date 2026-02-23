/**
 * wash Admin JavaScript
 * Handles connection testing and UI interactions.
 * Note: Tab switching is handled inline in settings-page.php for better form integration.
 */
(function ($) {
    "use strict";

    $(document).ready(function () {
        // Connection test
        const testButton = $("#wash-test-connection");
        const statusContainer = $("#wash-connection-status");
        const noticeEl = $("#wash-notice");
        const noticeText = $("#wash-notice-text");

        testButton.on("click", function () {
            testButton.addClass("is-loading");
            testButton.text(washAdmin.i18n.testing);
            statusContainer.hide();

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_test_connection",
                    nonce: washAdmin.nonce,
                },
                success: function (response) {
                    statusContainer.show();
                    if (response.success) {
                        noticeEl.removeClass("notice-error").addClass("notice-success");
                        noticeText.text(
                            washAdmin.i18n.success +
                            " (v" + (response.data.version || "?") + ")"
                        );
                    } else {
                        noticeEl.removeClass("notice-success").addClass("notice-error");
                        noticeText.text(washAdmin.i18n.error + response.data);
                    }
                },
                error: function () {
                    statusContainer.show();
                    noticeEl.removeClass("notice-success").addClass("notice-error");
                    noticeText.text(washAdmin.i18n.error + "Network error");
                },
                complete: function () {
                    testButton.removeClass("is-loading");
                    testButton.html(
                        '<span class="dashicons dashicons-networking" style="vertical-align: text-bottom;"></span> Test Connection'
                    );
                },
            });
        });

        // Disconnect handler
        $("#wash-disconnect-btn").on("click", function () {
            if (!confirm(washAdmin.i18n.confirmDisconnect || "Are you sure you want to disconnect from Shopify?")) {
                return;
            }

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_disconnect",
                    nonce: washAdmin.disconnectNonce,
                },
                success: function () {
                    window.location.reload();
                },
                error: function () {
                    alert("Failed to disconnect. Please try again.");
                },
            });
        });

        // Debug Session handler
        const debugOutput = $("#wash-debug-output");
        const debugPre = debugOutput.find("pre");

        $("#wash-debug-session").on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text("Checking...");
            debugOutput.hide();

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_debug_session",
                    nonce: washAdmin.nonce,
                },
                success: function (response) {
                    debugOutput.show();
                    if (response.success) {
                        debugPre.text(JSON.stringify(response.data, null, 2));
                    } else {
                        debugPre.text("Error: " + response.data);
                    }
                },
                error: function () {
                    debugOutput.show();
                    debugPre.text("Network error - could not reach debug endpoint");
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span> Debug Session'
                    );
                },
            });
        });

        // Reset Session handler
        $("#wash-reset-session").on("click", function () {
            if (!confirm("This will delete the Shopify session. You will need to re-open the app from Shopify Admin to re-authenticate. Continue?")) {
                return;
            }

            const btn = $(this);
            btn.prop("disabled", true).text("Resetting...");
            debugOutput.hide();

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_reset_session",
                    nonce: washAdmin.nonce,
                },
                success: function (response) {
                    debugOutput.show();
                    if (response.success) {
                        debugPre.text(JSON.stringify(response.data, null, 2));
                        alert(response.data.message || "Session reset. Now open the app from Shopify Admin to re-authenticate.");
                    } else {
                        debugPre.text("Error: " + response.data);
                        alert("Failed to reset session: " + response.data);
                    }
                },
                error: function () {
                    debugOutput.show();
                    debugPre.text("Network error - could not reach reset endpoint");
                    alert("Network error while resetting session");
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span> Reset Session'
                    );
                },
            });
        });

        // =====================
        // Redirections Management
        // =====================
        const textarea = $("#wash-redirections-textarea");
        const statsText = $("#wash-stats-text");
        const syncStatus = $("#wash-sync-status");
        const syncResults = $("#wash-sync-results");
        const syncResultsList = $("#wash-sync-results-list");
        const fixSessionBtn = $("#wash-fix-session");

        // Show fix session button when access denied error occurs
        function showFixSessionButton() {
            fixSessionBtn.show();
        }

        // Hide fix session button
        function hideFixSessionButton() {
            fixSessionBtn.hide();
        }

        // Load redirect stats on page load (if on redirections tab)
        function loadRedirectStats() {
            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_get_shopify_redirections",
                    nonce: washAdmin.redirectionsNonce,
                },
                success: function (response) {
                    if (response.success && response.data.stats) {
                        const stats = response.data.stats;
                        statsText.html(
                            '<strong>' + stats.count + '</strong> / ' + stats.limit +
                            ' redirects used | <strong>' + stats.remaining + '</strong> remaining'
                        );
                        hideFixSessionButton();
                    } else if (response.data && response.data.indexOf && response.data.indexOf('Access denied') !== -1) {
                        showFixSessionButton();
                    }
                },
                error: function () {
                    statsText.text("Failed to load stats");
                },
            });
        }

        // Load stats when switching to redirections tab
        $('a[data-tab="redirections"]').on("click", function () {
            loadRedirectStats();
        });

        // Fetch WordPress redirections from plugins
        $("#wash-fetch-wp-redirections").on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text(washAdmin.i18n.fetchingWp || "Fetching...");

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_fetch_wp_redirections",
                    nonce: washAdmin.redirectionsNonce,
                },
                success: function (response) {
                    if (response.success && response.data.redirections) {
                        const redirections = response.data.redirections;
                        if (redirections.length === 0) {
                            alert("No redirections found in WordPress plugins (Redirection, RankMath, Yoast).");
                        } else {
                            let content = textarea.val();
                            if (content.trim()) {
                                content += "\n\n# Imported from WordPress plugins\n";
                            }
                            redirections.forEach(function (r) {
                                if (r.from && r.to) {
                                    content += r.from + " -> " + r.to + "  # " + (r.source || "") + "\n";
                                }
                            });
                            textarea.val(content);
                        }
                    } else {
                        alert("Failed to fetch redirections: " + (response.data || "Unknown error"));
                    }
                },
                error: function () {
                    alert("Network error while fetching WordPress redirections");
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span> Sync from Old Website'
                    );
                },
            });
        });

        // Generate redirections from synced posts
        $("#wash-generate-redirections").on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text(washAdmin.i18n.generating || "Generating...");

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_generate_redirections",
                    nonce: washAdmin.redirectionsNonce,
                },
                success: function (response) {
                    if (response.success && response.data.redirections) {
                        const redirections = response.data.redirections;
                        if (redirections.length === 0) {
                            alert("No synced posts found. Sync some posts first to generate redirections.");
                        } else {
                            let content = textarea.val();
                            if (content.trim()) {
                                content += "\n\n# Generated from synced posts\n";
                            }
                            redirections.forEach(function (r) {
                                content += r.from + " -> " + r.to + "  # " + r.title + "\n";
                            });
                            textarea.val(content);
                        }
                    } else {
                        alert("Failed to generate redirections: " + (response.data || "Unknown error"));
                    }
                },
                error: function () {
                    alert("Network error while generating redirections");
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom;"></span> Generate from Synced Posts'
                    );
                },
            });
        });

        // Load existing Shopify redirections
        $("#wash-load-shopify-redirections").on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text(washAdmin.i18n.loading || "Loading...");

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_get_shopify_redirections",
                    nonce: washAdmin.redirectionsNonce,
                },
                success: function (response) {
                    if (response.success && response.data.redirects) {
                        hideFixSessionButton();
                        const redirects = response.data.redirects;
                        if (redirects.length === 0) {
                            alert("No redirections found in Shopify.");
                        } else {
                            let content = "# Existing Shopify redirections\n";
                            redirects.forEach(function (r) {
                                content += r.path + " -> " + r.target + "\n";
                            });
                            textarea.val(content);
                        }

                        if (response.data.stats) {
                            const stats = response.data.stats;
                            statsText.html(
                                '<strong>' + stats.count + '</strong> / ' + stats.limit +
                                ' redirects used | <strong>' + stats.remaining + '</strong> remaining'
                            );
                        }
                    } else {
                        const errorMsg = response.data || "Unknown error";
                        if (errorMsg.indexOf && errorMsg.indexOf('Access denied') !== -1) {
                            showFixSessionButton();
                        }
                        alert("Failed to load Shopify redirections: " + errorMsg);
                    }
                },
                error: function () {
                    alert("Network error while loading Shopify redirections");
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-cloud" style="vertical-align: text-bottom;"></span> Load from Shopify'
                    );
                },
            });
        });

        // Fix Session handler - repairs scope issue
        fixSessionBtn.on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text("Fixing...");

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_fix_session",
                    nonce: washAdmin.redirectionsNonce,
                },
                success: function (response) {
                    if (response.success) {
                        const data = response.data;
                        if (data.fixedSessions && data.fixedSessions.length > 0) {
                            alert("Session fixed! " + data.message + "\n\nTry loading redirections again.");
                            hideFixSessionButton();
                            // Reload stats
                            loadRedirectStats();
                        } else {
                            alert(data.message || "No sessions needed fixing.");
                        }
                    } else {
                        alert("Failed to fix session: " + response.data);
                    }
                },
                error: function () {
                    alert("Network error while fixing session");
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span> Fix Session Scopes'
                    );
                },
            });
        });

        // Sync redirections to Shopify
        $("#wash-sync-redirections").on("click", function () {
            const btn = $(this);
            const content = textarea.val().trim();

            if (!content) {
                alert("Please enter redirections to sync.");
                return;
            }

            btn.prop("disabled", true);
            syncStatus.show().html(
                '<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> ' +
                (washAdmin.i18n.syncing || "Syncing redirections...")
            );
            syncResults.hide();

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_sync_redirections",
                    nonce: washAdmin.redirectionsNonce,
                    redirections: content,
                },
                success: function (response) {
                    syncStatus.hide();

                    if (response.success) {
                        hideFixSessionButton();
                        syncResults.show();
                        syncResultsList.html(
                            "<li><strong>Created:</strong> " + response.data.created + "</li>" +
                            "<li><strong>Skipped (already exist):</strong> " + response.data.skipped + "</li>" +
                            "<li><strong>Failed:</strong> " + response.data.failed + "</li>" +
                            "<li><strong>Total in Shopify:</strong> " + response.data.totalInShopify + "</li>" +
                            "<li><strong>Remaining:</strong> " + response.data.remaining + "</li>"
                        );

                        statsText.html(
                            '<strong>' + response.data.totalInShopify + '</strong> / 100000 redirects used | ' +
                            '<strong>' + response.data.remaining + '</strong> remaining'
                        );

                        if (response.data.errors && response.data.errors.length > 0) {
                            let errorHtml = "<li style='color: #d63638;'><strong>Errors:</strong><ul>";
                            response.data.errors.slice(0, 10).forEach(function (e) {
                                errorHtml += "<li>" + e.path + ": " + e.error + "</li>";
                            });
                            if (response.data.errors.length > 10) {
                                errorHtml += "<li>... and " + (response.data.errors.length - 10) + " more</li>";
                            }
                            errorHtml += "</ul></li>";
                            syncResultsList.append(errorHtml);
                        }
                    } else {
                        const errorMsg = response.data || "Unknown error";
                        if (errorMsg.indexOf && errorMsg.indexOf('Access denied') !== -1) {
                            showFixSessionButton();
                        }
                        alert((washAdmin.i18n.syncError || "Failed to sync redirections: ") + errorMsg);
                    }
                },
                error: function () {
                    syncStatus.hide();
                    alert("Network error while syncing redirections");
                },
                complete: function () {
                    btn.prop("disabled", false);
                },
            });
        });

    });
})(jQuery);
