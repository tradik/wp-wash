<?php
/**
 * Review demo seed (#303): packages/wordpress/review-demo/functions.php.
 *
 * WordPress, Polylang and ACF calls are mocked with Brain Monkey. Branches that
 * need a plugin function to be absent run in a separate process, because a
 * function Brain Monkey defines stays defined for the rest of the process.
 */
namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
require_once dirname(__DIR__, 3) . '/wordpress/review-demo/functions.php';

class ReviewDemoSeedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->alias(fn ($v) => $v instanceof \WP_Error);
        $this->dir = sys_get_temp_dir() . '/wash-review-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/a.jpg', 'img');
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter(glob($this->dir . '/{,*/}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir . '/lost');
        @rmdir($this->dir);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Polylang model stub that records added languages. */
    private function pll(array $locales): object
    {
        $languages = new class {
            public array $added = [];
            public bool $cleaned = false;
            public function add(array $l)
            {
                $this->added[] = $l['slug'];
                return $l['slug'] === 'de' ? new \WP_Error('x', 'no') : true;
            }
            public function clean_cache(): void
            {
                $this->cleaned = true;
            }
        };
        $model = new class ($languages, $locales) {
            public ?string $renameFirst = null;
            public function __construct(public object $languages, private array $locales)
            {
            }
            public function get_languages_list(): array
            {
                $list = array_map(fn ($l) => (object) ['locale' => $l, 'slug' => strtolower(strtr($l, '_', '-'))], $this->locales);
                if ($this->renameFirst && $list) {
                    $list[0]->slug = $this->renameFirst;
                }
                return $list;
            }
        };
        return (object) ['model' => $model];
    }

    public function testLanguagesAddsOnlyMissingLocales(): void
    {
        $pll = $this->pll(['de_DE']);
        Functions\when('PLL')->justReturn($pll);
        $this->expectOutputString("[wash-review] added English\n");
        washReviewEnsureLanguages();
        $this->assertSame(['en'], $pll->model->languages->added);
        $this->assertTrue($pll->model->languages->cleaned);
    }

    public function testLanguagesSkipsTakenSlug(): void
    {
        // Production: slug "en" already belongs to en_GB, so English is not added again.
        $pll = $this->pll(['en_GB', 'de_DE']);
        $pll->model->renameFirst = 'en';
        Functions\when('PLL')->justReturn($pll);
        $this->expectOutputString('');
        washReviewEnsureLanguages();
        $this->assertSame([], $pll->model->languages->added);
    }

    public function testLanguagesReportsFailure(): void
    {
        $pll = $this->pll([]);
        Functions\when('PLL')->justReturn($pll);
        $this->expectOutputString("[wash-review] added English\n[wash-review] language de failed\n");
        washReviewEnsureLanguages();
    }

    #[RunInSeparateProcess]
    public function testLanguagesSkippedWithoutPolylang(): void
    {
        $this->expectOutputString("[wash-review] Polylang not loaded, languages skipped\n");
        washReviewEnsureLanguages();
    }

    public function testCategoryExisting(): void
    {
        Functions\when('term_exists')->justReturn(['term_id' => '7']);
        Functions\expect('wp_insert_term')->never();
        $this->assertSame(7, washReviewEnsureCategory());
    }

    public function testCategoryCreatedOrFailed(): void
    {
        Functions\when('term_exists')->justReturn(null);
        Functions\expect('wp_insert_term')->twice()->andReturn(['term_id' => 9], new \WP_Error('x', 'no'));
        $this->expectOutputString(str_repeat("[wash-review] created category News\n", 2));
        $this->assertSame(9, washReviewEnsureCategory());
        $this->assertSame(0, washReviewEnsureCategory());
    }

    public function testImageImportedAndTagged(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('wp_tempnam')->justReturn($this->dir . '/tmp.jpg');
        Functions\when('media_handle_sideload')->justReturn(21);
        Functions\expect('update_post_meta')->once()->with(21, '_wash_review_seed', 'a.jpg');
        $this->expectOutputString("[wash-review] imported a.jpg\n");
        $this->assertSame(21, washReviewEnsureImage($this->dir, 'a.jpg'));
    }

    public function testImageImportFailureAndMissingSource(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('wp_tempnam')->justReturn($this->dir . '/tmp.jpg');
        Functions\when('media_handle_sideload')->justReturn(new \WP_Error('x', 'too big'));
        $this->expectOutputString("[wash-review] import of a.jpg failed: too big\n[wash-review] image {$this->dir}/b.jpg missing\n");
        $this->assertSame(0, washReviewEnsureImage($this->dir, 'a.jpg'));
        $this->assertSame(0, washReviewEnsureImage($this->dir, 'b.jpg'));
    }

    public function testKnownImageWithLostFileIsRestored(): void
    {
        $lost = $this->dir . '/lost/a.jpg';
        Functions\when('get_posts')->justReturn(['5']);
        Functions\when('get_attached_file')->justReturn($lost);
        Functions\when('wp_mkdir_p')->alias(fn ($d) => is_dir($d) || mkdir($d, 0777, true));
        Functions\when('wp_generate_attachment_metadata')->justReturn(['sizes' => []]);
        Functions\expect('wp_update_attachment_metadata')->once()->with(5, ['sizes' => []]);
        $this->expectOutputString("[wash-review] restored file of a.jpg\n");
        $this->assertSame(5, washReviewEnsureImage($this->dir, 'a.jpg'));
        $this->assertFileExists($lost);
        $this->assertSame(5, washReviewEnsureImage($this->dir, 'a.jpg'));
    }

    public function testAcfFieldGroupCreatedOnce(): void
    {
        Functions\expect('acf_get_field_group')->twice()->andReturn(false, ['key' => WASH_REVIEW_FIELD_GROUP]);
        Functions\expect('acf_import_field_group')->once()->with(\Mockery::on(
            fn ($g) => $g['key'] === WASH_REVIEW_FIELD_GROUP && $g['fields'][0]['name'] === 'reading_time'
        ));
        $this->expectOutputString("[wash-review] created ACF field group Post details\n");
        washReviewEnsureAcfField();
        washReviewEnsureAcfField();
    }

    #[RunInSeparateProcess]
    public function testAcfSkippedWithoutAcf(): void
    {
        $this->expectOutputString("[wash-review] ACF not loaded, field group skipped\n");
        washReviewEnsureAcfField();
    }
}
