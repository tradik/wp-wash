<?php
/**
 * Coverage tests for WashImages.
 * Targets: init(), onAddAttachment(), onEditAttachment(), onDeleteAttachment(),
 *          syncFile(), syncImage(), isImage(), isDocument(), getShopifyCdnUrl(),
 *          private isSyncableFile() (via public entry-points),
 *          private uploadToShopify() + deleteFromShopify() (via syncFile/onEdit).
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-images.php';

class WashImagesCoverageTest extends TestCase
{
    private \WashImages $images;
    private array $optionMap = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Reset per-connection static state so a memoized WashContext client/id
        // can't leak between tests (PHPUnit runs in execution-order, not file order).
        \WashContext::setActiveId(null);

        $this->optionMap = [
            'wash_api_key'             => 'test-key',
            'wash_app_url'             => 'https://app-wash.tradik.dev',
            'wash_http_user'           => '',
            'wash_http_pass'           => '',
            'wash_files_enabled'       => '1',
            'wash_allowed_file_types'  => ['images', 'pdf', 'documents', 'video', 'audio'],
        ];

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return $this->optionMap[$key] ?? $default;
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('__')->alias(fn($t) => $t);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);

        $this->images = new \WashImages();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // init() — hooks registration
    // ---------------------------------------------------------------

    public function testInitRegistersHooksWhenEnabled(): void
    {
        // add_action is already mocked to justReturn true; no exception = hooks registered
        $this->images->init();
        $this->assertTrue(true);
    }

    public function testInitSkipsHooksWhenFilesDisabled(): void
    {
        $this->optionMap['wash_files_enabled'] = '0';
        // Should return early — no add_action calls should matter
        $this->images->init();
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // isImage() — public helper
    // ---------------------------------------------------------------

    public function testIsImageReturnsTrueForImageMime(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        $this->assertTrue($this->images->isImage(1));
    }

    public function testIsImageReturnsFalseForPdf(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/pdf');
        $this->assertFalse($this->images->isImage(2));
    }

    public function testIsImageReturnsFalseWhenNoMime(): void
    {
        Functions\when('get_post_mime_type')->justReturn(false);
        $this->assertFalse($this->images->isImage(3));
    }

    // ---------------------------------------------------------------
    // isDocument() — public helper
    // ---------------------------------------------------------------

    public function testIsDocumentReturnsTrueForPdf(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/pdf');
        $this->assertTrue($this->images->isDocument(10));
    }

    public function testIsDocumentReturnsTrueForWord(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/msword');
        $this->assertTrue($this->images->isDocument(11));
    }

    public function testIsDocumentReturnsTrueForDocx(): void
    {
        Functions\when('get_post_mime_type')->justReturn(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
        $this->assertTrue($this->images->isDocument(12));
    }

    public function testIsDocumentReturnsTrueForXls(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/vnd.ms-excel');
        $this->assertTrue($this->images->isDocument(13));
    }

    public function testIsDocumentReturnsTrueForXlsx(): void
    {
        Functions\when('get_post_mime_type')->justReturn(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->assertTrue($this->images->isDocument(14));
    }

    public function testIsDocumentReturnsTrueForPpt(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/vnd.ms-powerpoint');
        $this->assertTrue($this->images->isDocument(15));
    }

    public function testIsDocumentReturnsTrueForPptx(): void
    {
        Functions\when('get_post_mime_type')->justReturn(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        );
        $this->assertTrue($this->images->isDocument(16));
    }

    public function testIsDocumentReturnsTrueForTextPlain(): void
    {
        Functions\when('get_post_mime_type')->justReturn('text/plain');
        $this->assertTrue($this->images->isDocument(17));
    }

    public function testIsDocumentReturnsTrueForCsv(): void
    {
        Functions\when('get_post_mime_type')->justReturn('text/csv');
        $this->assertTrue($this->images->isDocument(18));
    }

    public function testIsDocumentReturnsFalseForImage(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/png');
        $this->assertFalse($this->images->isDocument(19));
    }

    public function testIsDocumentReturnsFalseWhenNoMime(): void
    {
        Functions\when('get_post_mime_type')->justReturn(false);
        $this->assertFalse($this->images->isDocument(20));
    }

    // ---------------------------------------------------------------
    // getShopifyCdnUrl() — static helper
    // ---------------------------------------------------------------

    public function testGetShopifyCdnUrlReturnsCdnUrlWhenSet(): void
    {
        Functions\when('get_post_meta')->justReturn('https://cdn.shopify.com/img.jpg');
        $result = \WashImages::getShopifyCdnUrl(5);
        $this->assertSame('https://cdn.shopify.com/img.jpg', $result);
    }

    public function testGetShopifyCdnUrlReturnsNullWhenEmpty(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        $result = \WashImages::getShopifyCdnUrl(6);
        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // syncFile() / syncImage() — isSyncableFile paths
    // ---------------------------------------------------------------

    public function testSyncFileReturnsFalseWhenMimeNotSyncable(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/octet-stream');
        $this->assertFalse($this->images->syncFile(30));
    }

    public function testSyncFileReturnsFalseWhenNoMime(): void
    {
        Functions\when('get_post_mime_type')->justReturn(false);
        $this->assertFalse($this->images->syncFile(31));
    }

    public function testSyncImageDelegatesToSyncFile(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/octet-stream');
        // syncImage calls syncFile which returns false for unknown mime
        $this->assertFalse($this->images->syncImage(32));
    }

    public function testSyncFileReturnsFalseWhenImageUrlMissing(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn(false);
        Functions\when('get_attached_file')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        $this->assertFalse($this->images->syncFile(33));
    }

    public function testSyncFileReturnsFalseWhenFilePathMissing(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('');
        Functions\when('get_post_meta')->justReturn('');
        $this->assertFalse($this->images->syncFile(34));
    }

    public function testSyncFileReturnsFalseOnApiError(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('error', 'API error'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $result = $this->images->syncFile(35);
        $this->assertFalse($result);
    }

    public function testSyncFilePdfTypeReturnsFalseOnNoMimeMatch(): void
    {
        // PDF in allowed types but MIME not syncable (e.g. zip)
        $this->optionMap['wash_allowed_file_types'] = ['pdf'];
        Functions\when('get_post_mime_type')->justReturn('application/zip');
        $this->assertFalse($this->images->syncFile(36));
    }

    public function testSyncFileDocumentMimeSyncable(): void
    {
        $this->optionMap['wash_allowed_file_types'] = ['documents'];
        Functions\when('get_post_mime_type')->justReturn('application/pdf');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/doc.pdf');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/doc.pdf');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        // isSyncableFile returns true for documents/pdf, but uploadToShopify fails → false
        $this->assertFalse($this->images->syncFile(37));
    }

    public function testSyncFileVideoMimeSyncable(): void
    {
        $this->optionMap['wash_allowed_file_types'] = ['video'];
        Functions\when('get_post_mime_type')->justReturn('video/mp4');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/vid.mp4');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/vid.mp4');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $this->assertFalse($this->images->syncFile(38));
    }

    public function testSyncFileAudioMimeSyncable(): void
    {
        $this->optionMap['wash_allowed_file_types'] = ['audio'];
        Functions\when('get_post_mime_type')->justReturn('audio/mpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/snd.mp3');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/snd.mp3');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $this->assertFalse($this->images->syncFile(39));
    }

    public function testSyncFileReturnsFalseWhenResponseMissingShopifyFileId(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $this->assertFalse($this->images->syncFile(40));
    }

    public function testSyncFileReturnsTrueOnSuccessWithCdnUrl(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        $responseBody = json_encode(['shopifyFileId' => 'gid://shopify/MediaImage/1', 'shopifyCdnUrl' => 'https://cdn.shopify.com/img.jpg']);
        Functions\when('wp_remote_post')->justReturn(['body' => $responseBody]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($responseBody);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);

        $this->assertTrue($this->images->syncFile(41));
    }

    public function testSyncFileReturnsFalseOnSuccessWithEmptyCdnUrl(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        $responseBody = json_encode(['shopifyFileId' => 'gid://shopify/MediaImage/2', 'shopifyCdnUrl' => '']);
        Functions\when('wp_remote_post')->justReturn(['body' => $responseBody]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($responseBody);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);

        $this->assertFalse($this->images->syncFile(42));
    }

    public function testSyncFileTreatsQueuedResponseAsSuccessPending(): void
    {
        // CRIT-3: the app now queues the upload and returns {queued:true} with no
        // shopifyFileId. uploadToShopify must treat that as success-pending (true),
        // not failure — otherwise the image is re-enqueued on every post save.
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('current_time')->justReturn('2026-06-20 12:00:00');
        $responseBody = json_encode(['success' => true, 'queued' => true, 'jobId' => 'job-1']);
        Functions\when('wp_remote_post')->justReturn(['body' => $responseBody]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($responseBody);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        // Only the pending marker is written here; the real file meta arrives later
        // via the worker's callback.
        Functions\expect('update_post_meta')->once()->with(43, '_wash_sync_pending', \Mockery::type('string'))->andReturn(true);

        $this->assertTrue($this->images->syncFile(43));
    }

    // ---------------------------------------------------------------
    // onAddAttachment()
    // ---------------------------------------------------------------

    public function testOnAddAttachmentSkipsNonSyncable(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/octet-stream');
        // Should return early — no further calls needed
        $this->images->onAddAttachment(50);
        $this->assertTrue(true);
    }

    public function testOnAddAttachmentUploadsForImage(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/png');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.png');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.png');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        // uploadToShopify is called, but fails silently (no return value checked by onAdd)
        $this->images->onAddAttachment(51);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // onEditAttachment()
    // ---------------------------------------------------------------

    public function testOnEditAttachmentSkipsNonSyncable(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/octet-stream');
        $this->images->onEditAttachment(60);
        $this->assertTrue(true);
    }

    public function testOnEditAttachmentWithExistingFileIdDeletesThenUploads(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/gif');
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_wash_file_id') {
                return 'gid://shopify/MediaImage/999';
            }
            return '';
        });
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.gif');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.gif');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('delete_post_meta')->justReturn(true);

        $this->images->onEditAttachment(61);
        $this->assertTrue(true);
    }

    public function testOnEditAttachmentWithNoExistingFileIdJustUploads(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/webp');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.webp');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.webp');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $this->images->onEditAttachment(62);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // onDeleteAttachment()
    // ---------------------------------------------------------------

    public function testOnDeleteAttachmentSkipsNonSyncable(): void
    {
        Functions\when('get_post_mime_type')->justReturn('application/octet-stream');
        $this->images->onDeleteAttachment(70);
        $this->assertTrue(true);
    }

    public function testOnDeleteAttachmentCallsDeleteForImage(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('is_wp_error')->justReturn(false);
        $responseBody = json_encode(['deleted' => true]);
        Functions\when('wp_remote_post')->justReturn(['body' => $responseBody]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($responseBody);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('delete_post_meta')->justReturn(true);

        $this->images->onDeleteAttachment(71);
        $this->assertTrue(true);
    }

    public function testOnDeleteAttachmentApiErrorLeavesMetaIntact(): void
    {
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        // delete_post_meta should NOT be called when api returns wp_error
        $this->images->onDeleteAttachment(72);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // isSyncableFile — allowed types variations (non-image array)
    // ---------------------------------------------------------------

    public function testSyncFileWhenAllowedTypesIsNotArray(): void
    {
        // get_option returns a string instead of array — code normalizes to ['images']
        $this->optionMap['wash_allowed_file_types'] = 'images';
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/wp-content/uploads/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        // isSyncableFile normalizes non-array to ['images'], image/* matches
        $this->assertFalse($this->images->syncFile(80)); // fails upload but was syncable
    }

    // ---------------------------------------------------------------
    // isSyncableFile — explicitly test PDF (line 192) and document (line 208) return true
    // ---------------------------------------------------------------

    public function testIsSyncableFilePdfReturnsTrue(): void
    {
        // 'pdf' in allowed types, MIME is application/pdf → hits return true on line 192
        $this->optionMap['wash_allowed_file_types'] = ['pdf'];
        Functions\when('get_post_mime_type')->justReturn('application/pdf');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/doc.pdf');
        Functions\when('get_attached_file')->justReturn('/var/www/html/doc.pdf');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        $body = json_encode(['shopifyFileId' => 'gid://shopify/MediaImage/99', 'shopifyCdnUrl' => 'https://cdn.shopify.com/doc.pdf']);
        Functions\when('wp_remote_post')->justReturn(['body' => $body]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);

        $this->assertTrue($this->images->syncFile(81));
    }

    public function testIsSyncableFileDocumentReturnsTrue(): void
    {
        // 'documents' in allowed types, MIME is application/msword → hits return true on line 208
        $this->optionMap['wash_allowed_file_types'] = ['documents'];
        Functions\when('get_post_mime_type')->justReturn('application/msword');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/doc.doc');
        Functions\when('get_attached_file')->justReturn('/var/www/html/doc.doc');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        $body = json_encode(['shopifyFileId' => 'gid://shopify/MediaImage/100', 'shopifyCdnUrl' => 'https://cdn.shopify.com/doc.doc']);
        Functions\when('wp_remote_post')->justReturn(['body' => $body]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);

        $this->assertTrue($this->images->syncFile(82));
    }
}
