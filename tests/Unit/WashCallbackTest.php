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
}
