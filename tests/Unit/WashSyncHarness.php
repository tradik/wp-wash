<?php
/**
 * Shared harness for the WashSync pipeline suites (buildPostData, syncPost,
 * doSync, SEO/language, files). Stubs the WordPress API with in-memory state,
 * loads the collaborator stand-ins (WashSyncCollaboratorStubs.php) and binds a
 * fake API client to the primary connection so no HTTP is attempted.
 *
 * Every class using it must run in a separate process: the stubs declare global
 * class names that the real plugin classes also use.
 */

namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

trait WashSyncHarness
{
    /** @var \WashSync */
    private $sync;
    /** @var array<string,mixed> option name => value */
    private array $options = [];
    /** @var array<string,mixed> post-meta key => value (single) */
    private array $meta = [];
    /** @var array<string,array> full meta map returned by get_post_meta($id) */
    private array $allMeta = [];
    /** @var array<string,mixed> */
    private array $updated = [];
    /** @var string[] */
    private array $deleted = [];
    /** @var int */
    private $thumbnailId = 0;
    /** @var object Fake WashApiClient. */
    private $client;

    private function bootHarness(): void
    {
        // Code under test before Patchwork (Monkey\setUp), so Xdebug line coverage stays exact.
        require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
        require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';
        require_once __DIR__ . '/WashSyncCollaboratorStubs.php';
        Monkey\setUp();
        $this->resetStubs();

        $this->options = [
            'wash_shopify_store' => 'test-store',
            'wash_api_key'       => 'test-key',
            'wash_app_url'       => 'https://app-wash.tradik.dev',
        ];
        $this->meta = $this->allMeta = $this->updated = $this->deleted = [];
        $this->thumbnailId = 0;

        Functions\when('get_option')->alias(fn($key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $key === '' ? $this->allMeta : ($this->meta[$key] ?? ''));
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->updated[$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            $this->deleted[] = $key;
            return true;
        });
        Functions\when('get_post_thumbnail_id')->alias(fn() => $this->thumbnailId);
        Functions\when('get_transient')->alias(fn($key) => str_starts_with($key, 'wash_sync_sent_') ? false : []);
        Functions\stubs([
            'get_site_url'                => 'https://example.com',
            'error_log'                   => true,
            'is_multisite'                => false,
            'set_transient'               => true,
            'current_time'                => '2026-11-03 10:00:00',
            'get_the_tags'                => [],
            'get_the_category'            => [],
            'get_the_author_meta'         => 'Ann Author',
            'get_bloginfo'                => 'My Blog',
            'get_the_date'                => '2026-11-03T10:00:00+00:00',
            'get_permalink'               => 'https://example.com/hello/',
            'get_the_title'               => 'Hello',
            'get_the_excerpt'             => '',
            'wp_get_attachment_url'       => 'https://example.com/wp-content/uploads/thumb.jpg',
            'attachment_url_to_postid'    => 0,
            'url_to_postid'               => 0,
            'wp_is_post_revision'         => false,
            'wp_is_post_autosave'         => false,
        ]);
        Functions\when('get_post')->justReturn(null);
        Functions\when('home_url')->alias(fn($path = '') => 'https://example.com' . $path);
        Functions\when('is_wp_error')->alias(fn($thing) => $thing instanceof \WP_Error);
        Functions\when('wp_json_encode')->alias(fn($value) => json_encode($value));
        Functions\when('esc_attr')->returnArg();
        Functions\when('wp_strip_all_tags')->alias(fn($text) => strip_tags((string) $text));

        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public array $inserted = [];
            public function show_errors($show = true) { return false; }
            public function suppress_errors($suppress = true) { return false; }
            public function insert($table, $data) { $this->inserted[] = $data; return 1; }
            public function get_var($query) { return 'wp_wash_sync'; }
            public function prepare($query, ...$args) { return $query; }
        };

        $this->sync = new \WashSync();
        $this->client = $this->injectClient();
    }

    private function shutdownHarness(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
    }

    private function resetStubs(): void
    {
        \WashPageBuilders::$detected = null;
        \WashPageBuilders::$rendered = ['html' => '', 'css' => ''];
        \WashHreflangs::$group = null;
        \WashHreflangs::$syncResult = ['count' => 0];
        \WashMetafields::$syncResult = ['count' => 0];
        \WashMetafields::$rawAcf = [];
        \WashMetafields::$enabled = [];
        \WashImages::$cdnUrls = [];
        \WashImages::$syncOk = true;
        \WashImages::$synced = [];
        \WashBlocks::$metafields = [];
    }

    /**
     * Bind a fake API client to the primary connection (WashContext memo).
     *
     * @return object The fake; set ->result / ->features to steer it.
     */
    private function injectClient(): object
    {
        $fake = new class {
            public $result = [];
            public $features = true;
            public array $posts = [];
            public function post($endpoint, array $data) { $this->posts[] = [$endpoint, $data]; return $this->result; }
            public function get($endpoint) { return []; }
            public function hasFeature($name) { return $this->features; }
            public function isConfigured() { return true; }
        };
        \WashContext::setActiveId('primary');
        $ref = new \ReflectionProperty(\WashContext::class, 'clients');
        $ref->setValue(null, ['primary' => $fake]);
        return $fake;
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        return $ref->invoke($this->sync, ...$args);
    }

    private function makePost(array $overrides = []): \stdClass
    {
        return (object) array_merge([
            'ID'           => 7,
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => 'Hello',
            'post_content' => '<p>Body</p>',
            'post_excerpt' => 'Short &amp; sweet',
            'post_name'    => 'hello',
            'post_author'  => 3,
        ], $overrides);
    }
}
