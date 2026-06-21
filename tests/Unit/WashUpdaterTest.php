<?php
/**
 * Tests for WashUpdater — the WordPress plugin auto-updater (LOW-6, #167).
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-updater.php';

class WashUpdaterTest extends TestCase
{
    private \WashUpdater $updater;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (!defined('WASH_PLUGIN_BASENAME')) {
            define('WASH_PLUGIN_BASENAME', 'wash/wash.php');
        }
        if (!defined('WASH_VERSION')) {
            define('WASH_VERSION', '1.26.8');
        }
        $this->updater = new \WashUpdater();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mockApi(array $plugin, bool $success = true): void
    {
        Functions\when('get_option')->alias(fn($key, $default = '') => match ($key) {
            'wash_app_url' => 'https://app.example',
            'wash_api_key' => 'wp4s_key',
            default        => $default,
        });
        Functions\when('wp_remote_get')->justReturn(['body' => json_encode(['success' => $success, 'plugin' => $plugin])]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
    }

    private function checkedTransient(): object
    {
        return (object) ['checked' => ['wash/wash.php' => '1.26.8'], 'response' => []];
    }

    public function testRegisterAddsBothFilters(): void
    {
        $hooks = [];
        Functions\when('add_filter')->alias(function ($hook) use (&$hooks) {
            $hooks[] = $hook;
            return true;
        });

        $this->updater->register();

        $this->assertContains('pre_set_site_transient_update_plugins', $hooks);
        $this->assertContains('plugins_api', $hooks);
    }

    public function testCheckForUpdateInjectsWhenUpdateAvailable(): void
    {
        $this->mockApi([
            'updateAvailable' => true,
            'latestVersion'   => '1.30.0',
            'downloadUrl'     => 'https://app.example/api/plugin-download?token=abc.def',
        ]);

        $result = $this->updater->checkForUpdate($this->checkedTransient());

        $this->assertArrayHasKey('wash/wash.php', $result->response);
        $entry = $result->response['wash/wash.php'];
        $this->assertSame('1.30.0', $entry->new_version);
        $this->assertSame('wash', $entry->slug);
        $this->assertStringContainsString('token=', $entry->package);
    }

    public function testCheckForUpdateDefersToWpOrgWhenItAlreadyOffered(): void
    {
        // wp.org is the primary channel: if it already populated an update for our
        // plugin, the app updater must not override it (#167 fallback model).
        $this->mockApi(['updateAvailable' => true, 'latestVersion' => '1.30.0', 'downloadUrl' => 'https://app/d?token=a']);
        $transient = (object) [
            'checked'  => ['wash/wash.php' => '1.26.8'],
            'response' => ['wash/wash.php' => (object) ['new_version' => '1.31.0']],
        ];

        $result = $this->updater->checkForUpdate($transient);

        $this->assertSame('1.31.0', $result->response['wash/wash.php']->new_version);
    }

    public function testCheckForUpdateSkipsWhenNoUpdateAvailable(): void
    {
        $this->mockApi(['updateAvailable' => false, 'latestVersion' => '1.26.8']);

        $result = $this->updater->checkForUpdate($this->checkedTransient());

        $this->assertArrayNotHasKey('wash/wash.php', $result->response);
    }

    public function testCheckForUpdateSkipsWhenTransientNotChecked(): void
    {
        $result = $this->updater->checkForUpdate((object) ['checked' => [], 'response' => []]);
        $this->assertEmpty($result->response);
    }

    public function testCheckForUpdateSkipsWhenSiteNotConnected(): void
    {
        Functions\when('get_option')->justReturn(''); // no app url / api key
        $result = $this->updater->checkForUpdate($this->checkedTransient());
        $this->assertEmpty($result->response);
    }

    public function testCheckForUpdateSkipsOnApiError(): void
    {
        Functions\when('get_option')->alias(fn($key, $default = '') => match ($key) {
            'wash_app_url' => 'https://app.example',
            'wash_api_key' => 'wp4s_key',
            default        => $default,
        });
        Functions\when('wp_remote_get')->justReturn(['body' => '']);
        Functions\when('is_wp_error')->justReturn(true); // transport error

        $result = $this->updater->checkForUpdate($this->checkedTransient());
        $this->assertEmpty($result->response);
    }

    public function testPluginInfoReturnsDetailsForOurSlug(): void
    {
        $this->mockApi([
            'latestVersion' => '1.30.0',
            'downloadUrl'   => 'https://app.example/api/plugin-download?token=abc.def',
            'releaseNotes'  => 'New stuff',
        ]);

        $result = $this->updater->pluginInfo(false, 'plugin_information', (object) ['slug' => 'wash']);

        $this->assertSame('1.30.0', $result->version);
        $this->assertSame('New stuff', $result->sections['changelog']);
        $this->assertStringContainsString('token=', $result->download_link);
    }

    public function testPluginInfoIgnoresOtherSlugsAndActions(): void
    {
        $this->assertFalse($this->updater->pluginInfo(false, 'plugin_information', (object) ['slug' => 'other']));
        $this->assertFalse($this->updater->pluginInfo(false, 'query_plugins', (object) ['slug' => 'wash']));
    }
}
