<?php
/** Unit tests for WashSync::applySearchReplaceRules. */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class WashSearchReplaceTest extends TestCase
{
    private $sync;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'test-store',
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                'wash_search_replace_rules' => $this->rules ?? [],
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $this->rules = [];
        $this->sync = new \WashSync();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function applyRules(string $content): string
    {
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);
        return $ref->invoke($this->sync, $content);
    }

    // -- applySearchReplaceRules --

    public function testReturnsContentUnchangedWhenNoRules(): void
    {
        $this->rules = [];
        $content = '<p>Hello world</p>';
        $this->assertSame($content, $this->applyRules($content));
    }

    public function testReturnsContentUnchangedWhenRulesEmpty(): void
    {
        $this->rules = [];
        $this->assertSame('foo', $this->applyRules('foo'));
    }

    public function testReturnsContentUnchangedWhenOptionNotArray(): void
    {
        $this->rules = '';
        $this->assertSame('foo', $this->applyRules('foo'));
    }

    public function testSimpleTextReplacement(): void
    {
        $this->rules = [
            ['search' => 'old-domain.com', 'replace' => 'new-domain.com'],
        ];
        $content = '<a href="https://old-domain.com/page">Link</a>';
        $expected = '<a href="https://new-domain.com/page">Link</a>';

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testShortcodeToHtmlReplacement(): void
    {
        $this->rules = [
            ['search' => '[[newsletter]]', 'replace' => '<div class="klaviyo-form-abc123"></div>'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $content = '<p>Subscribe: [[newsletter]]</p>';
        $expected = '<p>Subscribe: <div class="klaviyo-form-abc123"></div></p>';
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testMultipleRulesAppliedInOrder(): void
    {
        $this->rules = [
            ['search' => 'AAA', 'replace' => 'BBB'],
            ['search' => 'BBB', 'replace' => 'CCC'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        // AAA -> BBB (rule 1), then BBB -> CCC (rule 2)
        $this->assertSame('CCC', $ref->invoke($sync, 'AAA'));
    }

    public function testEmptyReplaceRemovesText(): void
    {
        $this->rules = [
            ['search' => ' target="_blank"', 'replace' => ''],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $content = '<a href="/page" target="_blank">Link</a>';
        $expected = '<a href="/page">Link</a>';
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testSkipsRulesWithEmptySearch(): void
    {
        $this->rules = [
            ['search' => '', 'replace' => 'should-not-appear'],
            ['search' => 'hello', 'replace' => 'world'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $this->assertSame('world', $ref->invoke($sync, 'hello'));
    }

    public function testMultipleOccurrencesReplaced(): void
    {
        $this->rules = [
            ['search' => 'foo', 'replace' => 'bar'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $this->assertSame('bar bar bar', $ref->invoke($sync, 'foo foo foo'));
    }

    public function testCaseSensitiveMatching(): void
    {
        $this->rules = [
            ['search' => 'Hello', 'replace' => 'Hi'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        // Only "Hello" should be replaced, not "hello"
        $this->assertSame('Hi hello', $ref->invoke($sync, 'Hello hello'));
    }

    public function testHtmlContentReplacement(): void
    {
        $this->rules = [
            ['search' => '<div class="wp-block">', 'replace' => '<div class="shopify-block">'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $content = '<div class="wp-block"><p>Content</p></div>';
        $expected = '<div class="shopify-block"><p>Content</p></div>';
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testHandlesMalformedRuleMissingKeys(): void
    {
        $this->rules = [
            ['search' => 'yes'],  // missing 'replace'
            ['replace' => 'no'],  // missing 'search'
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        // Rule 1: search=yes, replace='' (default) → 'yes' removed
        // Rule 2: search='' (default) → skipped
        $this->assertSame('hello ', $ref->invoke($sync, 'hello yes'));
    }
}
