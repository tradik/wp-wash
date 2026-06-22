<?php
/**
 * Tests for WashSync's connection-aware result/meta handling (#216, phase 2b-sync).
 *
 * Covers the units extracted to make the per-connection meta writes testable:
 *  - persistSyncOutcome(): records sync-result meta, returns the Shopify id on success.
 *  - validCdnUrlOrClear(): returns a still-valid CDN URL or clears stale file meta.
 *  - the syncMetafields()/syncHreflangs() guards and fetchPageBuilderCss() credentials.
 *
 * For `primary` every meta/credential key resolves to the historical unprefixed name,
 * so single-store behaviour is unchanged.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class WashSyncConnectionAwareTest extends TestCase
{
    private \WashSync $sync;
    /** @var array<string,mixed> */
    private array $updated = [];
    /** @var string[] */
    private array $deleted = [];
    /** @var array<string,mixed> */
    private array $postMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->updated  = [];
        $this->deleted  = [];
        $this->postMeta = [];
        \WashContext::setActiveId(null);
        $_POST = [];
        $_GET  = [];

        Functions\when('get_option')->alias(fn($key, $default = '') => $default); // primary → unprefixed keys
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('home_url')->alias(fn($path = '') => 'https://example.com' . $path);
        Functions\when('error_log')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-06-22 00:00:00');
        Functions\when('is_wp_error')->alias(fn($thing) => $thing instanceof \WP_Error);
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) {
            $this->updated[$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($postId, $key) {
            $this->deleted[] = $key;
            return true;
        });
        Functions\when('get_post_meta')->alias(fn($id, $key, $single = false) => $this->postMeta[$key] ?? '');
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_request_failed', 'fail'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(0);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $this->sync = new \WashSync();
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        $_POST = [];
        $_GET  = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke(string $method, array $args)
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->sync, ...$args);
    }

    // --- persistSyncOutcome() -------------------------------------------------

    public function testErrorResultStoresMessageAndReturnsNull(): void
    {
        $out = $this->invoke('persistSyncOutcome', [123, new \WP_Error('sync_failed', 'Boom')]);
        $this->assertNull($out);
        $this->assertSame('Boom', $this->updated['_wash_sync_error']);
    }

    public function testQueuedResultRecordsJobAndReturnsNull(): void
    {
        $out = $this->invoke('persistSyncOutcome', [123, ['queued' => true, 'jobId' => 'job-7']]);
        $this->assertNull($out);
        $this->assertSame('queued', $this->updated['_wash_sync_status']);
        $this->assertSame('job-7', $this->updated['_wash_job_id']);
        $this->assertSame('2026-06-22 00:00:00', $this->updated['_wash_queued_at']);
        $this->assertContains('_wash_sync_error', $this->deleted);
    }

    public function testSuccessResultPersistsIdsAndClearsState(): void
    {
        $out = $this->invoke('persistSyncOutcome', [123, [
            'shopifyArticleId'  => 'gid://shopify/Article/1',
            'shopifyBlogId'     => 'gid://shopify/Blog/2',
            'shopifyBlogHandle' => 'news',
        ]]);
        $this->assertSame('gid://shopify/Article/1', $out);
        $this->assertSame('gid://shopify/Article/1', $this->updated['_wash_article_id']);
        $this->assertSame('gid://shopify/Blog/2', $this->updated['_wash_blog_id']);
        $this->assertSame('news', $this->updated['_wash_blog_handle']);
        $this->assertSame('2026-06-22 00:00:00', $this->updated['_wash_synced_at']);
        foreach (['_wash_sync_status', '_wash_job_id', '_wash_queued_at', '_wash_sync_error'] as $cleared) {
            $this->assertContains($cleared, $this->deleted);
        }
    }

    public function testSuccessFallsBackToShopifyPageId(): void
    {
        $out = $this->invoke('persistSyncOutcome', [123, ['shopifyPageId' => 'gid://shopify/Page/9']]);
        $this->assertSame('gid://shopify/Page/9', $out);
        $this->assertSame('gid://shopify/Page/9', $this->updated['_wash_article_id']);
        $this->assertSame('', $this->updated['_wash_blog_id']); // missing keys default to ''
    }

    public function testEmptyResultPersistsNothingAndReturnsNull(): void
    {
        $out = $this->invoke('persistSyncOutcome', [123, ['somethingElse' => true]]);
        $this->assertNull($out);
        $this->assertSame([], $this->updated);
        $this->assertSame([], $this->deleted);
    }

    // --- validCdnUrlOrClear() -------------------------------------------------

    public function testValidCdnUrlIsReturnedUnchanged(): void
    {
        $this->postMeta['_wash_cdn_url'] = 'https://cdn.shopify.com/files/x.jpg';
        $out = $this->invoke('validCdnUrlOrClear', [456]);
        $this->assertSame('https://cdn.shopify.com/files/x.jpg', $out);
        $this->assertSame([], $this->deleted); // valid → nothing cleared
    }

    public function testStaleCdnUrlIsClearedAndReturnsEmpty(): void
    {
        $this->postMeta['_wash_cdn_url'] = 'https://old.example.com/x.jpg'; // not a shopify CDN
        $out = $this->invoke('validCdnUrlOrClear', [456]);
        $this->assertSame('', $out);
        $this->assertContains('_wash_cdn_url', $this->deleted);
        $this->assertContains('_wash_file_id', $this->deleted);
    }

    public function testNoCdnUrlReturnsEmptyWithoutClearing(): void
    {
        $out = $this->invoke('validCdnUrlOrClear', [456]); // postMeta empty → no stored URL
        $this->assertSame('', $out);
        $this->assertSame([], $this->deleted);
    }

    // --- guards / credentials -------------------------------------------------

    public function testSyncMetafieldsSkipsWhenDisabled(): void
    {
        // wash_sync_metafields defaults off → early return before instantiating WashMetafields.
        $this->assertNull($this->invoke('syncMetafields', [123, 'gid://shopify/Article/1']));
    }

    public function testSyncHreflangsSkipsWhenDisabled(): void
    {
        $this->assertNull($this->invoke('syncHreflangs', [123, 'gid://shopify/Article/1']));
    }

    public function testFetchPageBuilderCssReadsConnectionCredentials(): void
    {
        // Exercises the per-connection httpUser/httpPass reads; the request is mocked to fail,
        // so the method returns '' gracefully.
        $this->assertSame('', $this->invoke('fetchPageBuilderCss', [123]));
    }

    public function testSyncFeaturedImageSkipsWhenAlreadySyncedWithValidCdn(): void
    {
        // A still-valid CDN URL short-circuits before any re-sync — no meta is cleared.
        $this->postMeta['_wash_cdn_url'] = 'https://cdn.shopify.com/files/x.jpg';
        $this->invoke('syncFeaturedImage', [456]);
        $this->assertSame([], $this->deleted);
    }

    public function testSyncContentFilesSkipsAttachmentsAlreadySynced(): void
    {
        // A content image that resolves to an attachment with a still-valid CDN URL is
        // skipped via the shared validCdnUrlOrClear() guard (exercises the loop body).
        Functions\when('attachment_url_to_postid')->justReturn(789);
        $this->postMeta['_wash_cdn_url'] = 'https://cdn.shopify.com/files/x.jpg';
        $content = 'before <img src="https://example.com/wp-content/uploads/2024/x.jpg"> after';
        $this->invoke('syncContentFiles', [$content]);
        $this->assertSame([], $this->deleted); // valid CDN → nothing cleared
    }
}
