<?php
/**
 * WashSync::buildPostData() — the outbound payload. Covers the page-builder
 * branch (rendered HTML + CSS, frontend CSS fallback, debug fields), the block
 * editor branch (blocksMeta / blocksCss), featured image CDN selection,
 * translation group, content image ids, the raw-ACF fallback when the ACF
 * plugin is inactive, and the wash_sync_data filter.
 *
 * get_fields() is never defined in this process, so the "ACF inactive" branch
 * is the one exercised here; WashSyncAcfFieldsTest covers the active plugin.
 */

namespace Wash\Tests\Unit;

use Brain\Monkey\Filters;
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
class WashSyncBuildPostDataTest extends TestCase
{
    use WashSyncHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
    }

    protected function tearDown(): void
    {
        $this->shutdownHarness();
        parent::tearDown();
    }

    private function build(array $postOverrides = []): array
    {
        return $this->invokePrivate('buildPostData', [7, $this->makePost($postOverrides), 'create']);
    }

    public function testPageBuilderWithoutCssFetchesFrontendCss(): void
    {
        $this->meta['_bricks_page_content_2'] = [['id' => 'a'], ['id' => 'b']];
        \WashPageBuilders::$detected = 'bricks';
        \WashPageBuilders::$rendered = ['html' => '<div>Built</div>', 'css' => ''];
        $style = str_repeat('a', 120);
        Functions\when('wp_remote_get')->justReturn(['ok' => true]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn("<style>{$style}</style>");

        $data = $this->build();

        $this->assertSame('<div>Built</div>', $data['content']);
        $this->assertSame($style . "\n", $data['inlineCss']);
        $this->assertSame('bricks', $data['pageBuilder']);
        $this->assertSame('bricks', $data['_debug_pageBuilder']);
        $this->assertTrue($data['_debug_bricksMetaExists']);
    }

    public function testPageBuilderWithCssKeepsRenderedCss(): void
    {
        \WashPageBuilders::$detected = 'elementor';
        \WashPageBuilders::$rendered = ['html' => '<section>E</section>', 'css' => '.e{}'];
        $data = $this->build();
        $this->assertSame('.e{}', $data['inlineCss']);
        $this->assertSame(strlen('<section>E</section>'), $data['_debug_contentLength']);
    }

    public function testBlockEditorArrayAddsBlockMetafieldsAndCss(): void
    {
        $this->options['wash_sync_blocks_css'] = '1';
        \WashBlocks::$metafields = [['key' => 'blocks', 'value' => '[]']];
        Filters\expectApplied('wash_before_sync_content')->once()->andReturn([
            'content'    => '<p>Blocks</p>',
            'blocksMeta' => [['blockName' => 'core/paragraph']],
            'blocksCss'  => '.wp-block{}',
        ]);

        $data = $this->build();

        $this->assertSame('<p>Blocks</p>', $data['content']);
        $this->assertSame(\WashBlocks::$metafields, $data['blocksMetafields']);
        $this->assertSame('.wp-block{}', $data['blocksCss']);
        $this->assertArrayNotHasKey('inlineCss', $data);
    }

    public function testBlockCssIsDroppedWhenOptionOff(): void
    {
        Filters\expectApplied('wash_before_sync_content')->once()->andReturn([
            'content'   => '<p>x</p>',
            'blocksCss' => '.wp-block{}',
        ]);
        $data = $this->build();
        $this->assertArrayNotHasKey('blocksCss', $data);
        $this->assertArrayNotHasKey('blocksMetafields', $data);
    }

    public function testBasicFieldsDecodeEntitiesAndMapStatus(): void
    {
        $data = $this->build(['post_title' => 'Fish &amp; Chips', 'post_status' => 'draft']);
        $this->assertSame('Fish & Chips', $data['title']);
        $this->assertSame('Short & sweet', $data['excerpt']);
        $this->assertSame('draft', $data['status']);
        $this->assertSame('Ann Author', $data['author']);
        $this->assertSame('https://example.com/hello/', $data['wpPermalink']);
        $this->assertSame('My Blog', $data['wpBlogName']);
        $this->assertSame(WASH_VERSION, $data['_debug_pluginVersion']);
        $this->assertArrayNotHasKey('featuredImage', $data);
    }

    public function testFeaturedImageUsesValidCdnUrl(): void
    {
        $this->thumbnailId = 12;
        \WashImages::$cdnUrls[12] = 'https://cdn.shopify.com/s/files/thumb.jpg';
        $data = $this->build();
        $this->assertSame(12, $data['featuredImageId']);
        $this->assertSame('https://cdn.shopify.com/s/files/thumb.jpg', $data['featuredImage']);
    }

    public function testFeaturedImageFallsBackToWpUrlForStagedCdnUrl(): void
    {
        $this->thumbnailId = 12;
        \WashImages::$cdnUrls[12] = 'https://shopify-staged-uploads.storage.googleapis.com/tmp.jpg';
        $data = $this->build();
        $this->assertSame('https://example.com/wp-content/uploads/thumb.jpg', $data['featuredImage']);
    }

    public function testFeaturedImageFallsBackToWpUrlWhenNeverSynced(): void
    {
        $this->thumbnailId = 12;
        $data = $this->build();
        $this->assertSame('https://example.com/wp-content/uploads/thumb.jpg', $data['featuredImage']);
    }

    public function testTranslationGroupAndContentImageIdsAreAttached(): void
    {
        \WashHreflangs::$group = ['sourceId' => 3, 'language' => 'en'];
        $html = '<img class="wp-image-41" src="https://cdn.shopify.com/a.jpg">'
            . '<a href="https://example.com/wp-content/uploads/doc.pdf">PDF</a>';
        $data = $this->build(['post_content' => $html]);
        $this->assertSame(['sourceId' => 3, 'language' => 'en'], $data['translationGroup']);
        $this->assertSame(['https://cdn.shopify.com/a.jpg' => 41], $data['contentImageIds']);
    }

    public function testRawAcfFallbackWhenAcfPluginInactive(): void
    {
        $this->options['wash_meta_acf'] = '1';
        \WashMetafields::$rawAcf = ['hero' => 'Big'];
        $this->assertSame(['hero' => 'Big'], $this->build()['acfFields']);
    }

    public function testRawAcfFallbackEmptyAddsNothing(): void
    {
        $this->options['wash_meta_acf'] = '1';
        $this->assertArrayNotHasKey('acfFields', $this->build());
    }

    public function testSyncDataFilterCanAdjustPayload(): void
    {
        Filters\expectApplied('wash_sync_data')->once()->andReturnUsing(fn($data) => ['wrapped' => $data['title']]);
        $this->assertSame(['wrapped' => 'Hello'], $this->build());
    }
}
