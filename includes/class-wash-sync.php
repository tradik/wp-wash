<?php
/**
 * Content synchronization handler.
 * Hooks into WordPress save/delete actions to sync posts to Shopify.
 */
class WashSync {

    /** @var WashApiClient */
    private $apiClient;

    /** @var bool Prevents recursive sync calls */
    private $isSyncing = false;

    public function __construct() {
        $this->apiClient = new WashApiClient();
    }

    /**
     * Register WordPress hooks for content sync.
     */
    public function init() {
        add_action('save_post', [$this, 'onSavePost'], 20, 3);
        add_action('before_delete_post', [$this, 'onDeletePost'], 10, 1);
        add_action('transition_post_status', [$this, 'onStatusTransition'], 10, 3);
    }

    /**
     * Handle post save/update — triggers sync to Shopify.
     *
     * @param int      $postId  Post ID
     * @param \WP_Post $post    Post object
     * @param bool     $update  Whether this is an update
     */
    public function onSavePost($postId, $post, $update) {
        washLog("[wash] onSavePost called for post {$postId}, type: {$post->post_type}, status: {$post->post_status}");

        if ($this->shouldSkipSync($postId, $post)) {
            washLog("[wash] Skipping sync - shouldSkipSync returned true");
            return;
        }

        if (get_option('wash_sync_on_publish', '1') !== '1') {
            washLog("[wash] Skipping sync - wash_sync_on_publish is not '1', value: " . get_option('wash_sync_on_publish', '1'));
            return;
        }

        if ($post->post_status !== 'publish') {
            washLog("[wash] Skipping sync - post_status is not 'publish', status: {$post->post_status}");
            return;
        }

        $this->doSync($postId, $post);
    }

    /**
     * Manual sync - bypasses auto-sync option check.
     * Called when user clicks "Sync to Shopify" button.
     *
     * @param int      $postId Post ID
     * @param \WP_Post $post   Post object
     */
    public function syncManually($postId, $post) {
        washLog("[wash] syncManually called for post {$postId}, type: {$post->post_type}, status: {$post->post_status}");

        if ($this->shouldSkipSync($postId, $post)) {
            washLog("[wash] Skipping manual sync - shouldSkipSync returned true");
            return;
        }

        if ($post->post_status !== 'publish') {
            washLog("[wash] Skipping manual sync - post_status is not 'publish', status: {$post->post_status}");
            return;
        }

        $this->doSync($postId, $post);
    }

    /**
     * Execute the actual sync operation.
     *
     * @param int      $postId Post ID
     * @param \WP_Post $post   Post object
     */
    private function doSync($postId, $post) {
        washLog("[wash] Starting sync for post {$postId}");
        $this->isSyncing = true;
        // Determine action based on existing Shopify sync, not WordPress $update flag
        // WordPress passes $update=true even for new posts (auto-draft → publish)
        $existingArticleId = get_post_meta($postId, '_wash_article_id', true);
        $action = empty($existingArticleId) ? 'create' : 'update';
        washLog("[wash] Action determined: {$action} (existingArticleId: " . ($existingArticleId ?: 'none') . ")");
        $this->syncPost($postId, $post, $action);
        $this->isSyncing = false;
        washLog("[wash] Sync completed for post {$postId}");
    }

    /**
     * Handle post deletion — removes article from Shopify.
     *
     * @param int $postId Post ID
     */
    public function onDeletePost($postId) {
        $post = get_post($postId);
        if (!$post || !$this->isAllowedPostType($post->post_type)) {
            return;
        }

        $endpoint = $this->resolveEndpoint($post->post_type);
        $payload = [
            'action'   => 'delete',
            'wpPostId' => $postId,
            'wpSiteId' => $this->getCurrentSiteId(),
            'language' => $this->getPostLanguage($postId),
        ];

        // Translation-aware delete: include group info so queue worker knows
        // whether to delete whole article (source) or just remove locale variant
        $hreflangs = new WashHreflangs();
        $group = $hreflangs->getTranslationGroupSource($postId);
        if ($group) {
            $payload['translationGroup'] = $group;
        }

        $result = $this->apiClient->post($endpoint, $payload);

        $this->logSync($postId, 'delete', $result);
    }

    /**
     * Handle post status transitions (publish → draft, etc.).
     * Deletes article from Shopify and clears local meta when unpublishing.
     */
    public function onStatusTransition($newStatus, $oldStatus, $post) {
        if ($this->isSyncing || !$this->isAllowedPostType($post->post_type)) {
            return;
        }

        if ($oldStatus === 'publish' && $newStatus !== 'publish') {
            washLog("[wash] onStatusTransition: {$oldStatus} → {$newStatus} for post {$post->ID}");
            $endpoint = $this->resolveEndpoint($post->post_type);

            $payload = [
                'action'   => 'delete',
                'wpPostId' => $post->ID,
                'wpSiteId' => $this->getCurrentSiteId(),
                'language' => $this->getPostLanguage($post->ID),
            ];

            // Translation-aware delete: include group info
            $hreflangs = new WashHreflangs();
            $group = $hreflangs->getTranslationGroupSource($post->ID);
            if ($group) {
                $payload['translationGroup'] = $group;
            }

            $result = $this->apiClient->post($endpoint, $payload);

            // Clear local sync meta so republishing creates a fresh article
            $this->clearSyncMeta($post->ID);
            washLog("[wash] onStatusTransition: Cleared sync meta for post {$post->ID}");
        }
    }

    /**
     * Clear Shopify sync metadata from a post.
     * Called after deleting an article from Shopify.
     *
     * @param int $postId Post ID
     */
    private function clearSyncMeta($postId) {
        delete_post_meta($postId, '_wash_article_id');
        delete_post_meta($postId, '_wash_blog_id');
        delete_post_meta($postId, '_wash_blog_handle');
        delete_post_meta($postId, '_wash_synced_at');
    }

    /**
     * Sync a WordPress post to Shopify.
     */
    private function syncPost($postId, $post, $action) {
        washLog("[wash] syncPost() - Starting sync for post {$postId}");

        // Sync images in content first (if Files & Media enabled)
        if (get_option('wash_files_enabled', '1') === '1') {
            $this->syncContentImages($post->post_content);
        }

        // Sync featured image if set
        $thumbnailId = get_post_thumbnail_id($postId);
        if ($thumbnailId && get_option('wash_files_enabled', '1') === '1') {
            $this->syncFeaturedImage($thumbnailId);
        }

        washLog("[wash] syncPost() - Building post data for post {$postId}");
        $postData = $this->buildPostData($postId, $post, $action);
        $endpoint = $this->resolveEndpoint($post->post_type);
        washLog("[wash] syncPost() - Endpoint: {$endpoint}, Action: {$action}");
        washLog("[wash] syncPost() - Post title: {$postData['title']}, slug: {$postData['slug']}");
        washLog("[wash] syncPost() - Tags being sent: " . json_encode($postData['tags']));

        $result = $this->apiClient->post($endpoint, $postData);

        if (is_wp_error($result)) {
            washLog("[wash] syncPost() - API returned error: " . $result->get_error_message());
        } else {
            washLog("[wash] syncPost() - API returned success, shopifyArticleId: " . ($result['shopifyArticleId'] ?? 'null'));
        }

        $this->logSync($postId, $action, $result);

        if (is_wp_error($result)) {
            // Store error message for display
            $errorMessage = $result->get_error_message();
            update_post_meta($postId, '_wash_sync_error', $errorMessage);
            washLog("[wash] syncPost() - Stored error: " . $errorMessage);
            return;
        }

        // Handle async queue response - job queued but not completed yet
        if (!empty($result['queued']) && !empty($result['jobId'])) {
            washLog("[wash] syncPost() - Job queued with ID: " . $result['jobId']);
            update_post_meta($postId, '_wash_sync_status', 'queued');
            update_post_meta($postId, '_wash_job_id', $result['jobId']);
            update_post_meta($postId, '_wash_queued_at', current_time('mysql'));
            delete_post_meta($postId, '_wash_sync_error');
            return;
        }

        // Handle successful sync with Shopify ID
        $shopifyId = $result['shopifyArticleId'] ?? $result['shopifyPageId'] ?? null;
        if ($shopifyId) {
            washLog("[wash] syncPost() - Updating post meta with Shopify ID: {$shopifyId}");
            update_post_meta($postId, '_wash_article_id', $shopifyId);
            update_post_meta($postId, '_wash_blog_id', $result['shopifyBlogId'] ?? '');
            update_post_meta($postId, '_wash_blog_handle', $result['shopifyBlogHandle'] ?? '');
            update_post_meta($postId, '_wash_synced_at', current_time('mysql'));
            // Clear queued status and error
            delete_post_meta($postId, '_wash_sync_status');
            delete_post_meta($postId, '_wash_job_id');
            delete_post_meta($postId, '_wash_queued_at');
            delete_post_meta($postId, '_wash_sync_error');

            // Sync metafields (ACF, custom fields, SEO)
            $this->syncMetafields($postId, $shopifyId);

            // Sync hreflangs (WPML, Polylang, TranslatePress, Weglot)
            $this->syncHreflangs($postId, $shopifyId);

            // Automatic redirects are created in callback handler (class-wash-callback.php)
            // after Shopify confirms the article/page was synced — avoids duplicates.

            // Fire action for extensibility
            do_action('wash_post_synced', $postId, $shopifyId);
        }
    }

    /**
     * Sync all metafields for a post to Shopify.
     *
     * @param int    $postId    WordPress post ID
     * @param string $shopifyId Shopify resource GID
     */
    private function syncMetafields($postId, $shopifyId) {
        if (get_option('wash_sync_metafields') !== '1') {
            return;
        }

        $metafieldsHandler = new WashMetafields();
        $result = $metafieldsHandler->syncAllMetafields($postId, $shopifyId);

        if (is_wp_error($result)) {
            washLog("[wash] syncMetafields() - Error: " . $result->get_error_message());
        } else {
            $count = $result['count'] ?? 0;
            washLog("[wash] syncMetafields() - Synced {$count} metafields for post {$postId}");
        }
    }

    /**
     * Sync hreflang metafields for a post to Shopify.
     * Detects translations from WPML, Polylang, TranslatePress, Weglot.
     *
     * @param int    $postId    WordPress post ID
     * @param string $shopifyId Shopify resource GID
     */
    private function syncHreflangs($postId, $shopifyId) {
        if (get_option('wash_hreflangs_enabled') !== '1') {
            return;
        }

        $hreflangsHandler = new WashHreflangs();
        $result = $hreflangsHandler->syncHreflangs($postId, $shopifyId);

        if (is_wp_error($result)) {
            washLog("[wash] syncHreflangs() - Error: " . $result->get_error_message());
        } else {
            $count = $result['count'] ?? 0;
            if (isset($result['cleared']) && $result['cleared']) {
                washLog("[wash] syncHreflangs() - Cleared hreflangs (no translations) for post {$postId}");
            } else {
                washLog("[wash] syncHreflangs() - Synced {$count} hreflang entries for post {$postId}");
            }
        }
    }

    /**
     * Build the post data payload for the Shopify App API.
     */
    private function buildPostData($postId, $post, $action) {
        $blocksMeta = [];
        $blocksCss = '';

        // Detect page builder (Bricks, Elementor, Divi, etc.)
        $pageBuilder = WashPageBuilders::detect($postId);
        washLog("[wash] buildPostData() - Page builder detect result: " . ($pageBuilder ?: 'none') . " for post {$postId}");

        // Debug: check what meta keys exist for this post
        $bricksMeta = get_post_meta($postId, '_bricks_page_content_2', true);
        washLog("[wash] buildPostData() - _bricks_page_content_2 type: " . gettype($bricksMeta) . " empty: " . (empty($bricksMeta) ? 'yes' : 'no') . " is_array: " . (is_array($bricksMeta) ? 'yes' : 'no'));
        if (is_array($bricksMeta)) {
            washLog("[wash] buildPostData() - _bricks_page_content_2 count: " . count($bricksMeta));
        }
        washLog("[wash] buildPostData() - post_content length: " . strlen($post->post_content));

        $pageBuilderCss = '';
        if ($pageBuilder) {
            washLog("[wash] buildPostData() - Page builder detected: {$pageBuilder} for post {$postId}");
            $rendered = WashPageBuilders::render($postId, $pageBuilder);
            washLog("[wash] buildPostData() - Rendered HTML length: " . strlen($rendered['html']) . " CSS length: " . strlen($rendered['css']));

            // If CSS is empty, fetch from frontend page directly
            if (empty($rendered['css']) && !empty($rendered['html'])) {
                washLog("[wash] buildPostData() - CSS empty, fetching from frontend");
                $rendered['css'] = $this->fetchPageBuilderCss($postId);
                washLog("[wash] buildPostData() - Frontend CSS length: " . strlen($rendered['css']));
            }

            $content = $rendered['html'];
            $pageBuilderCss = $rendered['css'];
            washLog("[wash] buildPostData() - Content: " . strlen($content) . " CSS metafield: " . strlen($pageBuilderCss));
        } else {
            // Standard flow: Gutenberg blocks + the_content filter
            $blockData = apply_filters('wash_before_sync_content', $post->post_content, $postId);

            if (is_array($blockData)) {
                $rawContent = $blockData['content'];
                $blocksMeta = $blockData['blocksMeta'] ?? [];
                $blocksCss = $blockData['blocksCss'] ?? '';
            } else {
                $rawContent = $blockData;
            }

            // Apply block/shortcode search & replace rules BEFORE the_content
            $rawContent = $this->applySearchReplaceRules($rawContent, ['block', 'shortcode']);

            // Apply the_content filter to processed content
            $content = apply_filters('the_content', $rawContent);
        }

        // Preserve custom HTML tags that Shopify would strip (klaviyo-form, chat-widget, etc.)
        $content = $this->preserveCustomHtmlTags($content);

        // Strip leading <h1> that matches post title — some themes/plugins inject
        // the title into the_content output, causing it to appear twice on Shopify
        // (once from article.title, once from article.content/body).
        $content = $this->stripDuplicateTitle($content, $post->post_title);

        // Rewrite WordPress image URLs to Shopify CDN URLs
        if (get_option('wash_files_enabled', '1') === '1') {
            $content = $this->rewriteImageUrls($content);
        }

        // Rewrite internal WordPress links to Shopify URL format
        if (get_option('wash_rewrite_internal_urls', '1') === '1') {
            $content = $this->rewriteInternalUrls($content);
        }

        // Apply string/url search & replace rules AFTER the_content (on rendered HTML)
        $content = $this->applySearchReplaceRules($content, ['string', 'url']);

        // Build blocks metafields if enabled
        $blocksMetafields = [];
        if (!empty($blocksMeta) && get_option('wash_sync_blocks_meta', '1') === '1') {
            $blocksMetafields = WashBlocks::buildBlockMetafields($blocksMeta);
        }

        // Debug: log if content contains WordPress or Shopify CDN URLs
        if (strpos($content, 'cdn.shopify.com') !== false) {
            washLog("[wash] buildPostData() - Content CONTAINS cdn.shopify.com URLs (good!)");
        }
        if (strpos($content, 'wp-content/uploads') !== false) {
            washLog("[wash] buildPostData() - WARNING: Content still contains wp-content/uploads URLs!");
        }

        $data = [
            'action'      => $action,
            'wpPostId'    => $postId,
            'wpSiteId'    => $this->getCurrentSiteId(),
            'postType'    => $post->post_type,
            'title'       => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'content'     => $content,
            'excerpt'     => html_entity_decode($post->post_excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'slug'        => $post->post_name,
            'status'      => $post->post_status === 'publish' ? 'published' : 'draft',
            'author'      => $this->getMappedAuthorName($post->post_author),
            'tags'        => $this->getPostTagsWithCategories($postId),
            'publishedAt' => get_the_date('c', $postId),
        ];

        // Add language for rule-based blog mapping (WPML, Polylang, etc.)
        $data['language'] = $this->getPostLanguage($postId);

        // Add categories for rule-based blog mapping
        $data['categories'] = $this->getPostCategories($postId);

        // Add translation group info so Shopify sync merges siblings into one article
        $hreflangs = new WashHreflangs();
        $group = $hreflangs->getTranslationGroupSource($postId);
        if ($group) {
            $data['translationGroup'] = $group;
        }

        $thumbnailId = get_post_thumbnail_id($postId);
        if ($thumbnailId) {
            // Try to use Shopify CDN URL if image was synced, otherwise use WordPress URL
            $cdnUrl = WashImages::getShopifyCdnUrl($thumbnailId);
            $wpUrl = wp_get_attachment_url($thumbnailId);

            // Validate CDN URL - must be a real Shopify CDN URL, not a temp staged URL
            $isValidCdnUrl = $cdnUrl && strpos($cdnUrl, 'cdn.shopify.com') !== false;

            if ($isValidCdnUrl) {
                $data['featuredImage'] = $cdnUrl;
                washLog("[wash] buildPostData() - Featured image using CDN URL: {$cdnUrl}");
            } else {
                $data['featuredImage'] = $wpUrl;
                washLog("[wash] buildPostData() - Featured image using WordPress URL: {$wpUrl}");
                if ($cdnUrl) {
                    washLog("[wash] buildPostData() - WARNING: Invalid CDN URL stored: {$cdnUrl}");
                }
            }
        }

        $data['seo'] = $this->getSeoData($postId);

        if (function_exists('get_fields')) {
            $acfFields = get_fields($postId);
            if ($acfFields) {
                $data['acfFields'] = $acfFields;
            }
        } elseif (get_option('wash_meta_acf', '0') === '1') {
            // Fallback: read raw ACF post_meta when ACF plugin is deactivated
            $acfRaw = WashMetafields::getRawAcfFields($postId);
            if (!empty($acfRaw)) {
                $data['acfFields'] = $acfRaw;
            }
        }

        $data['customFields'] = $this->getCustomFields($postId);
        $data['wpPermalink'] = get_permalink($postId);
        $data['wpBlogName'] = get_bloginfo('name');

        // Add blocks metadata if available
        if (!empty($blocksMetafields)) {
            $data['blocksMetafields'] = $blocksMetafields;
            washLog("[wash] buildPostData() - Added " . count($blocksMetafields) . " block metafields");
        }

        // Add blocks CSS if enabled and available
        if (!empty($blocksCss) && get_option('wash_sync_blocks_css', '0') === '1') {
            $data['blocksCss'] = $blocksCss;
            washLog("[wash] buildPostData() - Added block CSS (" . strlen($blocksCss) . " bytes)");
        }

        // Page builder CSS as separate field (stored as Shopify metafield)
        if (!empty($pageBuilderCss)) {
            $data['inlineCss'] = $pageBuilderCss;
            $data['pageBuilder'] = $pageBuilder;
        }

        // Debug: include page builder detection info in payload (temporary)
        if ($pageBuilder) {
            $data['_debug_pageBuilder'] = $pageBuilder;
            $data['_debug_contentLength'] = strlen($content);
        }
        $data['_debug_postContentLength'] = strlen($post->post_content);
        $data['_debug_bricksMetaExists'] = !empty($bricksMeta);
        $data['_debug_pluginVersion'] = WASH_VERSION;
        $data['_debug_cssLength'] = strlen($pageBuilderCss ?? '');

        return $data;
    }

    /**
     * Rewrite WordPress file URLs in content to Shopify CDN URLs.
     * Replaces local wp-content/uploads URLs with synced Shopify CDN URLs.
     * Handles images (including resized), PDFs, documents, and links.
     * Removes srcset attributes (Shopify CDN handles responsive images).
     *
     * @param string $content Post content HTML.
     * @return string Content with rewritten file URLs.
     */
    private function rewriteFileUrls($content) {
        $siteUrl = get_site_url();
        washLog("[wash] rewriteFileUrls() - Site URL: {$siteUrl}");

        // Step 1: Remove srcset and sizes attributes (Shopify handles responsive images)
        $content = preg_replace('/\s+srcset=["\'][^"\']*["\']/', '', $content);
        $content = preg_replace('/\s+sizes=["\'][^"\']*["\']/', '', $content);

        // Step 2: Find all WordPress file URLs (images, PDFs, documents in src, href, etc.)
        // Debug: find all URLs first
        $pattern = '/(' . preg_quote($siteUrl, '/') . '\/wp-content\/uploads\/[^"\'<>\s\)]+)/i';
        preg_match_all($pattern, $content, $allMatches);
        if (!empty($allMatches[1])) {
            washLog("[wash] rewriteFileUrls() - Found " . count($allMatches[1]) . " URLs to process:");
            foreach (array_unique($allMatches[1]) as $url) {
                washLog("[wash] rewriteFileUrls() - URL: {$url}");
            }
        }

        // Step 3: Replace all WordPress file URLs with CDN URLs
        $rewrittenContent = preg_replace_callback(
            $pattern,
            function ($matches) {
                $wpUrl = $matches[1];
                $attachmentId = $this->getAttachmentIdFromUrl($wpUrl);

                if ($attachmentId) {
                    $cdnUrl = WashImages::getShopifyCdnUrl($attachmentId);
                    if ($cdnUrl && strpos($cdnUrl, 'cdn.shopify.com') !== false) {
                        washLog("[wash] rewriteFileUrls() - REWRITING: {$wpUrl} -> {$cdnUrl}");
                        return $cdnUrl;
                    } else {
                        washLog("[wash] rewriteFileUrls() - Attachment {$attachmentId} has no valid CDN URL (got: " . ($cdnUrl ?: 'null') . ")");
                    }
                } else {
                    washLog("[wash] rewriteFileUrls() - Could not find attachment for: {$wpUrl}");
                }
                return $wpUrl;
            },
            $content
        );

        // Check if any replacements were made
        if ($rewrittenContent !== $content) {
            washLog("[wash] rewriteFileUrls() - Content was modified");
        } else {
            washLog("[wash] rewriteFileUrls() - Content was NOT modified");
        }

        return $rewrittenContent;
    }

    /**
     * Remove a leading <h1> tag whose text matches the post title.
     * Themes/plugins sometimes inject the title into the_content output;
     * Shopify already renders article.title separately, so the duplicate
     * must be stripped to avoid showing the title twice.
     *
     * @param string $content  Rendered HTML content
     * @param string $title    Post title to match against
     * @return string Content with the duplicate title removed (if found)
     */
    /**
     * Preserve custom HTML tags by wrapping them in Shopify-safe divs.
     * Shopify strips unknown HTML elements (e.g. <klaviyo-form>, <chat-widget>).
     * This converts them to <div data-wash-tag="tagname" ...> so they survive
     * Shopify's HTML sanitizer and can be restored by the Shopify theme.
     */
    private function preserveCustomHtmlTags($content) {
        $patterns = get_option('wash_allowed_html_tags', '');
        if (empty($patterns)) {
            return $content;
        }

        $patternList = array_filter(array_map('trim', explode(',', $patterns)));
        if (empty($patternList)) {
            return $content;
        }

        foreach ($patternList as $pattern) {
            // Convert wildcard pattern to regex (e.g., "klaviyo-*" → "klaviyo-[a-z0-9-]+")
            $escaped = preg_quote($pattern, '/');
            $regex = str_replace('\\*', '[a-z0-9-]+', $escaped);

            // Replace opening tags: <tagname ...> → <div data-wash-tag="tagname" ...>
            $content = preg_replace(
                '/<(' . $regex . ')(\s[^>]*)?\/?>/i',
                '<div data-wash-tag="$1"$2>',
                $content
            );

            // Replace closing tags: </tagname> → </div>
            $content = preg_replace(
                '/<\/(' . $regex . ')>/i',
                '</div>',
                $content
            );
        }

        return $content;
    }

    private function stripDuplicateTitle($content, $title) {
        $trimmed = ltrim($content);
        if (stripos($trimmed, '<h1') !== 0) {
            return $content;
        }

        $pattern = '/^\s*<h1[^>]*>\s*' . preg_quote(trim($title), '/') . '\s*<\/h1>\s*/i';
        $stripped = preg_replace($pattern, '', $content, 1);

        if ($stripped !== $content) {
            washLog("[wash] stripDuplicateTitle() - Removed duplicate title from content");
        }

        return $stripped;
    }

    /**
     * Backwards compatibility alias.
     */
    private function rewriteImageUrls($content) {
        return $this->rewriteFileUrls($content);
    }

    /**
     * Rewrite internal WordPress URLs in content to Shopify URL format.
     * Converts links to posts/pages to their Shopify equivalents.
     *
     * @param string $content Post content HTML.
     * @return string Content with rewritten internal URLs.
     */
    private function rewriteInternalUrls($content) {
        $siteUrl = get_site_url();
        $parsedSite = parse_url($siteUrl);
        $siteHost = $parsedSite['host'] ?? '';

        if (empty($siteHost)) {
            return $content;
        }

        washLog("[wash] rewriteInternalUrls() - Site URL: {$siteUrl}, Host: {$siteHost}");

        // Find all internal links in href attributes
        $pattern = '/href=["\'](' . preg_quote($siteUrl, '/') . '\/[^"\'<>\s]*)["\']|href=["\'](\/[^"\'<>\s]*)["\']|href=["\']https?:\/\/' . preg_quote($siteHost, '/') . '(\/[^"\'<>\s]*)["\']|href=["\']http?:\/\/' . preg_quote($siteHost, '/') . '(\/[^"\'<>\s]*)["\']/i';

        $rewrittenContent = preg_replace_callback(
            $pattern,
            function ($matches) use ($siteUrl) {
                // Determine the matched URL path
                $fullMatch = $matches[0];
                $matchedUrl = '';
                $relativePath = '';

                if (!empty($matches[1])) {
                    $matchedUrl = $matches[1];
                    $relativePath = str_replace($siteUrl, '', $matchedUrl);
                } elseif (!empty($matches[2])) {
                    $matchedUrl = $matches[2];
                    $relativePath = $matches[2];
                } elseif (!empty($matches[3])) {
                    $relativePath = $matches[3];
                    $matchedUrl = $siteUrl . $relativePath;
                } elseif (!empty($matches[4])) {
                    $relativePath = $matches[4];
                    $matchedUrl = $siteUrl . $relativePath;
                }

                // Skip anchors, query strings only, or empty paths
                if (empty($relativePath) || $relativePath === '/' || strpos($relativePath, '#') === 0) {
                    return $fullMatch;
                }

                // Skip media/file URLs
                if (strpos($relativePath, '/wp-content/') !== false) {
                    return $fullMatch;
                }

                // Skip admin, login, and system URLs
                if (preg_match('/^\/(wp-admin|wp-login|wp-json|feed|xmlrpc)/i', $relativePath)) {
                    return $fullMatch;
                }

                // Try to find a matching post/page by URL
                $postId = url_to_postid($matchedUrl);
                if (!$postId) {
                    // Try with site URL prefix for relative paths
                    $postId = url_to_postid($siteUrl . $relativePath);
                }

                if (!$postId) {
                    washLog("[wash] rewriteInternalUrls() - No post found for URL: {$matchedUrl}");
                    return $fullMatch;
                }

                $post = get_post($postId);
                if (!$post) {
                    return $fullMatch;
                }

                // Check if this post type is allowed for syncing
                if (!$this->isAllowedPostType($post->post_type)) {
                    washLog("[wash] rewriteInternalUrls() - Post type '{$post->post_type}' not allowed for syncing");
                    return $fullMatch;
                }

                // Build Shopify URL based on post type
                if ($post->post_type === 'page') {
                    $shopifyPath = '/pages/' . $post->post_name;
                } else {
                    // For posts, use blog handle from meta or default
                    $blogHandle = get_post_meta($postId, '_wash_blog_handle', true);
                    if (empty($blogHandle)) {
                        $blogHandle = 'news'; // Default blog handle
                    }
                    $shopifyPath = '/blogs/' . $blogHandle . '/' . $post->post_name;
                }

                washLog("[wash] rewriteInternalUrls() - Rewriting: {$matchedUrl} -> {$shopifyPath}");
                return 'href="' . $shopifyPath . '"';
            },
            $content
        );

        return $rewrittenContent;
    }

    /**
     * Apply user-defined search & replace rules to content.
     * Rules are stored as wash_search_replace_rules option (array of {search, replace}).
     * Applied in order, using exact string matching (str_replace).
     *
     * @param string $content Post content HTML.
     * @return string Content with replacements applied.
     */
    private function applySearchReplaceRules($content, $onlyTypes = null) {
        $rules = get_option('wash_search_replace_rules', []);
        if (!is_array($rules) || empty($rules)) {
            return $content;
        }

        // Search & Replace requires BUSINESS+ plan
        if (!$this->apiClient->hasFeature('searchReplace')) {
            return $content;
        }

        $count = 0;
        foreach ($rules as $rule) {
            $search = $rule['search'] ?? '';
            $replace = $rule['replace'] ?? '';
            $type = $rule['type'] ?? 'string';

            if ($search === '') {
                continue;
            }

            // Filter by type if specified
            if ($onlyTypes !== null && !in_array($type, $onlyTypes, true)) {
                continue;
            }

            $before = $content;

            switch ($type) {
                case 'shortcode':
                    $content = $this->applyShortcodeRule($content, $search, $replace);
                    break;

                case 'url':
                    $content = $this->applyUrlRule($content, $search, $replace);
                    break;

                case 'block':
                    $content = $this->applyBlockRule($content, $search, $replace);
                    break;

                default: // string
                    $content = str_replace($search, $replace, $content);
                    break;
            }

            if ($content !== $before) {
                $count++;
                washLog("[wash] applySearchReplaceRules() - Applied {$type} rule: " . mb_substr($search, 0, 50) . " -> " . mb_substr($replace, 0, 50));
            }
        }

        if ($count > 0) {
            washLog("[wash] applySearchReplaceRules() - Applied {$count} rule(s)");
        }

        return $content;
    }

    /**
     * Apply shortcode rule — matches [shortcode ...] or [shortcode ...]...[/shortcode].
     * %content% variable captures inner content (HTML stripped).
     */
    private function applyShortcodeRule($content, $search, $replace) {
        // Extract shortcode name from pattern like [newsletter] or [form id="1"]
        if (!preg_match('/^\[(\w+)/', $search, $m)) {
            return str_replace($search, $replace, $content);
        }
        $tag = $m[1];

        // Auto-register shortcode if not exists (prevents WordPress from stripping it)
        if (!shortcode_exists($tag)) {
            add_shortcode($tag, function ($atts, $content) {
                return $content ?? '';
            });
        }

        // Match self-closing [tag ...] and paired [tag ...]...[/tag]
        $pattern = '/\[' . preg_quote($tag, '/') . '(?:\s[^\]]*?)?\](?:([\s\S]*?)\[\/' . preg_quote($tag, '/') . '\])?/';

        return preg_replace_callback($pattern, function ($matches) use ($replace) {
            $innerContent = $matches[1] ?? '';
            $strippedContent = wp_strip_all_tags(trim($innerContent));
            return str_replace('%content%', $strippedContent, $replace);
        }, $content);
    }

    /**
     * Apply URL rule — replaces URL patterns in all contexts:
     * href, src, srcset, data-* attributes, inline CSS url(), and standalone URLs in text.
     */
    private function applyUrlRule($content, $search, $replace) {
        $escaped = preg_quote($search, '/');

        // 1. Attributes: href, src, action, data-*, srcset, poster, content
        $content = preg_replace(
            '/((?:href|src|srcset|action|poster|content|data-[\w-]+)=["\'])' . $escaped . '/i',
            '$1' . $replace,
            $content
        );

        // 2. Inline CSS: url("...") or url('...')
        $content = preg_replace(
            '/(url\(["\']?)' . $escaped . '/i',
            '$1' . $replace,
            $content
        );

        // 3. Remaining occurrences in text content (anchor text, plain text, etc.)
        $content = str_replace($search, $replace, $content);

        return $content;
    }

    /**
     * Apply block rule — matches WordPress Gutenberg block comments.
     * Handles <!-- wp:blockname --> ... <!-- /wp:blockname -->
     */
    private function applyBlockRule($content, $search, $replace) {
        $rawName = preg_replace('/^wp:/', '', trim($search));

        // Handle embed/provider format (e.g., embed/youtube → matches wp:embed with providerNameSlug)
        if (preg_match('/^embed\/(.+)$/', $rawName, $embedMatch)) {
            $provider = $embedMatch[1];
            $pattern = '/<!--\s*wp:embed\s+\{[^}]*"providerNameSlug"\s*:\s*"' . preg_quote($provider, '/') . '"[^}]*\}\s*-->([\s\S]*?)<!--\s*\/wp:embed\s*-->/';
        } else {
            // Standard block: wp:blockname or namespace/blockname
            $blockName = $rawName;

            // Auto-register block type if not exists
            $fullBlockName = strpos($blockName, '/') !== false ? $blockName : 'core/' . $blockName;
            if (function_exists('register_block_type') && !\WP_Block_Type_Registry::get_instance()->is_registered($fullBlockName)) {
                register_block_type($fullBlockName, [
                    'render_callback' => function ($attributes, $content) {
                        return $content ?? '';
                    },
                ]);
            }
            $pattern = '/<!--\s*wp:' . preg_quote($blockName, '/') . '(?:\s[^>]*)?\s*-->([\s\S]*?)<!--\s*\/wp:' . preg_quote($blockName, '/') . '\s*-->/';
        }

        return preg_replace_callback($pattern, function ($matches) use ($replace) {
            $innerContent = $matches[1] ?? '';
            $strippedContent = wp_strip_all_tags(trim($innerContent));
            return str_replace('%content%', $strippedContent, $replace);
        }, $content);
    }

    /**
     * Get attachment ID from URL, including resized image URLs and documents.
     * WordPress stores only the original URL, so we strip size suffixes like -768x512.
     *
     * @param string $url File URL.
     * @return int|false Attachment ID or false if not found.
     */
    private function getAttachmentIdFromUrl($url) {
        // Clean URL - remove query strings
        $cleanUrl = strtok($url, '?');

        // First try direct lookup
        $attachmentId = attachment_url_to_postid($cleanUrl);
        if ($attachmentId) {
            washLog("[wash] getAttachmentIdFromUrl() - Found attachment {$attachmentId} for URL: {$cleanUrl}");
            return $attachmentId;
        }

        // Strip WordPress size suffix (e.g., -768x512, -1024x683, -scaled)
        // Only for image files (not PDFs, docs, etc.)
        $baseUrl = preg_replace('/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $cleanUrl);
        $baseUrl = preg_replace('/-scaled(?=\.[a-z]{3,4}$)/i', '', $baseUrl);

        if ($baseUrl !== $cleanUrl) {
            $attachmentId = attachment_url_to_postid($baseUrl);
            if ($attachmentId) {
                washLog("[wash] getAttachmentIdFromUrl() - Found attachment {$attachmentId} for resized URL: {$cleanUrl}");
                return $attachmentId;
            }
        }

        washLog("[wash] getAttachmentIdFromUrl() - No attachment found for: {$cleanUrl}");
        return false;
    }

    /**
     * Sync all files (images and documents) found in post content to Shopify.
     * Ensures files are uploaded before the post is synced so URLs can be rewritten.
     *
     * @param string $content Post content HTML.
     */
    private function syncContentFiles($content) {
        $siteUrl = get_site_url();
        $fileHandler = new WashImages();
        $syncedCount = 0;

        // Find all WordPress file URLs in content (images, PDFs, links, etc.)
        $pattern = '/(' . preg_quote($siteUrl, '/') . '\/wp-content\/uploads\/[^"\'<>\s\)]+)/i';
        preg_match_all($pattern, $content, $matches);

        if (empty($matches[1])) {
            washLog("[wash] syncContentFiles() - No wp-content/uploads URLs found in content");
            return;
        }

        $uniqueUrls = array_unique($matches[1]);
        washLog("[wash] syncContentFiles() - Found " . count($uniqueUrls) . " unique file URLs to sync");

        foreach ($uniqueUrls as $fileUrl) {
            washLog("[wash] syncContentFiles() - Processing: {$fileUrl}");
            $attachmentId = $this->getAttachmentIdFromUrl($fileUrl);

            if (!$attachmentId) {
                washLog("[wash] syncContentFiles() - Could not find attachment for URL: {$fileUrl}");
                continue;
            }

            // Skip if already synced with valid CDN URL
            $cdnUrl = WashImages::getShopifyCdnUrl($attachmentId);
            $isValidCdnUrl = $cdnUrl && strpos($cdnUrl, 'cdn.shopify.com') !== false;
            if ($isValidCdnUrl) {
                washLog("[wash] syncContentFiles() - File {$attachmentId} already synced to: {$cdnUrl}");
                continue;
            }

            // Clear invalid CDN URL if present
            if ($cdnUrl && !$isValidCdnUrl) {
                washLog("[wash] syncContentFiles() - Clearing invalid CDN URL for file {$attachmentId}: {$cdnUrl}");
                delete_post_meta($attachmentId, '_wash_cdn_url');
                delete_post_meta($attachmentId, '_wash_file_id');
            }

            // Sync the file
            washLog("[wash] syncContentFiles() - Syncing file {$attachmentId} from URL: {$fileUrl}");
            $result = $fileHandler->syncFile($attachmentId);

            if ($result) {
                $syncedCount++;
                washLog("[wash] syncContentFiles() - Successfully synced file {$attachmentId}");
            } else {
                washLog("[wash] syncContentFiles() - Failed to sync file {$attachmentId}");
            }
        }

        if ($syncedCount > 0) {
            washLog("[wash] syncContentFiles() - Synced {$syncedCount} new files to Shopify");
        }
    }

    /**
     * Backwards compatibility alias.
     */
    private function syncContentImages($content) {
        $this->syncContentFiles($content);
    }

    /**
     * Sync featured image to Shopify if not already synced.
     *
     * @param int $attachmentId Featured image attachment ID.
     */
    private function syncFeaturedImage($attachmentId) {
        if (!$attachmentId) {
            return;
        }

        // Skip if already synced with valid CDN URL
        $cdnUrl = WashImages::getShopifyCdnUrl($attachmentId);
        $isValidCdnUrl = $cdnUrl && strpos($cdnUrl, 'cdn.shopify.com') !== false;
        if ($isValidCdnUrl) {
            washLog("[wash] syncFeaturedImage() - Featured image {$attachmentId} already synced to: {$cdnUrl}");
            return;
        }

        // Clear invalid CDN URL if present
        if ($cdnUrl && !$isValidCdnUrl) {
            washLog("[wash] syncFeaturedImage() - Clearing invalid CDN URL for featured image {$attachmentId}: {$cdnUrl}");
            delete_post_meta($attachmentId, '_wash_cdn_url');
            delete_post_meta($attachmentId, '_wash_file_id');
        }

        // Sync the featured image
        washLog("[wash] syncFeaturedImage() - Syncing featured image {$attachmentId}");
        $imagesHandler = new WashImages();
        $result = $imagesHandler->syncImage($attachmentId);

        if ($result) {
            washLog("[wash] syncFeaturedImage() - Successfully synced featured image {$attachmentId}");
        } else {
            washLog("[wash] syncFeaturedImage() - Failed to sync featured image {$attachmentId}");
        }
    }

    /**
     * Get post tags as an array of names.
     */
    private function getPostTags($postId) {
        $tags = get_the_tags($postId);
        if (!$tags || is_wp_error($tags)) {
            washLog("[wash] getPostTags() - No tags found for post {$postId}");
            return [];
        }
        $tagNames = array_map(fn($tag) => $tag->name, $tags);
        washLog("[wash] getPostTags() - Found " . count($tagNames) . " tags: " . implode(', ', $tagNames));
        return $tagNames;
    }

    /**
     * Get post tags merged with category names (deduplicated).
     * WordPress categories are included as Shopify article tags.
     */
    private function getPostTagsWithCategories($postId) {
        $tags = $this->getPostTags($postId);

        $categories = get_the_category($postId);
        if ($categories && !is_wp_error($categories)) {
            $categoryNames = array_map(fn($cat) => $cat->name, $categories);
            // Filter out "Uncategorized" default category
            $categoryNames = array_filter($categoryNames, fn($name) => strtolower($name) !== 'uncategorized');
            $tags = array_values(array_unique(array_merge($tags, $categoryNames)));
        }

        washLog("[wash] getPostTagsWithCategories() - Final tags for post {$postId}: " . implode(', ', $tags));
        return $tags;
    }

    /**
     * Get author name, using custom mapping if enabled.
     *
     * @param int $authorId WordPress user ID.
     * @return string Author name for Shopify article.
     */
    private function getMappedAuthorName($authorId) {
        // Resolve WP author with fallbacks (display_name → user_login → nicename → site name)
        $defaultName = '';
        if ($authorId) {
            $defaultName = get_the_author_meta('display_name', $authorId);
            if (empty($defaultName)) {
                $defaultName = get_the_author_meta('user_login', $authorId);
            }
            if (empty($defaultName)) {
                $defaultName = get_the_author_meta('user_nicename', $authorId);
            }
        }
        if (empty($defaultName)) {
            $defaultName = get_bloginfo('name');
        }

        // Check if author mapping is enabled
        if (get_option('wash_authors_enabled', '0') !== '1') {
            return $defaultName;
        }

        // Get custom author mappings
        $authorMappings = get_option('wash_author_mappings', []);
        if (!is_array($authorMappings)) {
            return $defaultName;
        }

        // Return mapped name if exists, otherwise default
        return isset($authorMappings[$authorId]) && !empty($authorMappings[$authorId])
            ? $authorMappings[$authorId]
            : $defaultName;
    }

    /**
     * Get post categories as an array of slugs.
     * Used for rule-based blog mapping.
     */
    private function getPostCategories($postId) {
        $categories = get_the_category($postId);
        if (!$categories || is_wp_error($categories)) {
            return [];
        }
        return array_map(fn($cat) => [
            'slug' => $cat->slug,
            'name' => $cat->name,
        ], $categories);
    }

    /**
     * Get post language from translation plugins or custom meta.
     * Priority: manual override → WPML → Polylang → TranslatePress → site locale.
     *
     * @param int $postId Post ID
     * @return string|null Language code (e.g., 'en', 'de', 'pl')
     */
    private function getPostLanguage($postId) {
        // Manual override via custom field (highest priority)
        $manualLang = get_post_meta($postId, '_wash_language', true);
        if ($manualLang) {
            return strtolower(trim($manualLang));
        }

        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // WPML
        if (
            is_plugin_active('sitepress-multilingual-cms/sitepress.php') &&
            function_exists('wpml_get_language_information')
        ) {
            $langInfo = wpml_get_language_information(null, $postId);
            washLog("[wash] getPostLanguage($postId) WPML returned: " . wp_json_encode($langInfo));
            if (!empty($langInfo['locale'])) {
                // Use full locale (e.g., fr_CH → fr-ch) for region-specific matching
                return strtolower(str_replace('_', '-', $langInfo['locale']));
            }
            if (!empty($langInfo['language_code'])) {
                return strtolower($langInfo['language_code']);
            }
        }

        // Polylang — prefer full locale (e.g., en_US → en-us) for region-specific matching
        if (
            is_plugin_active('polylang/polylang.php') &&
            function_exists('pll_get_post_language')
        ) {
            $locale = pll_get_post_language($postId, 'locale');
            if ($locale) {
                return strtolower(str_replace('_', '-', $locale));
            }
            $slug = pll_get_post_language($postId, 'slug');
            if ($slug) {
                return strtolower($slug);
            }
        }

        // TranslatePress - uses post meta
        $trpLang = get_post_meta($postId, 'trp_language', true);
        if ($trpLang) {
            // TranslatePress stores full locale, extract language code
            return strtolower(substr($trpLang, 0, 2));
        }

        // No language detected - return null to use DEFAULT blog mapping rule
        return null;
    }

    /**
     * Get SEO metadata from Yoast, RankMath, or post meta.
     */
    private function getSeoData($postId) {
        $seo = ['metaTitle' => '', 'metaDescription' => '', 'focusKeyword' => ''];

        // Try Yoast rendered output first (resolves %%title%% variables)
        if (defined('WPSEO_VERSION') && class_exists('WPSEO_Meta')) {
            $yoastTitle = $this->getYoastRenderedTitle($postId);
            $yoastDesc = $this->getYoastRenderedDesc($postId);
            if ($yoastTitle) $seo['metaTitle'] = $yoastTitle;
            if ($yoastDesc) $seo['metaDescription'] = $yoastDesc;
            $focusKw = get_post_meta($postId, '_yoast_wpseo_focuskw', true);
            if ($focusKw) $seo['focusKeyword'] = $focusKw;
        }

        // Try RankMath rendered output (resolves %title% %sep% variables)
        if (class_exists('RankMath') && (empty($seo['metaTitle']) || empty($seo['metaDescription']))) {
            $rankTitle = $this->getRankMathRenderedTitle($postId);
            $rankDesc = $this->getRankMathRenderedDesc($postId);
            if ($rankTitle && empty($seo['metaTitle'])) $seo['metaTitle'] = $rankTitle;
            if ($rankDesc && empty($seo['metaDescription'])) $seo['metaDescription'] = $rankDesc;
            if (empty($seo['focusKeyword'])) {
                $rankKw = get_post_meta($postId, 'rank_math_focus_keyword', true);
                if ($rankKw) $seo['focusKeyword'] = $rankKw;
            }
        }

        // Fallback: use post title
        if (empty($seo['metaTitle'])) {
            $seo['metaTitle'] = html_entity_decode(get_the_title($postId), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Fallback: use excerpt only (no auto-generation from content)
        if (empty($seo['metaDescription'])) {
            $excerpt = get_the_excerpt($postId);
            if (!empty($excerpt)) {
                $seo['metaDescription'] = $excerpt;
            }
        }

        return $seo;
    }

    /**
     * Get Yoast SEO rendered title (resolves %%title%% %%sep%% etc.)
     */
    private function getYoastRenderedTitle($postId) {
        $template = get_post_meta($postId, '_yoast_wpseo_title', true);
        if (empty($template)) return '';

        // If no variables, return as-is
        if (strpos($template, '%%') === false) return $template;

        // Use Yoast API to resolve variables
        if (class_exists('WPSEO_Replace_Vars')) {
            $replacer = new \WPSEO_Replace_Vars();
            return $replacer->replace($template, get_post($postId));
        }

        // Manual fallback
        return $this->resolveSeoVariables($template, $postId);
    }

    /**
     * Get Yoast SEO rendered description
     */
    private function getYoastRenderedDesc($postId) {
        $template = get_post_meta($postId, '_yoast_wpseo_metadesc', true);
        if (empty($template)) return '';
        if (strpos($template, '%%') === false) return $template;

        if (class_exists('WPSEO_Replace_Vars')) {
            $replacer = new \WPSEO_Replace_Vars();
            return $replacer->replace($template, get_post($postId));
        }

        return $this->resolveSeoVariables($template, $postId);
    }

    /**
     * Get RankMath rendered title (resolves %title% %sep% etc.)
     */
    private function getRankMathRenderedTitle($postId) {
        // Try RankMath Paper API first
        if (method_exists('RankMath\Paper\Paper', 'get')) {
            try {
                $paper = \RankMath\Paper\Paper::get();
                if ($paper && method_exists($paper, 'get_title')) {
                    $title = $paper->get_title();
                    if ($title) return $title;
                }
            } catch (\Exception $e) { /* fall through */ }
        }

        $template = get_post_meta($postId, 'rank_math_title', true);
        if (empty($template)) return '';
        if (strpos($template, '%') === false) return $template;

        // Use RankMath helper if available
        if (function_exists('rank_math_replace_vars')) {
            return rank_math_replace_vars($template, get_post($postId));
        }

        return $this->resolveSeoVariables($template, $postId);
    }

    /**
     * Get RankMath rendered description
     */
    private function getRankMathRenderedDesc($postId) {
        $template = get_post_meta($postId, 'rank_math_description', true);
        if (empty($template)) return '';
        if (strpos($template, '%') === false) return $template;

        if (function_exists('rank_math_replace_vars')) {
            return rank_math_replace_vars($template, get_post($postId));
        }

        return $this->resolveSeoVariables($template, $postId);
    }

    /**
     * Manual fallback: resolve common SEO template variables.
     * Handles both Yoast (%%var%%) and RankMath (%var%) syntax.
     */
    private function resolveSeoVariables($template, $postId) {
        $post = get_post($postId);
        $sep = apply_filters('document_title_separator', '-');
        $siteName = get_bloginfo('name');

        // Yoast style (%%var%%) — must be replaced BEFORE RankMath (%var%)
        // to prevent %title% matching inside %%title%%
        $decodedTitle = html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $yoastVars = [
            '%%title%%'      => $decodedTitle,
            '%%sep%%'        => $sep,
            '%%sitename%%'   => $siteName,
            '%%sitedesc%%'   => get_bloginfo('description'),
            '%%excerpt%%'    => get_the_excerpt($postId),
            '%%date%%'       => get_the_date('', $postId),
            '%%category%%'   => $this->getPrimaryCategory($postId),
            '%%currentyear%%' => date('Y'),
            '%%primary_category%%' => $this->getPrimaryCategory($postId),
        ];

        // RankMath style (%var%)
        $rankVars = [
            '%title%'        => $decodedTitle,
            '%sep%'          => $sep,
            '%sitename%'     => $siteName,
            '%sitetitle%'    => $siteName,
            '%excerpt%'      => get_the_excerpt($postId),
            '%date%'         => get_the_date('', $postId),
            '%author%'       => get_the_author_meta('display_name', $post->post_author),
            '%category%'     => $this->getPrimaryCategory($postId),
            '%currentyear%'  => date('Y'),
            '%sitedesc%'     => get_bloginfo('description'),
        ];

        $result = str_replace(array_keys($yoastVars), array_values($yoastVars), $template);
        $result = str_replace(array_keys($rankVars), array_values($rankVars), $result);
        // Clean up any remaining unresolved variables
        $result = preg_replace('/%%[a-z_]+%%/', '', $result);
        $result = preg_replace('/%[a-z_]+%/', '', $result);
        return trim(preg_replace('/\s+/', ' ', $result));
    }

    /**
     * Get primary category name for a post.
     */
    private function getPrimaryCategory($postId) {
        // RankMath primary term
        $primaryTermId = get_post_meta($postId, 'rank_math_primary_category', true);
        if ($primaryTermId) {
            $term = get_term($primaryTermId);
            if ($term && !is_wp_error($term)) return $term->name;
        }

        // Yoast primary term
        $yoastPrimary = get_post_meta($postId, '_yoast_wpseo_primary_category', true);
        if ($yoastPrimary) {
            $term = get_term($yoastPrimary);
            if ($term && !is_wp_error($term)) return $term->name;
        }

        // First category
        $cats = get_the_category($postId);
        return !empty($cats) ? $cats[0]->name : '';
    }

    /**
     * Get custom fields, excluding internal WP meta keys.
     */
    private function getCustomFields($postId) {
        $allMeta = get_post_meta($postId);
        $customFields = [];
        if (!is_array($allMeta)) {
            return $customFields;
        }

        // Only sync fields enabled in Meta Fields configuration
        $metafields = new WashMetafields();
        $enabledFields = $metafields->getEnabledFieldSet();

        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            // Check if field is enabled (wash_custom.{key} or wash_acf.{key})
            $sanitizedKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $key));
            if (!empty($enabledFields) && !isset($enabledFields["wash_custom.{$sanitizedKey}"]) && !isset($enabledFields["wash_acf.{$sanitizedKey}"])) {
                continue;
            }
            $customFields[$key] = count($values) === 1 ? $values[0] : $values;
        }

        return $customFields;
    }

    /**
     * Check if sync should be skipped for this post.
     */
    private function shouldSkipSync($postId, $post) {
        if ($this->isSyncing) {
            washLog("[wash] shouldSkipSync: isSyncing=true");
            return true;
        }
        if (wp_is_post_revision($postId)) {
            washLog("[wash] shouldSkipSync: is revision");
            return true;
        }
        if (wp_is_post_autosave($postId)) {
            washLog("[wash] shouldSkipSync: is autosave");
            return true;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            washLog("[wash] shouldSkipSync: DOING_AUTOSAVE constant");
            return true;
        }
        if (!$this->isAllowedPostType($post->post_type)) {
            washLog("[wash] shouldSkipSync: post_type '{$post->post_type}' not allowed");
            return true;
        }
        if (!$this->apiClient->isConfigured()) {
            washLog("[wash] shouldSkipSync: API client not configured (missing api_key or api_secret)");
            return true;
        }

        washLog("[wash] shouldSkipSync: all checks passed, will sync");
        return false;
    }

    /**
     * Check if the post type is allowed for syncing.
     */
    private function isAllowedPostType($postType) {
        $allowedTypes = array_map('trim', explode(',', get_option('wash_post_types', 'post,page')));
        return in_array($postType, $allowedTypes, true);
    }

    /**
     * Resolve the sync API endpoint based on post type.
     * WP pages → /api/page-sync (Shopify Pages)
     * WP posts → /api/content-sync (Shopify Blog Articles)
     *
     * @param string $postType WordPress post type
     * @return string API endpoint path
     */
    private function resolveEndpoint($postType) {
        return $postType === 'page' ? '/api/page-sync' : '/api/content-sync';
    }

    /**
     * Fetch CSS from a page's frontend HTML.
     * Used when page builder renders HTML but CSS is separate.
     *
     * @param int $postId Post ID
     * @return string CSS content
     */
    private function fetchPageBuilderCss($postId) {
        $permalink = get_permalink($postId);
        if (!$permalink) return '';

        $args = ['timeout' => 30, 'sslverify' => false];
        $httpUser = get_option('wash_http_user', '');
        $httpPass = get_option('wash_http_pass', '');
        if (!empty($httpUser)) {
            $args['headers'] = ['Authorization' => 'Basic ' . base64_encode($httpUser . ':' . $httpPass)];
        }

        $response = wp_remote_get($permalink, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            washLog("[wash] fetchPageBuilderCss: fetch failed for {$permalink}");
            return '';
        }

        $body = wp_remote_retrieve_body($response);
        $css = '';

        // Extract inline <style> tags with bricks in ID
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $body, $matches)) {
            foreach ($matches[1] as $styleContent) {
                $trimmed = trim($styleContent);
                if (strlen($trimmed) > 100) {
                    $css .= $trimmed . "\n";
                }
            }
        }

        // Fetch linked Bricks CSS files
        if (preg_match_all('/<link[^>]*href=[\'"]([^\'"]*(?:bricks|themes\/bricks)[^\'"]*\.css[^\'"]*)[\'"][^>]*>/i', $body, $linkMatches)) {
            foreach ($linkMatches[1] as $cssUrl) {
                $cssResponse = wp_remote_get($cssUrl, ['timeout' => 15, 'sslverify' => false]);
                if (!is_wp_error($cssResponse) && wp_remote_retrieve_response_code($cssResponse) === 200) {
                    $css .= wp_remote_retrieve_body($cssResponse) . "\n";
                }
            }
        }

        washLog("[wash] fetchPageBuilderCss: total CSS " . strlen($css) . " bytes");
        return $css;
    }

    /**
     * Get the current WP site ID (for multisite support).
     */
    private function getCurrentSiteId() {
        return is_multisite() ? get_current_blog_id() : 1;
    }

    /**
     * Log sync operation to the local database.
     * Self-heals if the {$wpdb->prefix}wash_sync table is missing
     * (e.g. dev DB without activator run, or table dropped manually).
     */
    private function logSync($postId, $action, $result) {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wash_sync';

        $isError = is_wp_error($result);
        $data = [
            'postId'     => $postId,
            'syncAction' => $action,
            'syncStatus' => $isError ? 'error' : 'success',
            'errorMessage' => $isError ? $result->get_error_message() : null,
            'shopifyArticleId' => $isError ? null : ($result['shopifyArticleId'] ?? null),
            'shopifyBlogId'    => $isError ? null : ($result['shopifyBlogId'] ?? null),
        ];

        $this->ensureSyncTable($tableName);

        // Suppress errors so a transient table issue doesn't break the post save flow.
        $previousShowErrors = $wpdb->show_errors(false);
        $previousSuppressErrors = $wpdb->suppress_errors(true);
        $wpdb->insert($tableName, $data);
        $wpdb->show_errors($previousShowErrors);
        $wpdb->suppress_errors($previousSuppressErrors);
    }

    /**
     * Ensure the wash_sync tracking table exists. Idempotent — uses dbDelta
     * via WashActivator::createSyncTable(). Cached per-request so we don't
     * hit information_schema on every save.
     */
    private function ensureSyncTable($tableName) {
        static $verified = [];
        if (isset($verified[$tableName])) {
            return;
        }
        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $tableName)
        );
        if (!$exists && class_exists('WashActivator')) {
            WashActivator::createSyncTable();
        }
        $verified[$tableName] = true;
    }

    /**
     * Create automatic redirect from WordPress URL to Shopify URL.
     *
     * @param int         $postId           WordPress post ID.
     * @param string      $shopifyHandle    Shopify handle (article or page).
     * @param string|null $shopifyBlogHandle Shopify blog handle (null for pages).
     */
}
