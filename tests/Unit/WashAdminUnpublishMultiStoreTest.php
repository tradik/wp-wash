<?php
/**
 * Tests for multi-store UNPUBLISH fan-out (#264).
 *
 * A post mapped to several connected Shopify stores must be unpublished from ALL
 * of them, not only the active/primary connection. These tests prove:
 * - unpublishFromShopify() runs the per-connection worker for every enabled store
 *   that actually holds the post, and skips stores it was never synced to;
 * - return semantics: partial success => true, all-stores-fail => WP_Error, no
 *   store holds it => true (idempotent);
 * - the shared eachEnabledConnection() helper iterates enabled connections only and
 *   restores the previously-active connection;
 * - end-to-end, BOTH the primary and the secondary store's per-connection sync meta
 *   are cleared (the actual bug: secondary store used to stay "synced" and live).
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

/**
 * Replaces the per-connection unpublish worker with a scripted outcome and records
 * which connection was active for each call — so the fan-out loop is asserted
 * without touching the Shopify API.
 */
class WashAdminUnpublishStub extends \WashAdmin
{
    /** @var string[] connection ids the worker ran under, in order */
    public array $ranOn = [];
    /** @var callable fn(string $connectionId): true|\WP_Error */
    public $outcome;

    protected function unpublishFromShopifyOnActive($postId, $post)
    {
        $id = \WashContext::activeId();
        $this->ranOn[] = $id;
        return ($this->outcome)($id);
    }
}

class WashAdminUnpublishMultiStoreTest extends TestCase
{
    private array $options = [];
    private array $posts = [];
    private array $postMeta = [];
    /** @var array<int, array{0:int,1:string}> delete_post_meta calls */
    private array $deletedMeta = [];
    /** @var array<int, array{0:string,1:mixed}> update_post_meta calls (key,val) */
    private array $updatedMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);

        $this->options = [
            'wash_shopify_store' => 'primary-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app.example',
            'wash_post_types'    => 'post,page',
        ];
        $this->posts = [];
        $this->postMeta = [];
        $this->deletedMeta = [];
        $this->updatedMeta = [];

        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('get_post')->alias(fn($id) => $this->posts[(int) $id] ?? null);
        Functions\when('get_post_meta')->alias(fn($id, $key, $single = true) => $this->postMeta[(int) $id][$key] ?? '');
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            $this->deletedMeta[] = [(int) $id, $key];
            unset($this->postMeta[(int) $id][$key]);
            return true;
        });
        Functions\when('update_post_meta')->alias(function ($id, $key, $val) {
            $this->updatedMeta[] = [$key, $val];
            $this->postMeta[(int) $id][$key] = $val;
            return true;
        });
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        // NOTE: washLog() is a real plugin function (no-op when WASH_DEBUG is off) —
        // it must NOT be mocked (Patchwork can't redefine an already-loaded function).
        Functions\when('__')->alias(fn($t) => $t);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        // Reset WashHreflangs' cached plugin detection so it never leaks between tests.
        if (class_exists('WashHreflangs')) {
            $p = new \ReflectionProperty('WashHreflangs', 'activePlugin');
            $p->setAccessible(true);
            $p->setValue(null, null);
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    // -- helpers ------------------------------------------------------------

    /** Register one enabled secondary store so WashConnection::all() reports 2 enabled. */
    private function addSecondStore(string $id = 'x1'): void
    {
        $this->options['wash_connections'] = [
            $id => ['id' => $id, 'store' => 'store-' . $id, 'apiKey' => 'k-' . $id,
                    'appUrl' => 'https://app.example', 'enabled' => true, 'isPrimary' => false],
        ];
    }

    private function post(int $id, string $type = 'post'): object
    {
        return $this->posts[$id] = (object) ['ID' => $id, 'post_type' => $type, 'post_status' => 'publish'];
    }

    private function stub(?callable $outcome = null): WashAdminUnpublishStub
    {
        $s = new WashAdminUnpublishStub();
        $s->outcome = $outcome ?? (static fn($id) => true);
        return $s;
    }

    private function unpublish(\WashAdmin $admin, int $postId, object $post)
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'unpublishFromShopify');
        $ref->setAccessible(true);
        return $ref->invoke($admin, $postId, $post);
    }

    // -- fan-out ------------------------------------------------------------

    public function testUnpublishRunsForEveryStoreThatHoldsThePost(): void
    {
        $this->addSecondStore();
        $post = $this->post(10);
        $this->postMeta[10]['_wash_article_id']    = 'gid://shopify/Article/1'; // primary
        $this->postMeta[10]['_wash_article_id_x1'] = 'gid://shopify/Article/2'; // secondary

        $stub = $this->stub();
        $result = $this->unpublish($stub, 10, $post);

        $this->assertTrue($result);
        $this->assertSame(['primary', 'x1'], $stub->ranOn); // BOTH stores, primary-first
    }

    public function testUnpublishSkipsStoresThatNeverHeldThePost(): void
    {
        // Synced ONLY to the secondary store — primary must NOT be touched.
        $this->addSecondStore();
        $post = $this->post(11);
        $this->postMeta[11]['_wash_article_id_x1'] = 'gid://shopify/Article/2';

        $stub = $this->stub();
        $result = $this->unpublish($stub, 11, $post);

        $this->assertTrue($result);
        $this->assertSame(['x1'], $stub->ranOn);
    }

    public function testUnpublishAlsoConsidersPageIdMeta(): void
    {
        // A synced PAGE has _wash_page_id (no article id) — still counts as held.
        $this->addSecondStore();
        $post = $this->post(12, 'page');
        $this->postMeta[12]['_wash_page_id_x1'] = 'gid://shopify/Page/9';

        $stub = $this->stub();
        $this->unpublish($stub, 12, $post);

        $this->assertSame(['x1'], $stub->ranOn);
    }

    public function testUnpublishSingleStoreRunsOnceOnPrimary(): void
    {
        // No secondary store configured → exactly one call on primary (unchanged).
        $post = $this->post(13);
        $this->postMeta[13]['_wash_article_id'] = 'gid://shopify/Article/1';

        $stub = $this->stub();
        $result = $this->unpublish($stub, 13, $post);

        $this->assertTrue($result);
        $this->assertSame(['primary'], $stub->ranOn);
    }

    // -- return semantics ---------------------------------------------------

    public function testUnpublishReturnsTrueWhenPostOnNoStore(): void
    {
        $this->addSecondStore();
        $post = $this->post(14); // no sync meta anywhere
        $stub = $this->stub(static fn($id) => new \WP_Error('should_not_run', 'x'));

        $result = $this->unpublish($stub, 14, $post);

        $this->assertTrue($result); // idempotent no-op
        $this->assertSame([], $stub->ranOn);
    }

    public function testUnpublishReturnsErrorOnlyWhenEveryStoreFails(): void
    {
        $this->addSecondStore();
        $post = $this->post(15);
        $this->postMeta[15]['_wash_article_id']    = 'gid://shopify/Article/1';
        $this->postMeta[15]['_wash_article_id_x1'] = 'gid://shopify/Article/2';

        $stub = $this->stub(static fn($id) => new \WP_Error('api', 'down on ' . $id));
        $result = $this->unpublish($stub, 15, $post);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('down on primary', $result->get_error_message()); // first error surfaced
        $this->assertSame(['primary', 'x1'], $stub->ranOn); // still attempted every store
    }

    public function testUnpublishPartialSuccessReturnsTrue(): void
    {
        // Primary fails, secondary succeeds → still counts as processed (true).
        $this->addSecondStore();
        $post = $this->post(16);
        $this->postMeta[16]['_wash_article_id']    = 'gid://shopify/Article/1';
        $this->postMeta[16]['_wash_article_id_x1'] = 'gid://shopify/Article/2';

        $stub = $this->stub(static fn($id) => $id === 'primary' ? new \WP_Error('api', 'boom') : true);
        $result = $this->unpublish($stub, 16, $post);

        $this->assertTrue($result);
        $this->assertSame(['primary', 'x1'], $stub->ranOn);
    }

    // -- eachEnabledConnection helper ---------------------------------------

    public function testEachEnabledConnectionRunsEnabledOnlyAndRestoresActive(): void
    {
        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'store' => 's1', 'apiKey' => 'k1', 'enabled' => true,  'isPrimary' => false],
            'x2' => ['id' => 'x2', 'store' => 's2', 'apiKey' => 'k2', 'enabled' => false, 'isPrimary' => false],
        ];
        \WashContext::setActiveId('x1'); // a non-null active context exists before the call

        $seen = [];
        $ref = new \ReflectionMethod(\WashAdmin::class, 'eachEnabledConnection');
        $ref->setAccessible(true);
        $ref->invoke(new \WashAdmin(), function ($id) use (&$seen) { $seen[] = $id; });

        $this->assertSame(['primary', 'x1'], $seen);              // enabled only (x2 skipped), primary-first
        $this->assertSame('x1', \WashContext::activeId());        // restored to the prior active id
    }

    // -- end-to-end: both stores' meta cleared ------------------------------

    public function testUnpublishClearsPerConnectionMetaOnEveryStore(): void
    {
        $this->addSecondStore();
        // Success responses from the (mocked) Wash app for every request.
        Functions\when('wp_remote_post')->justReturn(['body' => '{}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $post = $this->post(17);
        $this->postMeta[17]['_wash_language']       = 'en'; // short-circuits getPostLanguage (no locale funcs)
        $this->postMeta[17]['_wash_article_id']     = 'gid://shopify/Article/1';
        $this->postMeta[17]['_wash_article_id_x1']  = 'gid://shopify/Article/2';

        $result = $this->unpublish(new \WashAdmin(), 17, $post);
        $this->assertTrue($result);

        $deletedKeys = array_map(static fn($c) => $c[1], $this->deletedMeta);
        $this->assertContains('_wash_article_id', $deletedKeys);     // primary cleared
        $this->assertContains('_wash_article_id_x1', $deletedKeys);  // secondary cleared (the fix)

        $skipFlags = array_filter($this->updatedMeta, static fn($c) => str_starts_with($c[0], '_wash_skip_autosync'));
        $skipKeys = array_map(static fn($c) => $c[0], $skipFlags);
        $this->assertContains('_wash_skip_autosync', $skipKeys);     // skip-autosync set on primary
        $this->assertContains('_wash_skip_autosync_x1', $skipKeys);  // and on secondary
    }
}
