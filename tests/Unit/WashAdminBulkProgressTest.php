<?php
/**
 * Tests for WashAdmin bulk-publish dispatch logic added in v1.22.0:
 * - handleBulkActions pre-validation (skip rules per action) and routing
 *   (inline mode redirects to progress page; cron mode schedules events).
 * - clearStaleErrors auto-cleanup cron handler.
 * - ajaxBulkSyncBatch security gates.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';
// processBulkPost() constructs a WashSync for publish/republish; load it so the
// happy-path batch test can exercise the real (network-free) syncManually guard.
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

/**
 * Test double that replaces the per-post sync/unpublish work with a scripted
 * outcome, so the ajaxBulkSyncBatch() loop (counting, time budget, error
 * isolation) can be unit-tested without the full Shopify sync stack (#253).
 */
class WashAdminBulkBatchStub extends \WashAdmin
{
    /** @var callable fn(string $action, int $postId, object $post): true|string|throws */
    public $behavior;
    /** @var array<int, array{0:string,1:int}> */
    public array $calls = [];

    protected function processBulkPost($action, $postId, $post)
    {
        $this->calls[] = [$action, $postId];
        return ($this->behavior)($action, $postId, $post);
    }
}

class WashAdminBulkProgressTest extends TestCase
{
    private \WashAdmin $admin;
    private array $transients = [];
    private array $jsonResponses = [];

    private array $posts = [];
    private array $postMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        $this->posts = [];
        $this->postMeta = [];
        $this->transients = [];
        $this->jsonResponses = [];

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                'wash_post_types' => 'post,page',
                'wash_sync_inline' => $this->inlineMode ?? '1',
                'wash_auto_clear_errors' => $this->autoClearMode ?? '1',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('__')->alias(fn($t) => $t);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('sanitize_key')->alias(fn($v) => preg_replace('/[^a-z0-9_-]/i', '', (string) $v));
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . ltrim($p, '/'));
        Functions\when('add_query_arg')->alias(function (...$a) {
            // Two signatures: (array $args, string $url) OR (string $key, string $value, string $url)
            if (count($a) === 3 && is_string($a[0])) {
                [$key, $value, $url] = $a;
                $args = [$key => $value];
            } else {
                [$args, $url] = $a;
            }
            $sep = strpos($url, '?') === false ? '?' : '&';
            return $url . $sep . http_build_query($args);
        });
        Functions\when('wp_generate_password')->justReturn('TESTTOKEN1234567890Z');
        Functions\when('current_time')->justReturn('2026-05-03 12:00:00');
        Functions\when('apply_filters')->alias(fn($_, $v) => $v);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('get_post')->alias(fn($id) => $this->posts[(int) $id] ?? null);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single = false) {
            return $this->postMeta[(int) $id][$key] ?? '';
        });

        Functions\when('set_transient')->alias(function ($k, $v, $ttl) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });

        Functions\when('wp_send_json_error')->alias(function ($msg = '') {
            $this->jsonResponses[] = ['type' => 'error', 'data' => $msg];
            throw new \RuntimeException('wp_send_json_error');
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->jsonResponses[] = ['type' => 'success', 'data' => $data];
            throw new \RuntimeException('wp_send_json_success');
        });

        $this->admin = new \WashAdmin();
    }

    private ?string $inlineMode = null;
    private ?string $autoClearMode = null;

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mockPost(int $id, string $type = 'post', string $status = 'publish', string $articleId = ''): void
    {
        $this->posts[$id] = (object) [
            'ID' => $id,
            'post_type' => $type,
            'post_status' => $status,
        ];
        if ($articleId !== '') {
            $this->postMeta[$id]['_wash_article_id'] = $articleId;
        }
    }

    // ---------------- handleBulkActions ----------------

    public function testHandleBulkActionsPassesThroughUnknownAction(): void
    {
        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'trash', [1, 2]);
        $this->assertSame($url, $result);
    }

    public function testHandleBulkActionsReturnsNotConfiguredErrorWhenApiKeyMissing(): void
    {
        // Re-create WashAdmin AFTER nuking get_option, so the inner WashApiClient
        // picks up the empty config (it caches options at construction time).
        Functions\when('get_option')->alias(fn($k, $d = '') => '');
        $admin = new \WashAdmin();

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $admin->handleBulkActions($url, 'wash_publish', [1]);
        $this->assertStringContainsString('wash_bulk_error=not_configured', $result);
    }

    public function testHandleBulkActionsRedirectsToProgressPageWhenInlineModeAndAtLeastOneValidPost(): void
    {
        $this->inlineMode = '1';
        $this->mockPost(10);
        $this->mockPost(20);

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'wash_publish', [10, 20]);

        $this->assertStringContainsString('page=wash-bulk-progress', $result);
        $this->assertStringContainsString('token=TESTTOKEN1234567890Z', $result);

        $stored = $this->transients['wash_bulk_TESTTOKEN1234567890Z'] ?? null;
        $this->assertIsArray($stored);
        $this->assertSame('wash_publish', $stored['action']);
        $this->assertSame([10, 20], $stored['postIds']);
        $this->assertSame(0, $stored['skipped']);
    }

    public function testHandleBulkActionsPublishesSyncedAndUnsyncedForPublish(): void
    {
        // Publish is an upsert now (#255): it no longer SKIPS already-synced posts —
        // both the synced (10) and the never-synced (20) post are processed.
        $this->inlineMode = '1';
        $this->mockPost(10, 'post', 'publish', 'gid://shopify/Article/X');
        $this->mockPost(20);

        $url = 'https://example.com/wp-admin/edit.php';
        $this->admin->handleBulkActions($url, 'wash_publish', [10, 20]);

        $stored = $this->transients['wash_bulk_TESTTOKEN1234567890Z'] ?? null;
        $this->assertSame([10, 20], $stored['postIds']);
        $this->assertSame(0, $stored['skipped']);
    }

    public function testHandleBulkActionsReturnsNoticeUrlWhenAllPostsSkipped(): void
    {
        // Drafts can't be published to Shopify → both skipped → notice, no progress page.
        $this->inlineMode = '1';
        $this->mockPost(10, 'post', 'draft');
        $this->mockPost(20, 'post', 'draft');

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'wash_publish', [10, 20]);

        $this->assertStringContainsString('wash_bulk_processed=0', $result);
        $this->assertStringContainsString('wash_bulk_skipped=2', $result);
        $this->assertStringNotContainsString('page=wash-bulk-progress', $result);
        $this->assertArrayNotHasKey('wash_bulk_TESTTOKEN1234567890Z', $this->transients);
    }

    public function testHandleBulkActionsSkipsDraftsForPublish(): void
    {
        $this->inlineMode = '1';
        $this->mockPost(10, 'post', 'draft');

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'wash_publish', [10]);

        $this->assertStringContainsString('wash_bulk_skipped=1', $result);
        $this->assertStringNotContainsString('page=wash-bulk-progress', $result);
    }

    public function testHandleBulkActionsSkipsNeverSyncedPostsForRepublish(): void
    {
        $this->inlineMode = '1';
        $this->mockPost(10);  // no _wash_article_id

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'wash_republish', [10]);

        $this->assertStringContainsString('wash_bulk_skipped=1', $result);
    }

    public function testHandleBulkActionsSkipsNeverSyncedPostsForUnpublish(): void
    {
        $this->inlineMode = '1';
        $this->mockPost(10);

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'wash_unpublish', [10]);

        $this->assertStringContainsString('wash_bulk_skipped=1', $result);
    }

    public function testHandleBulkActionsSkipsDisallowedPostTypes(): void
    {
        $this->inlineMode = '1';
        $this->mockPost(10, 'product'); // not in allowedTypes (post,page)

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'wash_publish', [10]);

        $this->assertStringContainsString('wash_bulk_skipped=1', $result);
        $this->assertStringNotContainsString('page=wash-bulk-progress', $result);
    }

    // ---------------- renderBulkProgressPage: enqueue (wp.org compliance) ----------------

    public function testRenderBulkProgressPageEnqueuesScriptInsteadOfInlineTag(): void
    {
        $_GET['token'] = 'TOKEN123';
        $this->transients['wash_bulk_TOKEN123'] = [
            'action' => 'wash_publish',
            'postIds' => [11, 22, 33],
            'skipped' => 0,
            'returnUrl' => 'https://example.com/wp-admin/edit.php',
        ];

        $enqueued = [];
        $localized = [];
        Functions\when('wp_create_nonce')->justReturn('NONCEVAL');
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('wp_enqueue_script')->alias(
            function ($handle, $src = '', $deps = [], $ver = '', $footer = false) use (&$enqueued) {
                $enqueued[$handle] = compact('src', 'deps', 'ver', 'footer');
            }
        );
        Functions\when('wp_localize_script')->alias(
            function ($handle, $object, $data) use (&$localized) {
                $localized[$handle] = ['object' => $object, 'data' => $data];
            }
        );
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html_e')->alias(function ($t) { echo $t; });

        ob_start();
        $this->admin->renderBulkProgressPage();
        $html = ob_get_clean();

        // Driver is enqueued from an external file, loaded in the footer
        $this->assertArrayHasKey('wash-bulk-progress', $enqueued);
        $this->assertStringContainsString('admin/js/bulk-progress.js', $enqueued['wash-bulk-progress']['src']);
        $this->assertTrue($enqueued['wash-bulk-progress']['footer']);

        // Dynamic config travels via wp_localize_script(), not inline PHP echo
        $this->assertArrayHasKey('wash-bulk-progress', $localized);
        $this->assertSame('WashBulkProgress', $localized['wash-bulk-progress']['object']);
        $cfg = $localized['wash-bulk-progress']['data'];
        $this->assertSame('TOKEN123', $cfg['token']);
        $this->assertSame('NONCEVAL', $cfg['nonce']);
        $this->assertSame(3, $cfg['total']);
        // Per-request batch cap is handed to the JS so a failed batch can skip ahead (#253).
        $this->assertSame(10, $cfg['batchSize']);
        $this->assertArrayHasKey('i18n', $cfg);

        // The rendered page must not contain a literal inline script element
        $this->assertStringNotContainsString('<script', $html);

        unset($_GET['token']);
    }

    // ---------------- ajaxBulkSyncBatch security ----------------

    public function testAjaxBulkSyncBatchRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->admin->ajaxBulkSyncBatch();
    }

    public function testAjaxBulkSyncBatchRejectsUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_error');
        try {
            $this->admin->ajaxBulkSyncBatch();
        } catch (\RuntimeException $e) {
            $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
            throw $e;
        }
    }

    public function testAjaxBulkSyncBatchRejectsExpiredToken(): void
    {
        $_POST['token'] = 'doesnotexist';
        $_POST['offset'] = 0;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_error');
        try {
            $this->admin->ajaxBulkSyncBatch();
        } catch (\RuntimeException $e) {
            $this->assertSame('Bulk session expired', $this->jsonResponses[0]['data']);
            throw $e;
        }
    }

    // ---------------- ajaxBulkSyncBatch processing loop (#253) ----------------

    /**
     * Drive ajaxBulkSyncBatch() to its wp_send_json_success() exit and return the
     * success payload. The json mocks throw to unwind the handler (mirroring how
     * WordPress wp_send_json_* exit), so callers don't need their own try/catch.
     *
     * @return array<string,mixed>
     */
    private function runBatch(\WashAdmin $admin, string $token, array $payload, int $offset): array
    {
        $this->transients['wash_bulk_' . $token] = $payload;
        $_POST['token'] = $token;
        $_POST['offset'] = (string) $offset;
        try {
            $admin->ajaxBulkSyncBatch();
        } catch (\RuntimeException $e) {
            // expected: json mock unwinds the handler
        }
        unset($_POST['token'], $_POST['offset']);
        $last = end($this->jsonResponses);
        $this->assertSame('success', $last['type']);
        return $last['data'];
    }

    public function testAjaxBulkSyncBatchReportsDoneWhenOffsetBeyondTotal(): void
    {
        $data = $this->runBatch($this->admin, 'TOKA', ['action' => 'wash_publish', 'postIds' => [1, 2]], 5);

        $this->assertTrue($data['done']);
        $this->assertSame(0, $data['processed']);
        // Completed run clears its one-shot transient.
        $this->assertArrayNotHasKey('wash_bulk_TOKA', $this->transients);
    }

    public function testAjaxBulkSyncBatchCountsMissingPostAsError(): void
    {
        // Unpublish path: $sync stays null and a missing post never reaches the
        // network — it is recorded as a per-post error without aborting the batch.
        $data = $this->runBatch($this->admin, 'TOKB', ['action' => 'wash_unpublish', 'postIds' => [999]], 0);

        $this->assertSame(0, $data['processed']);
        $this->assertSame(1, $data['errors']);
        $this->assertSame(1, $data['nextOffset']);
        $this->assertTrue($data['done']);
        $this->assertStringContainsString('not found', $data['errorMessages'][0]);
        $this->assertArrayNotHasKey('wash_bulk_TOKB', $this->transients);
    }

    public function testAjaxBulkSyncBatchSyncsPublishablePostAndCounts(): void
    {
        // A draft short-circuits WashSync::syncManually() on its non-publish guard
        // (no network), so this exercises the real processBulkPost() glue + the
        // processed++ branch end-to-end.
        $this->mockPost(30, 'post', 'draft');
        $data = $this->runBatch($this->admin, 'TOKC', ['action' => 'wash_publish', 'postIds' => [30]], 0);

        $this->assertSame(1, $data['processed']);
        $this->assertSame(0, $data['errors']);
        $this->assertTrue($data['done']);
    }

    public function testAjaxBulkSyncBatchIsolatesAThrowingPost(): void
    {
        // A thrown error for one post must be caught and recorded against that post,
        // leaving the rest of the batch (and the overall request) intact (#253).
        $admin = new WashAdminBulkBatchStub();
        $admin->behavior = function () { throw new \RuntimeException('boom'); };
        $this->mockPost(41);

        $data = $this->runBatch($admin, 'TOKD', ['action' => 'wash_publish', 'postIds' => [41]], 0);

        $this->assertSame(0, $data['processed']);
        $this->assertSame(1, $data['errors']);
        $this->assertStringContainsString('#41', $data['errorMessages'][0]);
        $this->assertStringContainsString('boom', $data['errorMessages'][0]);
    }

    public function testAjaxBulkSyncBatchRecordsHandledFailureReason(): void
    {
        // processBulkPost() returns a reason string (not true) for a handled failure
        // (e.g. an unpublish API error) — counted as an error, with the reason shown.
        $admin = new WashAdminBulkBatchStub();
        $admin->behavior = function () { return 'API rejected'; };
        $this->mockPost(51, 'post', 'publish', 'gid://shopify/Article/1');

        $data = $this->runBatch($admin, 'TOKE', ['action' => 'wash_unpublish', 'postIds' => [51]], 0);

        $this->assertSame(0, $data['processed']);
        $this->assertSame(1, $data['errors']);
        $this->assertStringContainsString('API rejected', $data['errorMessages'][0]);
    }

    public function testAjaxBulkSyncBatchStopsAtTimeBudget(): void
    {
        // A zero-second budget must end the request after the FIRST post even though
        // two more remain — this is what keeps a 200-post run off the gateway timeout.
        Functions\when('apply_filters')->alias(
            fn($hook, $value) => $hook === 'wash_bulk_time_budget' ? 0.0 : $value
        );

        $data = $this->runBatch($this->admin, 'TOKF', ['action' => 'wash_unpublish', 'postIds' => [901, 902, 903]], 0);

        $this->assertSame(1, $data['nextOffset']);
        $this->assertFalse($data['done']);
        // Not done → transient is kept so the next AJAX batch can resume.
        $this->assertArrayHasKey('wash_bulk_TOKF', $this->transients);
    }

    public function testAjaxBulkSyncBatchHonoursBatchSizeCap(): void
    {
        // A batch-size cap of 1 stops the loop after one post regardless of the time
        // budget, so the JS drives the remainder in further requests.
        Functions\when('apply_filters')->alias(
            fn($hook, $value) => $hook === 'wash_bulk_batch_size' ? 1 : $value
        );

        $data = $this->runBatch($this->admin, 'TOKG', ['action' => 'wash_unpublish', 'postIds' => [801, 802]], 0);

        $this->assertSame(1, $data['nextOffset']);
        $this->assertFalse($data['done']);
    }

    public function testProcessBulkPostUnpublishReturnsErrorReason(): void
    {
        // The unpublish branch delegates to unpublishFromShopify(); when the API
        // call fails it must surface a human reason (not a bare false), which the
        // batch loop records against the post.
        require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_request_failed', 'connection refused'));
        if (class_exists('WashHreflangs')) {
            $p = new \ReflectionProperty('WashHreflangs', 'activePlugin');
            $p->setAccessible(true);
            $p->setValue(null, null);
        }
        $this->mockPost(60, 'post', 'publish', 'gid://shopify/Article/9');

        $ref = new \ReflectionMethod(\WashAdmin::class, 'processBulkPost');
        $ref->setAccessible(true);
        $result = $ref->invoke($this->admin, 'wash_unpublish', 60, $this->posts[60]);

        $this->assertSame('connection refused', $result);
    }

    // ---------------- clearStaleErrors ----------------

    public function testClearStaleErrorsSkipsWhenOptionDisabled(): void
    {
        $this->autoClearMode = '0';
        // No $wpdb access expected, no exceptions
        $this->admin->clearStaleErrors();
        $this->addToAssertionCount(1);
    }

    public function testClearStaleErrorsSkipsWhenRetentionDaysZero(): void
    {
        $this->autoClearMode = '1';
        Functions\when('apply_filters')->alias(function ($_, $v) {
            // Return 0 for retention, leave others as-is
            return is_int($v) && $v === 30 ? 0 : $v;
        });
        $this->admin->clearStaleErrors();
        $this->addToAssertionCount(1);
    }
}
