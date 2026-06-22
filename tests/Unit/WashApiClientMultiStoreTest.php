<?php
/**
 * Tests for the connection-aware WashApiClient + WashContext::apiClient() (#216, phase 1).
 *
 * The client now reads its credentials and app URL from a connection definition
 * instead of the global options. For `primary` the connection synthesizes the
 * historical unprefixed options, so single-store behaviour is unchanged.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';

class WashApiClientMultiStoreTest extends TestCase
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
        Functions\when('get_site_url')->justReturn('https://shop.example.com'); // non-Hilo, non-dev
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

    private function prop(object $obj, string $name)
    {
        $r = new \ReflectionProperty($obj, $name);
        $r->setAccessible(true);
        return $r->getValue($obj);
    }

    public function testExplicitConnectionUsesItsCredentialsAndAppUrl(): void
    {
        $client = new \WashApiClient([
            'id' => 'x1', 'apiKey' => 'wp4s_x1', 'appUrl' => 'https://app-x1.example',
            'httpUser' => 'u1', 'httpPass' => 'p1',
        ]);

        $this->assertSame('wp4s_x1', $this->prop($client, 'apiKey'));
        $this->assertSame('https://app-x1.example', $this->prop($client, 'appUrl'));
        $this->assertSame('u1', $this->prop($client, 'httpUser'));
        $this->assertSame('p1', $this->prop($client, 'httpPass'));
    }

    public function testNullConnectionResolvesTheActiveConnection(): void
    {
        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'apiKey' => 'wp4s_active', 'appUrl' => 'https://app-active.example', 'enabled' => true, 'isPrimary' => false],
        ];
        \WashContext::setActiveId('x1');

        $client = new \WashApiClient(); // null → active connection (x1)
        $this->assertSame('wp4s_active', $this->prop($client, 'apiKey'));
        $this->assertSame('https://app-active.example', $this->prop($client, 'appUrl'));
    }

    public function testPrimaryConnectionUsesHistoricalBaseOptions(): void
    {
        $this->options = [
            'wash_api_key' => 'wp4s_primary', 'wash_app_url' => 'https://app-primary.example',
            'wash_http_user' => 'pu', 'wash_http_pass' => 'pp',
        ];
        \WashContext::setActiveId('primary');

        $client = new \WashApiClient(); // null → primary → unprefixed options
        $this->assertSame('wp4s_primary', $this->prop($client, 'apiKey'));
        $this->assertSame('https://app-primary.example', $this->prop($client, 'appUrl'));
        $this->assertSame('pu', $this->prop($client, 'httpUser'));
    }

    public function testHiloHostOverridesConnectionAppUrl(): void
    {
        Functions\when('get_site_url')->justReturn('https://www.hilo.com');
        $client = new \WashApiClient(['id' => 'x1', 'apiKey' => 'k', 'appUrl' => 'https://ignored.example']);
        $this->assertSame('https://app-wash1.tradik.com', $this->prop($client, 'appUrl'));
    }

    public function testApiClientMemoizesPerActiveConnection(): void
    {
        \WashContext::setActiveId('primary');
        $c1 = \WashContext::apiClient();
        $this->assertInstanceOf(\WashApiClient::class, $c1);
        $this->assertSame($c1, \WashContext::apiClient()); // memoized within a connection

        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'apiKey' => 'k', 'enabled' => true, 'isPrimary' => false],
        ];
        \WashContext::setActiveId('x1'); // changing the active connection drops the cache
        $this->assertNotSame($c1, \WashContext::apiClient());
    }
}
