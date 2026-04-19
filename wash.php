<?php
/**
 * Plugin Name:       Wash: Blog Manager
 * Plugin URI:        https://github.com/tradik/wash
 * Description:       Synchronize WordPress content, images, and metafields to Shopify blogs.
 * Version:           1.19.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Tradik
 * Author URI:        https://github.com/tradik
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wash
 * Domain Path:       /languages
 * Network:           true
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WASH_VERSION', '1.19.1');
define('WASH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WASH_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Get plugin URL with proper domain filtering.
 */
function washPluginUrl() {
    return plugins_url('', __FILE__) . '/';
}

require_once WASH_PLUGIN_DIR . 'includes/class-wash-activator.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-api-client.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-admin.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-sync.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-images.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-metafields.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-hreflangs.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-multisite.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-files-media.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-callback.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-blocks.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-page-builders.php';
require_once WASH_PLUGIN_DIR . 'includes/class-wash-updater.php';

register_activation_hook(__FILE__, ['WashActivator', 'activate']);
register_deactivation_hook(__FILE__, ['WashActivator', 'deactivate']);
WashActivator::init();

/**
 * Initialize the plugin.
 * Loads admin UI, content sync hooks, image sync, and multisite support.
 */
function washInit() {
    $admin = new WashAdmin();
    $admin->init();

    $sync = new WashSync();
    $sync->init();

    $images = new WashImages();
    $images->init();

    $metafields = new WashMetafields();
    $metafields->init();

    $hreflangs = new WashHreflangs();
    $hreflangs->init();

    $filesMedia = new WashFilesMedia();
    $filesMedia->init();

    $blocks = new WashBlocks();
    $blocks->init();

    $callback = new WashCallback();
    $callback->register();

    if (is_multisite()) {
        $multisite = new WashMultisite();
        $multisite->init();
    }

    // Initialize plugin updater
    $updater = new WashUpdater();
    $updater->init();
}

add_action('plugins_loaded', 'washInit');

/**
 * Block all search engine crawlers via robots.txt.
 * Enabled by default, configurable in plugin settings.
 *
 * @param string $output The robots.txt output.
 * @return string Modified robots.txt content.
 */
function washRobotsTxt($output) {
    if (get_option('wash_block_robots', '1') !== '1') {
        return $output;
    }
    return "User-agent: *\nDisallow: /\n";
}

add_filter('robots_txt', 'washRobotsTxt', 999);
