<?php
/**
 * Coverage tests for WashCallback: REST handlers getShopifyProducts,
 * getShopifyCollections, getContentStats, handleSettingsPush, getSyncStatus
 * branches, and register/registerRoutes coverage.
 *
 * Strategy: WashApiClient is already loaded, so we cannot use Mockery overload:.
 * Instead we mock the underlying wp_remote_* WordPress functions so the real
 * WashApiClient follows the desired path.
 *
 * @package Wash\Tests\Unit
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-callback.php';

class WashCallbackCoverageTest extends TestCase
{
    private \WashCallback $callback;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // restoreSettings() sanitizes every pushed value (MED-13).
        Functions\when('sanitize_text_field')->returnArg();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => 'test-api-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
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
    // Helper: mock request
    // ---------------------------------------------------------------

    private function createMockRequest(
        array $params = [],
        array $headers = [],
        array $urlParams = []
    ): \WP_REST_Request {
        return new class($params, $headers, $urlParams) extends \WP_REST_Request {
            private array $hdrs;
            private array $urlP;
            private array $bodyP;

            public function __construct(array $params, array $headers, array $urlParams)
            {
                parent::__construct($params);
                $this->hdrs  = $headers;
                $this->urlP  = $urlParams;
                $this->bodyP = $params;
            }

            public function get_header(string $key): ?string
            {
                return $this->hdrs[$key] ?? null;
            }

            public function get_param(string $key)
            {
                return $this->urlP[$key] ?? $this->bodyP[$key] ?? null;
            }
        };
    }

    // ---------------------------------------------------------------
    // Helpers: build WP HTTP response arrays
    // ---------------------------------------------------------------

    private function makeHttpResponse(string $body, int $code = 200): array
    {
        return [
            'response' => ['code' => $code, 'message' => 'OK'],
            'body'     => $body,
            'headers'  => [],
        ];
    }

    // ---------------------------------------------------------------
    // getShopifyProducts — not configured (empty api key)
    // ---------------------------------------------------------------

    public function testGetShopifyProductsNotConfigured(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => '',   // not configured
                'wash_app_url'   => '',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        // Reinstantiate with empty api key
        $callback = new \WashCallback();
        $request  = $this->createMockRequest([], [], []);
        $result   = $callback->getShopifyProducts($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_configured', $result->get_error_code());
    }

    // ---------------------------------------------------------------
    // getShopifyProducts — configured, API returns success (no handle)
    // ---------------------------------------------------------------

    public function testGetShopifyProductsSuccessNoHandle(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_remote_get')->justReturn(
            $this->makeHttpResponse(json_encode(['products' => []]))
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode(['products' => []]));

        $request = $this->createMockRequest([], [], []);
        $result  = $this->callback->getShopifyProducts($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertArrayHasKey('products', $result->data);
    }

    // ---------------------------------------------------------------
    // getShopifyProducts — configured, success with handle param
    // ---------------------------------------------------------------

    public function testGetShopifyProductsSuccessWithHandle(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        $responseData = json_encode(['products' => [['id' => 1, 'handle' => 'my-product']]]);
        Functions\when('wp_remote_get')->justReturn($this->makeHttpResponse($responseData));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($responseData);

        $request = $this->createMockRequest([], [], ['handle' => 'my-product']);
        $result  = $this->callback->getShopifyProducts($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertArrayHasKey('products', $result->data);
    }

    // ---------------------------------------------------------------
    // getShopifyProducts — API returns HTTP error
    // ---------------------------------------------------------------

    public function testGetShopifyProductsApiHttpError(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_remote_get')->justReturn($this->makeHttpResponse('{"error":"not found"}', 404));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"error":"not found"}');

        $request = $this->createMockRequest([], [], []);
        $result  = $this->callback->getShopifyProducts($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // getShopifyProducts — wp_remote_get returns WP_Error
    // ---------------------------------------------------------------

    public function testGetShopifyProductsWpRemoteError(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_error', 'Connection failed'));

        $request = $this->createMockRequest([], [], []);
        $result  = $this->callback->getShopifyProducts($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // getShopifyCollections — not configured
    // ---------------------------------------------------------------

    public function testGetShopifyCollectionsNotConfigured(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => '',
                'wash_app_url'   => '',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $callback = new \WashCallback();
        $request  = $this->createMockRequest([], [], []);
        $result   = $callback->getShopifyCollections($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_configured', $result->get_error_code());
    }

    // ---------------------------------------------------------------
    // getShopifyCollections — success with handle
    // ---------------------------------------------------------------

    public function testGetShopifyCollectionsSuccessWithHandle(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        $responseData = json_encode(['collections' => [['id' => 1, 'handle' => 'summer']]]);
        Functions\when('wp_remote_get')->justReturn($this->makeHttpResponse($responseData));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($responseData);

        $request = $this->createMockRequest([], [], ['handle' => 'summer']);
        $result  = $this->callback->getShopifyCollections($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertArrayHasKey('collections', $result->data);
    }

    // ---------------------------------------------------------------
    // getShopifyCollections — API error (wp_remote_get returns WP_Error)
    // ---------------------------------------------------------------

    public function testGetShopifyCollectionsApiError(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'Request timeout'));

        $request = $this->createMockRequest([], [], []);
        $result  = $this->callback->getShopifyCollections($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    // ---------------------------------------------------------------
    // getShopifyCollections — success without handle
    // ---------------------------------------------------------------

    public function testGetShopifyCollectionsSuccessNoHandle(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        $responseData = json_encode(['collections' => []]);
        Functions\when('wp_remote_get')->justReturn($this->makeHttpResponse($responseData));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn($responseData);

        $request = $this->createMockRequest([], [], []);
        $result  = $this->callback->getShopifyCollections($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
    }

    // ---------------------------------------------------------------
    // getContentStats — full happy path with wpdb mock
    // ---------------------------------------------------------------

    public function testGetContentStatsReturnsExpectedShape(): void
    {
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('wp_count_posts')->alias(function ($type) {
            return (object) [
                'publish' => 5,
                'draft'   => 2,
                'pending' => 1,
                'private' => 0,
            ];
        });

        $wpdbMock = new class {
            public string $posts   = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public function get_var(string $q): string { return '3'; }
        };
        $GLOBALS['wpdb'] = $wpdbMock;

        $request = $this->createMockRequest([], ['X-Wash-Key' => 'test-api-key'], []);
        $result  = $this->callback->getContentStats($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $data = $result->data;
        $this->assertArrayHasKey('posts', $data);
        $this->assertArrayHasKey('pages', $data);
        $this->assertArrayHasKey('languages', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('pluginVersion', $data);
        $this->assertSame(8, $data['posts']['total']); // 5+2+1+0
        $this->assertSame(3, $data['posts']['synced']);
        $this->assertSame(WASH_VERSION, $data['pluginVersion']);

        unset($GLOBALS['wpdb']);
    }

    // ---------------------------------------------------------------
    // getContentStats — WASH_VERSION defined
    // ---------------------------------------------------------------

    public function testGetContentStatsPluginVersionDefined(): void
    {
        Functions\when('wp_count_posts')->justReturn((object) [
            'publish' => 1, 'draft' => 0, 'pending' => 0, 'private' => 0,
        ]);
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('is_wp_error')->alias(fn($v) => false);
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_locale')->justReturn('en_US');

        $wpdbMock = new class {
            public string $posts   = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public function get_var(string $q): string { return '0'; }
        };
        $GLOBALS['wpdb'] = $wpdbMock;

        $request = $this->createMockRequest([], [], []);
        $result  = $this->callback->getContentStats($request);

        $this->assertSame(WASH_VERSION, $result->data['pluginVersion']);

        unset($GLOBALS['wpdb']);
    }

    // ---------------------------------------------------------------
    // handleSettingsPush — empty body → 400
    // ---------------------------------------------------------------

    public function testHandleSettingsPushEmptyBodyReturns400(): void
    {
        $request = new \WP_REST_Request([]);
        $result  = $this->callback->handleSettingsPush($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(400, $result->status);
        $this->assertFalse($result->data['success']);
    }

    // ---------------------------------------------------------------
    // handleSettingsPush — success (real restoreSettings via wp_json_encode mock)
    // ---------------------------------------------------------------

    public function testHandleSettingsPushSuccess(): void
    {
        Functions\when('do_action')->justReturn(null);
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));

        $request = new \WP_REST_Request([
            'syncOnPublish' => '1',
            'syncMetafields' => '1',
        ]);
        $result = $this->callback->handleSettingsPush($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(200, $result->status);
        $this->assertTrue($result->data['success']);
    }

    // ---------------------------------------------------------------
    // handleSettingsPush — exception → 500
    //
    // restoreSettings accesses $GLOBALS and calls update_option.
    // We inject a WashMetafields stub to make metaFieldConfig branch
    // NOT throw, then cause do_action to throw.
    // ---------------------------------------------------------------

    public function testHandleSettingsPushExceptionReturns500(): void
    {
        Functions\when('do_action')->alias(function ($hook) {
            if ($hook === 'wash_settings_pushed') {
                throw new \Exception('Hook failure');
            }
        });
        Functions\when('update_option')->justReturn(true);
        Functions\when('wp_json_encode')->alias(fn($d) => json_encode($d));

        $request = new \WP_REST_Request(['syncOnPublish' => '1']);
        $result  = $this->callback->handleSettingsPush($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(500, $result->status);
        $this->assertFalse($result->data['success']);
        $this->assertSame('Hook failure', $result->data['error']);
    }

    // ---------------------------------------------------------------
    // getSyncStatus — post not found → WP_Error 404
    // ---------------------------------------------------------------

    public function testGetSyncStatusPostNotFound(): void
    {
        Functions\when('get_post')->justReturn(null);
        Functions\when('__')->alias(fn($text) => $text);

        $request = $this->createMockRequest([], [], ['post_id' => '999']);
        $result  = $this->callback->getSyncStatus($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
    }

    // ---------------------------------------------------------------
    // getSyncStatus — not synced (no meta)
    // ---------------------------------------------------------------

    public function testGetSyncStatusNotSynced(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 7, 'post_type' => 'post']);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('__')->alias(fn($text) => $text);

        $request = $this->createMockRequest([], [], ['post_id' => '7']);
        $result  = $this->callback->getSyncStatus($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame('not_synced', $result->data['status']);
        $this->assertNull($result->data['shopifyArticleId']);
    }

    // ---------------------------------------------------------------
    // getSyncStatus — synced (has shopify article id)
    // ---------------------------------------------------------------

    public function testGetSyncStatusSynced(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 5, 'post_type' => 'post']);
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_wash_article_id'  => 'gid://shopify/Article/999',
                '_wash_blog_id'     => 'gid://shopify/Blog/1',
                '_wash_blog_handle' => 'news',
                '_wash_handle'      => 'test-post',
                '_wash_shopify_url' => 'https://test.myshopify.com/blogs/news/test-post',
                '_wash_synced_at'   => '2026-01-01 00:00:00',
                '_wash_sync_status' => '',
                '_wash_job_id'      => '',
                '_wash_queued_at'   => '',
                '_wash_sync_error'  => '',
                default             => '',
            };
        });
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('__')->alias(fn($text) => $text);

        $request = $this->createMockRequest([], [], ['post_id' => '5']);
        $result  = $this->callback->getSyncStatus($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame('synced', $result->data['status']);
        $this->assertSame('gid://shopify/Article/999', $result->data['shopifyArticleId']);
        $this->assertSame('news', $result->data['shopifyBlogHandle']);
    }

    // ---------------------------------------------------------------
    // getSyncStatus — queued (not timed out)
    // ---------------------------------------------------------------

    public function testGetSyncStatusQueued(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 10, 'post_type' => 'post']);
        $recentTime = date('Y-m-d H:i:s', time() - 10);
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) use ($recentTime) {
            return match ($key) {
                '_wash_sync_status' => 'queued',
                '_wash_job_id'      => 'job-abc-123',
                '_wash_queued_at'   => $recentTime,
                '_wash_sync_error'  => '',
                '_wash_article_id'  => '',
                default             => '',
            };
        });
        Functions\when('current_time')->alias(fn($f) => $f === 'timestamp' ? time() : date('Y-m-d H:i:s'));
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('__')->alias(fn($text) => $text);

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        $request = $this->createMockRequest([], [], ['post_id' => '10']);
        $result  = $this->callback->getSyncStatus($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame('queued', $result->data['status']);
        $this->assertSame('job-abc-123', $result->data['jobId']);
    }

    // ---------------------------------------------------------------
    // getSyncStatus — queued but timed out → 'timeout'
    // ---------------------------------------------------------------

    public function testGetSyncStatusTimeout(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 11, 'post_type' => 'post']);
        $oldTime = date('Y-m-d H:i:s', time() - 600); // 10 min ago
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) use ($oldTime) {
            return match ($key) {
                '_wash_sync_status' => 'queued',
                '_wash_job_id'      => 'job-stale',
                '_wash_queued_at'   => $oldTime,
                '_wash_sync_error'  => '',
                '_wash_article_id'  => '',
                default             => '',
            };
        });
        Functions\when('current_time')->alias(fn($f) => $f === 'timestamp' ? time() : date('Y-m-d H:i:s'));
        // #176: a GET must NOT mutate — flag any write attempt via a shared spy.
        $mutated = false;
        $spyWrite = function () use (&$mutated) {
            $mutated = true;
            return true;
        };
        Functions\when('delete_post_meta')->alias($spyWrite);
        Functions\when('update_post_meta')->alias($spyWrite);
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('__')->alias(fn($text) => $text);

        $request = $this->createMockRequest([], [], ['post_id' => '11']);
        $result  = $this->callback->getSyncStatus($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame('timeout', $result->data['status']);
        // The status is still reported as a timeout, but no post meta was written.
        $this->assertFalse($mutated, 'getSyncStatus (a GET) must not mutate post meta (#176)');
    }

    // ---------------------------------------------------------------
    // getSyncStatus — error state
    // ---------------------------------------------------------------

    public function testGetSyncStatusError(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 12, 'post_type' => 'post']);
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return match ($key) {
                '_wash_sync_error'  => 'Something went wrong',
                '_wash_sync_status' => '',
                '_wash_job_id'      => '',
                '_wash_queued_at'   => '',
                '_wash_article_id'  => '',
                default             => '',
            };
        });
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('__')->alias(fn($text) => $text);

        $request = $this->createMockRequest([], [], ['post_id' => '12']);
        $result  = $this->callback->getSyncStatus($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame('error', $result->data['status']);
        $this->assertSame('Something went wrong', $result->data['errorMessage']);
    }

    // ---------------------------------------------------------------
    // getSyncStatus — queued by jobId alone (no syncStatus field)
    // ---------------------------------------------------------------

    public function testGetSyncStatusQueuedByJobIdOnly(): void
    {
        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }
        Functions\when('get_post')->justReturn((object) ['ID' => 13, 'post_type' => 'post']);
        $recentTime = date('Y-m-d H:i:s', time() - 5);
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) use ($recentTime) {
            return match ($key) {
                '_wash_sync_status' => '',           // no status set
                '_wash_job_id'      => 'job-xyz',    // but jobId present
                '_wash_queued_at'   => $recentTime,
                '_wash_sync_error'  => '',
                '_wash_article_id'  => '',
                default             => '',
            };
        });
        Functions\when('current_time')->alias(fn($f) => $f === 'timestamp' ? time() : date('Y-m-d H:i:s'));
        Functions\when('rest_ensure_response')->alias(fn($d) => new \WP_REST_Response($d));
        Functions\when('__')->alias(fn($text) => $text);

        $request = $this->createMockRequest([], [], ['post_id' => '13']);
        $result  = $this->callback->getSyncStatus($request);

        $this->assertSame('queued', $result->data['status']);
    }

    // ---------------------------------------------------------------
    // handleCallback — success with page (shopifyPageId, no blog)
    // ---------------------------------------------------------------

    public function testHandleCallbackSuccessWithPageId(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 20, 'post_type' => 'page']);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_metafields'      => '0',
                'wash_hreflangs_enabled'    => '0',
                'wash_redirections_enabled' => '0',
                'wash_shopify_store'        => 'mystore',
                'wash_api_key'              => 'test-key',
                'wash_app_url'              => 'https://app-wash.tradik.dev',
                'wash_http_user'            => '',
                'wash_http_pass'            => '',
                default                     => $default,
            };
        });

        $storedUrl = null;
        Functions\when('update_post_meta')->alias(function ($postId, $key, $value) use (&$storedUrl) {
            if ($key === '_wash_shopify_url') {
                $storedUrl = $value;
            }
            return true;
        });

        $request = new \WP_REST_Request([
            'wpPostId'      => 20,
            'success'       => true,
            'shopifyPageId' => 'gid://shopify/Page/50',
            'shopifyHandle' => 'about',
        ]);

        $result = $this->callback->handleCallback($request);

        $this->assertSame(200, $result->status);
        $this->assertTrue($result->data['success']);
        $this->assertSame('https://mystore.myshopify.com/pages/about', $storedUrl);
    }

    // ---------------------------------------------------------------
    // handleCallback — failure path (success=false)
    // ---------------------------------------------------------------

    public function testHandleCallbackFailurePath(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 30, 'post_type' => 'post']);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $request = new \WP_REST_Request([
            'wpPostId' => 30,
            'success'  => false,
            'error'    => 'Shopify rate limit exceeded',
        ]);

        $result = $this->callback->handleCallback($request);

        $this->assertSame(200, $result->status);
        $this->assertTrue($result->data['success']);
    }

    // ---------------------------------------------------------------
    // handleCallback — post not found → 404
    // ---------------------------------------------------------------

    public function testHandleCallbackPostNotFound(): void
    {
        Functions\when('get_post')->justReturn(null);
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $request = new \WP_REST_Request(['wpPostId' => 9999, 'success' => true]);
        $result  = $this->callback->handleCallback($request);

        $this->assertSame(404, $result->status);
        $this->assertFalse($result->data['success']);
    }

    // ---------------------------------------------------------------
    // handleCallback — success but no shopifyId
    // ---------------------------------------------------------------

    public function testHandleCallbackSuccessWithoutShopifyId(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_type' => 'post']);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_metafields'      => '0',
                'wash_hreflangs_enabled'    => '0',
                'wash_redirections_enabled' => '0',
                'wash_shopify_store'        => 'mystore',
                'wash_api_key'              => 'test-key',
                'wash_app_url'              => 'https://app-wash.tradik.dev',
                'wash_http_user'            => '',
                'wash_http_pass'            => '',
                default                     => $default,
            };
        });

        $request = new \WP_REST_Request(['wpPostId' => 42, 'success' => true]);
        $result  = $this->callback->handleCallback($request);

        $this->assertSame(200, $result->status);
        $this->assertTrue($result->data['success']);
    }

    // ---------------------------------------------------------------
    // handleCallback — failure with null error message
    // ---------------------------------------------------------------

    public function testHandleCallbackFailureNullError(): void
    {
        Functions\when('get_post')->justReturn((object) ['ID' => 31, 'post_type' => 'post']);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $request = new \WP_REST_Request([
            'wpPostId' => 31,
            'success'  => false,
            // no 'error' field → uses 'Unknown error' fallback
        ]);

        $result = $this->callback->handleCallback($request);

        $this->assertSame(200, $result->status);
    }

    // ---------------------------------------------------------------
    // register / registerRoutes — smoke tests
    // ---------------------------------------------------------------

    public function testRegisterAddsRestApiInitAction(): void
    {
        Functions\when('add_action')->justReturn(null);
        $this->callback->register();
        $this->assertTrue(true); // should not throw
    }

    public function testRegisterRoutesRegistersAllEndpoints(): void
    {
        $registered = [];
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$registered) {
            $registered[] = $ns . $route;
        });
        $this->callback->registerRoutes();

        $this->assertContains('wash/v1/callback', $registered);
        $this->assertContains('wash/v1/shopify/products', $registered);
        $this->assertContains('wash/v1/shopify/collections', $registered);
        $this->assertContains('wash/v1/stats', $registered);
        $this->assertContains('wash/v1/settings-push', $registered);
    }

    // ---------------------------------------------------------------
    // verifyApiKey — already in SecurityTest; add missing branch: stored empty
    // ---------------------------------------------------------------

    public function testVerifyApiKeyWhenStoredKeyIsEmpty(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => '', // stored key is empty
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $callback = new \WashCallback();
        $request  = $this->createMockRequest([], ['X-Wash-Key' => 'any-key'], []);
        $result   = $callback->verifyApiKey($request);

        // provided ≠ empty-stored → unauthorized
        $this->assertInstanceOf(\WP_Error::class, $result);
    }
}
