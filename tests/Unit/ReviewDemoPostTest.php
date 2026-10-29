<?php
/**
 * Review demo seed (#303): the "Hello from WordPress" post, washReviewSeed() and
 * the wp-cli runner seed-review-demo.php. See ReviewDemoSeedTest for the rest.
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

class ReviewDemoPostTest extends TestCase
{
    /** @var array<int,array{0:string,1:mixed}> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->alias(fn ($v) => $v instanceof \WP_Error);
        Functions\when('esc_url')->returnArg();
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[] = [$key, $value];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testContentWithAndWithoutInlineImage(): void
    {
        Functions\when('wp_get_attachment_url')->justReturn('https://x/a.jpg');
        $this->assertStringContainsString('wp-image-4', washReviewPostContent(4));
        $this->assertStringNotContainsString('wp:image', washReviewPostContent(0));
    }

    public function testExistingPostIsLeftAlone(): void
    {
        Functions\when('get_page_by_path')->justReturn((object) ['ID' => 3]);
        Functions\expect('wp_insert_post')->never();
        washReviewEnsurePost(7, [1, 2]);
        $this->assertSame([], $this->meta);
    }

    public function testPostCreatedWithImagesSeoFieldAndLanguage(): void
    {
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\when('wp_get_attachment_url')->justReturn('https://x/b.jpg');
        Functions\expect('wp_insert_post')->once()->with(\Mockery::on(
            fn ($p) => $p['post_name'] === WASH_REVIEW_SLUG && !isset($p['post_category'])
                && str_contains($p['post_content'], 'wp-image-2')
        ), true)->andReturn(30);
        Functions\expect('set_post_thumbnail')->once()->with(30, 1);
        Functions\expect('update_field')->once()->with(WASH_REVIEW_FIELD, '3 min', 30);
        Functions\when('pll_default_language')->justReturn('en');
        Functions\expect('pll_set_post_language')->once()->with(30, 'en');
        Functions\expect('wp_set_post_categories')->once()->with(30, [7]);
        $this->expectOutputString("[wash-review] created post Hello from WordPress (ID 30)\n");
        washReviewEnsurePost(7, [1, 2]);
        $this->assertSame(['_yoast_wpseo_title', '_yoast_wpseo_metadesc'], array_column($this->meta, 0));
    }

    public function testPostWithoutCategoryImagesOrDefaultLanguage(): void
    {
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\expect('wp_insert_post')->once()->with(\Mockery::on(
            fn ($p) => !str_contains($p['post_content'], 'wp:image')
        ), true)->andReturn(31);
        Functions\expect('set_post_thumbnail')->never();
        Functions\when('update_field')->justReturn(true);
        Functions\when('pll_default_language')->justReturn(false);
        Functions\expect('pll_set_post_language')->never();
        Functions\expect('wp_set_post_categories')->never();
        $this->expectOutputString("[wash-review] created post Hello from WordPress (ID 31)\n");
        washReviewEnsurePost(0, [0, 0]);
    }

    public function testPostFailureIsReported(): void
    {
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\when('wp_insert_post')->justReturn(new \WP_Error('db', 'locked'));
        $this->expectOutputString("[wash-review] post failed: locked\n");
        washReviewEnsurePost(7, []);
        $this->assertSame([], $this->meta);
    }

    #[RunInSeparateProcess]
    public function testPostWithoutAcfOrPolylang(): void
    {
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\when('wp_insert_post')->justReturn(32);
        $this->expectOutputString("[wash-review] created post Hello from WordPress (ID 32)\n");
        washReviewEnsurePost(0, []);
    }

    /** Mocks for a full run on a site where everything already exists. */
    private function mockSeededSite(): void
    {
        Functions\when('get_posts')->justReturn(['5']);
        Functions\when('get_attached_file')->justReturn(__FILE__);
        Functions\when('term_exists')->justReturn(['term_id' => 7]);
        Functions\when('get_page_by_path')->justReturn((object) ['ID' => 3]);
    }

    #[RunInSeparateProcess]
    public function testSeedRunsEveryStep(): void
    {
        $this->mockSeededSite();
        $this->expectOutputString("[wash-review] Polylang not loaded, languages skipped\n"
            . "[wash-review] ACF not loaded, field group skipped\n[wash-review] done\n");
        washReviewSeed('/nowhere');
    }

    #[RunInSeparateProcess]
    public function testRunnerLoadsWpAdminIncludesAndSeeds(): void
    {
        $includes = ABSPATH . 'wp-admin/includes';
        if (!is_dir($includes)) {
            mkdir($includes, 0777, true);
        }
        foreach (['file', 'media', 'image'] as $name) {
            file_put_contents("$includes/$name.php", '<?php');
        }
        $this->mockSeededSite();
        putenv('WASH_REVIEW_IMAGES=/nowhere');
        $this->expectOutputRegex('/\[wash-review\] done\n$/');
        require dirname(__DIR__, 3) . '/wordpress/review-demo/seed-review-demo.php';
        putenv('WASH_REVIEW_IMAGES');
    }
}
