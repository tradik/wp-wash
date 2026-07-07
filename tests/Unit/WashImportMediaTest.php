<?php
/**
 * Tests for WashImportMedia — rehosting Shopify images into WordPress.
 * Covers featured-thumbnail sideload, inline cdn.shopify.com rewrite, dedup via
 * `_wash_src_cdn_url`, and soft-failure on sideload error.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-import-media.php';

class WashImportMediaTest extends TestCase
{
    private array $meta = [];
    private array $thumbnails = [];
    private ?string $updatedContent = null;
    private string $postContent = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->meta = [];
        $this->thumbnails = [];
        $this->updatedContent = null;
        $this->postContent = '';

        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('apply_filters')->alias(fn($hook, $value) => $value);
        // Stubbing media_sideload_image also makes function_exists() true, so
        // ensureMediaFunctions() skips the ABSPATH require.
        Functions\when('media_sideload_image')->justReturn(0);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('wp_get_attachment_url')->justReturn('https://site.test/wp-content/uploads/local.jpg');
        Functions\when('set_post_thumbnail')->alias(function ($postId, $attId) {
            $this->thumbnails[(int) $postId] = (int) $attId;
        });
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[(int) $id][$key] = $value;
        });
        Functions\when('get_post_field')->alias(fn($field, $id) => $this->postContent);
        Functions\when('wp_update_post')->alias(function ($arr) {
            $this->updatedContent = $arr['post_content'];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function article(array $overrides = []): array
    {
        return array_merge([
            'image' => ['url' => 'https://cdn.shopify.com/f.jpg', 'altText' => 'alt'],
        ], $overrides);
    }

    public function testApplySetsFeaturedThumbnail(): void
    {
        Functions\when('media_sideload_image')->justReturn(77);
        $this->postContent = '<p>no images here</p>';

        (new \WashImportMedia())->apply(9, $this->article());

        $this->assertSame(77, $this->thumbnails[9]);
        $this->assertSame('https://cdn.shopify.com/f.jpg', $this->meta[77]['_wash_src_cdn_url']);
        $this->assertNull($this->updatedContent, 'no inline cdn images → body left untouched');
    }

    public function testRejectsNonShopifyCdnHost(): void
    {
        // PLUGIN005: host validated by parsed HOST, not substring — a look-alike host
        // is refused before any fetch, and counted as a failed asset.
        Functions\expect('media_sideload_image')->never();
        $this->postContent = '';

        $failed = (new \WashImportMedia())->apply(9, $this->article(['image' => ['url' => 'https://cdn.shopify.com.evil.example/x.jpg', 'altText' => '']]));

        $this->assertArrayNotHasKey(9, $this->thumbnails);
        $this->assertSame(1, $failed);
    }

    public function testApplyReturnsFailedAssetCount(): void
    {
        // PLUGIN013: a swallowed sideload failure is now counted (and logged) so the
        // writer can report "imported with N media errors" instead of a silent success.
        Functions\when('media_sideload_image')->justReturn(new \WP_Error('http', 'boom'));
        $this->postContent = '';

        $failed = (new \WashImportMedia())->apply(9, $this->article());
        $this->assertSame(1, $failed);
    }

    public function testApplyReturnsZeroWhenAllGood(): void
    {
        Functions\when('media_sideload_image')->justReturn(77);
        $this->postContent = '<p>no images</p>';
        $this->assertSame(0, (new \WashImportMedia())->apply(9, $this->article()));
    }

    public function testApplyRewritesInlineImagesToLocalUrls(): void
    {
        Functions\when('media_sideload_image')->justReturn(88);
        $this->postContent = '<p><img src="https://cdn.shopify.com/a.jpg" /></p>';

        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));

        $this->assertNotNull($this->updatedContent);
        $this->assertStringContainsString('wp-content/uploads/local.jpg', $this->updatedContent);
        $this->assertStringNotContainsString('cdn.shopify.com', $this->updatedContent);
    }

    public function testDedupReusesExistingAttachmentWithoutSideloading(): void
    {
        Functions\when('get_posts')->justReturn([55]); // already sideloaded
        Functions\expect('media_sideload_image')->never();
        $this->postContent = '';

        (new \WashImportMedia())->apply(9, $this->article());

        $this->assertSame(55, $this->thumbnails[9]);
    }

    public function testSideloadErrorLeavesNoThumbnail(): void
    {
        Functions\when('media_sideload_image')->justReturn(new \WP_Error('http', 'download failed'));
        $this->postContent = '';

        (new \WashImportMedia())->apply(9, $this->article());

        $this->assertArrayNotHasKey(9, $this->thumbnails);
    }

    public function testCdnTextWithoutImgTagLeavesBodyUntouched(): void
    {
        $this->postContent = '<a href="https://cdn.shopify.com/page">a link, not an image</a>';
        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));
        $this->assertNull($this->updatedContent);
    }

    public function testInlineSideloadErrorSkipsRewrite(): void
    {
        Functions\when('media_sideload_image')->justReturn(new \WP_Error('http', 'boom'));
        $this->postContent = '<img src="https://cdn.shopify.com/a.jpg">';
        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));
        $this->assertNull($this->updatedContent, 'a failed inline sideload must not rewrite the body');
    }

    public function testRehostsFileAttachmentLinks(): void
    {
        Functions\when('download_url')->justReturn('/tmp/doc.pdf');
        Functions\when('media_handle_sideload')->justReturn(88);
        $this->postContent = '<a href="https://cdn.shopify.com/s/files/2024/doc.pdf">Download PDF</a>';

        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));

        $this->assertNotNull($this->updatedContent);
        $this->assertStringContainsString('wp-content/uploads/local.jpg', $this->updatedContent);
        $this->assertStringNotContainsString('cdn.shopify.com', $this->updatedContent);
        $this->assertSame('https://cdn.shopify.com/s/files/2024/doc.pdf', $this->meta[88]['_wash_src_cdn_url']);
    }

    public function testFileAttachmentDedupReusesExisting(): void
    {
        Functions\when('get_posts')->justReturn([55]);
        Functions\expect('download_url')->never();
        Functions\expect('media_handle_sideload')->never();
        $this->postContent = '<a href="https://cdn.shopify.com/s/files/2024/doc.pdf">PDF</a>';

        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));
        $this->assertStringContainsString('wp-content/uploads/local.jpg', $this->updatedContent);
    }

    public function testFileDownloadErrorSkipsRewrite(): void
    {
        Functions\when('download_url')->justReturn(new \WP_Error('http', 'download failed'));
        Functions\expect('media_handle_sideload')->never();
        $this->postContent = '<a href="https://cdn.shopify.com/s/files/2024/doc.pdf">PDF</a>';

        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));
        $this->assertNull($this->updatedContent);
    }

    public function testFileHandleSideloadErrorCleansUpTempFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wash'); // real file so file_exists() is true
        Functions\when('download_url')->justReturn($tmp);
        Functions\when('media_handle_sideload')->justReturn(new \WP_Error('x', 'sideload failed'));
        $deletedTmp = false;
        Functions\when('wp_delete_file')->alias(function ($path) use ($tmp, &$deletedTmp) {
            $deletedTmp = ($path === $tmp);
        });
        $this->postContent = '<a href="https://cdn.shopify.com/s/files/2024/doc.pdf">PDF</a>';

        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));

        $this->assertTrue($deletedTmp, 'the temp file must be cleaned up on sideload failure');
        $this->assertNull($this->updatedContent);
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }

    public function testNonFileCdnLinkIsLeftAlone(): void
    {
        // A cdn.shopify.com link that is NOT under /s/files/ is not an attachment.
        Functions\expect('download_url')->never();
        $this->postContent = '<a href="https://cdn.shopify.com/somepath">not a file</a>';

        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));
        $this->assertNull($this->updatedContent);
    }

    public function testInlineImageCapIsHonoured(): void
    {
        Functions\when('apply_filters')->alias(fn($hook, $value) => $hook === 'wash_import_max_images' ? 1 : $value);
        $calls = 0;
        Functions\when('media_sideload_image')->alias(function () use (&$calls) {
            $calls++;
            return 90 + $calls;
        });
        $this->postContent = '<img src="https://cdn.shopify.com/1.jpg"><img src="https://cdn.shopify.com/2.jpg">';

        (new \WashImportMedia())->apply(9, $this->article(['image' => null]));

        $this->assertSame(1, $calls, 'only the first inline image is sideloaded when capped at 1');
    }
}
