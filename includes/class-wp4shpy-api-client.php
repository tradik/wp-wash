<?php
/**
 * REST API client for communicating with the Shopify App.
 * Handles authentication, request signing, and error handling.
 */
class wp4shpyApiClient {

    /** @var string Shopify App base URL */
    private $appUrl;

    /** @var string API key for authentication */
    private $apiKey;

    /** @var string API secret for HMAC signing */
    private $apiSecret;

    /** @var string HTTP Basic Auth username */
    private $httpUser;

    /** @var string HTTP Basic Auth password */
    private $httpPass;

    /** @var int Maximum retry attempts for rate-limited requests */
    private $maxRetries = 3;

    public function __construct() {
        $this->appUrl    = 'https://app-wp4shpy.tradik.com';
        $this->apiKey    = get_option('wp4shpy_api_key', '');
        $this->apiSecret = get_option('wp4shpy_api_secret', '');
        $this->httpUser  = get_option('wp4shpy_http_user', '');
        $this->httpPass  = get_option('wp4shpy_http_pass', '');
    }

    /**
     * Send a GET request to the Shopify App.
     *
     * @param string $endpoint API endpoint path (e.g. "/api/blog-mappings")
     * @return array|WP_Error  Decoded response or WP_Error
     */
    public function get($endpoint) {
        if (empty($this->apiKey)) {
            return new \WP_Error('wp4shpy_not_configured', __('wp4shpy is not configured.', 'wp4shpy'));
        }

        $url = $this->appUrl . $endpoint;
        $timestamp = (string) time();

        $headers = [
            'Accept'                 => 'application/json',
            'X-WP4Shopify-Key'       => $this->apiKey,
            'X-WP4Shopify-Timestamp' => $timestamp,
        ];

        // Add HTTP Basic Auth if configured
        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $responseBody = json_decode($body, true);

        if ($code >= 400) {
            $errorMsg = $this->extractErrorMessage($responseBody, $body, $code);
            return new \WP_Error('wp4shpy_api_error', $errorMsg, ['status' => $code]);
        }

        if ($responseBody === null && !empty($body)) {
            return new \WP_Error('wp4shpy_invalid_response', __('Invalid JSON response from API', 'wp4shpy'));
        }

        return $responseBody;
    }

    /**
     * Send a POST request to the Shopify App.
     *
     * @param string $endpoint API endpoint path (e.g. "/api/content-sync")
     * @param array  $data     Request body data
     * @return array|WP_Error  Decoded response or WP_Error
     */
    public function post($endpoint, array $data) {
        error_log("[wp4shpy API] POST request to endpoint: {$endpoint}");

        if (empty($this->apiKey)) {
            error_log("[wp4shpy API] ERROR: API key is empty - not configured");
            return new \WP_Error('wp4shpy_not_configured', __('wp4shpy is not configured.', 'wp4shpy'));
        }

        $url = $this->appUrl . $endpoint;
        $body = wp_json_encode($data);
        $timestamp = (string) time();

        error_log("[wp4shpy API] URL: {$url}");
        error_log("[wp4shpy API] API Key (first 8 chars): " . substr($this->apiKey, 0, 8) . "...");

        $headers = [
            'Content-Type'           => 'application/json',
            'X-WP4Shopify-Key'       => $this->apiKey,
            'X-WP4Shopify-Timestamp' => $timestamp,
            'X-WP4Shopify-Signature' => $this->signRequest($timestamp, $body),
        ];

        $result = $this->sendWithRetry($url, $body, $headers);

        if (is_wp_error($result)) {
            error_log("[wp4shpy API] ERROR: " . $result->get_error_message());
        } else {
            error_log("[wp4shpy API] SUCCESS: " . wp_json_encode($result));
        }

        return $result;
    }

    /**
     * Send a DELETE request to the Shopify App.
     *
     * @param string $endpoint API endpoint path (e.g. "/api/blog-mappings/123")
     * @return array|WP_Error  Decoded response or WP_Error
     */
    public function delete($endpoint) {
        if (empty($this->apiKey)) {
            return new \WP_Error('wp4shpy_not_configured', __('wp4shpy is not configured.', 'wp4shpy'));
        }

        $url = $this->appUrl . $endpoint;
        $timestamp = (string) time();

        $headers = [
            'Accept'                 => 'application/json',
            'X-WP4Shopify-Key'       => $this->apiKey,
            'X-WP4Shopify-Timestamp' => $timestamp,
        ];

        // Add HTTP Basic Auth if configured
        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'timeout' => 30,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $responseBody = json_decode($body, true);

        if ($code >= 400) {
            $errorMsg = $this->extractErrorMessage($responseBody, $body, $code);
            return new \WP_Error('wp4shpy_api_error', $errorMsg, ['status' => $code]);
        }

        return $responseBody ?: ['success' => true];
    }

    /**
     * Test the connection to the Shopify App.
     *
     * @return array|WP_Error Health check response or error
     */
    public function testConnection() {
        $args = [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ];

        // Add HTTP Basic Auth if configured
        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        $response = wp_remote_get($this->appUrl . '/api/health', $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('wp4shpy_health_fail', sprintf(__('Health check failed with status %d', 'wp4shpy'), $code));
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Diagnose connection issues with the Shopify App.
     * Uses the authenticated diagnose endpoint to check session validity.
     *
     * @return array|WP_Error Diagnosis response or error
     */
    public function diagnose() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wp4shpy_not_configured', __('wp4shpy is not configured.', 'wp4shpy'));
        }

        $url = $this->appUrl . '/api/diagnose';
        $timestamp = (string) time();

        $headers = [
            'Accept'                 => 'application/json',
            'X-WP4Shopify-Key'       => $this->apiKey,
            'X-WP4Shopify-Timestamp' => $timestamp,
        ];

        // Add HTTP Basic Auth if configured
        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $responseBody = json_decode($body, true);

        // Return full response including diagnosis info even on error
        if ($responseBody !== null) {
            return $responseBody;
        }

        return new \WP_Error('wp4shpy_diagnose_error', sprintf(__('Diagnosis failed (HTTP %d)', 'wp4shpy'), $code));
    }

    /**
     * Debug Shopify session - shows detailed session information.
     *
     * @return array|WP_Error Debug info or error
     */
    public function debugSession() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wp4shpy_not_configured', __('wp4shpy is not configured.', 'wp4shpy'));
        }

        $url = $this->appUrl . '/api/debug-session';
        $timestamp = (string) time();

        $headers = [
            'Accept'                 => 'application/json',
            'X-WP4Shopify-Key'       => $this->apiKey,
            'X-WP4Shopify-Timestamp' => $timestamp,
        ];

        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseBody = json_decode($body, true);

        if ($responseBody !== null) {
            return $responseBody;
        }

        $code = wp_remote_retrieve_response_code($response);
        return new \WP_Error('wp4shpy_debug_error', sprintf(__('Debug failed (HTTP %d)', 'wp4shpy'), $code));
    }

    /**
     * Reset Shopify session - deletes session to force re-authentication.
     *
     * @return array|WP_Error Result or error
     */
    public function resetSession() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wp4shpy_not_configured', __('wp4shpy is not configured.', 'wp4shpy'));
        }

        $url = $this->appUrl . '/api/debug-session';
        $timestamp = (string) time();

        $headers = [
            'Accept'                 => 'application/json',
            'X-WP4Shopify-Key'       => $this->apiKey,
            'X-WP4Shopify-Timestamp' => $timestamp,
        ];

        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'timeout' => 15,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseBody = json_decode($body, true);

        if ($responseBody !== null) {
            return $responseBody;
        }

        $code = wp_remote_retrieve_response_code($response);
        return new \WP_Error('wp4shpy_reset_error', sprintf(__('Reset failed (HTTP %d)', 'wp4shpy'), $code));
    }

    /**
     * Fix Shopify session scope - updates session to include all granted scopes.
     * This fixes the Token Exchange bug where only write_* scopes are stored.
     *
     * @return array|WP_Error Result or error
     */
    public function fixSession() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wp4shpy_not_configured', __('wp4shpy is not configured.', 'wp4shpy'));
        }

        $url = $this->appUrl . '/api/fix-session';
        $timestamp = (string) time();

        $headers = [
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
            'X-WP4Shopify-Key'       => $this->apiKey,
            'X-WP4Shopify-Timestamp' => $timestamp,
        ];

        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => '{}',
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseBody = json_decode($body, true);

        if ($responseBody !== null) {
            return $responseBody;
        }

        $code = wp_remote_retrieve_response_code($response);
        return new \WP_Error('wp4shpy_fix_error', sprintf(__('Fix session failed (HTTP %d)', 'wp4shpy'), $code));
    }

    /**
     * Send request with exponential backoff retry on rate limiting.
     */
    private function sendWithRetry($url, $body, $headers, $attempt = 0) {
        // Add HTTP Basic Auth if configured
        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
            error_log("[wp4shpy API] Using HTTP Basic Auth");
        }

        error_log("[wp4shpy API] Sending POST request (attempt: {$attempt})");

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            error_log("[wp4shpy API] wp_remote_post error: " . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        error_log("[wp4shpy API] Response HTTP code: {$code}");

        if ($code === 429 && $attempt < $this->maxRetries) {
            $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');
            $delay = $retryAfter > 0 ? $retryAfter : pow(2, $attempt + 1);
            error_log("[wp4shpy API] Rate limited, retrying in {$delay}s");
            sleep($delay);
            return $this->sendWithRetry($url, $body, $headers, $attempt + 1);
        }

        $body = wp_remote_retrieve_body($response);
        error_log("[wp4shpy API] Response body: " . substr($body, 0, 500));
        $responseBody = json_decode($body, true);

        if ($code >= 400) {
            $errorMsg = $this->extractErrorMessage($responseBody, $body, $code);
            error_log("[wp4shpy API] Error response: {$errorMsg}");
            return new \WP_Error('wp4shpy_api_error', $errorMsg, ['status' => $code]);
        }

        return $responseBody;
    }

    /**
     * Extract a meaningful error message from API response.
     *
     * @param array|null $responseBody Decoded JSON response
     * @param string     $rawBody      Raw response body
     * @param int        $statusCode   HTTP status code
     * @return string Error message
     */
    private function extractErrorMessage($responseBody, $rawBody, $statusCode) {
        // Try to get error from JSON response
        if (is_array($responseBody) && !empty($responseBody['error'])) {
            return $responseBody['error'];
        }

        // Check for common HTTP errors
        $httpErrors = [
            401 => __('Unauthorized - check your API credentials', 'wp4shpy'),
            403 => __('Forbidden - access denied', 'wp4shpy'),
            404 => __('API endpoint not found', 'wp4shpy'),
            500 => __('Server error - please try again later', 'wp4shpy'),
            502 => __('Bad gateway - API server unavailable', 'wp4shpy'),
            503 => __('Service unavailable - API server down', 'wp4shpy'),
        ];

        if (isset($httpErrors[$statusCode])) {
            return $httpErrors[$statusCode];
        }

        // If we have a raw body, include a snippet for debugging
        if (!empty($rawBody) && strlen($rawBody) < 200) {
            return sprintf(__('API error (HTTP %d): %s', 'wp4shpy'), $statusCode, substr($rawBody, 0, 100));
        }

        return sprintf(__('API error (HTTP %d)', 'wp4shpy'), $statusCode);
    }

    /**
     * Generate HMAC-SHA256 signature for request authentication.
     */
    private function signRequest($timestamp, $body) {
        $payload = $timestamp . ':' . $body;
        return hash_hmac('sha256', $payload, $this->apiSecret);
    }

    /**
     * Check if the client is properly configured.
     */
    public function isConfigured() {
        return !empty($this->apiKey) && !empty($this->apiSecret);
    }

    /**
     * Backup current plugin settings to the Shopify App.
     * Settings are stored per-connection and can be restored on reauth.
     *
     * @return array|WP_Error Result or error
     */
    public function backupSettings() {
        $settings = [
            'syncOnPublish'    => get_option('wp4shpy_sync_on_publish', '1'),
            'syncImages'       => get_option('wp4shpy_sync_images', '1'),
            'syncMetafields'   => get_option('wp4shpy_sync_metafields', '1'),
            'postTypes'        => get_option('wp4shpy_post_types', 'post'),
            'blockRobots'      => get_option('wp4shpy_block_robots', '1'),
            'metaWpCore'       => get_option('wp4shpy_meta_wp_core', '1'),
            'metaYoast'        => get_option('wp4shpy_meta_yoast', '0'),
            'metaRankmath'     => get_option('wp4shpy_meta_rankmath', '0'),
            'metaAcf'          => get_option('wp4shpy_meta_acf', '0'),
            'hreflangsEnabled' => get_option('wp4shpy_hreflangs_enabled', '0'),
            'authorsEnabled'   => get_option('wp4shpy_authors_enabled', '0'),
            'authorMappings'   => get_option('wp4shpy_author_mappings', []),
            'filesEnabled'     => get_option('wp4shpy_files_enabled', '1'),
            'webpConvert'      => get_option('wp4shpy_webp_convert', '0'),
            'allowedFileTypes' => get_option('wp4shpy_allowed_file_types', ['images']),
            'rewriteUrls'      => get_option('wp4shpy_rewrite_internal_urls', '0'),
        ];

        return $this->post('/api/settings-sync', $settings);
    }

    /**
     * Get backed up settings from the Shopify App.
     * Used during reauth to restore previous configuration.
     *
     * @return array|WP_Error Settings or error
     */
    public function getBackedUpSettings() {
        return $this->get('/api/settings-sync');
    }

    /**
     * Restore settings from backup to WordPress options.
     *
     * @param array $settings Settings array from backup
     * @return bool True on success
     */
    public function restoreSettings($settings) {
        if (empty($settings) || !is_array($settings)) {
            return false;
        }

        $optionMap = [
            'syncOnPublish'    => 'wp4shpy_sync_on_publish',
            'syncImages'       => 'wp4shpy_sync_images',
            'syncMetafields'   => 'wp4shpy_sync_metafields',
            'postTypes'        => 'wp4shpy_post_types',
            'blockRobots'      => 'wp4shpy_block_robots',
            'metaWpCore'       => 'wp4shpy_meta_wp_core',
            'metaYoast'        => 'wp4shpy_meta_yoast',
            'metaRankmath'     => 'wp4shpy_meta_rankmath',
            'metaAcf'          => 'wp4shpy_meta_acf',
            'hreflangsEnabled' => 'wp4shpy_hreflangs_enabled',
            'authorsEnabled'   => 'wp4shpy_authors_enabled',
            'authorMappings'   => 'wp4shpy_author_mappings',
            'filesEnabled'     => 'wp4shpy_files_enabled',
            'webpConvert'      => 'wp4shpy_webp_convert',
            'allowedFileTypes' => 'wp4shpy_allowed_file_types',
            'rewriteUrls'      => 'wp4shpy_rewrite_internal_urls',
        ];

        foreach ($optionMap as $key => $option) {
            if (isset($settings[$key])) {
                update_option($option, $settings[$key]);
            }
        }

        return true;
    }
}
