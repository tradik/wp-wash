<?php
/**
 * Tests for the multi-store runtime routing (#216, phase 5):
 *  - WashConnection::getByApiKey() — match an incoming key to its connection.
 *  - WashSync::targetConnections() — which enabled connections a post fans out to.
 *  - WashCallback::verifyApiKey() routing — an inbound X-Wash-Key activates its connection.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-callback.php';

/** Minimal request stub exposing only the headers the verifier reads. */
class WashHeaderRequest
{
    /** @var array<string,string> */
    private array $headers;
    public function __construct(array $headers)
    {
        $this->headers = $headers;
    }
    public function get_header($name)
    {
        return $this->headers[$name] ?? '';
    }
}

class WashMultiStoreRoutingTest extends TestCase
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
        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('home_url')->alias(fn($p = '') => 'https://example.com' . $p);
        Functions\when('error_log')->justReturn(true);
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

    /** Seed the registry; primary credentials come from the base options. */
    private function seed(): void
    {
        $this->options['wash_api_key'] = 'wp4s_primary';
        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'store' => 's1', 'apiKey' => 'wp4s_x1', 'enabled' => true, 'isPrimary' => false, 'createdAt' => 1],
            'x2' => ['id' => 'x2', 'store' => 's2', 'apiKey' => 'wp4s_x2', 'enabled' => false, 'isPrimary' => false, 'createdAt' => 1],
        ];
    }

    public function testGetByApiKeyMatchesPrimaryAndAdditional(): void
    {
        $this->seed();
        $this->assertSame('primary', \WashConnection::getByApiKey('wp4s_primary'));
        $this->assertSame('x1', \WashConnection::getByApiKey('wp4s_x1'));
        $this->assertSame('x2', \WashConnection::getByApiKey('wp4s_x2')); // disabled but still owns the key
    }

    public function testGetByApiKeyReturnsNullForUnknownOrEmpty(): void
    {
        $this->seed();
        $this->assertNull(\WashConnection::getByApiKey('wp4s_ghost'));
        $this->assertNull(\WashConnection::getByApiKey(''));
    }

    public function testTargetConnectionsReturnsEnabledConfiguredConnections(): void
    {
        $this->seed(); // primary + x1 enabled & configured, x2 disabled
        $sync = new \WashSync();
        $post = (object) ['ID' => 5, 'post_type' => 'post', 'post_status' => 'publish'];

        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $ref = new \ReflectionMethod(\WashSync::class, 'targetConnections');
        $ref->setAccessible(true);
        $targets = $ref->invoke($sync, 5, $post, true);

        $this->assertSame(['primary', 'x1'], $targets); // x2 disabled → excluded
        $this->assertSame('primary', \WashContext::activeId()); // routing reset after the scan
    }

    public function testTargetConnectionsSkipsDisallowedPostType(): void
    {
        $this->seed();
        $this->options['wash_post_types'] = 'page'; // primary allows only pages
        $this->options['wash_c_x1_wash_post_types'] = 'page'; // x1 too
        $sync = new \WashSync();
        $post = (object) ['ID' => 5, 'post_type' => 'post', 'post_status' => 'publish'];
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $ref = new \ReflectionMethod(\WashSync::class, 'targetConnections');
        $ref->setAccessible(true);
        $this->assertSame([], $ref->invoke($sync, 5, $post, true));
    }

    public function testVerifyApiKeyRoutesToConnectionOwningTheKey(): void
    {
        $this->seed();
        $callback = new \WashCallback();
        $request  = new WashHeaderRequest(['X-Wash-Key' => 'wp4s_x1']);

        $result = $callback->verifyApiKey($request);

        $this->assertTrue($result);
        $this->assertSame('x1', \WashContext::activeId()); // routed to x1 by its key
    }

    public function testVerifyApiKeyRejectsUnknownKeyAndStaysPrimary(): void
    {
        $this->seed();
        $callback = new \WashCallback();
        $request  = new WashHeaderRequest(['X-Wash-Key' => 'wp4s_ghost']);

        $result = $callback->verifyApiKey($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('primary', \WashContext::activeId());
    }
}
