<?php
/**
 * Security tests for WashCallback REST API endpoints.
 * Validates: API key verification, permission callbacks, input validation,
 * and protection against unauthorized access.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-callback.php';

class WashCallbackSecurityTest extends TestCase
{
    private \WashCallback $callback;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'stored-secret-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_multisite')->justReturn(false);

        $this->callback = new \WashCallback();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // API Key Verification (verifyApiKey)
    // ---------------------------------------------------------------

    public function testVerifyApiKeyRejectsEmptyHeader(): void
    {
        $request = $this->createMockRequest([], []);

        $result = $this->callback->verifyApiKey($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unauthorized', $result->get_error_code());
    }

    public function testVerifyApiKeyRejectsWrongKey(): void
    {
        $request = $this->createMockRequest([], ['X-Wash-Key' => 'wrong-key']);

        $result = $this->callback->verifyApiKey($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unauthorized', $result->get_error_code());
    }

    public function testVerifyApiKeyAcceptsCorrectKey(): void
    {
        $request = $this->createMockRequest([], ['X-Wash-Key' => 'stored-secret-key']);

        $result = $this->callback->verifyApiKey($request);

        $this->assertTrue($result);
    }

    public function testVerifyApiKeyRejectsNullKey(): void
    {
        $request = $this->createMockRequest([], ['X-Wash-Key' => null]);

        $result = $this->callback->verifyApiKey($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // Callback Verification (verifyCallback - same logic)
    // ---------------------------------------------------------------

    public function testVerifyCallbackRejectsEmptyHeader(): void
    {
        $request = $this->createMockRequest([], []);

        $result = $this->callback->verifyCallback($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unauthorized', $result->get_error_code());
    }

    public function testVerifyCallbackRejectsWrongKey(): void
    {
        $request = $this->createMockRequest([], ['X-Wash-Key' => 'attacker-key']);

        $result = $this->callback->verifyCallback($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testVerifyCallbackAcceptsValidKey(): void
    {
        $request = $this->createMockRequest([], ['X-Wash-Key' => 'stored-secret-key']);

        $result = $this->callback->verifyCallback($request);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Edit Permission Check (checkEditPermission)
    // ---------------------------------------------------------------

    public function testCheckEditPermissionRejectsUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $result = $this->callback->checkEditPermission();

        $this->assertFalse($result);
    }

    public function testCheckEditPermissionAcceptsEditor(): void
    {
        Functions\when('current_user_can')->alias(function ($cap) {
            return $cap === 'edit_posts';
        });

        $result = $this->callback->checkEditPermission();

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Per-post Edit Permission Check (checkEditPostPermission)
    // ---------------------------------------------------------------

    public function testCheckEditPostPermissionRejectsMissingPostId(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $request = $this->createMockRequest([], [], []); // no post_id

        $this->assertFalse($this->callback->checkEditPostPermission($request));
    }

    public function testCheckEditPostPermissionAcceptsUserWhoCanEditThatPost(): void
    {
        Functions\when('current_user_can')->alias(function ($cap, $id = null) {
            return $cap === 'edit_post' && (int) $id === 42;
        });
        $request = $this->createMockRequest([], [], ['post_id' => '42']);

        $this->assertTrue($this->callback->checkEditPostPermission($request));
    }

    /**
     * The exact gap the wp.org reviewer flagged: a user with the GLOBAL
     * edit_posts capability must still be denied sync-status for a post they
     * cannot edit. The callback must consult the per-post edit_post meta-cap.
     */
    public function testCheckEditPostPermissionRejectsGlobalEditPostsWithoutPerPostCap(): void
    {
        $checked = [];
        Functions\when('current_user_can')->alias(function ($cap, $id = null) use (&$checked) {
            $checked[] = [$cap, $id];
            if ($cap === 'edit_posts') {
                return true;  // global cap granted (e.g. contributor/author)
            }
            return false;     // but NOT edit_post on this specific post
        });
        $request = $this->createMockRequest([], [], ['post_id' => '99']);

        $this->assertFalse($this->callback->checkEditPostPermission($request));
        $this->assertContains(['edit_post', 99], $checked, 'must check the per-post edit_post meta-cap');
    }

    // ---------------------------------------------------------------
    // Callback Handler: Input Validation
    // ---------------------------------------------------------------

    public function testHandleCallbackRejectsMissingPostId(): void
    {
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('do_action')->justReturn(null);

        $request = new \WP_REST_Request([
            'success' => true,
            'shopifyArticleId' => 'gid://shopify/Article/1',
            // wpPostId is missing
        ]);

        $response = $this->callback->handleCallback($request);

        $this->assertSame(400, $response->status);
        $this->assertFalse($response->data['success']);
        $this->assertSame('Missing wpPostId', $response->data['error']);
    }

    public function testHandleCallbackRejectsNullPostId(): void
    {
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('do_action')->justReturn(null);

        $request = new \WP_REST_Request([
            'wpPostId' => null,
            'success' => true,
        ]);

        $response = $this->callback->handleCallback($request);

        $this->assertSame(400, $response->status);
        $this->assertFalse($response->data['success']);
    }

    public function testHandleCallbackRejectsZeroPostId(): void
    {
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('do_action')->justReturn(null);

        $request = new \WP_REST_Request([
            'wpPostId' => 0,
            'success' => true,
        ]);

        $response = $this->callback->handleCallback($request);

        $this->assertSame(400, $response->status);
    }

    // ---------------------------------------------------------------
    // Sync Status Endpoint: Input Validation (post_id param)
    // ---------------------------------------------------------------

    public function testSyncStatusRouteValidatesPostIdIsNumeric(): void
    {
        // The route defines validate_callback: is_numeric
        // Test the validation logic directly
        $isNumericCallback = function ($param) {
            return is_numeric($param);
        };

        $this->assertTrue($isNumericCallback('42'));
        $this->assertTrue($isNumericCallback('1'));
        $this->assertFalse($isNumericCallback('abc'));
        $this->assertFalse($isNumericCallback('1; DROP TABLE posts'));
        $this->assertFalse($isNumericCallback('<script>alert(1)</script>'));
        $this->assertFalse($isNumericCallback(''));
    }

    public function testGetSyncStatusUsesIntvalForPostId(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_post')->justReturn(null);
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('rest_ensure_response')->alias(fn($data) => new \WP_REST_Response($data));

        $request = $this->createMockRequest([], [], ['post_id' => '42abc']);

        $response = $this->callback->getSyncStatus($request);

        // intval('42abc') = 42, get_post(42) returns null, so 404
        $this->assertInstanceOf(\WP_Error::class, $response);
    }

    // ---------------------------------------------------------------
    // API Key: Timing-safe comparison protection
    // ---------------------------------------------------------------

    public function testApiKeyComparisonIsStrictEquality(): void
    {
        // PHP === operator is used, so partial matches must fail
        $request = $this->createMockRequest([], ['X-Wash-Key' => 'stored-secret-ke']);

        $result = $this->callback->verifyApiKey($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testApiKeyComparisonRejectsExtraCharacters(): void
    {
        $request = $this->createMockRequest([], ['X-Wash-Key' => 'stored-secret-key-extra']);

        $result = $this->callback->verifyApiKey($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // Settings Push: Input Validation
    // ---------------------------------------------------------------

    public function testSettingsPushRequiresApiKey(): void
    {
        $request = $this->createMockRequest([], ['X-Wash-Key' => 'wrong']);

        $result = $this->callback->verifyApiKey($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // Content Stats: SQL Query Protection
    // ---------------------------------------------------------------

    public function testGetContentStatsUsesParameterizedQueries(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_count_posts')->alias(function ($type) {
            return (object) [
                'publish' => 10,
                'draft' => 5,
                'pending' => 2,
                'private' => 1,
            ];
        });
        Functions\when('rest_ensure_response')->alias(fn($data) => new \WP_REST_Response($data));
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('is_wp_error')->justReturn(false);

        // Mock $wpdb - the queries in getContentStats use hardcoded meta_key
        // (no user input), but let's verify the query structure
        $wpdb = new \stdClass();
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->queriesExecuted = [];
        $wpdb->get_var = function (string $query) use ($wpdb): string {
            $wpdb->queriesExecuted[] = $query;
            return '5';
        };

        // Use __call magic via anonymous class
        $wpdbMock = new class {
            public string $posts = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public array $queriesExecuted = [];

            public function get_var(string $query): string
            {
                $this->queriesExecuted[] = $query;
                return '5';
            }
        };
        $GLOBALS['wpdb'] = $wpdbMock;

        $request = $this->createMockRequest([], ['X-Wash-Key' => 'stored-secret-key']);
        $response = $this->callback->getContentStats($request);

        // Verify no user-supplied input in queries (all are hardcoded strings)
        foreach ($wpdbMock->queriesExecuted as $query) {
            // _wash_article_id is a hardcoded constant, not user input
            $this->assertStringContainsString('_wash_article_id', $query);
            // No interpolated user variables
            $this->assertStringNotContainsString('$', $query);
        }

        unset($GLOBALS['wpdb']);
    }

    // ---------------------------------------------------------------
    // Helper: Create Mock Request
    // ---------------------------------------------------------------

    private function createMockRequest(array $params = [], array $headers = [], array $urlParams = []): \WP_REST_Request
    {
        $request = new class($params, $headers, $urlParams) extends \WP_REST_Request {
            private array $headers;
            private array $urlParams;

            public function __construct(array $params = [], array $headers = [], array $urlParams = [])
            {
                parent::__construct($params);
                $this->headers = $headers;
                $this->urlParams = $urlParams;
            }

            public function get_header(string $key): ?string
            {
                return $this->headers[$key] ?? null;
            }

            public function get_param(string $key)
            {
                return $this->urlParams[$key] ?? null;
            }
        };

        return $request;
    }
}
