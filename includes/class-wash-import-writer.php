<?php
/**
 * Reverse import writer: turns one normalized Shopify article into a WordPress
 * post. The inverse of WashSync::buildPostData().
 *
 * Idempotency (D2): a post is matched by its per-connection `_wash_article_id`
 * meta == the article GID — found → update, missing → insert. The same meta the
 * push writes, so push and pull share one identity and never duplicate.
 *
 * Anti-loop (D3): the whole write runs under $GLOBALS['wash_importing'] (which
 * WashSync/WashImages short-circuit on) and every imported post is stamped
 * `_wash_skip_autosync` so it is never pushed straight back to Shopify.
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

class WashImportWriter {

    /**
     * Media helper (seam for tests).
     *
     * @return WashImportMedia
     */
    protected function media() {
        return new WashImportMedia();
    }

    /**
     * Translations helper (seam for tests).
     *
     * @return WashImportTranslations
     */
    protected function translations() {
        return new WashImportTranslations();
    }

    /**
     * Import (create or update) one article. Never pushes back to Shopify.
     *
     * @param array $article      Normalized article from GET /api/shopify-article.
     * @param array $options      Import options (status, images, blogAsCategory, skipImported).
     * @param array $translations Locale variants keyed by locale (Phase B), or empty.
     * @return true|string True on success, or a human-readable reason on failure.
     */
    public function importOne(array $article, array $options, array $translations = array()) {
        $gid = $article['id'] ?? '';
        if ($gid === '') {
            return 'missing article id';
        }

        $postType   = $this->postType($options);
        $existingId = $this->resolveExistingPostId($gid, $postType);
        if ($existingId && !empty($options['skipImported'])) {
            return true; // Leave an already-imported post untouched.
        }

        return $this->persist($article, $options, $translations, $existingId, $postType);
    }

    /**
     * Insert-or-update the post and enrich it (terms, media, metafields, identity,
     * translations) under the anti-loop global. Split from importOne() so each stays
     * at ≤3 returns (Sonar).
     *
     * @param array       $article      Normalized article.
     * @param array       $options      Import options.
     * @param array       $translations Locale variants, or empty.
     * @param int         $existingId   Existing mapped post ID, or 0 to insert.
     * @param string      $postType     'post' | 'page'.
     * @return true|string True on success, or a human-readable reason on failure.
     */
    private function persist(array $article, array $options, array $translations, $existingId, $postType) {
        $postArr = $this->buildPostArr($article, $options);
        if ($existingId) {
            $postArr['ID'] = $existingId;
        }

        $GLOBALS['wash_importing'] = true;
        try {
            $postId = $existingId ? wp_update_post($postArr, true) : wp_insert_post($postArr, true);
            if (is_wp_error($postId)) {
                return $postId->get_error_message();
            }
            $postId = (int) $postId;

            // Pages have no tags/blog → no term assignment; posts get tags + blog→category.
            if ($postType !== 'page') {
                $this->assignTerms($postId, $article, $options);
            }
            if (!empty($options['images'])) {
                $mediaFailed = $this->media()->apply($postId, $article);
                if ($mediaFailed) {
                    washLog('[wash] import: post ' . $postId . ' imported with ' . $mediaFailed . ' media error(s) — see log');
                }
            }
            if (!empty($options['metafields'])) {
                $this->metafields()->apply($postId, $article);
            }
            $this->writeIdentityMeta($postId, $article);
            if (!empty($translations)) {
                $this->translations()->apply($postId, $article, $translations, $options);
            }
        } finally {
            unset($GLOBALS['wash_importing']);
        }

        return true;
    }

    /**
     * Resolve the WordPress post already mapped to this Shopify article GID for the
     * active connection, or 0 if none.
     *
     * @param string $gid Shopify article GID.
     * @return int Post ID or 0.
     */
    public function resolveExistingPostId($gid, $postType = 'post') {
        if ($gid === '') {
            return 0;
        }
        $ids = get_posts(array(
            'post_type'      => $postType,
            'post_status'    => 'any',
            'meta_key'       => WashContext::metaKey('_wash_article_id'), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => $gid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ));
        return !empty($ids) ? (int) $ids[0] : 0;
    }

    /**
     * Map a normalized article to a wp_insert_post/wp_update_post array.
     * The inverse of WashSync::buildPostData().
     *
     * @param array $article Normalized article.
     * @param array $options Import options.
     * @return array<string,mixed>
     */
    public function buildPostArr(array $article, array $options) {
        $arr = array(
            'post_type'    => $this->postType($options),
            'post_title'   => wp_strip_all_tags($article['title'] ?? ''),
            'post_content' => $this->sanitizeContent($article['bodyHtml'] ?? ''),
            'post_excerpt' => $article['summaryHtml'] ?? '',
            'post_status'  => $this->resolveStatus($article, $options),
        );

        if (!empty($article['handle'])) {
            $arr['post_name'] = sanitize_title($article['handle']);
        }
        if (!empty($article['publishedAt'])) {
            $ts = strtotime($article['publishedAt']);
            if ($ts) {
                // wp_insert_post derives the local post_date from post_date_gmt.
                $arr['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
            }
        }
        $authorId = $this->resolveAuthorId($article['author'] ?? null);
        if ($authorId) {
            $arr['post_author'] = $authorId;
        }

        return $arr;
    }

    /**
     * Resolve the WordPress post status from the UI selector (D4).
     *
     * @param array $article Normalized article.
     * @param array $options Import options.
     * @return string 'publish' | 'draft'.
     */
    private function resolveStatus(array $article, array $options) {
        $mode = $options['status'] ?? 'draft';
        if ($mode === 'publish') {
            return 'publish';
        }
        if ($mode === 'shopify') {
            return !empty($article['isPublished']) ? 'publish' : 'draft';
        }
        return 'draft';
    }

    /**
     * Sanitize imported HTML for users without unfiltered_html (D5).
     *
     * @param string $html Raw body HTML.
     * @return string
     */
    private function sanitizeContent($html) {
        return current_user_can('unfiltered_html') ? $html : wp_kses_post($html);
    }

    /**
     * Target WordPress post type for the import ('post' for blog articles,
     * 'page' for Shopify Pages).
     *
     * @param array $options Import options.
     * @return string 'post' | 'page'.
     */
    private function postType(array $options) {
        return (($options['postType'] ?? 'post') === 'page') ? 'page' : 'post';
    }

    /**
     * Map a Shopify author display name to an existing WordPress user, or 0 to let
     * wp_insert_post default to the current importing user (D8 — never creates users).
     *
     * @param string|null $name Author display name.
     * @return int User ID or 0.
     */
    private function resolveAuthorId($name) {
        if (empty($name)) {
            return 0;
        }
        $users = get_users(array(
            'search'         => $name,
            'search_columns' => array('display_name'),
            'number'         => 1,
            'fields'         => 'ID',
        ));
        return !empty($users) ? (int) $users[0] : 0;
    }

    /**
     * Assign tags 1:1 and (optionally) the Shopify blog title as a WP category (D1).
     *
     * @param int   $postId  Post ID.
     * @param array $article Normalized article.
     * @param array $options Import options.
     */
    public function assignTerms($postId, array $article, array $options) {
        $tags = $article['tags'] ?? array();
        if (!empty($tags)) {
            wp_set_post_tags($postId, $tags, false);
        }
        if (!empty($options['blogAsCategory']) && !empty($article['blog']['title'])) {
            $termId = $this->findOrCreateCategory($article['blog']['title']);
            if ($termId) {
                wp_set_post_categories($postId, array($termId));
            }
        }
    }

    /**
     * Find or create a category by title.
     *
     * @param string $title Category title (Shopify blog title).
     * @return int Term ID or 0 on failure (e.g. insufficient permissions).
     */
    public function findOrCreateCategory($title) {
        $existing = term_exists($title, 'category');
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        $created = wp_insert_term($title, 'category');
        if (is_wp_error($created)) {
            return 0;
        }
        return (int) ($created['term_id'] ?? 0);
    }

    /**
     * Metafields import helper (seam for tests).
     *
     * @return WashImportMetafields
     */
    protected function metafields() {
        return new WashImportMetafields();
    }

    /**
     * Write the per-connection identity + anti-loop meta (D2, D3). Same keys the
     * push writes, plus `_wash_skip_autosync` so this post is not re-pushed.
     *
     * @param int   $postId  Post ID.
     * @param array $article Normalized article.
     */
    public function writeIdentityMeta($postId, array $article) {
        update_post_meta($postId, WashContext::metaKey('_wash_article_id'), $article['id']);
        update_post_meta($postId, WashContext::metaKey('_wash_blog_id'), $article['blog']['id'] ?? '');
        update_post_meta($postId, WashContext::metaKey('_wash_blog_handle'), $article['blog']['handle'] ?? '');
        update_post_meta($postId, WashContext::metaKey('_wash_synced_at'), current_time('mysql'));
        if (!empty($article['handle'])) {
            update_post_meta($postId, WashContext::metaKey('_wash_source_handle'), $article['handle']);
        }
        update_post_meta($postId, WashContext::metaKey('_wash_skip_autosync'), '1');
    }
}
