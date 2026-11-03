<?php
/**
 * WashSync with the optional SEO / custom-field plugins ACTIVE (Yoast SEO,
 * RankMath incl. its Paper API, ACF get_fields()). Covers getSeoData()'s Yoast
 * and RankMath blocks, the WPSEO_Replace_Vars / rank_math_replace_vars
 * resolvers, and buildPostData() reading ACF fields through get_fields().
 * The plugin APIs come from WashSyncPluginApiStubs.php, loaded only here.
 */

namespace Wash\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// Load the code under test before Brain Monkey boots Patchwork (which would
// otherwise rewrite it and skew Xdebug's per-line coverage in the child process).
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class WashSyncSeoPluginsTest extends TestCase
{
    use WashSyncHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
        require_once __DIR__ . '/WashSyncPluginApiStubs.php';
        \RankMath\Paper\Paper::$title = '';
        \RankMath\Paper\Paper::$throw = false;
        $GLOBALS['washTestAcfFields'] = [];
        Functions\when('get_post')->justReturn($this->makePost(['post_title' => 'Hello']));
    }

    protected function tearDown(): void
    {
        $this->shutdownHarness();
        parent::tearDown();
    }

    public function testYoastTemplatesResolveThroughReplaceVars(): void
    {
        $this->meta = [
            '_yoast_wpseo_title'    => '%%title%% %%sep%% Shop',
            '_yoast_wpseo_metadesc' => 'About %%title%%',
            '_yoast_wpseo_focuskw'  => 'hello kw',
        ];
        $seo = $this->invokePrivate('getSeoData', [7]);
        $this->assertSame(['metaTitle' => 'Hello | Shop', 'metaDescription' => 'About Hello', 'focusKeyword' => 'hello kw'], $seo);
    }

    public function testYoastPlainTemplatesAreReturnedAsIs(): void
    {
        $this->meta = ['_yoast_wpseo_title' => 'Plain', '_yoast_wpseo_metadesc' => 'Desc'];
        $seo = $this->invokePrivate('getSeoData', [7]);
        $this->assertSame('Plain', $seo['metaTitle']);
        $this->assertSame('Desc', $seo['metaDescription']);
    }

    public function testRankMathFillsWhatYoastLeftEmpty(): void
    {
        \RankMath\Paper\Paper::$title = 'Paper Title';
        $this->meta = [
            'rank_math_description'   => 'Read %title%',
            'rank_math_focus_keyword' => 'rm kw',
        ];
        $seo = $this->invokePrivate('getSeoData', [7]);
        $this->assertSame(['metaTitle' => 'Paper Title', 'metaDescription' => 'Read Hello', 'focusKeyword' => 'rm kw'], $seo);
    }

    public function testRankMathTitleFallsBackToMetaWhenPaperThrows(): void
    {
        \RankMath\Paper\Paper::$throw = true;
        $this->meta = ['rank_math_title' => '%title% - RM'];
        $this->assertSame('Hello - RM', $this->invokePrivate('getRankMathRenderedTitle', [7]));
    }

    public function testRankMathTitleWithoutTemplateIsEmpty(): void
    {
        $this->assertSame('', $this->invokePrivate('getRankMathRenderedTitle', [7]));
    }

    public function testRankMathPlainTitleIsReturnedAsIs(): void
    {
        $this->meta = ['rank_math_title' => 'Plain RM'];
        $this->assertSame('Plain RM', $this->invokePrivate('getRankMathRenderedTitle', [7]));
    }

    public function testBuildPostDataIncludesAcfFieldsFromGetFields(): void
    {
        $GLOBALS['washTestAcfFields'] = ['subtitle' => 'Sub'];
        $data = $this->invokePrivate('buildPostData', [7, $this->makePost(), 'create']);
        $this->assertSame(['subtitle' => 'Sub'], $data['acfFields']);
    }

    public function testBuildPostDataSkipsEmptyAcfFields(): void
    {
        $data = $this->invokePrivate('buildPostData', [7, $this->makePost(), 'create']);
        $this->assertArrayNotHasKey('acfFields', $data);
    }
}
