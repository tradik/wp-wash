<?php
/**
 * WashBlogRouter's rule helpers (#255): the PHP ports of the worker's
 * ruleMatches(), v1 rule ordering and expandLangVariants(), plus category-slug
 * lookup. Pure functions, so they are exercised directly via reflection.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-blog-router.php';

class WashBlogRouterRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->alias(fn($thing) => $thing instanceof \WP_Error);
    }

    protected function tearDown(): void
    {
        $this->seedApiMemo([]);
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return mixed */
    private function call(string $method, array $args)
    {
        $ref = new \ReflectionMethod(\WashBlogRouter::class, $method);
        return $ref->invokeArgs(null, $args);
    }

    private function ruleApplies(string $type, string $value, array $langs, array $cats): bool
    {
        return $this->call('ruleMatches', [['ruleType' => $type, 'ruleValue' => $value], $langs, $cats]);
    }

    public function testDefaultRuleAlwaysMatches(): void
    {
        $this->assertTrue($this->ruleApplies('default', '', [], []));
    }

    public function testLanguageRuleMatchesAnyVariant(): void
    {
        $this->assertTrue($this->ruleApplies('language', 'en', ['en-gb', 'en', 'gb', 'uk'], []));
        $this->assertFalse($this->ruleApplies('language', 'de', ['en-gb', 'en'], []));
    }

    public function testCategoryRuleMatchesASlug(): void
    {
        $this->assertTrue($this->ruleApplies('category', 'news', [], ['news', 'tips']));
        $this->assertFalse($this->ruleApplies('category', 'sale', [], ['news']));
    }

    public function testLanguageCategoryNeedsBothHalves(): void
    {
        $this->assertTrue($this->ruleApplies('language_category', 'en:news', ['en'], ['news']));
        $this->assertFalse($this->ruleApplies('language_category', 'en:news', ['de'], ['news']));
        $this->assertFalse($this->ruleApplies('language_category', 'en:news', ['en'], ['tips']));
        $this->assertFalse($this->ruleApplies('language_category', 'en-news', ['en'], ['news']), 'no separator, no match');
    }

    public function testUnknownOrMissingRuleTypeNeverMatches(): void
    {
        $this->assertFalse($this->ruleApplies('regex', '.*', ['en'], ['news']));
        $this->assertFalse($this->call('ruleMatches', [[], ['en'], ['news']]));
    }

    public function testRulesSortByPriorityThenSpecificityThenAge(): void
    {
        $rules = [
            ['id' => 'default-new', 'ruleType' => 'default', 'priority' => 0, 'createdAt' => '2026-02-01'],
            ['id' => 'category', 'ruleType' => 'category', 'priority' => 0, 'createdAt' => '2026-01-01'],
            ['id' => 'priority', 'ruleType' => 'default', 'priority' => 5, 'createdAt' => '2026-03-01'],
            ['id' => 'lang-cat', 'ruleType' => 'language_category', 'priority' => 0, 'createdAt' => '2026-03-01'],
            ['id' => 'default-old', 'ruleType' => 'default', 'priority' => 0, 'createdAt' => '2026-01-01'],
            ['id' => 'unknown', 'ruleType' => 'regex'],
        ];

        $ref = new \ReflectionMethod(\WashBlogRouter::class, 'sortRules');
        $args = [&$rules];
        $ref->invokeArgs(null, $args);

        $this->assertSame(
            ['priority', 'lang-cat', 'category', 'default-old', 'default-new', 'unknown'],
            array_column($rules, 'id')
        );
    }

    public function testExpandLangVariantsAddsLanguageRegionAndAlias(): void
    {
        $this->assertSame(['en-gb', 'en', 'gb', 'uk'], $this->call('expandLangVariants', ['en-gb']));
        $this->assertSame(['en-uk', 'en', 'uk', 'gb'], $this->call('expandLangVariants', ['en-uk']));
        $this->assertSame(['de-ch', 'de', 'ch'], $this->call('expandLangVariants', ['de-ch']));
        $this->assertSame(['fr'], $this->call('expandLangVariants', ['fr']));
    }

    public function testCategorySlugsListsThePostsCategories(): void
    {
        Functions\expect('get_the_terms')->once()->with(42, 'category')->andReturn([
            (object) ['slug' => 'news'],
            (object) ['name' => 'no slug'],
            (object) ['slug' => 'tips'],
        ]);

        $this->assertSame(['news', 'tips'], $this->call('categorySlugs', [42]));
    }

    public function testCategorySlugsIsEmptyWithoutTermsOrOnError(): void
    {
        Functions\when('get_the_terms')->justReturn(false);
        $this->assertSame([], $this->call('categorySlugs', [1]));

        Functions\when('get_the_terms')->justReturn(new \WP_Error('invalid_taxonomy', 'nope'));
        $this->assertSame([], $this->call('categorySlugs', [1]));
    }

    /** Seed the per-request API list memo, as if the app had answered. */
    private function seedApiMemo(array $memo): void
    {
        $prop = new \ReflectionProperty(\WashBlogRouter::class, 'apiMemo');
        $prop->setValue(null, $memo);
    }

    public function testBlogHandleForIdResolvesFromTheCachedBlogs(): void
    {
        \WashContext::setActiveId('primary');
        $this->seedApiMemo(['wash_shopify_blogs|primary' => [
            ['id' => 'gid://shopify/Blog/1', 'handle' => 'news'],
            ['id' => 'gid://shopify/Blog/2'],
        ]]);

        $this->assertSame('news', $this->call('blogHandleForId', ['gid://shopify/Blog/1']));
        $this->assertSame('', $this->call('blogHandleForId', ['gid://shopify/Blog/2']), 'no handle');
        $this->assertSame('', $this->call('blogHandleForId', ['gid://shopify/Blog/9']), 'unknown blog');
        $this->assertSame('', $this->call('blogHandleForId', ['']));
    }

    public function testMarketLooksUpTheCachedMarkets(): void
    {
        \WashContext::setActiveId('primary');
        $this->seedApiMemo(['wash_shopify_markets|primary' => [['id' => 'gid://shopify/Market/CH', 'name' => 'Switzerland']]]);

        $this->assertSame('Switzerland', $this->call('market', ['gid://shopify/Market/CH'])['name']);
        $this->assertNull($this->call('market', ['gid://shopify/Market/XX']));
    }
}
