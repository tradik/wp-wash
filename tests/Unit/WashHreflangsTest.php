<?php
/**
 * Unit tests for WashHreflangs class.
 * Tests: getTranslationGroupSource, getAvailableLanguages via static cache bypass.
 * NOTE: detectActivePlugin relies on function_exists/class_exists which can't be mocked
 * via Brain\Monkey. Instead, we set the static $activePlugin cache directly.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';

class WashHreflangsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Reset static cached plugin detection between tests
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Force the static $activePlugin cache to a specific value. */
    private function setActivePlugin(?string $plugin): void
    {
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, $plugin === null ? null : $plugin);
    }

    /** Force active plugin and let detection return cached value. */
    private function forcePlugin(string $plugin): void
    {
        // Set cache so detectActivePlugin returns immediately
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, $plugin);
    }

    // ------------------------------------------------------------------
    // detectActivePlugin (cached behavior only — can't mock function_exists)
    // ------------------------------------------------------------------

    public function testDetectActivePluginReturnsCachedValue(): void
    {
        $this->forcePlugin('polylang');
        $hreflangs = new \WashHreflangs();
        $result = $hreflangs->detectActivePlugin();
        $this->assertSame('polylang', $result);
    }

    public function testDetectActivePluginReturnsFalseWhenCachedFalse(): void
    {
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, false);

        $hreflangs = new \WashHreflangs();
        $result = $hreflangs->detectActivePlugin();
        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // getTranslationGroupSource — Polylang
    // ------------------------------------------------------------------

    public function testGetTranslationGroupSourcePolylang(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'de' => 20]);
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('pll_get_post_language')->justReturn('de');

        $hreflangs = new \WashHreflangs();
        $result = $hreflangs->getTranslationGroupSource(20);

        $this->assertSame(10, $result['sourcePostId']);
        $this->assertSame('en', $result['sourceLang']);
        $this->assertSame('de', $result['currentLang']);
        $this->assertFalse($result['isSource']);
    }

    public function testGetTranslationGroupSourcePolylangIsSource(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('pll_get_post_translations')->justReturn(['en' => 10, 'de' => 20]);
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('pll_get_post_language')->justReturn('en');

        $hreflangs = new \WashHreflangs();
        $result = $hreflangs->getTranslationGroupSource(10);

        $this->assertSame(10, $result['sourcePostId']);
        $this->assertTrue($result['isSource']);
    }

    public function testGetTranslationGroupSourceReturnsNullForUnknownPlugin(): void
    {
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, false);

        $hreflangs = new \WashHreflangs();
        $result = $hreflangs->getTranslationGroupSource(1);
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // getAvailableLanguages
    // ------------------------------------------------------------------

    public function testGetAvailableLanguagesReturnsPolylangList(): void
    {
        $this->forcePlugin('polylang');

        Functions\when('pll_languages_list')->alias(function ($args) {
            if (($args['fields'] ?? '') === 'slug') {
                return ['en', 'de', 'fr'];
            }
            if (($args['fields'] ?? '') === 'name') {
                return ['English', 'Deutsch', 'Français'];
            }
            return [];
        });

        $hreflangs = new \WashHreflangs();
        $result = $hreflangs->getAvailableLanguages();

        $this->assertCount(3, $result);
        $this->assertSame('en', $result[0]['code']);
        $this->assertSame('English', $result[0]['name']);
        $this->assertSame('de', $result[1]['code']);
    }

    public function testGetAvailableLanguagesReturnsEmptyWhenNoPlugin(): void
    {
        $ref = new \ReflectionProperty(\WashHreflangs::class, 'activePlugin');
        $ref->setAccessible(true);
        $ref->setValue(null, false);

        $hreflangs = new \WashHreflangs();
        $result = $hreflangs->getAvailableLanguages();
        $this->assertEmpty($result);
    }
}
