<?php
/**
 * WashSync edge branches around the main pipeline: frontend CSS fetching for
 * page builders, single-href rewriting guards, the plan gate on search &
 * replace, content/featured file pre-sync outcomes, disabled connections in
 * delete/unpublish fan-out, translation-aware deletes, the sync-table
 * self-heal and the block-rule render callback.
 */

namespace Wash\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// Load the code under test before Brain Monkey boots Patchwork (which would
// otherwise rewrite it and skew Xdebug's per-line coverage in the child process).
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class WashSyncEdgeCasesTest extends TestCase
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

    public function testFetchCssReturnsEmptyWithoutPermalink(): void
    {
        Functions\when('get_permalink')->justReturn(false);
        $this->assertSame('', $this->invokePrivate('fetchPageBuilderCss', [7]));
    }

    public function testFetchCssReturnsEmptyOnFailedRequest(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http', 'down'));
        Functions\when('wp_remote_retrieve_response_code')->justReturn(0);
        $this->assertSame('', $this->invokePrivate('fetchPageBuilderCss', [7]));
    }

    public function testFetchCssSendsBasicAuthAndPullsLinkedBricksCss(): void
    {
        $this->options['wash_http_user'] = 'staff';
        $this->options['wash_http_pass'] = 'secret';
        $calls = [];
        Functions\when('wp_remote_get')->alias(function ($url, $args) use (&$calls) {
            $calls[] = [$url, $args];
            return ['url' => $url];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($response) => str_contains($response['url'], '.css')
            ? '.brx{}'
            : '<style>tiny</style><link rel="stylesheet" href="https://example.com/wp-content/themes/bricks/frontend.min.css?ver=1">');

        $css = $this->invokePrivate('fetchPageBuilderCss', [7]);

        $this->assertSame(".brx{}\n", $css);
        $this->assertSame('Basic ' . base64_encode('staff:secret'), $calls[0][1]['headers']['Authorization']);
        $this->assertCount(2, $calls);
    }

    public function testRewriteHrefLeavesNonHttpSchemeAndRootAlone(): void
    {
        $this->assertSame('href="ftp://x.test/a"', $this->invokePrivate('rewriteHref', ['href="ftp://x.test/a"', 'ftp://x.test/a']));
        $this->assertSame('href="/"', $this->invokePrivate('rewriteHref', ['href="/"', '/']));
    }

    public function testRewriteHrefLeavesLinkWhenPostVanished(): void
    {
        Functions\when('url_to_postid')->justReturn(44);
        $this->assertSame('href="/gone/"', $this->invokePrivate('rewriteHref', ['href="/gone/"', '/gone/']));
    }

    public function testSearchReplaceSkippedWithoutPlanFeature(): void
    {
        $this->options['wash_search_replace_rules'] = [['search' => 'a', 'replace' => 'b']];
        $this->client->features = false;
        $this->assertSame('aaa', $this->invokePrivate('applySearchReplaceRules', ['aaa']));
    }

    public function testShortcodeRuleRunsThroughSearchReplace(): void
    {
        $this->options['wash_search_replace_rules'] = [['search' => '[promo]', 'replace' => '<b>%content%</b>', 'type' => 'shortcode']];
        Functions\when('shortcode_exists')->justReturn(true);
        $out = $this->invokePrivate('applySearchReplaceRules', ['A [promo]<i>Sale</i>[/promo] B', ['shortcode']]);
        $this->assertSame('A <b>Sale</b> B', $out);
    }

    public function testCustomTagListOfBlanksLeavesContent(): void
    {
        $this->options['wash_allowed_html_tags'] = ' , ';
        $this->assertSame('<x-a></x-a>', $this->invokePrivate('preserveCustomHtmlTags', ['<x-a></x-a>']));
    }

    public function testSyncContentFilesHandlesEveryOutcome(): void
    {
        $base = 'https://example.com/wp-content/uploads/';
        Functions\when('attachment_url_to_postid')->alias(fn($url) => ['new.jpg' => 1, 'done.jpg' => 2, 'bad.jpg' => 3][basename($url)] ?? 0);
        \WashImages::$cdnUrls[2] = 'https://cdn.shopify.com/done.jpg';
        \WashImages::$syncOk = false;
        $html = "<img src=\"{$base}new.jpg\"><img src=\"{$base}done.jpg\"><img src=\"{$base}missing.jpg\">";

        $this->invokePrivate('syncContentFiles', [$html]);
        $this->assertSame([1], \WashImages::$synced);

        \WashImages::$syncOk = true;
        $this->invokePrivate('syncContentFiles', ["<img src=\"{$base}bad.jpg\">"]);
        $this->assertSame([1, 3], \WashImages::$synced);

        $this->invokePrivate('syncContentFiles', ['<p>no files</p>']);
        $this->assertSame([1, 3], \WashImages::$synced);
    }

    public function testSyncFeaturedImageOutcomes(): void
    {
        \WashImages::$cdnUrls[8] = 'https://cdn.shopify.com/eight.jpg';
        $this->invokePrivate('syncFeaturedImage', [0]);
        $this->invokePrivate('syncFeaturedImage', [8]);
        $this->assertSame([], \WashImages::$synced);

        \WashImages::$syncOk = false;
        $this->invokePrivate('syncFeaturedImage', [9]);
        \WashImages::$syncOk = true;
        $this->invokePrivate('syncFeaturedImage', [10]);
        $this->assertSame([9, 10], \WashImages::$synced);
    }

    public function testDeleteAndUnpublishSkipDisabledConnections(): void
    {
        $this->options['wash_connections'] = ['second' => ['enabled' => false, 'apiKey' => 'k2']];
        $product = $this->makePost(['post_type' => 'product']);
        Functions\when('get_post')->justReturn($product);

        $this->sync->onDeletePost(7);
        $this->sync->onStatusTransition('draft', 'publish', $product);

        $this->assertSame([], $this->client->posts);
        $this->assertSame([], $GLOBALS['wpdb']->inserted);
    }

    public function testSendDeleteCarriesTranslationGroup(): void
    {
        \WashHreflangs::$group = ['sourceId' => 2];
        $this->invokePrivate('sendDelete', [7, $this->makePost(['post_type' => 'page'])]);
        $this->assertSame('/api/page-sync', $this->client->posts[0][0]);
        $this->assertSame(['sourceId' => 2], $this->client->posts[0][1]['translationGroup']);
    }

    public function testLogSyncSelfHealsMissingTable(): void
    {
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_heal_';
            public function show_errors($show = true) { return false; }
            public function suppress_errors($suppress = true) { return false; }
            public function insert($table, $data) { return 1; }
            public function get_var($query) { return null; }
            public function prepare($query, ...$args) { return $query; }
        };
        $this->invokePrivate('logSync', [7, 'create', ['shopifyArticleId' => 'a']]);
        $this->invokePrivate('logSync', [7, 'update', new \WP_Error('x', 'failed')]);
        // The table probe is cached per request: the second log does not re-heal.
        $this->assertSame(1, \WashActivator::$created);
    }

    public function testBlockRuleRegistersPassThroughRenderCallback(): void
    {
        $registered = [];
        Functions\when('register_block_type')->alias(function ($name, $args) use (&$registered) {
            $registered[$name] = $args;
        });
        $this->invokePrivate('applyBlockRule', ['<!-- wp:wash-test-cb --><b>x</b><!-- /wp:wash-test-cb -->', 'wp:wash-test-cb', 'Y']);
        $callback = $registered['core/wash-test-cb']['render_callback'];
        $this->assertSame('<i>kept</i>', $callback([], '<i>kept</i>'));
        $this->assertSame('', $callback([], null));
    }
}
