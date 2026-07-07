<?php
/**
 * Tests for WashImportTranslations — Phase B locale-variant import.
 * Covers the Polylang linking path, the no-multilingual-plugin fallback, and
 * skipping locales that are not configured in the active plugin.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-import-translations.php';

/** Harness that pins the detected multilingual plugin. */
class WashImportTranslationsHarness extends \WashImportTranslations
{
    public $plugin = null;

    protected function detectPlugin()
    {
        return $this->plugin;
    }
}

class WashImportTranslationsTest extends TestCase
{
    private array $meta = [];
    private array $pllGroup = [];
    private array $pllLanguage = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);

        $this->meta = [];
        $this->pllGroup = [];
        $this->pllLanguage = [];

        Functions\when('get_option')->justReturn('');
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('wp_strip_all_tags')->alias(fn($t) => trim(strip_tags((string) $t)));
        Functions\when('sanitize_title')->alias(fn($t) => strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $t)));
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[(int) $id][$key] = $value;
        });
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function article(): array
    {
        return ['id' => 'gid://shopify/Article/1', 'title' => 'Hello', 'bodyHtml' => '<p>en</p>', 'summaryHtml' => ''];
    }

    public function testNoMultilingualPluginIsNoOp(): void
    {
        Functions\expect('wp_insert_post')->never();
        $harness = new WashImportTranslationsHarness();
        $harness->plugin = null;

        $harness->apply(10, $this->article(), ['de' => ['title' => 'Hallo']], []);
        $this->addToAssertionCount(1);
    }

    public function testPolylangCreatesLinkedTranslationAndStampsSkipFlag(): void
    {
        Functions\when('pll_languages_list')->justReturn(['en', 'de']);
        Functions\when('pll_get_post')->justReturn(0); // no existing translation
        Functions\when('pll_get_post_language')->justReturn('en');
        $inserted = null;
        Functions\when('wp_insert_post')->alias(function ($arr) use (&$inserted) {
            $inserted = $arr;
            return 200;
        });
        Functions\when('pll_set_post_language')->alias(function ($id, $locale) {
            $this->pllLanguage[(int) $id] = $locale;
        });
        Functions\when('pll_save_post_translations')->alias(function ($group) {
            $this->pllGroup = $group;
        });

        $harness = new WashImportTranslationsHarness();
        $harness->plugin = 'polylang';
        $harness->apply(10, $this->article(), [
            'de' => ['title' => 'Hallo', 'body' => '<p>de</p>', 'handle' => 'hallo'],
        ], []);

        $this->assertSame('Hallo', $inserted['post_title']);
        $this->assertSame('<p>de</p>', $inserted['post_content']);
        $this->assertSame('hallo', $inserted['post_name']);
        $this->assertSame('publish', $inserted['post_status']);
        $this->assertSame('de', $this->pllLanguage[200]);
        $this->assertSame(['de' => 200, 'en' => 10], $this->pllGroup);
        $this->assertSame('1', $this->meta[200]['_wash_skip_autosync']);
    }

    public function testPolylangUpdatesExistingTranslation(): void
    {
        Functions\when('pll_languages_list')->justReturn(['en', 'de']);
        Functions\when('pll_get_post')->justReturn(201); // existing translation
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_set_post_language')->justReturn(null);
        Functions\when('pll_save_post_translations')->justReturn(null);
        $updated = null;
        Functions\when('wp_update_post')->alias(function ($arr) use (&$updated) {
            $updated = $arr;
            return 201;
        });
        Functions\expect('wp_insert_post')->never();

        $harness = new WashImportTranslationsHarness();
        $harness->plugin = 'polylang';
        $harness->apply(10, $this->article(), ['de' => ['title' => 'Hallo']], []);

        $this->assertSame(201, $updated['ID']);
    }

    public function testUnconfiguredLocaleIsSkipped(): void
    {
        Functions\when('pll_languages_list')->justReturn(['en']); // 'de' not configured
        Functions\expect('wp_insert_post')->never();
        Functions\expect('wp_update_post')->never();

        $harness = new WashImportTranslationsHarness();
        $harness->plugin = 'polylang';
        $harness->apply(10, $this->article(), ['de' => ['title' => 'Hallo']], []);
        $this->addToAssertionCount(1);
    }

    public function testWpmlCreatesLinkedTranslation(): void
    {
        // WPML treats every returned locale as configured; links via wpml actions.
        Functions\when('apply_filters')->alias(fn($hook, $default = null) => match ($hook) {
            'wpml_object_id' => 0,               // no existing translation
            'wpml_element_trid' => 555,
            'wpml_element_language_code' => 'en',
            default => $default,
        });
        $inserted = null;
        Functions\when('wp_insert_post')->alias(function ($arr) use (&$inserted) {
            $inserted = $arr;
            return 300;
        });
        $langDetails = null;
        Functions\when('do_action')->alias(function ($hook, $args = null) use (&$langDetails) {
            if ($hook === 'wpml_set_element_language_details') {
                $langDetails = $args;
            }
        });

        $harness = new WashImportTranslationsHarness();
        $harness->plugin = 'wpml';
        $harness->apply(10, $this->article(), ['de' => ['title' => 'Hallo']], []);

        $this->assertSame('Hallo', $inserted['post_title']);
        $this->assertSame('1', $this->meta[300]['_wash_skip_autosync']);
        $this->assertSame(300, $langDetails['element_id']);
        $this->assertSame('de', $langDetails['language_code']);
        $this->assertSame(555, $langDetails['trid']);
    }

    public function testPolylangVariantUsesPageTypeForPages(): void
    {
        Functions\when('pll_languages_list')->justReturn(['en', 'de']);
        Functions\when('pll_get_post')->justReturn(0);
        Functions\when('pll_get_post_language')->justReturn('en');
        Functions\when('pll_set_post_language')->justReturn(null);
        Functions\when('pll_save_post_translations')->justReturn(null);
        $inserted = null;
        Functions\when('wp_insert_post')->alias(function ($arr) use (&$inserted) {
            $inserted = $arr;
            return 400;
        });

        $harness = new WashImportTranslationsHarness();
        $harness->plugin = 'polylang';
        $harness->apply(10, $this->article(), ['de' => ['title' => 'Über uns']], ['postType' => 'page']);

        $this->assertSame('page', $inserted['post_type']);
    }

    public function testRealDetectPluginNoOpWhenNoPlugin(): void
    {
        // Exercise the real detectPlugin() path: no multilingual plugin active
        // (bootstrap's is_plugin_active() returns false) → import is a no-op.
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
        Functions\expect('wp_insert_post')->never();

        (new \WashImportTranslations())->apply(10, $this->article(), ['de' => ['title' => 'Hallo']], []);
        $this->addToAssertionCount(1);
    }
}
