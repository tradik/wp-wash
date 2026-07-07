<?php
/**
 * Anti-loop guard tests (D3): while a reverse import is writing posts/attachments,
 * WashSync and WashImages must NOT push them back to Shopify. Both short-circuit
 * on $GLOBALS['wash_importing'].
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-images.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';

class WashImportAntiLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wash_importing']);
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testOnSavePostSkipsOutboundSyncDuringImport(): void
    {
        // A published post would normally fan out to connections (reading options).
        // With the import guard set, onSavePost must return before any of that.
        Functions\expect('get_option')->never();
        $GLOBALS['wash_importing'] = true;

        $post = (object) ['post_type' => 'post', 'post_status' => 'publish'];
        (new \WashSync())->onSavePost(123, $post, true);

        $this->addToAssertionCount(1);
    }

    public function testOnStatusTransitionSkipsUnpublishDuringImport(): void
    {
        // Importing a post as Draft transitions publish → draft. Without the guard,
        // onStatusTransition would fire deleteForUnpublish() and DELETE the Shopify
        // article we just imported (audit PLUGIN001). The guard must return first —
        // reaching the unpublish loop would read connections via get_option().
        Functions\expect('get_option')->never();
        $GLOBALS['wash_importing'] = true;

        $post = (object) ['ID' => 789, 'post_type' => 'post', 'post_status' => 'draft'];
        (new \WashSync())->onStatusTransition('draft', 'publish', $post);

        $this->addToAssertionCount(1);
    }

    public function testOnAddAttachmentSkipsUploadDuringImport(): void
    {
        // isSyncableFile() would call get_post_mime_type() first; the guard must
        // return before that so a sideloaded image is not pushed back.
        Functions\expect('get_post_mime_type')->never();
        $GLOBALS['wash_importing'] = true;

        (new \WashImages())->onAddAttachment(456);
        $this->addToAssertionCount(1);
    }

    public function testOnMetaUpdateSkipsMetafieldPushDuringImport(): void
    {
        // Importing metafields writes post-meta; without the guard syncMetafield()
        // would echo them back to Shopify. The guard returns before getShopifyResourceId()
        // reads _wash_article_id via get_post_meta().
        Functions\expect('get_post_meta')->never();
        $GLOBALS['wash_importing'] = true;

        (new \WashMetafields())->onMetaUpdate(1, 55, 'some_key', 'value');
        $this->addToAssertionCount(1);
    }

    public function testOnAddAttachmentProceedsWhenNotImporting(): void
    {
        // Sanity: without the guard the syncable-file check runs (mime lookup).
        Functions\expect('get_post_mime_type')->once()->andReturn(''); // empty → not syncable, no upload

        (new \WashImages())->onAddAttachment(456);
        $this->addToAssertionCount(1);
    }
}
