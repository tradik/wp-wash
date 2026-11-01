<?php
/**
 * Review demo seed (#324, #327): packages/wordpress/review-demo/cleanup.php,
 * the repairs that run before the seed creates anything.
 */
namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/wordpress/review-demo/functions.php';
require_once dirname(__DIR__, 3) . '/wordpress/review-demo/cleanup.php';

class ReviewDemoCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_rewrite']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testPermalinksSwitchedToPostnameOnce(): void
    {
        Functions\expect('get_option')->twice()->with('permalink_structure')->andReturn('/%year%/%postname%/', '/%postname%/');
        $GLOBALS['wp_rewrite'] = new class {
            public array $set = [];
            public function set_permalink_structure(string $structure): void
            {
                $this->set[] = $structure;
            }
        };
        Functions\expect('flush_rewrite_rules')->once()->with(false);
        $this->expectOutputString("[wash-review] permalinks set to /%postname%/\n");
        washReviewEnsurePostnamePermalinks();
        washReviewEnsurePostnamePermalinks();
        $this->assertSame(['/%postname%/'], $GLOBALS['wp_rewrite']->set);
    }

    public function testOrphanLinksAndStaleGroupsRemoved(): void
    {
        $GLOBALS['wpdb'] = new ReviewDemoWpdbStub(['41', '42'], 5);
        Functions\expect('wp_delete_term')->twice()->with(\Mockery::anyOf(41, 42), 'post_translations');
        $this->expectOutputString("[wash-review] removed 2 stale translation groups and 5 orphaned term links\n");
        washReviewDropOrphanTermLinks();
        $this->assertStringContainsString("tt.taxonomy = 'post_translations'", $GLOBALS['wpdb']->queries[0]);
        $this->assertStringStartsWith('DELETE tr FROM wp_term_relationships', $GLOBALS['wpdb']->queries[1]);
        // Term languages link term ids, not post ids: never treated as orphans.
        $this->assertStringContainsString("NOT IN ('link_category', 'term_language', 'term_translations')", $GLOBALS['wpdb']->queries[1]);
    }

    public function testCleanSiteLogsNothing(): void
    {
        $GLOBALS['wpdb'] = new ReviewDemoWpdbStub();
        Functions\expect('wp_delete_term')->never();
        $this->expectOutputString('');
        washReviewDropOrphanTermLinks();
    }

    public function testOnlyEmptyNewsCopiesDeleted(): void
    {
        Functions\when('get_terms')->justReturn([
            (object) ['term_id' => 8, 'slug' => 'news-en', 'count' => 0],
            (object) ['term_id' => 9, 'slug' => 'news-en-en', 'count' => '0'],
            (object) ['term_id' => 10, 'slug' => 'news-en-en-en', 'count' => 2],
            (object) ['term_id' => 11, 'slug' => 'news-fr-ch', 'count' => 0],
        ]);
        Functions\expect('wp_delete_term')->twice()->with(\Mockery::anyOf(8, 9), 'category');
        $this->expectOutputString("[wash-review] deleted category copy news-en\n[wash-review] deleted category copy news-en-en\n");
        washReviewDropNewsCopies();
    }

    public function testNewsCopiesSkippedOnTermError(): void
    {
        Functions\when('get_terms')->justReturn(new \WP_Error('x', 'no'));
        Functions\expect('wp_delete_term')->never();
        $this->expectOutputString('');
        washReviewDropNewsCopies();
    }

    /** Polylang language stub: $termId is its term_language term id (0 = missing). */
    private function language(string $slug, int $termId): object
    {
        return new class ($slug, $termId) {
            public string $name;
            public function __construct(public string $slug, private int $termId)
            {
                $this->name = strtoupper($slug);
            }
            public function get_tax_prop(string $taxonomy, string $prop): int
            {
                return $taxonomy === 'term_language' && $prop === 'term_id' ? $this->termId : 0;
            }
        };
    }

    public function testMissingTermLanguagesRecreated(): void
    {
        $languages = new class {
            public int $cleaned = 0;
            public function clean_cache(): void
            {
                $this->cleaned++;
            }
        };
        $list = [$this->language('en', 0), $this->language('de', 0), $this->language('en-gb', 4)];
        $model = new class ($languages, $list) {
            public function __construct(public object $languages, private array $list)
            {
            }
            public function get_languages_list(): array
            {
                return $this->list;
            }
        };
        Functions\when('PLL')->justReturn((object) ['model' => $model]);
        Functions\expect('wp_insert_term')->twice()
            ->with(\Mockery::anyOf('EN', 'DE'), 'term_language', \Mockery::type('array'))
            ->andReturn(['term_id' => 50], new \WP_Error('x', 'no'));
        $this->expectOutputString("[wash-review] repaired 1 Polylang term languages\n");
        washReviewRepairLanguageTerms();
        $this->assertSame(1, $languages->cleaned);
    }

    public function testNothingToRepairLogsNothing(): void
    {
        $model = new class {
            public object $languages;
            public function __construct()
            {
                $this->languages = new \stdClass();
            }
            public function get_languages_list(): array
            {
                return [];
            }
        };
        Functions\when('PLL')->justReturn((object) ['model' => $model]);
        $this->expectOutputString('');
        washReviewRepairLanguageTerms();
    }

    #[RunInSeparateProcess]
    public function testRepairSkippedWithoutPolylang(): void
    {
        $this->expectOutputString('');
        washReviewRepairLanguageTerms();
        $this->assertFalse(function_exists('PLL'));
    }

    public function testCategoryGetsDefaultLanguageWhenMissing(): void
    {
        Functions\when('pll_default_language')->justReturn('en');
        Functions\expect('pll_get_term_language')->twice()->with(7)->andReturn(false, 'en');
        Functions\expect('pll_set_term_language')->once()->with(7, 'en');
        $this->expectOutputString("[wash-review] category News set to en\n");
        washReviewSetCategoryLanguage(7);
        washReviewSetCategoryLanguage(7);
        washReviewSetCategoryLanguage(0);
    }

    #[RunInSeparateProcess]
    public function testCategoryLanguageSkippedWithoutPolylang(): void
    {
        $this->expectOutputString('');
        washReviewSetCategoryLanguage(7);
        $this->assertFalse(function_exists('pll_set_term_language'));
    }

    public function testPublicUrlUntouchedWithoutSiteUrl(): void
    {
        $this->assertFalse(defined('WASH_SITE_URL'));
        $this->assertSame('http://127.0.0.1/a.jpg', washReviewPublicUrl('http://127.0.0.1/a.jpg'));
    }

    #[RunInSeparateProcess]
    public function testPublicUrlSwapsHost(): void
    {
        define('WASH_SITE_URL', 'https://demo-wash.tradik.com/');
        $this->assertSame('https://demo-wash.tradik.com/wp-content/uploads/a.jpg',
            washReviewPublicUrl('http://127.0.0.1/wp-content/uploads/a.jpg'));
        $this->assertSame('https://demo-wash.tradik.com', washReviewPublicUrl('http://127.0.0.1'));
        $this->assertSame('not a url', washReviewPublicUrl('not a url'));
    }
}
