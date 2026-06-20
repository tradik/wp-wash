<?php
/**
 * Coverage tests for WashPageBuilders: Elementor/Divi render,
 * CSS extraction helpers (extractBricksCssFromPage, getBricksCss,
 * extractBricksContent), wrapWithCss edge-cases, and scopeCss branches.
 *
 * Private methods are exercised through ReflectionMethod.
 *
 * @package Wash\Tests\Unit
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-page-builders.php';

class WashPageBuildersCoverageTest extends TestCase
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

    // ---------------------------------------------------------------
    // Helper: invoke a private/protected static method via reflection
    // ---------------------------------------------------------------

    private function callStatic(string $method, ...$args): mixed
    {
        $rm = new \ReflectionMethod(\WashPageBuilders::class, $method);
        // setAccessible is a no-op since PHP 8.1 — calling it triggers a
        // deprecation notice in PHP 8.5+.  The ReflectionMethod constructor
        // already grants access to private/protected members via reflection.
        return $rm->invoke(null, ...$args);
    }

    // ---------------------------------------------------------------
    // render() — Elementor branch
    // ---------------------------------------------------------------

    public function testRenderElementorFallsBackToTheContent(): void
    {
        $postContent = '<p>Elementor post content</p>';
        Functions\when('get_post')->justReturn((object) [
            'ID' => 5,
            'post_content' => $postContent,
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);

        $result = \WashPageBuilders::render(5, 'elementor');

        $this->assertSame($postContent, $result['html']);
        $this->assertSame('', $result['css']);
    }

    public function testRenderElementorNullPost(): void
    {
        Functions\when('get_post')->justReturn(null);
        Functions\when('error_log')->justReturn(true);

        $result = \WashPageBuilders::render(99, 'elementor');

        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['css']);
    }

    // ---------------------------------------------------------------
    // render() — Divi branch
    // ---------------------------------------------------------------

    public function testRenderDiviFallsBackToTheContent(): void
    {
        $postContent = '<div class="et_pb_section">Divi content</div>';
        Functions\when('get_post')->justReturn((object) [
            'ID' => 6,
            'post_content' => $postContent,
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);

        $result = \WashPageBuilders::render(6, 'divi');

        $this->assertSame($postContent, $result['html']);
        $this->assertSame('', $result['css']);
    }

    public function testRenderDiviNullPost(): void
    {
        Functions\when('get_post')->justReturn(null);
        Functions\when('error_log')->justReturn(true);

        $result = \WashPageBuilders::render(88, 'divi');

        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['css']);
    }

    // ---------------------------------------------------------------
    // detect() — priority ordering (Bricks wins if both Bricks+Elementor set)
    // ---------------------------------------------------------------

    public function testDetectBricksBeforeElementor(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'a', 'name' => 'section']],
                '_elementor_data'        => '[{"id":"abc"}]',
                default                  => '',
            };
        });

        $this->assertSame('bricks', \WashPageBuilders::detect(1));
    }

    public function testDetectElementorBeforeDivi(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => '',
                '_elementor_data'        => '[{"id":"abc"}]',
                '_et_builder_version'    => '4.24.0',
                default                  => '',
            };
        });

        $this->assertSame('elementor', \WashPageBuilders::detect(1));
    }

    // ---------------------------------------------------------------
    // extractBricksContent — private method via reflection
    // ---------------------------------------------------------------

    public function testExtractBricksContentBrxContentMarker(): void
    {
        $html = '<html><body><div id="brx-content"><p>Hello Bricks</p></div><footer>Footer</footer></body></html>';

        $result = $this->callStatic('extractBricksContent', $html);

        $this->assertStringContainsString('Hello Bricks', $result);
    }

    public function testExtractBricksContentMainTagFallback(): void
    {
        $html = '<html><body><main class="site-main"><p>Main area</p></main></body></html>';

        $result = $this->callStatic('extractBricksContent', $html);

        $this->assertStringContainsString('Main area', $result);
    }

    public function testExtractBricksContentEntryContentFallback(): void
    {
        $html = '<html><body><div class="entry-content other-class"><p>Entry</p></div></body></html>';

        $result = $this->callStatic('extractBricksContent', $html);

        $this->assertStringContainsString('Entry', $result);
    }

    public function testExtractBricksContentNoMatchReturnsEmpty(): void
    {
        Functions\when('error_log')->justReturn(true);
        $html = '<html><body><div class="unrelated"><p>Stuff</p></div></body></html>';

        $result = $this->callStatic('extractBricksContent', $html);

        $this->assertSame('', $result);
    }

    public function testExtractBricksContentBrxContentWithFooterBefore(): void
    {
        // Uses the broader regex — content with footer inside
        $html = '<html><div id="brx-content"><article><p>Article</p></article><footer>F</footer></div></html>';

        $result = $this->callStatic('extractBricksContent', $html);

        $this->assertNotEmpty($result);
    }

    public function testExtractBricksContentBrxContentBrxFooter(): void
    {
        $html = '<body><div id="brx-content"><p>Inner</p></div><div id="brx-footer">Footer</div></body>';

        $result = $this->callStatic('extractBricksContent', $html);

        $this->assertStringContainsString('Inner', $result);
    }

    // ---------------------------------------------------------------
    // extractBricksCssFromPage — private method via reflection
    // ---------------------------------------------------------------

    public function testExtractBricksCssFromPageInlineStyles(): void
    {
        $html = '<html><head><style>.hero { color: red; }</style><style>.nav { font-size: 14px; }</style></head><body></body></html>';
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('skipped', 'no linked css'));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $this->callStatic('extractBricksCssFromPage', $html, 1);

        $this->assertStringContainsString('.hero', $result);
        $this->assertStringContainsString('.nav', $result);
    }

    public function testExtractBricksCssFromPageLinkedBricksCss(): void
    {
        $html = '<html><head><link rel="stylesheet" href="https://example.com/bricks-main.css"></head><body></body></html>';
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200], 'body' => '.bricks-section { display: flex; }']);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('.bricks-section { display: flex; }');

        $result = $this->callStatic('extractBricksCssFromPage', $html, 1);

        $this->assertStringContainsString('.bricks-section', $result);
    }

    public function testExtractBricksCssFromPageLinkedBricksHttpError(): void
    {
        $html = '<html><head><link rel="stylesheet" href="https://example.com/bricks-style.css"></head></html>';
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'request timed out'));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        // Should not throw; just returns whatever inline CSS there is
        $result = $this->callStatic('extractBricksCssFromPage', $html, 1);

        $this->assertIsString($result);
    }

    public function testExtractBricksCssFromPagePostSpecificCss(): void
    {
        $postId = 42;
        $html = '<html><head><link rel="stylesheet" href="https://example.com/post-42.min.css"></head></html>';
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200], 'body' => '.post-42 { margin: 0; }']);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('.post-42 { margin: 0; }');

        $result = $this->callStatic('extractBricksCssFromPage', $html, $postId);

        $this->assertStringContainsString('.post-42', $result);
    }

    // ---------------------------------------------------------------
    // scopeCss — private method via reflection
    // ---------------------------------------------------------------

    public function testScopeCssPrefixesTopLevelSelector(): void
    {
        $css = ".hero {\n  color: blue;\n}";
        $result = $this->callStatic('scopeCss', $css);

        $this->assertStringContainsString('.wash-pb-content .hero', $result);
    }

    public function testScopeCssPassesThroughAtMedia(): void
    {
        $css = "@media (max-width: 768px) {\n.hero {\n  font-size: 14px;\n}\n}";
        $result = $this->callStatic('scopeCss', $css);

        $this->assertStringContainsString('@media (max-width: 768px)', $result);
        $this->assertStringContainsString('.wash-pb-content .hero', $result);
    }

    public function testScopeCssPassesThroughAtFontFace(): void
    {
        $css = "@font-face {\n  font-family: 'Foo';\n  src: url('foo.woff2');\n}";
        $result = $this->callStatic('scopeCss', $css);

        $this->assertStringContainsString('@font-face', $result);
        // @font-face is passed through as-is (no prefix added)
        $this->assertStringNotContainsString('.wash-pb-content @font-face', $result);
    }

    public function testScopeCssPassesThroughAtKeyframes(): void
    {
        $css = "@keyframes spin {\n  0% { transform: rotate(0deg); }\n  100% { transform: rotate(360deg); }\n}";
        $result = $this->callStatic('scopeCss', $css);

        $this->assertStringContainsString('@keyframes', $result);
    }

    public function testScopeCssHandlesAtSupports(): void
    {
        $css = "@supports (display: grid) {\n.grid-layout {\n  display: grid;\n}\n}";
        $result = $this->callStatic('scopeCss', $css);

        $this->assertStringContainsString('@supports', $result);
        $this->assertStringContainsString('.wash-pb-content .grid-layout', $result);
    }

    public function testScopeCssClosingBraceNotPrefixed(): void
    {
        $css = ".a {\n  color: red;\n}\n.b {\n  color: blue;\n}";
        $result = $this->callStatic('scopeCss', $css);

        $this->assertStringContainsString('.wash-pb-content .a', $result);
        $this->assertStringContainsString('.wash-pb-content .b', $result);
    }

    public function testScopeCssEmptyStringReturnsEmpty(): void
    {
        $result = $this->callStatic('scopeCss', '');
        $this->assertSame('', $result);
    }

    public function testScopeCssAtLayerPrefixesSelectors(): void
    {
        $css = "@layer utilities {\n.container {\n  max-width: 1200px;\n}\n}";
        $result = $this->callStatic('scopeCss', $css);

        $this->assertStringContainsString('@layer', $result);
    }

    // ---------------------------------------------------------------
    // wrapWithCss — additional edge cases
    // ---------------------------------------------------------------

    public function testWrapWithCssContainsScopedCss(): void
    {
        $html = '<section><h1>Title</h1></section>';
        $css  = ".title {\n  font-weight: bold;\n}";

        $result = \WashPageBuilders::wrapWithCss($html, $css);

        $this->assertStringContainsString('wash-pb-content', $result);
        $this->assertStringContainsString('<style>', $result);
        $this->assertStringContainsString('</style>', $result);
        $this->assertStringContainsString($html, $result);
    }

    public function testWrapWithCssStructureIsWrappingDiv(): void
    {
        $html = '<p>Text</p>';
        $css  = "p { margin: 0; }";

        $result = \WashPageBuilders::wrapWithCss($html, $css);

        $this->assertStringStartsWith('<div class="wash-pb-content">', $result);
        $this->assertStringEndsWith('</div>', $result);
    }

    // ---------------------------------------------------------------
    // renderBricks — no elements (empty array / false)
    // ---------------------------------------------------------------

    public function testRenderBricksNoElements(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_content_2') return [];
            return '';
        });
        Functions\when('error_log')->justReturn(true);

        $result = \WashPageBuilders::render(1, 'bricks');

        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['css']);
    }

    public function testRenderBricksFalseElements(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_content_2') return false;
            return '';
        });
        Functions\when('error_log')->justReturn(true);

        $result = \WashPageBuilders::render(1, 'bricks');

        $this->assertSame('', $result['html']);
        $this->assertSame('', $result['css']);
    }

    // ---------------------------------------------------------------
    // renderBricks — frontend fetch returns error (exercises fallback path)
    // ---------------------------------------------------------------

    public function testRenderBricksFrontendFetchWpError(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el1', 'name' => 'section']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 2,
            'post_content' => '<p>Fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_permalink')->justReturn(false); // no permalink → WP_Error path
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = \WashPageBuilders::render(2, 'bricks');

        // Should fall back to the_content output via apply_filters
        $this->assertStringContainsString('Fallback', $result['html']);
    }

    // ---------------------------------------------------------------
    // renderBricks — HTTP non-200 response
    // ---------------------------------------------------------------

    public function testRenderBricksFrontendFetch404(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el2', 'name' => 'text']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 3,
            'post_content' => '<p>404 fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_permalink')->justReturn('https://example.com/test-page/');
        Functions\when('wp_remote_get')->justReturn(['status' => 404, 'body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('wp_unslash')->alias(fn($v) => $v);

        $result = \WashPageBuilders::render(3, 'bricks');

        $this->assertStringContainsString('404 fallback', $result['html']);
    }

    // ---------------------------------------------------------------
    // renderBricks — successful frontend fetch with HTML + CSS
    // ---------------------------------------------------------------

    public function testRenderBricksFrontendFetchSuccess(): void
    {
        $fullPageHtml = '<html><head><style>#brx-content { display: block; } .hero { color: red; }</style></head>'
            . '<body><div id="brx-content"><article><p>Bricks page content</p></article></div><footer id="brx-footer">Footer</footer></body></html>';

        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el3', 'name' => 'section']],
                '_bricks_page_css'       => '.custom-class { margin: 0; }',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 4,
            'post_content' => '<p>Backup</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $d = '') {
            if ($key === 'bricks_global_css') return '.global { padding: 0; }';
            return $d;
        });
        Functions\when('get_permalink')->justReturn('https://example.com/bricks-page/');
        Functions\when('wp_remote_get')->justReturn(['status' => 200, 'body' => $fullPageHtml]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($fullPageHtml);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('wp_unslash')->alias(fn($v) => $v);

        $result = \WashPageBuilders::render(4, 'bricks');

        $this->assertStringContainsString('Bricks page content', $result['html']);
        $this->assertNotEmpty($result['css']);
        // Page CSS should be included
        $this->assertStringContainsString('.custom-class', $result['css']);
    }

    // ---------------------------------------------------------------
    // renderBricks — CSS truncation at 65k
    // ---------------------------------------------------------------

    public function testRenderBricksCssTruncatedAt65k(): void
    {
        $hugeCss = str_repeat('.a { color: red; }', 4000); // ~72k

        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) use ($hugeCss) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el4', 'name' => 'text']],
                '_bricks_page_css'       => $hugeCss,
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 50,
            'post_content' => '<p>Content</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_permalink')->justReturn(false); // no permalink → skip frontend fetch
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = \WashPageBuilders::render(50, 'bricks');

        $this->assertLessThanOrEqual(65001, strlen($result['css']));
    }

    // ---------------------------------------------------------------
    // fetchBricksFromFrontend — HTTP Basic Auth via option
    // ---------------------------------------------------------------

    public function testFetchBricksFromFrontendWithHttpAuth(): void
    {
        $pageHtml = '<html><main><p>Auth content</p></main></html>';

        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el5', 'name' => 'text']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 60,
            'post_content' => '<p>Fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $d = '') {
            return match ($key) {
                'wash_http_user'    => 'admin',
                'wash_http_pass'    => 'secret',
                'bricks_global_css' => '',
                default             => $d,
            };
        });
        Functions\when('get_permalink')->justReturn('https://example.com/auth-page/');

        $capturedArgs = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$capturedArgs, $pageHtml) {
            $capturedArgs = $args;
            return ['status' => 200, 'body' => $pageHtml];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($pageHtml);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('wp_unslash')->alias(fn($v) => $v);

        $result = \WashPageBuilders::render(60, 'bricks');

        $this->assertNotNull($capturedArgs);
        $this->assertStringContainsString('Basic ', $capturedArgs['headers']['Authorization'] ?? '');
    }

    // ---------------------------------------------------------------
    // fetchBricksFromFrontend — HTTP_AUTHORIZATION header fallback
    // ---------------------------------------------------------------

    public function testFetchBricksFromFrontendWithAuthorizationHeader(): void
    {
        $pageHtml = '<html><main><p>Auth content via header</p></main></html>';

        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el6', 'name' => 'text']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 70,
            'post_content' => '<p>Fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $d = '') {
            return match ($key) {
                'wash_http_user'    => '',  // no option set
                'wash_http_pass'    => '',
                'bricks_global_css' => '',
                default             => $d,
            };
        });
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('wp_unslash')->alias(fn($v) => $v);
        Functions\when('get_permalink')->justReturn('https://example.com/auth-header-page/');

        // Inject HTTP_AUTHORIZATION via $_SERVER
        $credentials = base64_encode('server-user:server-pass');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . $credentials;
        unset($_SERVER['PHP_AUTH_USER']);

        $capturedArgs = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$capturedArgs, $pageHtml) {
            $capturedArgs = $args;
            return ['status' => 200, 'body' => $pageHtml];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($pageHtml);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = \WashPageBuilders::render(70, 'bricks');

        // Clean up
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->assertNotNull($capturedArgs);
        $this->assertStringContainsString('Basic ', $capturedArgs['headers']['Authorization'] ?? '');
    }

    // ---------------------------------------------------------------
    // fetchBricksFromFrontend — PHP_AUTH_USER fallback from $_SERVER
    // ---------------------------------------------------------------

    public function testFetchBricksFromFrontendWithPhpAuthUser(): void
    {
        $pageHtml = '<html><main><p>PHP_AUTH content</p></main></html>';

        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el7', 'name' => 'text']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 71,
            'post_content' => '<p>Fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $d = '') {
            return match ($key) {
                'wash_http_user'    => '',  // no option set
                'wash_http_pass'    => '',
                'bricks_global_css' => '',
                default             => $d,
            };
        });
        Functions\when('sanitize_text_field')->alias(fn($v) => $v);
        Functions\when('wp_unslash')->alias(fn($v) => $v);
        Functions\when('get_permalink')->justReturn('https://example.com/php-auth-page/');

        // Inject PHP_AUTH_USER via $_SERVER
        $_SERVER['PHP_AUTH_USER'] = 'php-user';
        $_SERVER['PHP_AUTH_PW']   = 'php-pass';
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $capturedArgs = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$capturedArgs, $pageHtml) {
            $capturedArgs = $args;
            return ['status' => 200, 'body' => $pageHtml];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($pageHtml);
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = \WashPageBuilders::render(71, 'bricks');

        // Clean up
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);

        $this->assertNotNull($capturedArgs);
        $authorization = $capturedArgs['headers']['Authorization'] ?? '';
        $this->assertStringContainsString('Basic ', $authorization);
        $this->assertStringContainsString(base64_encode('php-user:php-pass'), $authorization);
    }

    // ---------------------------------------------------------------
    // getBricksCss — private method via reflection: filesystem fallback
    // ---------------------------------------------------------------

    public function testGetBricksCssFromFilesystem(): void
    {
        // getBricksCss builds: $uploadDir['basedir'] . '/bricks/css/post-{id}.min.css'
        $tmpDir  = sys_get_temp_dir() . '/bricks-test-css';
        @mkdir($tmpDir . '/bricks/css', 0777, true);
        $cssFile = $tmpDir . '/bricks/css/post-99.min.css';
        file_put_contents($cssFile, '.post-99 { color: green; }');

        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $d = '') {
            if ($key === 'bricks_global_css') return '.global { padding: 0; }';
            return $d;
        });
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === '_bricks_page_css') return '.custom { margin: 5px; }';
            return '';
        });
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $tmpDir]);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = $this->callStatic('getBricksCss', 99);

        // Cleanup
        @unlink($cssFile);
        @rmdir($tmpDir . '/bricks/css');
        @rmdir($tmpDir . '/bricks');
        @rmdir($tmpDir);

        $this->assertStringContainsString('.post-99', $result);
        $this->assertStringContainsString('.global', $result);
        $this->assertStringContainsString('.custom', $result);
    }

    public function testGetBricksCssFromFilesystemAlt(): void
    {
        // Uses post-{id}.css (non-minified) when .min.css doesn't exist
        $tmpDir  = sys_get_temp_dir() . '/bricks-test-css2';
        @mkdir($tmpDir . '/bricks/css', 0777, true);
        $cssFile = $tmpDir . '/bricks/css/post-77.css';
        file_put_contents($cssFile, '.post-77 { color: blue; }');

        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $tmpDir]);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = $this->callStatic('getBricksCss', 77);

        // Cleanup
        @unlink($cssFile);
        @rmdir($tmpDir . '/bricks/css');
        @rmdir($tmpDir . '/bricks');
        @rmdir($tmpDir);

        $this->assertStringContainsString('.post-77', $result);
    }

    public function testGetBricksCssThemeFile(): void
    {
        // Simulates loading Bricks theme CSS file from filesystem
        $themeDir = sys_get_temp_dir() . '/bricks-theme-test';
        @mkdir($themeDir . '/assets/css', 0777, true);
        $themeFile = $themeDir . '/assets/css/frontend-layer.min.css';
        file_put_contents($themeFile, '.brx-container { display: flex; }');

        $childDir = $themeDir . '/child';
        @mkdir($childDir, 0777, true);
        $childStyle = $childDir . '/style.css';
        file_put_contents($childStyle, '.child { color: pink; }');

        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn($themeDir);
        Functions\when('get_stylesheet_directory')->justReturn($childDir);

        $result = $this->callStatic('getBricksCss', 88);

        // Cleanup
        @unlink($themeFile);
        @rmdir($themeDir . '/assets/css');
        @rmdir($themeDir . '/assets');
        @unlink($childStyle);
        @rmdir($childDir);
        @rmdir($themeDir);

        $this->assertStringContainsString('.brx-container', $result);
        $this->assertStringContainsString('.child', $result);
    }

    public function testGetBricksCssEmptyWhenNoFiles(): void
    {
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-missing']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme-missing');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme-missing');

        $result = $this->callStatic('getBricksCss', 55);

        $this->assertSame('', $result);
    }

    // ---------------------------------------------------------------
    // renderBricks with \Bricks\Frontend class defined (Method 2 path)
    // This test defines the Bricks namespace classes to exercise the
    // render_data fallback when frontend fetch returns empty HTML.
    // ---------------------------------------------------------------

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRenderBricksWithBricksFrontendClass(): void
    {
        // Define stub Bricks\Frontend in a separate process so it doesn't
        // pollute other tests.
        if (!class_exists('\Bricks\Frontend')) {
            eval('namespace Bricks; class Frontend { public static function render_data($elements, $postId) { return "<div class=\"bricks-render\">Bricks HTML</div>"; } }');
        }

        require_once dirname(__DIR__, 2) . '/includes/class-wash-page-builders.php';

        // Boot Brain\Monkey for this isolated process
        Monkey\setUp();
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el8', 'name' => 'section']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 80,
            'post_content' => '<p>Fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->alias(function ($key, $d = '') {
            if ($key === 'bricks_global_css') return '';
            return $d;
        });
        // No valid permalink → frontend fetch fails → falls back to render_data
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('setup_postdata')->justReturn(true);
        Functions\when('wp_reset_postdata')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = \WashPageBuilders::render(80, 'bricks');
        Monkey\tearDown();

        $this->assertStringContainsString('Bricks HTML', $result['html']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRenderBricksWithBricksFrontendClassObFallback(): void
    {
        // render_data returns null/false → triggers ob_start fallback
        if (!class_exists('\Bricks\Frontend')) {
            eval('namespace Bricks; class Frontend { public static function render_data($elements, $postId) { echo "<p>Bricks OB content</p>"; return false; } }');
        }

        require_once dirname(__DIR__, 2) . '/includes/class-wash-page-builders.php';

        Monkey\setUp();
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'el9', 'name' => 'text']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 81,
            'post_content' => '<p>Fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('setup_postdata')->justReturn(true);
        Functions\when('wp_reset_postdata')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = \WashPageBuilders::render(81, 'bricks');
        Monkey\tearDown();

        $this->assertStringContainsString('Bricks OB content', $result['html']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRenderBricksWithBricksAssetsClass(): void
    {
        if (!class_exists('\Bricks\Frontend')) {
            eval('namespace Bricks; class Frontend { public static function render_data($elements, $postId) { return ""; } }');
        }
        if (!class_exists('\Bricks\Assets')) {
            eval('namespace Bricks; class Assets { public static function generate_inline_css($postId) { echo ".bricks-inline { display: block; }"; } }');
        }

        require_once dirname(__DIR__, 2) . '/includes/class-wash-page-builders.php';

        Monkey\setUp();
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'ela', 'name' => 'text']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 82,
            'post_content' => '<p>Assets fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('setup_postdata')->justReturn(true);
        Functions\when('wp_reset_postdata')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');

        $result = \WashPageBuilders::render(82, 'bricks');
        Monkey\tearDown();

        // Assets::generate_inline_css was called, CSS should be captured
        $this->assertStringContainsString('.bricks-inline', $result['css']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRenderBricksWithDynamicDataFunction(): void
    {
        if (!class_exists('\Bricks\Frontend')) {
            eval('namespace Bricks; class Frontend { public static function render_data($elements, $postId) { return "<p>Dynamic {post_title}</p>"; } }');
        }

        require_once dirname(__DIR__, 2) . '/includes/class-wash-page-builders.php';

        Monkey\setUp();
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_bricks_page_content_2' => [['id' => 'elb', 'name' => 'text']],
                '_bricks_page_css'       => '',
                default                  => '',
            };
        });
        Functions\when('get_post')->justReturn((object) [
            'ID' => 83,
            'post_content' => '<p>Fallback</p>',
        ]);
        Functions\when('apply_filters')->alias(fn($tag, $val) => $val);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn('');
        Functions\when('get_permalink')->justReturn(false);
        Functions\when('setup_postdata')->justReturn(true);
        Functions\when('wp_reset_postdata')->justReturn(true);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/tmp/nonexistent-dir']);
        Functions\when('get_template_directory')->justReturn('/tmp/nonexistent-theme');
        Functions\when('get_stylesheet_directory')->justReturn('/tmp/nonexistent-theme');
        // Define bricks_render_dynamic_data as a Brain\Monkey stub
        Functions\when('bricks_render_dynamic_data')->alias(fn($html, $postId) => str_replace('{post_title}', 'My Post Title', $html));

        $result = \WashPageBuilders::render(83, 'bricks');
        Monkey\tearDown();

        $this->assertStringContainsString('My Post Title', $result['html']);
    }
}
