<?php
/**
 * Reverse import orchestrator (Shopify → WordPress).
 *
 * The inverse of WashSync: instead of pushing WordPress posts to Shopify, this
 * pulls Shopify blog articles INTO WordPress. It is plugin-driven — the Shopify
 * App only reads (GET /api/shopify-articles, /api/shopify-article); every write
 * (posts, terms, media, translations) happens here in PHP.
 *
 * The batch loop mirrors WashAdmin::ajaxBulkSyncBatch() (#253): each AJAX request
 * is bounded by BOTH a post cap AND a wall-clock budget so it stays under the
 * ~100s reverse-proxy gateway window; the JS driver (import-progress.js) resumes
 * from the returned nextOffset. Per-connection state routes through WashContext.
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

class WashImport {

    /**
     * Register AJAX handlers for the reverse import.
     */
    public function init() {
        add_action('wp_ajax_wash_import_preview', [$this, 'ajaxImportPreview']);
        add_action('wp_ajax_wash_import_batch', [$this, 'ajaxImportBatch']);
        add_action('wp_ajax_wash_import_status', [$this, 'ajaxImportStatus']);
    }

    /**
     * API client for the active connection (seam for tests).
     *
     * @return WashApiClient
     */
    protected function apiClient() {
        return WashContext::apiClient();
    }

    /**
     * Writer that turns one normalized article into a WordPress post (seam for tests).
     *
     * @return WashImportWriter
     */
    protected function writer() {
        return new WashImportWriter();
    }

    /**
     * Follow blog pagination and collect every article GID for the blog.
     *
     * @param string $blogId Shopify blog GID.
     * @return array|\WP_Error List of article GIDs, or a WP_Error on API failure.
     */
    protected function fetchArticleIds($blogId) {
        $ids   = array();
        $after = null;
        do {
            $endpoint = '/api/shopify-articles?blogId=' . rawurlencode($blogId);
            if ($after) {
                $endpoint .= '&after=' . rawurlencode($after);
            }
            $resp = $this->apiClient()->get($endpoint);
            if (is_wp_error($resp)) {
                return $resp;
            }
            foreach (($resp['articles'] ?? array()) as $article) {
                if (!empty($article['id'])) {
                    $ids[] = $article['id'];
                }
            }
            $hasNext = !empty($resp['pageInfo']['hasNextPage']);
            $after   = $resp['pageInfo']['endCursor'] ?? null;
        } while ($hasNext && $after);

        return $ids;
    }

    /**
     * Follow pagination and collect every page GID (Shopify Pages have no blog).
     *
     * @return array|\WP_Error List of page GIDs, or a WP_Error on API failure.
     */
    protected function fetchPageIds() {
        $ids   = array();
        $after = null;
        do {
            $endpoint = '/api/shopify-pages';
            if ($after) {
                $endpoint .= '?after=' . rawurlencode($after);
            }
            $resp = $this->apiClient()->get($endpoint);
            if (is_wp_error($resp)) {
                return $resp;
            }
            foreach (($resp['pages'] ?? array()) as $page) {
                if (!empty($page['id'])) {
                    $ids[] = $page['id'];
                }
            }
            $hasNext = !empty($resp['pageInfo']['hasNextPage']);
            $after   = $resp['pageInfo']['endCursor'] ?? null;
        } while ($hasNext && $after);

        return $ids;
    }

    /**
     * Fetch one article's or page's full detail (and optionally its translations),
     * normalized to a uniform { article, translations } shape for the writer.
     *
     * @param string $id               Shopify article/page GID.
     * @param string $postType         'page' → /api/shopify-page, else /api/shopify-article.
     * @param bool   $withTranslations Whether to request locale variants.
     * @return array|\WP_Error { article, translations } or WP_Error.
     */
    protected function fetchDetail($id, $postType, $withTranslations) {
        $base     = ($postType === 'page') ? '/api/shopify-page' : '/api/shopify-article';
        $endpoint = $base . '?id=' . rawurlencode($id);
        if ($withTranslations) {
            $endpoint .= '&withTranslations=1';
        }
        $resp = $this->apiClient()->get($endpoint);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $detail = $resp['article'] ?? $resp['page'] ?? null;
        if (empty($detail)) {
            return new \WP_Error('wash_import_no_article', __('Content not found', 'wash-blog-manager'));
        }
        return array('article' => $detail, 'translations' => $resp['translations'] ?? array());
    }

    /**
     * Import one id end to end: fetch its detail, then write it. Extracted so the
     * batch loop stays flat and each item's outcome is isolated (mirrors
     * WashAdmin::processBulkPost()).
     *
     * @param string $id      Shopify article/page GID.
     * @param array  $options Import options (carries postType + translations flag).
     * @param object $writer  WashImportWriter (or a test double).
     * @return true|string True on success, or a human-readable reason on failure.
     */
    protected function processImportItem($id, array $options, $writer) {
        $resp = $this->fetchDetail($id, $options['postType'] ?? 'post', !empty($options['translations']));
        if (is_wp_error($resp)) {
            return $resp->get_error_message();
        }
        try {
            return $writer->importOne($resp['article'], $options, $resp['translations']);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Read the import options submitted with the preview request.
     *
     * @return array<string,mixed>
     */
    private function readOptions() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- ajaxImportPreview() verifies the nonce before calling this.
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'draft';
        if (!in_array($status, array('draft', 'shopify', 'publish'), true)) {
            $status = 'draft';
        }
        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : 'article';
        return array(
            'postType'       => ($type === 'page') ? 'page' : 'post',
            'status'         => $status,
            'images'         => (isset($_POST['images']) && $_POST['images'] === '1'),
            'metafields'     => (isset($_POST['metafields']) && $_POST['metafields'] === '1'),
            'blogAsCategory' => (isset($_POST['blogAsCategory']) && $_POST['blogAsCategory'] === '1'),
            'translations'   => (isset($_POST['translations']) && $_POST['translations'] === '1'),
            'skipImported'   => (isset($_POST['skipImported']) && $_POST['skipImported'] === '1'),
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * AJAX: build the id list for the chosen blog and stash it in a one-shot
     * transient, returning a token the batch loop then drives.
     */
    public function ajaxImportPreview() {
        check_ajax_referer('wash_import', 'nonce');
        // Reverse import creates posts as `publish`, sideloads media and creates
        // categories — capabilities beyond a Contributor's `edit_posts`. Require
        // `manage_options`, consistent with the settings screen this lives on and with
        // the blogs endpoint it calls (PLUGIN006). Nonce is CSRF-only, not authorization.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        $options = $this->readOptions();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $blogId = isset($_POST['blogId']) ? sanitize_text_field(wp_unslash($_POST['blogId'])) : '';
        if ($options['postType'] === 'page') {
            $ids = $this->fetchPageIds();
        } else {
            if ($blogId === '') {
                wp_send_json_error(__('Please choose a Shopify blog to import from.', 'wash-blog-manager'));
            }
            $ids = $this->fetchArticleIds($blogId);
        }
        if (is_wp_error($ids)) {
            wp_send_json_error($ids->get_error_message());
        }

        // Lowercase token: the batch handler reads it back through sanitize_key()
        // (which lowercases). A mixed-case token would then miss the transient on any
        // case-sensitive store — i.e. a persistent object cache (Redis/Memcached),
        // common in production — breaking every batch. DB transients happen to survive
        // via MySQL's case-insensitive collation, which is why dev "works".
        $token = strtolower(wp_generate_password(20, false, false));
        set_transient(
            WashContext::transientKey('wash_import_' . $token),
            array('articleIds' => $ids, 'blogId' => $blogId, 'options' => $options),
            HOUR_IN_SECONDS
        );

        wp_send_json_success(array('token' => $token, 'total' => count($ids)));
    }

    /**
     * AJAX: import one time-and-count-bounded batch of articles.
     * Same response shape as ajaxBulkSyncBatch() so import-progress.js mirrors
     * bulk-progress.js.
     */
    public function ajaxImportBatch() {
        $ctx     = $this->resolveBatchContext();
        $key     = $ctx['key'];
        $ids     = $ctx['data']['articleIds'];
        $total   = count($ids);
        $options = $ctx['data']['options'] ?? array();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in resolveBatchContext().
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if ($offset >= $total) {
            delete_transient($key);
            wp_send_json_success(array('processed' => 0, 'errors' => 0, 'nextOffset' => $offset, 'done' => true, 'errorMessages' => array()));
        }

        // Serialize batches per token (PLUGIN003): the server keeps processing under
        // ignore_user_abort() after a gateway 504, so a JS retry of the same offset could
        // run a SECOND worker over the same GIDs → duplicate posts/media. An atomic
        // add_option() lock makes a concurrent request wait (409 → JS retries) instead;
        // the redo is then idempotent (dedup by _wash_article_id / _wash_src_cdn_url).
        $lockKey = 'wash_import_lock_' . $ctx['token'];
        if (!$this->acquireLock($lockKey)) {
            wp_send_json_error(array('code' => 'locked', 'message' => __('A batch for this import is still running.', 'wash-blog-manager')), 409);
        }

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- importing many articles (each a network read + media sideload) can exceed default max_execution_time; suppressed (@) because some hosts disable set_time_limit.
        @set_time_limit(0);
        ignore_user_abort(true);

        $result     = $this->processBatchWindow($ids, $offset, $total, $options);
        $nextOffset = $offset + $result['count'];
        $done       = $nextOffset >= $total;
        if ($done) {
            delete_transient($key);
        }

        // Release the lock BEFORE responding: wp_send_json_success() exits, which skips
        // any finally block, so the lock must be dropped here or it would deadlock.
        $this->releaseLock($lockKey);

        wp_send_json_success(array(
            'processed'     => $result['processed'],
            'errors'        => $result['errors'],
            'errorMessages' => array_slice($result['errorMsgs'], 0, 5),
            'nextOffset'    => $nextOffset,
            'done'          => $done,
        ));
    }

    /**
     * Verify a batch request (nonce + `manage_options`) and resolve its import
     * session transient. Exits via wp_send_json_error() on any failure. Split from
     * ajaxImportBatch() to keep its cognitive complexity in check (Sonar).
     *
     * @return array{token:string,key:string,data:array} Resolved session.
     */
    private function resolveBatchContext() {
        check_ajax_referer('wash_import', 'nonce');
        // Reverse import creates posts as `publish`, sideloads media and creates
        // categories — capabilities beyond a Contributor's `edit_posts`. Require
        // `manage_options`, consistent with the settings screen this lives on and with
        // the blogs endpoint it calls (PLUGIN006). Nonce is CSRF-only, not authorization.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
        $key   = WashContext::transientKey('wash_import_' . $token);
        $data  = $token ? get_transient($key) : null;
        if (!$data || empty($data['articleIds'])) {
            wp_send_json_error(__('Import session expired', 'wash-blog-manager'));
        }

        return array('token' => $token, 'key' => $key, 'data' => $data);
    }

    /**
     * Process one time-and-count-bounded window of the id list, importing each item
     * through the writer. Bounded by BOTH a hard cap AND a wall-clock budget
     * (whichever hits first) so a slow media sideload can't push the request past the
     * gateway window (cap lower than the push because each item downloads images —
     * #253). Split from ajaxImportBatch() (Sonar cognitive complexity).
     *
     * @param array $ids     Article/page GIDs.
     * @param int   $offset  Window start index.
     * @param int   $total   Total id count.
     * @param array $options Import options.
     * @return array{processed:int,errors:int,errorMsgs:array,count:int}
     */
    private function processBatchWindow($ids, $offset, $total, $options) {
        $batchSize  = max(1, (int) apply_filters('wash_import_batch_size', 5));
        $timeBudget = (float) apply_filters('wash_import_time_budget', 20.0);
        $startedAt  = microtime(true);

        $writer    = $this->writer();
        $processed = 0;
        $errors    = 0;
        $errorMsgs = array();
        $count     = 0;

        for ($i = $offset; $i < $total && $count < $batchSize; $i++) {
            $id = $ids[$i];
            $count++;
            $result = $this->processImportItem($id, $options, $writer);
            if ($result === true) {
                $processed++;
            } else {
                $errors++;
                $errorMsgs[] = $id . ': ' . $result;
            }

            if ((microtime(true) - $startedAt) >= $timeBudget) {
                break;
            }
        }

        return array('processed' => $processed, 'errors' => $errors, 'errorMsgs' => $errorMsgs, 'count' => $count);
    }

    /**
     * AJAX: lightweight status for a token (parity with wash_check_sync_status).
     */
    public function ajaxImportStatus() {
        check_ajax_referer('wash_import', 'nonce');
        // Reverse import creates posts as `publish`, sideloads media and creates
        // categories — capabilities beyond a Contributor's `edit_posts`. Require
        // `manage_options`, consistent with the settings screen this lives on and with
        // the blogs endpoint it calls (PLUGIN006). Nonce is CSRF-only, not authorization.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'wash-blog-manager'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
        $data  = $token ? get_transient(WashContext::transientKey('wash_import_' . $token)) : null;

        wp_send_json_success(array(
            'active' => (bool) $data,
            'total'  => ($data && !empty($data['articleIds'])) ? count($data['articleIds']) : 0,
        ));
    }

    /**
     * Acquire an atomic per-token batch lock (PLUGIN003). add_option() inserts a row and
     * returns false if it already exists, so it is a real cross-process mutex. A lock left
     * behind by a crashed worker is stolen after a grace period so an import can never
     * deadlock permanently.
     *
     * @param string $lockKey Option name for the lock.
     * @return bool True if the lock was acquired.
     */
    protected function acquireLock($lockKey) {
        if (add_option($lockKey, time(), '', 'no')) {
            return true;
        }
        $heldAt = (int) get_option($lockKey, 0);
        $ttl    = (int) apply_filters('wash_import_lock_ttl', 120);
        if ($heldAt > 0 && (time() - $heldAt) > $ttl) {
            update_option($lockKey, time(), 'no'); // steal a stale lock
            return true;
        }
        return false;
    }

    /**
     * Release the per-token batch lock.
     *
     * @param string $lockKey Option name for the lock.
     */
    protected function releaseLock($lockKey) {
        delete_option($lockKey);
    }
}
