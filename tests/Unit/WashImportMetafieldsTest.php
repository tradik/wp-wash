<?php
/**
 * Tests for WashImportMetafields — importing Shopify metafields as WP post meta.
 * Covers namespace→key mapping, JSON decoding, and skipping protected keys.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-import-metafields.php';

class WashImportMetafieldsTest extends TestCase
{
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->meta = [];
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[(int) $id][$key] = $value;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function apply(array $metafields): void
    {
        (new \WashImportMetafields())->apply(7, ['metafields' => $metafields]);
    }

    public function testWashNamespaceMapsToBareKey(): void
    {
        $this->apply([['namespace' => 'wash', 'key' => 'subtitle', 'value' => 'Hi', 'type' => 'single_line_text_field']]);
        $this->assertSame('Hi', $this->meta[7]['subtitle']);
    }

    public function testForeignNamespaceIsPrefixed(): void
    {
        $this->apply([['namespace' => 'custom_app', 'key' => 'rating', 'value' => '5', 'type' => 'number_integer']]);
        $this->assertSame('5', $this->meta[7]['custom_app_rating']);
    }

    public function testCustomNamespaceIsPrefixedNotClobbering(): void
    {
        // custom.rank_math_title must NOT land on RankMath's bare `rank_math_title` meta.
        $this->apply([['namespace' => 'custom', 'key' => 'rank_math_title', 'value' => 'Hi', 'type' => 'single_line_text_field']]);
        $this->assertSame('Hi', $this->meta[7]['custom_rank_math_title']);
        $this->assertArrayNotHasKey('rank_math_title', $this->meta[7]);
    }

    public function testUnrelatedWashPrefixNamespaceIsNotStripped(): void
    {
        // `washington` must not be mistaken for the wash namespace.
        $this->apply([['namespace' => 'washington', 'key' => 'k', 'value' => 'v', 'type' => 'single_line_text_field']]);
        $this->assertSame('v', $this->meta[7]['washington_k']);
        $this->assertArrayNotHasKey('k', $this->meta[7]);
    }

    public function testJsonValueIsDecoded(): void
    {
        $this->apply([['namespace' => 'wash', 'key' => 'data', 'value' => '{"a":1}', 'type' => 'json']]);
        $this->assertSame(['a' => 1], $this->meta[7]['data']);
    }

    public function testJsonLookingTextFieldIsNotDecoded(): void
    {
        // A single_line_text_field whose value is coincidentally valid JSON stays a string.
        $this->apply([['namespace' => 'wash', 'key' => 'tags', 'value' => '["a","b"]', 'type' => 'single_line_text_field']]);
        $this->assertSame('["a","b"]', $this->meta[7]['tags']);
    }

    public function testProtectedKeyIsSkipped(): void
    {
        Functions\expect('update_post_meta')->never();
        $this->apply([['namespace' => '', 'key' => '_edit_lock', 'value' => 'x', 'type' => 'single_line_text_field']]);
        $this->addToAssertionCount(1);
    }

    public function testEmptyKeyIsSkipped(): void
    {
        Functions\expect('update_post_meta')->never();
        $this->apply([['namespace' => 'wash', 'key' => '', 'value' => 'x']]);
        $this->addToAssertionCount(1);
    }
}
