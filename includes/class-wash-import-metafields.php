<?php
/**
 * Reverse import metafields: write a Shopify resource's metafields into WordPress
 * post meta (optional — the "Import metafields" tick).
 *
 * wash-owned namespaces round-trip to a bare key (the inverse of the push, which
 * writes WP custom fields into a wash namespace); other namespaces are prefixed so
 * an imported metafield never clobbers core or another plugin's meta. Protected
 * (underscore-prefixed) keys are skipped. JSON-typed values are decoded to arrays.
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

class WashImportMetafields {

    /**
     * Import every metafield on the article/page as post meta.
     *
     * @param int   $postId  Post ID.
     * @param array $article Normalized article/page carrying `metafields`.
     */
    public function apply($postId, array $article) {
        foreach (($article['metafields'] ?? array()) as $metafield) {
            if (empty($metafield['key'])) {
                continue;
            }
            $metaKey = $this->metaKey($metafield['namespace'] ?? '', $metafield['key']);
            if ($metaKey === '' || $metaKey[0] === '_') {
                continue; // never write protected (underscore-prefixed) meta.
            }
            update_post_meta($postId, $metaKey, $this->decodeValue($metafield));
        }
    }

    /**
     * Post-meta key for an imported metafield.
     *
     * @param string $namespace Shopify metafield namespace.
     * @param string $key       Shopify metafield key.
     * @return string
     */
    private function metaKey($namespace, $key) {
        // Only an exact `wash` namespace (or `wash_*`) round-trips to a bare key — the
        // inverse of the push. Everything else (including Shopify's app-owned `custom`/
        // `global`) is PREFIXED, so an imported metafield never clobbers another plugin's
        // post-meta (e.g. `custom.rank_math_title` → `custom_rank_math_title`, not
        // RankMath's `rank_math_title`), and an unrelated namespace like `washington` is
        // not mistaken for wash (PLUGIN014).
        if ($namespace === '' || $namespace === 'wash' || strpos($namespace, 'wash_') === 0) {
            return $key;
        }
        return $namespace . '_' . $key;
    }

    /**
     * Decode a metafield value — JSON types become arrays, everything else stays a string.
     *
     * @param array $metafield Metafield node { value, type }.
     * @return mixed
     */
    private function decodeValue(array $metafield) {
        $value = $metafield['value'] ?? '';
        // Decode ONLY when Shopify declares the type as json — never guess from a leading
        // '{'/'[', which would mis-decode a single_line_text_field whose value happens to
        // be valid JSON (e.g. '["a","b"]') and corrupt the round-trip type (PLUGIN014).
        if (($metafield['type'] ?? '') === 'json') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $value;
    }
}
