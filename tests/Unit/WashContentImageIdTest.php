<?php
/**
 * Unit tests for content-image attachment resolution in WashSync:
 *   - getAttachmentIdFromUrl(): `-scaled` fallback for sized thumbnails of
 *     WordPress `-scaled` originals (images >2560px).
 *   - buildContentImageIdMap(): canonical `wp-image-<id>` class read straight
 *     off each <img>, independent of URL/host/size resolution.
 *
 * Regression guard for content images that stayed on the protected source host
 * (e.g. oldsite.hilo.com) instead of being rehosted to the Shopify CDN.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class WashContentImageIdTest extends TestCase
{
    private $sync;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'test-store',
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://oldsite.hilo.com');
        Functions\when('error_log')->justReturn(true);

        $this->sync = new \WashSync();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->sync, ...$args);
    }

    /**
     * A sized thumbnail of a `-scaled` original: stripping the size gives
     * `…hilo-1.jpg`, which never matches the stored `…hilo-1-scaled.jpg`. The
     * resolver must try the `-scaled` form before giving up.
     */
    public function testGetAttachmentIdFromUrlResolvesScaledThumbnail(): void
    {
        // Only the `-scaled` original is a real attachment; the bare base is not.
        Functions\when('attachment_url_to_postid')->alias(
            fn ($url) => str_contains($url, '-scaled.jpg') ? 302249 : 0
        );

        $url = 'https://oldsite.hilo.com/wp-content/uploads/2025/11/foo-hilo-1-1024x785.jpg';
        $this->assertSame(302249, $this->invokePrivate('getAttachmentIdFromUrl', [$url]));
    }

    /** The directly-referenced `-scaled` original still resolves (sanity). */
    public function testGetAttachmentIdFromUrlResolvesScaledOriginalDirectly(): void
    {
        Functions\when('attachment_url_to_postid')->alias(
            fn ($url) => str_contains($url, '-scaled.jpg') ? 302249 : 0
        );

        $url = 'https://oldsite.hilo.com/wp-content/uploads/2025/11/foo-hilo-1-scaled.jpg';
        $this->assertSame(302249, $this->invokePrivate('getAttachmentIdFromUrl', [$url]));
    }

    /** Unresolvable URL returns false, not a bogus id. */
    public function testGetAttachmentIdFromUrlReturnsFalseWhenUnknown(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $url = 'https://oldsite.hilo.com/wp-content/uploads/2025/11/missing-300x200.jpg';
        $this->assertFalse($this->invokePrivate('getAttachmentIdFromUrl', [$url]));
    }

    /**
     * The canonical `wp-image-<id>` class must map the img src straight to the
     * attachment id WITHOUT any URL resolution — proven by forcing
     * attachment_url_to_postid() to always fail.
     */
    public function testBuildContentImageIdMapReadsWpImageClass(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $src = 'https://oldsite.hilo.com/wp-content/uploads/2025/11/So-messen-hilo-1-1024x695.jpg';
        $content = '<!-- wp:image {"id":302382} -->'
            . '<figure class="wp-block-image size-large"><img src="' . $src . '" alt="" class="wp-image-302382"/></figure>'
            . '<!-- /wp:image -->';

        $map = $this->invokePrivate('buildContentImageIdMap', [$content]);
        $this->assertSame(302382, $map[$src] ?? null);
    }

    /** Attribute order is irrelevant: class before src must still map. */
    public function testBuildContentImageIdMapReadsWpImageClassBeforeSrc(): void
    {
        Functions\when('attachment_url_to_postid')->justReturn(0);

        $src = 'https://cdn.offload.example/wp-content/uploads/2025/11/pic-768x512.jpg';
        $content = '<img class="wp-image-555" src="' . $src . '"/>';

        $map = $this->invokePrivate('buildContentImageIdMap', [$content]);
        $this->assertSame(555, $map[$src] ?? null);
    }

    /** Non-<img> upload URLs (PDFs, links) still resolve via the URL fallback. */
    public function testBuildContentImageIdMapFallsBackToUrlResolution(): void
    {
        $href = 'https://oldsite.hilo.com/wp-content/uploads/2025/11/datasheet.pdf';
        Functions\when('attachment_url_to_postid')->alias(
            fn ($url) => $url === $href ? 4242 : 0
        );

        $content = '<a href="' . $href . '">Download</a>';
        $map = $this->invokePrivate('buildContentImageIdMap', [$content]);
        $this->assertSame(4242, $map[$href] ?? null);
    }
}
