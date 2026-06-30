<?php
/**
 * Tests for WashConnectionsAdmin — the multi-store connections UI controller (#216).
 *
 * Handlers end in wp_safe_redirect()+exit; we mock wp_safe_redirect() to throw, which
 * short-circuits before exit and lets us assert the redirect target + the registry side
 * effects (WashConnection state).
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-connections-admin.php';

class WashRedirect extends \RuntimeException
{
    public string $url;
    public function __construct(string $url)
    {
        parent::__construct($url);
        $this->url = $url;
    }
}

class WashConnectionsAdminTest extends TestCase
{
    private \WashConnectionsAdmin $admin;
    /** @var array<string,mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        \WashContext::setActiveId(null);
        $_POST = [];
        $_GET  = [];

        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(true);
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('sanitize_key')->alias(fn($k) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)));
        Functions\when('wp_generate_password')->justReturn('abc123');
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('is_wp_error')->alias(fn($thing) => $thing instanceof \WP_Error);
        Functions\when('get_transient')->justReturn(['plan' => 'BUSINESS']); // qualifying by default
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(true); // cron already scheduled → no re-schedule
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('admin_url')->alias(fn($p = '') => 'http://wp/wp-admin/' . $p);
        Functions\when('add_query_arg')->alias(fn($k, $v, $url) => $url . (strpos($url, '?') !== false ? '&' : '?') . $k . '=' . $v);
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            throw new WashRedirect((string) $url);
        });
        Functions\when('wp_die')->alias(function () {
            throw new WashRedirect('wp_die');
        });

        $this->admin = new \WashConnectionsAdmin();
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        $_POST = [];
        $_GET  = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Register a stored (non-primary) connection in the mock registry. */
    private function seedConnection(string $id = 'x1', string $store = 'shop2.myshopify.com'): void
    {
        $this->options['wash_connections'] = [
            $id => ['id' => $id, 'store' => $store, 'apiKey' => 'wp4s_2', 'enabled' => true, 'isPrimary' => false, 'createdAt' => 1],
        ];
    }

    private function expectRedirect(callable $fn): string
    {
        try {
            $fn();
        } catch (WashRedirect $e) {
            return $e->url;
        }
        $this->fail('Expected a redirect but none happened');
    }

    public function testInitRegistersHooks(): void
    {
        $actions = [];
        Functions\when('add_action')->alias(function ($hook) use (&$actions) {
            $actions[] = $hook;
        });
        $this->admin->init();
        $this->assertContains('admin_menu', $actions);
        $this->assertContains('admin_post_wash_add_connection', $actions);
        $this->assertContains('admin_post_wash_toggle_connection', $actions);
    }

    public function testRegisterPagesAddsRoutableSubmenuPerAdditionalStore(): void
    {
        $options = [];
        $subs    = [];
        Functions\when('add_options_page')->alias(function ($t, $m, $c, $slug) use (&$options) {
            $options[] = $slug;
        });
        Functions\when('add_submenu_page')->alias(function ($parent, $t, $m, $c, $slug) use (&$subs) {
            $subs[] = ['parent' => $parent, 'slug' => $slug];
        });

        $this->admin->registerPages(); // only primary → single-store install unchanged
        $this->assertSame([], $options, 'no standalone connections page anymore');
        $this->assertSame([], $subs);

        $this->seedConnection('x1');
        $subs = [];
        $this->admin->registerPages(); // primary + x1
        $this->assertSame([], $options);
        $this->assertCount(1, $subs);
        // Routable under the real Settings parent (not the old hidden '' parent) —
        // this is what makes ?page=wash-conn-x1 actually resolve (#252).
        $this->assertSame('options-general.php', $subs[0]['parent']);
        $this->assertSame('wash-conn-x1', $subs[0]['slug']);
    }

    public function testRegisterPagesTruncatesLongStoreLabel(): void
    {
        $labels = [];
        Functions\when('add_options_page')->justReturn(null);
        Functions\when('add_submenu_page')->alias(function ($parent, $t, $menuLabel) use (&$labels) {
            $labels[] = $menuLabel;
        });

        $this->seedConnection('x1', 'super-long-store-name.myshopify.com');
        $this->admin->registerPages();

        $this->assertCount(1, $labels);
        $this->assertStringStartsWith('Wash: ', $labels[0]);
        $this->assertStringContainsString('super-long-store-na', $labels[0]); // first 19 chars
        $this->assertStringContainsString('…', $labels[0]);                    // truncation marker
    }

    public function testManualAddCreatesConnectionAndRedirects(): void
    {
        $_POST = ['store' => 'shop2.myshopify.com', 'apiKey' => 'wp4s_new'];
        $url = $this->expectRedirect(fn() => $this->admin->handleManualAdd());
        $this->assertStringContainsString('page=wash-conn-', $url);
        $this->assertStringContainsString('added=1', $url);

        $stored = $this->options['wash_connections'];
        $entry  = reset($stored);
        $this->assertSame('shop2.myshopify.com', $entry['store']);
        $this->assertSame('wp4s_new', $entry['apiKey']);
    }

    public function testManualAddRejectsMissingFields(): void
    {
        $_POST = ['store' => '', 'apiKey' => ''];
        $url = $this->expectRedirect(fn() => $this->admin->handleManualAdd());
        $this->assertStringContainsString('error=missing', $url);
        $this->assertArrayNotHasKey('wash_connections', $this->options);
    }

    public function testRemoveDeletesConnection(): void
    {
        $this->seedConnection('x1');
        $_POST = ['washConnectionId' => 'x1'];
        $url = $this->expectRedirect(fn() => $this->admin->handleRemove());
        $this->assertStringContainsString('removed=1', $url);
        $this->assertArrayNotHasKey('x1', $this->options['wash_connections']);
    }

    public function testToggleDisablesConnection(): void
    {
        $this->seedConnection('x1');
        $_POST = ['washConnectionId' => 'x1', 'enabled' => '0'];
        $url = $this->expectRedirect(fn() => $this->admin->handleToggle());
        $this->assertStringContainsString('toggled=0', $url);
        $this->assertFalse($this->options['wash_connections']['x1']['enabled']);
    }

    public function testOAuthAddCallbackAddsConnection(): void
    {
        $_GET = [
            'page' => 'wash', 'wash_callback' => '1', 'wash_add' => '1',
            'wash_state' => 'nonce', 'api_key' => 'wp4s_oauth', 'store' => 'shop3',
        ];
        $url = $this->expectRedirect(fn() => $this->admin->handleOAuthAddCallback());
        $this->assertStringContainsString('page=wash-conn-', $url);
        $this->assertStringContainsString('added=1', $url);
        $stored = $this->options['wash_connections'];
        $entry  = reset($stored);
        $this->assertSame('shop3', $entry['store']);
        $this->assertSame('wp4s_oauth', $entry['apiKey']);
    }

    public function testOAuthAddCallbackIgnoresUnrelatedPages(): void
    {
        $_GET = ['page' => 'some-other-page'];
        // No redirect, no exception — handler returns early.
        $this->admin->handleOAuthAddCallback();
        $this->assertArrayNotHasKey('wash_connections', $this->options);
    }

    public function testOAuthAddCallbackRejectsBadNonce(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);
        $_GET = [
            'page' => 'wash', 'wash_callback' => '1', 'wash_add' => '1',
            'wash_state' => 'bad', 'api_key' => 'k', 'store' => 's',
        ];
        $url = $this->expectRedirect(fn() => $this->admin->handleOAuthAddCallback());
        $this->assertStringContainsString('error=state', $url);
        $this->assertArrayNotHasKey('wash_connections', $this->options);
    }

    public function testRenderConnectionSettingsRedirectsForPrimary(): void
    {
        \WashContext::setActiveId('primary');
        $url = $this->expectRedirect(fn() => $this->admin->renderConnectionSettings());
        $this->assertStringContainsString('page=wash', $url);
        $this->assertStringContainsString('tab=connection', $url);
    }

    public function testEnqueueAssetsLoadsScriptAndDataOnManagerPage(): void
    {
        $enqueued  = [];
        $localized = [];
        Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$enqueued) {
            $enqueued[] = $handle;
        });
        Functions\when('wp_localize_script')->alias(function ($handle, $obj, $data) use (&$localized) {
            $localized[$obj] = $data;
        });
        Functions\when('washPluginUrl')->justReturn('http://wp/wp-content/plugins/wash/');
        Functions\when('wp_create_nonce')->justReturn('nonce123');
        Functions\when('get_bloginfo')->justReturn('My Site');
        Functions\when('get_site_url')->justReturn('https://example.com'); // WashApiClient::getShopifyAppSlug

        $this->admin->enqueueAssets('settings_page_wash');

        $this->assertContains('wash-connections', $enqueued);
        $this->assertArrayHasKey('washConnData', $localized);
        $this->assertSame('nonce123', $localized['washConnData']['nonce']);
        $this->assertSame('wash', $localized['washConnData']['page']);
    }

    public function testEnqueueAssetsSkipsUnrelatedPages(): void
    {
        $enqueued = [];
        Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$enqueued) {
            $enqueued[] = $handle;
        });
        $this->admin->enqueueAssets('edit.php');
        $this->assertSame([], $enqueued);
    }

    public function testHandlerBlocksUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $url = $this->expectRedirect(fn() => $this->admin->handleManualAdd());
        $this->assertSame('wp_die', $url); // guard() calls wp_die() before any work
    }

    public function testPlanAllowsMultiStoreForQualifyingPlans(): void
    {
        Functions\when('get_transient')->justReturn(['plan' => 'BUSINESS']);
        $this->assertTrue(\WashConnectionsAdmin::planAllowsMultiStore());
        Functions\when('get_transient')->justReturn(['plan' => 'enterprise']); // case-insensitive
        $this->assertTrue(\WashConnectionsAdmin::planAllowsMultiStore());
    }

    public function testPlanBlocksMultiStoreForLowerPlans(): void
    {
        Functions\when('get_transient')->justReturn(['plan' => 'FREE']);
        $this->assertFalse(\WashConnectionsAdmin::planAllowsMultiStore());
        Functions\when('get_transient')->justReturn(['plan' => 'PRO']);
        $this->assertFalse(\WashConnectionsAdmin::planAllowsMultiStore());
    }

    public function testPlanCheckFailsOpenOnApiError(): void
    {
        Functions\when('get_transient')->justReturn(false);             // not cached → fetch
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http', 'down'));
        $this->assertTrue(\WashConnectionsAdmin::planAllowsMultiStore()); // don't block on outage
    }

    public function testManualAddRejectedWhenPlanDoesNotQualify(): void
    {
        Functions\when('get_transient')->justReturn(['plan' => 'FREE']);
        $_POST = ['store' => 'shop.myshopify.com', 'apiKey' => 'wp4s_new'];
        $url = $this->expectRedirect(fn() => $this->admin->handleManualAdd());
        $this->assertStringContainsString('error=plan', $url);
        $this->assertArrayNotHasKey('wash_connections', $this->options); // nothing added
    }

    public function testEnforcePlanDowngradeDisablesAdditionalConnections(): void
    {
        Functions\when('get_transient')->justReturn(['plan' => 'FREE']); // lapsed
        $this->seedConnection('x1');
        $this->admin->enforcePlanDowngrade();
        $this->assertFalse($this->options['wash_connections']['x1']['enabled']);
    }

    public function testEnforcePlanDowngradeKeepsConnectionsWhenPlanQualifies(): void
    {
        Functions\when('get_transient')->justReturn(['plan' => 'ENTERPRISE']);
        $this->seedConnection('x1');
        $this->admin->enforcePlanDowngrade();
        $this->assertTrue($this->options['wash_connections']['x1']['enabled']);
    }

    public function testOAuthAddRejectedWhenPlanDoesNotQualify(): void
    {
        Functions\when('get_transient')->justReturn(['plan' => 'FREE']);
        $_GET = [
            'page' => 'wash', 'wash_callback' => '1', 'wash_add' => '1',
            'wash_state' => 'nonce', 'api_key' => 'wp4s_oauth', 'store' => 'shop3',
        ];
        $url = $this->expectRedirect(fn() => $this->admin->handleOAuthAddCallback());
        $this->assertStringContainsString('error=plan', $url);
        $this->assertArrayNotHasKey('wash_connections', $this->options);
    }
}
