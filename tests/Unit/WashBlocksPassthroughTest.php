<?php
/**
 * Covers WashBlocks::registerSearchReplaceEntities() and pins the raw
 * passthrough contract: the auto-registered shortcode/block callback must
 * return its inner content verbatim (its output is rendered into the body
 * synced to Shopify, so escaping it would corrupt outbound content).
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-blocks.php';

class WashBlocksPassthroughTest extends TestCase
{
    private \WashBlocks $blocks;
    private array $rules = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_search_replace_rules' => $this->rules,
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $this->blocks = new \WashBlocks();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function register(): void
    {
        $ref = new \ReflectionMethod(\WashBlocks::class, 'registerSearchReplaceEntities');
        $ref->setAccessible(true);
        $ref->invoke($this->blocks);
    }

    public function testReturnsEarlyWhenNoRules(): void
    {
        $this->rules = [];
        Functions\expect('add_shortcode')->never();
        $this->register();
        $this->addToAssertionCount(1);
    }

    public function testRegistersPassthroughShortcodeThatReturnsContentVerbatim(): void
    {
        $this->rules = [['type' => 'shortcode', 'search' => '[newsletter]']];

        $captured = null;
        Functions\when('shortcode_exists')->justReturn(false);
        Functions\when('add_shortcode')->alias(function ($tag, $cb) use (&$captured) {
            $captured = $cb;
        });

        $this->register();

        $this->assertIsCallable($captured, 'a passthrough shortcode must be registered');
        // Rich HTML incl. a <script> must survive untouched — escaping here would
        // corrupt the body synced to Shopify.
        $html = '<strong>x</strong> <a href="https://y.test">y</a> <script>z()</script>';
        $this->assertSame($html, $captured([], $html));
        $this->assertSame('', $captured([], null));
    }

    public function testSkipsShortcodeRuleWhenAlreadyRegistered(): void
    {
        $this->rules = [['type' => 'shortcode', 'search' => '[existing]']];
        Functions\when('shortcode_exists')->justReturn(true);
        Functions\expect('add_shortcode')->never();
        $this->register();
        $this->addToAssertionCount(1);
    }
}
