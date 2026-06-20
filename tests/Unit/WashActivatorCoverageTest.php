<?php
/**
 * Coverage tests for WashActivator.
 *
 * Targets: init, activate, deactivate, onPluginUpdate,
 *          sendVersionPing, createSyncTable (contract already in WashSyncEnsureTableTest).
 *
 * createSyncTable requires ABSPATH + dbDelta (real DB) — not runnable in unit
 * context; the structural contract is already guarded in WashSyncEnsureTableTest.
 * We cover the init-time condition branches and the OPcache private method via
 * ReflectionMethod.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-activator.php';

class WashActivatorCoverageTest extends TestCase
{
    /** Options store used across tests. */
    private array $optionStore = [];

    /**
     * activate() -> createSyncTable() does an unconditional
     * `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` (for dbDelta).
     * That file doesn't exist in the unit environment, so create a minimal
     * stub here (CI has a clean /tmp; we must not rely on a leftover file).
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $dir = ABSPATH . 'wp-admin/includes';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/upgrade.php';
        if (!file_exists($file)) {
            file_put_contents(
                $file,
                '<?php if (!function_exists("dbDelta")) { function dbDelta($queries = "", $execute = true) { return []; } }'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->optionStore = [];

        // createSyncTable() reads $wpdb->prefix; provide a stub global so it
        // doesn't emit "read property on null" (failOnWarning is enabled).
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
        };

        Functions\when('error_log')->justReturn(true);
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('is_multisite')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // init()
    // ---------------------------------------------------------------

    public function testInitRegistersHooks(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(function ($hook) use (&$hooks) {
            $hooks[] = $hook;
        });

        \WashActivator::init();

        $this->assertContains('wash_ping_shopify', $hooks);
        $this->assertContains('upgrader_process_complete', $hooks);
    }

    // ---------------------------------------------------------------
    // activate() — defaults path (options not set yet)
    // ---------------------------------------------------------------

    public function testActivateSetsDefaultOptionsWhenMissing(): void
    {
        $added = [];
        Functions\when('get_option')->alias(function ($key, $default = false) {
            // Return false so the "not set" branch runs for every default.
            if ($default === false || func_num_args() === 1) {
                return false;
            }
            return $default;
        });
        Functions\when('add_option')->alias(function ($key, $value) use (&$added) {
            $added[$key] = $value;
        });
        Functions\when('get_bloginfo')->justReturn('Test Blog');
        Functions\when('delete_option')->justReturn(true);
        Functions\when('flush_rewrite_rules')->justReturn(null);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);

        \WashActivator::activate();

        $this->assertArrayHasKey('wash_app_url', $added);
        $this->assertArrayHasKey('wash_api_key', $added);
        $this->assertArrayHasKey('wash_sync_on_publish', $added);
        $this->assertArrayHasKey('wash_sync_metafields', $added);
        $this->assertArrayHasKey('wash_post_types', $added);
        $this->assertArrayHasKey('wash_auto_clear_errors', $added);
        $this->assertArrayHasKey('wash_sync_inline', $added);
    }

    public function testActivateSkipsDefaultsWhenAlreadySet(): void
    {
        $addCalled = false;
        Functions\when('get_option')->alias(function ($key, $default = false) {
            // Return a non-false value so "already set" branch runs.
            if (func_num_args() === 1 || $default === false) {
                return 'existing-value';
            }
            return $default;
        });
        Functions\when('add_option')->alias(function () use (&$addCalled) {
            $addCalled = true;
        });
        Functions\when('get_bloginfo')->justReturn('Test Blog');
        Functions\when('delete_option')->justReturn(true);
        Functions\when('flush_rewrite_rules')->justReturn(null);
        Functions\when('wp_next_scheduled')->justReturn(true); // already scheduled
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);

        \WashActivator::activate();

        // add_option should not have been called for defaults
        $this->assertFalse($addCalled);
    }

    public function testActivateMigratesLegacySyncImages(): void
    {
        // Legacy option present, new option absent.
        $deleted   = [];
        $added     = [];

        Functions\when('get_option')->alias(function ($key, $default = false) use (&$deleted) {
            if ($key === 'wash_sync_images') {
                return '1'; // legacy present
            }
            if ($key === 'wash_files_enabled') {
                return false; // new absent
            }
            // For all other defaults checks
            if (func_num_args() === 1 || $default === false) {
                return 'existing'; // skip the add_option default branch
            }
            return $default;
        });
        Functions\when('add_option')->alias(function ($key, $value) use (&$added) {
            $added[$key] = $value;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$deleted) {
            $deleted[] = $key;
        });
        Functions\when('get_bloginfo')->justReturn('Test Blog');
        Functions\when('flush_rewrite_rules')->justReturn(null);
        Functions\when('wp_next_scheduled')->justReturn(true);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);

        \WashActivator::activate();

        $this->assertContains('wash_sync_images', $deleted, 'Legacy option should be deleted');
        $this->assertArrayHasKey('wash_files_enabled', $added, 'New option should be migrated');
    }

    public function testActivateDoesNotMigrateWhenFilesEnabledAlreadySet(): void
    {
        $added = [];

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'wash_sync_images') {
                return '1'; // legacy present
            }
            if ($key === 'wash_files_enabled') {
                return '1'; // new already present
            }
            if (func_num_args() === 1 || $default === false) {
                return 'existing';
            }
            return $default;
        });
        Functions\when('add_option')->alias(function ($key, $value) use (&$added) {
            $added[$key] = $value;
        });
        Functions\when('delete_option')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Blog');
        Functions\when('flush_rewrite_rules')->justReturn(null);
        Functions\when('wp_next_scheduled')->justReturn(true);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_schedule_event')->justReturn(true);

        \WashActivator::activate();

        $this->assertArrayNotHasKey('wash_files_enabled', $added, 'Should NOT overwrite existing wash_files_enabled');
    }

    public function testActivateSchedulesSingleEventWhenNotScheduled(): void
    {
        $scheduled = [];

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if (func_num_args() === 1 || $default === false) {
                return 'existing';
            }
            return $default;
        });
        Functions\when('add_option')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Blog');
        Functions\when('delete_option')->justReturn(true);
        Functions\when('flush_rewrite_rules')->justReturn(null);
        Functions\when('wp_next_scheduled')->justReturn(false); // not scheduled yet
        Functions\when('wp_schedule_single_event')->alias(function ($ts, $hook) use (&$scheduled) {
            $scheduled[] = $hook;
        });
        Functions\when('wp_schedule_event')->alias(function ($ts, $recurrence, $hook) use (&$scheduled) {
            $scheduled[] = $hook;
        });

        \WashActivator::activate();

        $this->assertContains('wash_ping_shopify', $scheduled);
        $this->assertContains('wash_clear_stale_errors', $scheduled);
    }

    // ---------------------------------------------------------------
    // deactivate()
    // ---------------------------------------------------------------

    public function testDeactivateClearsScheduledHooks(): void
    {
        $cleared = [];
        Functions\when('wp_clear_scheduled_hook')->alias(function ($hook) use (&$cleared) {
            $cleared[] = $hook;
        });
        Functions\when('flush_rewrite_rules')->justReturn(null);

        \WashActivator::deactivate();

        $this->assertContains('wash_ping_shopify', $cleared);
        $this->assertContains('wash_clear_stale_errors', $cleared);
    }

    public function testDeactivateCallsFlushRewriteRules(): void
    {
        $flushed = false;
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('flush_rewrite_rules')->alias(function () use (&$flushed) {
            $flushed = true;
        });

        \WashActivator::deactivate();

        $this->assertTrue($flushed);
    }

    // ---------------------------------------------------------------
    // onPluginUpdate()
    // ---------------------------------------------------------------

    public function testOnPluginUpdateIgnoresNonPluginAction(): void
    {
        $pingScheduled = false;
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function () use (&$pingScheduled) {
            $pingScheduled = true;
        });

        $upgrader = new \stdClass();
        \WashActivator::onPluginUpdate($upgrader, [
            'action' => 'install', // not 'update'
            'type'   => 'plugin',
            'plugins' => [],
        ]);

        $this->assertFalse($pingScheduled, 'No ping should be scheduled for install action');
    }

    public function testOnPluginUpdateIgnoresThemeUpdate(): void
    {
        $pingScheduled = false;
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function () use (&$pingScheduled) {
            $pingScheduled = true;
        });

        \WashActivator::onPluginUpdate(new \stdClass(), [
            'action' => 'update',
            'type'   => 'theme', // not 'plugin'
            'plugins' => [],
        ]);

        $this->assertFalse($pingScheduled);
    }

    public function testOnPluginUpdateSchedulesPingForOurPlugin(): void
    {
        $pingScheduled = false;
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function () use (&$pingScheduled) {
            $pingScheduled = true;
        });

        // plugin_basename (stub in bootstrap) returns basename(dirname(file))/basename(file).
        // WASH_PLUGIN_DIR = .../wordpress-plugin/, so our plugin = "wordpress-plugin/wash.php".
        $ourPlugin = plugin_basename(WASH_PLUGIN_DIR . 'wash.php');

        \WashActivator::onPluginUpdate(new \stdClass(), [
            'action'  => 'update',
            'type'    => 'plugin',
            'plugins' => [$ourPlugin, 'other/other.php'],
        ]);

        $this->assertTrue($pingScheduled);
    }

    public function testOnPluginUpdateDoesNotSchedulePingWhenAlreadyScheduled(): void
    {
        $callCount = 0;
        Functions\when('wp_next_scheduled')->justReturn(true); // already scheduled
        Functions\when('wp_schedule_single_event')->alias(function () use (&$callCount) {
            $callCount++;
        });

        \WashActivator::onPluginUpdate(new \stdClass(), [
            'action'  => 'update',
            'type'    => 'plugin',
            'plugins' => ['wash/wash.php'],
        ]);

        $this->assertSame(0, $callCount);
    }

    public function testOnPluginUpdateSkipsOurPluginWhenNotInList(): void
    {
        $pingScheduled = false;
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function () use (&$pingScheduled) {
            $pingScheduled = true;
        });

        \WashActivator::onPluginUpdate(new \stdClass(), [
            'action'  => 'update',
            'type'    => 'plugin',
            'plugins' => ['other-plugin/other-plugin.php'], // our plugin NOT in list
        ]);

        $this->assertFalse($pingScheduled);
    }

    // ---------------------------------------------------------------
    // sendVersionPing()
    // ---------------------------------------------------------------

    public function testSendVersionPingReturnsEarlyWhenApiKeyEmpty(): void
    {
        $pingCalled = false;
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_api_key') {
                return ''; // empty key
            }
            return $default;
        });
        // If a WashApiClient::ping() is called, wp_remote_post would be invoked.
        Functions\expect('wp_remote_post')->never();

        \WashActivator::sendVersionPing();

        $this->assertFalse($pingCalled); // no side-effects
    }

    public function testSendVersionPingCallsPingWhenApiKeySet(): void
    {
        $pingCalled = false;
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'   => 'some-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });
        Functions\when('wp_remote_post')->alias(function () use (&$pingCalled) {
            $pingCalled = true;
            return ['response' => ['code' => 200], 'body' => '{}'];
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        \WashActivator::sendVersionPing();

        $this->assertTrue($pingCalled);
    }

    // ---------------------------------------------------------------
    // clearOpcache() — private; test via ReflectionMethod
    // ---------------------------------------------------------------

    public function testClearOpcacheReturnsEarlyWhenOpcacheUnavailable(): void
    {
        // opcache_get_status does not exist in this PHP environment when
        // opcache is disabled — the method returns early without throwing.
        $ref = new \ReflectionMethod(\WashActivator::class, 'clearOpcache');
        $ref->setAccessible(true);

        // Should complete without error regardless of OPcache availability.
        $ref->invoke(null);
        $this->assertTrue(true);
    }
}
