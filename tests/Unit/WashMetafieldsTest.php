<?php
/**
 * Unit tests for WashMetafields class.
 * Tests: generateDefinitionName, sanitizeKey, shouldSkipMeta,
 * getFieldConfig, getEnabledFieldSet, isFieldEnabled, saveFieldConfig.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';

class WashMetafieldsTest extends TestCase
{
    private $metafields;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // Reset per-connection static state so a memoized WashContext client/id
        // can't leak between tests (PHPUnit runs in execution-order, not file order).
        \WashContext::setActiveId(null);

        // Reset static cache between tests
        \WashMetafields::$fieldConfigCache = null;

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $this->metafields = new \WashMetafields();
    }

    protected function tearDown(): void
    {
        \WashMetafields::$fieldConfigCache = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: invoke a private method on WashMetafields via Reflection.
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashMetafields::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->metafields, ...$args);
    }

    // -- generateDefinitionName --

    public function testGenerateDefinitionNameConvertsUnderscoresToSpaces(): void
    {
        $this->assertSame('Wp Post Id', $this->invokePrivate('generateDefinitionName', ['wp_post_id']));
    }

    public function testGenerateDefinitionNameSingleWord(): void
    {
        $this->assertSame('Permalink', $this->invokePrivate('generateDefinitionName', ['permalink']));
    }

    // -- sanitizeKey --

    public function testSanitizeKeyRemovesSpecialCharsAndLowercases(): void
    {
        // Trailing `_` is now stripped to match the queue-worker's
        // sanitizeMetafieldKey (LOW-17, #178).
        $this->assertSame('my_field_name', $this->invokePrivate('sanitizeKey', ['My-Field.Name!']));
    }

    public function testSanitizeKeyTrimsLeadingAndTrailingUnderscores(): void
    {
        $this->assertSame('hidden_key', $this->invokePrivate('sanitizeKey', ['__hidden_key__']));
    }

    public function testSanitizeKeyTruncatesTo64Chars(): void
    {
        $this->assertSame(64, strlen($this->invokePrivate('sanitizeKey', [str_repeat('a', 100)])));
    }

    public function testSanitizeKeyCollapsesMultipleUnderscores(): void
    {
        $this->assertSame('some_key', $this->invokePrivate('sanitizeKey', ['some___key']));
    }

    // -- shouldSkipMeta --

    public function testShouldSkipMetaReturnsTrueForWashPrefix(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_wash_article_id']));
    }

    public function testShouldSkipMetaReturnsTrueForWpPrefix(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_wp_old_date']));
    }

    public function testShouldSkipMetaReturnsTrueForEditPrefix(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_edit_lock']));
    }

    public function testShouldSkipMetaReturnsTrueForExactThumbnailId(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_thumbnail_id']));
    }

    public function testShouldSkipMetaReturnsFalseForValidKey(): void
    {
        $this->assertFalse($this->invokePrivate('shouldSkipMeta', ['custom_field']));
    }

    // -- getFieldConfig --

    public function testGetFieldConfigReturnsCachedValue(): void
    {
        $cached = [['namespace' => 'wash', 'key' => 'test', 'syncEnabled' => true]];
        \WashMetafields::$fieldConfigCache = $cached;
        $this->assertSame($cached, $this->metafields->getFieldConfig());
    }

    public function testGetFieldConfigParsesJsonFromOption(): void
    {
        $config = [['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => true]];
        Functions\when('get_option')->alias(function ($key, $default = '') use ($config) {
            if ($key === 'wash_meta_field_config') return json_encode($config);
            return match ($key) {
                'wash_api_key' => 'test-key', 'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '', 'wash_http_pass' => '', default => $default,
            };
        });
        $this->assertSame($config, (new \WashMetafields())->getFieldConfig());
    }

    // -- getEnabledFieldSet / isFieldEnabled --

    public function testGetEnabledFieldSetBuildsLookupMap(): void
    {
        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => true],
            ['namespace' => 'wash_acf', 'key' => 'color', 'syncEnabled' => false],
            ['namespace' => 'wash_seo', 'key' => 'meta_title', 'syncEnabled' => true],
        ];
        $result = $this->invokePrivate('getEnabledFieldSet', []);
        $this->assertArrayHasKey('wash.wp_post_id', $result);
        $this->assertArrayHasKey('wash_seo.meta_title', $result);
        $this->assertArrayNotHasKey('wash_acf.color', $result);
    }

    public function testIsFieldEnabledReturnsTrueForEnabled(): void
    {
        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => true],
        ];
        $this->assertTrue($this->invokePrivate('isFieldEnabled', ['wash', 'wp_post_id']));
    }

    public function testIsFieldEnabledReturnsFalseForDisabled(): void
    {
        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => false],
        ];
        $this->assertFalse($this->invokePrivate('isFieldEnabled', ['wash', 'wp_post_id']));
    }

    // -- saveFieldConfig --

    public function testSaveFieldConfigEncodesAndSavesToOption(): void
    {
        $config = [['namespace' => 'wash', 'key' => 'test', 'syncEnabled' => true]];
        Functions\expect('update_option')->once()->with('wash_meta_field_config', \Mockery::type('string'));
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->metafields->saveFieldConfig($config);
        $this->assertSame($config, \WashMetafields::$fieldConfigCache);
    }
}
