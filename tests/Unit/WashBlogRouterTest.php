<?php
/**
 * Tests for WashBlogRouter (#255) — the storefront-URL generator and publish gate.
 *
 * Decisive cases:
 * - The market subfolder comes from Shopify Markets rootUrls (NOT ruleValue):
 *   a rule WITH a market resolves to /de-ch/…, a rule WITHOUT a market falls
 *   back to the primary domain (no subfolder).
 * - hasMapping() gates publishing: matches → true, no match → false, no rules
 *   cached → true (fail-open).
 * - matchRule() is priority-first and language-variant aware (en-gb → en).
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-blog-router.php';

class WashBlogRouterTest extends TestCase
{
    private array $options = [];
    private array $meta = [];
    private array $posts = [];
    private array $transients = [];

    private const SW_MARKET = 'gid://shopify/Market/SW';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = ['wash_shopify_store' => 'test-store'];
        $this->meta = [];
        $this->posts = [];
        $this->transients = [];
        $_GET = [];
        $_POST = [];
        \WashContext::setActiveId(null);

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('get_post_meta')->alias(fn($id, $key, $single = true) => $this->meta[$id][$key] ?? '');
        Functions\when('get_post')->alias(fn($id) => $this->posts[$id] ?? null);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('is_wp_error')->alias(fn($x) => $x instanceof \WP_Error);

        // The router memoizes fetched lists in a STATIC array that survives between
        // tests in the same process — clear it so each test sees its own transients.
        \WashBlogRouter::flushCache();
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    // -- arrange helpers ---------------------------------------------------

    private function setRules(array $rules): void
    {
        $this->transients['wash_blog_mapping_rules'] = $rules;
    }

    private function setMarkets(array $markets): void
    {
        $this->transients['wash_shopify_markets'] = $markets;
    }

    private function setBlogs(array $blogs): void
    {
        $this->transients['wash_shopify_blogs'] = $blogs;
    }

    private function makePost(int $id, string $lang, string $type = 'post', array $extra = []): void
    {
        $this->posts[$id] = (object) ['ID' => $id, 'post_type' => $type, 'post_name' => 'slug-' . $id];
        $this->meta[$id] = array_merge([
            '_wash_language'   => $lang,
            '_wash_article_id' => 'gid://shopify/Article/1',
            '_wash_handle'     => 'the-handle',
        ], $extra);
    }

    private function langRule(string $value, ?string $market, string $blog = 'articles', int $priority = 10): array
    {
        return [
            'ruleType' => 'language', 'ruleValue' => $value, 'shopifyLocale' => substr($value, 0, 2),
            'shopifyMarketId' => $market, 'shopifyBlogHandle' => $blog, 'destination' => 'article',
            'priority' => $priority, 'isActive' => true, 'createdAt' => '2026-01-01T00:00:00Z',
        ];
    }

    private function switzerlandMarket(): array
    {
        return [
            'id' => self::SW_MARKET,
            'webPresence' => ['rootUrls' => [
                ['locale' => 'de', 'url' => 'https://test-store.myshopify.com/de-ch/'],
                ['locale' => 'fr', 'url' => 'https://test-store.myshopify.com/fr-ch/'],
            ]],
        ];
    }

    // -- hasMapping (publish gate) -----------------------------------------

    public function testHasMappingTrueWhenLanguageRuleMatches(): void
    {
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(10, 'de-ch');
        $this->assertTrue(\WashBlogRouter::hasMapping(10));
    }

    public function testHasMappingFalseWhenNoRuleMatches(): void
    {
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(11, 'pl-pl');
        $this->assertFalse(\WashBlogRouter::hasMapping(11));
    }

    public function testHasMappingFailsOpenWhenNoRulesCached(): void
    {
        $this->setRules([]);
        $this->makePost(12, 'pl-pl');
        $this->assertTrue(\WashBlogRouter::hasMapping(12));
    }

    public function testHasMappingTrueWithDefaultRule(): void
    {
        $this->setRules([[
            'ruleType' => 'default', 'ruleValue' => null, 'destination' => 'article',
            'shopifyBlogHandle' => 'news', 'priority' => 0, 'isActive' => true, 'createdAt' => '2026-01-01',
        ]]);
        $this->makePost(13, 'pl-pl');
        $this->assertTrue(\WashBlogRouter::hasMapping(13));
    }

    public function testHasMappingMatchesBaseLanguageVariant(): void
    {
        // Rule on bare "en"; post is "en-gb" → expandLangVariants includes "en".
        $this->setRules([$this->langRule('en', null)]);
        $this->makePost(14, 'en-gb');
        $this->assertTrue(\WashBlogRouter::hasMapping(14));
    }

    // -- shouldSkipPublish (multi-store routing gate) -----------------------

    /** Register one enabled secondary store so enabledCount() reports 2+. */
    private function addSecondStore(): void
    {
        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'store' => 'shop2', 'apiKey' => 'k2', 'enabled' => true, 'isPrimary' => false],
        ];
    }

    public function testShouldSkipPublishFalseForSingleStoreWithNoRules(): void
    {
        // Legacy: one store, zero rules → default (first-blog) publish is allowed.
        $this->setRules([]);
        $this->makePost(20, 'pl-pl');
        $this->assertFalse(\WashBlogRouter::shouldSkipPublish(20));
    }

    public function testShouldSkipPublishFalseWhenRuleMatches(): void
    {
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(21, 'de-ch');
        $this->assertFalse(\WashBlogRouter::shouldSkipPublish(21));
    }

    public function testShouldSkipPublishTrueWhenRulesExistButNoneMatch(): void
    {
        // Single store WITH rules; post matches none → skip (no default publish).
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(22, 'pl-pl');
        $this->assertTrue(\WashBlogRouter::shouldSkipPublish(22));
    }

    public function testShouldSkipPublishTrueForMultiStoreEvenWithoutRules(): void
    {
        // 2+ stores connected → never default publish, even for a store with no rules
        // (e.g. an empty secondary). This is the US-post-must-not-land-on-UK guard.
        $this->addSecondStore();
        $this->setRules([]);
        $this->makePost(23, 'pl-pl');
        $this->assertTrue(\WashBlogRouter::shouldSkipPublish(23));
    }

    public function testShouldSkipPublishFalseForMultiStoreWhenRuleMatches(): void
    {
        $this->addSecondStore();
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(24, 'de-ch');
        $this->assertFalse(\WashBlogRouter::shouldSkipPublish(24));
    }

    public function testShouldSkipPublishTrueForMultiStoreWhenRulesExistButNoneMatch(): void
    {
        $this->addSecondStore();
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(25, 'en-us');
        $this->assertTrue(\WashBlogRouter::shouldSkipPublish(25));
    }

    // -- matchRule priority -------------------------------------------------

    public function testMatchRulePicksHighestPriority(): void
    {
        $this->setRules([
            $this->langRule('de-ch', self::SW_MARKET, 'low-prio-blog', 10),
            $this->langRule('de-ch', self::SW_MARKET, 'win-blog', 90),
        ]);
        $this->makePost(15, 'de-ch');
        $rule = \WashBlogRouter::matchRule(15, 'article');
        $this->assertSame('win-blog', $rule['shopifyBlogHandle']);
    }

    // -- storefrontUrl: market subfolder vs primary ------------------------

    public function testStorefrontUrlUsesRealMarketSubfolder(): void
    {
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET, 'articles')]);
        $this->setMarkets([$this->switzerlandMarket()]);
        $this->makePost(20, 'de-ch');
        $this->assertSame(
            'https://test-store.myshopify.com/de-ch/blogs/articles/the-handle',
            \WashBlogRouter::storefrontUrl(20)
        );
    }

    public function testStorefrontUrlDerivesMarketFromRuleValueCountry(): void
    {
        // No shopifyMarketId on the rule — derive the market from the ruleValue's
        // country (fr-fr → FR), matched against market regions, so the subfolder
        // comes straight from the blog mapping with no market field (#255).
        $this->setRules([$this->langRule('fr-fr', null, 'articles')]);
        $this->setMarkets([[
            'id' => 'gid://shopify/Market/EU', 'primary' => false,
            'regions' => [['code' => 'FR'], ['code' => 'DE']],
            'webPresence' => ['rootUrls' => [
                ['locale' => 'fr', 'url' => 'https://test-store.myshopify.com/fr-eu/'],
                ['locale' => 'de', 'url' => 'https://test-store.myshopify.com/de-eu/'],
            ]],
        ]]);
        $this->makePost(27, 'fr-fr');
        $this->assertSame(
            'https://test-store.myshopify.com/fr-eu/blogs/articles/the-handle',
            \WashBlogRouter::storefrontUrl(27)
        );
    }

    public function testStorefrontUrlFallsBackToPrimaryWhenRuleHasNoMarket(): void
    {
        // de-de rule with NO market → no localized subfolder → primary domain.
        $this->setRules([$this->langRule('de-de', null, 'articles')]);
        $this->setMarkets([$this->switzerlandMarket()]);
        $this->makePost(21, 'de-de');
        $this->assertSame(
            'https://test-store.myshopify.com/blogs/articles/the-handle',
            \WashBlogRouter::storefrontUrl(21)
        );
    }

    public function testStorefrontUrlUsesPrimaryMarketDomainForNoMarketRule(): void
    {
        // No-market rule → the PRIMARY market's real domain (the merchant's custom
        // domain), not the {store}.myshopify.com fallback (#255 issue comment).
        $this->setRules([$this->langRule('de-de', null, 'articles')]);
        $this->setMarkets([[
            'id' => 'gid://shopify/Market/PRIMARY', 'primary' => true,
            'webPresence' => ['domainUrl' => 'https://shop.example.com', 'rootUrls' => []],
        ]]);
        $this->makePost(26, 'de-de');
        $this->assertSame(
            'https://shop.example.com/blogs/articles/the-handle',
            \WashBlogRouter::storefrontUrl(26)
        );
    }

    public function testStorefrontUrlBuildsPageUrlByPostType(): void
    {
        // A page is routed by a destination=page rule; the market subfolder still applies.
        $this->setRules([[
            'ruleType' => 'language', 'ruleValue' => 'de-ch', 'shopifyLocale' => 'de',
            'shopifyMarketId' => self::SW_MARKET, 'destination' => 'page', 'shopifyBlogHandle' => '',
            'priority' => 10, 'isActive' => true, 'createdAt' => '2026-01-01',
        ]]);
        $this->setMarkets([$this->switzerlandMarket()]);
        $this->makePost(22, 'de-ch', 'page');
        $this->assertSame(
            'https://test-store.myshopify.com/de-ch/pages/the-handle',
            \WashBlogRouter::storefrontUrl(22)
        );
    }

    public function testStorefrontUrlNullWhenNotSynced(): void
    {
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(23, 'de-ch', 'post', ['_wash_article_id' => '', '_wash_page_id' => '']);
        $this->assertNull(\WashBlogRouter::storefrontUrl(23));
    }

    public function testStorefrontUrlNullWhenNoStore(): void
    {
        $this->options['wash_shopify_store'] = '';
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET)]);
        $this->makePost(24, 'de-ch');
        $this->assertNull(\WashBlogRouter::storefrontUrl(24));
    }

    public function testStorefrontUrlPrefersActualBlogFromBlogId(): void
    {
        // Translation variant: article actually lives in blog "articles" (via blog_id),
        // even though the matched rule points to "press".
        $this->setRules([$this->langRule('de-ch', self::SW_MARKET, 'press')]);
        $this->setMarkets([$this->switzerlandMarket()]);
        $this->setBlogs([['id' => 'gid://shopify/Blog/99', 'handle' => 'articles']]);
        $this->makePost(25, 'de-ch', 'post', ['_wash_blog_id' => 'gid://shopify/Blog/99']);
        $this->assertSame(
            'https://test-store.myshopify.com/de-ch/blogs/articles/the-handle',
            \WashBlogRouter::storefrontUrl(25)
        );
    }
}
