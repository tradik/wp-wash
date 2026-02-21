/**
 * wp4shpy Admin JavaScript
 * Handles connection testing and UI interactions.
 * Note: Tab switching is handled inline in settings-page.php for better form integration.
 */
(function ($) {
    "use strict";

    $(document).ready(function () {
        // Connection test
        const testButton = $("#wp4shpy-test-connection");
        const statusContainer = $("#wp4shpy-connection-status");
        const noticeEl = $("#wp4shpy-notice");
        const noticeText = $("#wp4shpy-notice-text");

        testButton.on("click", function () {
            testButton.addClass("is-loading");
            testButton.text(wp4shpyAdmin.i18n.testing);
            statusContainer.hide();

            $.ajax({
                url: wp4shpyAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wp4shpy_test_connection",
                    nonce: wp4shpyAdmin.nonce,
                },
                success: function (response) {
                    statusContainer.show();
                    if (response.success) {
                        noticeEl.removeClass("notice-error").addClass("notice-success");
                        noticeText.text(
                            wp4shpyAdmin.i18n.success +
                            " (v" + (response.data.version || "?") + ")"
                        );
                    } else {
                        noticeEl.removeClass("notice-success").addClass("notice-error");
                        noticeText.text(wp4shpyAdmin.i18n.error + response.data);
                    }
                },
                error: function () {
                    statusContainer.show();
                    noticeEl.removeClass("notice-success").addClass("notice-error");
                    noticeText.text(wp4shpyAdmin.i18n.error + "Network error");
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
        $("#wp4shpy-disconnect-btn").on("click", function () {
            if (!confirm(wp4shpyAdmin.i18n.confirmDisconnect || "Are you sure you want to disconnect from Shopify?")) {
                return;
            }

            $.ajax({
                url: wp4shpyAdmin.ajaxUrl,
                type: "POST",
                data: {
                    action: "wp4shpy_disconnect",
                    nonce: wp4shpyAdmin.disconnectNonce,
                },
                success: function () {
                    window.location.reload();
                },
                error: function () {
                    alert("Failed to disconnect. Please try again.");
                },
            });
        });
    });
})(jQuery);
