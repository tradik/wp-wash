<?php
/**
 * Reverse import media handler: rehosts Shopify images into the WordPress media
 * library. Featured image → post thumbnail; inline `cdn.shopify.com` images →
 * sideloaded attachments with the body rewritten to the local URLs (the inverse
 * of the push's URL rewrite).
 *
 * Runs while $GLOBALS['wash_importing'] is set, so the attachments it creates do
 * NOT get pushed back to Shopify by WashImages. Idempotent: each source CDN URL
 * is sideloaded once (deduped via `_wash_src_cdn_url` attachment meta).
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

class WashImportMedia {

    /** @var int Count of assets that could not be rehosted this run (logged, surfaced to the writer). */
    private $failedAssets = 0;

    /**
     * Apply featured + inline images and file attachments for a post.
     *
     * @param int   $postId  Post ID.
     * @param array $article Normalized article.
     * @return int Number of assets that failed to rehost (0 = all good).
     */
    public function apply($postId, array $article) {
        $this->failedAssets = 0;
        $this->ensureMediaFunctions();
        $this->importFeatured($postId, $article);
        $this->rehostBodyAssets($postId);
        return $this->failedAssets;
    }

    /**
     * Whether a URL is hosted on the Shopify CDN — validated by parsed HOST, not a
     * substring, so `cdn.shopify.com.evil.example` and `evil?cdn.shopify.com` are
     * rejected before any fetch (PLUGIN005 / SSRF).
     *
     * @param string $url
     * @return bool
     */
    private function isShopifyCdnUrl($url) {
        return wp_parse_url($url, PHP_URL_HOST) === 'cdn.shopify.com';
    }

    /**
     * Sideload the article's featured image and set it as the post thumbnail.
     *
     * @param int   $postId  Post ID.
     * @param array $article Normalized article.
     */
    private function importFeatured($postId, array $article) {
        $url = $article['image']['url'] ?? '';
        if ($url === '') {
            return;
        }
        $attId = $this->sideloadImageOnce($url, $postId, $article['image']['altText'] ?? '');
        if ($attId) {
            set_post_thumbnail($postId, $attId);
        }
    }

    /**
     * Rehost both inline images (`<img src>`) and file attachments (`<a href>` to
     * the Files CDN — PDFs, documents, …) from cdn.shopify.com into the media
     * library, rewriting the body to the local URLs in a single save.
     *
     * @param int $postId Post ID.
     */
    private function rehostBodyAssets($postId) {
        $content = get_post_field('post_content', $postId);
        if (!is_string($content) || strpos($content, 'cdn.shopify.com') === false) {
            return;
        }

        $original = $content;
        $max      = max(0, (int) apply_filters('wash_import_max_images', 30));

        if (preg_match_all('/<img[^>]+src=["\']([^"\']*cdn\.shopify\.com[^"\']*)["\'][^>]*>/i', $content, $imgs)) {
            foreach (array_slice(array_values(array_unique($imgs[1])), 0, $max) as $url) {
                $content = $this->rewriteUrl($content, $url, $this->sideloadImageOnce($url, $postId, ''));
            }
        }
        if (preg_match_all('/<a[^>]+href=["\']([^"\']*cdn\.shopify\.com\/s\/files\/[^"\']*)["\'][^>]*>/i', $content, $links)) {
            foreach (array_slice(array_values(array_unique($links[1])), 0, $max) as $url) {
                $content = $this->rewriteUrl($content, $url, $this->sideloadFileOnce($url, $postId));
            }
        }

        if ($content !== $original) {
            // save_post fires here but $GLOBALS['wash_importing'] is set, so
            // WashSync::onSavePost() early-returns — no push back to Shopify.
            wp_update_post(array('ID' => $postId, 'post_content' => $content));
        }
    }

    /**
     * Swap a source URL for a local attachment URL in the body (no-op on failure).
     *
     * @param string $content   Post body.
     * @param string $sourceUrl Original cdn.shopify.com URL.
     * @param int    $attId     Local attachment ID (0 = skip).
     * @return string
     */
    private function rewriteUrl($content, $sourceUrl, $attId) {
        if (!$attId) {
            return $content;
        }
        $localUrl = wp_get_attachment_url($attId);
        return $localUrl ? str_replace($sourceUrl, $localUrl, $content) : $content;
    }

    /**
     * Existing attachment previously imported from this source URL, or 0 (dedup).
     *
     * @param string $url Source CDN URL.
     * @return int
     */
    private function existingAttachmentId($url) {
        $existing = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'meta_key'       => '_wash_src_cdn_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ));
        return !empty($existing) ? (int) $existing[0] : 0;
    }

    /**
     * Guard + dedup shared by both sideload paths. Returns the attachment id to
     * reuse (dedup hit), 0 when the URL is not a Shopify CDN URL (blocked + counted),
     * or null to proceed with a fresh sideload. Keeps each sideload method at ≤3
     * returns (Sonar) and the CDN/dedup rule in one place (DRY).
     *
     * @param string $url  Source URL.
     * @param string $kind 'image' | 'file' (for the log line).
     * @return int|null
     */
    private function resolveSideloadTarget($url, $kind) {
        if (!$this->isShopifyCdnUrl($url)) {
            return $this->failSideload('skipped non-Shopify-CDN ' . $kind . ' ' . $url);
        }
        $existing = $this->existingAttachmentId($url);
        return $existing ? $existing : null;
    }

    /**
     * Single failure exit: count it, log it, return 0.
     *
     * @param string $message Log detail.
     * @return int Always 0.
     */
    private function failSideload($message) {
        $this->failedAssets++;
        washLog('[wash] import: ' . $message);
        return 0;
    }

    /**
     * Persist the source-URL dedup meta and return the attachment id.
     *
     * @param int    $attId Attachment ID.
     * @param string $url   Source CDN URL.
     * @return int
     */
    private function finalizeAttachment($attId, $url) {
        update_post_meta($attId, '_wash_src_cdn_url', $url);
        return $attId;
    }

    /**
     * Sideload an image once (media_sideload_image), deduped via `_wash_src_cdn_url`.
     *
     * @param string $url    Remote image URL.
     * @param int    $postId Parent post ID.
     * @param string $alt    Alt text.
     * @return int Attachment ID, or 0 on failure.
     */
    private function sideloadImageOnce($url, $postId, $alt) {
        $target = $this->resolveSideloadTarget($url, 'image');
        if ($target !== null) {
            return $target;
        }
        $attId = media_sideload_image($url, $postId, $alt, 'id');
        if (is_wp_error($attId)) {
            return $this->failSideload('image sideload failed for ' . $url . ': ' . $attId->get_error_message());
        }
        return $this->finalizeAttachment((int) $attId, $url);
    }

    /**
     * Sideload an arbitrary file once (download_url + media_handle_sideload) so any
     * attachment type — PDF, doc, etc. — is rehosted. Deduped via `_wash_src_cdn_url`.
     *
     * @param string $url    Remote file URL (Files CDN).
     * @param int    $postId Parent post ID.
     * @return int Attachment ID, or 0 on failure.
     */
    private function sideloadFileOnce($url, $postId) {
        $target = $this->resolveSideloadTarget($url, 'file');
        if ($target !== null) {
            return $target;
        }
        $attId = $this->downloadAndSideload($url, $postId);
        if (is_string($attId)) {
            return $this->failSideload($attId);
        }
        return $this->finalizeAttachment($attId, $url);
    }

    /**
     * Download a Files-CDN URL and hand it to media_handle_sideload(). Returns the
     * new attachment id (int) on success, or a human-readable error string on
     * failure (temp file cleaned up). Split out to keep sideloadFileOnce at ≤3 returns.
     *
     * @param string $url    Remote file URL.
     * @param int    $postId Parent post ID.
     * @return int|string Attachment ID, or an error message.
     */
    private function downloadAndSideload($url, $postId) {
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return 'file download failed for ' . $url . ': ' . $tmp->get_error_message();
        }
        $path      = wp_parse_url($url, PHP_URL_PATH);
        $fileArray = array('name' => basename((string) $path), 'tmp_name' => $tmp);
        $attId     = media_handle_sideload($fileArray, $postId);
        if (is_wp_error($attId)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return 'file sideload failed for ' . $url . ': ' . $attId->get_error_message();
        }
        return (int) $attId;
    }

    /**
     * Load the WP admin media/file/image helpers used by media_sideload_image().
     */
    private function ensureMediaFunctions() {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }
}
