<?php
/**
 * WashSync branches that depend on process-wide state which cannot be undone
 * once set: the FAQ JSON-LD json_encode() fallback (needs wp_json_encode() to
 * NOT exist) and the DOING_AUTOSAVE constant guard in shouldSkipSync(). Each
 * test therefore runs in its own fresh process.
 */

namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class WashSyncIsolatedBranchesTest extends TestCase
{
    /** @var bool Whether this test booted Brain Monkey (and must tear it down). */
    private bool $monkey = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
        require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
    }

    protected function tearDown(): void
    {
        if ($this->monkey) {
            Monkey\tearDown();
        }
        parent::tearDown();
    }

    private function invokePrivate(string $method, array $args)
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        return $ref->invoke(new \WashSync(), ...$args);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFaqSchemaFallsBackToJsonEncode(): void
    {
        // Brain Monkey's setUp() would load its own wp_json_encode(), so this test
        // skips it and declares the two WP helpers renderFaqAccordion() needs.
        eval('function esc_attr($text) { return $text; }');
        eval('function wp_strip_all_tags($text) { return strip_tags((string) $text); }');

        $html = $this->invokePrivate('renderFaqAccordion', [[['question' => 'Why?', 'answer' => '<p>Because/so</p>']]]);

        $this->assertFalse(function_exists('wp_json_encode'));
        $this->assertStringContainsString('"text":"Because/so"', $html);
        $this->assertStringContainsString('<summary class="wash-faq__question"', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDoingAutosaveConstantSkipsSync(): void
    {
        Monkey\setUp();
        $this->monkey = true;
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        define('DOING_AUTOSAVE', true);

        $post = (object) ['ID' => 7, 'post_type' => 'post'];
        $this->assertTrue($this->invokePrivate('shouldSkipSync', [7, $post]));
    }
}
