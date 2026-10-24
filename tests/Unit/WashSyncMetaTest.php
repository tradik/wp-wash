<?php
/**
 * WashSyncMeta: store-switch detection and per-connection meta clearing (#294).
 *
 * @package Wash\Tests\Unit
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync-meta.php';

class WashSyncMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testAFirstConnectIsNotAStoreSwitch(): void
    {
        $this->assertFalse(\WashSyncMeta::isDifferentStore('', 'shop'));
    }

    public function testHandleAndDomainOfOneStoreAreTheSameStore(): void
    {
        $this->assertFalse(\WashSyncMeta::isDifferentStore('Shop.myshopify.com', 'shop'));
    }

    public function testAnotherStoreIsASwitch(): void
    {
        $this->assertTrue(\WashSyncMeta::isDifferentStore('old-shop', 'new-shop.myshopify.com'));
    }

    public function testClearingTheAdditionalConnectionUsesItsSuffixedKeys(): void
    {
        $deleted = [];
        Functions\when('delete_post_meta_by_key')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        \WashSyncMeta::clearForConnection('c7');

        $this->assertContains('_wash_article_id_c7', $deleted);
        $this->assertNotContains('_wash_article_id', $deleted);
        $this->assertCount(count(\WashSyncMeta::KEYS), $deleted);
    }
}
