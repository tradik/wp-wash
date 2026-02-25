<?php
/**
 * Plugin updater class.
 * Checks for new plugin versions via Shopify App proxy.
 * Downloads updates through the secure proxy endpoint.
 */
class WashUpdater {

    /** @var string Current plugin version */
    private $currentVersion;

    /** @var string Plugin basename (e.g., "wash/wash.php") */
    private $pluginBasename;

    /** @var string Plugin slug */
    private $pluginSlug = 'wash';

    /** @var string Cache key for version info */
    private $cacheKey = 'wash_version_info';

    /** @var int Cache TTL in seconds (1 hour) */
    private $cacheTtl = 3600;

    /** @var WashApiClient API client instance */
    private $apiClient;

    public function __construct() {
        $this->currentVersion = WASH_VERSION;
        $this->pluginBasename = WASH_PLUGIN_BASENAME;
    }

    /**
     * Initialize updater hooks.
     */
    public function init() {
        // Hook into WordPress plugin update check
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);

        // Provide plugin info for the update details modal
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);

        // Add authentication headers for all requests to our API
        add_filter('http_request_args', [$this, 'addAuthHeaders'], 10, 2);

        // Add update notice in plugin list
        add_action('in_plugin_update_message-' . $this->pluginBasename, [$this, 'updateMessage'], 10, 2);

        // Admin notice for critical updates
        add_action('admin_notices', [$this, 'criticalUpdateNotice']);

        // Clear cache on plugin activation
        add_action('activated_plugin', [$this, 'clearCache']);
    }

    /**
     * Check for plugin updates.
     *
     * @param object $transient Update transient data.
     * @return object Modified transient.
     */
    public function checkForUpdate($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $versionInfo = $this->getVersionInfo();

        if (is_wp_error($versionInfo) || empty($versionInfo['plugin']['latestVersion'])) {
            return $transient;
        }

        $latestVersion = $versionInfo['plugin']['latestVersion'];

        // Compare versions
        if (version_compare($this->currentVersion, $latestVersion, '<')) {
            $transient->response[$this->pluginBasename] = (object) [
                'slug'        => $this->pluginSlug,
                'plugin'      => $this->pluginBasename,
                'new_version' => $latestVersion,
                'url'         => $versionInfo['plugin']['changelog'] ?? '',
                'package'     => $this->getProxyDownloadUrl(),
                'icons'       => [
                    '1x' => washPluginUrl() . 'admin/img/icon-128.png',
                    '2x' => washPluginUrl() . 'admin/img/icon-256.png',
                ],
                'banners'     => [
                    'low'  => washPluginUrl() . 'admin/img/banner-772x250.png',
                    'high' => washPluginUrl() . 'admin/img/banner-1544x500.png',
                ],
                'tested'      => get_bloginfo('version'),
                'requires'    => '6.0',
                'requires_php' => '8.0',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin info for the WordPress update details modal.
     *
     * @param false|object|array $result Default result.
     * @param string             $action API action.
     * @param object             $args   API arguments.
     * @return false|object Plugin info or false.
     */
    public function pluginInfo($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->pluginSlug) {
            return $result;
        }

        $versionInfo = $this->getVersionInfo();

        if (is_wp_error($versionInfo) || empty($versionInfo['plugin'])) {
            return $result;
        }

        $plugin = $versionInfo['plugin'];

        return (object) [
            'name'              => 'Wash: Blog Manager',
            'slug'              => $this->pluginSlug,
            'version'           => $plugin['latestVersion'] ?? $this->currentVersion,
            'author'            => '<a href="https://github.com/tradik">Tradik</a>',
            'author_profile'    => 'https://github.com/tradik',
            'requires'          => '6.0',
            'tested'            => get_bloginfo('version'),
            'requires_php'      => '8.0',
            'sections'          => [
                'description' => __('Synchronize WordPress content, images, and metafields to Shopify blogs.', 'wash'),
                'changelog'   => $this->formatChangelog($plugin['releaseNotes'] ?? ''),
            ],
            'download_link'     => $this->getProxyDownloadUrl(),
            'trunk'             => $this->getProxyDownloadUrl(),
            'last_updated'      => $plugin['releaseDate'] ?? '',
            'icons'             => [
                '1x' => washPluginUrl() . 'admin/img/icon-128.png',
                '2x' => washPluginUrl() . 'admin/img/icon-256.png',
            ],
            'banners'           => [
                'low'  => washPluginUrl() . 'admin/img/banner-772x250.png',
                'high' => washPluginUrl() . 'admin/img/banner-1544x500.png',
            ],
        ];
    }

    /**
     * Add authentication headers for all requests to our API.
     *
     * @param array  $args HTTP request arguments.
     * @param string $url  Request URL.
     * @return array Modified arguments.
     */
    public function addAuthHeaders($args, $url) {
        // Check if this is our API URL
        $appUrl = $this->getApiClient()->getAppUrl();
        if (strpos($url, $appUrl) === false) {
            return $args;
        }

        // Add authentication headers
        $apiKey = get_option('wash_api_key', '');
        if (!empty($apiKey)) {
            if (!isset($args['headers'])) {
                $args['headers'] = [];
            }
            $args['headers']['X-Wash-Key'] = $apiKey;
            $args['headers']['X-Wash-Plugin-Version'] = $this->currentVersion;
        }

        return $args;
    }

    /**
     * Display update message in plugin list.
     *
     * @param array $pluginData Plugin data.
     * @param object $response Response object.
     */
    public function updateMessage($pluginData, $response) {
        $versionInfo = $this->getVersionInfo();

        if (is_wp_error($versionInfo)) {
            return;
        }

        $plugin = $versionInfo['plugin'] ?? [];

        // Show release notes excerpt if available
        if (!empty($plugin['releaseNotes'])) {
            $notes = wp_trim_words(strip_tags($plugin['releaseNotes']), 30);
            echo '<br/><span style="margin-left: 26px; display: inline-block;">';
            echo esc_html($notes);
            echo '</span>';
        }

        // Warning for minimum required version
        if (!empty($plugin['belowMinimum'])) {
            echo '<br/><strong style="color: #d63638; margin-left: 26px;">';
            echo esc_html__('This is a critical update. Your current version may not function correctly.', 'wash');
            echo '</strong>';
        }
    }

    /**
     * Show admin notice for critical updates.
     */
    public function criticalUpdateNotice() {
        // Only show on wash settings page
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'wash') === false) {
            return;
        }

        $versionInfo = $this->getVersionInfo();

        if (is_wp_error($versionInfo)) {
            return;
        }

        $plugin = $versionInfo['plugin'] ?? [];

        // Check if below minimum required version
        if (!empty($plugin['belowMinimum'])) {
            $latestVersion = $plugin['latestVersion'] ?? 'latest';
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Wash Plugin Critical Update Required', 'wash'); ?></strong>
                </p>
                <p>
                    <?php
                    printf(
                        /* translators: %1$s: current version, %2$s: latest version */
                        esc_html__('Your Wash plugin version %1$s is below the minimum required. Please update to version %2$s or later.', 'wash'),
                        esc_html($this->currentVersion),
                        esc_html($latestVersion)
                    );
                    ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button button-primary">
                        <?php esc_html_e('Update Now', 'wash'); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        // Show update available notice (non-critical)
        if (!empty($plugin['updateAvailable']) && empty($plugin['belowMinimum'])) {
            $latestVersion = $plugin['latestVersion'] ?? '';
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: %s: latest version */
                        esc_html__('A new version of Wash (%s) is available.', 'wash'),
                        esc_html($latestVersion)
                    );
                    ?>
                    <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                        <?php esc_html_e('View updates', 'wash'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Get version info from API (with caching).
     *
     * @param bool $forceRefresh Force refresh ignoring cache.
     * @return array|WP_Error Version info or error.
     */
    public function getVersionInfo($forceRefresh = false) {
        $cached = get_transient($this->cacheKey);

        if ($cached !== false && !$forceRefresh) {
            return $cached;
        }

        $client = $this->getApiClient();
        $appUrl = $client->getAppUrl();
        $apiKey = get_option('wash_api_key', '');

        $headers = [
            'Accept'                  => 'application/json',
            'X-Wash-Plugin-Version'   => $this->currentVersion,
        ];

        if (!empty($apiKey)) {
            $headers['X-Wash-Key'] = $apiKey;
        }

        // Add HTTP Basic Auth if configured
        $httpUser = get_option('wash_http_user', '');
        $httpPass = get_option('wash_http_pass', '');
        if (!empty($httpUser) && !empty($httpPass)) {
            $headers['Authorization'] = 'Basic ' . base64_encode($httpUser . ':' . $httpPass);
        }

        $response = wp_remote_get($appUrl . '/api/plugin-version', [
            'timeout' => 15,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data['success'])) {
            return new \WP_Error('wash_version_check_failed', __('Version check failed', 'wash'));
        }

        // Cache the result
        set_transient($this->cacheKey, $data, $this->cacheTtl);

        return $data;
    }

    /**
     * Get proxy download URL.
     *
     * @return string Download URL.
     */
    private function getProxyDownloadUrl() {
        $client = $this->getApiClient();
        return $client->getAppUrl() . '/api/plugin-download';
    }

    /**
     * Get API client instance.
     *
     * @return WashApiClient
     */
    private function getApiClient() {
        if (!$this->apiClient) {
            $this->apiClient = new WashApiClient();
        }
        return $this->apiClient;
    }

    /**
     * Format changelog markdown to HTML.
     *
     * @param string $markdown Changelog in markdown.
     * @return string HTML formatted changelog.
     */
    private function formatChangelog($markdown) {
        if (empty($markdown)) {
            return '<p>' . esc_html__('No changelog available.', 'wash') . '</p>';
        }

        // Simple markdown to HTML conversion
        $html = esc_html($markdown);
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^\* (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)+/s', '<ul>$0</ul>', $html);
        $html = nl2br($html);

        return $html;
    }

    /**
     * Clear version cache.
     */
    public function clearCache() {
        delete_transient($this->cacheKey);
    }
}
