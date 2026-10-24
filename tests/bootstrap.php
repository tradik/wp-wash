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

// Multi-store foundation (#216): WashApiClient (and the classes that use it)
// reference WashConnection / WashContext, so — exactly like wash.php loads them
// before WashApiClient — make them available process-wide for the whole suite.
// Loaded AFTER ABSPATH because both files guard on `defined('ABSPATH')`.
require_once dirname(__DIR__) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__) . '/includes/class-wash-context.php';
// WashBlogRouter (#255) is called by WashAdmin/WashHreflangs to build storefront
// URLs and gate publishing; load it process-wide like the other shared classes so
// any test exercising those paths can resolve it.
require_once dirname(__DIR__) . '/includes/class-wash-blog-router.php';
if (!defined('WASH_VERSION')) {
    define('WASH_VERSION', '1.20.8');
}
if (!defined('WASH_PLUGIN_DIR')) {
    define('WASH_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('WASH_PLUGIN_BASENAME')) {
    define('WASH_PLUGIN_BASENAME', 'wash/wash.php');
}

// Debug logging helper (mirrors wash.php definition)
if (!function_exists('washLog')) {
    function washLog($message) {
        if (defined('WASH_DEBUG') && WASH_DEBUG) {
            error_log($message);
        }
    }
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
// wp_parse_url() is a thin wrapper over native parse_url(); the API client calls
// it during construction (resolveAppUrl), before per-test Brain\Monkey stubs run.
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}
// esc_sql() escapes a value for use in a raw SQL string; getContentStats() uses it
// on the per-connection meta key. Pure function (no test mocks it), so define it here
// rather than per-test — mirrors the real WP behaviour (addslashes-style escaping).
if (!function_exists('esc_sql')) {
    function esc_sql($data) {
        return is_array($data) ? array_map('addslashes', $data) : addslashes((string) $data);
    }
}
// Canonical WP_Block_Type_Registry stub shared by all tests. Defining it once
// here (with a complete is_registered()/register()) prevents per-test `eval`
// stubs from racing — the first-loaded definition wins process-wide, and an
// incomplete one would break every test that calls is_registered().
if (!class_exists('WP_Block_Type_Registry')) {
    class WP_Block_Type_Registry
    {
        private static $instance = null;
        private array $registered = [];

        public static function get_instance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function is_registered($name): bool
        {
            return isset($this->registered[$name]);
        }

        public function register($name, $args = [])
        {
            $this->registered[$name] = $args;
            return $args;
        }

        public function get_all_registered(): array
        {
            return $this->registered;
        }
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;
        private $data;

        public function __construct(string $code = '', string $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_data()
        {
            return $this->data;
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
// Note: sanitize_text_field() and wp_unslash() are stubbed per-test by
// Brain\Monkey via the base TestCase setUp(); they cannot be defined here
// because Patchwork must see them as redefinable userland functions.
