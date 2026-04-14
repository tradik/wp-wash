<?php
/**
 * Contract tests: validates WashSync::buildPostData() output
 * matches the Go API contentRequest struct shape.
 */
namespace Wash\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class ContentSyncContractTest extends TestCase
{
    private $sync;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'test-store',
                'wash_api_key' => 'test-key-abc123',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                'wash_sync_on_publish' => '1',
                'wash_files_enabled' => '0',
                'wash_rewrite_internal_urls' => '0',
                'wash_sync_blocks_meta' => '0',
                'wash_sync_blocks_css' => '0',
                'wash_sync_metafields' => '0',
                'wash_hreflangs_enabled' => '0',
                default => $default,
            };
        });

        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('wp_trim_words')->alias(fn($text) => substr($text, 0, 100));
        Functions\when('get_the_date')->justReturn('2026-01-15T10:00:00+00:00');
        Functions\when('apply_filters')->alias(fn($tag, $value) => $value);
        Functions\when('get_bloginfo')->justReturn('Test Blog');
        Functions\when('get_permalink')->justReturn('https://example.com/test-post/');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);

        $this->sync = new \WashSync();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->sync, ...$args);
    }

    private function createMockPost(array $overrides = []): object
    {
        return (object) array_merge([
            'ID' => 42,
            'post_title' => 'Test Article Title',
            'post_content' => '<p>Test content body</p>',
            'post_excerpt' => 'Test excerpt',
            'post_name' => 'test-article-title',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ], $overrides);
    }

    private function buildTestPostData(string $action = 'create'): array
    {
        $post = $this->createMockPost();

        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_post_tags')->justReturn([]);
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('get_the_author_meta')->justReturn('Test Author');

        return $this->invokePrivate('buildPostData', [42, $post, $action]);
    }

    /**
     * @test Contract: buildPostData contains required fields for Go API.
     *
     * Go struct contentRequest requires: action, title, content, slug, wpPostId.
     */
    public function testRequiredFieldsPresent(): void
    {
        $data = $this->buildTestPostData();

        $requiredFields = ['action', 'title', 'content', 'slug', 'wpPostId'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $data, "Missing required field: {$field}");
        }
    }

    /**
     * @test Contract: action values match Go API expectations.
     */
    public function testActionValuesValid(): void
    {
        $validActions = ['create', 'update', 'delete'];

        foreach ($validActions as $action) {
            $data = $this->buildTestPostData($action);
            $this->assertContains($data['action'], $validActions);
        }
    }

    /**
     * @test Contract: wpPostId is integer (Go: int64).
     */
    public function testWpPostIdIsInteger(): void
    {
        $data = $this->buildTestPostData();
        $this->assertIsInt($data['wpPostId']);
    }

    /**
     * @test Contract: tags field is array of strings (Go: []string).
     */
    public function testTagsIsArrayOfStrings(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_post_tags')->justReturn([
            (object) ['name' => 'Tag One'],
            (object) ['name' => 'Tag Two'],
        ]);
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('get_the_author_meta')->justReturn('Author');

        $post = $this->createMockPost();
        $data = $this->invokePrivate('buildPostData', [42, $post, 'create']);

        $this->assertIsArray($data['tags']);
        foreach ($data['tags'] as $tag) {
            $this->assertIsString($tag);
        }
    }

    /**
     * @test Contract: title, content, slug are strings.
     */
    public function testStringFieldTypes(): void
    {
        $data = $this->buildTestPostData();

        $this->assertIsString($data['title']);
        $this->assertIsString($data['content']);
        $this->assertIsString($data['slug']);
    }

    /**
     * @test Contract: seo sub-object matches Go seoData struct
     * (metaTitle, metaDescription).
     */
    public function testSeoFieldShape(): void
    {
        $data = $this->buildTestPostData();

        // seo should be an array (even if empty)
        $this->assertArrayHasKey('seo', $data);

        if (!empty($data['seo'])) {
            $seo = $data['seo'];
            // Go API expects metaTitle and metaDescription
            if (isset($seo['metaTitle'])) {
                $this->assertIsString($seo['metaTitle']);
            }
            if (isset($seo['metaDescription'])) {
                $this->assertIsString($seo['metaDescription']);
            }
        }
    }

    /**
     * @test Contract: publishedAt is a date string.
     */
    public function testPublishedAtIsDateString(): void
    {
        $data = $this->buildTestPostData();

        $this->assertArrayHasKey('publishedAt', $data);
        $this->assertIsString($data['publishedAt']);
        $this->assertNotEmpty($data['publishedAt']);
    }

    /**
     * @test Contract: additional fields present for extended sync.
     */
    public function testExtendedFieldsPresent(): void
    {
        $data = $this->buildTestPostData();

        $extendedFields = [
            'wpSiteId', 'excerpt', 'status', 'author',
            'language', 'categories', 'wpPermalink', 'wpBlogName',
        ];

        foreach ($extendedFields as $field) {
            $this->assertArrayHasKey($field, $data, "Missing extended field: {$field}");
        }
    }

    /**
     * @test Contract: field values are correctly mapped from WP_Post.
     */
    public function testFieldValueMapping(): void
    {
        $data = $this->buildTestPostData();

        $this->assertEquals('create', $data['action']);
        $this->assertEquals(42, $data['wpPostId']);
        $this->assertEquals('Test Article Title', $data['title']);
        $this->assertEquals('test-article-title', $data['slug']);
        $this->assertEquals('published', $data['status']);
    }
}
