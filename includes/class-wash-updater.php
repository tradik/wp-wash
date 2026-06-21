<?php
/**
 * WordPress plugin auto-updater.
 *
 * Teaches WP core to find Wash updates through the connected Shopify app instead
 * of wordpress.org: it queries the app's `/api/plugin-version` (authenticated
 * with the connection API key) and, when a newer release exists, injects it into
 * the update transient. WP core then downloads the package — but its downloader
 * cannot send headers, so the package URL the app returns carries a signed
 * `?token=` query param instead (LOW-6, #167).
 *
 * @package Wash
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashUpdater {
    /**
     * Cache the version lookup for the duration of a request so the transient
     * filter and the info modal don't hit the app twice.
     *
     * @var array|null|false null = not fetched, false = fetch failed
     */
    private $cachedPlugin = null;

    /**
     * Register the update hooks.
     */
    public function register() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
    }

    /**
     * The plugin folder slug (e.g. "wash"), used by WP to key the update.
     *
     * @return string
     */
    private function slug() {
        return dirname(WASH_PLUGIN_BASENAME);
    }

    /**
     * Fetch the latest-release info from the Shopify app, or null when it can't
     * be reached / the site isn't connected. Cached per request.
     *
     * @return array|null
     */
    private function fetchLatest() {
        if ($this->cachedPlugin !== null) {
            return $this->cachedPlugin ?: null;
        }
        $this->cachedPlugin = false;

        $appUrl = rtrim((string) get_option('wash_app_url', ''), '/');
        $apiKey = (string) get_option('wash_api_key', '');
        if ($appUrl !== '' && $apiKey !== '') {
            $response = wp_remote_get($appUrl . '/api/plugin-version', [
                'timeout' => 10,
                'headers' => [
                    'X-Wash-Key'            => $apiKey,
                    'X-Wash-Plugin-Version' => WASH_VERSION,
                ],
            ]);

            $ok = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200;
            $body = $ok ? json_decode(wp_remote_retrieve_body($response), true) : null;
            if (is_array($body) && !empty($body['success']) && !empty($body['plugin'])) {
                $this->cachedPlugin = $body['plugin'];
            }
        }

        return $this->cachedPlugin ?: null;
    }

    /**
     * Inject an available update into the update-plugins transient.
     *
     * @param object $transient
     * @return object
     */
    public function checkForUpdate($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        // wp.org is the primary update channel: if it already offered an update for
        // this plugin, defer to it. The app updater is only the fallback for sites
        // installed directly from the Shopify app (#167).
        $alreadyOffered = !empty($transient->response[WASH_PLUGIN_BASENAME]);
        $plugin = $alreadyOffered ? null : $this->fetchLatest();

        if ($plugin && !empty($plugin['updateAvailable']) && !empty($plugin['downloadUrl'])) {
            $transient->response[WASH_PLUGIN_BASENAME] = (object) [
                'slug'        => $this->slug(),
                'plugin'      => WASH_PLUGIN_BASENAME,
                'new_version' => $plugin['latestVersion'],
                'package'     => $plugin['downloadUrl'],
                'url'         => 'https://wash.tradik.com',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View details" modal (plugins_api).
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public function pluginInfo($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug()) {
            return $result;
        }

        $plugin = $this->fetchLatest();
        if (!$plugin) {
            return $result;
        }

        return (object) [
            'name'          => 'Wash: Blog Manager',
            'slug'          => $this->slug(),
            'version'       => $plugin['latestVersion'],
            'download_link' => $plugin['downloadUrl'] ?? '',
            'sections'      => [
                'changelog' => $plugin['releaseNotes'] ?? '',
            ],
        ];
    }
}
