<?php
/**
 * Coverage tests for WashMultisite.
 *
 * Targets: init, addNetworkMenu, onSiteCreated, onSiteDeleted,
 *          injectSiteData, getNetworkSites, renderNetworkSettingsPage.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-multisite.php';

class WashMultisiteCoverageTest extends TestCase
{
    private \WashMultisite $multisite;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'        => 'test-key',
                'wash_app_url'        => 'https://app-wash.tradik.dev',
                'wash_http_user'      => '',
                'wash_http_pass'      => '',
                'wash_sync_on_publish' => '1',
                'wash_blog_name'      => 'Test Blog',
                default               => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_multisite')->justReturn(true);

        $this->multisite = new \WashMultisite();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // init()
    // ---------------------------------------------------------------

    public function testInitRegistersAllHooks(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(function ($hook) use (&$hooks) {
            $hooks[] = $hook;
        });
        Functions\when('add_filter')->alias(function ($hook) use (&$hooks) {
            $hooks[] = $hook;
        });

        $this->multisite->init();

        $this->assertContains('network_admin_menu', $hooks);
        $this->assertContains('wp_initialize_site', $hooks);
        $this->assertContains('wp_delete_site', $hooks);
        $this->assertContains('wash_sync_data', $hooks);
    }

    // ---------------------------------------------------------------
    // addNetworkMenu()
    // ---------------------------------------------------------------

    public function testAddNetworkMenuCallsAddMenuPage(): void
    {
        $called = false;
        Functions\when('add_menu_page')->alias(function () use (&$called) {
            $called = true;
        });
        Functions\when('__')->alias(fn($text) => $text);

        $this->multisite->addNetworkMenu();

        $this->assertTrue($called);
    }

    public function testAddNetworkMenuPassesCorrectSlug(): void
    {
        $slug = null;
        Functions\when('add_menu_page')->alias(function ($title, $menuTitle, $cap, $menuSlug) use (&$slug) {
            $slug = $menuSlug;
        });
        Functions\when('__')->alias(fn($text) => $text);

        $this->multisite->addNetworkMenu();

        $this->assertSame('wash-network', $slug);
    }

    // ---------------------------------------------------------------
    // onSiteCreated()
    // ---------------------------------------------------------------

    public function testOnSiteCreatedStoresBlogNameWithoutDeadRegisterCall(): void
    {
        // The blog registers with the app lazily on first sync; the old eager
        // POST /api/content-sync {action: register_blog} (always 400) is gone, so
        // onSiteCreated only persists the blog name now (MED-18, #160).
        $postCalled = false;

        Functions\when('get_blog_details')->justReturn((object) ['blogname' => 'New Blog']);
        Functions\when('wp_remote_post')->alias(function () use (&$postCalled) {
            $postCalled = true;
            return ['response' => ['code' => 200], 'body' => '{}'];
        });
        Functions\expect('update_blog_option')->once()->with(3, 'wash_blog_name', 'New Blog');

        $this->multisite->onSiteCreated((object) ['blog_id' => 3]);

        $this->assertFalse($postCalled, 'no register_blog HTTP call should be made');
    }

    // ---------------------------------------------------------------
    // onSiteDeleted()
    // ---------------------------------------------------------------

    public function testOnSiteDeletedIsNoOp(): void
    {
        // Method is intentionally empty — just assert it doesn't throw.
        $oldSite = (object) ['blog_id' => 5];
        $this->multisite->onSiteDeleted($oldSite);
        $this->assertTrue(true); // reached without exception
    }

    // ---------------------------------------------------------------
    // injectSiteData()
    // ---------------------------------------------------------------

    public function testInjectSiteDataAddsSiteIdAndBlogName(): void
    {
        Functions\when('get_current_blog_id')->justReturn(2);
        Functions\when('get_blog_option')->alias(function ($siteId, $key, $default = '') {
            return $key === 'wash_blog_name' ? 'My Blog' : $default;
        });
        Functions\when('get_bloginfo')->justReturn('My Blog');

        $data   = ['title' => 'Article'];
        $result = $this->multisite->injectSiteData($data, 99);

        $this->assertSame(2, $result['wpSiteId']);
        $this->assertSame('My Blog', $result['wpBlogName']);
        $this->assertSame('Article', $result['title']);
    }

    public function testInjectSiteDataFallsBackToBloginfo(): void
    {
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('get_blog_option')->alias(function ($siteId, $key, $default = '') {
            // Return default to trigger get_bloginfo fallback path
            return $default;
        });
        Functions\when('get_bloginfo')->justReturn('Fallback Name');

        $result = $this->multisite->injectSiteData([], 10);

        $this->assertSame(1, $result['wpSiteId']);
        $this->assertSame('Fallback Name', $result['wpBlogName']);
    }

    // ---------------------------------------------------------------
    // getNetworkSites()
    // ---------------------------------------------------------------

    public function testGetNetworkSitesReturnsMappedArray(): void
    {
        $site1 = (object) ['blog_id' => 1];
        $site2 = (object) ['blog_id' => 2];

        Functions\when('get_sites')->justReturn([$site1, $site2]);
        Functions\when('switch_to_blog')->justReturn(true);
        Functions\when('restore_current_blog')->justReturn(true);
        Functions\when('get_bloginfo')->alias(function ($key = '') {
            return $key === 'name' ? 'Test Site' : 'https://example.com';
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_on_publish' => '1',
                'wash_blog_name'       => 'mapped-blog',
                default                => $default,
            };
        });

        $result = $this->multisite->getNetworkSites();

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['siteId']);
        $this->assertTrue($result[0]['isActive']);
        $this->assertSame('mapped-blog', $result[0]['blogMapping']);
    }

    public function testGetNetworkSitesIsInactiveWhenSyncOff(): void
    {
        Functions\when('get_sites')->justReturn([(object) ['blog_id' => 1]]);
        Functions\when('switch_to_blog')->justReturn(true);
        Functions\when('restore_current_blog')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_on_publish' => '0', // sync disabled
                default                => $default,
            };
        });

        $result = $this->multisite->getNetworkSites();

        $this->assertFalse($result[0]['isActive']);
    }

    public function testGetNetworkSitesReturnsEmptyForNoSites(): void
    {
        Functions\when('get_sites')->justReturn([]);

        $result = $this->multisite->getNetworkSites();

        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // renderNetworkSettingsPage()
    // ---------------------------------------------------------------

    public function testRenderNetworkSettingsPageReturnsEarlyWithoutCap(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        // If the method exits early, get_sites should NOT be called.
        Functions\expect('get_sites')->never();

        ob_start();
        $this->multisite->renderNetworkSettingsPage();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testRenderNetworkSettingsPageOutputsTableWhenAuthorised(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_sites')->justReturn([(object) ['blog_id' => 1]]);
        Functions\when('switch_to_blog')->justReturn(true);
        Functions\when('restore_current_blog')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_on_publish' => '1',
                'wash_blog_name'       => '',
                default                => $default,
            };
        });
        Functions\when('esc_html_e')->alias(function ($text) { echo $text; });
        Functions\when('esc_html')->alias(fn($text) => $text);
        Functions\when('esc_url')->alias(fn($url) => $url);

        ob_start();
        $this->multisite->renderNetworkSettingsPage();
        $output = ob_get_clean();

        $this->assertStringContainsString('Wash Network Settings', $output);
        $this->assertStringContainsString('<table', $output);
    }

    public function testRenderNetworkSettingsPageShowsDashForEmptyBlogMapping(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_sites')->justReturn([(object) ['blog_id' => 1]]);
        Functions\when('switch_to_blog')->justReturn(true);
        Functions\when('restore_current_blog')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Site');
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_sync_on_publish' => '0',
                'wash_blog_name'       => '', // empty => fallback
                default                => $default,
            };
        });
        Functions\when('esc_html_e')->alias(function ($text) { echo $text; });
        Functions\when('esc_html')->alias(fn($text) => $text);
        Functions\when('esc_url')->alias(fn($url) => $url);

        ob_start();
        $this->multisite->renderNetworkSettingsPage();
        $output = ob_get_clean();

        // Empty blog mapping renders the em-dash fallback
        $this->assertStringContainsString('—', $output);
    }
}
