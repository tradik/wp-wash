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

        $syncedPosts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'post'
             AND p.post_status IN ('publish', 'draft', 'pending', 'private')
             AND pm.meta_key = '_wash_article_id'
             AND pm.meta_value != ''"
        );

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
            'pluginVersion' => defined('WASH_VERSION') ? WASH_VERSION : null,
        ]);
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
                __('Wash is not configured. Please set up your API key.', 'wash'),
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
                __('Wash is not configured. Please set up your API key.', 'wash'),
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
                __('Post not found', 'wash'),
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
                update_post_meta($postId, '_wash_sync_error', __('Sync timed out. The callback may not have reached WordPress.', 'wash'));
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

        error_log("[wash] Callback received for post {$wpPostId}, jobId: {$jobId}, success: " . ($success ? 'true' : 'false'));

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

                // Build Shopify URL
                $shopifyDomain = get_option('wash_shopify_domain', '');
                if ($shopifyDomain && $shopifyHandle) {
                    if ($shopifyBlogHandle) {
                        // Article URL: /blogs/{blogHandle}/{articleHandle}
                        $shopifyUrl = "https://{$shopifyDomain}/blogs/{$shopifyBlogHandle}/{$shopifyHandle}";
                        $shopifyPath = "/blogs/{$shopifyBlogHandle}/{$shopifyHandle}";
                    } else {
                        // Page URL: /pages/{handle}
                        $shopifyUrl = "https://{$shopifyDomain}/pages/{$shopifyHandle}";
                        $shopifyPath = "/pages/{$shopifyHandle}";
                    }
                    update_post_meta($wpPostId, '_wash_shopify_url', $shopifyUrl);

                    // Create automatic redirect if enabled
                    if (get_option('wash_redirections_enabled', '0') === '1') {
                        $this->createAutomaticRedirect($wpPostId, $shopifyPath);
                    }
                }
            }

            // Clear queued status
            delete_post_meta($wpPostId, '_wash_sync_status');
            delete_post_meta($wpPostId, '_wash_job_id');
            delete_post_meta($wpPostId, '_wash_queued_at');
            delete_post_meta($wpPostId, '_wash_sync_error');

            error_log("[wash] Callback: Post {$wpPostId} synced successfully, shopifyId: {$shopifyId}");

            // Fire action for extensibility
            do_action('wash_callback_success', $wpPostId, $body);

        } else {
            // Store error
            update_post_meta($wpPostId, '_wash_sync_error', $error ?: 'Unknown error');
            delete_post_meta($wpPostId, '_wash_sync_status');
            delete_post_meta($wpPostId, '_wash_job_id');

            error_log("[wash] Callback: Post {$wpPostId} sync failed: {$error}");

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
            error_log("[wash] createAutomaticRedirect: Post {$postId} not found");
            return;
        }

        // Get WordPress post permalink and extract path
        $wpPermalink = get_permalink($postId);
        if (!$wpPermalink) {
            error_log("[wash] createAutomaticRedirect: No permalink for post {$postId}");
            return;
        }

        $wpPath = wp_parse_url($wpPermalink, PHP_URL_PATH);
        if (!$wpPath || $wpPath === '/') {
            error_log("[wash] createAutomaticRedirect: Invalid WP path for post {$postId}");
            return;
        }

        // Don't create redirect if paths are the same
        $normalizedWpPath = rtrim($wpPath, '/');
        $normalizedShopifyPath = rtrim($shopifyPath, '/');
        if ($normalizedWpPath === $normalizedShopifyPath) {
            error_log("[wash] createAutomaticRedirect: Paths are identical, skipping redirect for post {$postId}");
            return;
        }

        // Check if redirect already exists for this post
        $existingRedirect = get_post_meta($postId, '_wash_redirect_created', true);
        if ($existingRedirect === $normalizedWpPath) {
            error_log("[wash] createAutomaticRedirect: Redirect already created for post {$postId}");
            return;
        }

        // Create redirect via API
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
            error_log("[wash] createAutomaticRedirect: Failed to create redirect for post {$postId}: " . $result->get_error_message());
            return;
        }

        // Store that redirect was created
        update_post_meta($postId, '_wash_redirect_created', $normalizedWpPath);
        update_post_meta($postId, '_wash_redirect_to', $normalizedShopifyPath);

        $created = $result['created'] ?? 0;
        $skipped = $result['skipped'] ?? 0;
        error_log("[wash] createAutomaticRedirect: Post {$postId} redirect {$normalizedWpPath} -> {$normalizedShopifyPath} (created: {$created}, skipped: {$skipped})");
    }
}
