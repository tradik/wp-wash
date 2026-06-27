<?php
/**
 * Tests for the primary (source) translation protection in WashAdmin:
 * - isProtectedTranslationSource(): the default-language source of a multi-post
 *   translation group is protected; children and standalone posts are not.
 * - protectTranslationSourceCaps(): denies delete_post for the source only.
 * - addShopifyRowActions(): removes Trash and explains why for the source.
 * - renderShopifyColumn(): a synced source keeps its green icon plus a "primary" badge.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-blog-router.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

class WashAdminTranslationSourceTest extends TestCase
{
    private \WashAdmin $admin;
    private array $options = [];
    private array $meta = [];
    private array $posts = [];
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [
            'wash_shopify_store' => 'test-store', 'wash_api_key' => 'k',
            'wash_app_url' => 'https://app.example.com', 'wash_http_user' => '', 'wash_http_pass' => '',
            'wash_post_types' => 'post,page', 'wash_sync_inline' => '1',
        ];
        $this->meta = [];
        $this->posts = [];
        $this->transients = [];
        \WashContext::setActiveId(null);

        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('get_post_meta')->alias(fn($id, $k, $s = true) => $this->meta[(int) $id][$k] ?? '');
        Functions\when('get_post')->alias(fn($id) => $this->posts[(int) $id] ?? null);
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('wp_nonce_url')->returnArg(1);
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/edit.php');
        Functions\when('add_query_arg')->alias(fn($args, $url) => $url);

        \WashBlogRouter::flushCache();
        $this->admin = new \WashAdmin();
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke(string $method, array $args)
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($ref->isStatic() ? null : $this->admin, ...$args);
    }

    /** Wire a Polylang translation group (lang => postId) and its posts. */
    private function setGroup(array $group, string $default = 'en'): void
    {
        $ids = array_values($group);
        Functions\when('pll_get_post_translations')->alias(fn($id) => in_array((int) $id, $ids, true) ? $group : []);
        Functions\when('pll_default_language')->justReturn($default);
        foreach ($group as $id) {
            $this->posts[$id] = (object) ['ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'slug-' . $id];
        }
    }

    public function testSourceOfMultiPostGroupIsProtected(): void
    {
        $this->setGroup(['en' => 10, 'de' => 20, 'fr' => 30], 'en');
        $this->assertTrue($this->invoke('isProtectedTranslationSource', [10]));   // source (default lang)
        $this->assertFalse($this->invoke('isProtectedTranslationSource', [20]));  // child
        $this->assertFalse($this->invoke('isProtectedTranslationSource', [30]));  // child
    }

    public function testStandalonePostIsNotProtected(): void
    {
        $this->setGroup(['en' => 110], 'en'); // a group of one — no children
        $this->assertFalse($this->invoke('isProtectedTranslationSource', [110]));
    }

    public function testFallsBackToLowestIdWhenNoDefaultLangPost(): void
    {
        // No 'en' (default) post in the group → lowest ID is the source.
        $this->setGroup(['de' => 220, 'fr' => 210], 'en');
        $this->assertTrue($this->invoke('isProtectedTranslationSource', [210]));
        $this->assertFalse($this->invoke('isProtectedTranslationSource', [220]));
    }

    public function testMapMetaCapDeniesDeleteForSourceOnly(): void
    {
        $this->setGroup(['en' => 310, 'de' => 320], 'en');

        $denied = $this->admin->protectTranslationSourceCaps(['delete_published_posts'], 'delete_post', 1, [310]);
        $this->assertContains('do_not_allow', $denied);

        $allowed = $this->admin->protectTranslationSourceCaps(['delete_published_posts'], 'delete_post', 1, [320]);
        $this->assertNotContains('do_not_allow', $allowed);

        // Non-delete caps are never touched.
        $edit = $this->admin->protectTranslationSourceCaps(['edit_published_posts'], 'edit_post', 1, [310]);
        $this->assertNotContains('do_not_allow', $edit);
    }

    public function testRowActionsRemoveTrashAndExplainForSource(): void
    {
        $this->setGroup(['en' => 410, 'de' => 420], 'en');

        $actions = $this->admin->addShopifyRowActions(['trash' => '<a>Trash</a>', 'edit' => '<a>Edit</a>'], $this->posts[410]);
        $this->assertArrayNotHasKey('trash', $actions);
        $this->assertArrayHasKey('wash_primary', $actions);
        $this->assertStringContainsString('Primary', $actions['wash_primary']);

        // A child keeps its Trash action.
        $childActions = $this->admin->addShopifyRowActions(['trash' => '<a>Trash</a>'], $this->posts[420]);
        $this->assertArrayHasKey('trash', $childActions);
        $this->assertArrayNotHasKey('wash_primary', $childActions);
    }

    public function testPrimaryBadgeShownForSyncedSource(): void
    {
        $this->setGroup(['en' => 510, 'de' => 520], 'en');
        $this->meta[510]['_wash_article_id'] = 'gid://shopify/Article/1';
        $this->meta[510]['_wash_blog_handle'] = 'articles';
        $this->meta[510]['_wash_handle'] = 'slug-510';
        // Pre-cache rules/markets/blogs as arrays so storefrontUrl() resolves locally
        // (a FALSE transient would trigger a live /api fetch the test can't serve).
        $this->transients['wash_blog_mapping_rules'] = [];
        $this->transients['wash_shopify_markets'] = [];
        $this->transients['wash_shopify_blogs'] = [];

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 510);
        $html = ob_get_clean();

        $this->assertStringContainsString('dashicons-yes-alt', $html); // normal green synced icon
        $this->assertStringContainsString('primary', $html);            // + small "primary" badge
    }
}
