/**
 * wash Admin JavaScript
 * Handles connection testing and UI interactions.
 * Note: Tab switching is handled inline in settings-page.php for better form integration.
 */
(function ($) {
    "use strict";

    // Helper to get i18n string with fallback
    const i18n = (key, fallback) => (washAdmin.i18n && washAdmin.i18n[key]) || fallback;

    $(document).ready(function () {
        // Check if user can modify settings (read-only mode)
        const userCanModify = $("#wash-user-can-modify").val() === "1";

        // Block form submission in read-only mode
        if (!userCanModify) {
            $("#wash-settings-form").on("submit", function (e) {
                e.preventDefault();
                alert(i18n('readOnlyMode', 'You do not have permission to modify Wash settings. Contact your administrator for access.'));
                return false;
            });
        }

        // Connection test
        const testButton = $("#wash-test-connection");

        testButton.on("click", function () {
            testButton.addClass("is-loading");
            testButton.text(i18n('testing', 'Testing connection...'));

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_test_connection",
                    nonce: washAdmin.nonce,
                },
                success: function (response) {
                    if (response.success) {
                        alert(i18n('success', 'Connection successful!') + " (v" + (response.data.version || "?") + ")");
                    } else {
                        alert(i18n('error', 'Connection failed: ') + response.data);
                    }
                },
                error: function () {
                    alert(i18n('error', 'Connection failed: ') + i18n('networkError', 'Network error'));
                },
                complete: function () {
                    testButton.removeClass("is-loading");
                    testButton.html(
                        '<span class="dashicons dashicons-networking" style="vertical-align: text-bottom;"></span> ' + i18n('testConnection', 'Test Connection')
                    );
                },
            });
        });

        // Disconnect handler
        $("#wash-disconnect-btn").on("click", function () {
            if (!confirm(i18n('confirmDisconnect', 'Are you sure you want to disconnect from Shopify?'))) {
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
                    alert(i18n('failedDisconnect', 'Failed to disconnect. Please try again.'));
                },
            });
        });

        // Debug Session handler
        const debugOutput = $("#wash-debug-output");
        const debugPre = debugOutput.find("pre");

        $("#wash-debug-session").on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text(i18n('checking', 'Checking...'));
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
                        debugPre.text(i18n('error', 'Error: ') + response.data);
                    }
                },
                error: function () {
                    debugOutput.show();
                    debugPre.text(i18n('networkErrorDebug', 'Network error - could not reach debug endpoint'));
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span> ' + i18n('debugSession', 'Debug Session')
                    );
                },
            });
        });

        // Reset Session handler
        $("#wash-reset-session").on("click", function () {
            if (!confirm(i18n('confirmReset', 'This will delete the Shopify session. You will need to re-open the app from Shopify Admin to re-authenticate. Continue?'))) {
                return;
            }

            const btn = $(this);
            btn.prop("disabled", true).text(i18n('resetting', 'Resetting...'));
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
                        alert(response.data.message || i18n('sessionResetSuccess', 'Session reset. Now open the app from Shopify Admin to re-authenticate.'));
                    } else {
                        debugPre.text(i18n('error', 'Error: ') + response.data);
                        alert(i18n('failedResetSession', 'Failed to reset session: ') + response.data);
                    }
                },
                error: function () {
                    debugOutput.show();
                    debugPre.text(i18n('networkErrorReset', 'Network error - could not reach reset endpoint'));
                    alert(i18n('networkErrorResetAlert', 'Network error while resetting session'));
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span> ' + i18n('resetSession', 'Reset Session')
                    );
                },
            });
        });

        // =====================
        // Reset to Defaults
        // =====================
        $("#wash-reset-to-defaults").on("click", function () {
            if (!confirm(i18n('confirmResetToDefaults', 'Are you sure you want to reset all settings to their default values?\n\nThis will reset:\n• Sync Settings\n• Files & Media options\n• Metafield sources\n• Gutenberg Blocks settings\n• Advanced features\n\nYour Shopify connection and synced content will NOT be affected.'))) {
                return;
            }

            const btn = $(this);
            btn.prop("disabled", true).text(i18n('resettingSettings', 'Resetting...'));

            $.ajax({
                url: washAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wash_reset_to_defaults",
                    nonce: washAdmin.nonce,
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message || i18n('settingsResetSuccess', 'All settings have been reset to defaults.'));
                        window.location.reload();
                    } else {
                        alert(i18n('failedResetSettings', 'Failed to reset settings: ') + response.data);
                    }
                },
                error: function () {
                    alert(i18n('networkErrorResetSettings', 'Network error while resetting settings'));
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-image-rotate" style="vertical-align: text-bottom;"></span> ' + i18n('resetAllSettings', 'Reset All Settings')
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
        const unlockBtn = $("#wash-unlock-redirections");
        const verifyBtn = $("#wash-verify-redirections");
        const overlay = $("#wash-redirections-overlay");
        const verifyResults = $("#wash-verify-results");
        const syncBtn = $("#wash-sync-redirections");
        let isUnlocked = false;

        // Unlock/Lock redirections textarea
        unlockBtn.on("click", function () {
            if (isUnlocked) {
                // Lock it back
                isUnlocked = false;
                textarea.prop("readonly", true).css({
                    "background": "#f6f7f7",
                    "cursor": "not-allowed"
                });
                overlay.show();
                syncBtn.prop("disabled", true).attr("title", i18n('unlockToSync', 'Unlock to enable sync'));
                unlockBtn.html('<span class="dashicons dashicons-lock" style="vertical-align: text-bottom;"></span> ' + i18n('unlock', 'Unlock'));
            } else {
                // Show confirmation dialog
                const confirmed = confirm(
                    i18n('unlockWarningTitle', '⚠️ Warning: Editing Redirections') + "\n\n" +
                    i18n('unlockWarning1', 'Changes to redirections directly affect your Shopify store.') + "\n" +
                    i18n('unlockWarning2', 'Incorrect redirections can break links and harm SEO.') + "\n\n" +
                    i18n('unlockWarning3', 'Make sure you know what you are doing.') + "\n\n" +
                    i18n('unlockConfirm', 'Do you want to unlock editing?')
                );
                if (confirmed) {
                    isUnlocked = true;
                    textarea.prop("readonly", false).css({
                        "background": "#fff",
                        "cursor": "text"
                    });
                    overlay.hide();
                    syncBtn.prop("disabled", false).attr("title", "");
                    unlockBtn.html('<span class="dashicons dashicons-unlock" style="vertical-align: text-bottom;"></span> ' + i18n('lock', 'Lock'));
                    textarea.focus();
                }
            }
        });

        // Clicking overlay triggers unlock
        overlay.on("click", function () {
            unlockBtn.trigger("click");
        });

        // Verify redirections syntax
        verifyBtn.on("click", function () {
            const content = textarea.val().trim();
            if (!content) {
                verifyResults.hide();
                return;
            }

            const lines = content.split("\n");
            const errors = [];
            const warnings = [];
            let validCount = 0;
            let commentCount = 0;
            const seenFrom = {};

            lines.forEach(function (line, index) {
                const lineNum = index + 1;
                line = line.trim();

                if (!line) return;

                // Comment line
                if (line.startsWith("#")) {
                    commentCount++;
                    return;
                }

                // Check for valid separator
                const match = line.match(/^(.+?)\s*(?:->|→|=>)\s*(.+)$/);
                if (!match) {
                    errors.push(i18n('verifyLineError', 'Line') + ' ' + lineNum + ': ' + i18n('verifyInvalidFormat', 'Invalid format - missing separator (→, ->, =>)'));
                    return;
                }

                const from = match[1].trim();
                const to = match[2].trim();

                // Check if paths start with /
                if (!from.startsWith("/")) {
                    errors.push(i18n('verifyLineError', 'Line') + ' ' + lineNum + ': ' + i18n('verifyFromSlash', 'Source path should start with /'));
                }
                if (!to.startsWith("/") && !to.startsWith("http")) {
                    errors.push(i18n('verifyLineError', 'Line') + ' ' + lineNum + ': ' + i18n('verifyToSlash', 'Target path should start with / or http'));
                }

                // Check for duplicates
                if (seenFrom[from]) {
                    warnings.push(i18n('verifyLineError', 'Line') + ' ' + lineNum + ': ' + i18n('verifyDuplicate', 'Duplicate source path') + ' "' + from + '" (' + i18n('verifyFirstAt', 'first at line') + ' ' + seenFrom[from] + ')');
                } else {
                    seenFrom[from] = lineNum;
                }

                // Check for redirect loops
                if (from === to) {
                    errors.push(i18n('verifyLineError', 'Line') + ' ' + lineNum + ': ' + i18n('verifyLoop', 'Redirect loop - source equals target'));
                }

                validCount++;
            });

            // Show results
            let html = '';
            if (errors.length === 0 && warnings.length === 0) {
                html = '<div style="color: #155724;">' +
                    '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ' +
                    '<strong>' + i18n('verifySuccess', 'Syntax valid!') + '</strong> ' +
                    validCount + ' ' + i18n('verifyRedirects', 'redirections') +
                    (commentCount > 0 ? ', ' + commentCount + ' ' + i18n('verifyComments', 'comments') : '') +
                    '</div>';
                verifyResults.css({ "background": "#d4edda", "border": "1px solid #c3e6cb" });
            } else {
                html = '<div style="margin-bottom: 8px;">' +
                    '<strong>' + validCount + '</strong> ' + i18n('verifyValidLines', 'valid') +
                    (commentCount > 0 ? ', <strong>' + commentCount + '</strong> ' + i18n('verifyComments', 'comments') : '') +
                    '</div>';

                if (errors.length > 0) {
                    html += '<div style="color: #721c24; margin-bottom: 5px;">' +
                        '<span class="dashicons dashicons-warning" style="color: #d63638;"></span> ' +
                        '<strong>' + i18n('verifyErrors', 'Errors') + ' (' + errors.length + '):</strong></div>' +
                        '<ul style="margin: 0 0 10px 20px; color: #721c24;">' +
                        errors.map(e => '<li>' + e + '</li>').join('') +
                        '</ul>';
                }

                if (warnings.length > 0) {
                    html += '<div style="color: #856404; margin-bottom: 5px;">' +
                        '<span class="dashicons dashicons-info" style="color: #dba617;"></span> ' +
                        '<strong>' + i18n('verifyWarnings', 'Warnings') + ' (' + warnings.length + '):</strong></div>' +
                        '<ul style="margin: 0 0 0 20px; color: #856404;">' +
                        warnings.map(w => '<li>' + w + '</li>').join('') +
                        '</ul>';
                }

                verifyResults.css({
                    "background": errors.length > 0 ? "#f8d7da" : "#fff3cd",
                    "border": "1px solid " + (errors.length > 0 ? "#f5c6cb" : "#ffc107")
                });
            }

            verifyResults.html(html).show();
        });

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
                    statsText.text(i18n('failedLoadStats', 'Failed to load stats'));
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
            btn.prop("disabled", true).text(i18n('fetchingWp', 'Fetching...'));

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
                            alert(i18n('noRedirectionsFound', 'No redirections found in WordPress plugins (Redirection, RankMath, Yoast).'));
                        } else {
                            let content = textarea.val();
                            if (content.trim()) {
                                content += "\n\n" + i18n('importedFromWp', '# Imported from WordPress plugins') + "\n";
                            }
                            redirections.forEach(function (r) {
                                if (r.from && r.to) {
                                    content += r.from + " -> " + r.to + "  # " + (r.source || "") + "\n";
                                }
                            });
                            textarea.val(content);
                        }
                    } else {
                        alert(i18n('failedFetchRedirections', 'Failed to fetch redirections: ') + (response.data || i18n('unknownError', 'Unknown error')));
                    }
                },
                error: function () {
                    alert(i18n('networkErrorFetch', 'Network error while fetching WordPress redirections'));
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span> ' + i18n('syncFromOldWebsite', 'Sync from Old Website')
                    );
                },
            });
        });

        // Generate redirections from synced posts
        $("#wash-generate-redirections").on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text(i18n('generating', 'Generating...'));

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
                            alert(i18n('noSyncedPosts', 'No synced posts found. Sync some posts first to generate redirections.'));
                        } else {
                            let content = textarea.val();
                            if (content.trim()) {
                                content += "\n\n" + i18n('generatedFromSynced', '# Generated from synced posts') + "\n";
                            }
                            redirections.forEach(function (r) {
                                content += r.from + " -> " + r.to + "  # " + r.title + "\n";
                            });
                            textarea.val(content);
                        }
                    } else {
                        alert(i18n('failedGenerateRedirections', 'Failed to generate redirections: ') + (response.data || i18n('unknownError', 'Unknown error')));
                    }
                },
                error: function () {
                    alert(i18n('networkErrorGenerate', 'Network error while generating redirections'));
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom;"></span> ' + i18n('generateFromSynced', 'Generate from Synced Posts')
                    );
                },
            });
        });

        // Load existing Shopify redirections
        $("#wash-load-shopify-redirections").on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text(i18n('loading', 'Loading...'));

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
                            alert(i18n('noRedirectionsShopify', 'No redirections found in Shopify.'));
                        } else {
                            let content = i18n('existingShopifyRedirects', '# Existing Shopify redirections') + "\n";
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
                        const errorMsg = response.data || i18n('unknownError', 'Unknown error');
                        if (errorMsg.indexOf && errorMsg.indexOf('Access denied') !== -1) {
                            showFixSessionButton();
                        }
                        alert(i18n('failedLoadShopify', 'Failed to load Shopify redirections: ') + errorMsg);
                    }
                },
                error: function () {
                    alert(i18n('networkErrorLoadShopify', 'Network error while loading Shopify redirections'));
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-cloud" style="vertical-align: text-bottom;"></span> ' + i18n('loadFromShopify', 'Load from Shopify')
                    );
                },
            });
        });

        // Fix Session handler - repairs scope issue
        fixSessionBtn.on("click", function () {
            const btn = $(this);
            btn.prop("disabled", true).text(i18n('fixing', 'Fixing...'));

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
                            alert(i18n('sessionFixed', 'Session fixed!') + " " + data.message + "\n\n" + i18n('tryAgain', 'Try loading redirections again.'));
                            hideFixSessionButton();
                            // Reload stats
                            loadRedirectStats();
                        } else {
                            alert(data.message || i18n('noSessionsNeedFix', 'No sessions needed fixing.'));
                        }
                    } else {
                        alert(i18n('failedFixSession', 'Failed to fix session: ') + response.data);
                    }
                },
                error: function () {
                    alert(i18n('networkErrorFix', 'Network error while fixing session'));
                },
                complete: function () {
                    btn.prop("disabled", false).html(
                        '<span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span> ' + i18n('fixSessionScopes', 'Fix Session Scopes')
                    );
                },
            });
        });

        // Sync redirections to Shopify
        $("#wash-sync-redirections").on("click", function () {
            if (!isUnlocked) {
                alert(i18n('unlockToSync', 'Unlock to enable sync'));
                return;
            }

            const btn = $(this);
            const content = textarea.val().trim();

            if (!content) {
                alert(i18n('pleaseEnterRedirections', 'Please enter redirections to sync.'));
                return;
            }

            btn.prop("disabled", true);
            syncStatus.show().html(
                '<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> ' +
                i18n('syncing', 'Syncing redirections...')
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
                            "<li><strong>" + i18n('created', 'Created') + ":</strong> " + response.data.created + "</li>" +
                            "<li><strong>" + i18n('skippedExist', 'Skipped (already exist)') + ":</strong> " + response.data.skipped + "</li>" +
                            "<li><strong>" + i18n('failed', 'Failed') + ":</strong> " + response.data.failed + "</li>" +
                            "<li><strong>" + i18n('totalInShopify', 'Total in Shopify') + ":</strong> " + response.data.totalInShopify + "</li>" +
                            "<li><strong>" + i18n('remaining', 'Remaining') + ":</strong> " + response.data.remaining + "</li>"
                        );

                        statsText.html(
                            '<strong>' + response.data.totalInShopify + '</strong> / 100000 redirects used | ' +
                            '<strong>' + response.data.remaining + '</strong> remaining'
                        );

                        if (response.data.errors && response.data.errors.length > 0) {
                            let errorHtml = "<li style='color: #d63638;'><strong>" + i18n('errors', 'Errors') + ":</strong><ul>";
                            response.data.errors.slice(0, 10).forEach(function (e) {
                                errorHtml += "<li>" + e.path + ": " + e.error + "</li>";
                            });
                            if (response.data.errors.length > 10) {
                                const moreText = i18n('andMore', '... and %d more').replace('%d', response.data.errors.length - 10);
                                errorHtml += "<li>" + moreText + "</li>";
                            }
                            errorHtml += "</ul></li>";
                            syncResultsList.append(errorHtml);
                        }
                    } else {
                        const errorMsg = response.data || i18n('unknownError', 'Unknown error');
                        if (errorMsg.indexOf && errorMsg.indexOf('Access denied') !== -1) {
                            showFixSessionButton();
                        }
                        alert(i18n('syncError', 'Failed to sync redirections: ') + errorMsg);
                    }
                },
                error: function () {
                    syncStatus.hide();
                    alert(i18n('networkErrorSync', 'Network error while syncing redirections'));
                },
                complete: function () {
                    btn.prop("disabled", false);
                },
            });
        });

        // Save Redirections Settings
        $("#wash-save-redirections-settings").on("click", function () {
            const btn = $(this);
            const status = $("#wash-redirections-save-status");
            const enabled = $("#wash_redirections_enabled").is(":checked") ? "1" : "0";

            btn.prop("disabled", true);
            status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span>');

            $.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "wash_save_redirections_settings",
                    nonce: washAdmin.redirectionsNonce,
                    enabled: enabled,
                },
                success: function (response) {
                    if (response.success) {
                        status.html('<span style="color: #00a32a;">✓ ' + i18n('saved', 'Saved') + '</span>');
                        setTimeout(function () {
                            status.fadeOut(300, function () {
                                status.html("").show();
                            });
                        }, 2000);
                    } else {
                        status.html('<span style="color: #d63638;">✗ ' + (response.data || i18n('error', 'Error')) + '</span>');
                    }
                },
                error: function () {
                    status.html('<span style="color: #d63638;">✗ ' + i18n('networkError', 'Network error') + '</span>');
                },
                complete: function () {
                    btn.prop("disabled", false);
                },
            });
        });

    });
})(jQuery);
