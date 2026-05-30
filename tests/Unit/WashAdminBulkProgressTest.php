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

    public function testHandleBulkActionsSkipsAlreadySyncedPostsForPublish(): void
    {
        $this->inlineMode = '1';
        $this->mockPost(10, 'post', 'publish', 'gid://shopify/Article/X');
        $this->mockPost(20);

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $this->admin->handleBulkActions($url, 'wash_publish', [10, 20]);

        $stored = $this->transients['wash_bulk_TESTTOKEN1234567890Z'] ?? null;
        $this->assertSame([20], $stored['postIds']);
        $this->assertSame(1, $stored['skipped']);
    }

    public function testHandleBulkActionsReturnsNoticeUrlWhenAllPostsSkipped(): void
    {
        $this->inlineMode = '1';
        $this->mockPost(10, 'post', 'publish', 'gid://shopify/Article/X');
        $this->mockPost(20, 'post', 'publish', 'gid://shopify/Article/Y');

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
