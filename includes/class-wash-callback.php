<?php
/**
 * REST API callback endpoint for receiving sync completion notifications.
 * Queue worker sends results here after job completes.
 *
 * @package Wash
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashCallback {
    /**
     * Register REST API routes.
     */
    public function register() {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register REST API routes.
     */
    public function registerRoutes() {
        // Callback endpoint for sync completion
        register_rest_route('wash/v1', '/callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleCallback'],
            'permission_callback' => [$this, 'verifyCallback'],
        ]);

        // Shopify products endpoint for Gutenberg blocks
        register_rest_route('wash/v1', '/shopify/products', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getShopifyProducts'],
            'permission_callback' => [$this, 'checkEditPermission'],
        ]);

        // Shopify collections endpoint for Gutenberg blocks
        register_rest_route('wash/v1', '/shopify/collections', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getShopifyCollections'],
            'permission_callback' => [$this, 'checkEditPermission'],
        ]);

        // Sync status endpoint for Gutenberg blocks
        register_rest_route('wash/v1', '/sync-status/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getSyncStatus'],
            'permission_callback' => [$this, 'checkEditPermission'],
            'args'                => [
                'post_id' => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ],
            ],
        ]);

        // Content stats endpoint for Shopify App dashboard
        register_rest_route('wash/v1', '/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getContentStats'],
            'permission_callback' => [$this, 'verifyApiKey'],
        ]);

        // Settings push endpoint - receives settings updates from Shopify App
        register_rest_route('wash/v1', '/settings-push', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleSettingsPush'],
            'permission_callback' => [$this, 'verifyApiKey'],
        ]);
    }

    /**
     * Verify API key from header.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error
     */
    public function verifyApiKey($request) {
        $apiKey = $request->get_header('X-Wash-Key');
        $storedApiKey = get_option('wash_api_key');

        if (empty($apiKey) || $apiKey !== $storedApiKey) {
            return new WP_Error(
                'unauthorized',
                'Invalid or missing API key',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Get content statistics with status breakdown.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function getContentStats($request) {
        // Get post counts by status
        $postCounts = wp_count_posts('post');
        $pageCounts = wp_count_posts('page');

        // Count synced posts (have _wash_article_id meta)
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- COUNT() over the postmeta join; running this through WP_Query with meta_query+counting_only is slower and the values feed a REST status endpoint called by the Shopify app, not a hot path.
        $syncedPosts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'post'
             AND p.post_status IN ('publish', 'draft', 'pending', 'private')
             AND pm.meta_key = '_wash_article_id'
             AND pm.meta_value != ''"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above; same pattern for pages.
        $syncedPages = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'page'
             AND p.post_status IN ('publish', 'draft', 'pending', 'private')
             AND pm.meta_key = '_wash_article_id'
             AND pm.meta_value != ''"
        );

        return rest_ensure_response([
            'posts' => [
                'total'     => (int) $postCounts->publish + (int) $postCounts->draft + (int) $postCounts->pending + (int) $postCounts->private,
                'publish'   => (int) $postCounts->publish,
                'draft'     => (int) $postCounts->draft,
                'pending'   => (int) $postCounts->pending,
                'private'   => (int) $postCounts->private,
                'synced'    => $syncedPosts,
            ],
            'pages' => [
                'total'     => (int) $pageCounts->publish + (int) $pageCounts->draft + (int) $pageCounts->pending + (int) $pageCounts->private,
                'publish'   => (int) $pageCounts->publish,
                'draft'     => (int) $pageCounts->draft,
                'pending'   => (int) $pageCounts->pending,
                'private'   => (int) $pageCounts->private,
                'synced'    => $syncedPages,
            ],
            'languages'     => $this->getLanguageStats(),
            'categories'    => $this->getCategoryStats(),
            'pluginVersion' => defined('WASH_VERSION') ? WASH_VERSION : null,
        ]);
    }

    /**
     * Per-language post counts. Detects Polylang or WPML; falls back to
     * single-language site (all published posts assigned to site locale).
     *
     * @return array { detector: 'polylang'|'wpml'|null, primary: string, byLanguage: array }
     */
    public function getLanguageStats() {
        if (function_exists('pll_languages_list') && function_exists('pll_count_posts')) {
            return $this->getLanguageStatsPolylang();
        }

        if (function_exists('icl_object_id')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- `wpml_active_languages` is a WPML plugin filter; we consume it, we do not own it.
            $wpmlLangs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            if (is_array($wpmlLangs) && !empty($wpmlLangs)) {
                return $this->getLanguageStatsWpml($wpmlLangs);
            }
        }

        $siteLang = function_exists('get_locale') ? substr(get_locale(), 0, 2) : 'en';
        $postCounts = wp_count_posts('post');
        return [
            'detector'   => null,
            'primary'    => $siteLang,
            'byLanguage' => [
                $siteLang => ['posts' => (int) ($postCounts->publish ?? 0), 'translatedTo' => []],
            ],
        ];
    }

    /**
     * Polylang branch — uses pll_count_posts() per language.
     *
     * @return array
     */
    private function getLanguageStatsPolylang() {
        $primary = pll_default_language();
        $languages = pll_languages_list();
        $byLanguage = [];

        foreach ($languages as $lang) {
            $count = pll_count_posts($lang, ['post_type' => 'post']);
            $byLanguage[$lang] = [
                'posts'        => (int) $count,
                'translatedTo' => array_values(array_diff($languages, [$lang])),
            ];
        }

        return [
            'detector'   => 'polylang',
            'primary'    => $primary,
            'byLanguage' => $byLanguage,
        ];
    }

    /**
     * WPML branch — uses WP_Query with suppress_filters=false to count per language.
     *
     * @param array $wpmlLangs Result of `wpml_active_languages` filter.
     * @return array
     */
    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- `wpml_default_language` and `wpml_switch_language` are WPML plugin hooks; we consume them as a WPML integration, we do not own them.
    private function getLanguageStatsWpml($wpmlLangs) {
        $primary = apply_filters('wpml_default_language', null);
        $byLanguage = [];
        $codes = [];

        foreach ($wpmlLangs as $code => $info) {
            $codes[] = $code;
        }

        foreach ($wpmlLangs as $code => $info) {
            $lang = (string) $code;
            do_action('wpml_switch_language', $lang);

            $query = new WP_Query([
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'suppress_filters' => false,
            ]);

            $byLanguage[$lang] = [
                'posts'        => (int) $query->found_posts,
                'translatedTo' => array_values(array_diff($codes, [$lang])),
            ];
        }

        do_action('wpml_switch_language', $primary);
        // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        return [
            'detector'   => 'wpml',
            'primary'    => (string) ($primary ?: 'en'),
            'byLanguage' => $byLanguage,
        ];
    }

    /**
     * Per-category published-post counts. When Polylang/WPML is active each
     * category row carries its language code (a category can exist per language
     * with the same slug, so the (slug, language) tuple is the natural key).
     *
     * @return array
     */
    public function getCategoryStats() {
        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $hasPolylang = function_exists('pll_get_term_language');
        $hasWpml = function_exists('icl_object_id');
        $stats = [];

        foreach ($terms as $term) {
            $language = null;
            if ($hasPolylang) {
                $language = pll_get_term_language($term->term_id) ?: null;
            } elseif ($hasWpml) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- `wpml_element_language_code` is a WPML plugin filter; consumed for category-language detection.
                $language = apply_filters('wpml_element_language_code', null, [
                    'element_id'   => $term->term_id,
                    'element_type' => 'category',
                ]);
            }

            $stats[] = [
                'slug'      => sanitize_title($term->slug),
                'name'      => (string) $term->name,
                'postCount' => (int) $term->count,
                'language'  => $language ? (string) $language : null,
            ];
        }

        return $stats;
    }

    /**
     * Check if user can edit posts.
     *
     * @return bool
     */
    public function checkEditPermission() {
        return current_user_can('edit_posts');
    }

    /**
     * Proxy request to Shopify App API for products.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function getShopifyProducts($request) {
        $apiClient = new WashApiClient();

        if (!$apiClient->isConfigured()) {
            return new WP_Error(
                'not_configured',
                __('Wash is not configured. Please set up your API key.', 'wash-blog-manager'),
                ['status' => 400]
            );
        }

        $handle = $request->get_param('handle');
        $endpoint = '/api/shopify-products';
        if ($handle) {
            $endpoint .= '?handle=' . urlencode($handle);
        }

        $result = $apiClient->get($endpoint);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Proxy request to Shopify App API for collections.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function getShopifyCollections($request) {
        $apiClient = new WashApiClient();

        if (!$apiClient->isConfigured()) {
            return new WP_Error(
                'not_configured',
                __('Wash is not configured. Please set up your API key.', 'wash-blog-manager'),
                ['status' => 400]
            );
        }

        $handle = $request->get_param('handle');
        $endpoint = '/api/shopify-collections';
        if ($handle) {
            $endpoint .= '?handle=' . urlencode($handle);
        }

        $result = $apiClient->get($endpoint);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Get sync status for a post.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function getSyncStatus($request) {
        $postId = intval($request->get_param('post_id'));

        $post = get_post($postId);
        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Post not found', 'wash-blog-manager'),
                ['status' => 404]
            );
        }

        $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);
        $shopifyBlogId = get_post_meta($postId, '_wash_blog_id', true);
        $shopifyBlogHandle = get_post_meta($postId, '_wash_blog_handle', true);
        $shopifyHandle = get_post_meta($postId, '_wash_handle', true);
        $shopifyUrl = get_post_meta($postId, '_wash_shopify_url', true);
        $syncedAt = get_post_meta($postId, '_wash_synced_at', true);
        $syncStatus = get_post_meta($postId, '_wash_sync_status', true);
        $jobId = get_post_meta($postId, '_wash_job_id', true);
        $queuedAt = get_post_meta($postId, '_wash_queued_at', true);
        $syncError = get_post_meta($postId, '_wash_sync_error', true);

        // Determine status
        $status = 'not_synced';
        if ($syncError) {
            $status = 'error';
        } elseif ($syncStatus === 'queued' || $jobId) {
            // Check for timeout - if queued for more than 5 minutes, consider it failed
            $queueTimeout = 5 * MINUTE_IN_SECONDS;
            if ($queuedAt && (current_time('timestamp') - strtotime($queuedAt)) > $queueTimeout) {
                $status = 'timeout';
                // Clear stale queue status
                delete_post_meta($postId, '_wash_sync_status');
                delete_post_meta($postId, '_wash_job_id');
                delete_post_meta($postId, '_wash_queued_at');
                update_post_meta($postId, '_wash_sync_error', __('Sync timed out. The callback may not have reached WordPress.', 'wash-blog-manager'));
            } else {
                $status = 'queued';
            }
        } elseif ($shopifyArticleId) {
            $status = 'synced';
        }

        return rest_ensure_response([
            'status'            => $status,
            'shopifyArticleId'  => $shopifyArticleId ?: null,
            'shopifyBlogId'     => $shopifyBlogId ?: null,
            'shopifyBlogHandle' => $shopifyBlogHandle ?: null,
            'shopifyHandle'     => $shopifyHandle ?: null,
            'shopifyUrl'        => $shopifyUrl ?: null,
            'syncedAt'          => $syncedAt ?: null,
            'jobId'             => $jobId ?: null,
            'queuedAt'          => $queuedAt ?: null,
            'errorMessage'      => $syncError ?: null,
        ]);
    }

    /**
     * Verify callback authenticity using API key.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error
     */
    public function verifyCallback($request) {
        $apiKey = $request->get_header('X-Wash-Key');
        $storedApiKey = get_option('wash_api_key');

        if (empty($apiKey) || $apiKey !== $storedApiKey) {
            return new WP_Error(
                'unauthorized',
                'Invalid or missing API key',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Handle sync completion callback.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handleCallback($request) {
        $body = $request->get_json_params();

        $wpPostId = $body['wpPostId'] ?? null;
        $wpSiteId = $body['wpSiteId'] ?? 1;
        $success = $body['success'] ?? false;
        $jobId = $body['jobId'] ?? null;
        $error = $body['error'] ?? null;

        // Shopify result data
        $shopifyArticleId = $body['shopifyArticleId'] ?? null;
        $shopifyPageId = $body['shopifyPageId'] ?? null;
        $shopifyBlogId = $body['shopifyBlogId'] ?? null;
        $shopifyBlogHandle = $body['shopifyBlogHandle'] ?? null;
        $shopifyHandle = $body['shopifyHandle'] ?? null;
        $shopifyLocale = $body['shopifyLocale'] ?? null;
        $translationGroup = $body['translationGroup'] ?? null;

        // Fallback: detect translation group server-side if not in callback payload
        if (!$translationGroup && $wpPostId) {
            $hreflangs = new WashHreflangs();
            $translationGroup = $hreflangs->getTranslationGroupSource($wpPostId);
        }

        if (empty($wpPostId)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Missing wpPostId',
            ], 400);
        }

        // For multisite, switch to the correct site
        if (is_multisite() && $wpSiteId > 1) {
            switch_to_blog($wpSiteId);
        }

        $post = get_post($wpPostId);
        if (!$post) {
            if (is_multisite() && $wpSiteId > 1) {
                restore_current_blog();
            }
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Post not found',
            ], 404);
        }

        washLog("[wash] Callback received for post {$wpPostId}, jobId: {$jobId}, success: " . ($success ? 'true' : 'false'));

        if ($success) {
            $shopifyId = $shopifyArticleId ?: $shopifyPageId;

            if ($shopifyId) {
                update_post_meta($wpPostId, '_wash_article_id', $shopifyId);
                update_post_meta($wpPostId, '_wash_synced_at', current_time('mysql'));

                if ($shopifyBlogId) {
                    update_post_meta($wpPostId, '_wash_blog_id', $shopifyBlogId);
                }
                if ($shopifyBlogHandle) {
                    update_post_meta($wpPostId, '_wash_blog_handle', $shopifyBlogHandle);
                }
                if ($shopifyHandle) {
                    update_post_meta($wpPostId, '_wash_handle', $shopifyHandle);
                }

                // Build Shopify URL with locale prefix for non-primary markets
                $shopifyStore = get_option('wash_shopify_store', '');
                $shopifyDomain = $shopifyStore ? "{$shopifyStore}.myshopify.com" : '';
                if ($shopifyDomain && $shopifyHandle) {
                    // Locale prefix for market subfolders (e.g., /de-eu, /fr-eu)
                    $localePrefix = '';
                    if ($shopifyLocale) {
                        $localePrefix = '/' . strtolower($shopifyLocale);
                    }

                    if ($shopifyBlogHandle) {
                        $shopifyUrl = "https://{$shopifyDomain}{$localePrefix}/blogs/{$shopifyBlogHandle}/{$shopifyHandle}";
                        $shopifyPath = "{$localePrefix}/blogs/{$shopifyBlogHandle}/{$shopifyHandle}";
                    } else {
                        $shopifyUrl = "https://{$shopifyDomain}{$localePrefix}/pages/{$shopifyHandle}";
                        $shopifyPath = "{$localePrefix}/pages/{$shopifyHandle}";
                    }
                    update_post_meta($wpPostId, '_wash_shopify_url', $shopifyUrl);

                    // Create automatic redirect if enabled.
                    // Skip for translation syncs — source post creates one redirect,
                    // Shopify Markets handles per-locale routing automatically.
                    // Otherwise we'd get N duplicate redirects (one per locale variant)
                    // pointing to different Shopify handles, racing each other.
                    $isTranslationSync = $translationGroup && isset($translationGroup['isSource']) && !$translationGroup['isSource'];
                    if (!$isTranslationSync && get_option('wash_redirections_enabled', '0') === '1') {
                        $this->createAutomaticRedirect($wpPostId, $shopifyPath);
                    }
                }
            }

            // Sync metafields (ACF, custom fields, SEO) to Shopify
            // Skip for non-source translations — they share the source article's metafields
            $isNonSourceTranslation = $translationGroup && isset($translationGroup['isSource']) && !$translationGroup['isSource'];
            if ($shopifyId && get_option('wash_sync_metafields') === '1' && !$isNonSourceTranslation) {
                $metafieldsHandler = new WashMetafields();
                $metaResult = $metafieldsHandler->syncAllMetafields($wpPostId, $shopifyId);

                if (is_wp_error($metaResult)) {
                    washLog("[wash] Callback: Metafields sync error for post {$wpPostId}: " . $metaResult->get_error_message());
                } else {
                    $metaCount = $metaResult['count'] ?? 0;
                    washLog("[wash] Callback: Synced {$metaCount} metafields for post {$wpPostId}");
                }
            } elseif ($isNonSourceTranslation) {
                washLog("[wash] Callback: Skipping metafield sync for non-source translation post {$wpPostId}");
            }

            // Sync hreflangs to Shopify
            if ($shopifyId && get_option('wash_hreflangs_enabled') === '1') {
                $hreflangsHandler = new WashHreflangs();
                $hrefResult = $hreflangsHandler->syncHreflangs($wpPostId, $shopifyId);

                if (is_wp_error($hrefResult)) {
                    washLog("[wash] Callback: Hreflangs sync error for post {$wpPostId}: " . $hrefResult->get_error_message());
                } else {
                    $hrefCount = $hrefResult['count'] ?? 0;
                    washLog("[wash] Callback: Synced {$hrefCount} hreflangs for post {$wpPostId}");
                }
            }

            // Clear queued status
            delete_post_meta($wpPostId, '_wash_sync_status');
            delete_post_meta($wpPostId, '_wash_job_id');
            delete_post_meta($wpPostId, '_wash_queued_at');
            delete_post_meta($wpPostId, '_wash_sync_error');
            delete_post_meta($wpPostId, '_wash_sync_error_at');

            washLog("[wash] Callback: Post {$wpPostId} synced successfully, shopifyId: {$shopifyId}");

            // Fire action for extensibility
            do_action('wash_callback_success', $wpPostId, $body);

        } else {
            // Store error with timestamp so the auto-clear cron can age them out.
            update_post_meta($wpPostId, '_wash_sync_error', $error ?: 'Unknown error');
            update_post_meta($wpPostId, '_wash_sync_error_at', current_time('mysql'));
            delete_post_meta($wpPostId, '_wash_sync_status');
            delete_post_meta($wpPostId, '_wash_job_id');

            washLog("[wash] Callback: Post {$wpPostId} sync failed: {$error}");

            // Fire action for extensibility
            do_action('wash_callback_error', $wpPostId, $error, $body);
        }

        if (is_multisite() && $wpSiteId > 1) {
            restore_current_blog();
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Callback processed',
        ], 200);
    }

    /**
     * Create automatic redirect from WordPress URL to Shopify URL.
     *
     * @param int    $postId      WordPress post ID.
     * @param string $shopifyPath Shopify path (e.g., /blogs/news/my-article).
     */
    private function createAutomaticRedirect($postId, $shopifyPath) {
        $post = get_post($postId);
        if (!$post) {
            washLog("[wash] createAutomaticRedirect: Post {$postId} not found");
            return;
        }

        $wpPermalink = get_permalink($postId);
        if (!$wpPermalink) {
            washLog("[wash] createAutomaticRedirect: No permalink for post {$postId}");
            return;
        }

        $wpPath = wp_parse_url($wpPermalink, PHP_URL_PATH);
        if (!$wpPath || $wpPath === '/') {
            washLog("[wash] createAutomaticRedirect: Invalid WP path for post {$postId}");
            return;
        }

        $normalizedWpPath = rtrim($wpPath, '/');
        $normalizedShopifyPath = rtrim($shopifyPath, '/');
        if ($normalizedWpPath === $normalizedShopifyPath) {
            washLog("[wash] createAutomaticRedirect: Paths are identical, skipping redirect for post {$postId}");
            return;
        }

        // Already created? Same WP path stored in meta means we've handled this post.
        $existingRedirect = get_post_meta($postId, '_wash_redirect_created', true);
        if ($existingRedirect === $normalizedWpPath) {
            washLog("[wash] createAutomaticRedirect: Redirect already created for post {$postId}");
            return;
        }

        // Acquire transient lock to prevent concurrent callbacks for the same post
        // creating duplicate redirects (race between meta read and API call).
        $lockKey = 'wash_redirect_lock_' . $postId;
        if (get_transient($lockKey)) {
            washLog("[wash] createAutomaticRedirect: Another callback already creating redirect for post {$postId}, skipping");
            return;
        }
        set_transient($lockKey, 1, 60); // 60s safety expiration

        try {
            // Re-check meta after acquiring lock (another callback may have just finished)
            $existingRedirect = get_post_meta($postId, '_wash_redirect_created', true);
            if ($existingRedirect === $normalizedWpPath) {
                washLog("[wash] createAutomaticRedirect: Redirect created by concurrent callback, skipping");
                return;
            }

            $apiClient = new WashApiClient();
            $result = $apiClient->post('/api/redirections', [
                'redirects' => [
                    [
                        'from' => $normalizedWpPath,
                        'to'   => $normalizedShopifyPath,
                    ],
                ],
            ]);

            if (is_wp_error($result)) {
                washLog("[wash] createAutomaticRedirect: Failed to create redirect for post {$postId}: " . $result->get_error_message());
                return;
            }

            update_post_meta($postId, '_wash_redirect_created', $normalizedWpPath);
            update_post_meta($postId, '_wash_redirect_to', $normalizedShopifyPath);

            $created = $result['created'] ?? 0;
            $skipped = $result['skipped'] ?? 0;
            washLog("[wash] createAutomaticRedirect: Post {$postId} redirect {$normalizedWpPath} -> {$normalizedShopifyPath} (created: {$created}, skipped: {$skipped})");
            return;
        } finally {
            delete_transient($lockKey);
        }
    }

    /**
     * Handle settings push from Shopify App.
     * Called when settings are saved in Shopify App to sync them to WordPress.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handleSettingsPush($request) {
        $body = $request->get_json_params();

        if (empty($body) || !is_array($body)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Invalid or empty settings data',
            ], 400);
        }

        washLog('[wash] Settings push received from Shopify App');

        try {
            $apiClient = new WashApiClient();
            $apiClient->restoreSettings($body);

            washLog('[wash] Settings push: Successfully applied settings from Shopify');

            // Fire action for extensibility
            do_action('wash_settings_pushed', $body);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Settings applied successfully',
            ], 200);
        } catch (Exception $e) {
            washLog('[wash] Settings push failed: ' . $e->getMessage());

            return new WP_REST_Response([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
