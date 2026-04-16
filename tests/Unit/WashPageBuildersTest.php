<?php
/**
 * Unit tests for WashPageBuilders: detection, rendering, CSS scoping.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-page-builders.php';

class WashPageBuildersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -- detect() --

    public function testDetectBricks(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_content_2') {
                return [['id' => 'abc', 'name' => 'section']];
            }
            return '';
        });

        $this->assertSame('bricks', \WashPageBuilders::detect(1));
    }

    public function testDetectElementor(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_content_2') return '';
            if ($key === '_elementor_data') return '[{"id":"abc"}]';
            return '';
        });

        $this->assertSame('elementor', \WashPageBuilders::detect(1));
    }

    public function testDetectDivi(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_content_2') return '';
            if ($key === '_elementor_data') return '';
            if ($key === '_et_builder_version') return '4.24.0';
            return '';
        });

        $this->assertSame('divi', \WashPageBuilders::detect(1));
    }

    public function testDetectNone(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $this->assertNull(\WashPageBuilders::detect(1));
    }

    public function testDetectBricksEmptyArray(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_content_2') return [];
            return '';
        });

        // Empty array should NOT be detected as Bricks
        $this->assertNull(\WashPageBuilders::detect(1));
    }

    // -- wrapWithCss() --

    public function testWrapWithCss(): void
    {
        $html = '<div>Hello</div>';
        $css = '.section { color: red; }';

        $result = \WashPageBuilders::wrapWithCss($html, $css);

        $this->assertStringContainsString('<div class="wash-pb-content">', $result);
        $this->assertStringContainsString('<style>', $result);
        $this->assertStringContainsString('.wash-pb-content', $result);
        $this->assertStringContainsString('<div>Hello</div>', $result);
        $this->assertStringContainsString('</div>', $result);
    }

    public function testWrapWithCssEmptyHtml(): void
    {
        $this->assertSame('', \WashPageBuilders::wrapWithCss('', '.x { color: red; }'));
    }

    public function testWrapWithCssEmptyCss(): void
    {
        $html = '<p>Content</p>';
        $this->assertSame($html, \WashPageBuilders::wrapWithCss($html, ''));
    }

    // -- scopeCss (via wrapWithCss) --

    public function testCssScopingPrefixesSelectors(): void
    {
        $css = ".hero {\n  color: blue;\n}";
        $result = \WashPageBuilders::wrapWithCss('<p>x</p>', $css);

        $this->assertStringContainsString('.wash-pb-content .hero', $result);
    }

    public function testCssScopingPreservesMediaQueries(): void
    {
        $css = "@media (max-width: 768px) {\n.hero {\n  font-size: 14px;\n}\n}";
        $result = \WashPageBuilders::wrapWithCss('<p>x</p>', $css);

        $this->assertStringContainsString('@media (max-width: 768px)', $result);
        $this->assertStringContainsString('.wash-pb-content .hero', $result);
    }

    public function testCssScopingPreservesFontFace(): void
    {
        $css = "@font-face {\n  font-family: 'Custom';\n  src: url('font.woff2');\n}";
        $result = \WashPageBuilders::wrapWithCss('<p>x</p>', $css);

        $this->assertStringContainsString("@font-face", $result);
    }

    // -- render() fallback for unknown builder --

    public function testRenderUnknownBuilder(): void
    {
        $result = \WashPageBuilders::render(1, 'unknown');

        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['css']);
    }

    // -- renderBricks fallback when no Bricks class --

    public function testRenderBricksWithoutBricksClass(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_content_2') {
                return [['id' => 'el1', 'name' => 'section']];
            }
            if ($key === '_bricks_page_css') return '';
            return '';
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 1,
            'post_content' => '<p>Fallback content</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $value) => $value);
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => '/tmp/fake-uploads',
        ]);
        Functions\when('get_option')->justReturn('');

        $result = \WashPageBuilders::render(1, 'bricks');

        // Without Bricks class, falls back to the_content
        $this->assertStringContainsString('Fallback content', $result['html']);
    }
}
