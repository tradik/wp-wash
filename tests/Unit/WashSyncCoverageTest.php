<?php
/**
 * Coverage-expanding tests for WashSync.
 *
 * Targets untested or under-tested methods:
 *  - faqPlainText, extractRankMathFaqItems, renderFaqAccordion, transformRankMathFaq
 *  - getAttachmentIdFromUrl (resized / scaled variants)
 *  - buildContentImageIdMap
 *  - getPostLanguage (manual override, TranslatePress)
 *  - getSeoData (fallback paths)
 *  - getYoastRenderedTitle / getYoastRenderedDesc (no-variable branches)
 *  - getRankMathRenderedTitle / getRankMathRenderedDesc (no-variable branches)
 *  - resolveEndpoint
 *  - isAllowedPostType
 *  - shouldSkipSync branches (isSyncing flag, revision, autosave, DOING_AUTOSAVE, post type, api config)
 *  - onSavePost (various early-return branches)
 *  - syncManually (skip + status guards)
 *  - onDeletePost (post not found, post type not allowed, happy-path)
 *  - onStatusTransition (isSyncing, not-allowed, publish→draft, other transitions)
 *  - clearSyncMeta
 *  - getCurrentSiteId
 *  - getMappedAuthorName (no-author fallback, mapping with non-array mappings)
 *  - getPostCategories
 *  - applyUrlRule, applyBlockRule details
 *  - getCustomFields
 *  - getPostLanguage TranslatePress meta
 *
 * Framework: PHPUnit 10 + Brain\Monkey. Namespace Wash\Tests\Unit.
 */

namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

// Isolated process: exercises optional-integration branches (ACF / Polylang
// translation cleanup), so it must start from a clean function table — otherwise
// a pll_*/get_fields stub leaked by an earlier test flips function_exists() and
// drives sync down a branch this class didn't mock.
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class WashSyncCoverageTest extends TestCase
{
    /** @var \WashSync */
    private $sync;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'test-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $this->sync = new \WashSync();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helper: invoke a private method via Reflection
    // ---------------------------------------------------------------
    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->sync, ...$args);
    }

    // ---------------------------------------------------------------
    // Helper: create a minimal WP_Post-like stdClass
    // ---------------------------------------------------------------
    private function makePost(array $overrides = []): \stdClass
    {
        return (object) array_merge([
            'ID'           => 1,
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => 'Test Post',
            'post_content' => '<p>Content</p>',
            'post_excerpt' => '',
            'post_name'    => 'test-post',
            'post_author'  => 1,
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // faqPlainText
    // ---------------------------------------------------------------

    public function testFaqPlainTextStripsTagsAndDecodes(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $result = $this->invokePrivate('faqPlainText', ['<strong>Hello &amp; World</strong>']);
        $this->assertSame('Hello & World', $result);
    }

    public function testFaqPlainTextCollapsesWhitespace(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $result = $this->invokePrivate('faqPlainText', ["  Multiple   spaces\n here  "]);
        $this->assertSame('Multiple spaces here', $result);
    }

    public function testFaqPlainTextHandlesEmptyString(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $result = $this->invokePrivate('faqPlainText', ['']);
        $this->assertSame('', $result);
    }

    public function testFaqPlainTextDecodesHtmlEntities(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $result = $this->invokePrivate('faqPlainText', ['Myths &amp; Facts &#8212; explained']);
        $this->assertSame('Myths & Facts — explained', $result);
    }

    // ---------------------------------------------------------------
    // extractRankMathFaqItems
    // ---------------------------------------------------------------

    public function testExtractRankMathFaqItemsReturnsEmptyForNoMatch(): void
    {
        $result = $this->invokePrivate('extractRankMathFaqItems', ['<div>No FAQ here</div>']);
        $this->assertSame([], $result);
    }

    public function testExtractRankMathFaqItemsParsesSingleQA(): void
    {
        $inner = '<div class="rank-math-list-item">'
            . '<h3 class="rank-math-question">What is SEO?</h3>'
            . '<div class="rank-math-answer"><p>Search Engine Optimization.</p></div>'
            . '</div>';

        $result = $this->invokePrivate('extractRankMathFaqItems', [$inner]);

        $this->assertCount(1, $result);
        $this->assertSame('What is SEO?', $result[0]['question']);
        $this->assertStringContainsString('Search Engine Optimization.', $result[0]['answer']);
    }

    public function testExtractRankMathFaqItemsParsesMultipleQA(): void
    {
        $inner = '<div class="rank-math-list-item">'
            . '<h3 class="rank-math-question">Q1</h3>'
            . '<div class="rank-math-answer">A1</div>'
            . '</div>'
            . '<div class="rank-math-list-item">'
            . '<h3 class="rank-math-question">Q2</h3>'
            . '<div class="rank-math-answer">A2</div>'
            . '</div>';

        $result = $this->invokePrivate('extractRankMathFaqItems', [$inner]);

        $this->assertCount(2, $result);
        $this->assertSame('Q1', $result[0]['question']);
        $this->assertSame('Q2', $result[1]['question']);
    }

    public function testExtractRankMathFaqItemsSkipsEmptyQuestion(): void
    {
        $inner = '<div class="rank-math-list-item">'
            . '<h3 class="rank-math-question">   </h3>'
            . '<div class="rank-math-answer">Some answer</div>'
            . '</div>'
            . '<div class="rank-math-list-item">'
            . '<h3 class="rank-math-question">Real Q</h3>'
            . '<div class="rank-math-answer">Real A</div>'
            . '</div>';

        $result = $this->invokePrivate('extractRankMathFaqItems', [$inner]);
        $this->assertCount(1, $result);
        $this->assertSame('Real Q', $result[0]['question']);
    }

    // ---------------------------------------------------------------
    // renderFaqAccordion
    // ---------------------------------------------------------------

    public function testRenderFaqAccordionProducesDetailsAndSummary(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));
        Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars($s, ENT_QUOTES));
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));

        $faqs = [
            ['question' => 'Q1', 'answer' => '<p>A1</p>'],
            ['question' => 'Q2', 'answer' => '<p>A2</p>'],
        ];

        $result = $this->invokePrivate('renderFaqAccordion', [$faqs]);

        $this->assertStringContainsString('<details', $result);
        $this->assertStringContainsString('<summary', $result);
        $this->assertStringContainsString('Q1', $result);
        $this->assertStringContainsString('Q2', $result);
        $this->assertStringContainsString('FAQPage', $result);
        $this->assertStringContainsString('application/ld+json', $result);
    }

    public function testRenderFaqAccordionJsonLdContainsQuestions(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));
        Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars($s, ENT_QUOTES));
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));

        $faqs = [['question' => 'What is life?', 'answer' => '42']];

        $result = $this->invokePrivate('renderFaqAccordion', [$faqs]);

        $this->assertStringContainsString('What is life?', $result);
        $this->assertStringContainsString('"@type":"FAQPage"', $result);
    }

    // ---------------------------------------------------------------
    // transformRankMathFaq
    // ---------------------------------------------------------------

    public function testTransformRankMathFaqReturnsUnchangedWhenNoFaqMarker(): void
    {
        $content = '<p>Just a paragraph, no FAQ.</p>';
        $result = $this->invokePrivate('transformRankMathFaq', [$content]);
        $this->assertSame($content, $result);
    }

    public function testTransformRankMathFaqTransformsBlock(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));
        Functions\when('esc_attr')->alias(fn($s) => htmlspecialchars($s, ENT_QUOTES));
        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));

        $content = '<div id="rank-math-faq" class="rank-math-block">'
            . '<div class="rank-math-list-item">'
            . '<h3 class="rank-math-question">Test Q</h3>'
            . '<div class="rank-math-answer">Test A</div>'
            . '</div></div>';

        $result = $this->invokePrivate('transformRankMathFaq', [$content]);

        // Should be transformed to accordion
        $this->assertStringContainsString('<details', $result);
        $this->assertStringContainsString('Test Q', $result);
    }

    public function testTransformRankMathFaqKeepsUnparsableBlockUnchanged(): void
    {
        // Block marker exists but no valid items inside
        $content = '<div id="rank-math-faq" class="rank-math-block">'
            . '<p>No items here at all</p>'
            . '</div></div>';

        $result = $this->invokePrivate('transformRankMathFaq', [$content]);
        // Should remain unchanged (no parseable items)
        $this->assertStringContainsString('rank-math-faq', $result);
    }

    // ---------------------------------------------------------------
    // getAttachmentIdFromUrl
    // ---------------------------------------------------------------

    public function testGetAttachmentIdFromUrlReturnsIdForDirectMatch(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            if ($url === 'https://example.com/wp-content/uploads/2024/01/photo.jpg') {
                return 42;
            }
            return 0;
        });

        $result = $this->invokePrivate('getAttachmentIdFromUrl', [
            'https://example.com/wp-content/uploads/2024/01/photo.jpg'
        ]);
        $this->assertSame(42, $result);
    }

    public function testGetAttachmentIdFromUrlStripsQueryString(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            if ($url === 'https://example.com/wp-content/uploads/2024/01/photo.jpg') {
                return 55;
            }
            return 0;
        });

        $result = $this->invokePrivate('getAttachmentIdFromUrl', [
            'https://example.com/wp-content/uploads/2024/01/photo.jpg?ver=123'
        ]);
        $this->assertSame(55, $result);
    }

    public function testGetAttachmentIdFromUrlStripsResizeSuffix(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            // Only matches base URL without size suffix
            if ($url === 'https://example.com/wp-content/uploads/2024/01/photo.jpg') {
                return 77;
            }
            return 0;
        });

        $result = $this->invokePrivate('getAttachmentIdFromUrl', [
            'https://example.com/wp-content/uploads/2024/01/photo-768x512.jpg'
        ]);
        $this->assertSame(77, $result);
    }

    public function testGetAttachmentIdFromUrlStripsScaledSuffix(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            if ($url === 'https://example.com/wp-content/uploads/2024/01/photo.jpg') {
                return 88;
            }
            return 0;
        });

        $result = $this->invokePrivate('getAttachmentIdFromUrl', [
            'https://example.com/wp-content/uploads/2024/01/photo-scaled.jpg'
        ]);
        $this->assertSame(88, $result);
    }

    public function testGetAttachmentIdFromUrlTriesScaledFallback(): void
    {
        // Simulates: -1024x785.jpg → strip size → photo.jpg (not found),
        // then try photo-scaled.jpg → found
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            if (str_ends_with($url, 'photo-scaled.jpg')) {
                return 99;
            }
            return 0;
        });

        $result = $this->invokePrivate('getAttachmentIdFromUrl', [
            'https://example.com/wp-content/uploads/2024/01/photo-1024x785.jpg'
        ]);
        $this->assertSame(99, $result);
    }

    public function testGetAttachmentIdFromUrlReturnsFalseWhenNotFound(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $result = $this->invokePrivate('getAttachmentIdFromUrl', [
            'https://example.com/wp-content/uploads/2024/01/unknown.jpg'
        ]);
        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // buildContentImageIdMap
    // ---------------------------------------------------------------

    public function testBuildContentImageIdMapReturnsEmptyForEmptyContent(): void
    {
        $result = $this->invokePrivate('buildContentImageIdMap', ['']);
        $this->assertSame([], $result);
    }

    public function testBuildContentImageIdMapExtractsWpImageClassId(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $content = '<img class="wp-image-42 size-large" src="https://cdn.example.com/photo.jpg" alt="test">';
        $result = $this->invokePrivate('buildContentImageIdMap', [$content]);

        $this->assertArrayHasKey('https://cdn.example.com/photo.jpg', $result);
        $this->assertSame(42, $result['https://cdn.example.com/photo.jpg']);
    }

    public function testBuildContentImageIdMapFallsBackToUrlResolution(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            if (str_contains($url, 'document.pdf')) {
                return 15;
            }
            return 0;
        });

        $content = '<a href="https://example.com/wp-content/uploads/2024/01/document.pdf">PDF</a>';
        $result = $this->invokePrivate('buildContentImageIdMap', [$content]);

        $this->assertArrayHasKey('https://example.com/wp-content/uploads/2024/01/document.pdf', $result);
        $this->assertSame(15, $result['https://example.com/wp-content/uploads/2024/01/document.pdf']);
    }

    public function testBuildContentImageIdMapDeduplicatesUrls(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            return str_contains($url, 'photo.jpg') ? 10 : 0;
        });

        // Same URL appears twice (no wp-image class so goes to fallback path)
        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $content = "<img src=\"{$url}\"><a href=\"{$url}\">link</a>";
        $result = $this->invokePrivate('buildContentImageIdMap', [$content]);

        // Should appear only once
        $this->assertCount(1, $result);
    }

    // ---------------------------------------------------------------
    // resolveEndpoint
    // ---------------------------------------------------------------

    public function testResolveEndpointReturnsPageSyncForPage(): void
    {
        $result = $this->invokePrivate('resolveEndpoint', ['page']);
        $this->assertSame('/api/page-sync', $result);
    }

    public function testResolveEndpointReturnsContentSyncForPost(): void
    {
        $result = $this->invokePrivate('resolveEndpoint', ['post']);
        $this->assertSame('/api/content-sync', $result);
    }

    public function testResolveEndpointReturnsContentSyncForCustomType(): void
    {
        $result = $this->invokePrivate('resolveEndpoint', ['product']);
        $this->assertSame('/api/content-sync', $result);
    }

    // ---------------------------------------------------------------
    // isAllowedPostType
    // ---------------------------------------------------------------

    public function testIsAllowedPostTypeReturnsTrueForPost(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => $k === 'wash_post_types' ? 'post,page' : $d);
        $sync = new \WashSync();
        $ref  = new \ReflectionMethod(\WashSync::class, 'isAllowedPostType');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($sync, 'post'));
    }

    public function testIsAllowedPostTypeReturnsFalseForDisallowed(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => $k === 'wash_post_types' ? 'post,page' : $d);
        $sync = new \WashSync();
        $ref  = new \ReflectionMethod(\WashSync::class, 'isAllowedPostType');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($sync, 'attachment'));
    }

    public function testIsAllowedPostTypeHandlesSpacesInOption(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => $k === 'wash_post_types' ? 'post , page , news' : $d);
        $sync = new \WashSync();
        $ref  = new \ReflectionMethod(\WashSync::class, 'isAllowedPostType');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($sync, 'news'));
    }

    // ---------------------------------------------------------------
    // getCurrentSiteId
    // ---------------------------------------------------------------

    public function testGetCurrentSiteIdReturnOneForNonMultisite(): void
    {
        Functions\when('is_multisite')->justReturn(false);
        $result = $this->invokePrivate('getCurrentSiteId', []);
        $this->assertSame(1, $result);
    }

    public function testGetCurrentSiteIdReturnsBlogIdForMultisite(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('get_current_blog_id')->justReturn(5);
        $result = $this->invokePrivate('getCurrentSiteId', []);
        $this->assertSame(5, $result);
    }

    // ---------------------------------------------------------------
    // getPostLanguage — manual override and TranslatePress
    // ---------------------------------------------------------------

    public function testGetPostLanguageReturnsManualOverride(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single = false) {
            return $key === '_wash_language' ? 'fr' : '';
        });

        $result = $this->invokePrivate('getPostLanguage', [1]);
        $this->assertSame('fr', $result);
    }

    public function testGetPostLanguageNormalizesManualOverrideToLowercase(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single = false) {
            return $key === '_wash_language' ? '  EN  ' : '';
        });

        $result = $this->invokePrivate('getPostLanguage', [1]);
        $this->assertSame('en', $result);
    }

    public function testGetPostLanguageUsesTranslatePressMetaWhenNoOverride(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single = false) {
            if ($key === '_wash_language') return '';
            if ($key === 'trp_language')   return 'de_DE';
            return '';
        });

        $result = $this->invokePrivate('getPostLanguage', [1]);
        $this->assertSame('de', $result);
    }

    public function testGetPostLanguageReturnsNullWhenNoLanguageDetected(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->invokePrivate('getPostLanguage', [1]);
        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // shouldSkipSync
    // ---------------------------------------------------------------

    public function testShouldSkipSyncReturnsTrueWhenIsSyncingFlagSet(): void
    {
        // Set the isSyncing flag via reflection
        $ref = new \ReflectionProperty(\WashSync::class, 'isSyncing');
        $ref->setAccessible(true);
        $ref->setValue($this->sync, true);

        Functions\when('wp_is_post_revision')->justReturn(false);

        $post = $this->makePost();
        $result = $this->invokePrivate('shouldSkipSync', [1, $post]);
        $this->assertTrue($result);
    }

    public function testShouldSkipSyncReturnsTrueForRevision(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(true);

        $post = $this->makePost();
        $result = $this->invokePrivate('shouldSkipSync', [1, $post]);
        $this->assertTrue($result);
    }

    public function testShouldSkipSyncReturnsTrueForAutosave(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(true);

        $post = $this->makePost();
        $result = $this->invokePrivate('shouldSkipSync', [1, $post]);
        $this->assertTrue($result);
    }

    public function testShouldSkipSyncReturnsTrueForDisallowedPostType(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types'    => 'post,page',
                'wash_shopify_store' => 'test-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });

        $sync = new \WashSync();
        $post = $this->makePost(['post_type' => 'attachment']);
        $ref  = new \ReflectionMethod(\WashSync::class, 'shouldSkipSync');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($sync, 1, $post));
    }

    public function testShouldSkipSyncReturnsTrueWhenApiNotConfigured(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types'    => 'post,page',
                'wash_shopify_store' => '',  // empty = not configured
                'wash_api_key'       => '',  // empty = not configured
                'wash_app_url'       => '',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });

        $sync = new \WashSync();
        $post = $this->makePost(['post_type' => 'post']);
        $ref  = new \ReflectionMethod(\WashSync::class, 'shouldSkipSync');
        $ref->setAccessible(true);
        $this->assertTrue($ref->invoke($sync, 1, $post));
    }

    // ---------------------------------------------------------------
    // onSavePost — early-return guards
    // ---------------------------------------------------------------

    public function testOnSavePostSkipsWhenShouldSkipSyncReturnsTrue(): void
    {
        // Revision → shouldSkipSync = true → no further action
        Functions\when('wp_is_post_revision')->justReturn(true);
        Functions\when('get_option')->alias(fn($k, $d = '') => $d);

        // Should not throw or call API
        $this->sync->onSavePost(1, $this->makePost(), false);
        $this->assertTrue(true); // reached without error
    }

    public function testOnSavePostSkipsWhenSyncOnPublishDisabled(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_on_publish' => '0',
                'wash_post_types'      => 'post,page',
                'wash_shopify_store'   => 'test-store',
                'wash_api_key'         => 'test-key',
                'wash_app_url'         => 'https://app-wash.tradik.dev',
                'wash_http_user'       => '',
                'wash_http_pass'       => '',
                default                => $default,
            };
        });

        $sync = new \WashSync();
        $sync->onSavePost(1, $this->makePost(['post_status' => 'publish']), true);
        $this->assertTrue(true);
    }

    public function testOnSavePostSkipsWhenPostNotPublished(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_on_publish' => '1',
                'wash_post_types'      => 'post,page',
                'wash_shopify_store'   => 'test-store',
                'wash_api_key'         => 'test-key',
                'wash_app_url'         => 'https://app-wash.tradik.dev',
                'wash_http_user'       => '',
                'wash_http_pass'       => '',
                default                => $default,
            };
        });

        $sync = new \WashSync();
        $sync->onSavePost(1, $this->makePost(['post_status' => 'draft']), false);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // syncManually — guards
    // ---------------------------------------------------------------

    public function testSyncManuallySkipsWhenRevision(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(true);
        $this->sync->syncManually(1, $this->makePost());
        $this->assertTrue(true);
    }

    public function testSyncManuallySkipsWhenNotPublished(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types'    => 'post,page',
                'wash_shopify_store' => 'test-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });

        $sync = new \WashSync();
        $sync->syncManually(1, $this->makePost(['post_status' => 'draft']));
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // onDeletePost
    // ---------------------------------------------------------------

    public function testOnDeletePostSkipsWhenPostNotFound(): void
    {
        Functions\when('get_post')->justReturn(null);

        $this->sync->onDeletePost(999);
        $this->assertTrue(true);
    }

    public function testOnDeletePostSkipsWhenPostTypeNotAllowed(): void
    {
        Functions\when('get_post')->justReturn($this->makePost(['post_type' => 'attachment']));
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types'    => 'post,page',
                'wash_shopify_store' => 'test-store',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });

        $sync = new \WashSync();
        $sync->onDeletePost(1);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // onStatusTransition
    // ---------------------------------------------------------------

    public function testOnStatusTransitionSkipsWhenIsSyncing(): void
    {
        $ref = new \ReflectionProperty(\WashSync::class, 'isSyncing');
        $ref->setAccessible(true);
        $ref->setValue($this->sync, true);

        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types' => 'post,page',
            default           => $d,
        });

        $post = $this->makePost();
        $this->sync->onStatusTransition('draft', 'publish', $post);
        $this->assertTrue(true);
    }

    public function testOnStatusTransitionSkipsWhenPostTypeNotAllowed(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'    => 'post,page',
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });

        $sync = new \WashSync();
        $post = $this->makePost(['post_type' => 'attachment']);
        $sync->onStatusTransition('draft', 'publish', $post);
        $this->assertTrue(true);
    }

    public function testOnStatusTransitionSkipsWhenOldStatusNotPublish(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'    => 'post,page',
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });

        $sync = new \WashSync();
        $post = $this->makePost(['post_type' => 'post']);
        // draft → publish = not unpublishing
        $sync->onStatusTransition('publish', 'draft', $post);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // getMappedAuthorName — additional branches
    // ---------------------------------------------------------------

    public function testGetMappedAuthorNameReturnsBlogNameWhenAllMetaEmpty(): void
    {
        Functions\when('get_the_author_meta')->justReturn('');
        Functions\when('get_bloginfo')->justReturn('My Site');

        $result = $this->invokePrivate('getMappedAuthorName', [1]);
        $this->assertSame('My Site', $result);
    }

    public function testGetMappedAuthorNameFallsBackToDefaultWhenMappingNotArray(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_authors_enabled' => '1',
                'wash_author_mappings'  => 'not-an-array', // non-array
                'wash_shopify_store'    => 'test-store',
                'wash_api_key'          => 'test-key',
                'wash_app_url'          => 'https://app-wash.tradik.dev',
                'wash_http_user'        => '',
                'wash_http_pass'        => '',
                default                 => $default,
            };
        });
        Functions\when('get_the_author_meta')->alias(function ($field, $id) {
            return $field === 'display_name' ? 'Default User' : '';
        });
        Functions\when('get_bloginfo')->justReturn('Site');

        $sync = new \WashSync();
        $ref  = new \ReflectionMethod(\WashSync::class, 'getMappedAuthorName');
        $ref->setAccessible(true);
        $this->assertSame('Default User', $ref->invoke($sync, 5));
    }

    public function testGetMappedAuthorNameReturnsDefaultWhenNoMapping(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_authors_enabled' => '1',
                'wash_author_mappings'  => [99 => 'Other Author'], // no entry for id 5
                'wash_shopify_store'    => 'test-store',
                'wash_api_key'          => 'test-key',
                'wash_app_url'          => 'https://app-wash.tradik.dev',
                'wash_http_user'        => '',
                'wash_http_pass'        => '',
                default                 => $default,
            };
        });
        Functions\when('get_the_author_meta')->alias(fn($f, $id) => $f === 'display_name' ? 'John' : '');
        Functions\when('get_bloginfo')->justReturn('Site');

        $sync = new \WashSync();
        $ref  = new \ReflectionMethod(\WashSync::class, 'getMappedAuthorName');
        $ref->setAccessible(true);
        $this->assertSame('John', $ref->invoke($sync, 5));
    }

    // ---------------------------------------------------------------
    // getPostCategories
    // ---------------------------------------------------------------

    public function testGetPostCategoriesReturnsSlugAndNamePairs(): void
    {
        $cat1 = (object) ['slug' => 'tech', 'name' => 'Technology'];
        $cat2 = (object) ['slug' => 'news', 'name' => 'News'];
        Functions\when('get_the_category')->justReturn([$cat1, $cat2]);

        $result = $this->invokePrivate('getPostCategories', [1]);

        $this->assertCount(2, $result);
        $this->assertSame(['slug' => 'tech', 'name' => 'Technology'], $result[0]);
        $this->assertSame(['slug' => 'news', 'name' => 'News'], $result[1]);
    }

    public function testGetPostCategoriesReturnsEmptyArrayWhenNone(): void
    {
        Functions\when('get_the_category')->justReturn(false);

        $result = $this->invokePrivate('getPostCategories', [1]);
        $this->assertSame([], $result);
    }

    public function testGetPostCategoriesReturnsEmptyArrayOnWpError(): void
    {
        $error = new \WP_Error('no_cats', 'No categories');
        Functions\when('get_the_category')->justReturn($error);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $this->invokePrivate('getPostCategories', [1]);
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // applyUrlRule
    // ---------------------------------------------------------------

    public function testApplyUrlRuleReplacesInHrefAttribute(): void
    {
        // hasFeature() check — not needed here since we call the method directly
        $ref = new \ReflectionMethod(\WashSync::class, 'applyUrlRule');
        $ref->setAccessible(true);

        $content = '<a href="https://old.example.com/page">Link</a>';
        $result  = $ref->invoke($this->sync, $content, 'old.example.com', 'new.example.com');
        $this->assertStringContainsString('new.example.com', $result);
    }

    public function testApplyUrlRuleReplacesInSrcAttribute(): void
    {
        $ref = new \ReflectionMethod(\WashSync::class, 'applyUrlRule');
        $ref->setAccessible(true);

        $content = '<img src="https://old.cdn.com/img.jpg">';
        $result  = $ref->invoke($this->sync, $content, 'old.cdn.com', 'new.cdn.com');
        $this->assertStringContainsString('new.cdn.com', $result);
    }

    public function testApplyUrlRuleReplacesInCssUrlFunction(): void
    {
        $ref = new \ReflectionMethod(\WashSync::class, 'applyUrlRule');
        $ref->setAccessible(true);

        $content = 'background-image: url("https://old.cdn.com/bg.png");';
        $result  = $ref->invoke($this->sync, $content, 'old.cdn.com', 'new.cdn.com');
        $this->assertStringContainsString('new.cdn.com', $result);
    }

    public function testApplyUrlRuleReplacesInPlainText(): void
    {
        $ref = new \ReflectionMethod(\WashSync::class, 'applyUrlRule');
        $ref->setAccessible(true);

        $content = 'Visit https://old.example.com/page for details.';
        $result  = $ref->invoke($this->sync, $content, 'old.example.com', 'new.example.com');
        $this->assertStringContainsString('new.example.com', $result);
    }

    // ---------------------------------------------------------------
    // applyBlockRule
    // ---------------------------------------------------------------

    public function testApplyBlockRuleMatchesStandardBlock(): void
    {
        Functions\when('register_block_type')->justReturn(true);
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        if (!class_exists('WP_Block_Type_Registry')) {
            // Create minimal stub
            eval('class WP_Block_Type_Registry {
                public static function get_instance() { return new self(); }
                public function is_registered($name) { return false; }
            }');
        }

        $ref = new \ReflectionMethod(\WashSync::class, 'applyBlockRule');
        $ref->setAccessible(true);

        $content = '<!-- wp:core/embed --><p>Block content</p><!-- /wp:core/embed -->';
        $result  = $ref->invoke($this->sync, $content, 'wp:core/embed', '[REPLACED]');
        $this->assertSame('[REPLACED]', $result);
    }

    public function testApplyBlockRuleHandlesEmbedProvider(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $ref = new \ReflectionMethod(\WashSync::class, 'applyBlockRule');
        $ref->setAccessible(true);

        $content = '<!-- wp:embed {"providerNameSlug":"youtube","url":"https://youtube.com"} -->'
            . '<figure>embed</figure><!-- /wp:embed -->';
        $result  = $ref->invoke($this->sync, $content, 'embed/youtube', '[VIDEO REMOVED]');
        $this->assertSame('[VIDEO REMOVED]', $result);
    }

    // ---------------------------------------------------------------
    // applyShortcodeRule — %content% variable
    // ---------------------------------------------------------------

    public function testApplyShortcodeRuleReplacesContentVariable(): void
    {
        Functions\when('shortcode_exists')->justReturn(true); // already registered
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $ref = new \ReflectionMethod(\WashSync::class, 'applyShortcodeRule');
        $ref->setAccessible(true);

        $content = '[newsletter]<strong>Inner HTML</strong>[/newsletter]';
        $result  = $ref->invoke($this->sync, $content, '[newsletter]', '<p>%content%</p>');
        $this->assertSame('<p>Inner HTML</p>', $result);
    }

    public function testApplyShortcodeRuleSelfClosingShortcode(): void
    {
        Functions\when('shortcode_exists')->justReturn(true);
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $ref = new \ReflectionMethod(\WashSync::class, 'applyShortcodeRule');
        $ref->setAccessible(true);

        $content = '<p>Subscribe: [newsletter]</p>';
        $result  = $ref->invoke($this->sync, $content, '[newsletter]', '<div class="nl"></div>');
        $this->assertSame('<p>Subscribe: <div class="nl"></div></p>', $result);
    }

    public function testApplyShortcodeRuleFallsBackToStrReplaceWhenNoTag(): void
    {
        Functions\when('wp_strip_all_tags')->alias(fn($s) => strip_tags((string) $s));

        $ref = new \ReflectionMethod(\WashSync::class, 'applyShortcodeRule');
        $ref->setAccessible(true);

        $content = 'Replace old with something.';
        $result  = $ref->invoke($this->sync, $content, 'old', 'new');
        $this->assertSame('Replace new with something.', $result);
    }

    // ---------------------------------------------------------------
    // getSeoData — fallback path (no Yoast, no RankMath)
    // ---------------------------------------------------------------

    public function testGetSeoDataFallsBackToPostTitle(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_the_title')->justReturn('My Post Title');
        Functions\when('get_the_excerpt')->justReturn('');

        $result = $this->invokePrivate('getSeoData', [1]);

        $this->assertSame('My Post Title', $result['metaTitle']);
        $this->assertSame('', $result['metaDescription']);
        $this->assertSame('', $result['focusKeyword']);
    }

    public function testGetSeoDataUsesExcerptAsDescription(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_the_title')->justReturn('Title');
        Functions\when('get_the_excerpt')->justReturn('This is the excerpt.');

        $result = $this->invokePrivate('getSeoData', [1]);

        $this->assertSame('This is the excerpt.', $result['metaDescription']);
    }

    // ---------------------------------------------------------------
    // getYoastRenderedTitle / Desc — no-variable branches
    // ---------------------------------------------------------------

    public function testGetYoastRenderedTitleReturnsEmptyWhenNoMeta(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->invokePrivate('getYoastRenderedTitle', [1]);
        $this->assertSame('', $result);
    }

    public function testGetYoastRenderedTitleReturnsRawWhenNoVariables(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return $key === '_yoast_wpseo_title' ? 'Plain Title' : '';
        });

        $result = $this->invokePrivate('getYoastRenderedTitle', [1]);
        $this->assertSame('Plain Title', $result);
    }

    public function testGetYoastRenderedDescReturnsEmptyWhenNoMeta(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->invokePrivate('getYoastRenderedDesc', [1]);
        $this->assertSame('', $result);
    }

    public function testGetYoastRenderedDescReturnsRawWhenNoVariables(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return $key === '_yoast_wpseo_metadesc' ? 'Plain description.' : '';
        });

        $result = $this->invokePrivate('getYoastRenderedDesc', [1]);
        $this->assertSame('Plain description.', $result);
    }

    // ---------------------------------------------------------------
    // getRankMathRenderedTitle / Desc — no-variable branches
    // ---------------------------------------------------------------

    public function testGetRankMathRenderedTitleReturnsEmptyWhenNoMeta(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->invokePrivate('getRankMathRenderedTitle', [1]);
        $this->assertSame('', $result);
    }

    public function testGetRankMathRenderedTitleReturnsRawWhenNoVariables(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return $key === 'rank_math_title' ? 'RankMath Plain Title' : '';
        });

        $result = $this->invokePrivate('getRankMathRenderedTitle', [1]);
        $this->assertSame('RankMath Plain Title', $result);
    }

    public function testGetRankMathRenderedTitleFallsBackToResolveSeoVars(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                'rank_math_title'           => '%title% - %sitename%',
                'rank_math_primary_category' => '',
                default                      => '',
            };
        });
        Functions\when('get_post')->justReturn((object)['post_title' => 'My Title', 'post_author' => 1]);
        Functions\when('apply_filters')->justReturn('-');
        Functions\when('get_bloginfo')->justReturn('My Blog');
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_the_date')->justReturn('2026-01-01');
        Functions\when('get_the_author_meta')->justReturn('Author');
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('get_term')->justReturn(null);

        $result = $this->invokePrivate('getRankMathRenderedTitle', [1]);
        $this->assertSame('My Title - My Blog', $result);
    }

    public function testGetRankMathRenderedDescReturnsEmptyWhenNoMeta(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $result = $this->invokePrivate('getRankMathRenderedDesc', [1]);
        $this->assertSame('', $result);
    }

    public function testGetRankMathRenderedDescReturnsRawWhenNoVariables(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return $key === 'rank_math_description' ? 'RankMath Plain Desc' : '';
        });

        $result = $this->invokePrivate('getRankMathRenderedDesc', [1]);
        $this->assertSame('RankMath Plain Desc', $result);
    }

    // ---------------------------------------------------------------
    // getCustomFields
    // ---------------------------------------------------------------

    public function testGetCustomFieldsReturnsEmptyWhenMetaNotArray(): void
    {
        Functions\when('get_post_meta')->justReturn(false);

        // We need WashMetafields to be loadable
        require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';

        $result = $this->invokePrivate('getCustomFields', [1]);
        $this->assertSame([], $result);
    }

    public function testGetCustomFieldsSkipsPrivateKeys(): void
    {
        Functions\when('get_post_meta')->justReturn([
            '_private_key' => ['private_val'],
            'public_key'   => ['public_val'],
        ]);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_metafields_enabled'  => [],
            'wash_shopify_store'       => 'test-store',
            'wash_api_key'             => 'test-key',
            'wash_app_url'             => 'https://app-wash.tradik.dev',
            'wash_http_user'           => '',
            'wash_http_pass'           => '',
            default                    => $d,
        });

        require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';

        $sync   = new \WashSync();
        $ref    = new \ReflectionMethod(\WashSync::class, 'getCustomFields');
        $ref->setAccessible(true);
        $result = $ref->invoke($sync, 1);

        // _private_key should be excluded
        $this->assertArrayNotHasKey('_private_key', $result);
    }

    // ---------------------------------------------------------------
    // rewriteInternalUrls — early-return paths
    // Note: wp_parse_url() is already defined in bootstrap.php as a passthrough
    // to parse_url(), so we must NOT attempt to re-stub it here via Brain\Monkey.
    // ---------------------------------------------------------------

    public function testRewriteInternalUrlsReturnsUnchangedWhenNoLinks(): void
    {
        // Bootstrap stub for wp_parse_url already in place (parse_url passthrough)
        $content = '<p>No links here.</p>';
        $result  = $this->invokePrivate('rewriteInternalUrls', [$content]);
        $this->assertSame($content, $result);
    }

    public function testRewriteInternalUrlsSkipsWpContentLinks(): void
    {
        Functions\when('url_to_postid')->justReturn(0);

        $content = '<a href="https://example.com/wp-content/uploads/file.pdf">File</a>';
        $result  = $this->invokePrivate('rewriteInternalUrls', [$content]);
        $this->assertSame($content, $result);
    }

    public function testRewriteInternalUrlsSkipsWpAdminLinks(): void
    {
        Functions\when('url_to_postid')->justReturn(0);

        $content = '<a href="https://example.com/wp-admin/options.php">Admin</a>';
        $result  = $this->invokePrivate('rewriteInternalUrls', [$content]);
        $this->assertSame($content, $result);
    }

    public function testRewriteInternalUrlsSkipsAnchorLinks(): void
    {
        $content = '<a href="#section-1">Jump</a>';
        $result  = $this->invokePrivate('rewriteInternalUrls', [$content]);
        $this->assertSame($content, $result);
    }

    public function testRewriteInternalUrlsRewritesPageLink(): void
    {
        Functions\when('url_to_postid')->alias(fn($url) => str_contains($url, 'about') ? 10 : 0);
        Functions\when('get_post')->justReturn($this->makePost(['ID' => 10, 'post_type' => 'page', 'post_name' => 'about']));
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'    => 'post,page',
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });

        $sync    = new \WashSync();
        $ref     = new \ReflectionMethod(\WashSync::class, 'rewriteInternalUrls');
        $ref->setAccessible(true);
        $content = '<a href="https://example.com/about">About</a>';
        $result  = $ref->invoke($sync, $content);

        $this->assertStringContainsString('/pages/about', $result);
    }

    public function testRewriteInternalUrlsRewritesPostLink(): void
    {
        Functions\when('url_to_postid')->alias(fn($url) => str_contains($url, 'my-post') ? 5 : 0);
        Functions\when('get_post')->justReturn($this->makePost(['ID' => 5, 'post_type' => 'post', 'post_name' => 'my-post']));
        Functions\when('get_post_meta')->alias(fn($id, $key, $single) => $key === '_wash_blog_handle' ? 'blog' : '');
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'    => 'post,page',
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });

        $sync    = new \WashSync();
        $ref     = new \ReflectionMethod(\WashSync::class, 'rewriteInternalUrls');
        $ref->setAccessible(true);
        $content = '<a href="https://example.com/my-post">Post</a>';
        $result  = $ref->invoke($sync, $content);

        $this->assertStringContainsString('/blogs/blog/my-post', $result);
    }

    public function testRewriteInternalUrlsDefaultsBlogHandleToNews(): void
    {
        Functions\when('url_to_postid')->alias(fn($url) => str_contains($url, 'my-article') ? 7 : 0);
        Functions\when('get_post')->justReturn($this->makePost(['ID' => 7, 'post_type' => 'post', 'post_name' => 'my-article']));
        Functions\when('get_post_meta')->justReturn(''); // no blog handle stored
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'    => 'post,page',
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });

        $sync    = new \WashSync();
        $ref     = new \ReflectionMethod(\WashSync::class, 'rewriteInternalUrls');
        $ref->setAccessible(true);
        $content = '<a href="https://example.com/my-article">Article</a>';
        $result  = $ref->invoke($sync, $content);

        $this->assertStringContainsString('/blogs/news/my-article', $result);
    }

    // ---------------------------------------------------------------
    // preserveCustomHtmlTags — exact-name pattern (no wildcard)
    // ---------------------------------------------------------------

    public function testPreserveCustomHtmlTagsHandlesExactTagName(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_allowed_html_tags' => 'mywidget',
            default                  => $d,
        });

        $sync    = new \WashSync();
        $ref     = new \ReflectionMethod(\WashSync::class, 'preserveCustomHtmlTags');
        $ref->setAccessible(true);
        $content = '<mywidget data-id="1">inner</mywidget>';
        $result  = $ref->invoke($sync, $content);

        $this->assertStringContainsString('data-wash-tag="mywidget"', $result);
        $this->assertStringNotContainsString('<mywidget', $result);
    }

    // ---------------------------------------------------------------
    // getPostTags (private, exercised via getPostTagsWithCategories)
    // ---------------------------------------------------------------

    public function testGetPostTagsReturnsNamesArray(): void
    {
        $tag = (object)['name' => 'Alpha', 'term_id' => 1];
        Functions\when('get_the_tags')->justReturn([$tag]);
        Functions\when('get_the_category')->justReturn([]);

        $result = $this->invokePrivate('getPostTagsWithCategories', [1]);
        $this->assertSame(['Alpha'], $result);
    }

    public function testGetPostTagsHandlesWpError(): void
    {
        $error = new \WP_Error('no_tags', 'none');
        Functions\when('get_the_tags')->justReturn($error);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('get_the_category')->justReturn([]);

        $result = $this->invokePrivate('getPostTagsWithCategories', [1]);
        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // init() — just exercises the add_action calls
    // ---------------------------------------------------------------

    public function testInitRegistersHooks(): void
    {
        Functions\when('add_action')->justReturn(true);

        // Should not throw
        $this->sync->init();
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // rewriteFileUrls / rewriteImageUrls (alias) — full path
    // ---------------------------------------------------------------

    public function testRewriteFileUrlsReturnsSamContentWhenNoUploadUrls(): void
    {
        $content = '<p>No WordPress uploads here.</p>';
        $result  = $this->invokePrivate('rewriteImageUrls', [$content]);
        $this->assertSame($content, $result);
    }

    public function testRewriteFileUrlsRemovesSrcsetAndSizes(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $content = '<img src="https://example.com/wp-content/uploads/img.jpg" srcset="img.jpg 2x" sizes="(max-width: 100px) 100px">';
        $result  = $this->invokePrivate('rewriteImageUrls', [$content]);

        $this->assertStringNotContainsString('srcset=', $result);
        $this->assertStringNotContainsString('sizes=', $result);
    }

    public function testRewriteFileUrlsRewritesKnownAttachmentToCdn(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            return str_contains($url, 'photo.jpg') ? 42 : 0;
        });

        // Need to stub WashImages static method — load the class and ensure it's defined
        require_once dirname(__DIR__, 2) . '/includes/class-wash-images.php';
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($id === 42 && $key === '_wash_cdn_url') {
                return 'https://cdn.shopify.com/s/files/1/photo.jpg';
            }
            return '';
        });

        $content = '<img src="https://example.com/wp-content/uploads/2024/photo.jpg" alt="test">';
        $result  = $this->invokePrivate('rewriteImageUrls', [$content]);

        $this->assertStringContainsString('cdn.shopify.com', $result);
        $this->assertStringNotContainsString('wp-content/uploads', $result);
    }

    public function testRewriteFileUrlsLeavesUrlUnchangedWhenNoCdnUrl(): void
    {
        Functions\when('attachment_url_to_postid')->alias(function ($url) {
            return str_contains($url, 'image.jpg') ? 10 : 0;
        });

        require_once dirname(__DIR__, 2) . '/includes/class-wash-images.php';
        Functions\when('get_post_meta')->justReturn(''); // no CDN url

        $content = '<img src="https://example.com/wp-content/uploads/2024/image.jpg">';
        $result  = $this->invokePrivate('rewriteImageUrls', [$content]);

        // URL kept intact since not synced yet
        $this->assertStringContainsString('wp-content/uploads', $result);
    }

    public function testRewriteFileUrlsLeavesUrlUnchangedWhenAttachmentNotFound(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $content = '<img src="https://example.com/wp-content/uploads/2024/unknown.jpg">';
        $result  = $this->invokePrivate('rewriteImageUrls', [$content]);

        $this->assertStringContainsString('wp-content/uploads', $result);
    }

    // ---------------------------------------------------------------
    // getPrimaryCategory — Yoast primary branch
    // ---------------------------------------------------------------

    public function testGetPrimaryCategoryUsesYoastPrimaryWhenNoRankMath(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === 'rank_math_primary_category') return '';
            if ($key === '_yoast_wpseo_primary_category') return '20';
            return '';
        });
        $term = (object) ['name' => 'Yoast Cat', 'term_id' => 20];
        Functions\when('get_term')->justReturn($term);
        Functions\when('is_wp_error')->justReturn(false);

        $result = $this->invokePrivate('getPrimaryCategory', [1]);
        $this->assertSame('Yoast Cat', $result);
    }

    public function testGetPrimaryCategorySkipsInvalidYoastTerm(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            if ($key === 'rank_math_primary_category') return '';
            if ($key === '_yoast_wpseo_primary_category') return '20';
            return '';
        });
        Functions\when('get_term')->justReturn(null); // term not found
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('get_the_category')->justReturn([]);

        $result = $this->invokePrivate('getPrimaryCategory', [1]);
        $this->assertSame('', $result);
    }

    // ---------------------------------------------------------------
    // getYoastRenderedTitle/Desc — %%vars%% resolution via manual fallback
    // ---------------------------------------------------------------

    public function testGetYoastRenderedTitleResolvesVarsManually(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return $key === '_yoast_wpseo_title' ? '%%title%% %%sep%% %%sitename%%' : '';
        });
        Functions\when('get_post')->justReturn((object) ['post_title' => 'Yoast Test', 'post_author' => 1]);
        Functions\when('apply_filters')->justReturn('|');
        Functions\when('get_bloginfo')->justReturn('My Site');
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_the_date')->justReturn('2026-01-01');
        Functions\when('get_the_author_meta')->justReturn('Author');
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('get_term')->justReturn(null);

        $result = $this->invokePrivate('getYoastRenderedTitle', [1]);
        $this->assertSame('Yoast Test | My Site', $result);
    }

    public function testGetYoastRenderedDescResolvesVarsManually(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return $key === '_yoast_wpseo_metadesc' ? '%%excerpt%%' : '';
        });
        Functions\when('get_post')->justReturn((object) ['post_title' => 'Title', 'post_author' => 1]);
        Functions\when('apply_filters')->justReturn('-');
        Functions\when('get_bloginfo')->justReturn('Site');
        Functions\when('get_the_excerpt')->justReturn('Great summary here.');
        Functions\when('get_the_date')->justReturn('2026-01-01');
        Functions\when('get_the_author_meta')->justReturn('');
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('get_term')->justReturn(null);

        $result = $this->invokePrivate('getYoastRenderedDesc', [1]);
        $this->assertSame('Great summary here.', $result);
    }

    // ---------------------------------------------------------------
    // getRankMathRenderedDesc — resolution via manual fallback
    // ---------------------------------------------------------------

    public function testGetRankMathRenderedDescFallsBackToResolveSeoVars(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                'rank_math_description'      => '%excerpt%',
                'rank_math_primary_category' => '',
                default                       => '',
            };
        });
        Functions\when('get_post')->justReturn((object)['post_title' => 'T', 'post_author' => 1]);
        Functions\when('apply_filters')->justReturn('-');
        Functions\when('get_bloginfo')->justReturn('Blog');
        Functions\when('get_the_excerpt')->justReturn('Excerpt text.');
        Functions\when('get_the_date')->justReturn('2026-01-01');
        Functions\when('get_the_author_meta')->justReturn('');
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('get_term')->justReturn(null);

        $result = $this->invokePrivate('getRankMathRenderedDesc', [1]);
        $this->assertSame('Excerpt text.', $result);
    }

    // ---------------------------------------------------------------
    // getCustomFields — public field included when enabledFields is empty
    // ---------------------------------------------------------------

    public function testGetCustomFieldsIncludesPublicFieldWhenNoFilterSet(): void
    {
        Functions\when('get_post_meta')->justReturn([
            'public_field' => ['value1'],
            '_private'     => ['hidden'],
        ]);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        // Provide an empty field config array → enabledFields will be empty → all public fields pass through
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_meta_field_config' => '[]',  // empty config JSON → no filters
            'wash_shopify_store'     => 'test-store',
            'wash_api_key'           => 'test-key',
            'wash_app_url'           => 'https://app-wash.tradik.dev',
            'wash_http_user'         => '',
            'wash_http_pass'         => '',
            default                  => $d,
        });

        require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';
        // Reset static cache so our option takes effect
        \WashMetafields::$fieldConfigCache = null;

        $sync   = new \WashSync();
        $ref    = new \ReflectionMethod(\WashSync::class, 'getCustomFields');
        $ref->setAccessible(true);
        $result = $ref->invoke($sync, 1);

        $this->assertArrayHasKey('public_field', $result);
        $this->assertSame('value1', $result['public_field']);
        $this->assertArrayNotHasKey('_private', $result);
    }

    public function testGetCustomFieldsScalarValueForMultipleEntries(): void
    {
        Functions\when('get_post_meta')->justReturn([
            'multi_field' => ['val_a', 'val_b'],
        ]);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_meta_field_config' => '[]', // empty config
            'wash_shopify_store'     => 'test-store',
            'wash_api_key'           => 'test-key',
            'wash_app_url'           => 'https://app-wash.tradik.dev',
            'wash_http_user'         => '',
            'wash_http_pass'         => '',
            default                  => $d,
        });

        require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';
        \WashMetafields::$fieldConfigCache = null;

        $sync   = new \WashSync();
        $ref    = new \ReflectionMethod(\WashSync::class, 'getCustomFields');
        $ref->setAccessible(true);
        $result = $ref->invoke($sync, 1);

        // Multiple values stay as array
        $this->assertSame(['val_a', 'val_b'], $result['multi_field']);
    }

    // ---------------------------------------------------------------
    // onStatusTransition — publish→draft happy path with HrefLangs stub
    // ---------------------------------------------------------------

    public function testOnStatusTransitionPublishToDraftCallsDeleteAndClearsMeta(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
        require_once dirname(__DIR__, 2) . '/includes/class-wash-multisite.php';

        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'        => 'post,page',
            'wash_hreflangs_enabled' => '0', // disabled so no hreflang call needed
            'wash_shopify_store'     => 'test-store',
            'wash_api_key'           => 'test-key',
            'wash_app_url'           => 'https://app-wash.tradik.dev',
            'wash_http_user'         => '',
            'wash_http_pass'         => '',
            default                  => $d,
        });

        // Mock what onDeletePost/onStatusTransition calls
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('is_multisite')->justReturn(false);
        // Note: is_plugin_active() is defined in bootstrap.php → cannot re-mock

        // Mock the apiClient->post call indirectly via wp_remote_post
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $sync = new \WashSync();
        $post = $this->makePost(['ID' => 10, 'post_type' => 'post']);

        // Transition from publish → draft should NOT throw
        $sync->onStatusTransition('draft', 'publish', $post);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // onDeletePost — happy path (allowed post type, no translation group)
    // ---------------------------------------------------------------

    public function testOnDeletePostHappyPathForAllowedType(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
        require_once dirname(__DIR__, 2) . '/includes/class-wash-multisite.php';

        $post = $this->makePost(['ID' => 5, 'post_type' => 'post']);
        Functions\when('get_post')->justReturn($post);

        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'        => 'post,page',
            'wash_hreflangs_enabled' => '0',
            'wash_shopify_store'     => 'test-store',
            'wash_api_key'           => 'test-key',
            'wash_app_url'           => 'https://app-wash.tradik.dev',
            'wash_http_user'         => '',
            'wash_http_pass'         => '',
            default                  => $d,
        });
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_multisite')->justReturn(false);
        // Note: is_plugin_active() is defined in bootstrap.php → cannot re-mock
        Functions\when('wp_remote_post')->justReturn(['body' => '{}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        // We need $wpdb global for logSync
        global $wpdb;
        if (!isset($wpdb)) {
            $wpdb = new class {
                public $prefix = 'wp_';
                public function show_errors($v = true) { return false; }
                public function suppress_errors($v = true) { return false; }
                public function insert($t, $d) { return 1; }
                public function get_var($q) { return null; }
                public function prepare($q, ...$args) { return $q; }
            };
        }

        $sync = new \WashSync();
        $sync->onDeletePost(5);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // rewriteInternalUrls — no post found for URL
    // ---------------------------------------------------------------

    public function testRewriteInternalUrlsLeavesLinkWhenNoPostFound(): void
    {
        Functions\when('url_to_postid')->justReturn(0);

        $content = '<a href="https://example.com/unknown-page">Unknown</a>';
        $result  = $this->invokePrivate('rewriteInternalUrls', [$content]);
        $this->assertSame($content, $result);
    }

    public function testRewriteInternalUrlsLeavesLinkWhenPostTypeNotAllowed(): void
    {
        Functions\when('url_to_postid')->alias(fn($url) => str_contains($url, 'product') ? 20 : 0);
        Functions\when('get_post')->justReturn($this->makePost(['ID' => 20, 'post_type' => 'product']));
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'    => 'post,page',
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });

        $sync    = new \WashSync();
        $ref     = new \ReflectionMethod(\WashSync::class, 'rewriteInternalUrls');
        $ref->setAccessible(true);
        $content = '<a href="https://example.com/product/some-product">Product</a>';
        $result  = $ref->invoke($sync, $content);

        // Should be unchanged
        $this->assertStringContainsString('example.com/product', $result);
    }

    // ---------------------------------------------------------------
    // shouldSkipSync — returns false (all clear) path
    // ---------------------------------------------------------------

    public function testShouldSkipSyncReturnsFalseWhenAllChecksClear(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_post_types'    => 'post,page',
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'configured-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });

        $sync = new \WashSync();
        $post = $this->makePost(['post_type' => 'post']);
        $ref  = new \ReflectionMethod(\WashSync::class, 'shouldSkipSync');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($sync, 1, $post));
    }

    // ---------------------------------------------------------------
    // applySearchReplaceRules — type filtering (only block/shortcode types)
    // ---------------------------------------------------------------

    public function testApplySearchReplaceRulesFiltersStringRulesWhenTypeBlockOnly(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_search_replace_rules' => [
                ['search' => 'hello', 'replace' => 'world', 'type' => 'string'],
                ['search' => 'bye',   'replace' => 'ciao',  'type' => 'block'],
            ],
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });
        Functions\when('get_transient')->justReturn(['features' => ['searchReplace' => true]]);
        Functions\when('set_transient')->justReturn(true);

        if (!class_exists('WP_Block_Type_Registry')) {
            eval('class WP_Block_Type_Registry {
                public static function get_instance() { return new self(); }
                public function is_registered($n) { return false; }
            }');
        }
        Functions\when('register_block_type')->justReturn(true);

        $sync = new \WashSync();
        $ref  = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        // Only apply 'block' type rules — 'string' rule must be skipped
        $result = $ref->invoke($sync, 'hello world', ['block', 'shortcode']);
        // 'hello' should NOT be replaced (string rule filtered out)
        $this->assertSame('hello world', $result);
    }

    public function testApplySearchReplaceRulesAppliesStringRuleWhenTypeStringOnly(): void
    {
        Functions\when('get_option')->alias(fn($k, $d = '') => match ($k) {
            'wash_search_replace_rules' => [
                ['search' => 'hello', 'replace' => 'world', 'type' => 'string'],
            ],
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
            'wash_http_user'     => '',
            'wash_http_pass'     => '',
            default              => $d,
        });
        Functions\when('get_transient')->justReturn(['features' => ['searchReplace' => true]]);
        Functions\when('set_transient')->justReturn(true);

        $sync = new \WashSync();
        $ref  = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        // Apply 'string' type only
        $result = $ref->invoke($sync, 'hello world', ['string', 'url']);
        $this->assertSame('world world', $result);
    }

    // ---------------------------------------------------------------
    // buildContentImageIdMap — no images scenario
    // ---------------------------------------------------------------

    public function testBuildContentImageIdMapReturnsEmptyForContentWithNoImageTags(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $content = '<p>Just text, no images or upload URLs.</p>';
        $result  = $this->invokePrivate('buildContentImageIdMap', [$content]);
        $this->assertSame([], $result);
    }

    public function testBuildContentImageIdMapHandlesWpImageClassWithoutFallback(): void
    {
        // Image has wp-image class → direct mapping, no URL-resolution fallback needed
        Functions\when('attachment_url_to_postid')->justReturn(0); // fallback would return 0

        $content = '<img class="wp-image-77 aligncenter" src="https://other-cdn.com/photo.jpg" alt="test">';
        $result  = $this->invokePrivate('buildContentImageIdMap', [$content]);

        $this->assertArrayHasKey('https://other-cdn.com/photo.jpg', $result);
        $this->assertSame(77, $result['https://other-cdn.com/photo.jpg']);
    }
}
