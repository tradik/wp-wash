<?php
/**
 * Demo site mu-plugin, admin side: locked settings, blocked pages and
 * actions, plugin defaults and the dashboard widget. Each test loads
 * packages/wordpress/mu-plugins/wash-demo.php in its own process.
 */
namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class WashDemoMuPluginAdminTest extends TestCase
{
    /** @var array<string, callable[]> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $capture = function ($hook, $callback) {
            $this->hooks[$hook][] = $callback;
            return true;
        };
        Functions\when('add_action')->alias($capture);
        Functions\when('add_filter')->alias($capture);
        Functions\when('wp_die')->alias(function ($message) {
            throw new \RuntimeException($message);
        });
        require dirname(__DIR__, 3) . '/wordpress/mu-plugins/wash-demo.php';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function hook(string $name, int $index = 0): callable
    {
        return $this->hooks[$name][$index];
    }

    public static function lockedPages(): array
    {
        return [['options-general.php', 'blogname'], ['options-writing.php', 'mailserver_url'], ['options-reading.php', 'blog_public'], ['index.php', '']];
    }

    #[RunInSeparateProcess]
    #[DataProvider('lockedPages')]
    public function testSettingsPagesGetTheirLockScript(string $page, string $field): void
    {
        $GLOBALS['pagenow'] = $page;
        $this->hook('admin_init', 1)();
        ob_start();
        $this->hook('admin_footer')();
        $html = ob_get_clean();

        $field === '' ? $this->assertSame('', $html) : $this->assertStringContainsString($field, $html);
    }

    #[RunInSeparateProcess]
    public function testRestrictedPagesAndActionsAreBlocked(): void
    {
        $GLOBALS['pagenow'] = 'plugin-install.php';
        $this->assertDies(fn() => $this->hook('admin_init', 2)(), 'This feature is disabled');
        $GLOBALS['pagenow'] = 'edit.php';
        $this->assertNull($this->hook('admin_init', 2)());

        $referer = $this->hook('check_admin_referer');
        $this->assertDies(fn() => $referer('delete-plugin_x'), 'Plugin deletion');
        $this->assertDies(fn() => $referer('plugin-upload'), 'Plugin upload');
        $this->assertNull($referer('bulk-posts'));

        $this->assertDies(fn() => $this->hook('personal_options_update')(1), 'Profile editing');
        $this->assertDies(fn() => $this->hook('edit_user_profile_update')(1), 'Profile editing');
    }

    #[RunInSeparateProcess]
    public function testMenusCommentsAndAdminBarAreTrimmed(): void
    {
        $removed = [];
        $record = function (...$args) use (&$removed) {
            $removed[] = implode(':', $args);
        };
        Functions\when('remove_menu_page')->alias($record);
        Functions\when('remove_submenu_page')->alias($record);
        Functions\when('remove_post_type_support')->alias($record);
        $bar = new class {
            public array $gone = [];
            public function remove_menu($id) { $this->gone[] = $id; }
            public function remove_node($id) { $this->gone[] = $id; }
        };
        $GLOBALS['wp_admin_bar'] = $bar;

        $this->hook('admin_menu')();
        $this->hook('admin_menu', 1)();
        $this->hook('init', 1)();
        $this->hook('wp_before_admin_bar_render')();
        $this->hook('admin_bar_menu')($bar);

        $this->assertContains('plugins.php:plugin-install.php', $removed);
        $this->assertContains('users.php:profile.php', $removed);
        $this->assertContains('post:comments', $removed);
        $this->assertSame(['comments', 'edit-profile', 'user-info'], $bar->gone);
    }

    #[RunInSeparateProcess]
    public function testPluginDefaultsAreSetOnlyWhenTheConnectorIsActive(): void
    {
        $GLOBALS['washTestActivePlugins'] = [];
        $written = [];
        Functions\when('get_option')->alias(fn($name) => $name === 'wash_files_enabled' ? '0' : ($name === 'wash_sync_on_publish' ? '' : false));
        Functions\when('update_option')->alias(function ($name, $value) use (&$written) {
            $written[$name] = $value;
        });

        $this->hook('init', 2)();
        $this->assertSame([], $written);

        $GLOBALS['washTestActivePlugins'] = ['wash-blog-manager/wash.php'];
        $this->hook('init', 2)();
        $this->assertSame(['wash_sync_on_publish' => '1', 'wash_convert_images_to_webp' => '1'], $written);
    }

    #[RunInSeparateProcess]
    public function testDashboardWidgetGoesFirst(): void
    {
        Functions\when('remove_meta_box')->justReturn(true);
        Functions\when('wp_add_dashboard_widget')->alias(function ($id) {
            $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'][$id] = ['id' => $id];
        });
        $GLOBALS['wp_meta_boxes'] = ['dashboard' => ['normal' => ['core' => ['dashboard_activity' => ['id' => 'a']]]]];

        $this->hook('wp_dashboard_setup')();

        $this->assertSame(['wash_status_widget', 'dashboard_activity'], array_keys($GLOBALS['wp_meta_boxes']['dashboard']['normal']['core']));
    }

    #[RunInSeparateProcess]
    public function testDashboardSetupWithoutTheWidgetRegistered(): void
    {
        Functions\when('remove_meta_box')->justReturn(true);
        Functions\when('wp_add_dashboard_widget')->justReturn(null);
        $GLOBALS['wp_meta_boxes'] = [];

        $this->hook('wp_dashboard_setup')();

        $this->assertSame([], $GLOBALS['wp_meta_boxes']);
    }

    public static function widgetStates(): array
    {
        return [
            'connected with blog' => [['wash_shopify_store' => 'shop', 'wash_api_key' => 'k', 'wash_blog_name' => 'News'], ['Connected', 'News', 'shop.myshopify.com']],
            'connected, no blog' => [['wash_shopify_store' => 'shop', 'wash_api_key' => 'k'], ['Connected', 'shop.myshopify.com']],
            'not configured' => [[], ['Not Configured', 'Configure']],
        ];
    }

    #[RunInSeparateProcess]
    #[DataProvider('widgetStates')]
    public function testDashboardWidgetShowsTheConnection(array $options, array $expected): void
    {
        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', '/wp/plugins');
        }
        Functions\when('get_option')->alias(fn($name, $default = '') => $options[$name] ?? $default);
        Functions\when('plugins_url')->alias(fn($path, $file) => 'https://demo/' . basename(dirname($file)));
        Functions\when('admin_url')->alias(fn($path) => 'https://demo/wp-admin/' . $path);
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html')->returnArg();

        ob_start();
        washDashboardWidget();
        $html = ob_get_clean();

        $this->assertStringContainsString('https://demo/wash-blog-manager/admin/assets/logo.png', $html);
        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    private function assertDies(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
