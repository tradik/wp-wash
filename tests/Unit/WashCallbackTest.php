<?php
/**
 * Unit tests for WashCallback::handleCallback — translation-aware metafield sync.
 * Each test runs in a separate process so Mockery overload: can define
 * WashMetafields and WashHreflangs before the real classes are loaded.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class WashCallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_metafields' => '1',
                'wash_hreflangs_enabled' => '0',
                'wash_redirections_enabled' => '0',
                'wash_shopify_store' => 'test',
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('get_post')->justReturn((object) [
            'ID' => 42,
            'post_type' => 'post',
            'post_title' => 'Test',
        ]);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('error_log')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('current_time')->justReturn('2026-04-13 12:00:00');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        // Redirect dedupe lock now uses the atomic object cache (LOW-12).
        Functions\when('wp_cache_add')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeRequest(array $overrides = []): \WP_REST_Request
    {
        return new \WP_REST_Request(array_merge([
            'wpPostId' => 42,
            'wpSiteId' => 1,
            'success' => true,
            'jobId' => 'job-1',
            'shopifyArticleId' => 'gid://shopify/Article/100',
            'shopifyBlogId' => 'gid://shopify/Blog/1',
            'shopifyBlogHandle' => 'news',
            'shopifyHandle' => 'test-post',
        ], $overrides));
    }

    private function loadCallback(): \WashCallback
    {
        if (!class_exists('WashApiClient', false)) {
            require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
        }
        require_once dirname(__DIR__, 2) . '/includes/class-wash-callback.php';
        return new \WashCallback();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSourcePostSyncsMetafields(): void
    {
        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')
            ->once()
            ->with(42, 'gid://shopify/Article/100')
            ->andReturn(['count' => 3]);

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn([
            'sourcePostId' => 42,
            'sourceLang' => 'en',
            'currentLang' => 'en',
            'isSource' => true,
        ]);

        $cb = $this->loadCallback();
        $response = $cb->handleCallback($this->makeRequest([
            'translationGroup' => [
                'sourcePostId' => 42,
                'sourceLang' => 'en',
                'currentLang' => 'en',
                'isSource' => true,
            ],
        ]));

        $this->assertTrue($response->data['success']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNonSourceTranslationSkipsMetafields(): void
    {
        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->never();

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn([
            'sourcePostId' => 10,
            'sourceLang' => 'en',
            'currentLang' => 'fr',
            'isSource' => false,
        ]);

        $cb = $this->loadCallback();
        $response = $cb->handleCallback($this->makeRequest([
            'translationGroup' => [
                'sourcePostId' => 10,
                'sourceLang' => 'en',
                'currentLang' => 'fr',
                'isSource' => false,
            ],
        ]));

        $this->assertTrue($response->data['success']);
    }

    public function testAttachmentCallbackStoresFileMetaAndReturns200(): void
    {
        // Image-sync (CRIT-3) callback: keyed by wpAttachmentId, no wpPostId.
        // Must short-circuit the post path and report success without 400-ing.
        $cb = $this->loadCallback();
        $response = $cb->handleCallback($this->makeRequest([
            'wpPostId'        => null,
            'wpAttachmentId'  => 77,
            'success'         => true,
            'shopifyFileId'   => 'gid://shopify/MediaImage/55',
            'shopifyCdnUrl'   => 'https://cdn.shopify.com/files/x.png',
        ]));

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertSame('Attachment callback processed', $response->data['message']);
    }

    public function testAttachmentCallbackFailureClearsPendingAndReturns200(): void
    {
        $cb = $this->loadCallback();
        $response = $cb->handleCallback($this->makeRequest([
            'wpPostId'       => null,
            'wpAttachmentId' => 77,
            'success'        => false,
            'error'          => 'upload failed',
        ]));

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertSame('Attachment callback processed', $response->data['message']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNoTranslationGroupSyncsMetafields(): void
    {
        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')
            ->once()
            ->andReturn(['count' => 2]);

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn(null);

        $cb = $this->loadCallback();
        $response = $cb->handleCallback($this->makeRequest());
        $this->assertTrue($response->data['success']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFallbackDetectsNonSourceTranslation(): void
    {
        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->never();

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')
            ->with(42)
            ->andReturn([
                'sourcePostId' => 10,
                'sourceLang' => 'en',
                'currentLang' => 'fr',
                'isSource' => false,
            ]);

        $cb = $this->loadCallback();
        $response = $cb->handleCallback($this->makeRequest());
        $this->assertTrue($response->data['success']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShopifyUrlWithoutLocaleHasNoPrefix(): void
    {
        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->andReturn(['count' => 0]);

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn(null);

        $storedUrl = null;
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) use (&$storedUrl) {
            if ($key === '_wash_shopify_url') {
                $storedUrl = $value;
            }
            return true;
        });

        $cb = $this->loadCallback();
        $cb->handleCallback($this->makeRequest());

        $this->assertSame('https://test.myshopify.com/blogs/news/test-post', $storedUrl);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShopifyUrlWithLocaleHasPrefix(): void
    {
        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->andReturn(['count' => 0]);

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn(null);

        $storedUrl = null;
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) use (&$storedUrl) {
            if ($key === '_wash_shopify_url') {
                $storedUrl = $value;
            }
            return true;
        });

        $cb = $this->loadCallback();
        $cb->handleCallback($this->makeRequest([
            'shopifyLocale' => 'de',
        ]));

        $this->assertSame('https://test.myshopify.com/de/blogs/news/test-post', $storedUrl);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShopifyPageUrlWithLocaleHasPrefix(): void
    {
        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->andReturn(['count' => 0]);

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn(null);

        $storedUrl = null;
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) use (&$storedUrl) {
            if ($key === '_wash_shopify_url') {
                $storedUrl = $value;
            }
            return true;
        });

        $cb = $this->loadCallback();
        $cb->handleCallback($this->makeRequest([
            'shopifyArticleId' => null,
            'shopifyPageId' => 'gid://shopify/Page/200',
            'shopifyBlogId' => null,
            'shopifyBlogHandle' => null,
            'shopifyHandle' => 'about-us',
            'shopifyLocale' => 'fr',
        ]));

        $this->assertSame('https://test.myshopify.com/fr/pages/about-us', $storedUrl);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRedirectPathIncludesLocalePrefix(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_redirections_enabled' => '1',
                'wash_shopify_store' => 'test',
                'wash_sync_metafields' => '0',
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_permalink')->justReturn('https://example.com/de/art/mein-artikel');

        $redirectTo = null;
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) use (&$redirectTo) {
            if ($key === '_wash_redirect_to') {
                $redirectTo = $value;
            }
            return true;
        });
        Functions\when('get_post_meta')->justReturn('');

        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->never();

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn(null);

        // Mock API client to capture redirect request
        $apiMock = Mockery::mock('overload:WashApiClient');
        $apiMock->shouldReceive('post')
            ->with('/api/redirections', Mockery::type('array'))
            ->andReturn(['created' => 1, 'skipped' => 0]);

        $cb = $this->loadCallback();
        $cb->handleCallback($this->makeRequest([
            'shopifyBlogHandle' => 'art',
            'shopifyHandle' => 'mein-artikel',
            'shopifyLocale' => 'de',
        ]));

        $this->assertSame('/de/blogs/art/mein-artikel', $redirectTo);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRedirectPathWithoutLocaleHasNoPrefix(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_redirections_enabled' => '1',
                'wash_shopify_store' => 'test',
                'wash_sync_metafields' => '0',
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_permalink')->justReturn('https://example.com/uk/art/my-article');

        $redirectTo = null;
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) use (&$redirectTo) {
            if ($key === '_wash_redirect_to') {
                $redirectTo = $value;
            }
            return true;
        });
        Functions\when('get_post_meta')->justReturn('');

        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->never();

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn(null);

        $apiMock = Mockery::mock('overload:WashApiClient');
        $apiMock->shouldReceive('post')
            ->with('/api/redirections', Mockery::type('array'))
            ->andReturn(['created' => 1, 'skipped' => 0]);

        $cb = $this->loadCallback();
        $cb->handleCallback($this->makeRequest([
            'shopifyBlogHandle' => 'art',
            'shopifyHandle' => 'my-article',
        ]));

        $this->assertSame('/blogs/art/my-article', $redirectTo);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRedirectSkippedWhenLockHeldByConcurrentCallback(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_redirections_enabled' => '1',
                'wash_shopify_store' => 'test',
                'wash_sync_metafields' => '0',
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_permalink')->justReturn('https://example.com/uk/art/my-article');
        Functions\when('get_post_meta')->justReturn('');
        // The atomic lock is already held by a concurrent callback (LOW-12, #173).
        Functions\when('wp_cache_add')->justReturn(false);

        $hrefMock = Mockery::mock('overload:WashHreflangs');
        $hrefMock->shouldReceive('getTranslationGroupSource')->andReturn(null);

        $metaMock = Mockery::mock('overload:WashMetafields');
        $metaMock->shouldReceive('syncAllMetafields')->never();

        // The redirect API must NOT be hit while the lock is contended.
        $apiMock = Mockery::mock('overload:WashApiClient');
        $apiMock->shouldReceive('post')->with('/api/redirections', Mockery::type('array'))->never();

        $cb = $this->loadCallback();
        $result = $cb->handleCallback($this->makeRequest([
            'shopifyBlogHandle' => 'art',
            'shopifyHandle' => 'my-article',
        ]));

        // The callback still succeeds — the redirect is simply skipped this round.
        $this->assertInstanceOf(\WP_REST_Response::class, $result);
    }

    /**
     * A delete/unpublish ack must clear local sync meta and pause auto-sync,
     * and must NOT re-store the (shared) article id — that re-store was the
     * "unpublish, but WordPress still shows synced" desync (#255).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDeleteCallbackClearsMetaSetsSkipFlagAndDoesNotStoreArticleId(): void
    {
        $updated = [];
        $deleted = [];
        Functions\when('update_post_meta')->alias(function ($id, $key, $val) use (&$updated) {
            $updated[$key] = $val;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $cb = $this->loadCallback();
        $response = $cb->handleCallback($this->makeRequest([
            'action'           => 'delete',
            'deleted'          => true,
            // worker may still echo the shared article id — it must be ignored
            'shopifyArticleId' => 'gid://shopify/Article/567434018883',
            // truthy group skips the server-side fallback lookup (no WashHreflangs needed)
            'translationGroup' => ['isSource' => true],
        ]));

        $this->assertTrue($response->data['success']);
        $this->assertArrayNotHasKey('_wash_article_id', $updated, 'delete must not re-store article id');
        $this->assertContains('_wash_article_id', $deleted, 'delete must clear article id');
        $this->assertSame('1', $updated['_wash_skip_autosync'] ?? null, 'delete must pause auto-sync');
    }
}
