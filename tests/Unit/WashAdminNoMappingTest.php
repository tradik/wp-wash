<?php
/**
 * Tests for the #255 WashAdmin additions:
 * - renderShopifyColumn() "No mapping" badge for a published post whose language
 *   matches no blog-mapping rule.
 * - bulk skip-reason encode/decode (silent-skip fix).
 * - handleBulkActions() skipping a publish with no mapping, surfacing the reason.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-blog-router.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

class WashAdminNoMappingTest extends TestCase
{
    private \WashAdmin $admin;
    private array $options = [];
    private array $meta = [];
    private array $posts = [];
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [
            'wash_shopify_store' => 'test-store',
            'wash_api_key' => 'k', 'wash_app_url' => 'https://app.example.com',
            'wash_http_user' => '', 'wash_http_pass' => '', 'wash_post_types' => 'post,page',
            'wash_sync_inline' => '1',
        ];
        $this->meta = [];
        $this->posts = [];
        $this->transients = [];
        $_GET = [];
        $_POST = [];
        \WashContext::setActiveId(null);

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }
        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('get_post_meta')->alias(fn($id, $k, $s = true) => $this->meta[(int) $id][$k] ?? '');
        Functions\when('get_post')->alias(fn($id) => $this->posts[(int) $id] ?? null);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('date_i18n')->justReturn('2026-01-01');
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            $sep = strpos((string) $url, '?') === false ? '?' : '&';
            return $url . $sep . http_build_query($args);
        });

        // Clear the router's static memo AFTER delete_transient is mocked, so each
        // test sees its own rules transient (the memo survives between tests).
        \WashBlogRouter::flushCache();

        $this->admin = new \WashAdmin();
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function setRules(array $rules): void
    {
        $this->transients['wash_blog_mapping_rules'] = $rules;
    }

    private function langRule(string $value): array
    {
        return [
            'ruleType' => 'language', 'ruleValue' => $value, 'shopifyLocale' => substr($value, 0, 2),
            'shopifyMarketId' => null, 'shopifyBlogHandle' => 'articles', 'destination' => 'article',
            'priority' => 10, 'isActive' => true, 'createdAt' => '2026-01-01',
        ];
    }

    private function makePost(int $id, string $lang, string $status = 'publish'): void
    {
        $this->posts[$id] = (object) ['ID' => $id, 'post_type' => 'post', 'post_status' => $status, 'post_name' => 'slug-' . $id];
        $this->meta[$id] = ['_wash_language' => $lang];
    }

    private function invoke(string $method, array $args)
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($ref->isStatic() ? null : $this->admin, ...$args);
    }

    // -- No-mapping column badge -------------------------------------------

    public function testRenderShopifyColumnShowsNoMappingBadgeForUnmappedLanguage(): void
    {
        $this->setRules([$this->langRule('de-ch')]);
        $this->makePost(50, 'pl-pl'); // pl-pl matches no rule, not synced

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 50);
        $html = ob_get_clean();

        $this->assertStringContainsString('No mapping', $html);
        $this->assertStringNotContainsString('dashicons-yes-alt', $html);
    }

    public function testRenderShopifyColumnPlainNotSyncedWhenMappingExists(): void
    {
        $this->setRules([$this->langRule('de-ch')]);
        $this->makePost(51, 'de-ch'); // matches → no "No mapping" badge

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 51);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('No mapping', $html);
    }

    // -- skip-reason encode/decode -----------------------------------------

    public function testEncodeSkipDropsZeroReasons(): void
    {
        $this->assertSame(
            'unsynced-2.nomapping-1',
            $this->invoke('encodeSkip', [['unsynced' => 2, 'notpublished' => 0, 'nomapping' => 1, 'type' => 0]])
        );
    }

    public function testDecodeSkipReasonsToHuman(): void
    {
        $human = $this->invoke('decodeSkipReasons', ['unsynced-2.nomapping-1']);
        $this->assertStringContainsString('2 not synced to Shopify', $human);
        $this->assertStringContainsString('1 no blog-mapping rule', $human);
    }

    public function testDecodeSkipReasonsEmpty(): void
    {
        $this->assertSame('', $this->invoke('decodeSkipReasons', ['']));
    }

    // -- bulk publish surfaces the no-mapping skip -------------------------

    public function testBulkPublishSkipsNoMappingAndReportsReason(): void
    {
        $this->setRules([$this->langRule('de-ch')]);
        $this->makePost(60, 'pl-pl'); // published, not synced, no mapping

        $redirect = $this->admin->handleBulkActions('https://example.com/edit.php', 'wash_publish', [60]);

        $this->assertStringContainsString('wash_bulk_processed=0', $redirect);
        $this->assertStringContainsString('wash_bulk_skip=nomapping-1', $redirect);
    }
}
