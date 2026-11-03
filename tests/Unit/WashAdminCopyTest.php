<?php
/**
 * Admin copy that reviewers saw wrong on the demo site (#303 screencast):
 * - #329: Meta Fields status read "Saved &amp; synced to Shopify";
 * - #330: Search & Replace pointed at an "Add Rule" button that doesn't exist,
 *   and the Export alert reused that empty-table text.
 */
namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

class WashAdminCopyTest extends TestCase
{
    /** @var array<string,array> wp_localize_script() data by object name */
    private array $localized = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
        Functions\when('get_option')->alias(fn ($key, $default = false) => $default);
        Functions\when('__')->returnArg(1);
        Functions\when('apply_filters')->alias(fn ($_, $v) => $v);
        Functions\when('admin_url')->alias(fn ($p = '') => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('filemtime')->justReturn(1);
        Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) {
            $this->localized[$name] = $data;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    }

    public function testSearchReplaceStringsNameTheRealButtons(): void
    {
        (new \WashAdmin())->enqueueAssets('settings_page_wash');
        $i18n = $this->localized['washAdmin']['i18n'];
        $this->assertStringNotContainsString('Add Rule', $i18n['srNoRules']);
        $this->assertStringContainsString('Text, URL, Shortcode or Block', $i18n['srNoRules']);
        $this->assertSame('No rules to export.', $i18n['srNothingToExport']);
    }

    public function testExportAlertUsesItsOwnString(): void
    {
        $js = $this->source('admin/js/search-replace.js');
        $this->assertStringContainsString('alert(i18n("srNothingToExport"', $js);
        $this->assertStringNotContainsString('alert(i18n("srNoRules"', $js);
    }

    public function testMetaFieldsStatusIsJsonEncodedNotEscJs(): void
    {
        // esc_js() turns & into &amp;, which textContent shows literally.
        $view = $this->source('admin/views/settings-page.php');
        $this->assertStringContainsString("wp_json_encode(__('Saved & synced to Shopify'", $view);
        $this->assertStringNotContainsString("esc_js(__('Saved & synced to Shopify'", $view);
    }
}
