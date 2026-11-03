<?php
/**
 * WashSync push pipeline: dispatch() → doSync() → syncPost() and the follow-up
 * syncMetafields()/syncHreflangs() steps. Runs the real methods end to end
 * against the fake API client from WashSyncHarness (no HTTP) and checks the
 * gating (skip-autosync, no-mapping), the create/update decision, the image
 * pre-sync, and what each API outcome leaves behind.
 */

namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// Load the code under test before Brain Monkey boots Patchwork (which would
// otherwise rewrite it and skew Xdebug's per-line coverage in the child process).
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class WashSyncPipelineTest extends TestCase
{
    use WashSyncHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
    }

    protected function tearDown(): void
    {
        $this->shutdownHarness();
        parent::tearDown();
    }

    public function testDoSyncAutoSkipsPostUnpublishedFromShopify(): void
    {
        $this->meta['_wash_skip_autosync'] = '1';
        $this->invokePrivate('doSync', [7, $this->makePost(), true]);
        $this->assertSame([], $this->client->posts);
    }

    public function testDoSyncSkipsPublishWhenNoMappingAcrossStores(): void
    {
        $this->options['wash_connections'] = ['second' => ['enabled' => true, 'apiKey' => 'k2']];
        $this->meta['_wash_language'] = 'DE ';
        $this->invokePrivate('doSync', [7, $this->makePost(), true]);
        $this->assertSame('1', $this->updated['_wash_no_mapping']);
        $this->assertSame([], $this->client->posts);
    }

    public function testManualDoSyncClearsSkipFlagAndCreatesArticle(): void
    {
        $this->meta['_wash_skip_autosync'] = '1';
        $this->client->result = ['shopifyArticleId' => 'gid://shopify/Article/1', 'shopifyBlogId' => 'gid://shopify/Blog/2'];
        $this->invokePrivate('doSync', [7, $this->makePost(), false]);

        $this->assertContains('_wash_skip_autosync', $this->deleted);
        $this->assertContains('_wash_no_mapping', $this->deleted);
        $this->assertSame('/api/content-sync', $this->client->posts[0][0]);
        $this->assertSame('create', $this->client->posts[0][1]['action']);
        $this->assertSame('gid://shopify/Article/1', $this->updated['_wash_article_id']);
        $this->assertSame(1, count($GLOBALS['wpdb']->inserted));
    }

    public function testDoSyncUpdatesWhenArticleAlreadyKnown(): void
    {
        $this->meta['_wash_article_id'] = 'gid://shopify/Page/9';
        $this->client->result = ['shopifyPageId' => 'gid://shopify/Page/9'];
        $this->invokePrivate('doSync', [7, $this->makePost(['post_type' => 'page']), true]);
        $this->assertSame('/api/page-sync', $this->client->posts[0][0]);
        $this->assertSame('update', $this->client->posts[0][1]['action']);
        $this->assertSame('gid://shopify/Page/9', $this->client->posts[0][1]['shopifyPageId']);
    }

    public function testSyncPostPreSyncsContentAndFeaturedImages(): void
    {
        $this->thumbnailId = 55;
        \Brain\Monkey\Functions\when('attachment_url_to_postid')->justReturn(31);
        $post = $this->makePost(['post_content' => '<img src="https://example.com/wp-content/uploads/a.jpg">']);
        $this->invokePrivate('syncPost', [7, $post, 'create']);
        $this->assertSame([31, 55], \WashImages::$synced);
    }

    public function testSyncPostSkipsImagesWhenFilesDisabled(): void
    {
        $this->options['wash_files_enabled'] = '0';
        $this->thumbnailId = 55;
        $this->invokePrivate('syncPost', [7, $this->makePost(), 'create']);
        $this->assertSame([], \WashImages::$synced);
        $this->assertCount(1, $this->client->posts);
    }

    public function testDedupedSyncPostSendsAnIdenticalPayloadOnlyOnce(): void
    {
        $sent = [];
        \Brain\Monkey\Functions\when('get_transient')->alias(function ($key) use (&$sent) {
            return $sent[$key] ?? false;
        });
        \Brain\Monkey\Functions\when('set_transient')->alias(function ($key, $hash) use (&$sent) {
            $sent[$key] = $hash;
            return true;
        });
        $this->client->result = ['queued' => true, 'jobId' => 'job-1'];
        $this->invokePrivate('syncPost', [7, $this->makePost(), 'update', true]);
        $this->invokePrivate('syncPost', [7, $this->makePost(), 'update', true]);
        $this->assertCount(1, $this->client->posts);
    }

    public function testSyncPostApiErrorStoresErrorAndStops(): void
    {
        $this->options['wash_sync_metafields'] = '1';
        $this->client->result = new \WP_Error('boom', 'Shopify down');
        \Brain\Monkey\Actions\expectDone('wash_post_synced')->never();
        $this->invokePrivate('syncPost', [7, $this->makePost(), 'update']);
        $this->assertSame('Shopify down', $this->updated['_wash_sync_error']);
    }

    public function testSyncPostQueuedResultDoesNotRunFollowUps(): void
    {
        $this->client->result = ['queued' => true, 'jobId' => 'job-1'];
        \Brain\Monkey\Actions\expectDone('wash_post_synced')->never();
        $this->invokePrivate('syncPost', [7, $this->makePost(), 'create']);
        $this->assertSame('queued', $this->updated['_wash_sync_status']);
    }

    public function testSyncPostSuccessRunsMetafieldsHreflangsAndAction(): void
    {
        $this->options['wash_sync_metafields'] = '1';
        $this->options['wash_hreflangs_enabled'] = '1';
        \WashMetafields::$syncResult = ['count' => 4];
        \WashHreflangs::$syncResult = ['count' => 2];
        $this->client->result = ['shopifyArticleId' => 'gid://shopify/Article/1'];
        \Brain\Monkey\Actions\expectDone('wash_post_synced')->once()->with(7, 'gid://shopify/Article/1');
        $this->invokePrivate('syncPost', [7, $this->makePost(), 'create']);
        $this->assertSame('gid://shopify/Article/1', $this->updated['_wash_article_id']);
    }

    public function testFollowUpErrorsAreLoggedNotThrown(): void
    {
        $this->options['wash_sync_metafields'] = '1';
        $this->options['wash_hreflangs_enabled'] = '1';
        \WashMetafields::$syncResult = new \WP_Error('mf', 'metafields failed');
        \WashHreflangs::$syncResult = new \WP_Error('hl', 'hreflangs failed');
        $this->assertNull($this->invokePrivate('syncMetafields', [7, 'gid://shopify/Article/1']));
        $this->assertNull($this->invokePrivate('syncHreflangs', [7, 'gid://shopify/Article/1']));
    }

    public function testHreflangsClearedResultIsHandled(): void
    {
        $this->options['wash_hreflangs_enabled'] = '1';
        \WashHreflangs::$syncResult = ['cleared' => true];
        $this->assertNull($this->invokePrivate('syncHreflangs', [7, 'gid://shopify/Article/1']));
    }

    public function testDispatchRunsDoSyncPerTargetConnection(): void
    {
        $this->meta['_wash_skip_autosync'] = '1';
        $this->sync->onSavePost(7, $this->makePost(), true);
        // doSync ran for primary and stopped at the skip-autosync gate.
        $this->assertSame([], $this->client->posts);
        $this->assertNull((new \ReflectionProperty(\WashContext::class, 'activeId'))->getValue());
    }
}
