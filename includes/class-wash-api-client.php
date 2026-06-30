<?php
/**
 * REST API client for communicating with the Shopify App.
 * Handles authentication, request signing, and error handling.
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

class WashApiClient {

    /** @var string Shopify App base URL */
    private $appUrl;

    /** @var string API key for authentication */
    private $apiKey;

    /** @var string HTTP Basic Auth username */
    private $httpUser;

    /** @var string HTTP Basic Auth password */
    private $httpPass;

    /** @var int Maximum retry attempts for rate-limited requests */
    private $maxRetries = 3;

    /** @var array<string,mixed> The connection this client operates on (#216). */
    private $connection;

    /**
     * @param array<string,mixed>|null $connection A connection from
     *   WashConnection::get(); null resolves the active connection via
     *   WashContext. For the primary connection the credentials come from the
     *   historical unprefixed options, so single-store behaviour is unchanged.
     */
    public function __construct($connection = null) {
        if (!is_array($connection)) {
            // activeId() always resolves to a real id, so get() returns an array.
            $connection = WashConnection::get(WashContext::activeId());
        }
        $this->connection = $connection;
        $this->apiKey    = isset($connection['apiKey'])   ? $connection['apiKey']   : '';
        $this->httpUser  = isset($connection['httpUser']) ? $connection['httpUser'] : '';
        $this->httpPass  = isset($connection['httpPass']) ? $connection['httpPass'] : '';
        $this->appUrl    = $this->resolveAppUrl();
    }

    /**
     * Resolve the Shopify App URL based on environment.
     * Dev sites (*.tradik.dev) use dev API, others use production.
     * Hilo domains route to secondary app instance (forced — overrides any option).
     *
     * @return string App URL
     */
    private function resolveAppUrl() {
        $siteUrl = get_site_url();
        $host = wp_parse_url($siteUrl, PHP_URL_HOST);

        // Hilo domains: force secondary app instance regardless of stored option
        // (override needed because legacy installs may have wash_app_url set to primary)
        if ($host === 'hilo.com' || substr($host, -9) === '.hilo.com') {
            return 'https://app-wash1.tradik.com';
        }

        // Explicit app URL from the connection definition (for primary this is
        // the historical wash_app_url option). Only a fallback — the Hilo host
        // override above always wins, so both Hilo connections reach app-wash1.
        $configuredUrl = isset($this->connection['appUrl']) ? $this->connection['appUrl'] : '';
        if (!empty($configuredUrl)) {
            return $configuredUrl;
        }

        // Auto-detect dev environment; otherwise production.
        return (strpos($siteUrl, 'tradik.dev') !== false)
            ? 'https://app-wash.tradik.dev'
            : 'https://app-wash.tradik.com';
    }

    /**
     * Get the Shopify app slug based on the configured app URL.
     * Dev environment uses 'wash-blog-manager-dev', production uses 'wash'.
     *
     * @return string Shopify app slug
     */
    public function getShopifyAppSlug() {
        if (strpos($this->appUrl, 'tradik.dev') !== false) {
            return 'wash-blog-manager-dev';
        }
        if (strpos($this->appUrl, 'app-wash1') !== false) {
            return 'wash-blog';
        }
        return 'wash';
    }

    /**
     * Get the configured app URL.
     *
     * @return string App URL
     */
    public function getAppUrl() {
        return $this->appUrl;
    }

    /**
     * Build base headers for all API requests.
     * Includes API key, timestamp, plugin version, and optional HTTP Basic Auth.
     *
     * @param string $timestamp Unix timestamp
     * @return array Headers array
     */
    private function getBaseHeaders($timestamp) {
        $headers = [
            'Accept'               => 'application/json',
            'X-Wash-Key'           => $this->apiKey,
            'X-Wash-Timestamp'     => $timestamp,
            'X-Wash-Plugin-Version' => WASH_VERSION,
        ];

        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
        }

        return $headers;
    }

    /**
     * Send a GET request to the Shopify App.
     *
     * @param string $endpoint API endpoint path (e.g. "/api/blog-mappings")
     * @return array|WP_Error  Decoded response or WP_Error
     */
    public function get($endpoint) {
        if (empty($this->apiKey)) {
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . $endpoint;
        $timestamp = (string) time();
        $headers = $this->getBaseHeaders($timestamp);

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
            return new \WP_Error('wash_api_error', $errorMsg, ['status' => $code]);
        }

        if ($responseBody === null && !empty($body)) {
            return new \WP_Error('wash_invalid_response', __('Invalid JSON response from API', 'wash-blog-manager'));
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
        washLog("[wash API] POST request to endpoint: {$endpoint}");

        if (empty($this->apiKey)) {
            washLog("[wash API] ERROR: API key is empty - not configured");
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . $endpoint;
        $body = wp_json_encode($data);
        $timestamp = (string) time();

        washLog("[wash API] URL: {$url}");
        washLog("[wash API] API Key (first 8 chars): " . substr($this->apiKey, 0, 8) . "...");

        $headers = $this->getBaseHeaders($timestamp);
        $headers['Content-Type'] = 'application/json';

        $result = $this->sendWithRetry($url, $body, $headers);

        if (is_wp_error($result)) {
            washLog("[wash API] ERROR: " . $result->get_error_message());
        } else {
            washLog("[wash API] SUCCESS: " . wp_json_encode($result));
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
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . $endpoint;
        $timestamp = (string) time();
        $headers = $this->getBaseHeaders($timestamp);

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
            return new \WP_Error('wash_api_error', $errorMsg, ['status' => $code]);
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
            // translators: %d is the HTTP status code returned by the health check.
            return new \WP_Error('wash_health_fail', sprintf(__('Health check failed with status %d', 'wash-blog-manager'), $code));
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Send a ping to report plugin version to Shopify App.
     * Called on plugin activation, update, or manual check.
     *
     * @return array|WP_Error Ping response or error
     */
    public function ping() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . '/api/ping';
        $timestamp = (string) time();
        $headers = $this->getBaseHeaders($timestamp);
        $headers['Content-Type'] = 'application/json';

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => '{}',
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $responseBody = json_decode($body, true);

        if ($code >= 400) {
            $errorMsg = $this->extractErrorMessage($responseBody, $body, $code);
            return new \WP_Error('wash_ping_error', $errorMsg, ['status' => $code]);
        }

        return $responseBody ?: ['success' => true];
    }

    /**
     * Diagnose connection issues with the Shopify App.
     * Uses the authenticated diagnose endpoint to check session validity.
     *
     * @return array|WP_Error Diagnosis response or error
     */
    public function diagnose() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . '/api/diagnose';
        $timestamp = (string) time();
        $headers = $this->getBaseHeaders($timestamp);

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

        // translators: %d is the HTTP status code.
        return new \WP_Error('wash_diagnose_error', sprintf(__('Diagnosis failed (HTTP %d)', 'wash-blog-manager'), $code));
    }

    /**
     * Get permissions from Shopify App.
     * Returns which settings WordPress is allowed to modify.
     * Results are cached for 5 minutes.
     *
     * @return array|WP_Error Permissions array or error
     */
    public function getPermissions() {
        // Check cache first
        $cached = get_transient(WashContext::transientKey('wash_permissions'));
        if ($cached !== false) {
            return $cached;
        }

        $result = $this->get('/api/settings-permissions');

        if (is_wp_error($result)) {
            // Return default permissions (all allowed) on error
            return [
                'canEditSync' => true,
                'canEditBlogs' => true,
                'canEditHreflangs' => true,
                'canEditMeta' => true,
                'canEditAuthors' => true,
                'canEditFiles' => true,
                'canEditContent' => true,
                'canEditRedirections' => true,
                'canEditAdvanced' => true,
                'settingsAdminOnly' => false,
                'settingsAllowedUsers' => '',
            ];
        }

        $permissions = $result['permissions'] ?? [];

        // Ensure admin-only fields have defaults
        if (!isset($permissions['settingsAdminOnly'])) {
            $permissions['settingsAdminOnly'] = false;
        }
        if (!isset($permissions['settingsAllowedUsers'])) {
            $permissions['settingsAllowedUsers'] = '';
        }

        // Cache for 5 minutes
        set_transient(WashContext::transientKey('wash_permissions'), $permissions, 5 * MINUTE_IN_SECONDS);

        return $permissions;
    }

    /**
     * Check if the current user can modify Wash settings based on admin-only permissions.
     *
     * @param array|null $permissions Optional permissions array. If null, fetches from cache/API.
     * @return bool True if user can modify settings, false otherwise.
     */
    public function canUserModifySettings($permissions = null) {
        if ($permissions === null) {
            $permissions = $this->getPermissions();
        }

        // If permissions couldn't be fetched, allow access (fail-open)
        if (is_wp_error($permissions)) {
            return true;
        }

        // Get current user
        $currentUser = wp_get_current_user();
        if (!$currentUser || !$currentUser->exists()) {
            return false;
        }

        // Check allowed usernames first (they always have access)
        $allowedUsers = isset($permissions['settingsAllowedUsers']) ? $permissions['settingsAllowedUsers'] : '';
        if (!empty($allowedUsers)) {
            $allowedUsersList = array_map('trim', explode(',', $allowedUsers));
            if (in_array($currentUser->user_login, $allowedUsersList, true)) {
                return true;
            }
        }

        // Check admin-only setting
        $adminOnly = isset($permissions['settingsAdminOnly']) ? $permissions['settingsAdminOnly'] : false;
        if ($adminOnly) {
            // Only users with 'manage_options' capability (administrators) can modify
            return current_user_can('manage_options');
        }

        // If not admin-only, anyone with manage_options can modify (standard WP permission)
        return current_user_can('manage_options');
    }

    /**
     * Clear cached permissions.
     * Call this when settings are changed from Shopify side.
     */
    public function clearPermissionsCache() {
        delete_transient(WashContext::transientKey('wash_permissions'));
    }

    /**
     * Debug Shopify session - shows detailed session information.
     *
     * @return array|WP_Error Debug info or error
     */
    public function debugSession() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . '/api/debug-session';
        $timestamp = (string) time();
        $headers = $this->getBaseHeaders($timestamp);

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
        // translators: %d is the HTTP status code.
        return new \WP_Error('wash_debug_error', sprintf(__('Debug failed (HTTP %d)', 'wash-blog-manager'), $code));
    }

    /**
     * Reset Shopify session - deletes session to force re-authentication.
     *
     * @return array|WP_Error Result or error
     */
    public function resetSession() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . '/api/debug-session';
        $timestamp = (string) time();
        $headers = $this->getBaseHeaders($timestamp);

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
        // translators: %d is the HTTP status code.
        return new \WP_Error('wash_reset_error', sprintf(__('Reset failed (HTTP %d)', 'wash-blog-manager'), $code));
    }

    /**
     * Fix Shopify session scope - updates session to include all granted scopes.
     * This fixes the Token Exchange bug where only write_* scopes are stored.
     *
     * @return array|WP_Error Result or error
     */
    public function fixSession() {
        if (empty($this->apiKey)) {
            return new \WP_Error('wash_not_configured', __('Wash is not configured.', 'wash-blog-manager'));
        }

        $url = $this->appUrl . '/api/fix-session';
        $timestamp = (string) time();
        $headers = $this->getBaseHeaders($timestamp);
        $headers['Content-Type'] = 'application/json';

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
        // translators: %d is the HTTP status code.
        return new \WP_Error('wash_fix_error', sprintf(__('Fix session failed (HTTP %d)', 'wash-blog-manager'), $code));
    }

    /**
     * Send request with exponential backoff retry on rate limiting.
     */
    private function sendWithRetry($url, $body, $headers, $attempt = 0) {
        // Add HTTP Basic Auth if configured
        if (!empty($this->httpUser) && !empty($this->httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->httpUser . ':' . $this->httpPass);
            washLog("[wash API] Using HTTP Basic Auth");
        }

        washLog("[wash API] Sending POST request (attempt: {$attempt})");

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            washLog("[wash API] wp_remote_post error: " . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        washLog("[wash API] Response HTTP code: {$code}");

        if ($code === 429 && $attempt < $this->maxRetries) {
            $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');
            $delay = $retryAfter > 0 ? $retryAfter : pow(2, $attempt + 1);
            washLog("[wash API] Rate limited, retrying in {$delay}s");
            sleep($delay);
            return $this->sendWithRetry($url, $body, $headers, $attempt + 1);
        }

        $body = wp_remote_retrieve_body($response);
        washLog("[wash API] Response body: " . substr($body, 0, 500));
        $responseBody = json_decode($body, true);

        if ($code >= 400) {
            $errorMsg = $this->extractErrorMessage($responseBody, $body, $code);
            washLog("[wash API] Error response: {$errorMsg}");
            return new \WP_Error('wash_api_error', $errorMsg, ['status' => $code]);
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
            401 => __('Unauthorized - check your API credentials', 'wash-blog-manager'),
            403 => __('Forbidden - access denied', 'wash-blog-manager'),
            404 => __('API endpoint not found', 'wash-blog-manager'),
            500 => __('Server error - please try again later', 'wash-blog-manager'),
            502 => __('Bad gateway - API server unavailable', 'wash-blog-manager'),
            503 => __('Service unavailable - API server down', 'wash-blog-manager'),
        ];

        if (isset($httpErrors[$statusCode])) {
            return $httpErrors[$statusCode];
        }

        // If we have a raw body, include a snippet for debugging
        if (!empty($rawBody) && strlen($rawBody) < 200) {
            // translators: 1: HTTP status code, 2: response body snippet.
            return sprintf(__('API error (HTTP %1$d): %2$s', 'wash-blog-manager'), $statusCode, substr($rawBody, 0, 100));
        }

        // translators: %d is the HTTP status code.
        return sprintf(__('API error (HTTP %d)', 'wash-blog-manager'), $statusCode);
    }

    /**
     * Check if the client is properly configured.
     */
    public function isConfigured() {
        return !empty($this->apiKey);
    }

    /**
     * Get plan info including features and usage from the Shopify App.
     * Caches the result for 5 minutes to reduce API calls.
     *
     * @param bool $forceRefresh Force refresh ignoring cache.
     * @return array|WP_Error Plan info or error.
     */
    public function getPlanInfo($forceRefresh = false) {
        $cacheKey = WashContext::transientKey('wash_plan_info');
        $cached = get_transient($cacheKey);

        if ($cached !== false && !$forceRefresh) {
            return $cached;
        }

        $result = $this->get('/api/plan-info');

        if (is_wp_error($result)) {
            return $result;
        }

        // Cache for 5 minutes
        set_transient($cacheKey, $result, 5 * MINUTE_IN_SECONDS);

        return $result;
    }

    /**
     * Check if a specific feature is available on the current plan.
     *
     * @param string $featureName Feature name (e.g., 'seoSync', 'acfSync').
     * @return bool True if feature is available.
     */
    public function hasFeature($featureName) {
        $planInfo = $this->getPlanInfo();

        if (is_wp_error($planInfo)) {
            // On error, assume feature is available to not block functionality
            return true;
        }

        return !empty($planInfo['features'][$featureName]);
    }

    /**
     * Get the minimum plan required for a feature.
     *
     * @param string $featureName Feature name.
     * @return string|null Plan name or null if feature is available.
     */
    public function getRequiredPlanForFeature($featureName) {
        $featurePlans = [
            'seoSync' => 'PRO',
            'metafields' => 'PRO',
            'hreflang' => 'PRO',
            'authorMapping' => 'PRO',
            'blogMapping' => 'PRO',
            'smartRedirections' => 'BUSINESS',
            'acfSync' => 'BUSINESS',
            'prioritySupport' => 'BUSINESS',
        ];

        if (!$this->hasFeature($featureName)) {
            return $featurePlans[$featureName] ?? 'PRO';
        }

        return null; // Feature is available
    }

    /**
     * Clear cached plan info.
     */
    public function clearPlanInfoCache() {
        delete_transient(WashContext::transientKey('wash_plan_info'));
    }

    /**
     * Backup current plugin settings to the Shopify App.
     * Settings are stored per-connection and can be restored on reauth.
     *
     * @return array|WP_Error Result or error
     */
    public function backupSettings() {
        $settings = [
            'syncOnPublish'    => WashContext::option('wash_sync_on_publish', '1'),
            'syncMetafields'   => WashContext::option('wash_sync_metafields', '1'),
            'postTypes'        => WashContext::option('wash_post_types', 'post,page'),
            // wash_block_robots stays a GLOBAL option (#216): the robots.txt block
            // is site-wide, one per WordPress install, not per Shopify connection.
            'blockRobots'      => get_option('wash_block_robots', '1'),
            'metaFieldConfig'  => json_decode(WashContext::option('wash_meta_field_config', '[]'), true),
            // Per-source metafield flags. Without these the app coerces missing
            // values to false on every WP-side save, silently resetting them (LOW-17, #178).
            'metaWpCore'       => WashContext::option('wash_meta_wp_core', '1'),
            'metaYoast'        => WashContext::option('wash_meta_yoast', '0'),
            'metaRankmath'     => WashContext::option('wash_meta_rankmath', '0'),
            'metaAcf'          => WashContext::option('wash_meta_acf', '0'),
            'hreflangsEnabled' => WashContext::option('wash_hreflangs_enabled', '0'),
            'authorsEnabled'   => WashContext::option('wash_authors_enabled', '0'),
            'authorMappings'   => WashContext::option('wash_author_mappings', []),
            'filesEnabled'     => WashContext::option('wash_files_enabled', '1'),
            'webpConvert'      => WashContext::option('wash_webp_convert', '0'),
            'allowedFileTypes' => WashContext::option('wash_allowed_file_types', ['images']),
            'blocksEnabled'    => WashContext::option('wash_blocks_enabled', '1'),
            'syncBlocksMeta'   => WashContext::option('wash_sync_blocks_meta', '1'),
            'syncBlocksCss'    => WashContext::option('wash_sync_blocks_css', '0'),
            'redirectionsEnabled' => WashContext::option('wash_redirections_enabled', '0'),
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

        // Set flag to prevent backup loop when restoring settings from Shopify push
        // This prevents: Shopify push -> WP restore -> WP backup -> overwrite Shopify
        global $washRestoreInProgress;
        $washRestoreInProgress = true;

        $optionMap = [
            'syncOnPublish'    => 'wash_sync_on_publish',
            'syncMetafields'   => 'wash_sync_metafields',
            'postTypes'        => 'wash_post_types',
            'blockRobots'      => 'wash_block_robots',
            'hreflangsEnabled' => 'wash_hreflangs_enabled',
            'authorsEnabled'   => 'wash_authors_enabled',
            'authorMappings'   => 'wash_author_mappings',
            'filesEnabled'     => 'wash_files_enabled',
            'webpConvert'      => 'wash_webp_convert',
            'allowedFileTypes' => 'wash_allowed_file_types',
            'blocksEnabled'    => 'wash_blocks_enabled',
            'syncBlocksMeta'   => 'wash_sync_blocks_meta',
            'syncBlocksCss'    => 'wash_sync_blocks_css',
            'redirectionsEnabled' => 'wash_redirections_enabled',
        ];

        // The push is HMAC-authenticated, but never trust the payload blindly:
        // sanitize every value before it lands in an option (MED-13, defence in depth).
        // Per-store (#252): write through WashContext so a restore lands on the
        // connection it is for — except wash_block_robots, which stays a single
        // site-wide option (#216), so it is written raw.
        foreach ($optionMap as $key => $option) {
            if (isset($settings[$key])) {
                $clean = $this->sanitizeSettingValue($settings[$key]);
                if ($option === 'wash_block_robots') {
                    update_option($option, $clean);
                } else {
                    WashContext::updateOption($option, $clean);
                }
            }
        }

        // Per-field metafield config (JSON array)
        if (isset($settings['metaFieldConfig'])) {
            $value = $this->sanitizeSettingValue($settings['metaFieldConfig']);
            if (is_array($value)) {
                $value = wp_json_encode($value);
            }
            WashContext::updateOption('wash_meta_field_config', $value);
            WashMetafields::$fieldConfigCache = null;
        }

        $washRestoreInProgress = false;

        return true;
    }

    /**
     * Sanitize a pushed setting value before it is written to an option.
     * Booleans become the plugin's '1'/'0' convention; arrays are sanitized
     * recursively; everything else is treated as text (MED-13).
     *
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeSettingValue($value) {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeSettingValue'], $value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return sanitize_text_field((string) $value);
    }
}
