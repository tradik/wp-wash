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
        // hasFeature() calls getPlanInfo() which needs get_transient
        Functions\when('get_transient')->justReturn(['features' => ['searchReplace' => true]]);
        Functions\when('set_transient')->justReturn(true);

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

    public function testUrlRuleReplacesHrefAndAnchorText(): void
    {
        $this->rules = [
            ['search' => 'hilo1316.e.wpstage.net', 'replace' => 'hilo.com', 'type' => 'url'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $content = '<a href="https://hilo1316.e.wpstage.net/uk/art/dash-diet/">https://hilo1316.e.wpstage.net/uk/art/dash-diet/</a>';
        $expected = '<a href="https://hilo.com/uk/art/dash-diet/">https://hilo.com/uk/art/dash-diet/</a>';
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testUrlRuleReplacesTextInReferencesSection(): void
    {
        $this->rules = [
            ['search' => 'hilo1316.e.wpstage.net', 'replace' => 'hilo.com', 'type' => 'url'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $content = '<p>Hilo. <a href="https://hilo1316.e.wpstage.net/blood-pressure-monitor/">https://hilo1316.e.wpstage.net/blood-pressure-monitor/</a></p>';
        $expected = '<p>Hilo. <a href="https://hilo.com/blood-pressure-monitor/">https://hilo.com/blood-pressure-monitor/</a></p>';
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testUrlRuleReplacesInSrcAndCssUrl(): void
    {
        $this->rules = [
            ['search' => 'old-cdn.example.com', 'replace' => 'new-cdn.example.com', 'type' => 'url'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $content = '<img src="https://old-cdn.example.com/img.jpg" /><div style="background: url(\'https://old-cdn.example.com/bg.png\')"></div>';
        $expected = '<img src="https://new-cdn.example.com/img.jpg" /><div style="background: url(\'https://new-cdn.example.com/bg.png\')"></div>';
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testUrlRuleDoesNotDoubleReplaceInHref(): void
    {
        $this->rules = [
            ['search' => 'staging.example.com', 'replace' => 'example.com', 'type' => 'url'],
        ];

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        // href gets replaced in step 1, text gets replaced in step 3
        // Neither should produce double replacement
        $content = '<a href="https://staging.example.com/page">Visit staging.example.com</a>';
        $expected = '<a href="https://example.com/page">Visit example.com</a>';
        $this->assertSame($expected, $ref->invoke($sync, $content));
    }

    public function testSearchReplaceSkippedWhenPlanLacksFeature(): void
    {
        $this->rules = [
            ['search' => 'old', 'replace' => 'new'],
        ];

        // Override get_transient to return plan without searchReplace
        Functions\when('get_transient')->justReturn(['features' => ['searchReplace' => false]]);

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        // Rules should NOT be applied — content returned unchanged
        $this->assertSame('old text', $ref->invoke($sync, 'old text'));
    }

    public function testSearchReplaceAppliedWhenPlanHasFeature(): void
    {
        $this->rules = [
            ['search' => 'old', 'replace' => 'new'],
        ];

        // Plan has searchReplace feature
        Functions\when('get_transient')->justReturn(['features' => ['searchReplace' => true]]);

        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'applySearchReplaceRules');
        $ref->setAccessible(true);

        $this->assertSame('new text', $ref->invoke($sync, 'old text'));
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
