/**
 * wp4shpy Media Library Scripts
 * Handles Shopify sync functionality in WordPress media library.
 */
(function($) {
    'use strict';

    /**
     * Handle sync button click in attachment details.
     */
    function initSyncButtons() {
        $(document).on('click', '.wp4shpy-sync-btn', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $container = $btn.closest('.wp4shpy-attachment-sync');
            var $spinner = $container.find('.spinner');
            var $info = $container.find('.wp4shpy-sync-info');
            var attachmentId = $btn.data('attachment-id');

            if ($btn.hasClass('syncing')) {
                return;
            }

            $btn.addClass('syncing').prop('disabled', true);
            $btn.find('.dashicons').removeClass('dashicons-store').addClass('dashicons-update spin');
            $spinner.addClass('is-active');

            $.ajax({
                url: wp4shpyMedia.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp4shpy_sync_media',
                    nonce: wp4shpyMedia.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        updateSyncStatus($info, $btn, response.data);
                    } else {
                        showSyncError($info, response.data || wp4shpyMedia.i18n.syncError);
                    }
                },
                error: function() {
                    showSyncError($info, wp4shpyMedia.i18n.syncError);
                },
                complete: function() {
                    $btn.removeClass('syncing').prop('disabled', false);
                    $btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-store');
                    $spinner.removeClass('is-active');
                }
            });
        });
    }

    /**
     * Update sync status display after successful sync.
     *
     * @param {jQuery} $info Info container element.
     * @param {jQuery} $btn  Sync button element.
     * @param {Object} data  Response data with cdnUrl.
     */
    function updateSyncStatus($info, $btn, data) {
        var html = '<span class="wp4shpy-sync-status synced">' +
            '<span class="dashicons dashicons-yes-alt"></span> ' +
            wp4shpyMedia.i18n.synced +
            '</span>';

        if (data.cdnUrl) {
            html += ' <a href="' + data.cdnUrl + '" target="_blank" rel="noopener noreferrer" class="wp4shpy-cdn-link">' +
                wp4shpyMedia.i18n.viewOnCdn +
                '</a>';
        }

        $info.html(html);
        $btn.html('<span class="dashicons dashicons-store"></span> ' + wp4shpyMedia.i18n.resyncBtn);
    }

    /**
     * Show sync error message.
     *
     * @param {jQuery} $info    Info container element.
     * @param {string} message  Error message.
     */
    function showSyncError($info, message) {
        var currentHtml = $info.html();
        $info.html('<span class="wp4shpy-sync-status" style="color: #dc3232;">' +
            '<span class="dashicons dashicons-warning"></span> ' +
            message +
            '</span>');

        setTimeout(function() {
            $info.html(currentHtml);
        }, 3000);
    }

    /**
     * Initialize when document is ready.
     */
    $(document).ready(function() {
        initSyncButtons();
    });

    /**
     * Re-initialize when media modal content changes.
     */
    if (typeof wp !== 'undefined' && wp.media) {
        wp.media.view.Attachment.Details = wp.media.view.Attachment.Details.extend({
            render: function() {
                wp.media.view.Attachment.prototype.render.apply(this, arguments);
                return this;
            }
        });
    }

})(jQuery);
