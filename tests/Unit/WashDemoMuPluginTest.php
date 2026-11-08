<?php
/**
 * Demo site mu-plugin (packages/wordpress/mu-plugins/wash-demo.php): the
 * Wash Connector folder (#389), the WP-CLI exemption for in-place updates
 * (#388) and the demo restrictions. The file defines constants and functions
 * when it loads, so every test loads it in its own process.
 */
namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class WashDemoMuPluginTest extends TestCase
{
    /** @var array<string, callable[]> Callbacks the mu-plugin registered, by hook. */
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
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function load(): void
    {
        require dirname(__DIR__, 3) . '/wordpress/mu-plugins/wash-demo.php';
    }

    /** Run every closure registered on a hook; returns the last result. */
    private function fire(string $hook, ...$args)
    {
        $result = null;
        foreach ($this->hooks[$hook] ?? [] as $callback) {
            if ($callback instanceof \Closure) {
                $result = $callback(...$args);
            }
        }
        return $result;
    }

    #[RunInSeparateProcess]
    public function testSiteUrlComesFromTheEnvironmentAndFileModsAreLocked(): void
    {
        putenv('WORDPRESS_SITE_URL=https://demo.example');
        $this->load();

        $this->assertSame('https://demo.example', WASH_SITE_URL);
        $this->assertSame('wash-blog-manager/wash.php', WASH_DEMO_PLUGIN);
        $this->assertTrue(DISALLOW_FILE_MODS);
        $this->assertTrue(DISALLOW_FILE_EDIT);
    }

    #[RunInSeparateProcess]
    public function testWpCliMayModifyFilesSoPluginsUpdateInPlace(): void
    {
        define('WP_CLI', true);
        $_SERVER['HTTP_HOST'] = 'host.example';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        putenv('WORDPRESS_SITE_URL');
        $this->load();

        $this->assertFalse(defined('DISALLOW_FILE_MODS'));
        $this->assertSame('https://host.example', WASH_SITE_URL);
        $this->assertSame('on', $_SERVER['HTTPS']);
    }

    #[RunInSeparateProcess]
    public function testFallbackSiteUrl(): void
    {
        unset($_SERVER['HTTP_HOST']);
        putenv('WORDPRESS_SITE_URL');
        $this->load();

        $this->assertSame('https://demo-wash.tradik.com', WASH_SITE_URL);
    }

    #[RunInSeparateProcess]
    public function testActivatesTheConnectorAndDropsTheOldFolderEntry(): void
    {
        $this->load();
        $saved = null;
        Functions\when('get_option')->justReturn(['wash/wash.php', 'polylang/polylang.php']);
        Functions\when('update_option')->alias(function ($name, $value) use (&$saved) {
            $saved = [$name, $value];
        });
        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', dirname(__DIR__, 3));
        }
        // dirname(__DIR__, 3) . '/wash-blog-manager/wash.php' doesn't exist: no activation, only the cleanup.
        $this->fire('admin_init');

        $this->assertSame(['active_plugins', ['polylang/polylang.php']], $saved);
    }

    #[RunInSeparateProcess]
    public function testAddsTheConnectorWhenItsFileExists(): void
    {
        $dir = sys_get_temp_dir() . '/wash-demo-' . getmypid();
        @mkdir($dir . '/wash-blog-manager', 0777, true);
        touch($dir . '/wash-blog-manager/wash.php');
        define('WP_PLUGIN_DIR', $dir);
        $this->load();
        $saved = [];
        Functions\when('get_option')->justReturn(['polylang/polylang.php']);
        Functions\when('update_option')->alias(function ($name, $value) use (&$saved) {
            $saved[] = $value;
        });
        $GLOBALS['pagenow'] = 'index.php';

        $this->fire('admin_init');
        Functions\when('get_option')->justReturn(['polylang/polylang.php', 'wash-blog-manager/wash.php']);
        $this->fire('admin_init'); // already active: nothing to write

        $this->assertSame([['polylang/polylang.php', 'wash-blog-manager/wash.php']], $saved);
        unlink($dir . '/wash-blog-manager/wash.php');
    }

    #[RunInSeparateProcess]
    public function testOptionFiltersPinTheDemoSettings(): void
    {
        putenv('WORDPRESS_SITE_URL=https://demo.example');
        $this->load();
        $first = fn(string $hook) => $this->hooks[$hook][0];

        $this->assertSame('https://demo.example', $first('option_siteurl')());
        $this->assertSame('https://demo.example', $first('option_home')());
        $this->assertSame('https://demo.example/x', $first('plugins_url')('http://auto/x'));
        $this->assertSame('https://demo.example/c', $first('content_url')('https://auto/c'));
        $this->assertSame('https://demo.example/a.jpg', $first('wp_get_attachment_url')('https://auto/a.jpg'));
        $uploads = $first('upload_dir')(['baseurl' => 'https://auto/u', 'url' => 'http://auto/u/1']);
        $this->assertSame(['baseurl' => 'https://demo.example/u', 'url' => 'https://demo.example/u/1'], $uploads);
        $this->assertSame('wash demo', $first('option_blogname')());
        $this->assertStringContainsString('Demo website', $first('option_blogdescription')());
        $this->assertSame('support@tradik.com', $first('option_admin_email')());
        $this->assertSame('old@x', $first('pre_update_option_admin_email')('new@x', 'old@x'));
        $this->assertSame('0', $first('option_users_can_register')());
        $this->assertSame('subscriber', $first('option_default_role')());
        $this->assertSame('110', $first('option_mailserver_port')());
        $this->assertSame('1', $first('option_default_email_category')());
        $this->assertSame('0', $first('pre_update_option_blog_public')('1', '1'));
        $this->assertSame(512 * 1024, $first('upload_size_limit')());
        $this->assertSame('closed', $first('option_default_ping_status')());
        $this->assertSame('closed', $first('option_default_comment_status')());
        $this->assertSame(['activate' => 'a'], $first('plugin_action_links')(['activate' => 'a', 'delete' => 'd'], 'x.php'));
        $this->assertSame(['number' => 0], $first('dashboard_recent_comments_query_args')(['number' => 5]));
    }

    #[RunInSeparateProcess]
    public function testCleansTheDatabaseOnAFreshStartOnly(): void
    {
        define('WP_PLUGIN_DIR', '/nonexistent');
        ini_set('error_log', '/dev/null');
        $this->load();
        $marker = ABSPATH . '.wash-demo-initialized';
        @mkdir(ABSPATH, 0777, true);
        @unlink($marker);
        $deleted = [];
        Functions\when('get_posts')->alias(fn($args) => [(object) ['ID' => $args['post_type'] === 'attachment' ? 1 : 2]]);
        Functions\when('wp_delete_attachment')->alias(function ($id) use (&$deleted) {
            $deleted[] = "media:$id";
        });
        Functions\when('wp_delete_post')->alias(function ($id) use (&$deleted) {
            $deleted[] = "post:$id";
        });
        $GLOBALS['wpdb'] = new class {
            public string $options = 'wp_options';
            public string $posts = 'wp_posts';
            public array $queries = [];
            public function query($sql) { $this->queries[] = $sql; }
        };
        Functions\when('remove_post_type_support')->justReturn(true);

        $this->fire('init'); // fresh start: wipes, then writes the marker
        $this->fire('init'); // marker present: nothing more

        $this->assertSame(['media:1', 'post:2', 'post:2'], $deleted);
        $this->assertCount(4, $GLOBALS['wpdb']->queries);
        $this->assertFileExists($marker);
        unlink($marker);
    }
}
