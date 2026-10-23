<?php
/**
 * Contract tests: validates WashApiClient HTTP request structure
 * (headers, URL construction, error handling).
 */
namespace Wash\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';

class ApiClientContractTest extends TestCase
{
    private $client;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'test-api-key-12345',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $this->client = new \WashApiClient();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function getPrivateProperty(string $property)
    {
        $ref = new \ReflectionProperty(\WashApiClient::class, $property);
        $ref->setAccessible(true);
        return $ref->getValue($this->client);
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashApiClient::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->client, ...$args);
    }

    /**
     * Contract: base headers contain required auth fields.
     */
    public function testBaseHeadersContainRequiredFields(): void
    {
        $headers = $this->invokePrivate('getBaseHeaders', ['1700000000']);

        $this->assertArrayHasKey('Accept', $headers);
        $this->assertEquals('application/json', $headers['Accept']);

        $this->assertArrayHasKey('X-Wash-Key', $headers);
        $this->assertEquals('test-api-key-12345', $headers['X-Wash-Key']);

        $this->assertArrayHasKey('X-Wash-Timestamp', $headers);
        $this->assertEquals('1700000000', $headers['X-Wash-Timestamp']);

        $this->assertArrayHasKey('X-Wash-Plugin-Version', $headers);
        $this->assertEquals(WASH_VERSION, $headers['X-Wash-Plugin-Version']);
    }

    /**
     * Contract: no Bearer auth — WP plugin uses X-Wash-Key, not Bearer.
     */
    public function testNoBeareAuthWithoutHttpCredentials(): void
    {
        $headers = $this->invokePrivate('getBaseHeaders', ['1700000000']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    /**
     * Contract: HTTP Basic Auth header when credentials set.
     */
    public function testHttpBasicAuthWhenCredentialsConfigured(): void
    {
        Monkey\tearDown();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => 'admin',
                'wash_http_pass' => 'secret',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $client = new \WashApiClient();
        $ref = new \ReflectionMethod(\WashApiClient::class, 'getBaseHeaders');
        $ref->setAccessible(true);
        $headers = $ref->invoke($client, '1700000000');

        $this->assertArrayHasKey('Authorization', $headers);
        $expected = 'Basic ' . base64_encode('admin:secret');
        $this->assertEquals($expected, $headers['Authorization']);
    }

    /**
     * Contract: POST URL is appUrl + endpoint.
     */
    public function testPostUrlConstruction(): void
    {
        $capturedArgs = null;

        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = ['url' => $url, 'args' => $args];
            return [
                'response' => ['code' => 200],
                'body' => '{"success":true}',
            ];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('is_wp_error')->justReturn(false);

        $this->client->post('/api/content-sync', ['action' => 'create']);

        $this->assertNotNull($capturedArgs);
        $this->assertEquals(
            'https://app-wash.tradik.dev/api/content-sync',
            $capturedArgs['url']
        );
    }

    /**
     * Contract: POST includes Content-Type: application/json.
     */
    public function testPostContentTypeHeader(): void
    {
        $capturedArgs = null;

        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return [
                'response' => ['code' => 200],
                'body' => '{"success":true}',
            ];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('is_wp_error')->justReturn(false);

        $this->client->post('/api/content-sync', ['action' => 'create']);

        $this->assertNotNull($capturedArgs);
        $this->assertArrayHasKey('Content-Type', $capturedArgs['headers']);
        $this->assertEquals('application/json', $capturedArgs['headers']['Content-Type']);
    }

    /**
     * Contract: POST body is valid JSON.
     */
    public function testPostBodyIsValidJson(): void
    {
        $capturedBody = null;

        Functions\when('wp_json_encode')->alias(fn($data) => json_encode($data));
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$capturedBody) {
            $capturedBody = $args['body'];
            return [
                'response' => ['code' => 200],
                'body' => '{"success":true}',
            ];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('is_wp_error')->justReturn(false);

        $payload = ['action' => 'create', 'title' => 'Test'];
        $this->client->post('/api/content-sync', $payload);

        $this->assertNotNull($capturedBody);
        $decoded = json_decode($capturedBody, true);
        $this->assertNotNull($decoded, 'POST body must be valid JSON');
        $this->assertEquals('create', $decoded['action']);
    }

    /**
     * Contract: returns WP_Error when API key not configured.
     */
    public function testReturnsErrorWhenNotConfigured(): void
    {
        Monkey\tearDown();
        Monkey\setUp();

        Functions\when('get_option')->justReturn('');
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('__')->alias(fn($text) => $text);

        $client = new \WashApiClient();
        $result = $client->post('/api/content-sync', ['action' => 'create']);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    /**
     * Contract: app URL resolves based on environment.
     */
    public function testAppUrlResolution(): void
    {
        $appUrl = $this->getPrivateProperty('appUrl');
        $this->assertEquals('https://app-wash.tradik.dev', $appUrl);
    }
}
