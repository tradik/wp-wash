<?php
/**
 * Coverage-focused unit tests for WashApiClient.
 * Exercises every public method and private branch not already covered by
 * WashApiClientTest or ApiClientContractTest.
 *
 * Methods targeted:
 *  - resolveAppUrl (all branches: hilo, explicit option, tradik.dev, default)
 *  - getShopifyAppSlug (all 3 branches)
 *  - getAppUrl
 *  - get() – success, not-configured, wp_error, 4xx, null JSON body
 *  - post() – success, not-configured, wp_error, 4xx error body
 *  - delete() – success, not-configured, wp_error, 4xx, null body fallback
 *  - testConnection – success, wp_error, non-200, basic-auth variant
 *  - ping() – success, not-configured, wp_error, 4xx, null body
 *  - diagnose() – success, not-configured, wp_error, null body
 *  - debugSession() – success, not-configured, wp_error, null body
 *  - resetSession() – success, not-configured, wp_error, null body
 *  - fixSession() – success, not-configured, wp_error, null body
 *  - sendWithRetry (via post) – 429 retry once, 429 max retries exceeded, 4xx
 *  - extractErrorMessage (all branches: JSON error, known HTTP code, raw body, generic)
 *  - getPermissions – cache hit, cache miss+success, cache miss+wp_error defaults
 *  - canUserModifySettings – WP_Error permissions, no user, allowed-users list match,
 *                            allowed-users list no match, adminOnly=true manage_options
 *  - clearPermissionsCache
 *  - getPlanInfo – cache hit, cache miss+success, cache miss+error, forceRefresh
 *  - hasFeature – feature present, feature absent, plan error
 *  - getRequiredPlanForFeature – feature available, feature not available (known + unknown)
 *  - clearPlanInfoCache
 *  - backupSettings – covered in existing test; extended via HTTP Basic Auth path
 *  - getBackedUpSettings
 *  - restoreSettings – empty/non-array, metaFieldConfig string path
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';

if (!class_exists('WashMetafields')) {
    require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';
}

// WordPress time constants not defined in bootstrap
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

class WashApiClientCoverageTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('error_log')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Wire get_option for the constructor.
     *
     * @param array<string,mixed> $overrides
     */
    private function mockOptions(array $overrides = []): void
    {
        $defaults = [
            'wash_api_key'   => 'test-key-abc123',
            'wash_app_url'   => '',
            'wash_http_user' => '',
            'wash_http_pass' => '',
        ];
        $opts = array_merge($defaults, $overrides);
        Functions\when('get_option')->alias(fn($key, $default = '') => $opts[$key] ?? $default);
    }

    /** Create a configured client with optional constructor option overrides. */
    private function makeClient(array $optOverrides = []): \WashApiClient
    {
        $this->mockOptions($optOverrides);
        return new \WashApiClient();
    }

    /** Invoke a private method by name. */
    private function invokePrivate(\WashApiClient $obj, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashApiClient::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }

    // -----------------------------------------------------------------------
    // resolveAppUrl
    // -----------------------------------------------------------------------

    public function testResolveAppUrlHiloDomainRoot(): void
    {
        Functions\when('get_site_url')->justReturn('https://hilo.com');
        $this->mockOptions(['wash_app_url' => '']);
        $client = new \WashApiClient();
        $this->assertSame('https://app-wash1.tradik.com', $client->getAppUrl());
    }

    public function testResolveAppUrlHiloSubdomain(): void
    {
        Functions\when('get_site_url')->justReturn('https://editorial.hilo.com');
        $this->mockOptions(['wash_app_url' => '']);
        $client = new \WashApiClient();
        $this->assertSame('https://app-wash1.tradik.com', $client->getAppUrl());
    }

    public function testResolveAppUrlHiloIgnoresStoredOption(): void
    {
        Functions\when('get_site_url')->justReturn('https://hilo.com');
        // Even if wash_app_url is set to something else, hilo forces secondary
        $this->mockOptions(['wash_app_url' => 'https://custom.example.com']);
        $client = new \WashApiClient();
        $this->assertSame('https://app-wash1.tradik.com', $client->getAppUrl());
    }

    public function testResolveAppUrlExplicitOption(): void
    {
        Functions\when('get_site_url')->justReturn('https://myshop.com');
        $this->mockOptions(['wash_app_url' => 'https://custom-app.example.com']);
        $client = new \WashApiClient();
        $this->assertSame('https://custom-app.example.com', $client->getAppUrl());
    }

    public function testResolveAppUrlDevEnvironment(): void
    {
        Functions\when('get_site_url')->justReturn('https://myshop.tradik.dev');
        $this->mockOptions(['wash_app_url' => '']);
        $client = new \WashApiClient();
        $this->assertSame('https://app-wash.tradik.dev', $client->getAppUrl());
    }

    public function testResolveAppUrlDefaultProduction(): void
    {
        Functions\when('get_site_url')->justReturn('https://myshop.com');
        $this->mockOptions(['wash_app_url' => '']);
        $client = new \WashApiClient();
        $this->assertSame('https://app-wash.tradik.com', $client->getAppUrl());
    }

    // -----------------------------------------------------------------------
    // getShopifyAppSlug
    // -----------------------------------------------------------------------

    public function testGetShopifyAppSlugDevEnvironment(): void
    {
        Functions\when('get_site_url')->justReturn('https://myshop.tradik.dev');
        $client = $this->makeClient(['wash_app_url' => '']);
        $this->assertSame('wash-blog-manager-dev', $client->getShopifyAppSlug());
    }

    public function testGetShopifyAppSlugHiloInstance(): void
    {
        Functions\when('get_site_url')->justReturn('https://hilo.com');
        $this->mockOptions(['wash_app_url' => '']);
        $client = new \WashApiClient();
        $this->assertSame('wash-blog', $client->getShopifyAppSlug());
    }

    public function testGetShopifyAppSlugProductionDefault(): void
    {
        Functions\when('get_site_url')->justReturn('https://myshop.com');
        $client = $this->makeClient(['wash_app_url' => '']);
        $this->assertSame('wash', $client->getShopifyAppSlug());
    }

    // -----------------------------------------------------------------------
    // getAppUrl
    // -----------------------------------------------------------------------

    public function testGetAppUrlReturnsConfiguredUrl(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient(['wash_app_url' => 'https://configured.example.com']);
        $this->assertSame('https://configured.example.com', $client->getAppUrl());
    }

    // -----------------------------------------------------------------------
    // get() method
    // -----------------------------------------------------------------------

    public function testGetReturnsErrorWhenNotConfigured(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->get('/api/test');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testGetReturnsWpErrorOnHttpFailure(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $wpError = new \WP_Error('http_error', 'Connection refused');
        Functions\when('wp_remote_get')->justReturn($wpError);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->get('/api/test');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('http_error', $result->get_error_code());
    }

    public function testGetReturns4xxAsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 401], 'body' => '']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = $client->get('/api/test');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_api_error', $result->get_error_code());
    }

    public function testGetReturns4xxWithJsonError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 403]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(403);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"error":"Forbidden message"}');

        $result = $client->get('/api/test');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('Forbidden message', $result->get_error_message());
    }

    public function testGetReturnsWpErrorOnInvalidJson(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('not-valid-json!!!');

        $result = $client->get('/api/test');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_invalid_response', $result->get_error_code());
    }

    public function testGetReturnsDecodedBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"hello":"world"}');

        $result = $client->get('/api/test');
        $this->assertSame(['hello' => 'world'], $result);
    }

    public function testGetReturnsNullBodyWhenEmptyBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        // empty body: json_decode('', true) === null AND empty($body) === true => returns null
        $result = $client->get('/api/test');
        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // post() method
    // -----------------------------------------------------------------------

    public function testPostReturnsErrorWhenNotConfigured(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('wp_json_encode')->alias('json_encode');
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->post('/api/test', ['foo' => 'bar']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testPostSuccessReturnsDecodedBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias('json_encode');
        $client = $this->makeClient();

        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"status":"ok"}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $result = $client->post('/api/content-sync', ['action' => 'create']);
        $this->assertSame(['status' => 'ok'], $result);
    }

    public function testPostReturnsWpErrorOnHttpFailure(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias('json_encode');
        $client = $this->makeClient();

        $err = new \WP_Error('connection_failed', 'Timeout');
        Functions\when('wp_remote_post')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $result = $client->post('/api/content-sync', []);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('connection_failed', $result->get_error_code());
    }

    public function testPostWith4xxReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('wp_json_encode')->alias('json_encode');
        $client = $this->makeClient();

        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 500]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $result = $client->post('/api/content-sync', []);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_api_error', $result->get_error_code());
    }

    public function testPostWithBasicAuthAddsAuthHeader(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias('json_encode');
        $capturedArgs = null;

        $client = $this->makeClient(['wash_http_user' => 'user', 'wash_http_pass' => 'pass']);

        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return ['response' => ['code' => 200]];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"ok":true}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $client->post('/api/test', []);

        $this->assertArrayHasKey('Authorization', $capturedArgs['headers']);
        $this->assertSame('Basic ' . base64_encode('user:pass'), $capturedArgs['headers']['Authorization']);
    }

    // -----------------------------------------------------------------------
    // delete() method
    // -----------------------------------------------------------------------

    public function testDeleteReturnsErrorWhenNotConfigured(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->delete('/api/test/1');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testDeleteReturnsWpErrorOnHttpFailure(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $err = new \WP_Error('http_error', 'Connection refused');
        Functions\when('wp_remote_request')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->delete('/api/test/1');
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testDeleteSuccessReturnsDecodedBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"deleted":true}');

        $result = $client->delete('/api/test/1');
        $this->assertSame(['deleted' => true], $result);
    }

    public function testDeleteSuccessNullBodyReturnsSuccessArray(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => 204]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(204);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = $client->delete('/api/test/1');
        $this->assertSame(['success' => true], $result);
    }

    public function testDeleteReturns4xxAsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => 404]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = $client->delete('/api/test/1');
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // -----------------------------------------------------------------------
    // testConnection()
    // -----------------------------------------------------------------------

    public function testConnectionSuccessReturnsBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"status":"healthy"}');

        $result = $client->testConnection();
        $this->assertSame(['status' => 'healthy'], $result);
    }

    public function testConnectionWpErrorPassedThrough(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $err = new \WP_Error('connect_error', 'Unreachable');
        Functions\when('wp_remote_get')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->testConnection();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testConnectionNon200ReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 503]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);

        $result = $client->testConnection();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_health_fail', $result->get_error_code());
    }

    public function testConnectionWithBasicAuthSendsAuthHeader(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $capturedArgs = null;

        $client = $this->makeClient(['wash_http_user' => 'u', 'wash_http_pass' => 'p']);
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return ['response' => ['code' => 200]];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        $client->testConnection();
        $this->assertArrayHasKey('Authorization', $capturedArgs['headers']);
    }

    // -----------------------------------------------------------------------
    // ping()
    // -----------------------------------------------------------------------

    public function testPingNotConfiguredReturnsError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->ping();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testPingWpErrorPassedThrough(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $err = new \WP_Error('timeout', 'Read timeout');
        Functions\when('wp_remote_post')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->ping();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testPingSuccessReturnsBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"pong":true}');

        $result = $client->ping();
        $this->assertSame(['pong' => true], $result);
    }

    public function testPingNullBodyReturnsSuccessArray(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = $client->ping();
        $this->assertSame(['success' => true], $result);
    }

    public function testPing4xxReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 503]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = $client->ping();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_ping_error', $result->get_error_code());
    }

    // -----------------------------------------------------------------------
    // diagnose()
    // -----------------------------------------------------------------------

    public function testDiagnoseNotConfiguredReturnsError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->diagnose();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testDiagnoseWpErrorPassedThrough(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $err = new \WP_Error('network_error', 'Network failure');
        Functions\when('wp_remote_get')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->diagnose();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testDiagnoseReturnsDecodedBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"session":"valid","shop":"test.myshopify.com"}');

        $result = $client->diagnose();
        $this->assertSame('valid', $result['session']);
    }

    public function testDiagnoseNullBodyReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 503]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);
        Functions\when('wp_remote_retrieve_body')->justReturn('not-json');

        $result = $client->diagnose();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_diagnose_error', $result->get_error_code());
    }

    // -----------------------------------------------------------------------
    // debugSession()
    // -----------------------------------------------------------------------

    public function testDebugSessionNotConfiguredReturnsError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->debugSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testDebugSessionWpErrorPassedThrough(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $err = new \WP_Error('timeout', 'Timed out');
        Functions\when('wp_remote_get')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->debugSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testDebugSessionReturnsDecodedBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sessionId":"abc123"}');

        $result = $client->debugSession();
        $this->assertSame('abc123', $result['sessionId']);
    }

    public function testDebugSessionNullBodyReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 400]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(400);
        Functions\when('wp_remote_retrieve_body')->justReturn('bad-json');

        $result = $client->debugSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_debug_error', $result->get_error_code());
    }

    // -----------------------------------------------------------------------
    // resetSession()
    // -----------------------------------------------------------------------

    public function testResetSessionNotConfiguredReturnsError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->resetSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testResetSessionWpErrorPassedThrough(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $err = new \WP_Error('timeout', 'Timed out');
        Functions\when('wp_remote_request')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->resetSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testResetSessionSuccessReturnsDecodedBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"reset":true}');

        $result = $client->resetSession();
        $this->assertSame(['reset' => true], $result);
    }

    public function testResetSessionNullBodyReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_request')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('not-json');

        $result = $client->resetSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_reset_error', $result->get_error_code());
    }

    // -----------------------------------------------------------------------
    // fixSession()
    // -----------------------------------------------------------------------

    public function testFixSessionNotConfiguredReturnsError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient(['wash_api_key' => '']);
        $result = $client->fixSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_not_configured', $result->get_error_code());
    }

    public function testFixSessionWpErrorPassedThrough(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $err = new \WP_Error('timeout', 'Timed out');
        Functions\when('wp_remote_post')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->fixSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testFixSessionSuccessReturnsDecodedBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"fixed":true}');

        $result = $client->fixSession();
        $this->assertSame(['fixed' => true], $result);
    }

    public function testFixSessionNullBodyReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('invalid-json');

        $result = $client->fixSession();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_fix_error', $result->get_error_code());
    }

    // -----------------------------------------------------------------------
    // sendWithRetry – 429 retry logic (via post())
    // -----------------------------------------------------------------------

    public function testSendWithRetry429OnceRetries(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        $callCount = 0;
        Functions\when('wp_remote_post')->alias(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return ['response' => ['code' => 429], 'headers' => []];
            }
            return ['response' => ['code' => 200]];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(function () use (&$callCount) {
            return $callCount === 1 ? 429 : 200;
        });
        Functions\when('wp_remote_retrieve_body')->justReturn('{"retried":true}');
        Functions\when('wp_remote_retrieve_header')->justReturn('0');

        // Override sleep to avoid actual delay
        Functions\when('sleep')->justReturn(0);

        $result = $client->post('/api/content-sync', ['data' => 'x']);
        // Should have retried and got 200
        $this->assertSame(2, $callCount);
    }

    public function testSendWithRetry429ExceedsMaxRetriesReturnsError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        // Set maxRetries=3, so 4 calls of 429 should exhaust retries and hit 4th response
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 429]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(429);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_header')->justReturn('1');
        Functions\when('sleep')->justReturn(0);

        $result = $client->post('/api/content-sync', []);
        // After maxRetries exhausted, falls through to 4xx handler
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // -----------------------------------------------------------------------
    // extractErrorMessage (private, exercised via 4xx responses)
    // -----------------------------------------------------------------------

    public function testExtractErrorMessageWithJsonErrorField(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(400);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"error":"Custom error message"}');

        $result = $client->get('/api/test');
        $this->assertSame('Custom error message', $result->get_error_message());
    }

    public function testExtractErrorMessageKnownStatusCodes(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        $knownCodes = [401, 403, 404, 500, 502, 503];
        foreach ($knownCodes as $code) {
            Monkey\tearDown();
            Monkey\setUp();
            Functions\when('error_log')->justReturn(true);
            Functions\when('get_site_url')->justReturn('https://example.com');
            $this->mockOptions();
            $c = new \WashApiClient();

            Functions\when('__')->alias(fn($text) => $text);
            Functions\when('wp_remote_get')->justReturn([]);
            Functions\when('is_wp_error')->justReturn(false);
            Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
            Functions\when('wp_remote_retrieve_body')->justReturn('');

            $result = $c->get('/api/test');
            $this->assertInstanceOf(\WP_Error::class, $result, "Expected WP_Error for HTTP $code");
            $this->assertNotEmpty($result->get_error_message(), "Expected non-empty message for HTTP $code");
        }
    }

    public function testExtractErrorMessageWithShortRawBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        $shortBody = 'Short error text';
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(422);
        Functions\when('wp_remote_retrieve_body')->justReturn($shortBody);

        $result = $client->get('/api/test');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('Short error text', $result->get_error_message());
    }

    public function testExtractErrorMessageWithLongBody(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        // Body > 200 chars should use generic "API error (HTTP %d)" message
        $longBody = str_repeat('X', 300);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(422);
        Functions\when('wp_remote_retrieve_body')->justReturn($longBody);

        $result = $client->get('/api/test');
        $this->assertInstanceOf(\WP_Error::class, $result);
        // generic message must contain the status code
        $this->assertStringContainsString('422', $result->get_error_message());
    }

    // -----------------------------------------------------------------------
    // getPermissions()
    // -----------------------------------------------------------------------

    public function testGetPermissionsReturnsCachedValue(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $cached = ['canEditSync' => true, 'canEditBlogs' => false, 'settingsAdminOnly' => false, 'settingsAllowedUsers' => ''];
        Functions\when('get_transient')->justReturn($cached);

        $result = $client->getPermissions();
        $this->assertSame($cached, $result);
    }

    public function testGetPermissionsFetchesOnCacheMiss(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"permissions":{"canEditSync":true,"canEditBlogs":true,"settingsAdminOnly":false,"settingsAllowedUsers":""}}');
        Functions\when('set_transient')->justReturn(true);

        $result = $client->getPermissions();
        $this->assertTrue($result['canEditSync']);
    }

    public function testGetPermissionsOnApiErrorReturnsDefaults(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(false);
        // Simulate API failure
        $err = new \WP_Error('api_error', 'Failed');
        Functions\when('wp_remote_get')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->getPermissions();
        // On error, returns defaults (all allowed)
        $this->assertIsArray($result);
        $this->assertTrue($result['canEditSync']);
        $this->assertFalse($result['settingsAdminOnly']);
    }

    public function testGetPermissionsEnsuresAdminOnlyDefaults(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        // Response does NOT include settingsAdminOnly / settingsAllowedUsers
        Functions\when('wp_remote_retrieve_body')->justReturn('{"permissions":{"canEditSync":true}}');
        Functions\when('set_transient')->justReturn(true);

        $result = $client->getPermissions();
        $this->assertFalse($result['settingsAdminOnly']);
        $this->assertSame('', $result['settingsAllowedUsers']);
    }

    // -----------------------------------------------------------------------
    // canUserModifySettings()
    // -----------------------------------------------------------------------

    public function testCanUserModifySettingsWithWpErrorPermissionsAllowsAccess(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        $err = new \WP_Error('error', 'Could not fetch');
        $result = $client->canUserModifySettings($err);
        $this->assertTrue($result);
    }

    public function testCanUserModifySettingsNoUserReturnsFalse(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $noUser = new class {
            public function exists() { return false; }
        };
        Functions\when('wp_get_current_user')->justReturn($noUser);

        $result = $client->canUserModifySettings(['settingsAdminOnly' => false, 'settingsAllowedUsers' => '']);
        $this->assertFalse($result);
    }

    public function testCanUserModifySettingsUserInAllowedListReturnsTrue(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $user = new class {
            public string $user_login = 'john';
            public function exists() { return true; }
        };
        Functions\when('wp_get_current_user')->justReturn($user);

        $perms = ['settingsAdminOnly' => true, 'settingsAllowedUsers' => 'john, jane'];
        $result = $client->canUserModifySettings($perms);
        $this->assertTrue($result);
    }

    public function testCanUserModifySettingsUserNotInAllowedListChecksAdminOnly(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $user = new class {
            public string $user_login = 'bob';
            public function exists() { return true; }
        };
        Functions\when('wp_get_current_user')->justReturn($user);
        Functions\when('current_user_can')->justReturn(false);

        $perms = ['settingsAdminOnly' => true, 'settingsAllowedUsers' => 'john, jane'];
        $result = $client->canUserModifySettings($perms);
        $this->assertFalse($result);
    }

    public function testCanUserModifySettingsAdminOnlyTrueWithManageOptions(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $user = new class {
            public string $user_login = 'admin';
            public function exists() { return true; }
        };
        Functions\when('wp_get_current_user')->justReturn($user);
        Functions\when('current_user_can')->justReturn(true);

        $perms = ['settingsAdminOnly' => true, 'settingsAllowedUsers' => ''];
        $result = $client->canUserModifySettings($perms);
        $this->assertTrue($result);
    }

    public function testCanUserModifySettingsNullPermsFetchesViaGetPermissions(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        // Provide cached permissions
        $cached = ['settingsAdminOnly' => false, 'settingsAllowedUsers' => ''];
        Functions\when('get_transient')->justReturn($cached);

        $user = new class {
            public string $user_login = 'editor';
            public function exists() { return true; }
        };
        Functions\when('wp_get_current_user')->justReturn($user);
        Functions\when('current_user_can')->justReturn(true);

        $result = $client->canUserModifySettings(null);
        $this->assertTrue($result);
    }

    public function testCanUserModifySettingsNotAdminOnlyChecksManageOptions(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $user = new class {
            public string $user_login = 'editor';
            public function exists() { return true; }
        };
        Functions\when('wp_get_current_user')->justReturn($user);
        Functions\when('current_user_can')->justReturn(true);

        $perms = ['settingsAdminOnly' => false, 'settingsAllowedUsers' => ''];
        $result = $client->canUserModifySettings($perms);
        $this->assertTrue($result);
    }

    // -----------------------------------------------------------------------
    // clearPermissionsCache()
    // -----------------------------------------------------------------------

    public function testClearPermissionsCacheCallsDeleteTransient(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $deleted = null;
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
            $deleted = $key;
            return true;
        });

        $client->clearPermissionsCache();
        $this->assertSame('wash_permissions', $deleted);
    }

    // -----------------------------------------------------------------------
    // getPlanInfo()
    // -----------------------------------------------------------------------

    public function testGetPlanInfoReturnsCachedValue(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $cached = ['plan' => 'PRO', 'features' => ['seoSync' => true]];
        Functions\when('get_transient')->justReturn($cached);

        $result = $client->getPlanInfo();
        $this->assertSame($cached, $result);
    }

    public function testGetPlanInfoForceRefreshIgnoresCache(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $cachedValue = ['plan' => 'FREE'];
        Functions\when('get_transient')->justReturn($cachedValue);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"plan":"PRO"}');
        Functions\when('set_transient')->justReturn(true);

        $result = $client->getPlanInfo(true);
        $this->assertSame(['plan' => 'PRO'], $result);
    }

    public function testGetPlanInfoCacheMissFetchesAndCaches(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"plan":"BUSINESS","features":{"acfSync":true}}');
        Functions\when('set_transient')->justReturn(true);

        $result = $client->getPlanInfo();
        $this->assertSame('BUSINESS', $result['plan']);
    }

    public function testGetPlanInfoOnApiErrorReturnsWpError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(false);
        $err = new \WP_Error('api_error', 'Failed');
        Functions\when('wp_remote_get')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $result = $client->getPlanInfo();
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // -----------------------------------------------------------------------
    // hasFeature()
    // -----------------------------------------------------------------------

    public function testHasFeatureReturnsTrueWhenFeaturePresent(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(['features' => ['seoSync' => true]]);

        $this->assertTrue($client->hasFeature('seoSync'));
    }

    public function testHasFeatureReturnsFalseWhenFeatureAbsent(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(['features' => ['seoSync' => false]]);

        $this->assertFalse($client->hasFeature('seoSync'));
    }

    public function testHasFeatureReturnsTrueOnPlanError(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('__')->alias(fn($text) => $text);
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(false);
        $err = new \WP_Error('api_error', 'Failed');
        Functions\when('wp_remote_get')->justReturn($err);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        // On error, hasFeature returns true (fail-open)
        $this->assertTrue($client->hasFeature('acfSync'));
    }

    public function testHasFeatureReturnsFalseWhenFeatureMissingFromArray(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(['features' => []]);

        $this->assertFalse($client->hasFeature('unknownFeature'));
    }

    // -----------------------------------------------------------------------
    // getRequiredPlanForFeature()
    // -----------------------------------------------------------------------

    public function testGetRequiredPlanForFeatureReturnsNullWhenAvailable(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(['features' => ['seoSync' => true]]);

        $this->assertNull($client->getRequiredPlanForFeature('seoSync'));
    }

    public function testGetRequiredPlanForFeatureReturnsKnownPlan(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(['features' => ['seoSync' => false]]);

        $this->assertSame('PRO', $client->getRequiredPlanForFeature('seoSync'));
    }

    public function testGetRequiredPlanForFeatureReturnsBusinessForAcfSync(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(['features' => ['acfSync' => false]]);

        $this->assertSame('BUSINESS', $client->getRequiredPlanForFeature('acfSync'));
    }

    public function testGetRequiredPlanForFeatureReturnsProForUnknownFeature(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        Functions\when('get_transient')->justReturn(['features' => []]);

        $this->assertSame('PRO', $client->getRequiredPlanForFeature('someUnknownFeature'));
    }

    // -----------------------------------------------------------------------
    // clearPlanInfoCache()
    // -----------------------------------------------------------------------

    public function testClearPlanInfoCacheCallsDeleteTransient(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $deleted = null;
        Functions\when('delete_transient')->alias(function ($key) use (&$deleted) {
            $deleted = $key;
            return true;
        });

        $client->clearPlanInfoCache();
        $this->assertSame('wash_plan_info', $deleted);
    }

    // -----------------------------------------------------------------------
    // backupSettings() – HTTP Basic Auth path
    // -----------------------------------------------------------------------

    public function testBackupSettingsWithBasicAuthIncludesAuthHeader(): void
    {
        $capturedArgs = null;
        $opts = [
            'wash_api_key'             => 'key',
            'wash_app_url'             => '',
            'wash_http_user'           => 'user',
            'wash_http_pass'           => 'pass',
            'wash_sync_on_publish'     => '1',
            'wash_sync_metafields'     => '1',
            'wash_post_types'          => 'post',
            'wash_block_robots'        => '1',
            'wash_meta_field_config'   => '[]',
            'wash_hreflangs_enabled'   => '0',
            'wash_authors_enabled'     => '0',
            'wash_author_mappings'     => [],
            'wash_files_enabled'       => '1',
            'wash_webp_convert'        => '0',
            'wash_allowed_file_types'  => ['images'],
            'wash_blocks_enabled'      => '1',
            'wash_sync_blocks_meta'    => '1',
            'wash_sync_blocks_css'     => '0',
            'wash_redirections_enabled' => '0',
        ];
        Functions\when('get_site_url')->justReturn('https://myshop.com');
        Functions\when('get_option')->alias(fn($k, $d = '') => $opts[$k] ?? $d);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
            return ['response' => ['code' => 200]];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"ok":true}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $client = new \WashApiClient();
        $client->backupSettings();

        $this->assertArrayHasKey('Authorization', $capturedArgs['headers']);
        $this->assertSame('Basic ' . base64_encode('user:pass'), $capturedArgs['headers']['Authorization']);
    }

    // -----------------------------------------------------------------------
    // getBackedUpSettings()
    // -----------------------------------------------------------------------

    public function testGetBackedUpSettingsCallsGetEndpoint(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient();

        $capturedUrl = null;
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$capturedUrl) {
            $capturedUrl = $url;
            return ['response' => ['code' => 200]];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"syncOnPublish":"1"}');

        $result = $client->getBackedUpSettings();
        $this->assertStringEndsWith('/api/settings-sync', $capturedUrl);
        $this->assertSame(['syncOnPublish' => '1'], $result);
    }

    // -----------------------------------------------------------------------
    // restoreSettings() – string metaFieldConfig path
    // -----------------------------------------------------------------------

    public function testRestoreSettingsMetaFieldConfigAsStringIsStoredDirectly(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->mockOptions();

        $updateCalls = [];
        Functions\when('update_option')->alias(function ($k, $v) use (&$updateCalls) {
            $updateCalls[$k] = $v;
        });
        \WashMetafields::$fieldConfigCache = null;

        $client = new \WashApiClient();
        $result = $client->restoreSettings(['metaFieldConfig' => '[{"key":"x"}]']);

        $this->assertTrue($result);
        $this->assertSame('[{"key":"x"}]', $updateCalls['wash_meta_field_config']);
    }

    public function testRestoreSettingsNonArrayInputReturnsFalse(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $this->mockOptions();
        $client = new \WashApiClient();

        $this->assertFalse($client->restoreSettings(null));
        $this->assertFalse($client->restoreSettings([]));
        $this->assertFalse($client->restoreSettings('string'));
    }

    public function testRestoreSettingsUpdatesKnownOptionKeys(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias('json_encode');
        $this->mockOptions();

        $updateCalls = [];
        Functions\when('update_option')->alias(function ($k, $v) use (&$updateCalls) {
            $updateCalls[$k] = $v;
        });
        \WashMetafields::$fieldConfigCache = null;

        $client = new \WashApiClient();
        $result = $client->restoreSettings([
            'syncOnPublish'    => '1',
            'syncMetafields'   => '0',
            'postTypes'        => 'post,page',
            'blockRobots'      => '0',
            'hreflangsEnabled' => '1',
            'authorsEnabled'   => '1',
            'filesEnabled'     => '0',
            'webpConvert'      => '1',
            'blocksEnabled'    => '0',
            'syncBlocksMeta'   => '0',
            'syncBlocksCss'    => '1',
            'redirectionsEnabled' => '1',
        ]);

        $this->assertTrue($result);
        $this->assertSame('1', $updateCalls['wash_sync_on_publish']);
        $this->assertSame('0', $updateCalls['wash_sync_metafields']);
        $this->assertSame('post,page', $updateCalls['wash_post_types']);
    }

    public function testRestoreSettingsMetaFieldConfigAsArrayCallsWpJsonEncode(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $this->mockOptions();

        $updateCalls = [];
        Functions\when('update_option')->alias(function ($k, $v) use (&$updateCalls) {
            $updateCalls[$k] = $v;
        });
        Functions\when('wp_json_encode')->alias('json_encode');
        \WashMetafields::$fieldConfigCache = null;

        $client = new \WashApiClient();
        $metaConfig = [['namespace' => 'global', 'key' => 'test_key']];
        $result = $client->restoreSettings(['metaFieldConfig' => $metaConfig]);

        $this->assertTrue($result);
        $this->assertSame(json_encode($metaConfig), $updateCalls['wash_meta_field_config']);
    }

    // -----------------------------------------------------------------------
    // isConfigured() – covered here for completeness
    // -----------------------------------------------------------------------

    public function testIsConfiguredReturnsTrueWhenKeySet(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient(['wash_api_key' => 'my-key']);
        $this->assertTrue($client->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenKeyEmpty(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com');
        $client = $this->makeClient(['wash_api_key' => '']);
        $this->assertFalse($client->isConfigured());
    }
}
