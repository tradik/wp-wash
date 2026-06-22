<?php
/**
 * Tests for WashConnection — the multi-store connection registry (#216).
 *
 * The decisive case is the zero-migration invariant: get('primary') reads the
 * historical unprefixed credential options, so an existing single-store install
 * is unchanged.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';

class WashConnectionTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('wp_generate_password')->justReturn('abc123');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testPrimaryReturnsHistoricalUnprefixedCredentials(): void
    {
        $this->options = [
            'wash_api_key'       => 'wp4s_aaa',
            'wash_shopify_store' => 'store-1',
            'wash_app_url'       => 'https://app-wash.tradik.com',
            'wash_http_user'     => 'basic-u',
            'wash_http_pass'     => 'basic-p',
        ];

        $primary = \WashConnection::get('primary');

        $this->assertSame('wp4s_aaa', $primary['apiKey']);
        $this->assertSame('store-1', $primary['store']);
        $this->assertSame('https://app-wash.tradik.com', $primary['appUrl']);
        $this->assertSame('basic-u', $primary['httpUser']);
        $this->assertSame('basic-p', $primary['httpPass']);
        $this->assertTrue($primary['enabled']);
        $this->assertTrue($primary['isPrimary']);
        $this->assertSame('primary', $primary['id']);
    }

    public function testPrimaryCreatedAtComesFromRegistryPointerWhenPresent(): void
    {
        $this->options['wash_connections'] = ['primary' => ['createdAt' => 1699999999, 'isPrimary' => true]];
        $this->assertSame(1699999999, \WashConnection::get('primary')['createdAt']);
    }

    public function testAllWithNoRegistryIsJustPrimary(): void
    {
        $this->assertSame(['primary'], array_keys(\WashConnection::all()));
        $this->assertSame(1, \WashConnection::count());
        $this->assertSame(1, \WashConnection::enabledCount());
    }

    public function testAddRegistersNonPrimaryAndReturnsId(): void
    {
        $id = \WashConnection::add('store-2', 'wp4s_bbb', 'https://app2', 'u2', 'p2');

        $this->assertSame('abc123', $id);
        $conn = \WashConnection::get($id);
        $this->assertSame('store-2', $conn['store']);
        $this->assertSame('wp4s_bbb', $conn['apiKey']);
        $this->assertSame('https://app2', $conn['appUrl']);
        $this->assertFalse($conn['isPrimary']);
        $this->assertTrue($conn['enabled']);
        $this->assertIsInt($conn['createdAt']);
        $this->assertGreaterThan(0, $conn['createdAt']);
        $this->assertSame(2, \WashConnection::count());
        $this->assertSame(['primary', 'abc123'], array_keys(\WashConnection::all()));
    }

    public function testSetEnabledTogglesNonPrimaryAndRefusesPrimary(): void
    {
        $id = \WashConnection::add('store-2', 'k');

        $this->assertTrue(\WashConnection::setEnabled($id, false));
        $this->assertFalse(\WashConnection::get($id)['enabled']);
        $this->assertSame(1, \WashConnection::enabledCount());

        $this->assertFalse(\WashConnection::setEnabled('primary', false));
        $this->assertFalse(\WashConnection::setEnabled('ghost', true));
    }

    public function testRemoveDeletesNonPrimaryAndRefusesPrimary(): void
    {
        $id = \WashConnection::add('store-2', 'k');

        $this->assertTrue(\WashConnection::remove($id));
        $this->assertNull(\WashConnection::get($id));
        $this->assertFalse(\WashConnection::exists($id));
        $this->assertSame(1, \WashConnection::count());

        $this->assertFalse(\WashConnection::remove('primary'));
        $this->assertFalse(\WashConnection::remove('ghost'));
    }

    public function testGetUnknownReturnsNullAndExistsReflectsIt(): void
    {
        $this->assertNull(\WashConnection::get('nope'));
        $this->assertFalse(\WashConnection::exists('nope'));
        $this->assertTrue(\WashConnection::exists('primary'));
    }

    public function testIsPrimary(): void
    {
        $this->assertTrue(\WashConnection::isPrimary('primary'));
        $this->assertFalse(\WashConnection::isPrimary('abc123'));
    }

    public function testAddRecoversFromCorruptRegistryOption(): void
    {
        $this->options['wash_connections'] = 'corrupt-not-an-array';
        $id = \WashConnection::add('store-2', 'k');
        $this->assertSame('abc123', $id);
        $this->assertSame('store-2', \WashConnection::get($id)['store']);
    }

    public function testMalformedRegistryEntriesAreIgnored(): void
    {
        $this->options['wash_connections'] = [
            'good'   => ['id' => 'good', 'store' => 's', 'apiKey' => 'k', 'enabled' => true, 'isPrimary' => false],
            'bogus'  => 'not-an-array',
            'shadow' => ['isPrimary' => true], // a non-primary slot claiming primary is skipped
        ];

        $all = \WashConnection::all();
        $this->assertSame(['primary', 'good'], array_keys($all));
        $this->assertNull(\WashConnection::get('bogus'));
        $this->assertNull(\WashConnection::get('shadow'));
    }
}
