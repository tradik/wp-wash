/**
 * Wash Sync Status Polling
 * Auto-updates sync status icons and notices without page refresh.
 */
(function($) {
    'use strict';

    var pollInterval = 3000; // 3 seconds
    var maxPolls = 60; // Max 3 minutes of polling
    var pollCount = 0;
    var pollTimer = null;
    var queuedPostIds = [];

    /**
     * Find all posts currently in queued state.
     */
    function findQueuedPosts() {
        queuedPostIds = [];
        $('.wash-syncing').each(function() {
            var postId = $(this).data('post-id');
            if (postId) {
                queuedPostIds.push(postId);
            }
        });
        return queuedPostIds;
    }

    /**
     * Update the icon for a specific post.
     */
    function updatePostIcon($cell, status, data) {
        var html = '';

        if (status === 'synced') {
            var title = WashSyncStatus.i18n.synced;
            html = '<a href="' + escapeHtml(data.shopifyUrl) + '" target="_blank" rel="noopener noreferrer" title="' + escapeHtml(title) + '">' +
                   '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>' +
                   '</a>';
        } else if (status === 'error') {
            html = '<span class="dashicons dashicons-warning" style="color: #d63638;" title="' + escapeHtml(data.error || WashSyncStatus.i18n.error) + '"></span>';
        } else if (status === 'queued') {
            // Still queued, keep spinning
            return;
        } else {
            html = '<span class="dashicons dashicons-minus" style="color: #ddd;" title="Not synced"></span>';
        }

        $cell.html(html);
    }

    /**
     * Show success notice at top of page.
     */
    function showSuccessNotice(message) {
        // Remove existing wash notices
        $('.wash-sync-notice').remove();

        var notice = $('<div class="notice notice-success is-dismissible wash-sync-notice">' +
                      '<p><strong>Wash:</strong> ' + escapeHtml(message) + '</p>' +
                      '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>' +
                      '</div>');

        $('.wrap h1').first().after(notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notice.addClass('wash-notice-hiding');
            setTimeout(function() {
                notice.remove();
            }, 300);
        }, 5000);

        // Manual dismiss
        notice.find('.notice-dismiss').on('click', function() {
            notice.addClass('wash-notice-hiding');
            setTimeout(function() {
                notice.remove();
            }, 300);
        });
    }

    /**
     * Show error notice at top of page.
     */
    function showErrorNotice(message) {
        $('.wash-sync-notice').remove();

        var notice = $('<div class="notice notice-error is-dismissible wash-sync-notice">' +
                      '<p><strong>Wash:</strong> ' + escapeHtml(message) + '</p>' +
                      '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>' +
                      '</div>');

        $('.wrap h1').first().after(notice);

        notice.find('.notice-dismiss').on('click', function() {
            notice.addClass('wash-notice-hiding');
            setTimeout(function() {
                notice.remove();
            }, 300);
        });
    }

    /**
     * Poll server for sync status updates.
     */
    function pollSyncStatus() {
        if (queuedPostIds.length === 0) {
            stopPolling();
            return;
        }

        if (pollCount >= maxPolls) {
            stopPolling();
            return;
        }

        pollCount++;

        $.ajax({
            url: WashSyncStatus.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wash_check_sync_status',
                nonce: WashSyncStatus.nonce,
                post_ids: queuedPostIds
            },
            success: function(response) {
                if (!response.success || !response.data) {
                    return;
                }

                var stillQueued = [];
                var anyCompleted = false;

                $.each(response.data, function(postId, result) {
                    var $icon = $('.wash-syncing[data-post-id="' + postId + '"]');
                    var $cell = $icon.closest('.column-wash_sync');

                    if (result.status === 'synced') {
                        updatePostIcon($cell, 'synced', result);
                        anyCompleted = true;
                    } else if (result.status === 'error') {
                        updatePostIcon($cell, 'error', result);
                        showErrorNotice(result.error || WashSyncStatus.i18n.error);
                    } else if (result.status === 'queued') {
                        stillQueued.push(parseInt(postId, 10));
                    }
                });

                if (anyCompleted) {
                    showSuccessNotice(WashSyncStatus.i18n.success);
                }

                queuedPostIds = stillQueued;

                if (queuedPostIds.length === 0) {
                    stopPolling();
                }
            },
            error: function() {
                // Silently fail, will retry on next poll
            }
        });
    }

    /**
     * Start polling for sync status.
     */
    function startPolling() {
        if (pollTimer) {
            return;
        }
        pollCount = 0;
        pollTimer = setInterval(pollSyncStatus, pollInterval);
        // Immediate first poll
        pollSyncStatus();
    }

    /**
     * Stop polling.
     */
    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    /**
     * Escape HTML to prevent XSS.
     */
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Auto-dismiss existing queued notices after page load.
     */
    function handleExistingNotices() {
        // If there's a "queued" notice from page load, it will be updated by polling
        var $queuedNotice = $('.notice-info:contains("queued")');
        if ($queuedNotice.length > 0 && queuedPostIds.length > 0) {
            // Will be replaced by success/error notice when sync completes
            $queuedNotice.addClass('wash-sync-notice');
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        findQueuedPosts();
        handleExistingNotices();

        if (queuedPostIds.length > 0) {
            startPolling();
        }
    });

})(jQuery);
