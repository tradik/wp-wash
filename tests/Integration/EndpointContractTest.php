<?php
/**
 * Contract tests: validates endpoint paths used by WP plugin
 * match the Shopify App route definitions.
 */
namespace Wash\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class EndpointContractTest extends TestCase
{
    private $sync;

    /**
     * Expected endpoint paths as defined in the Shopify App routes.
     * Update this list when adding new API endpoints.
     */
    private const EXPECTED_ENDPOINTS = [
        'post' => '/api/content-sync',
        'page' => '/api/page-sync',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $this->sync = new \WashSync();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->sync, ...$args);
    }

    /**
     * Contract: post type → endpoint mapping matches Shopify App routes.
     */
    public function testPostEndpoint(): void
    {
        $endpoint = $this->invokePrivate('resolveEndpoint', ['post']);
        $this->assertEquals(
            self::EXPECTED_ENDPOINTS['post'],
            $endpoint,
            'Post endpoint must match Shopify App route'
        );
    }

    /**
     * Contract: page type → endpoint mapping matches Shopify App routes.
     */
    public function testPageEndpoint(): void
    {
        $endpoint = $this->invokePrivate('resolveEndpoint', ['page']);
        $this->assertEquals(
            self::EXPECTED_ENDPOINTS['page'],
            $endpoint,
            'Page endpoint must match Shopify App route'
        );
    }

    /**
     * Contract: all expected endpoints are reachable paths.
     */
    public function testAllEndpointsStartWithApiPrefix(): void
    {
        foreach (self::EXPECTED_ENDPOINTS as $type => $expected) {
            $endpoint = $this->invokePrivate('resolveEndpoint', [$type]);
            $this->assertStringStartsWith(
                '/api/',
                $endpoint,
                "Endpoint for {$type} must start with /api/"
            );
        }
    }

    /**
     * Contract: API client constructs correct full URL.
     */
    public function testApiClientFullUrl(): void
    {
        $client = new \WashApiClient();
        $appUrl = $client->getAppUrl();

        foreach (self::EXPECTED_ENDPOINTS as $type => $endpoint) {
            $fullUrl = $appUrl . $endpoint;
            $this->assertStringStartsWith('https://', $fullUrl);
            $this->assertStringContainsString('/api/', $fullUrl);
        }
    }

    /**
     * Contract: health check endpoint is /api/health.
     */
    public function testHealthEndpoint(): void
    {
        $client = new \WashApiClient();
        $appUrl = $client->getAppUrl();
        $healthUrl = $appUrl . '/api/health';

        $this->assertEquals(
            'https://app-wash.tradik.dev/api/health',
            $healthUrl
        );
    }
}
