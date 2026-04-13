<?php
/**
 * Unit tests for WashApiClient class.
 * Tests: backupSettings, restoreSettings, isConfigured, getBaseHeaders.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';

// Ensure WashMetafields is loaded for restoreSettings test
if (!class_exists('WashMetafields')) {
    require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';
}

class WashApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invokePrivate(object $obj, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashApiClient::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }

    /** Mock get_option for WashApiClient constructor with optional overrides. */
    private function mockOptions(array $overrides = []): void
    {
        $defaults = [
            'wash_api_key' => 'test-key', 'wash_app_url' => 'https://app-wash.tradik.dev',
            'wash_http_user' => '', 'wash_http_pass' => '',
        ];
        $opts = array_merge($defaults, $overrides);
        Functions\when('get_option')->alias(fn($key, $default = '') => $opts[$key] ?? $default);
    }

    // -- isConfigured --

    public function testIsConfiguredReturnsTrueWhenApiKeySet(): void
    {
        $this->mockOptions(['wash_api_key' => 'valid-api-key']);
        $this->assertTrue((new \WashApiClient())->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenApiKeyEmpty(): void
    {
        $this->mockOptions(['wash_api_key' => '', 'wash_app_url' => '']);
        $this->assertFalse((new \WashApiClient())->isConfigured());
    }

    // -- getBaseHeaders --

    public function testGetBaseHeadersIncludesRequiredHeaders(): void
    {
        $this->mockOptions(['wash_api_key' => 'my-api-key']);
        $client = new \WashApiClient();
        $headers = $this->invokePrivate($client, 'getBaseHeaders', ['1234567890']);
        $this->assertSame('my-api-key', $headers['X-Wash-Key']);
        $this->assertSame('1234567890', $headers['X-Wash-Timestamp']);
        $this->assertSame(WASH_VERSION, $headers['X-Wash-Plugin-Version']);
        $this->assertSame('application/json', $headers['Accept']);
    }

    public function testGetBaseHeadersIncludesBasicAuthWhenConfigured(): void
    {
        $this->mockOptions(['wash_http_user' => 'admin', 'wash_http_pass' => 'secret']);
        $headers = $this->invokePrivate(new \WashApiClient(), 'getBaseHeaders', ['12345']);
        $this->assertSame('Basic ' . base64_encode('admin:secret'), $headers['Authorization']);
    }

    public function testGetBaseHeadersOmitsAuthWhenNotConfigured(): void
    {
        $this->mockOptions();
        $headers = $this->invokePrivate(new \WashApiClient(), 'getBaseHeaders', ['12345']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    // -- backupSettings --

    public function testBackupSettingsReturnsApiResponse(): void
    {
        $this->mockOptions([
            'wash_sync_on_publish' => '1', 'wash_sync_images' => '1',
            'wash_sync_metafields' => '1', 'wash_post_types' => 'post,page',
            'wash_block_robots' => '1', 'wash_meta_field_config' => '[]',
            'wash_hreflangs_enabled' => '0', 'wash_authors_enabled' => '0',
            'wash_author_mappings' => [], 'wash_files_enabled' => '1',
            'wash_webp_convert' => '0', 'wash_allowed_file_types' => ['images'],
            'wash_blocks_enabled' => '1', 'wash_sync_blocks_meta' => '1',
            'wash_sync_blocks_css' => '0', 'wash_redirections_enabled' => '0',
        ]);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);

        $this->assertSame(['success' => true], (new \WashApiClient())->backupSettings());
    }

    // -- restoreSettings --

    public function testRestoreSettingsMapsProperly(): void
    {
        $updateCalls = [];
        Functions\when('update_option')->alias(function ($k, $v) use (&$updateCalls) { $updateCalls[$k] = $v; });
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->mockOptions();
        \WashMetafields::$fieldConfigCache = null;

        $result = (new \WashApiClient())->restoreSettings([
            'syncOnPublish' => '1', 'syncImages' => '0',
            'metaFieldConfig' => [['namespace' => 'wash', 'key' => 'x']],
        ]);
        $this->assertTrue($result);
        $this->assertSame('1', $updateCalls['wash_sync_on_publish']);
        $this->assertSame('0', $updateCalls['wash_sync_images']);
        $this->assertSame(json_encode([['namespace' => 'wash', 'key' => 'x']]), $updateCalls['wash_meta_field_config']);
    }

    public function testRestoreSettingsClearsRestoreFlag(): void
    {
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->mockOptions();
        \WashMetafields::$fieldConfigCache = null;
        (new \WashApiClient())->restoreSettings(['syncOnPublish' => '1']);
        global $washRestoreInProgress;
        $this->assertFalse($washRestoreInProgress);
    }

    public function testRestoreSettingsReturnsFalseForEmptyInput(): void
    {
        $this->mockOptions();
        $this->assertFalse((new \WashApiClient())->restoreSettings([]));
    }
}
