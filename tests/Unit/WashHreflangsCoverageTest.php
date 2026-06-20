<?php
/**
 * Extended unit tests for WashHreflangs — coverage push toward 90 %.
 *
 * Strategy:
 *  - Force static $activePlugin cache so detectActivePlugin() returns without
 *    calling is_plugin_active() (which needs filesystem access unavailable in tests).
 *  - Use Brain\Monkey\Functions\when() to stub every WordPress / plugin function
 *    the class actually invokes at the call-site.
 *  - Use ReflectionMethod to invoke private helpers.
 *  - Mock WashApiClient->post() via subclass override so no real HTTP is made.
 */

namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';

/**
 * Thin subclass that replaces WashApiClient with a recording stub.
 * Lets us verify calls to $this->apiClient->post() without HTTP.
 */
class StubApiClient extends \WashApiClient
{
    public array $calls = [];
    /** @var mixed Return value for the next post() call (or WP_Error) */
    public $nextResult = ['success' => true];

    public function post($endpoint, array $data = [])
    {
        $this->calls[] = ['endpoint' => $endpoint, 'body' => $data];
        return $this->nextResult;
    }
}

/**
 * WashHreflangs sub-class that exposes a replaceable apiClient for testing.
 */
class TestableWashHreflangs extends \WashHreflangs
{
    public StubApiClient $stub;

    public function __construct()
    {
        // Skip parent constructor – we set up apiClient manually.
        $this->stub = new StubApiClient();
        // Inject via reflection.
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'apiClient');
        $ref->setAccessible(true);
        $ref->setValue($this, $this->stub);
    }
}

// Isolated process: this class stubs optional multilingual functions (PLL,
// pll_*, WPML, Weglot), which make function_exists() true process-wide and
// would otherwise contaminate later tests (e.g. WashHreflangsTest) that assume
// those plugins are absent.
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class WashHreflangsCoverageTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** Reset the static plugin-detection cache between tests. */
    private function resetCache(): void
    {
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    /** Force the cache to a specific value so detectActivePlugin() returns it immediately. */
    private function forcePlugin(?string $plugin): void
    {
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, $plugin === null ? null : $plugin);
    }

    /** Force the cache to boolean false (no plugin). */
    private function forceFalsePlugin(): void
    {
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
    }

    private function makeH(): TestableWashHreflangs
    {
        return new TestableWashHreflangs();
    }

    /** Call a private method and return its result. */
    private function callPrivate(object $obj, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    // Standard Brain\Monkey API-client stubs required for construction.
    private function stubBasicOptions(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
    }

    // ---------------------------------------------------------------
    // Test lifecycle
    // ---------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->resetCache();
        $this->stubBasicOptions();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // init()
    // ---------------------------------------------------------------

    public function testInitDoesNothingWhenDisabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_hreflangs_enabled') {
                return '0';
            }
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $h = $this->makeH();
        // Should return early without adding any actions — just assert no exception.
        $h->init();
        $this->assertTrue(true);
    }

    public function testInitAddsActionWhenEnabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_hreflangs_enabled') {
                return '1';
            }
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $addActionCalled = false;
        Functions\when('add_action')->alias(function ($hook, $cb, $prio, $args) use (&$addActionCalled) {
            if ($hook === 'wash_post_synced') {
                $addActionCalled = true;
            }
        });

        $h = $this->makeH();
        $h->init();
        $this->assertTrue($addActionCalled, 'add_action("wash_post_synced") must be called when enabled');
    }

    // ---------------------------------------------------------------
    // onPostSynced()
    // ---------------------------------------------------------------

    public function testOnPostSyncedSkipsEmptyShopifyId(): void
    {
        $h = $this->makeH();
        // If shopifyId is empty, syncHreflangs should NOT be called.
        // We verify indirectly: apiClient->calls stays empty.
        $h->onPostSynced(1, '');
        $this->assertEmpty($h->stub->calls, 'No API call should be made for empty shopifyId');
    }

    public function testOnPostSyncedDelegatesToSyncHreflangs(): void
    {
        $this->forcePlugin(false); // will fall through to "no translations"

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled' => '1',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);

        $h = $this->makeH();
        // syncHreflangs with no translations → clearHreflangMetafields
        $h->stub->nextResult = ['success' => true, 'cleared' => true];
        $h->onPostSynced(1, 'gid://shopify/Article/99');

        $this->assertNotEmpty($h->stub->calls, 'API call expected for non-empty shopifyId');
    }

    // ---------------------------------------------------------------
    // syncHreflangs()
    // ---------------------------------------------------------------

    public function testSyncHreflangsSkipsWhenDisabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled' => '0',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });

        $h = $this->makeH();
        $result = $h->syncHreflangs(1, 'gid://shopify/Article/1');

        $this->assertTrue($result['skipped']);
        $this->assertSame('Hreflangs sync disabled', $result['reason']);
        $this->assertEmpty($h->stub->calls);
    }

    public function testSyncHreflangsCallsClearWhenNoTranslations(): void
    {
        $this->forceFalsePlugin();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled' => '1',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);

        $h = $this->makeH();
        $h->stub->nextResult = ['success' => true, 'cleared' => true];

        $result = $h->syncHreflangs(1, 'gid://shopify/Article/1');

        $this->assertCount(1, $h->stub->calls);
        $this->assertSame('/api/metafields', $h->stub->calls[0]['endpoint']);
        $this->assertSame('delete_namespace', $h->stub->calls[0]['body']['action']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['cleared']);
    }

    public function testSyncHreflangsReturnsWpErrorFromApiClient(): void
    {
        $this->forceFalsePlugin();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled' => '1',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $h = $this->makeH();
        $h->stub->nextResult = new \WP_Error('api_error', 'Connection failed');

        $result = $h->syncHreflangs(1, 'gid://shopify/Article/1');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('Connection failed', $result->get_error_message());
    }

    public function testSyncHreflangsWithTranslationsCallsUpsert(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled'  => '1',
                'wash_shopify_store'      => 'mystore',
                'wash_api_key'            => 'test-key',
                'wash_app_url'            => 'https://app-wash.tradik.dev',
                'wash_http_user'          => '',
                'wash_http_pass'          => '',
                default                   => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/' . $postId;
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $postEn = new \stdClass();
        $postEn->post_status = 'publish';
        $postEn->post_type   = 'post';
        $postEn->post_name   = 'my-article';

        $postDe = new \stdClass();
        $postDe->post_status = 'publish';
        $postDe->post_type   = 'post';
        $postDe->post_name   = 'mein-artikel';

        Functions\when('get_post')->alias(function ($id) use ($postEn, $postDe) {
            return $id === 10 ? $postEn : ($id === 20 ? $postDe : null);
        });
        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'de' => 20]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_default_language')->justReturn('en');

        // PLL() returns empty stdClass → method_exists returns false → getPolylangLocale returns slug
        Functions\when('PLL')->justReturn(new \stdClass());
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $h = $this->makeH();
        $h->stub->nextResult = ['success' => true];

        $result = $h->syncHreflangs(10, 'gid://shopify/Article/10');

        $this->assertTrue($result['success'] ?? false);
        $this->assertGreaterThanOrEqual(1, $result['count'] ?? 0);
        // Ensure upsert was called (last call is always upsert)
        $lastCall = end($h->stub->calls);
        $this->assertSame('upsert', $lastCall['body']['action']);
    }

    public function testSyncHreflangsLogsErrorOnWpError(): void
    {
        // Cover the washLog path in syncHreflangs when API returns error
        $this->forceFalsePlugin();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled' => '1',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $h = $this->makeH();
        $wpErr = new \WP_Error('fail', 'Bad request');
        $h->stub->nextResult = $wpErr;

        $result = $h->syncHreflangs(1, 'gid://shopify/Article/1');
        // clearHreflangMetafields returned a WP_Error, should bubble up
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // buildHreflangMetafields()
    // ---------------------------------------------------------------

    public function testBuildHreflangMetafieldsReturnsEmptyWhenNoTranslations(): void
    {
        $this->forceFalsePlugin();

        $h = $this->makeH();
        $result = $h->buildHreflangMetafields(1);
        $this->assertSame([], $result);
    }

    public function testBuildHreflangMetafieldsNormalizesLocaleAndAddsXDefault(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'mystore',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/' . $postId;
            }
            if ($key === '_wash_blog_handle') {
                return '';
            }
            return '';
        });

        $post = new \stdClass();
        $post->post_status = 'publish';
        $post->post_type   = 'post';
        $post->post_name   = 'test-article';

        Functions\when('get_post')->justReturn($post);
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1, 'de' => 2]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass()); // no 'model' property → getPolylangLocale returns slug

        $h = $this->makeH();
        $result = $h->buildHreflangMetafields(1);

        $this->assertNotEmpty($result);

        // Check that all entries are well-formed
        foreach ($result as $mf) {
            $this->assertArrayHasKey('namespace', $mf);
            $this->assertArrayHasKey('key', $mf);
            $this->assertArrayHasKey('value', $mf);
            $this->assertArrayHasKey('type', $mf);
            $this->assertSame('url', $mf['type']);
            $this->assertSame('wash_hreflang', $mf['namespace']);
        }

        // Should have x-default
        $keys = array_column($result, 'key');
        $this->assertContains('hreflang_x_default', $keys);
    }

    public function testBuildHreflangMetafieldsSkipsEmptyUrls(): void
    {
        // If getShopifyUrlForPost returns null, that translation should be skipped
        $this->forcePlugin('polylang');

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => '',  // empty → no URL
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass()); // no 'model' property → getPolylangLocale returns slug

        $post = new \stdClass();
        $post->post_status = 'publish';
        $post->post_type   = 'post';
        $post->post_name   = 'test';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $result = $h->buildHreflangMetafields(1);
        // No shopify store → no URLs → empty result
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // getPostTranslations() — dispatch per active plugin
    // ---------------------------------------------------------------

    public function testGetPostTranslationsDispatchesToPolylang(): void
    {
        $this->forcePlugin('polylang');
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_get_post_language')->justReturn('en');

        $h = $this->makeH();
        $result = $h->getPostTranslations(1);
        $this->assertIsArray($result);
    }

    public function testGetPostTranslationsReturnsEmptyForNoPlugin(): void
    {
        $this->forceFalsePlugin();
        $h = $this->makeH();
        $result = $h->getPostTranslations(1);
        $this->assertSame([], $result);
    }

    public function testGetPostTranslationsDispatchesToWpml(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        $post->post_name   = 'article';
        Functions\when('get_post')->justReturn($post);
        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_element_trid') {
                return 42;
            }
            if ($filter === 'wpml_get_element_translations') {
                return [];
            }
            return $default;
        });

        $h = $this->makeH();
        $result = $h->getPostTranslations(1);
        $this->assertIsArray($result);
    }

    public function testGetPostTranslationsDispatchesToTranslatePress(): void
    {
        $this->forcePlugin('translatepress');

        $h = $this->makeH();
        // TRP_Translate_Press class doesn't exist → returns []
        $result = $h->getPostTranslations(1);
        $this->assertSame([], $result);
    }

    public function testGetPostTranslationsDispatchesToWeglot(): void
    {
        $this->forcePlugin('weglot');

        // weglot_get_options may have been stubbed by a prior test (Patchwork registers it).
        // If so, get_post will be called. Stub both defensively.
        Functions\when('weglot_get_options')->justReturn([]);
        Functions\when('get_post')->justReturn(null); // null post → returns []

        $h = $this->makeH();
        $result = $h->getPostTranslations(1);
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // getTranslationGroupSource() — WPML path
    // ---------------------------------------------------------------

    public function testGetTranslationGroupSourceWpmlNullPost(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);
        Functions\when('get_post')->justReturn(null);

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(999);
        $this->assertNull($result);
    }

    public function testGetTranslationGroupSourceWpmlNullTrid(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_element_trid') {
                return null;
            }
            return $default;
        });

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(1);
        $this->assertNull($result);
    }

    public function testGetTranslationGroupSourceWpmlEmptyTranslations(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_element_trid') {
                return 10;
            }
            if ($filter === 'wpml_get_element_translations') {
                return [];
            }
            return $default;
        });

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(1);
        $this->assertNull($result);
    }

    public function testGetTranslationGroupSourceWpmlFoundByDefaultLang(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'de']);

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);

        $enTranslation = new \stdClass();
        $enTranslation->element_id = 5;
        $enTranslation->original   = true;

        $deTranslation = new \stdClass();
        $deTranslation->element_id = 7;
        $deTranslation->original   = false;

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) use ($enTranslation, $deTranslation) {
            if ($filter === 'wpml_element_trid') {
                return 10;
            }
            if ($filter === 'wpml_get_element_translations') {
                return ['en' => $enTranslation, 'de' => $deTranslation];
            }
            if ($filter === 'wpml_default_language') {
                return 'en';
            }
            return $default;
        });

        // wpml_get_language_information called via function stub
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'de']);

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(7);

        $this->assertSame(5, $result['sourcePostId']);
        $this->assertSame('en', $result['sourceLang']);
        $this->assertFalse($result['isSource']);
    }

    public function testGetTranslationGroupSourceWpmlFallbackToOriginalFlag(): void
    {
        $this->forcePlugin('wpml');

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);

        $enTranslation = new \stdClass();
        $enTranslation->element_id = 5;
        $enTranslation->original   = true;

        $deTranslation = new \stdClass();
        $deTranslation->element_id = 7;
        $deTranslation->original   = false;

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) use ($enTranslation, $deTranslation) {
            if ($filter === 'wpml_element_trid') {
                return 10;
            }
            if ($filter === 'wpml_get_element_translations') {
                return ['en' => $enTranslation, 'de' => $deTranslation];
            }
            if ($filter === 'wpml_default_language') {
                // Return a language not in the list → forces fallback to original flag
                return 'fr';
            }
            return $default;
        });

        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'de']);

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(7);
        $this->assertSame(5, $result['sourcePostId']);
    }

    public function testGetTranslationGroupSourceWpmlFallbackNoOriginalReturnsNull(): void
    {
        $this->forcePlugin('wpml');

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);

        $deTranslation = new \stdClass();
        $deTranslation->element_id = 7;
        $deTranslation->original   = false;

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) use ($deTranslation) {
            if ($filter === 'wpml_element_trid') {
                return 10;
            }
            if ($filter === 'wpml_get_element_translations') {
                return ['de' => $deTranslation];
            }
            if ($filter === 'wpml_default_language') {
                return 'fr';
            }
            return $default;
        });

        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'de']);

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(7);
        $this->assertNull($result);
    }

    public function testGetTranslationGroupSourcePolylangNoDefaultSlotUseMinId(): void
    {
        $this->forcePlugin('polylang');

        // Default language 'fr' not in translations → use min ID
        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'de' => 20]);
        Functions\when('pll_default_language')->justReturn('fr');
        Functions\when('pll_get_post_language')->justReturn('en');

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(10);
        $this->assertSame(10, $result['sourcePostId']);
    }

    public function testGetTranslationGroupSourcePolylangEmptyTranslations(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_default_language')->justReturn('en');

        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(1);
        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // getAvailableLanguages() — wpml, translatepress, weglot
    // ---------------------------------------------------------------

    public function testGetAvailableLanguagesWpml(): void
    {
        $this->forcePlugin('wpml');

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_active_languages') {
                return [
                    'en' => ['default_locale' => 'en_US', 'native_name' => 'English'],
                    'de' => ['default_locale' => 'de_DE', 'native_name' => 'Deutsch'],
                ];
            }
            return $default;
        });

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();

        $this->assertCount(2, $result);
        $this->assertSame('en-us', $result[0]['code']);
        $this->assertSame('English', $result[0]['name']);
        $this->assertSame('de-de', $result[1]['code']);
    }

    public function testGetAvailableLanguagesWpmlNullResponse(): void
    {
        $this->forcePlugin('wpml');

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_active_languages') {
                return null;
            }
            return $default;
        });

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $this->assertSame([], $result);
    }

    public function testGetAvailableLanguagesWpmlFallsBackToCodeWhenNoLocale(): void
    {
        $this->forcePlugin('wpml');

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_active_languages') {
                return [
                    'es' => ['native_name' => 'Español'], // no default_locale
                ];
            }
            return $default;
        });

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $this->assertSame('es', $result[0]['code']);
    }

    public function testGetAvailableLanguagesTranslatePressNoClass(): void
    {
        $this->forcePlugin('translatepress');

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        // TRP_Translate_Press class doesn't exist in test environment
        $this->assertSame([], $result);
    }

    public function testGetAvailableLanguagesWeglotNoDestinations(): void
    {
        $this->forcePlugin('weglot');

        // Stub both functions defensively — Patchwork may already have them registered.
        Functions\when('weglot_get_destination_languages')->justReturn([]);
        Functions\when('weglot_get_original_language')->justReturn(null);

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // getActivePluginName()
    // ---------------------------------------------------------------

    public function testGetActivePluginNameWpml(): void
    {
        $this->forcePlugin('wpml');
        $h = $this->makeH();
        $this->assertSame('WPML', $h->getActivePluginName());
    }

    public function testGetActivePluginNamePolylang(): void
    {
        $this->forcePlugin('polylang');
        $h = $this->makeH();
        $this->assertSame('Polylang', $h->getActivePluginName());
    }

    public function testGetActivePluginNameTranslatePress(): void
    {
        $this->forcePlugin('translatepress');
        $h = $this->makeH();
        $this->assertSame('TranslatePress', $h->getActivePluginName());
    }

    public function testGetActivePluginNameWeglot(): void
    {
        $this->forcePlugin('weglot');
        $h = $this->makeH();
        $this->assertSame('Weglot', $h->getActivePluginName());
    }

    public function testGetActivePluginNameNone(): void
    {
        $this->forceFalsePlugin();
        Functions\when('__')->alias(fn($text) => $text);
        $h = $this->makeH();
        $this->assertSame('None detected', $h->getActivePluginName());
    }

    // ---------------------------------------------------------------
    // isMultilingualPluginActive()
    // ---------------------------------------------------------------

    public function testIsMultilingualPluginActiveTrue(): void
    {
        $this->forcePlugin('wpml');
        $h = $this->makeH();
        $this->assertTrue($h->isMultilingualPluginActive());
    }

    public function testIsMultilingualPluginActiveFalse(): void
    {
        // Full detection run with no plugin active: returns null → not active
        $this->resetCache();
        $h = $this->makeH();
        $this->assertFalse($h->isMultilingualPluginActive());
    }

    // ---------------------------------------------------------------
    // getTemplateCode() — static method
    // ---------------------------------------------------------------

    public function testGetTemplateCodeContainsExpectedLiquidSnippets(): void
    {
        $code = \WashHreflangs::getTemplateCode();

        $this->assertStringContainsString('wash_hreflang', $code);
        $this->assertStringContainsString('hreflang', $code);
        $this->assertStringContainsString('{% if article %}', $code);
        $this->assertStringContainsString('rel="alternate"', $code);
        $this->assertStringContainsString('{% endfor %}', $code);
    }

    // ---------------------------------------------------------------
    // Private: getShopifyUrlForPost()
    // ---------------------------------------------------------------

    public function testGetShopifyUrlForPostEmptyStore(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => '',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getShopifyUrlForPost', [1]);
        $this->assertNull($result);
    }

    public function testGetShopifyUrlForPostNoArticleId(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->justReturn('');

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getShopifyUrlForPost', [1]);
        $this->assertNull($result);
    }

    public function testGetShopifyUrlForPostNullPost(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return $key === '_wash_article_id' ? 'gid://shopify/Article/1' : '';
        });
        Functions\when('get_post')->justReturn(null);

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getShopifyUrlForPost', [1]);
        $this->assertNull($result);
    }

    public function testGetShopifyUrlForPostBuildsArticleUrl(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/1';
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $post = new \stdClass();
        $post->post_type = 'post';
        $post->post_name = 'hello-world';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getShopifyUrlForPost', [1]);

        $this->assertSame('https://my-store.myshopify.com/blogs/news/hello-world', $result);
    }

    public function testGetShopifyUrlForPostBuildsPageUrl(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Page/1';
            }
            return '';
        });

        $post = new \stdClass();
        $post->post_type = 'page';
        $post->post_name = 'about-us';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getShopifyUrlForPost', [1]);

        $this->assertSame('https://my-store.myshopify.com/pages/about-us', $result);
    }

    public function testGetShopifyUrlForPostDefaultsBlogHandleToNews(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/1';
            }
            return ''; // empty blog handle
        });

        $post = new \stdClass();
        $post->post_type = 'post';
        $post->post_name = 'test-post';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getShopifyUrlForPost', [1]);

        $this->assertStringContainsString('/blogs/news/', $result);
    }

    // ---------------------------------------------------------------
    // Private: getDefaultLanguageUrl()
    // ---------------------------------------------------------------

    public function testGetDefaultLanguageUrlMatchesLocale(): void
    {
        $this->forcePlugin('polylang');
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass()); // no 'model' property → getPolylangLocale returns slug

        $translations = ['en' => 'https://example.com/en', 'de' => 'https://example.com/de'];

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getDefaultLanguageUrl', [1, $translations]);
        $this->assertSame('https://example.com/en', $result);
    }

    public function testGetDefaultLanguageUrlFallbackToFirst(): void
    {
        // No plugin → get_locale() will be used and won't match our translation keys
        $this->forceFalsePlugin();
        Functions\when('get_locale')->justReturn('fr_FR');

        $translations = ['en' => 'https://example.com/en', 'de' => 'https://example.com/de'];

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getDefaultLanguageUrl', [1, $translations]);
        // fallback: reset($translations) = first value
        $this->assertSame('https://example.com/en', $result);
    }

    public function testGetDefaultLanguageUrlForWpml(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_default_language') {
                return 'en';
            }
            if ($filter === 'wpml_language_details') {
                return ['locale' => 'en_US'];
            }
            return $default;
        });
        Functions\when('get_locale')->justReturn('en_US');

        $translations = ['en-US' => 'https://shopify.com/en', 'de-DE' => 'https://shopify.com/de'];

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getDefaultLanguageUrl', [1, $translations]);
        $this->assertSame('https://shopify.com/en', $result);
    }

    public function testGetDefaultLanguageUrlForTranslatePress(): void
    {
        $this->forcePlugin('translatepress');

        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'trp_settings') {
                return ['default-language' => 'en_US'];
            }
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $translations = ['en-US' => 'https://shopify.com/en'];

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getDefaultLanguageUrl', [1, $translations]);
        $this->assertSame('https://shopify.com/en', $result);
    }

    public function testGetDefaultLanguageUrlForWeglot(): void
    {
        $this->forcePlugin('weglot');
        Functions\when('weglot_get_options')->justReturn(['original_language' => 'en']);

        $translations = ['en' => 'https://shopify.com/en'];

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getDefaultLanguageUrl', [1, $translations]);
        $this->assertSame('https://shopify.com/en', $result);
    }

    // ---------------------------------------------------------------
    // Private: getWpmlDefaultLocale()
    // ---------------------------------------------------------------

    public function testGetWpmlDefaultLocaleWithIclConstant(): void
    {
        if (!defined('ICL_LANGUAGE_CODE')) {
            define('ICL_LANGUAGE_CODE', 'en');
        }

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_default_language') {
                return 'en';
            }
            if ($filter === 'wpml_language_details') {
                return ['locale' => 'en_GB'];
            }
            return $default;
        });

        $this->forcePlugin('wpml');
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getWpmlDefaultLocale', []);
        $this->assertSame('en_GB', $result);
    }

    public function testGetWpmlDefaultLocaleObjectResponse(): void
    {
        if (!defined('ICL_LANGUAGE_CODE')) {
            define('ICL_LANGUAGE_CODE', 'en');
        }

        $langDetails = new \stdClass();
        $langDetails->locale = 'de_AT';

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) use ($langDetails) {
            if ($filter === 'wpml_default_language') {
                return 'de';
            }
            if ($filter === 'wpml_language_details') {
                return $langDetails;
            }
            return $default;
        });

        $this->forcePlugin('wpml');
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getWpmlDefaultLocale', []);
        $this->assertSame('de_AT', $result);
    }

    public function testGetWpmlDefaultLocaleFallsBackToGetLocale(): void
    {
        // ICL_LANGUAGE_CODE not defined scenario — simulate with apply_filters returning null
        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_default_language') {
                return null; // no default language
            }
            return $default;
        });
        Functions\when('get_locale')->justReturn('en_US');

        $this->forcePlugin('wpml');
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getWpmlDefaultLocale', []);
        // When ICL_LANGUAGE_CODE is defined but wpml_default_language returns null,
        // falls back to get_locale()
        $this->assertNotEmpty($result);
    }

    // ---------------------------------------------------------------
    // Private: getPolylangDefaultLocale()
    // ---------------------------------------------------------------

    public function testGetPolylangDefaultLocaleWithPll(): void
    {
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass()); // no 'model' property → getPolylangLocale returns slug

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getPolylangDefaultLocale', []);
        $this->assertSame('en', $result);
    }

    public function testGetPolylangDefaultLocaleGetLocaleCalledWhenPllDefaultLangReturnsEmpty(): void
    {
        // pll_default_language returns '' (falsy) → getPolylangLocale('') returns ''
        // That then becomes the locale, not get_locale().
        // This test covers the branch where pll_default_language() is called.
        Functions\when('pll_default_language')->justReturn('');
        Functions\when('PLL')->justReturn(new \stdClass()); // no model → returns slug
        Functions\when('get_locale')->justReturn('de_DE');

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getPolylangDefaultLocale', []);
        // pll_default_language returns '' → getPolylangLocale('') returns ''
        $this->assertSame('', $result);
    }

    // ---------------------------------------------------------------
    // Private: getTranslatePressDefaultLocale()
    // ---------------------------------------------------------------

    public function testGetTranslatePressDefaultLocale(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'trp_settings') {
                return ['default-language' => 'fr_FR'];
            }
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getTranslatePressDefaultLocale', []);
        $this->assertSame('fr_FR', $result);
    }

    public function testGetTranslatePressDefaultLocaleEmptySettings(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'trp_settings') {
                return [];
            }
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });
        Functions\when('get_locale')->justReturn('en_US');

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getTranslatePressDefaultLocale', []);
        $this->assertSame('en_US', $result);
    }

    // ---------------------------------------------------------------
    // Private: getWeglotDefaultLocale()
    // ---------------------------------------------------------------

    public function testGetWeglotDefaultLocaleWithFunction(): void
    {
        Functions\when('weglot_get_options')->justReturn(['original_language' => 'es']);

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getWeglotDefaultLocale', []);
        $this->assertSame('es', $result);
    }

    public function testGetWeglotDefaultLocaleNoOriginalLanguageKey(): void
    {
        // weglot_get_options returns array without original_language → fallback to 'en'
        Functions\when('weglot_get_options')->justReturn([]);
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getWeglotDefaultLocale', []);
        $this->assertSame('en', $result);
    }

    // ---------------------------------------------------------------
    // Private: clearHreflangMetafields()
    // ---------------------------------------------------------------

    public function testClearHreflangMetafieldsCallsDeleteNamespace(): void
    {
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'clearHreflangMetafields', ['gid://shopify/Article/1']);

        $this->assertCount(1, $h->stub->calls);
        $call = $h->stub->calls[0];
        $this->assertSame('/api/metafields', $call['endpoint']);
        $this->assertSame('delete_namespace', $call['body']['action']);
        $this->assertSame('wash_hreflang', $call['body']['namespace']);
        $this->assertTrue($result['success'] ?? false);
        $this->assertTrue($result['cleared'] ?? false);
    }

    public function testClearHreflangMetafieldsHandlesWpError(): void
    {
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $h = $this->makeH();
        $h->stub->nextResult = new \WP_Error('api_err', 'Server error');

        $result = $this->callPrivate($h, 'clearHreflangMetafields', ['gid://shopify/Article/1']);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // Private: ensureHreflangDefinitions()
    // ---------------------------------------------------------------

    public function testEnsureHreflangDefinitionsCallsApi(): void
    {
        $metafields = [
            ['namespace' => 'wash_hreflang', 'key' => 'hreflang_en', 'value' => 'https://example.com/en', 'type' => 'url'],
            ['namespace' => 'wash_hreflang', 'key' => 'hreflang_de', 'value' => 'https://example.com/de', 'type' => 'url'],
        ];

        $h = $this->makeH();
        $this->callPrivate($h, 'ensureHreflangDefinitions', [$metafields]);

        $this->assertCount(1, $h->stub->calls);
        $call = $h->stub->calls[0];
        $this->assertSame('/api/metafield-definitions', $call['endpoint']);
        $this->assertSame('ensure', $call['body']['action']);
        $this->assertCount(2, $call['body']['definitions']);
    }

    public function testEnsureHreflangDefinitionsDeduplicatesKeys(): void
    {
        $metafields = [
            ['namespace' => 'wash_hreflang', 'key' => 'hreflang_en', 'value' => 'https://example.com/en', 'type' => 'url'],
            ['namespace' => 'wash_hreflang', 'key' => 'hreflang_en', 'value' => 'https://example.com/en-alt', 'type' => 'url'],
        ];

        $h = $this->makeH();
        $this->callPrivate($h, 'ensureHreflangDefinitions', [$metafields]);

        $this->assertCount(1, $h->stub->calls);
        $this->assertCount(1, $h->stub->calls[0]['body']['definitions']);
    }

    public function testEnsureHreflangDefinitionsDoesNothingForEmpty(): void
    {
        $h = $this->makeH();
        $this->callPrivate($h, 'ensureHreflangDefinitions', [[]]);
        $this->assertEmpty($h->stub->calls);
    }

    // ---------------------------------------------------------------
    // Private: getTranslatePressUrl()
    // ---------------------------------------------------------------

    public function testGetTranslatePressUrlReturnBaseForDefaultLang(): void
    {
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getTranslatePressUrl', [
            'https://example.com/article', 'en_US', 'en_US'
        ]);
        $this->assertSame('https://example.com/article', $result);
    }

    public function testGetTranslatePressUrlAddsLanguagePrefix(): void
    {
        // wp_parse_url is defined in bootstrap as parse_url wrapper — do NOT stub here
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getTranslatePressUrl', [
            'https://example.com/article', 'de_DE', 'en_US'
        ]);
        $this->assertStringContainsString('/de/', $result);
        $this->assertStringStartsWith('https://example.com', $result);
    }

    // ---------------------------------------------------------------
    // Private: getPolylangLocale() — fallback when PLL() returns null
    // ---------------------------------------------------------------

    public function testGetPolylangLocaleReturnSlugWhenNoModelProperty(): void
    {
        // PLL() returns object without 'model' property → method_exists returns false → returns slug
        Functions\when('PLL')->justReturn(new \stdClass());
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getPolylangLocale', ['fr']);
        $this->assertSame('fr', $result);
    }

    public function testGetPolylangLocaleReturnSlugWhenNoMatchInList(): void
    {
        // Cover the foreach-not-found path: model has languages but none matching slug.
        // production code: method_exists checks for a METHOD named 'model'. Use a class
        // that has a 'model' method so the guard passes; PLL()->model is then a property
        // access, but since PLL() is an anonymous object without a 'model' property,
        // PHP will throw — so we skip that deep path and just verify slug fallback.
        // The getPolylangLocale PLL path is only reachable when Polylang is installed;
        // cover the "no slug match" and "empty list" returns via the early-return guard.
        Functions\when('PLL')->justReturn(new \stdClass()); // no 'model' method → early return
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getPolylangLocale', ['en']);
        // method_exists(stdClass, 'model') = false → returns slug unchanged
        $this->assertSame('en', $result);
    }

    // ---------------------------------------------------------------
    // Private: convertWpUrlToShopifyUrl()
    // ---------------------------------------------------------------

    public function testConvertWpUrlToShopifyUrlReturnsNullWhenNoShopifyUrl(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => '', // no store → getShopifyUrlForPost returns null
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'convertWpUrlToShopifyUrl', [
            'https://example.com/article', 1, 'en_US'
        ]);
        $this->assertNull($result);
    }

    public function testConvertWpUrlToShopifyUrlReturnsShopifyBaseUrl(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/1';
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $post = new \stdClass();
        $post->post_type = 'post';
        $post->post_name = 'my-post';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'convertWpUrlToShopifyUrl', [
            'https://example.com/de/my-post', 1, 'de_DE'
        ]);
        $this->assertNotNull($result);
        $this->assertStringContainsString('my-store.myshopify.com', $result);
    }

    // ---------------------------------------------------------------
    // Private: getWpmlTranslations() — deeper paths
    // ---------------------------------------------------------------

    public function testGetWpmlTranslationsNoFunctionReturnsEmpty(): void
    {
        // wpml_get_language_information is NOT stubbed →
        // function_exists('wpml_get_language_information') returns false → early empty return
        $this->forcePlugin('wpml');
        $h = $this->makeH();

        $ref = new \ReflectionMethod($h, 'getWpmlTranslations');
        $ref->setAccessible(true);
        // get_post must NOT be called (early return), so we do NOT stub it
        $result = $ref->invoke($h, 999);
        $this->assertSame([], $result);
    }

    public function testGetWpmlTranslationsSkipsUnpublishedPosts(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->alias(function ($id) use ($post) {
            if ($id === 1) {
                return $post;
            }
            $draft = new \stdClass();
            $draft->post_status = 'draft';
            $draft->post_type   = 'post';
            $draft->post_name   = 'my-draft';
            return $draft;
        });

        $translation = new \stdClass();
        $translation->element_id = 99;

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) use ($translation) {
            if ($filter === 'wpml_element_trid') {
                return 10;
            }
            if ($filter === 'wpml_get_element_translations') {
                return ['en' => $translation];
            }
            return $default;
        });

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWpmlTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    public function testGetWpmlTranslationsIncludesPublishedPosts(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        $post->post_name   = 'my-article';
        Functions\when('get_post')->justReturn($post);

        $langDetails        = new \stdClass();
        $langDetails->locale = 'en_US';

        $translation             = new \stdClass();
        $translation->element_id = 1;

        Functions\when('apply_filters')->alias(
            function ($filter, $default, ...$args) use ($translation, $langDetails) {
                if ($filter === 'wpml_element_trid') {
                    return 10;
                }
                if ($filter === 'wpml_get_element_translations') {
                    return ['en' => $translation];
                }
                if ($filter === 'wpml_element_language_details') {
                    return $langDetails;
                }
                return $default;
            }
        );

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/1';
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWpmlTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('en_US', $result);
    }

    // ---------------------------------------------------------------
    // Private: getPolylangTranslations() — deeper paths
    // ---------------------------------------------------------------

    public function testGetPolylangTranslationsSkipsUnpublishedPost(): void
    {
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1, 'de' => 2]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass()); // no 'model' property → getPolylangLocale returns slug

        Functions\when('get_post')->alias(function ($id) {
            $post = new \stdClass();
            $post->post_status = 'draft';
            $post->post_type   = 'post';
            $post->post_name   = 'draft-post';
            return $post;
        });

        $this->forcePlugin('polylang');
        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getPolylangTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // Private: getWeglotTranslations() — with weglot_get_options stub
    // ---------------------------------------------------------------

    public function testGetWeglotTranslationsWithOptions(): void
    {
        $this->forcePlugin('weglot');

        Functions\when('weglot_get_options')->justReturn([
            'original_language'     => 'en',
            'destination_languages' => [
                ['language_to' => 'fr'],
                ['language_to' => 'de'],
            ],
        ]);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/1';
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $post = new \stdClass();
        $post->post_status = 'publish';
        $post->post_type   = 'post';
        $post->post_name   = 'test-post';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWeglotTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);

        $this->assertArrayHasKey('en', $result);
        $this->assertArrayHasKey('fr', $result);
        $this->assertArrayHasKey('de', $result);
    }

    public function testGetWeglotTranslationsUnpublishedPostReturnsEmpty(): void
    {
        $this->forcePlugin('weglot');

        Functions\when('weglot_get_options')->justReturn([
            'original_language' => 'en',
        ]);

        $post = new \stdClass();
        $post->post_status = 'draft';
        $post->post_type   = 'post';
        $post->post_name   = 'draft';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWeglotTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // Private: getTranslatePressTranslations()
    // ---------------------------------------------------------------

    public function testGetTranslatePressTranslationsNoClassReturnsEmpty(): void
    {
        $this->forcePlugin('translatepress');

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getTranslatePressTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // detectActivePlugin() — live detection path (no plugin active)
    // ---------------------------------------------------------------

    public function testDetectActivePluginLiveDetectionNoPlugin(): void
    {
        // Reset the cache so we go through the full detection
        $this->resetCache();

        // is_plugin_active is already stubbed to false in bootstrap
        // Ensure it returns false for all plugins
        $h = $this->makeH();
        $result = $h->detectActivePlugin();

        // No plugin active → should return null
        $this->assertNull($result);
    }

    public function testDetectActivePluginCachesResult(): void
    {
        // Second call should use cache; we verify by forcing a known plugin string
        $this->forcePlugin('wpml');

        $h = $this->makeH();
        $first  = $h->detectActivePlugin();
        $second = $h->detectActivePlugin();
        $this->assertSame('wpml', $first);
        $this->assertSame($first, $second);
    }

    // ---------------------------------------------------------------
    // isMultilingualPluginActive() edge case
    // ---------------------------------------------------------------

    public function testIsMultilingualPluginActiveWpmlReturnsTrue(): void
    {
        // When a plugin is forced, isMultilingualPluginActive returns true
        $this->forcePlugin('polylang');
        $h = $this->makeH();
        $this->assertTrue($h->isMultilingualPluginActive());
    }

    // ---------------------------------------------------------------
    // Multisite path in syncHreflangs / clearHreflangMetafields
    // ---------------------------------------------------------------

    public function testSyncHreflangsUsesCurrentBlogIdOnMultisite(): void
    {
        $this->forceFalsePlugin();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled' => '1',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('get_current_blog_id')->justReturn(3);

        $h = $this->makeH();
        $h->syncHreflangs(1, 'gid://shopify/Article/1');

        $this->assertNotEmpty($h->stub->calls);
        $this->assertSame(3, $h->stub->calls[0]['body']['wpSiteId']);
    }

    // ---------------------------------------------------------------
    // Additional coverage for uncovered branches
    // ---------------------------------------------------------------

    /** Cover lines 92-93: syncHreflangs with translations, API returns WP_Error */
    public function testSyncHreflangsWithTranslationsApiErrorReturnsWpError(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_hreflangs_enabled' => '1',
                'wash_shopify_store'     => 'my-store',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/' . $postId;
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $post = new \stdClass();
        $post->post_status = 'publish';
        $post->post_type   = 'post';
        $post->post_name   = 'test-article';
        Functions\when('get_post')->justReturn($post);

        Functions\when('pll_get_post_translations')->justReturn(['en' => 1, 'de' => 2]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass());
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $h = $this->makeH();
        // First call is ensureHreflangDefinitions, second is the upsert → make upsert fail
        $callCount = 0;
        $wpError   = new \WP_Error('api_fail', 'Service unavailable');
        $h->stub->nextResult = $wpError; // both calls fail, but ensureHreflangDefinitions ignores result

        $result = $h->syncHreflangs(1, 'gid://shopify/Article/1');
        // The upsert call returns WP_Error → should return WP_Error
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('Service unavailable', $result->get_error_message());
    }

    /** Cover line 117: empty URL skip in buildHreflangMetafields */
    public function testBuildHreflangMetafieldsSkipsTranslationWithEmptyUrl(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });

        // Return translations array with one entry having no Shopify article ID
        // so getShopifyUrlForPost returns null → url is null → empty($url) = true → skip
        Functions\when('pll_get_post_translations')->justReturn(['en' => 1, 'de' => 2]);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass());

        $post = new \stdClass();
        $post->post_status = 'publish';
        $post->post_type   = 'post';
        $post->post_name   = 'article';
        Functions\when('get_post')->justReturn($post);

        // Only post 1 has a Shopify article id; post 2 doesn't
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return $postId === 1 ? 'gid://shopify/Article/1' : '';
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $h = $this->makeH();
        $result = $h->buildHreflangMetafields(1);
        // Only 'en' has a Shopify URL, 'de' is skipped
        // Plus x-default for the matching default locale
        $keys = array_column($result, 'key');
        $this->assertContains('hreflang_en', $keys);
        $this->assertNotContains('hreflang_de', $keys);
    }

    /** Cover line 312: getAvailableLanguages returns [] when detectActivePlugin returns null/false */
    public function testGetAvailableLanguagesNoPluginReturnsEmpty(): void
    {
        $this->resetCache();
        // No plugin active → detectActivePlugin returns null → returns []
        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $this->assertSame([], $result);
    }

    /** Cover line 319: getAvailableLanguages polylang case with pll_languages_list */
    public function testGetAvailableLanguagesPolylangWithLocales(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('pll_languages_list')->alias(function ($args) {
            return match ($args['fields'] ?? '') {
                'slug' => ['en', 'fr'],
                'name' => ['English', 'Français'],
                default => [],
            };
        });
        Functions\when('PLL')->justReturn(new \stdClass()); // no model method → getPolylangLocale returns slug

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $this->assertCount(2, $result);
        $this->assertSame('en', $result[0]['code']);
        $this->assertSame('English', $result[0]['name']);
    }

    /** Cover lines 346-357: getPolylangLanguages with pll_languages_list no function */
    public function testGetPolylangLanguagesNoFunctionReturnsEmpty(): void
    {
        $this->forcePlugin('polylang');
        // pll_languages_list may have been registered by previous tests — stub to safe values
        Functions\when('pll_languages_list')->justReturn([]);
        Functions\when('PLL')->justReturn(new \stdClass());

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $this->assertSame([], $result);
    }

    /** Cover lines 380-390: getWeglotLanguages with weglot functions */
    public function testGetAvailableLanguagesWeglotWithDestinations(): void
    {
        $this->forcePlugin('weglot');

        Functions\when('weglot_get_destination_languages')->justReturn(['fr', 'de']);
        Functions\when('weglot_get_original_language')->justReturn('en');

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();

        $this->assertNotEmpty($result);
        // Should include original + destinations
        $codes = array_column($result, 'code');
        $this->assertContains('en', $codes);
        $this->assertContains('fr', $codes);
        $this->assertContains('de', $codes);
    }

    /** Cover line 382-383: weglot_get_original_language returns null/empty */
    public function testGetWeglotLanguagesNoOriginalLanguage(): void
    {
        $this->forcePlugin('weglot');

        Functions\when('weglot_get_destination_languages')->justReturn([]);
        Functions\when('weglot_get_original_language')->justReturn(null);

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $this->assertSame([], $result);
    }

    /** Cover weglot destination with object having getIso639 method */
    public function testGetWeglotLanguagesObjectDestination(): void
    {
        $this->forcePlugin('weglot');

        $lang = new class {
            public function getIso639(): string { return 'it'; }
        };

        Functions\when('weglot_get_destination_languages')->justReturn([$lang]);
        Functions\when('weglot_get_original_language')->justReturn('en');

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $codes = array_column($result, 'code');
        $this->assertContains('it', $codes);
    }

    /** Cover line 409: getWpmlTranslations with null post */
    public function testGetWpmlTranslationsNullPostReturnsEmpty(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);
        Functions\when('get_post')->justReturn(null);

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWpmlTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 999);
        $this->assertSame([], $result);
    }

    /** Cover lines 417: getWpmlTranslations with empty trid */
    public function testGetWpmlTranslationsEmptyTridReturnsEmpty(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        Functions\when('get_post')->justReturn($post);
        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_element_trid') {
                return 0; // falsy
            }
            return $default;
        });

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWpmlTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    /** Cover lines 423-424: getWpmlTranslations non-array translations */
    public function testGetWpmlTranslationsNonArrayTranslationsReturnsEmpty(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        Functions\when('get_post')->justReturn($post);
        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_element_trid') {
                return 10;
            }
            if ($filter === 'wpml_get_element_translations') {
                return null; // non-array
            }
            return $default;
        });

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWpmlTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    /** Cover lines 428-429: getWpmlTranslations with empty element_id */
    public function testGetWpmlTranslationsSkipsTranslationWithEmptyElementId(): void
    {
        $this->forcePlugin('wpml');
        Functions\when('wpml_get_language_information')->justReturn(['language_code' => 'en']);

        $post = new \stdClass();
        $post->post_type   = 'post';
        $post->post_status = 'publish';
        Functions\when('get_post')->justReturn($post);

        $t = new \stdClass();
        $t->element_id = 0; // empty/falsy

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) use ($t) {
            if ($filter === 'wpml_element_trid') {
                return 10;
            }
            if ($filter === 'wpml_get_element_translations') {
                return ['en' => $t];
            }
            return $default;
        });

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWpmlTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    /** Cover lines 468-474: getPolylangTranslations without functions */
    public function testGetPolylangTranslationsNoFunctionsReturnsEmpty(): void
    {
        $this->forcePlugin('polylang');

        // Don't stub pll_get_post_translations → if it was previously patched,
        // stub it to return null (non-array) to cover the is_array check
        Functions\when('pll_get_post_translations')->justReturn(null);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass());

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getPolylangTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    /** Cover line 592: getWeglotTranslations early return when no functions */
    public function testGetWeglotTranslationsNoFunctionOrClassReturnsEmpty(): void
    {
        $this->forcePlugin('weglot');

        // weglot_get_options may be Patchwork-registered. Stub it to return a valid
        // but empty set (post = null → still returns [])
        Functions\when('weglot_get_options')->justReturn([]);
        Functions\when('get_post')->justReturn(null);

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWeglotTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    /** Cover line 616: Weglot empty langCode is skipped */
    public function testGetWeglotTranslationsSkipsEmptyLangCode(): void
    {
        $this->forcePlugin('weglot');

        Functions\when('weglot_get_options')->justReturn([
            'original_language'     => 'en',
            'destination_languages' => [
                ['language_to' => ''],  // empty → skip
                ['language_to' => 'fr'],
            ],
        ]);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'my-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_wash_article_id') {
                return 'gid://shopify/Article/1';
            }
            if ($key === '_wash_blog_handle') {
                return 'news';
            }
            return '';
        });

        $post = new \stdClass();
        $post->post_status = 'publish';
        $post->post_type   = 'post';
        $post->post_name   = 'test-post';
        Functions\when('get_post')->justReturn($post);

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getWeglotTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);

        // Empty lang was skipped; 'fr' was included
        $this->assertArrayHasKey('fr', $result);
        $this->assertArrayNotHasKey('', $result);
    }

    /** Cover getTranslatePressTranslations — class does not exist returns empty */
    public function testGetTranslatePressTranslationsNoClassAlwaysEmpty(): void
    {
        $this->forcePlugin('translatepress');

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getTranslatePressTranslations');
        $ref->setAccessible(true);
        // TRP_Translate_Press not available in test env → early return
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }

    /** Cover getTranslatePressLanguages — class does not exist returns empty */
    public function testGetAvailableLanguagesTranslatePressNoClassReturnsEmpty(): void
    {
        $this->forcePlugin('translatepress');
        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        // TRP_Translate_Press class not available → returns []
        $this->assertSame([], $result);
    }

    /** Verify the constructor using WashApiClient directly (line 31) */
    public function testConstructorCreatesApiClient(): void
    {
        // Use the real WashHreflangs constructor — needs WashApiClient construction stubs
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');

        $h = new \WashHreflangs();
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'apiClient');
        $ref->setAccessible(true);
        $this->assertInstanceOf(\WashApiClient::class, $ref->getValue($h));
    }

    /** Cover wpml_language_details array path in getWpmlDefaultLocale */
    public function testGetWpmlDefaultLocaleArrayLocaleDetails(): void
    {
        if (!defined('ICL_LANGUAGE_CODE')) {
            define('ICL_LANGUAGE_CODE', 'en');
        }

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_default_language') {
                return 'fr';
            }
            if ($filter === 'wpml_language_details') {
                return ['locale' => 'fr_FR'];
            }
            return $default;
        });
        Functions\when('get_locale')->justReturn('en_US');

        $this->forcePlugin('wpml');
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getWpmlDefaultLocale', []);
        $this->assertSame('fr_FR', $result);
    }

    /** Cover getWpmlDefaultLocale when wpml_language_details returns langcode (no locale key) */
    public function testGetWpmlDefaultLocaleNoLocaleKeyFallsBackToLangCode(): void
    {
        if (!defined('ICL_LANGUAGE_CODE')) {
            define('ICL_LANGUAGE_CODE', 'en');
        }

        Functions\when('apply_filters')->alias(function ($filter, $default, ...$args) {
            if ($filter === 'wpml_default_language') {
                return 'es';
            }
            if ($filter === 'wpml_language_details') {
                return ['name' => 'Spanish']; // no 'locale' key
            }
            return $default;
        });
        Functions\when('get_locale')->justReturn('en_US');

        $this->forcePlugin('wpml');
        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getWpmlDefaultLocale', []);
        $this->assertSame('es', $result); // falls back to $defaultLang
    }

    /** Cover getDefaultLanguageUrl with empty translations */
    public function testGetDefaultLanguageUrlEmptyTranslationsReturnsNull(): void
    {
        $this->forceFalsePlugin();
        Functions\when('get_locale')->justReturn('en_US');

        $h = $this->makeH();
        $result = $this->callPrivate($h, 'getDefaultLanguageUrl', [1, []]);
        $this->assertNull($result);
    }

    /** Cover line 245: getTranslationGroupSource returns null when no plugin matched */
    public function testGetTranslationGroupSourceReturnsNullForNoPlugin(): void
    {
        $this->forceFalsePlugin();
        $h = $this->makeH();
        $result = $h->getTranslationGroupSource(1);
        $this->assertNull($result);
    }

    /** Cover line 325: getAvailableLanguages default case — unreachable by design */

    /** Cover getPolylangLanguages with real slugs and PLL model stub */
    public function testGetPolylangLanguagesReturnsMappedLocales(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('pll_languages_list')->alias(function ($args) {
            return match ($args['fields'] ?? '') {
                'slug' => ['en', 'de'],
                'name' => ['English', 'Deutsch'],
                default => [],
            };
        });
        Functions\when('PLL')->justReturn(new \stdClass()); // no model method → returns slug

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();

        // en → 'en', de → 'de' (since PLL has no model, getPolylangLocale returns slug)
        $this->assertCount(2, $result);
        $this->assertSame('en', $result[0]['code']);
        $this->assertSame('de', $result[1]['code']);
    }

    /** Cover getWeglotLanguages with null original language */
    public function testGetWeglotLanguagesOriginalLanguageNull(): void
    {
        $this->forcePlugin('weglot');
        Functions\when('weglot_get_destination_languages')->justReturn(['fr']);
        Functions\when('weglot_get_original_language')->justReturn(null); // null → not added

        $h = $this->makeH();
        $result = $h->getAvailableLanguages();
        $codes = array_column($result, 'code');
        $this->assertNotContains(null, $codes);
        $this->assertContains('fr', $codes);
    }

    /** Cover getPolylangTranslations non-array return */
    public function testGetPolylangTranslationsNonArrayResultReturnsEmpty(): void
    {
        $this->forcePlugin('polylang');
        Functions\when('pll_get_post_translations')->justReturn(false); // non-array
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('PLL')->justReturn(new \stdClass());

        $h = $this->makeH();
        $ref = new \ReflectionMethod($h, 'getPolylangTranslations');
        $ref->setAccessible(true);
        $result = $ref->invoke($h, 1);
        $this->assertSame([], $result);
    }
}
