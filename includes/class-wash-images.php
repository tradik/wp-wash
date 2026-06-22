<?php
/**
 * File synchronization handler.
 * Uploads WordPress media attachments (images, PDFs, documents) to Shopify and handles deletion.
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

class WashImages {

    /** @var WashApiClient */
    private $apiClient;

    public function __construct() {
        $this->apiClient = new WashApiClient();
    }

    /**
     * Register WordPress hooks for file sync.
     */
    public function init() {
        if (WashContext::option('wash_files_enabled', '1') !== '1') {
            return;
        }

        add_action('add_attachment', [$this, 'onAddAttachment']);
        add_action('edit_attachment', [$this, 'onEditAttachment']);
        add_action('delete_attachment', [$this, 'onDeleteAttachment']);
    }

    /**
     * Handle new attachment upload — sync to Shopify.
     *
     * @param int $attachmentId Attachment post ID
     */
    public function onAddAttachment($attachmentId) {
        if (!$this->isSyncableFile($attachmentId)) {
            return;
        }

        $this->uploadToShopify($attachmentId);
    }

    /**
     * Handle attachment edit — re-sync if metadata changed.
     *
     * @param int $attachmentId Attachment post ID
     */
    public function onEditAttachment($attachmentId) {
        if (!$this->isSyncableFile($attachmentId)) {
            return;
        }

        $shopifyFileId = get_post_meta($attachmentId, '_wash_file_id', true);
        if ($shopifyFileId) {
            $this->deleteFromShopify($attachmentId);
        }

        $this->uploadToShopify($attachmentId);
    }

    /**
     * Handle attachment deletion — remove from Shopify.
     *
     * @param int $attachmentId Attachment post ID
     */
    public function onDeleteAttachment($attachmentId) {
        if (!$this->isSyncableFile($attachmentId)) {
            return;
        }

        $this->deleteFromShopify($attachmentId);
    }

    /**
     * Public method to sync a single file (image or document).
     * Called by WashFilesMedia for manual sync.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool Success status.
     */
    public function syncFile($attachmentId) {
        if (!$this->isSyncableFile($attachmentId)) {
            return false;
        }
        return $this->uploadToShopify($attachmentId);
    }

    /**
     * Public method to sync a single image.
     * Kept for backwards compatibility.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool Success status.
     */
    public function syncImage($attachmentId) {
        return $this->syncFile($attachmentId);
    }

    /**
     * Upload an image to Shopify via the Shopify App API.
     * If WebP conversion is enabled, processes through image-processor first.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool Success status.
     */
    private function uploadToShopify($attachmentId) {
        $imageUrl = wp_get_attachment_url($attachmentId);
        $filePath = get_attached_file($attachmentId);
        $mimeType = get_post_mime_type($attachmentId);
        $altText  = get_post_meta($attachmentId, '_wp_attachment_image_alt', true);

        if (!$imageUrl || !$filePath) {
            return false;
        }

        $filename = basename($filePath);

        $payload = [
            'action'         => 'upload',
            'wpAttachmentId' => $attachmentId,
            'wpSiteId'       => is_multisite() ? get_current_blog_id() : 1,
            'filename'       => $filename,
            'mimeType'       => $mimeType,
            'imageUrl'       => $imageUrl,
            'alt'            => $altText ?: basename($filePath),
        ];

        $result = $this->apiClient->post('/api/image-sync', $payload);

        if (is_wp_error($result)) {
            washLog("[wash] uploadToShopify() - API error: " . $result->get_error_message());
            return false;
        }

        // Async path (CRIT-3): the app queues the upload and the worker reports
        // the shopifyFileId / shopifyCdnUrl back via the callback, which writes
        // the attachment meta. Treat "queued" as success-pending, not failure —
        // returning false here is what made every image re-enqueue on each save.
        if (!empty($result['queued'])) {
            update_post_meta($attachmentId, '_wash_sync_pending', current_time('mysql'));
            washLog("[wash] uploadToShopify() - Attachment {$attachmentId} queued (async); awaiting callback");
            return true;
        }

        if (!isset($result['shopifyFileId'])) {
            washLog("[wash] uploadToShopify() - Missing shopifyFileId in response");
            return false;
        }

        $cdnUrl = $result['shopifyCdnUrl'] ?? '';
        washLog("[wash] uploadToShopify() - Attachment {$attachmentId} synced. CDN URL: " . ($cdnUrl ?: 'EMPTY'));

        update_post_meta($attachmentId, '_wash_file_id', $result['shopifyFileId']);
        update_post_meta($attachmentId, '_wash_cdn_url', $cdnUrl);

        return !empty($cdnUrl);
    }


    /**
     * Delete an image from Shopify via the Shopify App API.
     */
    private function deleteFromShopify($attachmentId) {
        $result = $this->apiClient->post('/api/image-sync', [
            'action'         => 'delete',
            'wpAttachmentId' => $attachmentId,
            'wpSiteId'       => is_multisite() ? get_current_blog_id() : 1,
        ]);

        if (!is_wp_error($result)) {
            delete_post_meta($attachmentId, '_wash_file_id');
            delete_post_meta($attachmentId, '_wash_cdn_url');
        }
    }

    /**
     * Check if an attachment is a syncable file type based on settings.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool Whether the file should be synced.
     */
    private function isSyncableFile($attachmentId) {
        $mimeType = get_post_mime_type($attachmentId);
        if (!$mimeType) {
            return false;
        }

        $allowedTypes = WashContext::option('wash_allowed_file_types', ['images']);
        if (!is_array($allowedTypes)) {
            $allowedTypes = ['images'];
        }

        // Check images
        if (in_array('images', $allowedTypes, true) && str_starts_with($mimeType, 'image/')) {
            return true;
        }

        // Check PDF
        if (in_array('pdf', $allowedTypes, true) && $mimeType === 'application/pdf') {
            return true;
        }

        // Check documents (Word, Excel, PowerPoint, etc.)
        if (in_array('documents', $allowedTypes, true)) {
            $docMimeTypes = [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'text/csv',
            ];
            if (in_array($mimeType, $docMimeTypes, true)) {
                return true;
            }
        }

        // Check video
        if (in_array('video', $allowedTypes, true) && str_starts_with($mimeType, 'video/')) {
            return true;
        }

        // Check audio
        if (in_array('audio', $allowedTypes, true) && str_starts_with($mimeType, 'audio/')) {
            return true;
        }

        return false;
    }

    /**
     * Check if an attachment is an image type.
     * Kept for backwards compatibility.
     *
     * @param int $attachmentId Attachment ID.
     * @return bool Whether the attachment is an image.
     */
    public function isImage($attachmentId) {
        $mimeType = get_post_mime_type($attachmentId);
        return $mimeType && str_starts_with($mimeType, 'image/');
    }

    /**
     * Check if an attachment is a document type (PDF or office documents).
     *
     * @param int $attachmentId Attachment ID.
     * @return bool Whether the attachment is a document.
     */
    public function isDocument($attachmentId) {
        $mimeType = get_post_mime_type($attachmentId);
        if (!$mimeType) {
            return false;
        }

        $docMimeTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
        ];

        return in_array($mimeType, $docMimeTypes, true);
    }

    /**
     * Get the Shopify CDN URL for a WordPress attachment.
     * Useful for rewriting file URLs in synced content.
     *
     * @param int $attachmentId WordPress attachment ID
     * @return string|null Shopify CDN URL or null
     */
    public static function getShopifyCdnUrl($attachmentId) {
        return get_post_meta($attachmentId, '_wash_cdn_url', true) ?: null;
    }
}
