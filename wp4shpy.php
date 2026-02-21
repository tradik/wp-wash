<?php
/**
 * Plugin Name:       wp4shpy
 * Plugin URI:        https://github.com/tradik/wp4shpy
 * Description:       Synchronize WordPress content, images, and metafields to Shopify blogs.
 * Version:           1.6.3
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Tradik
 * Author URI:        https://github.com/tradik
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp4shpy
 * Domain Path:       /languages
 * Network:           true
 */

if (!defined('ABSPATH')) {
    exit;
}

define('wp4shpy_VERSION', '1.6.3');
define('wp4shpy_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('wp4shpy_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Get plugin URL with proper domain filtering.
 */
function wp4shpy_plugin_url() {
    return plugins_url('', __FILE__) . '/';
}

require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-activator.php';
require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-api-client.php';
require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-admin.php';
require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-sync.php';
require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-images.php';
require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-metafields.php';
require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-multisite.php';
require_once wp4shpy_PLUGIN_DIR . 'includes/class-wp4shpy-files-media.php';

register_activation_hook(__FILE__, ['wp4shpyActivator', 'activate']);
register_deactivation_hook(__FILE__, ['wp4shpyActivator', 'deactivate']);

/**
 * Initialize the plugin.
 * Loads admin UI, content sync hooks, image sync, and multisite support.
 */
function wp4shpyInit() {
    $admin = new wp4shpyAdmin();
    $admin->init();

    $sync = new wp4shpySync();
    $sync->init();

    $images = new wp4shpyImages();
    $images->init();

    $metafields = new wp4shpyMetafields();
    $metafields->init();

    $filesMedia = new wp4shpyFilesMedia();
    $filesMedia->init();

    if (is_multisite()) {
        $multisite = new wp4shpyMultisite();
        $multisite->init();
    }
}

add_action('plugins_loaded', 'wp4shpyInit');

/**
 * Block all search engine crawlers via robots.txt.
 * Enabled by default, configurable in plugin settings.
 *
 * @param string $output The robots.txt output.
 * @return string Modified robots.txt content.
 */
function wp4shpyRobotsTxt($output) {
    if (get_option('wp4shpy_block_robots', '1') !== '1') {
        return $output;
    }
    return "User-agent: *\nDisallow: /\n";
}

add_filter('robots_txt', 'wp4shpyRobotsTxt', 999);
