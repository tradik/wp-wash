<?php
/**
 * Tests for the bulk "Refresh all statuses" feature (WashAdmin):
 *  - applyStatusToPostMeta(): the shared reconcile that restores/clears the
 *    _wash_* sync meta from a /api/check-status entry.
 *  - ajaxRefreshAllStatuses(): security gates, the prepare phase (enumerate +
 *    cache ids), expired-session handling, and a batch that reconciles posts.
 *
 * Recovers the synced/not-synced indicators after a plugin delete wiped the
 * _wash_* post meta (uninstall.php), rebuilding them from shopify-app's SyncLog
 * without re-syncing content.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

class WashRefreshStatusesTest extends TestCase
{
    private \WashAdmin $admin;
    private array $transients = [];
    private array $jsonResponses = [];
    private array $postMeta = [];
    private array $postIds = [];
    private string $apiKey = 'test-key';
    /** @var array{code:int,body:string} */
    private array $httpResponse = ['code' => 200, 'body' => '{"success":true,"statuses":{}}'];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
        if (!defined('WASH_VERSION')) { define('WASH_VERSION', '1.26.7'); }

        $this->transients = [];
        $this->jsonResponses = [];
        $this->postMeta = [];
        $this->postIds = [];

        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('__')->alias(fn($t) => $t);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('sanitize_key')->alias(fn($v) => preg_replace('/[^a-z0-9_-]/i', '', (string) $v));
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('apply_filters')->alias(fn($_, $v) => $v);
        Functions\when('wp_generate_password')->justReturn('TESTTOKEN1234567890Z');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => $this->apiKey,
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_post_types' => 'post,page',
                default => $default,
            };
        });

        Functions\when('get_posts')->alias(fn($args = []) => $this->postIds);

        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->postMeta[(int) $id][$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->postMeta[(int) $id][$key]);
            return true;
        });

        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });

        // HTTP layer used by WashApiClient::post() → sendWithRetry().
        Functions\when('wp_remote_post')->justReturn(['_marker' => true]);
        Functions\when('is_wp_error')->alias(fn($t) => $t instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn() => $this->httpResponse['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn() => $this->httpResponse['body']);
        Functions\when('wp_remote_retrieve_header')->justReturn('');

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

    protected function tearDown(): void
    {
        unset($_POST['token'], $_POST['offset']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function lastSuccess(): array
    {
        $last = end($this->jsonResponses);
        $this->assertSame('success', $last['type']);
        return $last['data'];
    }

    private function invokePrivate(string $method, array $args)
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->admin, ...$args);
    }

    // ---------------- applyStatusToPostMeta ----------------

    public function testApplyStatusRestoresMetaWhenArticleExists(): void
    {
        $result = $this->invokePrivate('applyStatusToPostMeta', [42, [
            'exists' => true,
            'shopifyArticleId' => 'gid://shopify/Article/X',
            'shopifyBlogId' => 'gid://shopify/Blog/1',
            'shopifyBlogHandle' => 'news',
            'syncedAt' => '2026-05-30T20:00:00Z',
        ]]);

        $this->assertSame('synced', $result);
        $this->assertSame('gid://shopify/Article/X', $this->postMeta[42]['_wash_article_id']);
        $this->assertSame('gid://shopify/Blog/1', $this->postMeta[42]['_wash_blog_id']);
        $this->assertSame('news', $this->postMeta[42]['_wash_blog_handle']);
        $this->assertSame('2026-05-30T20:00:00Z', $this->postMeta[42]['_wash_synced_at']);
    }

    public function testApplyStatusClearsMetaWhenArticleMissing(): void
    {
        $this->postMeta[7] = ['_wash_article_id' => 'gid://stale', '_wash_sync_error' => 'boom'];

        $result = $this->invokePrivate('applyStatusToPostMeta', [7, ['exists' => false]]);

        $this->assertSame('not_synced', $result);
        $this->assertArrayNotHasKey('_wash_article_id', $this->postMeta[7] ?? []);
        $this->assertArrayNotHasKey('_wash_sync_error', $this->postMeta[7] ?? []);
    }

    public function testApplyStatusClearsMetaWhenStatusNull(): void
    {
        $this->postMeta[9] = ['_wash_article_id' => 'gid://stale'];
        $result = $this->invokePrivate('applyStatusToPostMeta', [9, null]);
        $this->assertSame('not_synced', $result);
        $this->assertArrayNotHasKey('_wash_article_id', $this->postMeta[9] ?? []);
    }

    // ---------------- ajaxRefreshAllStatuses: security ----------------

    public function testRefreshRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () { throw new \RuntimeException('nonce_failed'); });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->admin->ajaxRefreshAllStatuses();
    }

    public function testRefreshRejectsUnauthorized(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        try {
            $this->admin->ajaxRefreshAllStatuses();
            $this->fail('expected wp_send_json_error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
        }
    }

    public function testRefreshRejectsWhenNotConfigured(): void
    {
        $this->apiKey = '';
        $admin = new \WashAdmin(); // picks up empty apiKey at construction
        try {
            $admin->ajaxRefreshAllStatuses();
            $this->fail('expected wp_send_json_error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Not connected to Shopify.', $this->jsonResponses[0]['data']);
        }
    }

    // ---------------- prepare phase ----------------

    public function testPreparePhaseEnumeratesAndCachesIds(): void
    {
        $this->postIds = [10, 20, 30];
        try {
            $this->admin->ajaxRefreshAllStatuses(); // no token → prepare
            $this->fail('expected wp_send_json_success');
        } catch (\RuntimeException $e) {
            $data = $this->lastSuccess();
            $this->assertSame('TESTTOKEN1234567890Z', $data['token']);
            $this->assertSame(3, $data['total']);
            $this->assertSame([10, 20, 30], $this->transients['wash_refresh_TESTTOKEN1234567890Z']);
        }
    }

    public function testBatchPhaseRejectsExpiredToken(): void
    {
        $_POST['token'] = 'doesnotexist';
        $_POST['offset'] = '0';
        try {
            $this->admin->ajaxRefreshAllStatuses();
            $this->fail('expected wp_send_json_error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', strtolower($this->jsonResponses[0]['data']));
        }
    }

    // ---------------- batch phase ----------------

    public function testBatchReconcilesPostsAndReportsProgress(): void
    {
        $this->transients['wash_refresh_TESTTOKEN1234567890Z'] = [10, 20];
        $_POST['token'] = 'TESTTOKEN1234567890Z';
        $_POST['offset'] = '0';
        $this->httpResponse['body'] = json_encode([
            'success' => true,
            'statuses' => [
                '10' => ['exists' => true, 'shopifyArticleId' => 'gid://shopify/Article/10', 'syncedAt' => '2026-05-30T20:00:00Z'],
                '20' => ['exists' => false],
            ],
        ]);

        try {
            $this->admin->ajaxRefreshAllStatuses();
            $this->fail('expected wp_send_json_success');
        } catch (\RuntimeException $e) {
            $data = $this->lastSuccess();
            $this->assertSame(1, $data['restored']);
            $this->assertSame(1, $data['notSynced']);
            $this->assertSame(0, $data['errors']);
            $this->assertTrue($data['done']);
            // Post 10 restored, post 20 left unsynced.
            $this->assertSame('gid://shopify/Article/10', $this->postMeta[10]['_wash_article_id']);
            $this->assertArrayNotHasKey('_wash_article_id', $this->postMeta[20] ?? []);
            // Session cleaned up when done.
            $this->assertArrayNotHasKey('wash_refresh_TESTTOKEN1234567890Z', $this->transients);
        }
    }
}
