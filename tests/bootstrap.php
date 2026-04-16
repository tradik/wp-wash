<?php
/**
 * PHPUnit bootstrap for Wash plugin tests.
 * Uses Brain\Monkey to mock WordPress functions without loading WordPress.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants needed by plugin code
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/fake-wp/');
}
if (!defined('WASH_VERSION')) {
    define('WASH_VERSION', '1.17.1');
}
if (!defined('WASH_PLUGIN_DIR')) {
    define('WASH_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('WASH_PLUGIN_BASENAME')) {
    define('WASH_PLUGIN_BASENAME', 'wash/wash.php');
}

// Stub WordPress REST API classes used by WashCallback tests
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $params;

        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        public function get_json_params(): array
        {
            return $this->params;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

// Stub WordPress functions that are used at file include time
// Brain\Monkey handles most, but some need to exist before class files are loaded
if (!function_exists('is_plugin_active')) {
    function is_plugin_active($plugin) {
        return false;
    }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return dirname($file) . '/';
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return basename(dirname($file)) . '/' . basename($file);
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;

        public function __construct(string $code = '', string $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

// Note: is_multisite() and get_current_blog_id() are NOT stubbed here.
// Brain\Monkey (Patchwork) needs to define them itself for unit tests.
// For integration tests (ContentSyncContractTest), they are stubbed
// in the integration bootstrap which loads before Patchwork.
