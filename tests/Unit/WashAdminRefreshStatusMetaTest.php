<?php
/**
 * PLUGIN023: "Refresh all statuses" must write/clear the ACTIVE connection's
 * namespaced meta (via WashContext), never the primary's unprefixed keys — else a
 * refresh started from a secondary store's page corrupts the primary store's meta.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

class WashAdminRefreshStatusMetaTest extends TestCase
{
    private array $metaWrites = [];
    private array $metaDeletes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
        $this->metaWrites = [];
        $this->metaDeletes = [];

        Functions\when('get_option')->justReturn('');
        Functions\when('get_site_url')->justReturn('https://example.com'); // WashApiClient ctor
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->metaWrites[$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            $this->metaDeletes[] = $key;
            return true;
        });
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function apply($status)
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'applyStatusToPostMeta');
        $ref->setAccessible(true);
        return $ref->invoke(new \WashAdmin(), 123, $status);
    }

    public function testSyncedWritesNamespacedMetaForSecondaryStore(): void
    {
        \WashContext::setActiveId('x1');
        $result = $this->apply(array(
            'exists'            => true,
            'shopifyArticleId'  => 'gid://shopify/Article/9',
            'shopifyBlogId'     => 'gid://shopify/Blog/1',
            'shopifyBlogHandle' => 'news',
            'syncedAt'          => '2026-01-01',
        ));

        $this->assertSame('synced', $result);
        $this->assertSame('gid://shopify/Article/9', $this->metaWrites['_wash_article_id_x1']);
        $this->assertArrayNotHasKey('_wash_article_id', $this->metaWrites); // never the primary key
    }

    public function testNotSyncedClearsNamespacedMetaForSecondaryStore(): void
    {
        \WashContext::setActiveId('x1');
        $result = $this->apply(array('exists' => false));

        $this->assertSame('not_synced', $result);
        $this->assertContains('_wash_article_id_x1', $this->metaDeletes);
        $this->assertNotContains('_wash_article_id', $this->metaDeletes); // primary untouched
    }

    public function testPrimaryStoreStillUsesUnprefixedMeta(): void
    {
        \WashContext::setActiveId(null); // primary
        $this->apply(array('exists' => true, 'shopifyArticleId' => 'gid://shopify/Article/5'));
        $this->assertSame('gid://shopify/Article/5', $this->metaWrites['_wash_article_id']);
    }
}
