<?php
/**
 * File synchronization handler.
 * Uploads WordPress media attachments (images, PDFs, documents) to Shopify and handles deletion.
 */
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
        if (get_option('wash_sync_images') !== '1') {
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
        $processedData = null;

        // Check if WebP conversion is enabled
        if (get_option('wash_webp_convert') === '1' && $this->isConvertibleToWebp($mimeType)) {
            $processed = $this->processImageToWebp($imageUrl, $filename);
            if ($processed) {
                $processedData = $processed['processedData'];
                $mimeType = $processed['mimeType'];
                $filename = $processed['filename'];
            }
        }

        $payload = [
            'action'         => 'upload',
            'wpAttachmentId' => $attachmentId,
            'wpSiteId'       => is_multisite() ? get_current_blog_id() : 1,
            'filename'       => $filename,
            'mimeType'       => $mimeType,
            'imageUrl'       => $imageUrl,
            'alt'            => $altText ?: basename($filePath),
        ];

        // Include processed data if available
        if ($processedData) {
            $payload['processedData'] = $processedData;
        }

        $result = $this->apiClient->post('/api/image-sync', $payload);

        if (is_wp_error($result)) {
            error_log("[wash] uploadToShopify() - API error: " . $result->get_error_message());
            return false;
        }

        if (!isset($result['shopifyFileId'])) {
            error_log("[wash] uploadToShopify() - Missing shopifyFileId in response");
            return false;
        }

        $cdnUrl = $result['shopifyCdnUrl'] ?? '';
        error_log("[wash] uploadToShopify() - Attachment {$attachmentId} synced. CDN URL: " . ($cdnUrl ?: 'EMPTY'));

        update_post_meta($attachmentId, '_wash_file_id', $result['shopifyFileId']);
        update_post_meta($attachmentId, '_wash_cdn_url', $cdnUrl);

        return !empty($cdnUrl);
    }

    /**
     * Process image through image-processor for WebP conversion.
     *
     * @param string $imageUrl Original image URL.
     * @param string $filename Original filename.
     * @return array|null Processed data or null on failure.
     */
    private function processImageToWebp($imageUrl, $filename) {
        $processorUrl = 'http://image-processor:4040/api/process-image';

        $response = wp_remote_post($processorUrl, [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'imageUrl'      => $imageUrl,
                'convertToWebp' => true,
                'quality'       => 85,
                'filename'      => $filename,
            ]),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['success']) || !$data['success']) {
            return null;
        }

        return $data['data'] ?? null;
    }

    /**
     * Check if MIME type is convertible to WebP.
     *
     * @param string $mimeType MIME type.
     * @return bool Whether convertible.
     */
    private function isConvertibleToWebp($mimeType) {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/tiff'], true);
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

        $allowedTypes = get_option('wash_allowed_file_types', ['images']);
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
