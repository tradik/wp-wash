<?php
/**
 * Tests for WashContext — the active-connection accessor (#216).
 *
 * Decisive case: for `primary` every key resolver returns the historical
 * unprefixed name (zero-migration); additional connections get a namespace.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';

class WashContextTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        \WashContext::setActiveId(null);
        $_POST = [];
        $_GET  = [];
        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->options[$key]);
            return true;
        });
        Functions\when('sanitize_key')->alias(fn($k) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)));
        Functions\when('wp_unslash')->returnArg();
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        $_POST = [];
        $_GET  = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Register a real non-primary connection so activeId() will accept its id. */
    private function registerConnection(string $id): void
    {
        $this->options['wash_connections'] = [
            $id => ['id' => $id, 'store' => 's', 'apiKey' => 'k', 'enabled' => true, 'isPrimary' => false],
        ];
    }

    public function testDefaultsToPrimary(): void
    {
        $this->assertSame('primary', \WashContext::activeId());
    }

    public function testPrimaryKeysAreUnprefixed(): void
    {
        \WashContext::setActiveId('primary');
        $this->assertSame('wash_post_types', \WashContext::optionKey('wash_post_types'));
        $this->assertSame('_wash_article_id', \WashContext::metaKey('_wash_article_id'));
        $this->assertSame('wash_plan_info', \WashContext::transientKey('wash_plan_info'));
    }

    public function testNonPrimaryKeysAreNamespaced(): void
    {
        \WashContext::setActiveId('x1');
        $this->assertSame('wash_c_x1_wash_post_types', \WashContext::optionKey('wash_post_types'));
        $this->assertSame('_wash_article_id_x1', \WashContext::metaKey('_wash_article_id'));
        $this->assertSame('wash_plan_info_x1', \WashContext::transientKey('wash_plan_info'));
    }

    public function testActiveIdResolvesFromPageSlug(): void
    {
        $this->registerConnection('x1');
        $_GET['page'] = 'wash-conn-x1';
        $this->assertSame('x1', \WashContext::activeId());
    }

    public function testActiveIdResolvesFromPostRouting(): void
    {
        $this->registerConnection('x1');
        $_POST['washConnectionId'] = 'x1';
        $this->assertSame('x1', \WashContext::activeId());
    }

    public function testPostRoutingWinsOverPageSlug(): void
    {
        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'store' => 's', 'apiKey' => 'k', 'enabled' => true, 'isPrimary' => false],
            'x2' => ['id' => 'x2', 'store' => 's', 'apiKey' => 'k', 'enabled' => true, 'isPrimary' => false],
        ];
        $_POST['washConnectionId'] = 'x1';
        $_GET['page']             = 'wash-conn-x2';
        $this->assertSame('x1', \WashContext::activeId());
    }

    public function testUnknownConnectionFallsBackToPrimary(): void
    {
        $_GET['page'] = 'wash-conn-ghost';
        $this->assertSame('primary', \WashContext::activeId());
    }

    public function testActiveIdIsMemoizedUntilReset(): void
    {
        $this->registerConnection('x1');
        $_GET['page'] = 'wash-conn-x1';
        $this->assertSame('x1', \WashContext::activeId());

        // Changing the request without resetting does not change the memoized id.
        $_GET['page'] = 'wash-conn-ghost';
        $this->assertSame('x1', \WashContext::activeId());

        \WashContext::setActiveId(null);
        $this->assertSame('primary', \WashContext::activeId());
    }

    public function testOptionHelpersRouteThroughActiveConnection(): void
    {
        \WashContext::setActiveId('x1');

        \WashContext::updateOption('wash_post_types', 'post,page');
        $this->assertSame('post,page', $this->options['wash_c_x1_wash_post_types']);
        $this->assertSame('post,page', \WashContext::option('wash_post_types'));

        \WashContext::deleteOption('wash_post_types');
        $this->assertArrayNotHasKey('wash_c_x1_wash_post_types', $this->options);
        $this->assertSame('fallback', \WashContext::option('wash_post_types', 'fallback'));
    }

    public function testPrimaryOptionHelpersUseBaseKeys(): void
    {
        \WashContext::setActiveId('primary');
        \WashContext::updateOption('wash_sync_on_publish', '1');
        $this->assertSame('1', $this->options['wash_sync_on_publish']);
        $this->assertArrayNotHasKey('wash_c_primary_wash_sync_on_publish', $this->options);
    }

    /**
     * Credentials must NOT route through prefixed options — for primary they are the
     * historical unprefixed values (zero-migration), so callers that switched from
     * get_option('wash_shopify_store') to connection()['store'] are byte-identical.
     */
    public function testConnectionReturnsPrimaryBaseCredentials(): void
    {
        $this->options = [
            'wash_shopify_store' => 'myshop', 'wash_api_key' => 'wp4s_primary',
            'wash_app_url' => 'https://app.example', 'wash_http_user' => 'hu', 'wash_http_pass' => 'hp',
        ];
        \WashContext::setActiveId('primary');

        $c = \WashContext::connection();
        $this->assertSame('myshop', $c['store']);
        $this->assertSame('wp4s_primary', $c['apiKey']);
        $this->assertSame('hu', $c['httpUser']);
        $this->assertSame('hp', $c['httpPass']);
        $this->assertTrue($c['isPrimary']);
    }

    public function testConnectionReturnsRegistryCredentialsForNonPrimary(): void
    {
        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'store' => 'shop2', 'apiKey' => 'wp4s_2', 'httpUser' => 'u2', 'httpPass' => 'p2', 'enabled' => true, 'isPrimary' => false],
        ];
        \WashContext::setActiveId('x1');

        $c = \WashContext::connection();
        $this->assertSame('shop2', $c['store']);
        $this->assertSame('wp4s_2', $c['apiKey']);
        $this->assertSame('u2', $c['httpUser']);
        $this->assertFalse($c['isPrimary']);
    }
}
