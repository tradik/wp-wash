<?php
/**
 * Tests for WashImportWriter — the reverse-import writer (Shopify → WordPress).
 * Covers field mapping, status resolution (D4), dedup (D2), term/category
 * assignment (D1), identity + anti-loop meta (D3), and the importOne orchestration.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-import-media.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import-metafields.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import-translations.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import-writer.php';

/**
 * Stub that replaces the media + translations collaborators with no-ops so
 * importOne() can be tested without the media library or a multilingual plugin.
 */
class WashImportWriterStub extends \WashImportWriter
{
    public int $mediaCalls = 0;
    public int $translationCalls = 0;
    public int $metafieldCalls = 0;

    protected function media()
    {
        $this->mediaCalls++;
        return new class {
            public function apply()
            {
                // No-op: WashImportMedia is exercised in WashImportMediaTest.
            }
        };
    }

    protected function metafields()
    {
        $this->metafieldCalls++;
        return new class {
            public function apply()
            {
                // No-op: WashImportMetafields is exercised in WashImportMetafieldsTest.
            }
        };
    }

    protected function translations()
    {
        $this->translationCalls++;
        return new class {
            public function apply()
            {
                // No-op: WashImportTranslations is exercised in WashImportTranslationsTest.
            }
        };
    }
}

class WashImportWriterTest extends TestCase
{
    private array $postMeta = [];
    private array $tags = [];
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);

        $this->postMeta = [];
        $this->tags = [];
        $this->categories = [];

        Functions\when('__')->returnArg(1);
        Functions\when('get_option')->justReturn('');
        Functions\when('wp_strip_all_tags')->alias(fn($t) => trim(strip_tags((string) $t)));
        Functions\when('sanitize_title')->alias(fn($t) => strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $t)));
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('current_time')->justReturn('2026-07-03 12:00:00');
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->postMeta[(int) $id][$key] = $value;
            return true;
        });
        Functions\when('get_post_meta')->alias(function ($id, $key) {
            return $this->postMeta[(int) $id][$key] ?? '';
        });
        Functions\when('wp_set_post_tags')->alias(function ($id, $tags) {
            $this->tags[(int) $id] = $tags;
        });
        Functions\when('wp_set_post_categories')->alias(function ($id, $cats) {
            $this->categories[(int) $id] = $cats;
        });
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        unset($GLOBALS['wash_importing']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function article(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gid://shopify/Article/100',
            'handle' => 'my-post',
            'title' => 'My <b>Post</b>',
            'bodyHtml' => '<p>Body</p>',
            'summaryHtml' => '<p>Sum</p>',
            'isPublished' => true,
            'publishedAt' => '2026-01-02T03:04:05Z',
            'tags' => ['a', 'b'],
            'author' => 'Jane Doe',
            'image' => null,
            'blog' => ['id' => 'gid://shopify/Blog/1', 'handle' => 'news', 'title' => 'News'],
        ], $overrides);
    }

    // ---------------- buildPostArr ----------------

    public function testBuildPostArrMapsCoreFields(): void
    {
        Functions\when('get_users')->justReturn([7]);
        $writer = new \WashImportWriter();
        $arr = $writer->buildPostArr($this->article(), ['status' => 'draft']);

        $this->assertSame('post', $arr['post_type']);
        $this->assertSame('My Post', $arr['post_title']);
        $this->assertSame('<p>Body</p>', $arr['post_content']);
        $this->assertSame('<p>Sum</p>', $arr['post_excerpt']);
        $this->assertSame('draft', $arr['post_status']);
        $this->assertSame('my-post', $arr['post_name']);
        $this->assertSame('2026-01-02 03:04:05', $arr['post_date_gmt']);
        $this->assertSame(7, $arr['post_author']);
    }

    public function testBuildPostArrStatusMatchesShopifyWhenSelected(): void
    {
        Functions\when('get_users')->justReturn([]);
        $writer = new \WashImportWriter();

        $published = $writer->buildPostArr($this->article(['isPublished' => true]), ['status' => 'shopify']);
        $this->assertSame('publish', $published['post_status']);

        $hidden = $writer->buildPostArr($this->article(['isPublished' => false]), ['status' => 'shopify']);
        $this->assertSame('draft', $hidden['post_status']);
    }

    public function testBuildPostArrForcedPublishAndKsesForRestrictedUser(): void
    {
        Functions\when('get_users')->justReturn([]);
        Functions\when('current_user_can')->justReturn(false); // no unfiltered_html
        Functions\when('wp_kses_post')->alias(fn($h) => '[kses]' . $h);
        $writer = new \WashImportWriter();
        $arr = $writer->buildPostArr($this->article(), ['status' => 'publish']);

        $this->assertSame('publish', $arr['post_status']);
        $this->assertSame('[kses]<p>Body</p>', $arr['post_content']);
        $this->assertArrayNotHasKey('post_author', $arr); // author not found → WP default
    }

    // ---------------- resolveExistingPostId (D2) ----------------

    public function testResolveExistingPostIdReturnsMatchedIdThenZero(): void
    {
        Functions\when('get_posts')->alias(fn($args) => $args['meta_value'] === 'gid://shopify/Article/100' ? [55] : []);
        $writer = new \WashImportWriter();

        $this->assertSame(55, $writer->resolveExistingPostId('gid://shopify/Article/100'));
        $this->assertSame(0, $writer->resolveExistingPostId('gid://shopify/Article/999'));
        $this->assertSame(0, $writer->resolveExistingPostId(''));
    }

    // ---------------- assignTerms (D1) ----------------

    public function testAssignTermsSetsTagsAndBlogCategory(): void
    {
        Functions\when('term_exists')->justReturn(['term_id' => 12]);
        $writer = new \WashImportWriter();
        $writer->assignTerms(9, $this->article(), ['blogAsCategory' => true]);

        $this->assertSame(['a', 'b'], $this->tags[9]);
        $this->assertSame([12], $this->categories[9]);
    }

    public function testAssignTermsSkipsCategoryWhenToggleOff(): void
    {
        $writer = new \WashImportWriter();
        $writer->assignTerms(9, $this->article(), ['blogAsCategory' => false]);

        $this->assertSame(['a', 'b'], $this->tags[9]);
        $this->assertArrayNotHasKey(9, $this->categories);
    }

    public function testFindOrCreateCategoryCreatesWhenMissing(): void
    {
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 21]);
        $writer = new \WashImportWriter();
        $this->assertSame(21, $writer->findOrCreateCategory('News'));
    }

    public function testFindOrCreateCategoryReturnsZeroOnError(): void
    {
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(new \WP_Error('cannot', 'no perms'));
        $writer = new \WashImportWriter();
        $this->assertSame(0, $writer->findOrCreateCategory('News'));
    }

    // ---------------- writeIdentityMeta (D2/D3) ----------------

    public function testWriteIdentityMetaWritesIdentityAndSkipFlag(): void
    {
        $writer = new \WashImportWriter();
        $writer->writeIdentityMeta(42, $this->article());

        $meta = $this->postMeta[42];
        $this->assertSame('gid://shopify/Article/100', $meta['_wash_article_id']);
        $this->assertSame('gid://shopify/Blog/1', $meta['_wash_blog_id']);
        $this->assertSame('news', $meta['_wash_blog_handle']);
        $this->assertSame('my-post', $meta['_wash_source_handle']);
        $this->assertSame('1', $meta['_wash_skip_autosync']);
    }

    // ---------------- importOne orchestration ----------------

    public function testImportOneInsertsNewPostAndStampsMeta(): void
    {
        Functions\when('get_posts')->justReturn([]); // no existing → insert
        Functions\when('get_users')->justReturn([]);
        Functions\when('term_exists')->justReturn(['term_id' => 3]);
        $seenGlobalDuringInsert = null;
        Functions\when('wp_insert_post')->alias(function () use (&$seenGlobalDuringInsert) {
            $seenGlobalDuringInsert = !empty($GLOBALS['wash_importing']);
            return 500;
        });
        Functions\expect('wp_update_post')->never();

        $writer = new WashImportWriterStub();
        $result = $writer->importOne($this->article(), ['status' => 'draft', 'blogAsCategory' => true]);

        $this->assertTrue($result);
        $this->assertTrue($seenGlobalDuringInsert, 'anti-loop global must be set during the insert');
        $this->assertArrayNotHasKey('wash_importing', $GLOBALS, 'global must be cleared after import');
        $this->assertSame('gid://shopify/Article/100', $this->postMeta[500]['_wash_article_id']);
        $this->assertSame('1', $this->postMeta[500]['_wash_skip_autosync']);
    }

    public function testImportOneUpdatesExistingMappedPost(): void
    {
        Functions\when('get_posts')->justReturn([88]); // existing → update
        Functions\when('get_users')->justReturn([]);
        Functions\when('term_exists')->justReturn(null);
        Functions\when('wp_insert_term')->justReturn(['term_id' => 1]);
        $updatedArr = null;
        Functions\when('wp_update_post')->alias(function ($arr) use (&$updatedArr) {
            $updatedArr = $arr;
            return 88;
        });
        Functions\expect('wp_insert_post')->never();

        $writer = new WashImportWriterStub();
        $result = $writer->importOne($this->article(), ['status' => 'draft']);

        $this->assertTrue($result);
        $this->assertSame(88, $updatedArr['ID']);
    }

    public function testImportOneSkipsAlreadyImportedWhenFlagged(): void
    {
        Functions\when('get_posts')->justReturn([88]); // already imported
        Functions\expect('wp_insert_post')->never();
        Functions\expect('wp_update_post')->never();

        $writer = new WashImportWriterStub();
        $result = $writer->importOne($this->article(), ['skipImported' => true]);

        $this->assertTrue($result);
    }

    public function testImportOneReturnsReasonOnInsertError(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_users')->justReturn([]);
        Functions\when('wp_insert_post')->justReturn(new \WP_Error('db', 'insert failed'));

        $writer = new WashImportWriterStub();
        $result = $writer->importOne($this->article(), ['status' => 'draft']);

        $this->assertSame('insert failed', $result);
        $this->assertArrayNotHasKey('wash_importing', $GLOBALS);
    }

    public function testImportOneRejectsMissingArticleId(): void
    {
        $writer = new WashImportWriterStub();
        $this->assertSame('missing article id', $writer->importOne(['id' => ''], []));
    }

    public function testImportOneInvokesMediaWhenImagesEnabled(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_users')->justReturn([]);
        Functions\when('wp_insert_post')->justReturn(500);

        $writer = new WashImportWriterStub();
        $writer->importOne($this->article(), ['status' => 'draft', 'images' => true]);
        $this->assertSame(1, $writer->mediaCalls);
    }

    public function testImportOneInvokesTranslationsWhenPresent(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_users')->justReturn([]);
        Functions\when('wp_insert_post')->justReturn(500);

        $writer = new WashImportWriterStub();
        $writer->importOne($this->article(), ['status' => 'draft'], ['de' => ['title' => 'Hallo']]);
        $this->assertSame(1, $writer->translationCalls);
    }

    public function testBuildPostArrOmitsAuthorWhenArticleHasNone(): void
    {
        Functions\expect('get_users')->never(); // empty author short-circuits before the lookup
        $writer = new \WashImportWriter();
        $arr = $writer->buildPostArr($this->article(['author' => null]), ['status' => 'draft']);
        $this->assertArrayNotHasKey('post_author', $arr);
    }

    public function testRealFactoriesReturnConcreteHelpers(): void
    {
        $writer = new \WashImportWriter();
        $map = [
            'media' => \WashImportMedia::class,
            'translations' => \WashImportTranslations::class,
            'metafields' => \WashImportMetafields::class,
        ];
        foreach ($map as $method => $type) {
            $ref = new \ReflectionMethod(\WashImportWriter::class, $method);
            $ref->setAccessible(true);
            $this->assertInstanceOf($type, $ref->invoke($writer));
        }
    }

    // ---------------- Pages (postType) + metafields ----------------

    public function testBuildPostArrUsesPageType(): void
    {
        Functions\when('get_users')->justReturn([]);
        $writer = new \WashImportWriter();
        $arr = $writer->buildPostArr($this->article(['blog' => null, 'tags' => []]), ['status' => 'draft', 'postType' => 'page']);
        $this->assertSame('page', $arr['post_type']);
    }

    public function testResolveExistingPostIdHonoursPostType(): void
    {
        $seen = null;
        Functions\when('get_posts')->alias(function ($args) use (&$seen) {
            $seen = $args['post_type'];
            return [];
        });
        (new \WashImportWriter())->resolveExistingPostId('gid://shopify/Page/1', 'page');
        $this->assertSame('page', $seen);
    }

    public function testImportOneSkipsTermsForPages(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_users')->justReturn([]);
        Functions\when('wp_insert_post')->justReturn(600);

        $writer = new WashImportWriterStub();
        $writer->importOne($this->article(), ['status' => 'draft', 'postType' => 'page', 'blogAsCategory' => true]);

        $this->assertArrayNotHasKey(600, $this->tags, 'pages must not get tag assignment');
        $this->assertArrayNotHasKey(600, $this->categories, 'pages must not get a blog category');
    }

    public function testImportOneImportsMetafieldsWhenEnabled(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_users')->justReturn([]);
        Functions\when('wp_insert_post')->justReturn(700);

        $writer = new WashImportWriterStub();
        $writer->importOne($this->article(), ['status' => 'draft', 'metafields' => true]);
        $this->assertSame(1, $writer->metafieldCalls);
    }
}
