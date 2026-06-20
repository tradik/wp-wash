<?php
/**
 * Guideline 11 (no admin-dashboard hijacking) tests for WashAdmin notices.
 * The "not connected" setup notice must be scoped to the content screens where
 * it's actionable (post list + editor) and must NOT appear dashboard-wide.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

class WashAdminNoticesTest extends TestCase
{
    private \WashAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        // Unconnected: empty api key => isConfigured() === false.
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_app_url' => 'https://app-wash.tradik.dev',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('__')->alias(fn($t) => $t);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . $p);

        $this->admin = new \WashAdmin();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render(string $screenBase): string
    {
        Functions\when('get_current_screen')->justReturn((object) ['base' => $screenBase]);
        ob_start();
        $this->admin->renderSyncNotices();
        return (string) ob_get_clean();
    }

    public function testNotConnectedNoticeIsHiddenOnDashboard(): void
    {
        $this->assertStringNotContainsString('not connected', strtolower($this->render('dashboard')));
    }

    public function testNotConnectedNoticeIsHiddenOnPluginsScreen(): void
    {
        $this->assertStringNotContainsString('not connected', strtolower($this->render('plugins')));
    }

    public function testNotConnectedNoticeShowsOnPostListAndIsDismissible(): void
    {
        $html = $this->render('edit');
        $this->assertStringContainsString('not connected', strtolower($html));
        $this->assertStringContainsString('is-dismissible', $html);
    }

    public function testNotConnectedNoticeShowsInPostEditor(): void
    {
        $this->assertStringContainsString('not connected', strtolower($this->render('post')));
    }
}
