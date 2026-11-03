<?php
/**
 * WashAdmin manual sync outcomes: ajaxManualSync (post editor button) and
 * handleManualSync (posts list row action). WashSync runs for real; with no
 * API key it skips every connection, so the post meta set here decides the
 * reported result. The security gates are in WashAdminSecurityTest.
 */
namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class WashAdminManualSyncTest extends TestCase
{
    private array $meta = [];
    private ?object $post = null;
    private string $referer = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
        $this->meta = [];
        $this->post = (object) ['ID' => 5, 'post_type' => 'post', 'post_status' => 'publish'];
        $this->referer = '';
        $_POST = ['post_id' => '5'];
        $_GET = ['action' => 'wash_sync', 'post_id' => '5', '_wpnonce' => 'n'];
        Functions\when('get_option')->alias(fn ($key, $default = false) => $default);
        Functions\when('get_post')->alias(fn () => $this->post);
        Functions\when('get_post_meta')->alias(fn ($id, $key) => $this->meta[$key] ?? '');
        Functions\stubs([
            '__' => fn ($t) => $t, 'absint' => fn ($v) => abs((int) $v), 'wp_unslash' => fn ($v) => $v,
            'sanitize_text_field' => fn ($v) => $v, 'check_ajax_referer' => true, 'current_user_can' => true,
            'wp_verify_nonce' => true, 'wp_is_post_revision' => false, 'wp_is_post_autosave' => false,
            'error_log' => true, 'get_permalink' => 'https://wp.example/p/', 'get_site_url' => 'https://wp.example',
        ]);
        Functions\when('admin_url')->alias(fn ($p = '') => 'https://wp.example/wp-admin/' . $p);
        Functions\when('wp_get_referer')->alias(fn () => $this->referer);
        Functions\when('add_query_arg')->alias(function ($args, $value, $url = null) {
            $args = is_array($args) ? $args : [$args => $value];
            $url = is_array($args) && $url === null ? $value : $url;
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            throw new \RuntimeException('redirect:' . $url);
        });
        Functions\when('wp_send_json_success')->alias(function ($data) {
            throw new \RuntimeException('success:' . json_encode($data));
        });
        Functions\when('wp_send_json_error')->alias(function ($data) {
            throw new \RuntimeException('error:' . json_encode($data));
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private function outcome(callable $run): string
    {
        try {
            $run();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $this->fail('no response sent');
    }

    public function testAjaxReportsQueuedJob(): void
    {
        $this->meta = ['_wash_sync_status' => 'queued', '_wash_job_id' => 'j1'];
        $out = $this->outcome(fn () => (new \WashAdmin())->ajaxManualSync());
        $this->assertStringStartsWith('success:{"status":"queued","jobId":"j1"', $out);
    }

    public function testAjaxReportsFailure(): void
    {
        $this->assertSame('error:"Sync failed"', $this->outcome(fn () => (new \WashAdmin())->ajaxManualSync()));
    }

    public function testAjaxReportsSyncedArticle(): void
    {
        $this->meta = ['_wash_article_id' => 'gid://shopify/Article/9'];
        $out = $this->outcome(fn () => (new \WashAdmin())->ajaxManualSync());
        $this->assertStringStartsWith('success:{"status":"synced","shopifyArticleId":"gid:\/\/shopify\/Article\/9"', $out);
    }

    public static function rowActionCases(): array
    {
        return [
            'synced, admin referer kept' => [['_wash_article_id' => '9'], 'post', 'https://wp.example/wp-admin/edit.php?s=x',
                'redirect:https://wp.example/wp-admin/edit.php?s=x&wash_result=synced'],
            'queued, page list' => [['_wash_sync_status' => 'queued'], 'page', '',
                'redirect:https://wp.example/wp-admin/edit.php?post_type=page&wash_result=queued'],
            'error with message, foreign referer' => [['_wash_sync_error' => 'Boom'], 'post', 'https://evil.example/',
                'redirect:https://wp.example/wp-admin/edit.php?wash_result=error&wash_error_msg=Boom'],
            'error without message' => [[], 'post', '', 'redirect:https://wp.example/wp-admin/edit.php?wash_result=error'],
        ];
    }

    #[DataProvider('rowActionCases')]
    public function testRowActionRedirectsWithResult(array $meta, string $type, string $referer, string $expected): void
    {
        $this->meta = $meta;
        $this->post->post_type = $type;
        $this->referer = $referer;
        $this->assertSame($expected, $this->outcome(fn () => (new \WashAdmin())->handleManualSync()));
    }

    public function testRowActionOnDraftRedirectsWithNotPublished(): void
    {
        $this->post->post_status = 'draft';
        $this->referer = 'https://wp.example/wp-admin/edit.php';
        $this->assertSame(
            'redirect:https://wp.example/wp-admin/edit.php?wash_error=not_published',
            $this->outcome(fn () => (new \WashAdmin())->handleManualSync())
        );
    }

    public function testCronSyncRunsForPublishedPost(): void
    {
        // No API key: WashSync skips every store, so this only proves the
        // published path reaches syncManually() without throwing.
        Functions\when('get_post')->alias(fn ($id) => $id === 5 ? $this->post : null);
        (new \WashAdmin())->asyncSyncPost('5');
        $this->assertSame('publish', $this->post->post_status);
    }

    /** Posts list column icon for one store: synced links out, an error warns. */
    private function syncIcon(array $meta): string
    {
        $this->meta = $meta;
        $render = new \ReflectionMethod(\WashAdmin::class, 'renderConnectionSyncIcon');
        ob_start();
        $render->invoke(new \WashAdmin(), 5, ['id' => 'primary', 'store' => 'demo.myshopify.com']);
        return (string) ob_get_clean();
    }

    public function testSyncIconForSyncedAndFailedPosts(): void
    {
        Functions\stubs(['esc_url' => fn ($v) => $v, 'esc_attr' => fn ($v) => $v]);
        $synced = $this->syncIcon(['_wash_article_id' => 'gid://shopify/Article/9']);
        $this->assertStringContainsString('title="Synced to demo.myshopify.com"', $synced);
        $this->assertStringContainsString('dashicons-yes-alt', $synced);
        $failed = $this->syncIcon(['_wash_sync_error' => 'Boom']);
        $this->assertStringContainsString('title="Sync to demo.myshopify.com failed"', $failed);
    }
}
