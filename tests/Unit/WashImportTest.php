<?php
/**
 * Tests for WashImport — the reverse-import orchestrator (AJAX + batch loop).
 * Covers ajaxImportPreview pagination + transient handshake, the ajaxImportBatch
 * dual guard (post cap + wall-clock budget), per-item error isolation, status,
 * and the nonce/capability gates.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-import-media.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import-metafields.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import-translations.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import-writer.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import.php';

/** Queue-backed fake of WashApiClient::get(). */
class FakeImportApiClient
{
    public array $responses = [];
    public array $getCalls = [];

    public function get($endpoint)
    {
        $this->getCalls[] = $endpoint;
        return array_shift($this->responses);
    }
}

/** Harness exposing seams: a fake API client and a scripted writer. */
class WashImportHarness extends \WashImport
{
    public $client;
    /** @var callable fn(array $article, array $options, array $translations): true|string|throws */
    public $importBehavior;

    protected function apiClient()
    {
        return $this->client;
    }

    protected function writer()
    {
        $behavior = $this->importBehavior ?? fn($a, $o, $t) => true;
        return new class($behavior) {
            private $behavior;
            public function __construct($behavior)
            {
                $this->behavior = $behavior;
            }
            public function importOne($article, $options, $translations = [])
            {
                return ($this->behavior)($article, $options, $translations);
            }
        };
    }
}

class WashImportTest extends TestCase
{
    private array $transients = [];
    private array $jsonResponses = [];
    private WashImportHarness $import;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);

        $this->transients = [];
        $this->jsonResponses = [];

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        Functions\when('__')->returnArg(1);
        Functions\when('get_option')->justReturn('');
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        // Match real WordPress sanitize_key(): it LOWERCASES. Using /i here would
        // hide the mixed-case-token/transient-miss bug on object-cache stores (PLUGIN002).
        Functions\when('sanitize_key')->alias(fn($v) => preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $v)));
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('apply_filters')->alias(fn($hook, $value) => $value);
        Functions\when('wp_generate_password')->justReturn('token1234567890abcde');

        Functions\when('set_transient')->alias(function ($k, $v) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });

        // Per-token batch lock (PLUGIN003). Default: lock is free so batches run.
        Functions\when('add_option')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);

        Functions\when('wp_send_json_error')->alias(function ($msg = '') {
            $this->jsonResponses[] = ['type' => 'error', 'data' => $msg];
            throw new \RuntimeException('wp_send_json_error');
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->jsonResponses[] = ['type' => 'success', 'data' => $data];
            throw new \RuntimeException('wp_send_json_success');
        });

        $this->import = new WashImportHarness();
        $this->import->client = new FakeImportApiClient();
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Drive an AJAX handler to its json exit and return the payload. */
    private function drive(callable $handler): array
    {
        try {
            $handler();
        } catch (\RuntimeException $e) {
            // expected: json mock unwinds the handler
        }
        return end($this->jsonResponses);
    }

    // ---------------- ajaxImportPreview ----------------

    public function testPreviewFollowsPaginationAndStoresIdList(): void
    {
        $_POST = ['blogId' => 'gid://shopify/Blog/1', 'status' => 'draft', 'images' => '1'];
        $this->import->client->responses = [
            ['articles' => [['id' => 'A'], ['id' => 'B']], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1']],
            ['articles' => [['id' => 'C']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ];

        $res = $this->drive(fn() => $this->import->ajaxImportPreview());

        $this->assertSame('success', $res['type']);
        $this->assertSame('token1234567890abcde', $res['data']['token']);
        $this->assertSame(3, $res['data']['total']);
        $this->assertCount(2, $this->import->client->getCalls);

        $stored = $this->transients['wash_import_token1234567890abcde'];
        $this->assertSame(['A', 'B', 'C'], $stored['articleIds']);
        $this->assertTrue($stored['options']['images']);
        $this->assertSame('draft', $stored['options']['status']);
    }

    public function testPreviewRejectsMissingBlogId(): void
    {
        $_POST = ['blogId' => ''];
        $res = $this->drive(fn() => $this->import->ajaxImportPreview());
        $this->assertSame('error', $res['type']);
    }

    public function testPreviewSurfacesApiError(): void
    {
        $_POST = ['blogId' => 'gid://shopify/Blog/1'];
        $this->import->client->responses = [new \WP_Error('x', 'API down')];
        $res = $this->drive(fn() => $this->import->ajaxImportPreview());
        $this->assertSame('error', $res['type']);
        $this->assertSame('API down', $res['data']);
    }

    public function testPreviewRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->import->ajaxImportPreview();
    }

    public function testPreviewRejectsUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $_POST = ['blogId' => 'gid://shopify/Blog/1'];
        $res = $this->drive(fn() => $this->import->ajaxImportPreview());
        $this->assertSame('error', $res['type']);
    }

    public function testBatchRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->import->ajaxImportBatch();
    }

    public function testStatusRejectsUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $res = $this->drive(fn() => $this->import->ajaxImportStatus());
        $this->assertSame('error', $res['type']);
    }

    // ---------------- ajaxImportBatch ----------------

    private function seed(array $ids, array $options = []): void
    {
        $this->transients['wash_import_tok'] = ['articleIds' => $ids, 'options' => $options];
    }

    private function detailResponses(array $ids): void
    {
        $this->import->client->responses = array_map(fn($id) => ['article' => ['id' => $id]], $ids);
    }

    public function testBatchImportsWholeWindowAndReportsDone(): void
    {
        $this->seed(['A', 'B', 'C']);
        $this->detailResponses(['A', 'B', 'C']);
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame(3, $res['data']['processed']);
        $this->assertSame(0, $res['data']['errors']);
        $this->assertSame(3, $res['data']['nextOffset']);
        $this->assertTrue($res['data']['done']);
        $this->assertArrayNotHasKey('wash_import_tok', $this->transients);
    }

    public function testBatchHonoursBatchSizeCap(): void
    {
        Functions\when('apply_filters')->alias(fn($hook, $value) => $hook === 'wash_import_batch_size' ? 1 : $value);
        $this->seed(['A', 'B']);
        $this->detailResponses(['A', 'B']);
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame(1, $res['data']['processed']);
        $this->assertSame(1, $res['data']['nextOffset']);
        $this->assertFalse($res['data']['done']);
        $this->assertArrayHasKey('wash_import_tok', $this->transients);
    }

    public function testBatchStopsAtTimeBudget(): void
    {
        Functions\when('apply_filters')->alias(fn($hook, $value) => $hook === 'wash_import_time_budget' ? 0.0 : $value);
        $this->seed(['A', 'B', 'C']);
        $this->detailResponses(['A', 'B', 'C']);
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame(1, $res['data']['nextOffset']);
        $this->assertFalse($res['data']['done']);
    }

    public function testBatchIsolatesFetchError(): void
    {
        $this->seed(['A']);
        $this->import->client->responses = [new \WP_Error('net', 'fetch failed')];
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame(0, $res['data']['processed']);
        $this->assertSame(1, $res['data']['errors']);
        $this->assertStringContainsString('fetch failed', $res['data']['errorMessages'][0]);
    }

    public function testBatchIsolatesThrowingImport(): void
    {
        $this->seed(['A']);
        $this->detailResponses(['A']);
        $this->import->importBehavior = function () {
            throw new \RuntimeException('boom');
        };
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame(1, $res['data']['errors']);
        $this->assertStringContainsString('boom', $res['data']['errorMessages'][0]);
    }

    public function testBatchRecordsHandledFailureReason(): void
    {
        $this->seed(['A']);
        $this->detailResponses(['A']);
        $this->import->importBehavior = fn() => 'slug taken';
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame(1, $res['data']['errors']);
        $this->assertStringContainsString('slug taken', $res['data']['errorMessages'][0]);
    }

    public function testBatchDoneWhenOffsetBeyondTotal(): void
    {
        $this->seed(['A', 'B']);
        $_POST = ['token' => 'tok', 'offset' => '5'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertTrue($res['data']['done']);
        $this->assertArrayNotHasKey('wash_import_tok', $this->transients);
    }

    public function testBatchRejectsExpiredToken(): void
    {
        $_POST = ['token' => 'missing', 'offset' => '0'];
        $res = $this->drive(fn() => $this->import->ajaxImportBatch());
        $this->assertSame('error', $res['type']);
    }

    public function testBatchReturnsLockedWhenAnotherBatchHoldsTheToken(): void
    {
        // PLUGIN003: a concurrent/retried batch for the same token is refused (409)
        // rather than double-processing the same offset.
        Functions\when('add_option')->justReturn(false);            // lock already held
        Functions\when('get_option')->alias(fn($k) => $k === 'wash_import_lock_tok' ? time() : ''); // fresh
        $this->seed(['A', 'B']);
        $this->detailResponses(['A', 'B']);
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame('error', $res['type']);
        $this->assertSame('locked', $res['data']['code']);
    }

    public function testBatchStealsAStaleLockAndProceeds(): void
    {
        Functions\when('add_option')->justReturn(false);
        Functions\when('get_option')->alias(fn($k) => $k === 'wash_import_lock_tok' ? (time() - 9999) : ''); // stale
        $this->seed(['A']);
        $this->detailResponses(['A']);
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame('success', $res['type']);
        $this->assertSame(1, $res['data']['processed']);
    }

    public function testBatchRejectsUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $res = $this->drive(fn() => $this->import->ajaxImportBatch());
        $this->assertSame('error', $res['type']);
    }

    // ---------------- ajaxImportStatus ----------------

    public function testStatusReportsActiveTokenCounts(): void
    {
        $this->seed(['A', 'B', 'C']);
        $_POST = ['token' => 'tok'];
        $res = $this->drive(fn() => $this->import->ajaxImportStatus());

        $this->assertTrue($res['data']['active']);
        $this->assertSame(3, $res['data']['total']);
    }

    public function testStatusReportsInactiveForUnknownToken(): void
    {
        $_POST = ['token' => 'nope'];
        $res = $this->drive(fn() => $this->import->ajaxImportStatus());
        $this->assertFalse($res['data']['active']);
        $this->assertSame(0, $res['data']['total']);
    }

    // ---------------- init + factories + option sanitising ----------------

    public function testInitRegistersAjaxHandlers(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(function ($hook) use (&$hooks) {
            $hooks[] = $hook;
        });
        (new \WashImport())->init();

        $this->assertContains('wp_ajax_wash_import_preview', $hooks);
        $this->assertContains('wp_ajax_wash_import_batch', $hooks);
        $this->assertContains('wp_ajax_wash_import_status', $hooks);
    }

    public function testRealFactoriesReturnConcreteCollaborators(): void
    {
        Functions\when('get_site_url')->justReturn('https://example.com'); // WashApiClient::resolveAppUrl()
        $import = new \WashImport();
        foreach (['apiClient' => \WashApiClient::class, 'writer' => \WashImportWriter::class] as $method => $type) {
            $ref = new \ReflectionMethod(\WashImport::class, $method);
            $ref->setAccessible(true);
            $this->assertInstanceOf($type, $ref->invoke($import));
        }
    }

    public function testPreviewSanitisesInvalidStatusToDraft(): void
    {
        $_POST = ['blogId' => 'gid://shopify/Blog/1', 'status' => 'bogus'];
        $this->import->client->responses = [
            ['articles' => [['id' => 'A']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ];

        $this->drive(fn() => $this->import->ajaxImportPreview());
        $this->assertSame('draft', $this->transients['wash_import_token1234567890abcde']['options']['status']);
    }

    public function testBatchRequestsTranslationsWhenEnabled(): void
    {
        $this->seed(['A'], ['translations' => true]);
        $this->import->client->responses = [['article' => ['id' => 'A'], 'translations' => ['de' => []]]];
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $this->drive(fn() => $this->import->ajaxImportBatch());
        $this->assertStringContainsString('withTranslations=1', $this->import->client->getCalls[0]);
    }

    public function testBatchTreatsMissingArticlePayloadAsError(): void
    {
        $this->seed(['A']);
        $this->import->client->responses = [['translations' => []]]; // no 'article'/'page' key
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());
        $this->assertSame(1, $res['data']['errors']);
    }

    // ---------------- Pages source ----------------

    public function testPreviewPagesSourceDoesNotRequireBlog(): void
    {
        $_POST = ['type' => 'page']; // no blogId
        $this->import->client->responses = [
            ['pages' => [['id' => 'P1'], ['id' => 'P2']], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ];

        $res = $this->drive(fn() => $this->import->ajaxImportPreview());

        $this->assertSame('success', $res['type']);
        $this->assertSame(2, $res['data']['total']);
        $stored = $this->transients['wash_import_token1234567890abcde'];
        $this->assertSame(['P1', 'P2'], $stored['articleIds']);
        $this->assertSame('page', $stored['options']['postType']);
        $this->assertStringContainsString('/api/shopify-pages', $this->import->client->getCalls[0]);
    }

    public function testBatchUsesPageDetailEndpointForPages(): void
    {
        $this->seed(['P1'], ['postType' => 'page']);
        $this->import->client->responses = [['page' => ['id' => 'P1']]];
        $_POST = ['token' => 'tok', 'offset' => '0'];

        $res = $this->drive(fn() => $this->import->ajaxImportBatch());

        $this->assertSame(1, $res['data']['processed']);
        $this->assertStringContainsString('/api/shopify-page?id=', $this->import->client->getCalls[0]);
    }
}
