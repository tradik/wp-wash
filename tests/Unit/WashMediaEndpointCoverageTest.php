<?php
/**
 * Additional coverage tests for WashMediaEndpoint.
 *
 * Existing WashMediaEndpointTest already covers the core signature verification
 * and rate-limit paths.  These tests add:
 *   - register() / registerRoute() route registration wiring
 *   - serveMedia(): null path, empty mime type fallback, streamFile stub
 *   - isAttachmentPublic(): all branches (non-attachment type, wrong status,
 *     no parent, parent published, parent non-published)
 *   - checkRateLimit(): counter increment, bucket key derivation
 *   - clientIpForRateLimit(): REMOTE_ADDR absent → 'unknown'
 *   - verifySignature(): stored key empty, api key empty
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-media-endpoint.php';

class WashMediaEndpointCoverageTest extends TestCase
{
    private const API_KEY = 'coverage-test-secret';
    private const ATTACHMENT_ID = 7;
    private const SITE_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // serveMedia() now consults is_multisite() (#161); default to single-site.
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => self::API_KEY,
                default        => $default,
            };
        });
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // register() / registerRoute()
    // ---------------------------------------------------------------

    public function testRegisterAddsRestApiInitAction(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(function ($hook) use (&$hooks) {
            $hooks[] = $hook;
        });

        $endpoint = new \WashMediaEndpoint();
        $endpoint->register();

        $this->assertContains('rest_api_init', $hooks);
    }

    public function testRegisterRouteCallsRegisterRestRoute(): void
    {
        $routeRegistered = false;
        Functions\when('register_rest_route')->alias(function ($namespace, $route) use (&$routeRegistered) {
            $routeRegistered = ($namespace === 'wash/v1' && str_contains($route, 'media'));
        });

        $endpoint = new \WashMediaEndpoint();
        $endpoint->registerRoute();

        $this->assertTrue($routeRegistered);
    }

    // ---------------------------------------------------------------
    // verifySignature() — additional branches
    // ---------------------------------------------------------------

    public function testVerifySignatureRejectsWhenStoredKeyEmpty(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => '', // empty stored key
                default        => $default,
            };
        });
        Functions\when('get_transient')->justReturn(0);
        Functions\when('set_transient')->justReturn(true);

        $endpoint = new \WashMediaEndpoint();
        $request  = $this->buildRequest(['X-Wash-Key' => 'some-key']);

        $result = $endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unauthorized', $result->get_error_code());
    }

    public function testVerifySignatureRejectsEmptyApiKeyHeader(): void
    {
        $endpoint = new \WashMediaEndpoint();
        $request  = $this->buildRequest(['X-Wash-Key' => '']);

        $result = $endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unauthorized', $result->get_error_code());
    }

    public function testVerifySignatureAcceptsValidSignature(): void
    {
        $endpoint = new \WashMediaEndpoint();
        $request  = $this->validSignedRequest();

        $result = $endpoint->verifySignature($request);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // isAttachmentPublic() — via reflection (protected)
    // ---------------------------------------------------------------

    public function testIsAttachmentPublicReturnsFalseForNullPost(): void
    {
        Functions\when('get_post')->justReturn(null);

        $result = $this->callIsAttachmentPublic(99);

        $this->assertFalse($result);
    }

    public function testIsAttachmentPublicReturnsFalseForWrongPostType(): void
    {
        Functions\when('get_post')->justReturn((object) [
            'post_type'   => 'post',  // not 'attachment'
            'post_status' => 'inherit',
            'post_parent' => 0,
        ]);

        $this->assertFalse($this->callIsAttachmentPublic(1));
    }

    public function testIsAttachmentPublicReturnsFalseForWrongStatus(): void
    {
        Functions\when('get_post')->justReturn((object) [
            'post_type'   => 'attachment',
            'post_status' => 'trash',  // not 'inherit'
            'post_parent' => 0,
        ]);

        $this->assertFalse($this->callIsAttachmentPublic(1));
    }

    public function testIsAttachmentPublicReturnsTrueForNoParent(): void
    {
        Functions\when('get_post')->justReturn((object) [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,  // no parent
        ]);

        $this->assertTrue($this->callIsAttachmentPublic(1));
    }

    public function testIsAttachmentPublicReturnsTrueWhenParentPublished(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === 10) {
                return (object) ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 20];
            }
            return (object) ['post_status' => 'publish'];  // parent is published
        });

        $this->assertTrue($this->callIsAttachmentPublic(10));
    }

    public function testIsAttachmentPublicReturnsFalseWhenParentNotPublished(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === 10) {
                return (object) ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 20];
            }
            return (object) ['post_status' => 'private']; // parent not published
        });

        $this->assertFalse($this->callIsAttachmentPublic(10));
    }

    public function testIsAttachmentPublicReturnsFalseWhenParentNull(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id === 5) {
                return (object) ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 20];
            }
            return null;  // parent not found
        });

        $this->assertFalse($this->callIsAttachmentPublic(5));
    }

    // ---------------------------------------------------------------
    // serveMedia() — additional branches
    // ---------------------------------------------------------------

    public function testServeMediaRejectsWithNotFoundWhenAttachmentNotPublic(): void
    {
        Functions\when('get_post')->justReturn(null);

        $endpoint = new \WashMediaEndpoint();
        $request  = $this->validSignedRequest();
        $result   = $endpoint->serveMedia($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function testServeMediaRejectsWhenFilePathIsNull(): void
    {
        Functions\when('get_post')->justReturn((object) [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
        ]);
        Functions\when('get_attached_file')->justReturn(null);

        $endpoint = new \WashMediaEndpoint();
        $result   = $endpoint->serveMedia($this->validSignedRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    public function testServeMediaUsesOctetStreamForEmptyMime(): void
    {
        // Create a real temp file so file_exists() + is_readable() pass.
        $tmpFile = tempnam(sys_get_temp_dir(), 'wash_test_');
        file_put_contents($tmpFile, 'fake image data');

        Functions\when('get_post')->justReturn((object) [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
        ]);
        Functions\when('get_attached_file')->justReturn($tmpFile);
        Functions\when('get_post_mime_type')->justReturn('');  // empty mime

        // Subclass to intercept streamFile() instead of actually exiting.
        $endpoint = new class extends \WashMediaEndpoint {
            public ?string $capturedMime = null;

            protected function streamFile($path, $mime, $id): void
            {
                $this->capturedMime = $mime;
                // Do not call exit
            }
        };

        $endpoint->serveMedia($this->validSignedRequest());

        @unlink($tmpFile);

        $this->assertSame('application/octet-stream', $endpoint->capturedMime);
    }

    public function testServeMediaSwitchesToRequestedBlogOnMultisite(): void
    {
        // MED-19 (#161): on multisite, a subsite attachment (site=2) must be read
        // after switch_to_blog(2), else the network-root blob is served.
        $switchedTo = null;
        $restored   = false;
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('switch_to_blog')->alias(function ($blogId) use (&$switchedTo) {
            $switchedTo = (int) $blogId;
            return true;
        });
        Functions\when('restore_current_blog')->alias(function () use (&$restored) {
            $restored = true;
            return true;
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'wash_test_');
        file_put_contents($tmpFile, 'subsite image');
        Functions\when('get_post')->justReturn((object) [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
        ]);
        Functions\when('get_attached_file')->justReturn($tmpFile);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');

        $endpoint = new class extends \WashMediaEndpoint {
            protected function streamFile($path, $mime, $id): void
            {
                // Do not exit.
            }
        };

        $endpoint->serveMedia($this->validSignedRequest([], ['site' => 2]));
        @unlink($tmpFile);

        $this->assertSame(2, $switchedTo);
        $this->assertTrue($restored);
    }

    public function testServeMediaPassesRealMimeToStreamFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'wash_test_');
        file_put_contents($tmpFile, 'fake jpeg data');

        Functions\when('get_post')->justReturn((object) [
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => 0,
        ]);
        Functions\when('get_attached_file')->justReturn($tmpFile);
        Functions\when('get_post_mime_type')->justReturn('image/jpeg');

        $endpoint = new class extends \WashMediaEndpoint {
            public ?string $capturedMime = null;
            public ?string $capturedPath = null;

            protected function streamFile($path, $mime, $id): void
            {
                $this->capturedMime = $mime;
                $this->capturedPath = $path;
            }
        };

        $endpoint->serveMedia($this->validSignedRequest());

        @unlink($tmpFile);

        $this->assertSame('image/jpeg', $endpoint->capturedMime);
        $this->assertSame($tmpFile, $endpoint->capturedPath);
    }

    // ---------------------------------------------------------------
    // checkRateLimit() and clientIpForRateLimit() — via reflection
    // ---------------------------------------------------------------

    public function testCheckRateLimitIncrementsBucketCounter(): void
    {
        $storedCount = 5;
        $savedCount  = null;
        Functions\when('get_transient')->justReturn($storedCount);
        Functions\when('set_transient')->alias(function ($key, $value) use (&$savedCount) {
            $savedCount = $value;
        });

        $endpoint = new \WashMediaEndpoint();
        $ref      = new \ReflectionMethod($endpoint, 'checkRateLimit');
        $ref->setAccessible(true);
        $result   = $ref->invoke($endpoint);

        $this->assertTrue($result);
        $this->assertSame(6, $savedCount, 'Counter should be incremented by 1');
    }

    public function testCheckRateLimitReturnsFalseAtExactLimit(): void
    {
        Functions\when('get_transient')->justReturn(100); // exactly at limit
        Functions\when('set_transient')->justReturn(true);

        $endpoint = new \WashMediaEndpoint();
        $ref      = new \ReflectionMethod($endpoint, 'checkRateLimit');
        $ref->setAccessible(true);
        $result   = $ref->invoke($endpoint);

        $this->assertFalse($result);
    }

    public function testClientIpForRateLimitReturnsUnknownWhenNoRemoteAddr(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        $endpoint = new \WashMediaEndpoint();
        $ref      = new \ReflectionMethod($endpoint, 'clientIpForRateLimit');
        $ref->setAccessible(true);
        $ip = $ref->invoke($endpoint);

        $this->assertSame('unknown', $ip);
    }

    public function testClientIpForRateLimitReturnsRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $endpoint = new \WashMediaEndpoint();
        $ref      = new \ReflectionMethod($endpoint, 'clientIpForRateLimit');
        $ref->setAccessible(true);
        $ip = $ref->invoke($endpoint);

        $this->assertSame('192.168.1.1', $ip);
    }

    // ---------------------------------------------------------------
    // verifySignature() — WP_Error branches for coverage
    // ---------------------------------------------------------------

    public function testVerifySignatureRejectsRateLimit(): void
    {
        Functions\when('get_transient')->justReturn(100); // at limit
        Functions\when('set_transient')->justReturn(true);

        $endpoint = new \WashMediaEndpoint();
        $result = $endpoint->verifySignature($this->validSignedRequest());

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('rate_limited', $result->get_error_code());
    }

    public function testVerifySignatureRejectsNonNumericTimestamp(): void
    {
        $endpoint = new \WashMediaEndpoint();
        $request  = $this->validSignedRequest(['X-Wash-Timestamp' => 'not-a-number']);

        $result = $endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bad_timestamp', $result->get_error_code());
    }

    public function testVerifySignatureRejectsStaleTimestamp(): void
    {
        $endpoint = new \WashMediaEndpoint();
        $ts       = (string) (time() - 120); // 2 minutes old
        $request  = $this->validSignedRequest(['X-Wash-Timestamp' => $ts]);

        $result = $endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('stale_timestamp', $result->get_error_code());
    }

    public function testVerifySignatureRejectsBadSignature(): void
    {
        $endpoint = new \WashMediaEndpoint();
        $request  = $this->validSignedRequest(['X-Wash-Signature' => str_repeat('a', 64)]);

        $result = $endpoint->verifySignature($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bad_signature', $result->get_error_code());
    }

    // ---------------------------------------------------------------
    // registerRoute() — validate_callback closures
    // ---------------------------------------------------------------

    public function testRegisterRouteIdValidateCallbackAcceptsPositiveInt(): void
    {
        $capturedArgs = null;
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
        });

        $endpoint = new \WashMediaEndpoint();
        $endpoint->registerRoute();

        $this->assertNotNull($capturedArgs);
        $validateId = $capturedArgs['args']['id']['validate_callback'];
        $this->assertTrue($validateId('42'));
        $this->assertFalse($validateId('0'));
        $this->assertFalse($validateId('abc'));
    }

    public function testRegisterRouteSiteValidateCallbackAcceptsPositiveInt(): void
    {
        $capturedArgs = null;
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$capturedArgs) {
            $capturedArgs = $args;
        });

        $endpoint = new \WashMediaEndpoint();
        $endpoint->registerRoute();

        $validateSite = $capturedArgs['args']['site']['validate_callback'];
        $this->assertTrue($validateSite('1'));
        $this->assertFalse($validateSite('0'));
        $this->assertFalse($validateSite('-1'));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function callIsAttachmentPublic(int $id): bool
    {
        $endpoint = new \WashMediaEndpoint();
        $ref      = new \ReflectionMethod($endpoint, 'isAttachmentPublic');
        $ref->setAccessible(true);
        return $ref->invoke($endpoint, $id);
    }

    private function validSignedRequest(array $overrides = [], array $paramOverrides = []): \WP_REST_Request
    {
        $ts  = (string) time();
        $sig = hash_hmac('sha256', $ts . ':' . self::ATTACHMENT_ID . ':' . self::SITE_ID, self::API_KEY);

        $headers = array_merge([
            'X-Wash-Key'       => self::API_KEY,
            'X-Wash-Timestamp' => $ts,
            'X-Wash-Signature' => $sig,
        ], $overrides);

        $params = array_merge(['id' => self::ATTACHMENT_ID, 'site' => self::SITE_ID], $paramOverrides);

        return new class($params, $headers) extends \WP_REST_Request {
            private array $headers;
            private array $params;

            public function __construct(array $params, array $headers)
            {
                parent::__construct([]);
                $this->headers = $headers;
                $this->params  = $params;
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

    private function buildRequest(array $headers = [], array $params = []): \WP_REST_Request
    {
        $params = array_merge(['id' => self::ATTACHMENT_ID, 'site' => self::SITE_ID], $params);

        return new class($params, $headers) extends \WP_REST_Request {
            private array $headers;
            private array $params;

            public function __construct(array $params, array $headers)
            {
                parent::__construct([]);
                $this->headers = $headers;
                $this->params  = $params;
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
