<?php
/** Unit tests for WashSync::transformRankMathFaq() and its helpers. */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class WashFaqTransformTest extends TestCase
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
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('esc_attr')->alias(fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        Functions\when('wp_json_encode')->alias(fn ($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Functions\when('wp_strip_all_tags')->alias(fn ($v) => trim(strip_tags((string) $v)));

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

    /** Real RankMath FAQ markup as rendered on oldsite.hilo.com. */
    private function rankMathBlock(): string
    {
        return <<<HTML
<div id="rank-math-faq" class="rank-math-block">
<div class="rank-math-list ">
<div id="faq-question-1" class="rank-math-list-item">
<h3 class="rank-math-question ">Is cannabis good for high blood pressure?</h3>
<div class="rank-math-answer ">
<p>Cannabis is <strong>not</strong> a proven therapy.</p>
</div>
</div>
<div id="faq-question-2" class="rank-math-list-item">
<h3 class="rank-math-question ">What can I do to reduce my blood pressure?</h3>
<div class="rank-math-answer ">
<p>Rest, deep breathing &amp; exercise.</p>
</div>
</div>
</div>
</div>
HTML;
    }

    public function testReturnsContentUnchangedWhenNoFaqBlock(): void
    {
        $content = '<p>Just a normal article body.</p>';
        $this->assertSame($content, $this->invokePrivate('transformRankMathFaq', [$content]));
    }

    public function testTransformsRankMathBlockIntoDetailsAccordion(): void
    {
        $out = $this->invokePrivate('transformRankMathFaq', [$this->rankMathBlock()]);

        // Shopify-safe markup is emitted...
        $this->assertStringContainsString('<div class="wash-faq"', $out);
        $this->assertSame(2, substr_count($out, '<details'));
        $this->assertSame(2, substr_count($out, '<summary'));
        $this->assertStringContainsString('Is cannabis good for high blood pressure?', $out);
        $this->assertStringContainsString('<strong>not</strong>', $out);

        // ...and the RankMath wrappers are gone.
        $this->assertStringNotContainsString('rank-math-block', $out);
        $this->assertStringNotContainsString('rank-math-list-item', $out);
    }

    public function testEmitsFaqPageJsonLd(): void
    {
        $out = $this->invokePrivate('transformRankMathFaq', [$this->rankMathBlock()]);

        $this->assertStringContainsString('application/ld+json', $out);

        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $out, $m);
        $this->assertNotEmpty($m[1] ?? '', 'JSON-LD script must be present');

        $schema = json_decode($m[1], true);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertCount(2, $schema['mainEntity']);
        $this->assertSame('Question', $schema['mainEntity'][0]['@type']);
        $this->assertSame('Is cannabis good for high blood pressure?', $schema['mainEntity'][0]['name']);
        // Answer text is plain (tags + entities stripped/decoded).
        $this->assertSame('Cannabis is not a proven therapy.', $schema['mainEntity'][0]['acceptedAnswer']['text']);
        $this->assertSame('Rest, deep breathing & exercise.', $schema['mainEntity'][1]['acceptedAnswer']['text']);
    }

    public function testLeavesBlockUntouchedWhenNoItemsParsable(): void
    {
        // Has the marker class but no valid question/answer structure.
        $content = '<div id="rank-math-faq" class="rank-math-block"><div class="rank-math-list "><p>broken</p></div></div>';
        $out = $this->invokePrivate('transformRankMathFaq', [$content]);
        $this->assertSame($content, $out);
    }

    public function testExtractRankMathFaqItemsParsesPairs(): void
    {
        $items = $this->invokePrivate('extractRankMathFaqItems', [$this->rankMathBlock()]);
        $this->assertCount(2, $items);
        $this->assertSame('Is cannabis good for high blood pressure?', $items[0]['question']);
        $this->assertStringContainsString('not', $items[0]['answer']);
    }

    public function testFaqPlainTextStripsTagsAndCollapsesWhitespace(): void
    {
        $out = $this->invokePrivate('faqPlainText', ["<p>Hello   <strong>world</strong>\n&amp; more</p>"]);
        $this->assertSame('Hello world & more', $out);
    }

    public function testSkipsItemWithEmptyQuestion(): void
    {
        $block = <<<HTML
<div id="rank-math-faq" class="rank-math-block">
<div class="rank-math-list ">
<div id="faq-question-1" class="rank-math-list-item">
<h3 class="rank-math-question "></h3>
<div class="rank-math-answer "><p>Orphan answer</p></div>
</div>
<div id="faq-question-2" class="rank-math-list-item">
<h3 class="rank-math-question ">Real question?</h3>
<div class="rank-math-answer "><p>Real answer</p></div>
</div>
</div>
</div>
HTML;
        $items = $this->invokePrivate('extractRankMathFaqItems', [$block]);
        $this->assertCount(1, $items);
        $this->assertSame('Real question?', $items[0]['question']);
    }
}
