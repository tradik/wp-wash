<?php
/**
 * Tests for WashMediaEndpoint — signed media REST endpoint introduced in 1.24.0.
 *
 * Covers:
 *   - HMAC signature verification (constant-time, correct algorithm, correct payload shape)
 *   - 60s anti-replay window for X-Wash-Timestamp
 *   - API-key constant-time compare
 *   - Public-attachment guard (only inherit + published-parent served)
 *   - Per-IP rate limiting (100/min/IP)
 *
 * Background: the endpoint is the worker-side fix for the May-2026 hilo.com
 * incident where /wp-content/uploads/ started returning 401 to anonymous
 * fetches. Tests assert no auth bypass is possible because this is the
 * surface that replaces direct uploads access for the queue worker.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-media-endpoint.php';

class WashMediaEndpointTest extends TestCase
{
    private const API_KEY = 'shared-hmac-secret-abcdef';
    private const ATTACHMENT_ID = 42;
    private const SITE_ID = 1;

    private \WashMediaEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => self::API_KEY,
                default => $default,
            };
        });
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        $this->endpoint = new \WashMediaEndpoint();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Signature verification
    // ---------------------------------------------------------------

    public function testRejectsMissingApiKey(): void
    {
        $request = $this->signedRequest(['X-Wash-Key' => null]);

        $result = $this->endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unauthorized', $result->get_error_code());
    }

    public function testRejectsWrongApiKey(): void
    {
        $request = $this->signedRequest(['X-Wash-Key' => 'attacker-key']);

        $result = $this->endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unauthorized', $result->get_error_code());
    }

    public function testRejectsNonNumericTimestamp(): void
    {
        $request = $this->signedRequest(['X-Wash-Timestamp' => 'now']);

        $result = $this->endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bad_timestamp', $result->get_error_code());
    }

    public function testRejectsStaleTimestamp(): void
    {
        $request = $this->signedRequest([
            'X-Wash-Timestamp' => (string) (time() - 120), // 2 minutes ago
        ]);

        $result = $this->endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('stale_timestamp', $result->get_error_code());
    }

    public function testRejectsFutureTimestampOutsideWindow(): void
    {
        $request = $this->signedRequest([
            'X-Wash-Timestamp' => (string) (time() + 120),
        ]);

        $result = $this->endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('stale_timestamp', $result->get_error_code());
    }

    public function testRejectsWrongSignature(): void
    {
        $request = $this->signedRequest(['X-Wash-Signature' => str_repeat('0', 64)]);

        $result = $this->endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bad_signature', $result->get_error_code());
    }

    public function testRejectsSignatureFromWrongAttachmentId(): void
    {
        // Attacker signs for attachment 1 but requests attachment 42 —
        // signature mismatch must reject.
        $ts = (string) time();
        $wrongSig = hash_hmac('sha256', $ts . ':1:1', self::API_KEY);
        $request = $this->signedRequest([
            'X-Wash-Timestamp' => $ts,
            'X-Wash-Signature' => $wrongSig,
        ]);

        $result = $this->endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bad_signature', $result->get_error_code());
    }

    public function testAcceptsValidSignature(): void
    {
        $request = $this->signedRequest();

        $result = $this->endpoint->verifySignature($request);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Public-attachment guard
    // ---------------------------------------------------------------

    public function testServeMediaRejectsNonAttachmentPost(): void
    {
        Functions\when('get_post')->justReturn((object) [
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_parent' => 0,
        ]);

        $result = $this->endpoint->serveMedia($this->signedRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function testServeMediaRejectsDraftParentAttachment(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === self::ATTACHMENT_ID) {
                return (object) ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 99];
            }
            return (object) ['post_status' => 'draft'];
        });

        $result = $this->endpoint->serveMedia($this->signedRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function testServeMediaRejectsMissingPost(): void
    {
        Functions\when('get_post')->justReturn(null);

        $result = $this->endpoint->serveMedia($this->signedRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function testServeMediaRejectsMissingFileOnDisk(): void
    {
        Functions\when('get_post')->justReturn((object) [
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 0,
        ]);
        Functions\when('get_attached_file')->justReturn('/nonexistent/path/file.jpg');

        $result = $this->endpoint->serveMedia($this->signedRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    // ---------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------

    public function testRateLimitRejectsOver100PerMinute(): void
    {
        Functions\when('get_transient')->justReturn(100); // at the limit already

        $result = $this->endpoint->verifySignature($this->signedRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('rate_limited', $result->get_error_code());
    }

    public function testRateLimitAllowsUnderThreshold(): void
    {
        Functions\when('get_transient')->justReturn(99);

        $result = $this->endpoint->verifySignature($this->signedRequest());

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Build a request with valid HMAC signature by default; overrides let
     * each test mutate one header to assert the rejection.
     */
    private function signedRequest(array $overrides = []): \WP_REST_Request
    {
        $ts = (string) time();
        $sig = hash_hmac(
            'sha256',
            $ts . ':' . self::ATTACHMENT_ID . ':' . self::SITE_ID,
            self::API_KEY
        );
        $headers = array_merge([
            'X-Wash-Key' => self::API_KEY,
            'X-Wash-Timestamp' => $ts,
            'X-Wash-Signature' => $sig,
        ], $overrides);
        $params = ['id' => self::ATTACHMENT_ID, 'site' => self::SITE_ID];

        return new class($params, $headers) extends \WP_REST_Request {
            private array $headers;
            private array $params;

            public function __construct(array $params, array $headers)
            {
                parent::__construct([]);
                $this->headers = $headers;
                $this->params = $params;
            }

            public function get_header(string $key): ?string
            {
                return $this->headers[$key] ?? null;
            }

            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }
        };
    }
}
