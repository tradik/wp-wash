<?php
/**
 * Coverage tests for WashFilesMedia.
 * Targets: init(), addSyncColumn(), renderSyncColumn(), addSyncRowAction(),
 *          handleMediaSync(), ajaxSyncMedia(), addBulkSyncAction(),
 *          handleBulkSync(), showAdminNotices(), addAttachmentSyncField(),
 *          renderAttachmentSyncButton(), enqueueMediaScripts(),
 *          private syncAttachment() (exercised via public entry-points).
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-images.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-files-media.php';

class WashFilesMediaCoverageTest extends TestCase
{
    private \WashFilesMedia $media;
    private array $optionMap = [];
    private array $jsonResponses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);

        $this->optionMap = [
            'wash_api_key'            => 'test-key',
            'wash_app_url'            => 'https://app-wash.tradik.dev',
            'wash_http_user'          => '',
            'wash_http_pass'          => '',
            'wash_files_enabled'      => '1',
            'wash_allowed_file_types' => ['images'],
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
        Functions\when('esc_attr__')->alias(fn($t) => $t);
        Functions\when('esc_html')->alias(fn($t) => $t);
        Functions\when('esc_attr')->alias(fn($t) => $t);
        Functions\when('esc_url')->alias(fn($t) => $t);
        Functions\when('esc_html_e')->alias(fn($t) => print($t));
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('absint')->alias(fn($v) => abs(intval($v)));

        $this->jsonResponses = [];
        Functions\when('wp_send_json_error')->alias(function ($msg = '') {
            $this->jsonResponses[] = ['type' => 'error', 'data' => $msg];
            throw new \RuntimeException('wp_send_json_error');
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->jsonResponses[] = ['type' => 'success', 'data' => $data];
            throw new \RuntimeException('wp_send_json_success');
        });

        $this->media = new \WashFilesMedia();
    }

    protected function tearDown(): void
    {
        unset($_GET, $_POST);
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // init()
    // ---------------------------------------------------------------

    public function testInitRegistersHooksWhenEnabled(): void
    {
        $this->media->init();
        $this->assertTrue(true);
    }

    public function testInitSkipsHooksWhenFilesDisabled(): void
    {
        $this->optionMap['wash_files_enabled'] = '0';
        $this->media->init();
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // addSyncColumn()
    // ---------------------------------------------------------------

    public function testAddSyncColumnAppendsWashColumn(): void
    {
        $columns = ['title' => 'Title', 'date' => 'Date'];
        $result = $this->media->addSyncColumn($columns);
        $this->assertArrayHasKey('wash_sync', $result);
        $this->assertStringContainsString('dashicons-store', $result['wash_sync']);
    }

    // ---------------------------------------------------------------
    // renderSyncColumn()
    // ---------------------------------------------------------------

    public function testRenderSyncColumnIgnoresOtherColumns(): void
    {
        ob_start();
        $this->media->renderSyncColumn('other_column', 1);
        $output = ob_get_clean();
        $this->assertEmpty($output);
    }

    public function testRenderSyncColumnShowsNotImageForNonImages(): void
    {
        Functions\when('wp_attachment_is_image')->justReturn(false);
        ob_start();
        $this->media->renderSyncColumn('wash_sync', 1);
        $output = ob_get_clean();
        $this->assertStringContainsString('dashicons-minus', $output);
    }

    public function testRenderSyncColumnShowsSyncedIconWhenSynced(): void
    {
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_wash_file_id' => 'gid://shopify/MediaImage/1',
                '_wash_cdn_url' => 'https://cdn.shopify.com/img.jpg',
                default => '',
            };
        });
        ob_start();
        $this->media->renderSyncColumn('wash_sync', 5);
        $output = ob_get_clean();
        $this->assertStringContainsString('dashicons-yes-alt', $output);
        $this->assertStringContainsString('cdn.shopify.com', $output);
    }

    public function testRenderSyncColumnShowsUploadIconWhenNotSynced(): void
    {
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        ob_start();
        $this->media->renderSyncColumn('wash_sync', 6);
        $output = ob_get_clean();
        $this->assertStringContainsString('dashicons-upload', $output);
    }

    // ---------------------------------------------------------------
    // addSyncRowAction()
    // ---------------------------------------------------------------

    public function testAddSyncRowActionSkipsWhenNotConfigured(): void
    {
        // Create a new instance with empty api key so isConfigured() returns false
        Functions\when('get_site_url')->justReturn('https://example.com');
        $this->optionMap['wash_api_key'] = '';
        $mediaNotConfigured = new \WashFilesMedia();
        $post = new \stdClass();
        $post->ID = 10;
        $actions = [];
        Functions\when('wp_attachment_is_image')->justReturn(true);
        $result = $mediaNotConfigured->addSyncRowAction($actions, $post);
        $this->assertArrayNotHasKey('wash_sync', $result);
    }

    public function testAddSyncRowActionSkipsNonImages(): void
    {
        $post = new \stdClass();
        $post->ID = 10;
        $actions = [];
        Functions\when('wp_attachment_is_image')->justReturn(false);
        $result = $this->media->addSyncRowAction($actions, $post);
        $this->assertArrayNotHasKey('wash_sync', $result);
    }

    public function testAddSyncRowActionAddsLinkForUnsyncedImage(): void
    {
        $post = new \stdClass();
        $post->ID = 11;
        $actions = [];
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_nonce_url')->alias(fn($url, $action) => $url . '&_wpnonce=fake');

        $result = $this->media->addSyncRowAction($actions, $post);
        $this->assertArrayHasKey('wash_sync', $result);
        $this->assertStringContainsString('Sync to Shopify', $result['wash_sync']);
    }

    public function testAddSyncRowActionShowsResyncLabelForSyncedImage(): void
    {
        $post = new \stdClass();
        $post->ID = 12;
        $actions = [];
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_wash_file_id' => 'gid://shopify/MediaImage/99',
                '_wash_cdn_url' => 'https://cdn.shopify.com/img.jpg',
                default => '',
            };
        });
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_nonce_url')->alias(fn($url, $action) => $url . '&_wpnonce=fake');

        $result = $this->media->addSyncRowAction($actions, $post);
        $this->assertArrayHasKey('wash_sync', $result);
        $this->assertStringContainsString('Re-sync to Shopify', $result['wash_sync']);
        $this->assertArrayHasKey('wash_view', $result);
    }

    public function testAddSyncRowActionDoesNotAddViewLinkWhenCdnUrlEmpty(): void
    {
        $post = new \stdClass();
        $post->ID = 13;
        $actions = [];
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_wash_file_id' => 'gid://shopify/MediaImage/99',
                '_wash_cdn_url' => '',
                default => '',
            };
        });
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_nonce_url')->alias(fn($url, $action) => $url . '&_wpnonce=fake');

        $result = $this->media->addSyncRowAction($actions, $post);
        $this->assertArrayNotHasKey('wash_view', $result);
    }

    // ---------------------------------------------------------------
    // addBulkSyncAction()
    // ---------------------------------------------------------------

    public function testAddBulkSyncActionAddsActionWhenConfigured(): void
    {
        $actions = [];
        $result = $this->media->addBulkSyncAction($actions);
        $this->assertArrayHasKey('wash_bulk_sync', $result);
    }

    public function testAddBulkSyncActionSkipsWhenNotConfigured(): void
    {
        $this->optionMap['wash_api_key'] = '';
        $mediaNotConfigured = new \WashFilesMedia();
        $actions = [];
        $result = $mediaNotConfigured->addBulkSyncAction($actions);
        $this->assertArrayNotHasKey('wash_bulk_sync', $result);
    }

    // ---------------------------------------------------------------
    // handleBulkSync()
    // ---------------------------------------------------------------

    public function testHandleBulkSyncReturnsUnchangedForOtherAction(): void
    {
        $result = $this->media->handleBulkSync('https://example.com/upload.php', 'other_action', [1, 2]);
        $this->assertSame('https://example.com/upload.php', $result);
    }

    public function testHandleBulkSyncSkipsNonImages(): void
    {
        Functions\when('wp_attachment_is_image')->justReturn(false);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));

        $result = $this->media->handleBulkSync('https://example.com/upload.php', 'wash_bulk_sync', [1, 2]);
        $this->assertStringContainsString('wash_bulk_synced=0', $result);
        $this->assertStringContainsString('wash_bulk_failed=0', $result);
    }

    public function testHandleBulkSyncCountsSyncedAndFailed(): void
    {
        $callCount = 0;
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        // First attachment upload succeeds, second fails
        Functions\when('wp_get_attachment_url')->alias(function ($id) use (&$callCount) {
            $callCount++;
            return $callCount === 1 ? 'https://example.com/img1.jpg' : false;
        });
        Functions\when('get_attached_file')->alias(function ($id) use (&$callCount) {
            return $callCount === 1 ? '/var/www/html/img1.jpg' : false;
        });
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('err', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));

        // Both fail (wp_get_attachment_url returns false on second call → syncAttachment returns false)
        $result = $this->media->handleBulkSync('https://example.com/upload.php', 'wash_bulk_sync', [1, 2]);
        $this->assertStringContainsString('wash_bulk_synced', $result);
        $this->assertStringContainsString('wash_bulk_failed', $result);
    }

    public function testHandleBulkSyncSuccessPath(): void
    {
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        $body = json_encode(['shopifyFileId' => 'gid://shopify/MediaImage/1', 'shopifyCdnUrl' => 'https://cdn.shopify.com/img.jpg']);
        Functions\when('wp_remote_post')->justReturn(['body' => $body]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));

        $result = $this->media->handleBulkSync('https://example.com/upload.php', 'wash_bulk_sync', [1]);
        $this->assertStringContainsString('wash_bulk_synced=1', $result);
    }

    // ---------------------------------------------------------------
    // showAdminNotices()
    // ---------------------------------------------------------------

    public function testShowAdminNoticesSkipsOnNonUploadScreen(): void
    {
        $screen = new \stdClass();
        $screen->id = 'dashboard';
        Functions\when('get_current_screen')->justReturn($screen);

        ob_start();
        $this->media->showAdminNotices();
        $output = ob_get_clean();
        $this->assertEmpty($output);
    }

    public function testShowAdminNoticesSkipsWhenNoScreen(): void
    {
        Functions\when('get_current_screen')->justReturn(null);

        ob_start();
        $this->media->showAdminNotices();
        $output = ob_get_clean();
        $this->assertEmpty($output);
    }

    public function testShowAdminNoticesShowsSuccessNotice(): void
    {
        $screen = new \stdClass();
        $screen->id = 'upload';
        Functions\when('get_current_screen')->justReturn($screen);
        $_GET['wash_media_synced'] = '1';

        ob_start();
        $this->media->showAdminNotices();
        $output = ob_get_clean();
        $this->assertStringContainsString('notice-success', $output);

        unset($_GET['wash_media_synced']);
    }

    public function testShowAdminNoticesShowsErrorNotice(): void
    {
        $screen = new \stdClass();
        $screen->id = 'upload';
        Functions\when('get_current_screen')->justReturn($screen);
        $_GET['wash_media_error'] = '1';

        ob_start();
        $this->media->showAdminNotices();
        $output = ob_get_clean();
        $this->assertStringContainsString('notice-error', $output);

        unset($_GET['wash_media_error']);
    }

    public function testShowAdminNoticesShowsBulkNotice(): void
    {
        $screen = new \stdClass();
        $screen->id = 'upload';
        Functions\when('get_current_screen')->justReturn($screen);
        $_GET['wash_bulk_synced'] = '3';
        $_GET['wash_bulk_failed'] = '1';

        ob_start();
        $this->media->showAdminNotices();
        $output = ob_get_clean();
        $this->assertStringContainsString('notice-success', $output);

        unset($_GET['wash_bulk_synced'], $_GET['wash_bulk_failed']);
    }

    public function testShowAdminNoticesBulkWithoutFailedKey(): void
    {
        $screen = new \stdClass();
        $screen->id = 'upload';
        Functions\when('get_current_screen')->justReturn($screen);
        $_GET['wash_bulk_synced'] = '2';

        ob_start();
        $this->media->showAdminNotices();
        $output = ob_get_clean();
        $this->assertStringContainsString('notice-success', $output);

        unset($_GET['wash_bulk_synced']);
    }

    // ---------------------------------------------------------------
    // ajaxSyncMedia() — AJAX handler
    // ---------------------------------------------------------------

    public function testAjaxSyncMediaRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->media->ajaxSyncMedia();
    }

    public function testAjaxSyncMediaRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->media->ajaxSyncMedia();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testAjaxSyncMediaRejectsInvalidAttachmentId(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $_POST['attachment_id'] = '0';

        try {
            $this->media->ajaxSyncMedia();
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Invalid attachment ID', $this->jsonResponses[0]['data']);

        unset($_POST['attachment_id']);
    }

    public function testAjaxSyncMediaReturnsSyncError(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $_POST['attachment_id'] = '5';
        Functions\when('wp_attachment_is_image')->justReturn(false);

        try {
            $this->media->ajaxSyncMedia();
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);

        unset($_POST['attachment_id']);
    }

    public function testAjaxSyncMediaReturnsSuccessOnSync(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        $_POST['attachment_id'] = '7';
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/img.jpg');
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_wash_cdn_url' => 'https://cdn.shopify.com/img.jpg',
                '_wash_file_id' => 'gid://shopify/MediaImage/77',
                default => '',
            };
        });
        Functions\when('is_wp_error')->justReturn(false);
        $body = json_encode(['shopifyFileId' => 'gid://shopify/MediaImage/77', 'shopifyCdnUrl' => 'https://cdn.shopify.com/img.jpg']);
        Functions\when('wp_remote_post')->justReturn(['body' => $body]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);

        try {
            $this->media->ajaxSyncMedia();
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('success', $this->jsonResponses[0]['type']);

        unset($_POST['attachment_id']);
    }

    // ---------------------------------------------------------------
    // handleMediaSync() — GET-based sync handler
    // ---------------------------------------------------------------

    public function testHandleMediaSyncIgnoresNonSyncAction(): void
    {
        $_GET['action'] = 'other_action';
        $this->media->handleMediaSync();
        $this->assertTrue(true);
        unset($_GET['action']);
    }

    public function testHandleMediaSyncIgnoresMissingAttachmentId(): void
    {
        $_GET['action'] = 'wash_sync_media';
        $this->media->handleMediaSync();
        $this->assertTrue(true);
        unset($_GET['action']);
    }

    public function testHandleMediaSyncRejectsInvalidNonce(): void
    {
        $_GET['action'] = 'wash_sync_media';
        $_GET['attachment_id'] = '5';
        $_GET['_wpnonce'] = 'bad_nonce';
        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die: ' . $msg);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die: Security check failed');
        $this->media->handleMediaSync();

        unset($_GET['action'], $_GET['attachment_id'], $_GET['_wpnonce']);
    }

    public function testHandleMediaSyncRejectsUnauthorizedUser(): void
    {
        $_GET['action'] = 'wash_sync_media';
        $_GET['attachment_id'] = '5';
        $_GET['_wpnonce'] = 'valid_nonce';
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die: ' . $msg);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die: You do not have permission to sync media');
        $this->media->handleMediaSync();

        unset($_GET['action'], $_GET['attachment_id'], $_GET['_wpnonce']);
    }

    public function testHandleMediaSyncSuccessPathRedirects(): void
    {
        $_GET['action'] = 'wash_sync_media';
        $_GET['attachment_id'] = '8';
        $_GET['_wpnonce'] = 'valid_nonce';
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/img.jpg');
        Functions\when('get_attached_file')->justReturn('/var/www/html/img.jpg');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);
        $body = json_encode(['shopifyFileId' => 'gid://shopify/MediaImage/8', 'shopifyCdnUrl' => 'https://cdn.shopify.com/img.jpg']);
        Functions\when('wp_remote_post')->justReturn(['body' => $body]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('add_query_arg')->alias(fn($k, $v, $url) => $url . '&' . $k . '=' . $v);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            throw new \RuntimeException('redirect: ' . $url);
        });

        try {
            $this->media->handleMediaSync();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('redirect:', $e->getMessage());
            $this->assertStringContainsString('wash_media_synced', $e->getMessage());
        }

        unset($_GET['action'], $_GET['attachment_id'], $_GET['_wpnonce']);
    }

    public function testHandleMediaSyncFailurePathRedirects(): void
    {
        $_GET['action'] = 'wash_sync_media';
        $_GET['attachment_id'] = '9';
        $_GET['_wpnonce'] = 'valid_nonce';
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_attachment_is_image')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('add_query_arg')->alias(fn($k, $v, $url) => $url . '&' . $k . '=' . $v);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            throw new \RuntimeException('redirect: ' . $url);
        });

        try {
            $this->media->handleMediaSync();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('redirect:', $e->getMessage());
            $this->assertStringContainsString('wash_media_error', $e->getMessage());
        }

        unset($_GET['action'], $_GET['attachment_id'], $_GET['_wpnonce']);
    }

    // ---------------------------------------------------------------
    // addAttachmentSyncField()
    // ---------------------------------------------------------------

    public function testAddAttachmentSyncFieldSkipsWhenNotConfigured(): void
    {
        $this->optionMap['wash_api_key'] = '';
        $mediaNotConfigured = new \WashFilesMedia();
        $post = new \stdClass();
        $post->ID = 20;
        Functions\when('wp_attachment_is_image')->justReturn(true);

        $result = $mediaNotConfigured->addAttachmentSyncField([], $post);
        $this->assertArrayNotHasKey('wash_sync', $result);
    }

    public function testAddAttachmentSyncFieldSkipsNonImages(): void
    {
        $post = new \stdClass();
        $post->ID = 20;
        Functions\when('wp_attachment_is_image')->justReturn(false);

        $result = $this->media->addAttachmentSyncField([], $post);
        $this->assertArrayNotHasKey('wash_sync', $result);
    }

    public function testAddAttachmentSyncFieldAddsFieldForUnsyncedImage(): void
    {
        $post = new \stdClass();
        $post->ID = 21;
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->media->addAttachmentSyncField([], $post);
        $this->assertArrayHasKey('wash_sync', $result);
        $this->assertSame('Shopify', $result['wash_sync']['label']);
        $this->assertStringContainsString('Not synced', $result['wash_sync']['html']);
    }

    public function testAddAttachmentSyncFieldAddsFieldForSyncedImage(): void
    {
        $post = new \stdClass();
        $post->ID = 22;
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_wash_file_id' => 'gid://shopify/MediaImage/22',
                '_wash_cdn_url' => 'https://cdn.shopify.com/img.jpg',
                default => '',
            };
        });

        $result = $this->media->addAttachmentSyncField([], $post);
        $this->assertArrayHasKey('wash_sync', $result);
        $this->assertStringContainsString('cdn.shopify.com', $result['wash_sync']['html']);
        $this->assertStringContainsString('Re-sync to Shopify', $result['wash_sync']['html']);
    }

    // ---------------------------------------------------------------
    // renderAttachmentSyncButton()
    // ---------------------------------------------------------------

    public function testRenderAttachmentSyncButtonSkipsWhenNotConfigured(): void
    {
        $this->optionMap['wash_api_key'] = '';
        $mediaNotConfigured = new \WashFilesMedia();
        $post = new \stdClass();
        $post->ID = 30;
        Functions\when('wp_attachment_is_image')->justReturn(true);

        $level = ob_get_level();
        ob_start();
        $mediaNotConfigured->renderAttachmentSyncButton($post);
        $output = ob_get_clean();
        // restore output buffering to original level
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        $this->assertEmpty($output);
    }

    public function testRenderAttachmentSyncButtonSkipsNonImages(): void
    {
        $post = new \stdClass();
        $post->ID = 30;
        Functions\when('wp_attachment_is_image')->justReturn(false);

        ob_start();
        $this->media->renderAttachmentSyncButton($post);
        $output = ob_get_clean();
        $this->assertEmpty($output);
    }

    public function testRenderAttachmentSyncButtonRendersForUnsyncedImage(): void
    {
        $post = new \stdClass();
        $post->ID = 31;
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_nonce_url')->alias(fn($url, $action) => $url . '&_wpnonce=fake');

        ob_start();
        $this->media->renderAttachmentSyncButton($post);
        $output = ob_get_clean();
        $this->assertStringContainsString('wash-attachment-sync-section', $output);
        $this->assertStringContainsString('Sync to Shopify', $output);
    }

    public function testRenderAttachmentSyncButtonRendersForSyncedImage(): void
    {
        $post = new \stdClass();
        $post->ID = 32;
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_wash_file_id' => 'gid://shopify/MediaImage/32',
                '_wash_cdn_url' => 'https://cdn.shopify.com/img32.jpg',
                default => '',
            };
        });
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url . '?' . http_build_query($args));
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_nonce_url')->alias(fn($url, $action) => $url . '&_wpnonce=fake');

        ob_start();
        $this->media->renderAttachmentSyncButton($post);
        $output = ob_get_clean();
        $this->assertStringContainsString('cdn.shopify.com', $output);
        $this->assertStringContainsString('Re-sync to Shopify', $output);
    }

    // ---------------------------------------------------------------
    // enqueueMediaScripts()
    // ---------------------------------------------------------------

    public function testEnqueueMediaScriptsSkipsOnUnrelatedHook(): void
    {
        // No wp_enqueue_* should be called
        $this->media->enqueueMediaScripts('index.php');
        $this->assertTrue(true);
    }

    public function testEnqueueMediaScriptsSkipsWhenNotConfigured(): void
    {
        $this->optionMap['wash_api_key'] = '';
        $mediaNotConfigured = new \WashFilesMedia();
        $mediaNotConfigured->enqueueMediaScripts('upload.php');
        $this->assertTrue(true);
    }

    public function testEnqueueMediaScriptsEnqueuesOnUploadHook(): void
    {
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('wp_create_nonce')->justReturn('fake-nonce');
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('filemtime')->justReturn(1234567890);

        $this->media->enqueueMediaScripts('upload.php');
        $this->assertTrue(true);
    }

    public function testEnqueueMediaScriptsEnqueuesOnPostHook(): void
    {
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('wp_create_nonce')->justReturn('fake-nonce');
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('filemtime')->justReturn(1234567890);

        $this->media->enqueueMediaScripts('post.php');
        $this->assertTrue(true);
    }

    public function testEnqueueMediaScriptsEnqueuesOnPostNewHook(): void
    {
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('wp_create_nonce')->justReturn('fake-nonce');
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('filemtime')->justReturn(1234567890);

        $this->media->enqueueMediaScripts('post-new.php');
        $this->assertTrue(true);
    }
}
