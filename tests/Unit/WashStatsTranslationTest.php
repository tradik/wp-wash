<?php
/**
 * Unit tests for WashCallback::getLanguageStats() and getCategoryStats() —
 * the per-language and per-category aggregation helpers consumed by the
 * Shopify App Translation Status report. Each test runs in a separate
 * process so function_exists() of pll_ and icl_ helpers does not bleed
 * between cases.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-callback.php';

class WashStatsTranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('rest_ensure_response')->alias(fn($d) => $d);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('sanitize_title')->alias(fn($s) => strtolower(preg_replace('/[^a-z0-9-]/i', '-', (string) $s)));
        Functions\when('esc_html')->alias(fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));
        Functions\when('get_locale')->justReturn('en_US');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetLanguageStatsPolylang(): void
    {
        Functions\when('pll_languages_list')->justReturn(['en', 'pl', 'de']);
        Functions\when('pll_count_posts')->alias(function ($lang) {
            return match ($lang) {
                'en' => 95,
                'pl' => 120,
                'de' => 30,
                default => 0,
            };
        });
        Functions\when('pll_default_language')->justReturn('pl');

        $callback = new \WashCallback();
        $result = $callback->getLanguageStats();

        $this->assertSame('polylang', $result['detector']);
        $this->assertSame('pl', $result['primary']);
        $this->assertSame(120, $result['byLanguage']['pl']['posts']);
        $this->assertSame(95, $result['byLanguage']['en']['posts']);
        $this->assertSame(30, $result['byLanguage']['de']['posts']);
        $this->assertEqualsCanonicalizing(['en', 'de'], $result['byLanguage']['pl']['translatedTo']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetLanguageStatsWpml(): void
    {
        Functions\when('icl_object_id')->justReturn(0);
        Functions\when('apply_filters')->alias(function ($filter, $value, $args = null) {
            if ($filter === 'wpml_active_languages') {
                return [
                    'en' => ['code' => 'en', 'native_name' => 'English'],
                    'fr' => ['code' => 'fr', 'native_name' => 'Français'],
                ];
            }
            if ($filter === 'wpml_default_language') {
                return 'en';
            }
            return $value;
        });
        Functions\when('do_action')->justReturn(null);

        if (!class_exists('WP_Query')) {
            eval('class WP_Query { public int $found_posts = 50; public function __construct($args = []) {} }');
        }

        $callback = new \WashCallback();
        $result = $callback->getLanguageStats();

        $this->assertSame('wpml', $result['detector']);
        $this->assertSame('en', $result['primary']);
        $this->assertArrayHasKey('en', $result['byLanguage']);
        $this->assertArrayHasKey('fr', $result['byLanguage']);
        $this->assertSame(50, $result['byLanguage']['en']['posts']);
        $this->assertSame(['fr'], $result['byLanguage']['en']['translatedTo']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetLanguageStatsSingleLanguageFallback(): void
    {
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 42, 'draft' => 5, 'pending' => 0, 'private' => 0]);

        $callback = new \WashCallback();
        $result = $callback->getLanguageStats();

        $this->assertNull($result['detector']);
        $this->assertSame('en', $result['primary']);
        $this->assertSame(42, $result['byLanguage']['en']['posts']);
        $this->assertSame([], $result['byLanguage']['en']['translatedTo']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetCategoryStatsPolylang(): void
    {
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 1, 'slug' => 'news', 'name' => 'News', 'count' => 10],
            (object) ['term_id' => 2, 'slug' => 'reviews', 'name' => 'Reviews', 'count' => 5],
        ]);
        Functions\when('pll_get_term_language')->alias(fn($id) => $id === 1 ? 'en' : 'pl');

        $callback = new \WashCallback();
        $result = $callback->getCategoryStats();

        $this->assertCount(2, $result);
        $this->assertSame('news', $result[0]['slug']);
        $this->assertSame('News', $result[0]['name']);
        $this->assertSame(10, $result[0]['postCount']);
        $this->assertSame('en', $result[0]['language']);
        $this->assertSame('pl', $result[1]['language']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetCategoryStatsWpml(): void
    {
        Functions\when('icl_object_id')->justReturn(0);
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 7, 'slug' => 'travel', 'name' => 'Travel', 'count' => 12],
        ]);
        Functions\when('apply_filters')->alias(function ($filter, $value, $args = null) {
            if ($filter === 'wpml_element_language_code' && ($args['element_id'] ?? null) === 7) {
                return 'fr';
            }
            return $value;
        });

        $callback = new \WashCallback();
        $result = $callback->getCategoryStats();

        $this->assertCount(1, $result);
        $this->assertSame('fr', $result[0]['language']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetCategoryStatsSlugSanitization(): void
    {
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 9, 'slug' => 'XSS Slug!', 'name' => '<script>alert(1)</script>', 'count' => 0],
        ]);

        $callback = new \WashCallback();
        $result = $callback->getCategoryStats();

        $this->assertSame('xss-slug-', $result[0]['slug']);
        $this->assertSame('<script>alert(1)</script>', $result[0]['name']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetCategoryStatsHandlesEmptyTerms(): void
    {
        Functions\when('get_terms')->justReturn([]);
        $callback = new \WashCallback();
        $this->assertSame([], $callback->getCategoryStats());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetCategoryStatsHandlesWpError(): void
    {
        Functions\when('is_wp_error')->alias(fn($v) => is_object($v) && property_exists($v, 'is_error'));
        Functions\when('get_terms')->justReturn((object) ['is_error' => true]);
        $callback = new \WashCallback();
        $this->assertSame([], $callback->getCategoryStats());
    }
}
