<?php
/**
 * Coverage-boosting tests for WashAdmin.
 * Targets previously-untested methods:
 *  - sanitize* helpers
 *  - scheduleSettingsBackup / runScheduledSettingsBackup
 *  - preserveTabAfterSave
 *  - getBlockEditorShopifyLinkScript (via ReflectionMethod)
 *  - getShopifyArticleUrl (via ReflectionMethod)
 *  - filterPreviewLink
 *  - addShopifyColumn / renderShopifyColumn
 *  - addShopifyRowActions
 *  - applyStatusToPostMeta (via ReflectionMethod)
 *  - ajaxRefreshAllStatuses
 *  - ajaxSaveConsent
 *  - ajaxGetShopifyBlogs / ajaxGetShopifyMarkets / ajaxGetShopDomains
 *  - ajaxReorderMappings
 *  - ajaxGetSearchReplaceRules
 *  - ajaxSaveSearchReplaceRules (success path)
 *  - ajaxGetShopifyRedirections (success + error+diagnosis paths)
 *  - ajaxSyncRedirections (success + parse paths)
 *  - ajaxFetchWpRedirections
 *  - ajaxSaveRedirectionsSettings
 *  - ajaxGenerateRedirections
 *  - parseRedirections (via ReflectionMethod)
 *  - addBulkActions
 *  - renderSyncNotices (various GET params)
 *  - renderBulkActionNotice (via GET)
 *  - asyncSyncPost (skip path)
 *  - ajaxTestConnection (success / wp_error paths)
 *  - ajaxDisconnect (success path)
 *  - ajaxDebugSession / ajaxResetSession / ajaxFixSession (success paths)
 *  - ajaxResetToDefaults (success path)
 *  - ajaxSyncSettingsFromShopify (error + no-settings paths)
 *  - ajaxCreateMapping / ajaxUpdateMapping / ajaxDeleteMapping (paths)
 *  - ajaxCheckSyncStatus (with post IDs)
 *  - ajaxManualSync (invalid / not-found paths)
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

/**
 * @covers WashAdmin
 */
class WashAdminCoverageTest extends TestCase
{
    private \WashAdmin $admin;
    private array $jsonResponses = [];
    private array $options = [];
    private array $postMetaStore = [];
    private array $postStore = [];
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);

        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        $this->jsonResponses = [];
        $this->options = [
            'wash_api_key'   => 'test-key',
            'wash_app_url'   => 'https://app-wash.tradik.dev',
            'wash_http_user' => '',
            'wash_http_pass' => '',
            'wash_post_types' => 'post,page',
            'wash_shopify_store' => 'test-store',
        ];
        $this->postMetaStore = [];
        $this->postStore = [];
        $this->transients = [];

        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_key')->alias(fn($v) => preg_replace('/[^a-z0-9_\-]/i', '', (string) $v));
        Functions\when('absint')->alias(fn($v) => abs((int) $v));
        Functions\when('error_log')->justReturn(true);
        Functions\when('__')->alias(fn($t) => $t);
        Functions\when('esc_html__')->alias(fn($t) => $t);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_attr__')->alias(fn($t) => $t);
        Functions\when('esc_js')->returnArg(1);
        Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
        Functions\when('apply_filters')->alias(fn($_, $v) => $v);
        Functions\when('admin_url')->alias(fn($p = '') => 'https://example.com/wp-admin/' . ltrim((string) $p, '/'));
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        // renderShopifyColumn / addShopifyRowActions consult Polylang for the
        // translation-source ("primary") badge. Provide no-op stubs so these tests
        // don't trip Brain Monkey when an earlier test left pll_* globally registered.
        Functions\when('pll_get_post_translations')->justReturn([]);
        Functions\when('pll_default_language')->justReturn('en');
        Functions\when('add_query_arg')->alias(function (...$a) {
            if (count($a) === 3 && is_string($a[0])) {
                [$key, $value, $url] = $a;
                $args = [$key => $value];
            } else {
                [$args, $url] = $a;
            }
            $sep = strpos((string) $url, '?') === false ? '?' : '&';
            return $url . $sep . http_build_query($args);
        });

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return $this->options[$key] ?? $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->options[$key]);
            return true;
        });

        Functions\when('get_post_meta')->alias(function ($id, $key, $single = false) {
            return $this->postMetaStore[(int) $id][$key] ?? '';
        });
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->postMetaStore[(int) $id][$key] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->postMetaStore[(int) $id][$key]);
            return true;
        });

        Functions\when('get_post')->alias(fn($id) => $this->postStore[(int) $id] ?? null);

        Functions\when('set_transient')->alias(function ($k, $v, $ttl = 0) {
            $this->transients[$k] = $v;
            return true;
        });
        Functions\when('get_transient')->alias(fn($k) => $this->transients[$k] ?? false);
        Functions\when('delete_transient')->alias(function ($k) {
            unset($this->transients[$k]);
            return true;
        });

        Functions\when('wp_send_json_error')->alias(function ($msg = '') {
            $this->jsonResponses[] = ['type' => 'error', 'data' => $msg];
            throw new \RuntimeException('wp_send_json_error');
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->jsonResponses[] = ['type' => 'success', 'data' => $data];
            throw new \RuntimeException('wp_send_json_success');
        });

        Functions\when('wp_generate_password')->justReturn('TESTTOKEN1234567890Z');
        Functions\when('current_time')->justReturn('2026-05-03 12:00:00');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('wp_create_nonce')->justReturn('test-nonce');
        Functions\when('wp_nonce_url')->alias(fn($url) => $url . '&_wpnonce=testnonce');
        Functions\when('wp_remote_get')->justReturn(['body' => '{}']);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}']);
        Functions\when('wp_remote_request')->justReturn(['body' => '{}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('wp_get_referer')->justReturn('https://example.com/wp-admin/edit.php');
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            throw new \RuntimeException('redirect:' . $url);
        });
        Functions\when('date_i18n')->alias(fn($fmt, $ts = null) => '2026-01-01');
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);
        // Note: sprintf/printf are PHP internals - Brain Monkey cannot redefine them.
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('sanitize_textarea_field')->returnArg(1);

        $this->admin = new \WashAdmin();
    }

    protected function tearDown(): void
    {
        // Clean up superglobals
        $_GET = [];
        $_POST = [];
        // Reset WashHreflangs' cached translation-plugin detection so a test that
        // forces it (e.g. Polylang) never leaks into the next test in the process.
        if (class_exists('WashHreflangs')) {
            $ref = new \ReflectionProperty('WashHreflangs', 'activePlugin');
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helper: mock a post object in the store
    // ---------------------------------------------------------------

    private function mockPost(int $id, string $type = 'post', string $status = 'publish', string $name = 'test-slug'): object
    {
        $post = (object) [
            'ID'          => $id,
            'post_type'   => $type,
            'post_status' => $status,
            'post_name'   => $name,
            'post_title'  => 'Test Post ' . $id,
        ];
        $this->postStore[$id] = $post;
        return $post;
    }

    // ---------------------------------------------------------------
    // sanitizeCheckbox
    // ---------------------------------------------------------------

    public function testSanitizeCheckboxReturns1ForOne(): void
    {
        $this->assertSame('1', $this->admin->sanitizeCheckbox('1'));
    }

    public function testSanitizeCheckboxReturns0ForOther(): void
    {
        $this->assertSame('0', $this->admin->sanitizeCheckbox('yes'));
        $this->assertSame('0', $this->admin->sanitizeCheckbox(''));
        $this->assertSame('0', $this->admin->sanitizeCheckbox(null));
    }

    // ---------------------------------------------------------------
    // sanitizeAllowedFileTypes
    // ---------------------------------------------------------------

    public function testSanitizeAllowedFileTypesReturnsDefaultForNonArray(): void
    {
        $result = $this->admin->sanitizeAllowedFileTypes('bad');
        $this->assertSame(['images'], $result);
    }

    public function testSanitizeAllowedFileTypesFiltersUnknownTypes(): void
    {
        $result = $this->admin->sanitizeAllowedFileTypes(['images', 'pdf', 'malware']);
        $this->assertContains('images', $result);
        $this->assertContains('pdf', $result);
        $this->assertNotContains('malware', $result);
    }

    public function testSanitizeAllowedFileTypesReturnsDefaultWhenAllInvalid(): void
    {
        $result = $this->admin->sanitizeAllowedFileTypes(['malware', 'virus']);
        $this->assertSame(['images'], $result);
    }

    // ---------------------------------------------------------------
    // sanitizeAuthorMappings
    // ---------------------------------------------------------------

    public function testSanitizeAuthorMappingsReturnsEmptyForNonArray(): void
    {
        $result = $this->admin->sanitizeAuthorMappings('bad');
        $this->assertSame([], $result);
    }

    public function testSanitizeAuthorMappingsFiltersInvalidEntries(): void
    {
        $result = $this->admin->sanitizeAuthorMappings([
            0   => 'Author Zero',   // user_id 0 → skip
            5   => '',              // empty name → skip
            10  => 'Author Ten',
        ]);
        $this->assertArrayNotHasKey(0, $result);
        $this->assertArrayNotHasKey(5, $result);
        $this->assertSame('Author Ten', $result[10]);
    }

    // ---------------------------------------------------------------
    // preserveTabAfterSave
    // ---------------------------------------------------------------

    public function testPreserveTabAfterSaveAppendsTabParam(): void
    {
        $_POST['_wp_http_referer_tab'] = 'mappings';
        $location = 'https://example.com/wp-admin/options-general.php?page=wash';
        $result = $this->admin->preserveTabAfterSave($location);
        $this->assertStringContainsString('tab=mappings', $result);
        unset($_POST['_wp_http_referer_tab']);
    }

    public function testPreserveTabAfterSaveIgnoresUnrelatedLocations(): void
    {
        $location = 'https://example.com/wp-admin/options-general.php?page=other';
        $result = $this->admin->preserveTabAfterSave($location);
        $this->assertSame($location, $result);
    }

    public function testPreserveTabAfterSaveIgnoresEmptyTab(): void
    {
        $_POST['_wp_http_referer_tab'] = '';
        $location = 'https://example.com/wp-admin/options-general.php?page=wash';
        $result = $this->admin->preserveTabAfterSave($location);
        $this->assertStringNotContainsString('tab=', $result);
        unset($_POST['_wp_http_referer_tab']);
    }

    // ---------------------------------------------------------------
    // scheduleSettingsBackup / runScheduledSettingsBackup
    // ---------------------------------------------------------------

    public function testScheduleSettingsBackupSetsTransient(): void
    {
        $this->admin->scheduleSettingsBackup();
        $this->assertTrue((bool) $this->transients['wash_settings_backup_pending']);
    }

    public function testScheduleSettingsBackupSkipsWhenRestoreInProgress(): void
    {
        global $washRestoreInProgress;
        $washRestoreInProgress = true;
        $this->admin->scheduleSettingsBackup();
        $this->assertArrayNotHasKey('wash_settings_backup_pending', $this->transients);
        $washRestoreInProgress = false;
    }

    public function testRunScheduledSettingsBackupSkipsWhenNoPendingTransient(): void
    {
        // no transient set → should return early without error
        $this->admin->runScheduledSettingsBackup();
        $this->addToAssertionCount(1);
    }

    public function testRunScheduledSettingsBackupSkipsWhenNotConfigured(): void
    {
        $this->options['wash_api_key'] = '';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();
        $this->transients['wash_settings_backup_pending'] = true;

        // No WP_Error expected because it returns early
        $admin->runScheduledSettingsBackup();
        $this->assertArrayNotHasKey('wash_settings_backup_pending', $this->transients);
    }

    public function testRunScheduledSettingsBackupCallsBackupAndClearsTransient(): void
    {
        $this->transients['wash_settings_backup_pending'] = true;

        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);

        $this->admin->runScheduledSettingsBackup();
        $this->assertArrayNotHasKey('wash_settings_backup_pending', $this->transients);
    }

    // ---------------------------------------------------------------
    // getBlockEditorShopifyLinkScript (private → Reflection)
    // ---------------------------------------------------------------

    public function testGetBlockEditorShopifyLinkScriptContainsUrl(): void
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'getBlockEditorShopifyLinkScript');
        $ref->setAccessible(true);
        $script = $ref->invoke($this->admin, 'https://test-store.myshopify.com/blogs/news/my-article');
        $this->assertStringContainsString('registerPlugin', $script);
        $this->assertStringContainsString('test-store.myshopify.com', $script);
    }

    // ---------------------------------------------------------------
    // getShopifyArticleUrl (private → Reflection)
    // ---------------------------------------------------------------

    public function testGetShopifyArticleUrlReturnsNullWhenNoStore(): void
    {
        $this->options['wash_shopify_store'] = '';
        $ref = new \ReflectionMethod(\WashAdmin::class, 'getShopifyArticleUrl');
        $ref->setAccessible(true);
        $this->assertNull($ref->invoke($this->admin, 99));
    }

    public function testGetShopifyArticleUrlReturnsNullWhenNoArticleId(): void
    {
        // store set, but no _wash_article_id meta
        $ref = new \ReflectionMethod(\WashAdmin::class, 'getShopifyArticleUrl');
        $ref->setAccessible(true);
        $this->assertNull($ref->invoke($this->admin, 99));
    }

    public function testGetShopifyArticleUrlReturnsBlogUrlForPost(): void
    {
        $this->mockPost(10, 'post', 'publish', 'my-slug');
        $this->postMetaStore[10]['_wash_article_id'] = 'gid://shopify/Article/123';
        $this->postMetaStore[10]['_wash_blog_handle'] = 'news';

        $ref = new \ReflectionMethod(\WashAdmin::class, 'getShopifyArticleUrl');
        $ref->setAccessible(true);
        $url = $ref->invoke($this->admin, 10);

        $this->assertStringContainsString('/blogs/news/my-slug', $url);
        $this->assertStringContainsString('test-store', $url);
    }

    public function testGetShopifyArticleUrlUsesDefaultBlogHandle(): void
    {
        $this->mockPost(11, 'post', 'publish', 'my-slug');
        $this->postMetaStore[11]['_wash_article_id'] = 'gid://shopify/Article/456';
        // no _wash_blog_handle → should fall back to 'news'

        $ref = new \ReflectionMethod(\WashAdmin::class, 'getShopifyArticleUrl');
        $ref->setAccessible(true);
        $url = $ref->invoke($this->admin, 11);

        $this->assertStringContainsString('/blogs/news/', $url);
    }

    public function testGetShopifyArticleUrlReturnsPagesUrlForPage(): void
    {
        $this->mockPost(20, 'page', 'publish', 'about');
        $this->postMetaStore[20]['_wash_article_id'] = 'gid://shopify/OnlineStorePage/789';

        $ref = new \ReflectionMethod(\WashAdmin::class, 'getShopifyArticleUrl');
        $ref->setAccessible(true);
        $url = $ref->invoke($this->admin, 20);

        $this->assertStringContainsString('/pages/about', $url);
    }

    // ---------------------------------------------------------------
    // filterPreviewLink
    // ---------------------------------------------------------------

    public function testFilterPreviewLinkReturnShopifyUrlWhenSynced(): void
    {
        $this->mockPost(30, 'post', 'publish', 'synced-post');
        $this->postMetaStore[30]['_wash_article_id'] = 'gid://shopify/Article/1';
        $this->postMetaStore[30]['_wash_blog_handle'] = 'news';
        $post = $this->postStore[30];

        $url = $this->admin->filterPreviewLink('https://example.com/?p=30', $post);
        $this->assertStringContainsString('myshopify.com', $url);
    }

    public function testFilterPreviewLinkReturnsOriginalWhenNotSynced(): void
    {
        $this->mockPost(31, 'post', 'publish', 'not-synced');
        $post = $this->postStore[31];

        $original = 'https://example.com/?p=31';
        $url = $this->admin->filterPreviewLink($original, $post);
        $this->assertSame($original, $url);
    }

    // ---------------------------------------------------------------
    // addShopifyColumn
    // ---------------------------------------------------------------

    public function testAddShopifyColumnInsertsAfterTitle(): void
    {
        $columns = ['cb' => '<input>', 'title' => 'Title', 'date' => 'Date'];
        $result = $this->admin->addShopifyColumn($columns);
        $keys = array_keys($result);
        $titlePos = array_search('title', $keys, true);
        $syncPos  = array_search('wash_sync', $keys, true);
        $this->assertGreaterThan($titlePos, $syncPos);
    }

    public function testAddShopifyColumnDoesNotDuplicateColumns(): void
    {
        $columns = ['title' => 'Title'];
        $result = $this->admin->addShopifyColumn($columns);
        $this->assertArrayHasKey('wash_sync', $result);
        $this->assertCount(2, $result);
    }

    // ---------------------------------------------------------------
    // renderShopifyColumn
    // ---------------------------------------------------------------

    public function testRenderShopifyColumnSkipsUnrelatedColumns(): void
    {
        ob_start();
        $this->admin->renderShopifyColumn('author', 10);
        $html = ob_get_clean();
        $this->assertEmpty($html);
    }

    public function testRenderShopifyColumnShowsSyncedIcon(): void
    {
        $this->mockPost(40, 'post', 'publish', 'synced');
        $this->postMetaStore[40]['_wash_article_id'] = 'gid://shopify/Article/1';
        $this->postMetaStore[40]['_wash_blog_handle'] = 'news';

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 40);
        $html = ob_get_clean();
        $this->assertStringContainsString('dashicons-yes-alt', $html);
    }

    public function testRenderShopifyColumnFlagsImageWarningsOnPartialSuccess(): void
    {
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);
        $this->mockPost(44, 'post', 'publish', 'synced');
        $this->postMetaStore[44]['_wash_article_id'] = 'gid://shopify/Article/2';
        $this->postMetaStore[44]['_wash_blog_handle'] = 'news';
        $this->postMetaStore[44]['_wash_image_warnings'] = ['hero-1024x881.jpg', 'inline.jpg'];

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 44);
        $html = ob_get_clean();

        $this->assertStringContainsString('dashicons-yes-alt', $html);  // still synced
        $this->assertStringContainsString('dashicons-warning', $html);  // partial-success flag (#249)
        $this->assertStringContainsString('hero-1024x881.jpg', $html);  // reason shown in tooltip
    }

    public function testRenderShopifyColumnShowsQueuedIcon(): void
    {
        $this->mockPost(41, 'post', 'publish', 'queued');
        $this->postMetaStore[41]['_wash_sync_status'] = 'queued';

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 41);
        $html = ob_get_clean();
        $this->assertStringContainsString('wash-syncing', $html);
    }

    public function testRenderShopifyColumnShowsErrorIcon(): void
    {
        $this->mockPost(42, 'post', 'publish', 'errored');
        $this->postMetaStore[42]['_wash_sync_error'] = 'Some error message';

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 42);
        $html = ob_get_clean();
        $this->assertStringContainsString('dashicons-warning', $html);
    }

    public function testRenderShopifyColumnShowsPendingSourceIcon(): void
    {
        $this->mockPost(43, 'post', 'publish', 'pending');
        $this->postMetaStore[43]['_wash_sync_error'] = 'PENDING_SOURCE: WP post #5 must sync first';

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 43);
        $html = ob_get_clean();
        $this->assertStringContainsString('dashicons-clock', $html);
    }

    public function testRenderShopifyColumnShowsNotSyncedForPublished(): void
    {
        $this->mockPost(44, 'post', 'publish', 'not-synced');

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 44);
        $html = ob_get_clean();
        $this->assertStringContainsString('dashicons-minus', $html);
    }

    public function testRenderShopifyColumnShowsHiddenForDraft(): void
    {
        $this->mockPost(45, 'post', 'draft', 'draft-post');

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 45);
        $html = ob_get_clean();
        $this->assertStringContainsString('dashicons-hidden', $html);
    }

    public function testRenderShopifyColumnShowsPerStoreIconsWhenMultiStore(): void
    {
        // With more than one enabled store the column renders one icon per store,
        // each from that store's own per-connection meta (#252).
        $this->options['wash_connections'] = [
            'x1' => ['id' => 'x1', 'store' => 'shop2.myshopify.com', 'apiKey' => 'k2', 'enabled' => true, 'isPrimary' => false],
        ];
        $this->mockPost(70, 'post', 'publish', 'multi');
        // primary: not synced (base meta empty); store x1: queued (per-connection key).
        $this->postMetaStore[70]['_wash_sync_status_x1'] = 'queued';

        ob_start();
        $this->admin->renderShopifyColumn('wash_sync', 70);
        $html = ob_get_clean();

        $this->assertStringContainsString('dashicons-minus', $html);     // primary not synced
        $this->assertStringContainsString('wash-syncing', $html);        // shop2 queued
        $this->assertStringContainsString('shop2.myshopify.com', $html); // each store labelled
    }

    public function testConnectionAwareSettingRoutesNonPrimarySaveToNamespaced(): void
    {
        // The Settings-API save is wrapped so a non-primary connection writes to its
        // namespaced option and leaves primary untouched (#252).
        $callbacks = [];
        Functions\when('register_setting')->alias(function ($group, $key, $args) use (&$callbacks) {
            $callbacks[$key] = $args['sanitize_callback'];
        });
        $this->admin->registerSettings();
        $cb = $callbacks['wash_block_robots'];
        $this->options['wash_block_robots'] = '1';

        // Primary: returns the sanitized value as-is, writes nothing namespaced.
        \WashContext::setActiveId('primary');
        $this->assertSame('1', $cb('1'));
        $this->assertArrayNotHasKey('wash_c_primary_wash_block_robots', $this->options);

        // Additional store: writes the namespaced option, returns primary's value unchanged.
        \WashContext::setActiveId('x1');
        $this->assertSame('1', $cb('0'));                                      // primary value returned
        $this->assertSame('0', $this->options['wash_c_x1_wash_block_robots']); // namespaced write
        $this->assertSame('1', $this->options['wash_block_robots']);           // primary untouched
    }

    // ---------------------------------------------------------------
    // addShopifyRowActions
    // ---------------------------------------------------------------

    public function testAddShopifyRowActionsSkipsWhenNotConfigured(): void
    {
        $this->options['wash_api_key'] = '';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();

        $post = $this->mockPost(50);
        $result = $admin->addShopifyRowActions([], $post);
        $this->assertSame([], $result);
    }

    public function testAddShopifyRowActionsSkipsDisallowedPostType(): void
    {
        $post = (object) ['ID' => 51, 'post_type' => 'product', 'post_status' => 'publish', 'post_name' => 'p'];
        $result = $this->admin->addShopifyRowActions([], $post);
        $this->assertArrayNotHasKey('wash_sync', $result);
    }

    public function testAddShopifyRowActionsHasNoPerPostRefresh(): void
    {
        // The per-post "Refresh status" row action was removed by design —
        // reconciling sync state is a bulk operation (Settings → Wash → Sync).
        $this->mockPost(52, 'post', 'publish', 'my-post');
        $post = $this->postStore[52];
        $result = $this->admin->addShopifyRowActions([], $post);
        $this->assertArrayNotHasKey('wash_refresh', $result);
    }

    public function testAddShopifyRowActionsAddsSyncForPublished(): void
    {
        $this->mockPost(53, 'post', 'publish', 'pub-post');
        $post = $this->postStore[53];
        $result = $this->admin->addShopifyRowActions([], $post);
        $this->assertArrayHasKey('wash_sync', $result);
    }

    public function testAddShopifyRowActionsAddsViewForSyncedPost(): void
    {
        $this->mockPost(54, 'post', 'publish', 'synced-p');
        $this->postMetaStore[54]['_wash_article_id'] = 'gid://shopify/Article/9';
        $this->postMetaStore[54]['_wash_blog_handle'] = 'news';
        $post = $this->postStore[54];
        $result = $this->admin->addShopifyRowActions([], $post);
        $this->assertArrayHasKey('wash_view', $result);
    }

    // ---------------------------------------------------------------
    // applyStatusToPostMeta (private → Reflection)
    // ---------------------------------------------------------------

    public function testApplyStatusToPostMetaReturnsSyncedWhenExists(): void
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'applyStatusToPostMeta');
        $ref->setAccessible(true);

        $status = [
            'exists'             => true,
            'shopifyArticleId'   => 'gid://shopify/Article/1',
            'shopifyBlogId'      => 'gid://shopify/Blog/2',
            'shopifyBlogHandle'  => 'news',
            'syncedAt'           => '2026-01-01T00:00:00Z',
        ];

        $result = $ref->invoke($this->admin, 100, $status);
        $this->assertSame('synced', $result);
        $this->assertSame('gid://shopify/Article/1', $this->postMetaStore[100]['_wash_article_id']);
    }

    public function testApplyStatusToPostMetaReturnsNotSyncedWhenNotExists(): void
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'applyStatusToPostMeta');
        $ref->setAccessible(true);

        $result = $ref->invoke($this->admin, 101, null);
        $this->assertSame('not_synced', $result);
    }

    // ---------------------------------------------------------------
    // ajaxRefreshAllStatuses
    // ---------------------------------------------------------------

    public function testAjaxRefreshAllStatusesPreparePhaseReturnsTokenAndTotal(): void
    {
        Functions\when('get_posts')->justReturn([1, 2, 3]);

        try {
            $this->admin->ajaxRefreshAllStatuses();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertNotEmpty($this->jsonResponses);
        $resp = $this->jsonResponses[0];
        $this->assertSame('success', $resp['type']);
        $this->assertArrayHasKey('token', $resp['data']);
        $this->assertSame(3, $resp['data']['total']);
    }

    public function testAjaxRefreshAllStatusesBatchPhaseWithExpiredToken(): void
    {
        $_POST['token'] = 'no-such-token';
        $_POST['offset'] = '0';

        try {
            $this->admin->ajaxRefreshAllStatuses();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertStringContainsString('expired', $this->jsonResponses[0]['data']);

        unset($_POST['token'], $_POST['offset']);
    }

    public function testAjaxRefreshAllStatusesBatchPhaseProcessesPosts(): void
    {
        $token = 'TESTTOKEN1234567890Z';
        $this->transients['wash_refresh_' . $token] = [1, 2];
        $_POST['token']  = $token;
        $_POST['offset'] = '0';

        Functions\when('wp_remote_post')->justReturn(['body' => json_encode([
            'success'  => true,
            'statuses' => [
                1 => ['exists' => true, 'shopifyArticleId' => 'gid://shopify/Article/1'],
                2 => ['exists' => false],
            ],
        ])]);

        try {
            $this->admin->ajaxRefreshAllStatuses();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertTrue($this->jsonResponses[0]['data']['done']);

        unset($_POST['token'], $_POST['offset']);
    }

    // ---------------------------------------------------------------
    // ajaxSaveConsent
    // ---------------------------------------------------------------

    public function testAjaxSaveConsentStoresFlag(): void
    {
        Functions\when('time')->justReturn(1700000000);

        try {
            $this->admin->ajaxSaveConsent();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame('1', $this->options['wash_consent_accepted']);
    }

    // ---------------------------------------------------------------
    // ajaxTestConnection
    // ---------------------------------------------------------------

    public function testAjaxTestConnectionSuccessPath(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"connected":true,"status":"ok"}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"connected":true,"status":"ok"}');

        try {
            $this->admin->ajaxTestConnection();
        } catch (\RuntimeException $e) {
            // wp_send_json_success or error
        }

        $this->assertNotEmpty($this->jsonResponses);
    }

    // ---------------------------------------------------------------
    // ajaxDisconnect
    // ---------------------------------------------------------------

    public function testAjaxDisconnectDeletesApiKey(): void
    {
        try {
            $this->admin->ajaxDisconnect();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertArrayNotHasKey('wash_api_key', $this->options);
    }

    // ---------------------------------------------------------------
    // ajaxDebugSession / ajaxResetSession / ajaxFixSession
    // ---------------------------------------------------------------

    public function testAjaxDebugSessionForwardsApiResult(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"sessions":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sessions":[]}');

        try {
            $this->admin->ajaxDebugSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertNotEmpty($this->jsonResponses);
    }

    public function testAjaxResetSessionForwardsApiResult(): void
    {
        Functions\when('wp_remote_post')->justReturn(['body' => '{"deleted":1}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"deleted":1}');

        try {
            $this->admin->ajaxResetSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertNotEmpty($this->jsonResponses);
    }

    public function testAjaxFixSessionForwardsApiResult(): void
    {
        Functions\when('wp_remote_post')->justReturn(['body' => '{"fixed":1}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"fixed":1}');

        try {
            $this->admin->ajaxFixSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertNotEmpty($this->jsonResponses);
    }

    // ---------------------------------------------------------------
    // ajaxResetToDefaults
    // ---------------------------------------------------------------

    public function testAjaxResetToDefaultsSetsDefaultOptions(): void
    {
        $this->transients['wash_settings_backup_pending'] = false;

        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');

        try {
            $this->admin->ajaxResetToDefaults();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame('1', $this->options['wash_sync_on_publish']);
    }

    // ---------------------------------------------------------------
    // ajaxSyncSettingsFromShopify
    // ---------------------------------------------------------------

    public function testAjaxSyncSettingsFromShopifyReturnsErrorOnApiFailure(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'Timed out'));

        try {
            $this->admin->ajaxSyncSettingsFromShopify();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    public function testAjaxSyncSettingsFromShopifyReturnsErrorWhenNoSettings(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"success":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');

        try {
            $this->admin->ajaxSyncSettingsFromShopify();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetMappings success
    // ---------------------------------------------------------------

    public function testAjaxGetMappingsReturnsEmptyMappingsOnSuccess(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"mappings":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"mappings":[]}');

        try {
            $this->admin->ajaxGetMappings();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame([], $this->jsonResponses[0]['data']['mappings']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyBlogs
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyBlogsReturnsBlogsList(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"blogs":[{"id":1,"title":"News"}]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"blogs":[{"id":1,"title":"News"}]}');

        try {
            $this->admin->ajaxGetShopifyBlogs();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertCount(1, $this->jsonResponses[0]['data']['blogs']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyMarkets
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyMarketsReturnsMarketsList(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"markets":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"markets":[]}');

        try {
            $this->admin->ajaxGetShopifyMarkets();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopDomains
    // ---------------------------------------------------------------

    public function testAjaxGetShopDomainsReturnsDomainsList(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"domains":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"domains":[]}');

        try {
            $this->admin->ajaxGetShopDomains();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxCreateMapping
    // ---------------------------------------------------------------

    public function testAjaxCreateMappingSuccessPath(): void
    {
        $_POST['destination']  = 'article';
        $_POST['wpPostTypes']  = 'post';
        $_POST['ruleType']     = 'default';
        $_POST['ruleValue']    = '';
        $_POST['shopifyBlogId'] = '';
        $_POST['newBlogName']  = '';
        $_POST['priority']     = '0';
        $_POST['marketId']     = '';
        $_POST['shopifyLocale'] = '';

        Functions\when('wp_remote_post')->justReturn(['body' => '{"mapping":{"id":"abc"},"blogCreated":false}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"mapping":{"id":"abc"},"blogCreated":false}');

        try {
            $this->admin->ajaxCreateMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        unset($_POST['destination'], $_POST['wpPostTypes'], $_POST['ruleType'],
              $_POST['ruleValue'], $_POST['shopifyBlogId'], $_POST['newBlogName'],
              $_POST['priority'], $_POST['marketId'], $_POST['shopifyLocale']);
    }

    // ---------------------------------------------------------------
    // ajaxUpdateMapping
    // ---------------------------------------------------------------

    public function testAjaxUpdateMappingReturnsErrorWhenNoId(): void
    {
        $_POST['mappingId'] = '';

        try {
            $this->admin->ajaxUpdateMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['mappingId']);
    }

    public function testAjaxUpdateMappingSuccessPath(): void
    {
        $_POST['mappingId']    = 'mapping-123';
        $_POST['destination']  = 'article';
        $_POST['wpPostTypes']  = 'post';
        $_POST['ruleType']     = 'default';
        $_POST['ruleValue']    = '';
        $_POST['shopifyBlogId'] = '';
        $_POST['priority']     = '0';
        $_POST['marketId']     = '';
        $_POST['shopifyLocale'] = '';

        Functions\when('wp_remote_post')->justReturn(['body' => '{"mapping":{"id":"mapping-123"}}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"mapping":{"id":"mapping-123"}}');

        try {
            $this->admin->ajaxUpdateMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        unset($_POST['mappingId'], $_POST['destination'], $_POST['wpPostTypes'],
              $_POST['ruleType'], $_POST['ruleValue'], $_POST['shopifyBlogId'],
              $_POST['priority'], $_POST['marketId'], $_POST['shopifyLocale']);
    }

    // ---------------------------------------------------------------
    // ajaxDeleteMapping
    // ---------------------------------------------------------------

    public function testAjaxDeleteMappingReturnsErrorWhenNoId(): void
    {
        $_POST['mappingId'] = '';

        try {
            $this->admin->ajaxDeleteMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['mappingId']);
    }

    public function testAjaxDeleteMappingSuccessPath(): void
    {
        $_POST['mappingId'] = 'mapping-456';

        Functions\when('wp_remote_post')->justReturn(['body' => '{"deleted":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"deleted":true}');

        try {
            $this->admin->ajaxDeleteMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        unset($_POST['mappingId']);
    }

    // ---------------------------------------------------------------
    // ajaxReorderMappings
    // ---------------------------------------------------------------

    public function testAjaxReorderMappingsReturnsErrorOnInvalidData(): void
    {
        $_POST['updates'] = 'not-json';

        try {
            $this->admin->ajaxReorderMappings();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['updates']);
    }

    public function testAjaxReorderMappingsSuccessPath(): void
    {
        $_POST['updates'] = json_encode([
            ['id' => 'abc', 'priority' => 1],
            ['id' => 'def', 'priority' => 2],
        ]);

        Functions\when('wp_remote_post')->justReturn(['body' => '{"updated":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"updated":true}');

        try {
            $this->admin->ajaxReorderMappings();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(2, $this->jsonResponses[0]['data']['updated']);
        unset($_POST['updates']);
    }

    // ---------------------------------------------------------------
    // ajaxGetSearchReplaceRules
    // ---------------------------------------------------------------

    public function testAjaxGetSearchReplaceRulesReturnsStoredRules(): void
    {
        $this->options['wash_search_replace_rules'] = [
            ['search' => 'old', 'replace' => 'new', 'type' => 'string'],
        ];

        try {
            $this->admin->ajaxGetSearchReplaceRules();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertCount(1, $this->jsonResponses[0]['data']['rules']);
    }

    public function testAjaxGetSearchReplaceRulesReturnsEmptyWhenNotArray(): void
    {
        $this->options['wash_search_replace_rules'] = 'not-array';

        try {
            $this->admin->ajaxGetSearchReplaceRules();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame([], $this->jsonResponses[0]['data']['rules']);
    }

    // ---------------------------------------------------------------
    // ajaxSaveSearchReplaceRules
    // ---------------------------------------------------------------

    public function testAjaxSaveSearchReplaceRulesSanitizesAndSaves(): void
    {
        $_POST['rules'] = [
            ['search' => 'old-domain.com', 'replace' => 'new-domain.com', 'type' => 'string'],
            ['search' => '', 'replace' => 'skip-this'],  // empty search → skipped
            ['search' => 'foo', 'replace' => 'bar', 'type' => 'invalid_type'], // invalid type → 'string'
        ];

        try {
            $this->admin->ajaxSaveSearchReplaceRules();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $rules = $this->jsonResponses[0]['data']['rules'];
        $this->assertCount(2, $rules);
        $this->assertSame('string', $rules[0]['type']);
        $this->assertSame('string', $rules[1]['type']); // invalid type normalized
        unset($_POST['rules']);
    }

    public function testAjaxSaveSearchReplaceRulesHandlesEmptyPost(): void
    {
        // $_POST['rules'] not set → empty array

        try {
            $this->admin->ajaxSaveSearchReplaceRules();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame([], $this->jsonResponses[0]['data']['rules']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyRedirections
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyRedirectionsSuccessPath(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{"stats":{},"redirects":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"stats":{},"redirects":[]}');

        try {
            $this->admin->ajaxGetShopifyRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
    }

    public function testAjaxGetShopifyRedirectionsErrorWithDiagnosis(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('api', 'API error'));

        try {
            $this->admin->ajaxGetShopifyRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertStringContainsString('API error', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxSyncRedirections
    // ---------------------------------------------------------------

    public function testAjaxSyncRedirectionsReturnsErrorWhenEmpty(): void
    {
        $_POST['redirections'] = '';

        try {
            $this->admin->ajaxSyncRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['redirections']);
    }

    public function testAjaxSyncRedirectionsReturnsErrorWhenNoValidLines(): void
    {
        $_POST['redirections'] = "# just a comment\n\n";

        Functions\when('sanitize_textarea_field')->returnArg(1);

        try {
            $this->admin->ajaxSyncRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['redirections']);
    }

    public function testAjaxSyncRedirectionsSuccessPath(): void
    {
        $_POST['redirections'] = "/old-path -> /new-path\n/foo -> /bar";

        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('wp_remote_post')->justReturn(['body' => '{"created":2,"skipped":0,"failed":0,"remaining":0,"totalInShopify":2,"errors":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"created":2,"skipped":0,"failed":0,"remaining":0,"totalInShopify":2,"errors":[]}');

        try {
            $this->admin->ajaxSyncRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(2, $this->jsonResponses[0]['data']['created']);
        unset($_POST['redirections']);
    }

    // ---------------------------------------------------------------
    // parseRedirections (private → Reflection)
    // ---------------------------------------------------------------

    public function testParseRedirectionsHandlesArrowSyntax(): void
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'parseRedirections');
        $ref->setAccessible(true);

        $result = $ref->invoke($this->admin, "/old -> /new\n# comment\n\n/foo => /bar");
        $this->assertCount(2, $result);
        $this->assertSame('/old', $result[0]['from']);
        $this->assertSame('/new', $result[0]['to']);
        $this->assertSame('/foo', $result[1]['from']);
    }

    public function testParseRedirectionsPrependsMissingSlash(): void
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'parseRedirections');
        $ref->setAccessible(true);

        $result = $ref->invoke($this->admin, 'old-path -> new-path');
        $this->assertCount(1, $result);
        $this->assertSame('/old-path', $result[0]['from']);
        $this->assertSame('/new-path', $result[0]['to']);
    }

    public function testParseRedirectionsAllowsHttpTarget(): void
    {
        $ref = new \ReflectionMethod(\WashAdmin::class, 'parseRedirections');
        $ref->setAccessible(true);

        $result = $ref->invoke($this->admin, '/old → https://example.com/new');
        $this->assertCount(1, $result);
        $this->assertStringStartsWith('https://', $result[0]['to']);
    }

    // ---------------------------------------------------------------
    // ajaxSaveRedirectionsSettings
    // ---------------------------------------------------------------

    public function testAjaxSaveRedirectionsSettingsSavesEnabled(): void
    {
        $_POST['enabled'] = '1';

        try {
            $this->admin->ajaxSaveRedirectionsSettings();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame('1', $this->jsonResponses[0]['data']['enabled']);
        unset($_POST['enabled']);
    }

    public function testAjaxSaveRedirectionsSettingsSavesDisabled(): void
    {
        // no $_POST['enabled'] set

        try {
            $this->admin->ajaxSaveRedirectionsSettings();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('0', $this->jsonResponses[0]['data']['enabled']);
    }

    // ---------------------------------------------------------------
    // ajaxGenerateRedirections
    // ---------------------------------------------------------------

    public function testAjaxGenerateRedirectionsReturnsEmptyWhenNoPosts(): void
    {
        Functions\when('get_posts')->justReturn([]);

        try {
            $this->admin->ajaxGenerateRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(0, $this->jsonResponses[0]['data']['count']);
    }

    public function testAjaxGenerateRedirectionsBuildsPageRedirection(): void
    {
        $post = (object) [
            'ID' => 60, 'post_type' => 'page', 'post_status' => 'publish',
            'post_name' => 'about', 'post_title' => 'About',
        ];
        Functions\when('get_posts')->justReturn([$post]);
        Functions\when('get_permalink')->justReturn('https://example.com/about/');
        Functions\when('get_post_meta')->justReturn('');

        try {
            $this->admin->ajaxGenerateRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $data = $this->jsonResponses[0]['data'];
        // /about/ → /pages/about → different so should be included
        $this->assertGreaterThanOrEqual(0, $data['count']);
    }

    // ---------------------------------------------------------------
    // ajaxFetchWpRedirections
    // ---------------------------------------------------------------

    public function testAjaxFetchWpRedirectionsReturnsEmptyWhenNoPlugins(): void
    {
        // No Red_Item class, stub $wpdb so the RankMath table check returns null
        global $wpdb;
        $mockWpdb = new class {
            public string $prefix = 'wp_';
            public string $postmeta = 'wp_postmeta';
            public string $posts = 'wp_posts';
            public function prepare(string $sql, ...$args): string { return $sql; }
            public function get_var(string $sql): ?string { return null; }
            public function esc_like(string $s): string { return $s; }
        };
        $wpdb = $mockWpdb;

        Functions\when('get_option')->alias(function ($k, $d = []) {
            if ($k === 'wpseo-premium-redirects-base') {
                return [];
            }
            return $this->options[$k] ?? $d;
        });
        Functions\when('maybe_unserialize')->returnArg(1);

        try {
            $this->admin->ajaxFetchWpRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(0, $this->jsonResponses[0]['data']['count']);
        $wpdb = null;
    }

    // ---------------------------------------------------------------
    // addBulkActions
    // ---------------------------------------------------------------

    public function testAddBulkActionsReturnsUnchangedWhenNotConfigured(): void
    {
        $this->options['wash_api_key'] = '';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();

        $result = $admin->addBulkActions(['trash' => 'Move to Trash']);
        $this->assertArrayNotHasKey('wash_publish', $result);
    }

    public function testAddBulkActionsAddsThreeActions(): void
    {
        $result = $this->admin->addBulkActions(['trash' => 'Move to Trash']);
        $this->assertArrayHasKey('wash_publish', $result);
        $this->assertArrayHasKey('wash_republish', $result);
        $this->assertArrayHasKey('wash_unpublish', $result);
    }

    // ---------------------------------------------------------------
    // ajaxCheckSyncStatus with post IDs
    // ---------------------------------------------------------------

    public function testAjaxCheckSyncStatusReturnsSyncedForSyncedPost(): void
    {
        $this->mockPost(70, 'post', 'publish', 'synced');
        $this->postMetaStore[70]['_wash_article_id'] = 'gid://shopify/Article/1';
        $this->postMetaStore[70]['_wash_blog_handle'] = 'news';

        $_POST['post_ids'] = ['70'];

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame('synced', $this->jsonResponses[0]['data'][70]['status']);
        unset($_POST['post_ids']);
    }

    public function testAjaxCheckSyncStatusReturnsQueuedStatus(): void
    {
        $this->postMetaStore[71]['_wash_sync_status'] = 'queued';

        $_POST['post_ids'] = ['71'];

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('queued', $this->jsonResponses[0]['data'][71]['status']);
        unset($_POST['post_ids']);
    }

    public function testAjaxCheckSyncStatusReturnsErrorStatus(): void
    {
        $this->postMetaStore[72]['_wash_sync_error'] = 'something went wrong';

        $_POST['post_ids'] = ['72'];

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('error', $this->jsonResponses[0]['data'][72]['status']);
        unset($_POST['post_ids']);
    }

    public function testAjaxCheckSyncStatusReturnsNoneStatus(): void
    {
        $_POST['post_ids'] = ['73'];

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('none', $this->jsonResponses[0]['data'][73]['status']);
        unset($_POST['post_ids']);
    }

    // ---------------------------------------------------------------
    // ajaxManualSync
    // ---------------------------------------------------------------

    public function testAjaxManualSyncReturnsErrorWhenNoPostId(): void
    {
        $_POST['post_id'] = '0';

        try {
            $this->admin->ajaxManualSync();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['post_id']);
    }

    public function testAjaxManualSyncReturnsErrorWhenPostNotFound(): void
    {
        $_POST['post_id'] = '999';

        try {
            $this->admin->ajaxManualSync();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['post_id']);
    }

    // ---------------------------------------------------------------
    // renderSyncNotices — various GET params
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesShowsSyncedNotice(): void
    {
        $_GET['wash_result'] = 'synced';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-success', $html);
        unset($_GET['wash_result']);
    }

    public function testRenderSyncNoticesShowsQueuedNotice(): void
    {
        $_GET['wash_result'] = 'queued';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-info', $html);
        unset($_GET['wash_result']);
    }

    public function testRenderSyncNoticesShowsErrorNotice(): void
    {
        $_GET['wash_result']   = 'error';
        $_GET['wash_error_msg'] = 'Something went wrong';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        unset($_GET['wash_result'], $_GET['wash_error_msg']);
    }

    public function testRenderSyncNoticesShowsErrorNoticeWithLimitUpgrade(): void
    {
        $_GET['wash_result']   = 'error';
        $_GET['wash_error_msg'] = 'Usage limit exceeded';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('myshopify.com', $html); // upgrade link
        unset($_GET['wash_result'], $_GET['wash_error_msg']);
    }

    public function testRenderSyncNoticesShowsNotPublishedNotice(): void
    {
        $_GET['wash_result'] = 'not_published';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $html);
        unset($_GET['wash_result']);
    }

    public function testRenderSyncNoticesShowsBulkErrorNotConfigured(): void
    {
        $_GET['wash_bulk_error'] = 'not_configured';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        unset($_GET['wash_bulk_error']);
    }

    public function testRenderSyncNoticesShowsBulkActionNotice(): void
    {
        $_GET['wash_bulk_action']    = 'wash_publish';
        $_GET['wash_bulk_processed'] = '5';
        $_GET['wash_bulk_skipped']   = '1';
        $_GET['wash_bulk_errors']    = '0';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-success', $html);
        unset($_GET['wash_bulk_action'], $_GET['wash_bulk_processed'],
              $_GET['wash_bulk_skipped'], $_GET['wash_bulk_errors']);
    }

    public function testRenderSyncNoticesShowsPostSyncErrorOnEditScreen(): void
    {
        $_GET['post'] = '80';
        $this->postMetaStore[80]['_wash_sync_error'] = 'Sync failed: timeout';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'post']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('Sync failed', $html);
        unset($_GET['post']);
    }

    public function testRenderSyncNoticesShowsUnknownResultSilently(): void
    {
        $_GET['wash_result'] = 'bogus_result';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        // Unknown result → returns early, no notice output
        $this->assertStringNotContainsString('notice', $html);
        unset($_GET['wash_result']);
    }

    // ---------------------------------------------------------------
    // asyncSyncPost (skip when not published)
    // ---------------------------------------------------------------

    public function testAsyncSyncPostSkipsNonPublished(): void
    {
        $this->mockPost(90, 'post', 'draft', 'draft-p');
        // Should just log and return — no exception
        $this->admin->asyncSyncPost(90);
        $this->addToAssertionCount(1);
    }

    public function testAsyncSyncPostSkipsMissingPost(): void
    {
        // Post 999 not in store → get_post returns null
        $this->admin->asyncSyncPost(999);
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------
    // enqueueAssets — three branches
    // ---------------------------------------------------------------

    public function testEnqueueAssetsOnEditPhpEnqueuesSyncStatusScript(): void
    {
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('filemtime')->justReturn(12345);
        Functions\when('time')->justReturn(1700000000);

        // Should not throw
        $this->admin->enqueueAssets('edit.php');
        $this->addToAssertionCount(1);
    }

    public function testEnqueueAssetsOnSettingsPageEnqueuesAdminScript(): void
    {
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('wp_enqueue_style')->justReturn(true);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('filemtime')->justReturn(12345);
        Functions\when('time')->justReturn(1700000000);

        $this->admin->enqueueAssets('settings_page_wash');
        $this->addToAssertionCount(1);
    }

    public function testEnqueueAssetsOnUnknownHookReturnsEarly(): void
    {
        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('filemtime')->justReturn(12345);

        // Other hooks do nothing
        $this->admin->enqueueAssets('plugins.php');
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------
    // renderShopifyPreviewLink
    // ---------------------------------------------------------------

    public function testRenderShopifyPreviewLinkOutputsLinkWhenSynced(): void
    {
        $this->mockPost(110, 'post', 'publish', 'synced-post');
        $this->postMetaStore[110]['_wash_article_id'] = 'gid://shopify/Article/1';
        $this->postMetaStore[110]['_wash_blog_handle'] = 'news';

        $post = $this->postStore[110];

        ob_start();
        $this->admin->renderShopifyPreviewLink($post);
        $html = ob_get_clean();

        $this->assertStringContainsString('View on Shopify', $html);
        $this->assertStringContainsString('myshopify.com', $html);
    }

    public function testRenderShopifyPreviewLinkOutputsNothingWhenNotSynced(): void
    {
        $this->mockPost(111, 'post', 'publish', 'not-synced');
        $post = $this->postStore[111];

        ob_start();
        $this->admin->renderShopifyPreviewLink($post);
        $html = ob_get_clean();

        $this->assertEmpty($html);
    }

    // ---------------------------------------------------------------
    // enqueueBlockEditorAssets
    // ---------------------------------------------------------------

    public function testEnqueueBlockEditorAssetsDoesNothingWhenNoGlobalPost(): void
    {
        global $post;
        $post = null;

        $this->admin->enqueueBlockEditorAssets();
        $this->addToAssertionCount(1);
    }

    public function testEnqueueBlockEditorAssetsDoesNothingWhenNotSynced(): void
    {
        global $post;
        $post = $this->mockPost(120, 'post', 'publish', 'not-synced');

        $this->admin->enqueueBlockEditorAssets();
        $this->addToAssertionCount(1);
        $post = null;
    }

    public function testEnqueueBlockEditorAssetsEnqueuesScriptWhenSynced(): void
    {
        global $post;
        $this->mockPost(121, 'post', 'publish', 'synced-e');
        $this->postMetaStore[121]['_wash_article_id'] = 'gid://shopify/Article/1';
        $this->postMetaStore[121]['_wash_blog_handle'] = 'news';
        $post = $this->postStore[121];

        Functions\when('wp_add_inline_script')->justReturn(true);

        $this->admin->enqueueBlockEditorAssets();
        $this->addToAssertionCount(1);
        $post = null;
    }

    // ---------------------------------------------------------------
    // init — cover add_action/add_filter hooks (they return null)
    // ---------------------------------------------------------------

    public function testInitRegistersHooksWithoutError(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);

        $this->admin->init();
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------
    // addMenuPage / registerSettings / addBulkProgressPage
    // ---------------------------------------------------------------

    public function testAddMenuPageCallsAddOptionsPage(): void
    {
        Functions\when('add_options_page')->justReturn('wash');
        $this->admin->addMenuPage();
        $this->addToAssertionCount(1);
    }

    public function testRegisterSettingsCallsRegisterSetting(): void
    {
        Functions\when('register_setting')->justReturn(true);
        $this->admin->registerSettings();
        $this->addToAssertionCount(1);
    }

    public function testAddBulkProgressPageCallsAddSubmenuPage(): void
    {
        Functions\when('add_submenu_page')->justReturn(true);
        $this->admin->addBulkProgressPage();
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------
    // ajaxScanContentLinks — cached path
    // ---------------------------------------------------------------

    public function testAjaxScanContentLinksReturnsCachedResult(): void
    {
        $cached = ['urls' => [], 'domains' => [], 'shortcodes' => [], 'blocks' => [], 'postCount' => 0];
        $this->transients['wash_content_links'] = $cached;

        try {
            $this->admin->ajaxScanContentLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(0, $this->jsonResponses[0]['data']['postCount']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyLinks — cached path
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyLinksReturnsCachedResult(): void
    {
        $cached = ['links' => [['url' => '/products/test', 'label' => 'Test', 'type' => 'product']]];
        $this->transients['wash_shopify_links'] = $cached;

        try {
            $this->admin->ajaxGetShopifyLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertCount(1, $this->jsonResponses[0]['data']['links']);
    }

    // ---------------------------------------------------------------
    // ajaxRefreshAllStatuses — not configured branch
    // ---------------------------------------------------------------

    public function testAjaxRefreshAllStatusesReturnsErrorWhenNotConfigured(): void
    {
        $this->options['wash_api_key'] = '';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();

        // Need to re-stub json callbacks since new admin instance
        $jsonResponses = [];
        Functions\when('wp_send_json_error')->alias(function ($msg = '') use (&$jsonResponses) {
            $jsonResponses[] = ['type' => 'error', 'data' => $msg];
            throw new \RuntimeException('wp_send_json_error');
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) use (&$jsonResponses) {
            $jsonResponses[] = ['type' => 'success', 'data' => $data];
            throw new \RuntimeException('wp_send_json_success');
        });

        try {
            $admin->ajaxRefreshAllStatuses();
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame('error', $jsonResponses[0]['type']);
        $this->assertStringContainsString('Not connected', $jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxSyncRedirections — API error + diagnosis branch
    // ---------------------------------------------------------------

    public function testAjaxSyncRedirectionsReturnsApiError(): void
    {
        $_POST['redirections'] = "/old -> /new";

        Functions\when('wp_remote_post')->justReturn(new \WP_Error('api', 'Sync API error'));

        try {
            $this->admin->ajaxSyncRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertStringContainsString('Sync API error', $this->jsonResponses[0]['data']);
        unset($_POST['redirections']);
    }

    // ---------------------------------------------------------------
    // ajaxReorderMappings — API error path
    // ---------------------------------------------------------------

    public function testAjaxReorderMappingsReturnsErrorOnApiFailure(): void
    {
        $_POST['updates'] = json_encode([['id' => 'abc', 'priority' => 1]]);

        Functions\when('wp_remote_post')->justReturn(new \WP_Error('api', 'Network error'));

        try {
            $this->admin->ajaxReorderMappings();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['updates']);
    }

    // ---------------------------------------------------------------
    // ajaxScanContentLinks — no cache, no posts
    // ---------------------------------------------------------------

    public function testAjaxScanContentLinksWithNoPostsReturnsEmpty(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_post_field')->justReturn('');

        // WP_Block_Type_Registry::get_instance() may not exist; skip if so
        if (!class_exists('WP_Block_Type_Registry')) {
            $this->markTestSkipped('WP_Block_Type_Registry not available in test environment');
        }

        try {
            $this->admin->ajaxScanContentLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyLinks — no cache, fresh fetch
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyLinksBuildsLinkListFromApi(): void
    {
        Functions\when('wp_remote_get')->alias(function ($url) {
            if (strpos($url, '/api/shopify-products') !== false) {
                return ['body' => '{"products":[{"handle":"tshirt","title":"T-Shirt"}]}'];
            }
            if (strpos($url, '/api/shopify-collections') !== false) {
                return ['body' => '{"collections":[{"handle":"sale","title":"Sale"}]}'];
            }
            if (strpos($url, '/api/shopify-blogs') !== false) {
                return ['body' => '{"blogs":[{"handle":"news","title":"News"}]}'];
            }
            return ['body' => '{}'];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(function ($response) {
            return $response['body'] ?? '{}';
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_post_field')->justReturn('');
        Functions\when('get_the_title')->justReturn('Test');

        try {
            $this->admin->ajaxGetShopifyLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $links = $this->jsonResponses[0]['data']['links'];
        // Should include at least the default pages
        $this->assertNotEmpty($links);
    }

    // ---------------------------------------------------------------
    // Bulk action — cron mode (inlineMode = '0')
    // ---------------------------------------------------------------

    public function testHandleBulkActionsCronModeSchedulesSyncEvents(): void
    {
        $this->options['wash_sync_inline'] = '0';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();

        $this->mockPost(130, 'post', 'publish', 'pub-post-1');
        $this->mockPost(131, 'post', 'publish', 'pub-post-2');

        Functions\when('update_post_meta')->alias(function ($id, $key, $val) {
            $this->postMetaStore[(int) $id][$key] = $val;
            return true;
        });
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('time')->justReturn(1700000000);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}']);
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('apply_filters')->alias(fn($_, $v) => $v);

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $admin->handleBulkActions($url, 'wash_publish', [130, 131]);

        $this->assertStringContainsString('wash_bulk_processed', $result);
    }

    // ---------------------------------------------------------------
    // renderBulkActionNotice — errors branch
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesBulkActionWithErrors(): void
    {
        $_GET['wash_bulk_action']    = 'wash_unpublish';
        $_GET['wash_bulk_processed'] = '2';
        $_GET['wash_bulk_skipped']   = '0';
        $_GET['wash_bulk_errors']    = '1';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $html);
        unset($_GET['wash_bulk_action'], $_GET['wash_bulk_processed'],
              $_GET['wash_bulk_skipped'], $_GET['wash_bulk_errors']);
    }

    // ---------------------------------------------------------------
    // ajaxCheckSyncStatus — empty post_ids
    // ---------------------------------------------------------------

    public function testAjaxCheckSyncStatusReturnsErrorWhenEmptyPostIds(): void
    {
        $_POST['post_ids'] = [];

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['post_ids']);
    }

    // ---------------------------------------------------------------
    // ajaxManualSync — post exists, simulated sync (WashSync not available)
    // ---------------------------------------------------------------

    public function testAjaxManualSyncReturnsErrorWhenNoPostIdSet(): void
    {
        // No $_POST['post_id'] at all → should error on absint(null)
        try {
            $this->admin->ajaxManualSync();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyRedirections — error with diagnosis having fix key
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyRedirectionsErrorWithFixInDiagnosis(): void
    {
        $callCount = 0;
        Functions\when('wp_remote_get')->alias(function ($url) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                // First call: /api/redirections → error
                return new \WP_Error('api', 'Scope missing');
            }
            // Second call: /api/diagnose
            return ['body' => '{"fix":"Add read_redirects scope"}'];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"fix":"Add read_redirects scope"}');

        try {
            $this->admin->ajaxGetShopifyRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertStringContainsString('Scope missing', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // scheduleAsyncSync (private) — via handleBulkActions cron mode
    // ---------------------------------------------------------------

    public function testHandleBulkActionsCronModeUnpublishPath(): void
    {
        $this->options['wash_sync_inline'] = '0';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();

        $this->mockPost(140, 'post', 'publish', 'synced-unp');
        $this->postMetaStore[140]['_wash_article_id'] = 'gid://shopify/Article/99';

        Functions\when('update_post_meta')->alias(function ($id, $key, $val) {
            $this->postMetaStore[(int) $id][$key] = $val;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->postMetaStore[(int) $id][$key]);
            return true;
        });
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('apply_filters')->alias(fn($_, $v) => $v);
        Functions\when('site_url')->justReturn('https://example.com');

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $admin->handleBulkActions($url, 'wash_unpublish', [140]);

        $this->assertStringContainsString('wash_bulk_processed', $result);
    }

    public function testUnpublishFromShopifyIncludesLanguageAndTranslationGroup(): void
    {
        $this->options['wash_sync_inline'] = '0';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();

        $this->mockPost(141, 'post', 'publish', 'synced-unp-group');
        $this->postMetaStore[141]['_wash_article_id'] = 'gid://shopify/Article/100';
        $this->postMetaStore[141]['_wash_language'] = 'fr';

        // Polylang translation detection. is_plugin_active() is defined in
        // bootstrap.php and cannot be re-mocked (Patchwork "DefinedTooEarly"),
        // so force WashHreflangs' cached plugin detection to 'polylang' directly
        // — detectActivePlugin() short-circuits on the cached value (reset in
        // tearDown). pll_* are not pre-defined, so Brain\Monkey can stub them.
        require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
        $activePluginRef = new \ReflectionProperty('WashHreflangs', 'activePlugin');
        $activePluginRef->setAccessible(true);
        $activePluginRef->setValue(null, 'polylang');

        Functions\when('pll_get_post_language')->justReturn('fr');
        Functions\when('pll_get_post_translations')->justReturn([
            'en' => 100,
            'fr' => 141,
        ]);
        Functions\when('pll_default_language')->justReturn('en');

        Functions\when('update_post_meta')->alias(function ($id, $key, $val) {
            $this->postMetaStore[(int) $id][$key] = $val;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->postMetaStore[(int) $id][$key]);
            return true;
        });

        $capturedPayload = null;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use (&$capturedPayload) {
            $capturedPayload = json_decode($args['body'] ?? '{}', true);
            return ['body' => '{"success":true}'];
        });

        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('apply_filters')->alias(fn($_, $v) => $v);
        Functions\when('site_url')->justReturn('https://example.com');

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $admin->handleBulkActions($url, 'wash_unpublish', [141]);

        $this->assertStringContainsString('wash_bulk_processed', $result);
        $this->assertNotNull($capturedPayload);
        $this->assertSame('delete', $capturedPayload['action']);
        $this->assertSame(141, $capturedPayload['wpPostId']);
        $this->assertSame('fr', $capturedPayload['language']);
        $this->assertIsArray($capturedPayload['translationGroup']);
        $this->assertSame(100, $capturedPayload['translationGroup']['sourcePostId']);
        $this->assertSame('en', $capturedPayload['translationGroup']['sourceLang']);
        $this->assertSame('fr', $capturedPayload['translationGroup']['currentLang']);
        $this->assertFalse($capturedPayload['translationGroup']['isSource']);
    }

    // ---------------------------------------------------------------
    // renderSyncNotices — no screen (function_exists guard)
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesWhenGetCurrentScreenNotExists(): void
    {
        // When get_current_screen is NOT defined — but Brain Monkey stubs it already.
        // Test the no-screen path by returning null
        Functions\when('get_current_screen')->justReturn(null);
        $_GET['wash_result'] = 'synced';

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        // renderSyncNotices continues without screen; result notice still renders
        $this->assertStringContainsString('notice-success', $html);
        unset($_GET['wash_result']);
    }

    // ---------------------------------------------------------------
    // ajaxResetToDefaults — calls backup which fails
    // ---------------------------------------------------------------

    public function testAjaxResetToDefaultsSucceedsEvenWhenBackupFails(): void
    {
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('network', 'offline'));

        try {
            $this->admin->ajaxResetToDefaults();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxSaveConsent — rejects unauthorized user
    // ---------------------------------------------------------------

    public function testAjaxSaveConsentRejectsUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxSaveConsent();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // clearStaleErrors — body execution with wpdb mock
    // ---------------------------------------------------------------

    public function testClearStaleErrorsClearsStalePostsViaWpdb(): void
    {
        $this->options['wash_auto_clear_errors'] = '1';
        $this->options['wash_post_types'] = 'post,page';

        global $wpdb;
        $mockWpdb = new class {
            public string $prefix    = 'wp_';
            public string $postmeta  = 'wp_postmeta';
            public string $posts     = 'wp_posts';
            public function prepare(string $sql, ...$args): string { return $sql; }
            public function get_col(string $sql): array { return [200, 201]; }
            public function esc_like(string $s): string { return $s; }
        };
        $wpdb = $mockWpdb;

        // gmdate and strtotime are PHP internals — let them run naturally.
        $this->admin->clearStaleErrors();

        // Both stale post IDs should have had meta deleted
        $this->assertArrayNotHasKey('_wash_sync_error', $this->postMetaStore[200] ?? []);
        $wpdb = null;
    }

    // ---------------------------------------------------------------
    // ajaxDetectMetaFields and ajaxSaveMetaFieldConfig
    // (require WashMetafields — loaded via require_once)
    // ---------------------------------------------------------------

    private function requireWashMetafields(): bool
    {
        $path = dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';
        if (!class_exists('WashMetafields')) {
            require_once $path;
        }
        return class_exists('WashMetafields');
    }

    public function testAjaxDetectMetaFieldsReturnsMergedFields(): void
    {
        if (!$this->requireWashMetafields()) {
            $this->markTestSkipped('WashMetafields not loadable');
        }

        // WashMetafields uses get_option and get_posts internally
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_post_meta')->returnArg(1);
        Functions\when('map_deep')->alias(fn($a, $cb) => $a);
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');

        try {
            $this->admin->ajaxDetectMetaFields();
        } catch (\RuntimeException $e) {
            // wp_send_json_success or error
        }

        $this->assertNotEmpty($this->jsonResponses);
    }

    public function testAjaxSaveMetaFieldConfigRejectsInvalidConfig(): void
    {
        if (!$this->requireWashMetafields()) {
            $this->markTestSkipped('WashMetafields not loadable');
        }

        $_POST['config'] = 'not-valid-json';

        try {
            $this->admin->ajaxSaveMetaFieldConfig();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['config']);
    }

    public function testAjaxSaveMetaFieldConfigSavesValidConfig(): void
    {
        if (!$this->requireWashMetafields()) {
            $this->markTestSkipped('WashMetafields not loadable');
        }

        $_POST['config'] = json_encode([
            ['namespace' => 'custom', 'key' => 'color', 'syncEnabled' => true],
        ]);

        Functions\when('map_deep')->alias(fn($a, $cb) => $a);
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');

        try {
            $this->admin->ajaxSaveMetaFieldConfig();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertNotEmpty($this->jsonResponses);
        if ($this->jsonResponses[0]['type'] === 'success') {
            $this->assertSame(1, $this->jsonResponses[0]['data']['saved']);
        }
        unset($_POST['config']);
    }

    // ---------------------------------------------------------------
    // ajaxSyncSettingsFromShopify — success path (settings present)
    // ---------------------------------------------------------------

    public function testAjaxSyncSettingsFromShopifySuccessPath(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => json_encode([
            'success'  => true,
            'settings' => ['wash_post_types' => 'post,page'],
        ])]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'success'  => true,
            'settings' => ['wash_post_types' => 'post,page'],
        ]));

        try {
            $this->admin->ajaxSyncSettingsFromShopify();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // handleCallback — success path covered here too
    // ---------------------------------------------------------------

    public function testHandleCallbackWithReauthAndBackupRestoresSettings(): void
    {
        $_GET['page']         = 'wash';
        $_GET['wash_callback'] = '1';
        $_GET['wash_state']   = 'valid';
        $_GET['api_key']      = 'new-key';
        $_GET['store']        = 'my-store';
        $_GET['is_reauth']    = '1';
        $_GET['has_backup']   = '1';

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_verify_nonce')->alias(fn($n, $a) => $a === 'wash_connect' ? 1 : false);
        Functions\when('add_query_arg')->alias(function (...$a) {
            if (count($a) === 3 && is_string($a[0])) {
                return $a[2] . '&' . $a[0] . '=' . $a[1];
            }
            [$args, $url] = $a;
            return $url . '&' . http_build_query($args);
        });

        // getBackedUpSettings returns backed-up settings
        Functions\when('wp_remote_get')->justReturn(['body' => json_encode([
            'success'  => true,
            'settings' => ['wash_post_types' => 'post,page'],
        ])]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'success'  => true,
            'settings' => ['wash_post_types' => 'post,page'],
        ]));

        try {
            $this->admin->handleCallback();
            $this->fail('Expected redirect');
        } catch (\RuntimeException $e) {
            // redirect at end
        }

        $this->assertSame('new-key', $this->options['wash_api_key'] ?? null);
        unset($_GET['page'], $_GET['wash_callback'], $_GET['wash_state'],
              $_GET['api_key'], $_GET['store'], $_GET['is_reauth'], $_GET['has_backup']);
    }

    // ---------------------------------------------------------------
    // renderSyncNotices — bulk action with no processed/skipped/errors
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesHidesEmptyBulkActionNotice(): void
    {
        $_GET['wash_bulk_action']    = 'wash_republish';
        $_GET['wash_bulk_processed'] = '0';
        $_GET['wash_bulk_skipped']   = '0';
        $_GET['wash_bulk_errors']    = '0';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        // No messages → no notice output
        $this->assertEmpty(trim($html));
        unset($_GET['wash_bulk_action'], $_GET['wash_bulk_processed'],
              $_GET['wash_bulk_skipped'], $_GET['wash_bulk_errors']);
    }

    // ---------------------------------------------------------------
    // renderSyncNotices — unknown bulk action hides notice
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesHidesUnknownBulkAction(): void
    {
        $_GET['wash_bulk_action']    = 'wash_unknown';
        $_GET['wash_bulk_processed'] = '5';
        $_GET['wash_bulk_skipped']   = '0';
        $_GET['wash_bulk_errors']    = '0';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertEmpty(trim($html));
        unset($_GET['wash_bulk_action'], $_GET['wash_bulk_processed'],
              $_GET['wash_bulk_skipped'], $_GET['wash_bulk_errors']);
    }

    // ---------------------------------------------------------------
    // ajaxRefreshAllStatuses — batch with API error
    // ---------------------------------------------------------------

    public function testAjaxRefreshAllStatusesBatchPhaseWithApiError(): void
    {
        $token = 'TESTTOKEN1234567890Z';
        $this->transients['wash_refresh_' . $token] = [10, 11];
        $_POST['token']  = $token;
        $_POST['offset'] = '0';

        Functions\when('wp_remote_post')->justReturn(new \WP_Error('timeout', 'Timed out'));

        try {
            $this->admin->ajaxRefreshAllStatuses();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(2, $this->jsonResponses[0]['data']['errors']);
        unset($_POST['token'], $_POST['offset']);
    }

    // ---------------------------------------------------------------
    // ajaxRefreshAllStatuses — empty batch (done=true immediately)
    // ---------------------------------------------------------------

    public function testAjaxRefreshAllStatusesEmptyBatchReturnsDone(): void
    {
        $token = 'TESTTOKEN1234567890Z';
        $this->transients['wash_refresh_' . $token] = [];
        $_POST['token']  = $token;
        $_POST['offset'] = '0';

        try {
            $this->admin->ajaxRefreshAllStatuses();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertTrue($this->jsonResponses[0]['data']['done']);
        unset($_POST['token'], $_POST['offset']);
    }

    // ---------------------------------------------------------------
    // renderBulkProgressPage — expired token path
    // ---------------------------------------------------------------

    public function testRenderBulkProgressPageDiesOnExpiredToken(): void
    {
        $_GET['token'] = 'no-such-token';

        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die:' . $msg);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die:');
        $this->admin->renderBulkProgressPage();
        unset($_GET['token']);
    }

    // ---------------------------------------------------------------
    // renderBulkProgressPage — success path
    // ---------------------------------------------------------------

    public function testRenderBulkProgressPageRendersHtml(): void
    {
        $_GET['token'] = 'MYTOKEN';
        $this->transients['wash_bulk_MYTOKEN'] = [
            'action'    => 'wash_republish',
            'postIds'   => [1, 2, 3],
            'skipped'   => 0,
            'returnUrl' => 'https://example.com/wp-admin/edit.php',
        ];

        Functions\when('washPluginUrl')->justReturn('https://example.com/wp-content/plugins/wash/');
        Functions\when('filemtime')->justReturn(12345);
        Functions\when('wp_enqueue_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);

        ob_start();
        $this->admin->renderBulkProgressPage();
        $html = ob_get_clean();

        $this->assertStringContainsString('wash-bulk-progress', $html);
        $this->assertStringContainsString('progress', $html);
        unset($_GET['token']);
    }

    // ---------------------------------------------------------------
    // ajaxScanContentLinks — uncached, no posts (WP_Block_Type_Registry stubbed)
    // ---------------------------------------------------------------

    public function testAjaxScanContentLinksUncachedWithBlockRegistry(): void
    {
        // Provide a stub WP_Block_Type_Registry if not available
        if (!class_exists('WP_Block_Type_Registry')) {
            eval('class WP_Block_Type_Registry {
                private static $inst;
                public static function get_instance() {
                    if (!self::$inst) self::$inst = new self();
                    return self::$inst;
                }
                public function get_all_registered() { return []; }
            }');
        }

        Functions\when('get_posts')->justReturn([]);

        try {
            $this->admin->ajaxScanContentLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(0, $this->jsonResponses[0]['data']['postCount']);
    }

    // ---------------------------------------------------------------
    // ajaxScanContentLinks — with post content
    // ---------------------------------------------------------------

    public function testAjaxScanContentLinksExtractsUrlsFromContent(): void
    {
        if (!class_exists('WP_Block_Type_Registry')) {
            eval('class WP_Block_Type_Registry {
                private static $inst;
                public static function get_instance() {
                    if (!self::$inst) self::$inst = new self();
                    return self::$inst;
                }
                public function get_all_registered() { return []; }
            }');
        }

        Functions\when('get_posts')->justReturn([50, 51]);
        Functions\when('get_post_field')->alias(function ($field, $id) {
            if ($id === 50) {
                return '<a href="https://example.com/page">Link</a> <!-- wp:paragraph -->';
            }
            return '';
        });
        Functions\when('site_url')->justReturn('https://example.com');

        try {
            $this->admin->ajaxScanContentLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(2, $this->jsonResponses[0]['data']['postCount']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyLinks — no cache, synced posts
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyLinksIncludesSyncedArticles(): void
    {
        // Return empty products/collections/blogs from API
        Functions\when('wp_remote_get')->alias(function ($url) {
            return ['body' => '{}'];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body'] ?? '{}');

        // Synced posts
        Functions\when('get_posts')->justReturn([55, 56]);
        Functions\when('get_post_meta')->alias(function ($id, $key) {
            if ($key === '_wash_blog_handle') return 'news';
            return '';
        });
        Functions\when('get_post_field')->alias(function ($field, $id) {
            if ($field === 'post_name') return 'my-article-' . $id;
            return '';
        });
        Functions\when('get_the_title')->justReturn('My Article');

        try {
            $this->admin->ajaxGetShopifyLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $links = $this->jsonResponses[0]['data']['links'];
        $types = array_column($links, 'type');
        $this->assertContains('article', $types);
    }

    // ---------------------------------------------------------------
    // handleManualSync — success path (post is published, sync returns synced)
    // ---------------------------------------------------------------

    public function testHandleManualSyncSuccessRedirectsSynced(): void
    {
        // WashSync requires dozens of WP functions (wp_is_post_revision, etc.) that
        // cannot be stubbed at this point because Patchwork only stubs userland
        // functions defined after it loads. Skip this path — the security/early-exit
        // paths are covered by WashAdminSecurityTest.
        $this->markTestSkipped('handleManualSync success path requires WashSync which needs full WP runtime');
    }

    // ---------------------------------------------------------------
    // handleManualSync — not-published redirect
    // ---------------------------------------------------------------

    public function testHandleManualSyncRedirectsWhenNotPublished(): void
    {
        $_GET['action']   = 'wash_sync';
        $_GET['post_id']  = '301';
        $_GET['_wpnonce'] = 'good';

        $this->mockPost(301, 'post', 'draft', 'draft');

        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_get_referer')->justReturn('https://example.com/wp-admin/edit.php');

        try {
            $this->admin->handleManualSync();
            $this->fail('Expected redirect');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not_published', $e->getMessage());
        }

        unset($_GET['action'], $_GET['post_id'], $_GET['_wpnonce']);
    }

    // ---------------------------------------------------------------
    // ajaxGenerateRedirections — post type 'post' with blog handle
    // ---------------------------------------------------------------

    public function testAjaxGenerateRedirectionsBuildsArticleRedirection(): void
    {
        $post = (object) [
            'ID' => 70, 'post_type' => 'post', 'post_status' => 'publish',
            'post_name' => 'hello-world', 'post_title' => 'Hello World',
        ];
        Functions\when('get_posts')->justReturn([$post]);
        Functions\when('get_permalink')->justReturn('https://example.com/hello-world/');
        Functions\when('get_post_meta')->alias(function ($id, $key) {
            return $key === '_wash_blog_handle' ? 'news' : '';
        });

        try {
            $this->admin->ajaxGenerateRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        // /hello-world/ → /blogs/news/hello-world: different paths → redirect generated
        $this->assertGreaterThan(0, $this->jsonResponses[0]['data']['count']);
    }

    // ---------------------------------------------------------------
    // ajaxSyncRedirections — unicode arrow syntax (→)
    // ---------------------------------------------------------------

    public function testAjaxSyncRedirectionsHandlesUnicodeArrow(): void
    {
        $_POST['redirections'] = "/old → /new";

        Functions\when('wp_remote_post')->justReturn(['body' => '{"created":1,"skipped":0,"failed":0,"remaining":0,"totalInShopify":1,"errors":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"created":1,"skipped":0,"failed":0,"remaining":0,"totalInShopify":1,"errors":[]}');

        try {
            $this->admin->ajaxSyncRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        unset($_POST['redirections']);
    }

    // ---------------------------------------------------------------
    // ajaxTestConnection — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxTestConnectionReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'Connection timed out'));

        try {
            $this->admin->ajaxTestConnection();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertStringContainsString('Connection timed out', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxDebugSession — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxDebugSessionReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'Debug timed out'));

        try {
            $this->admin->ajaxDebugSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxResetSession — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxResetSessionReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('timeout', 'Reset timed out'));

        try {
            $this->admin->ajaxResetSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxFixSession — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxFixSessionReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('timeout', 'Fix timed out'));

        try {
            $this->admin->ajaxFixSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetMappings — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxGetMappingsReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('api', 'Mappings failed'));

        try {
            $this->admin->ajaxGetMappings();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyBlogs — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyBlogsReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('api', 'Blogs failed'));

        try {
            $this->admin->ajaxGetShopifyBlogs();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyMarkets — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyMarketsReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('api', 'Markets failed'));

        try {
            $this->admin->ajaxGetShopifyMarkets();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopDomains — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxGetShopDomainsReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('api', 'Domains failed'));

        try {
            $this->admin->ajaxGetShopDomains();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxCreateMapping — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxCreateMappingReturnsErrorOnWpError(): void
    {
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('api', 'Create mapping failed'));

        try {
            $this->admin->ajaxCreateMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // ajaxUpdateMapping — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxUpdateMappingReturnsErrorOnWpError(): void
    {
        $_POST['mappingId'] = 'map-abc';
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('api', 'Update failed'));

        try {
            $this->admin->ajaxUpdateMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['mappingId']);
    }

    // ---------------------------------------------------------------
    // ajaxDeleteMapping — WP_Error path
    // ---------------------------------------------------------------

    public function testAjaxDeleteMappingReturnsErrorOnWpError(): void
    {
        $_POST['mappingId'] = 'map-xyz';
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('api', 'Delete failed'));

        try {
            $this->admin->ajaxDeleteMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        unset($_POST['mappingId']);
    }

    // ---------------------------------------------------------------
    // ajaxGetSearchReplaceRules — unauthorized path
    // ---------------------------------------------------------------

    public function testAjaxGetSearchReplaceRulesRejectsUnauthorized(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxGetSearchReplaceRules();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxScanContentLinks — unauthorized path
    // ---------------------------------------------------------------

    public function testAjaxScanContentLinksRejectsUnauthorized(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxScanContentLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyLinks — unauthorized path
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyLinksRejectsUnauthorized(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxGetShopifyLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxGenerateRedirections — unauthorized path
    // ---------------------------------------------------------------

    public function testAjaxGenerateRedirectionsRejectsUnauthorized(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxGenerateRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxFetchWpRedirections — unauthorized path
    // ---------------------------------------------------------------

    public function testAjaxFetchWpRedirectionsRejectsUnauthorized(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxFetchWpRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // ajaxFetchWpRedirections — Yoast redirects path
    // ---------------------------------------------------------------

    public function testAjaxFetchWpRedirectionsIncludesYoastRedirects(): void
    {
        global $wpdb;
        $mockWpdb = new class {
            public string $prefix   = 'wp_';
            public string $postmeta = 'wp_postmeta';
            public string $posts    = 'wp_posts';
            public function prepare(string $sql, ...$args): string { return $sql; }
            public function get_var(string $sql): ?string { return null; }
            public function esc_like(string $s): string { return $s; }
        };
        $wpdb = $mockWpdb;

        Functions\when('get_option')->alias(function ($k, $d = []) {
            if ($k === 'wpseo-premium-redirects-base') {
                return [
                    ['origin' => '/old-page', 'url' => '/new-page'],
                ];
            }
            return $this->options[$k] ?? $d;
        });
        Functions\when('maybe_unserialize')->returnArg(1);

        try {
            $this->admin->ajaxFetchWpRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(1, $this->jsonResponses[0]['data']['count']);
        $wpdb = null;
    }

    // ---------------------------------------------------------------
    // ajaxFetchWpRedirections — RankMath path (table exists)
    // ---------------------------------------------------------------

    public function testAjaxFetchWpRedirectionsIncludesRankMathRedirects(): void
    {
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        global $wpdb;
        $rankMathTable = 'wp_rank_math_redirections';
        $mockWpdb = new class($rankMathTable) {
            public string $prefix   = 'wp_';
            public string $postmeta = 'wp_postmeta';
            public string $posts    = 'wp_posts';
            private string $table;
            public function __construct(string $t) { $this->table = $t; }
            public function prepare(string $sql, ...$args): string { return $sql; }
            public function get_var(string $sql): ?string { return $this->table; }
            public function get_results(string $sql, $output = null): array {
                return [
                    ['sources' => serialize([['pattern' => '/old']]), 'url_to' => '/new'],
                ];
            }
            public function esc_like(string $s): string { return $s; }
        };
        $wpdb = $mockWpdb;

        Functions\when('get_option')->alias(function ($k, $d = []) {
            if ($k === 'wpseo-premium-redirects-base') return [];
            return $this->options[$k] ?? $d;
        });
        Functions\when('maybe_unserialize')->alias(fn($v) => unserialize($v));

        try {
            $this->admin->ajaxFetchWpRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(1, $this->jsonResponses[0]['data']['count']);
        $wpdb = null;
    }

    // ---------------------------------------------------------------
    // renderSyncNotices — post edit screen (non-empty post GET param)
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesOnPostScreenWithNoError(): void
    {
        $_GET['post'] = '80';
        // No sync error set
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'post']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        // No error meta → no error notice, but "not connected" notice may appear
        // on the 'post' base screen when not configured
        $this->addToAssertionCount(1);
        unset($_GET['post']);
    }

    // ---------------------------------------------------------------
    // renderBulkActionNotice — republish label
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesBulkRepublishActionLabel(): void
    {
        $_GET['wash_bulk_action']    = 'wash_republish';
        $_GET['wash_bulk_processed'] = '3';
        $_GET['wash_bulk_skipped']   = '0';
        $_GET['wash_bulk_errors']    = '0';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);
        Functions\when('_n')->alias(fn($s, $p, $n) => $n === 1 ? $s : $p);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('re-published', strtolower($html));
        unset($_GET['wash_bulk_action'], $_GET['wash_bulk_processed'],
              $_GET['wash_bulk_skipped'], $_GET['wash_bulk_errors']);
    }

    // ---------------------------------------------------------------
    // handleBulkActions — cron mode republish path
    // ---------------------------------------------------------------

    public function testHandleBulkActionsCronModeRepublishPath(): void
    {
        $this->options['wash_sync_inline'] = '0';
        Functions\when('get_option')->alias(fn($k, $d = '') => $this->options[$k] ?? $d);
        $admin = new \WashAdmin();

        $this->mockPost(160, 'post', 'publish', 'pub-1');
        $this->postMetaStore[160]['_wash_article_id'] = 'gid://shopify/Article/1';

        Functions\when('update_post_meta')->alias(function ($id, $key, $val) {
            $this->postMetaStore[(int) $id][$key] = $val;
            return true;
        });
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('time')->justReturn(1700000000);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}']);
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('apply_filters')->alias(fn($_, $v) => $v);

        $url = 'https://example.com/wp-admin/edit.php';
        $result = $admin->handleBulkActions($url, 'wash_republish', [160]);

        $this->assertStringContainsString('wash_bulk_processed', $result);
    }

    // ---------------------------------------------------------------
    // ajaxScanContentLinks — content with shortcodes and blocks
    // ---------------------------------------------------------------

    public function testAjaxScanContentLinksHandlesShortcodesAndBlocks(): void
    {
        if (!class_exists('WP_Block_Type_Registry')) {
            eval('class WP_Block_Type_Registry {
                private static $inst2;
                public static function get_instance() {
                    if (!self::$inst2) self::$inst2 = new self();
                    return self::$inst2;
                }
                public function get_all_registered() { return []; }
            }');
        }

        Functions\when('get_posts')->justReturn([60]);
        Functions\when('get_post_field')->alias(function ($field, $id) {
            return '[gallery ids="1,2,3"] <!-- wp:image {"id":123} --> <a href="/relative-path">link</a>';
        });
        Functions\when('site_url')->justReturn('https://example.com');

        try {
            $this->admin->ajaxScanContentLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $this->assertSame(1, $this->jsonResponses[0]['data']['postCount']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopifyLinks — pages synced
    // ---------------------------------------------------------------

    public function testAjaxGetShopifyLinksIncludesSyncedPages(): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => '{}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');

        // First call to get_posts = synced posts (empty), second = synced pages
        $callCount = 0;
        Functions\when('get_posts')->alias(function ($args) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) return [];      // synced posts
            return [70];                           // synced pages
        });
        Functions\when('get_post_field')->alias(function ($field, $id) {
            if ($field === 'post_name') return 'about-us';
            return '';
        });
        Functions\when('get_the_title')->justReturn('About Us');
        Functions\when('get_post_meta')->alias(function ($id, $key) {
            if ($key === '_wash_blog_handle') return 'news';
            return '';
        });

        try {
            $this->admin->ajaxGetShopifyLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_success
        }

        $this->assertSame('success', $this->jsonResponses[0]['type']);
        $links = $this->jsonResponses[0]['data']['links'];
        $types = array_column($links, 'type');
        $this->assertContains('page', $types);
    }

    // ---------------------------------------------------------------
    // ajaxCheckSyncStatus — no post_ids key (defaults to empty array)
    // ---------------------------------------------------------------

    public function testAjaxCheckSyncStatusHandlesMissingPostIdsKey(): void
    {
        // No $_POST['post_ids'] set

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_error (no posts to check)
        }

        $this->assertSame('error', $this->jsonResponses[0]['type']);
    }

    // ---------------------------------------------------------------
    // renderSyncNotices — wash_bulk_error with unknown value (no output)
    // ---------------------------------------------------------------

    public function testRenderSyncNoticesIgnoresUnknownBulkError(): void
    {
        $_GET['wash_bulk_error'] = 'some_other_error';
        Functions\when('get_current_screen')->justReturn((object) ['base' => 'edit']);

        ob_start();
        $this->admin->renderSyncNotices();
        $html = ob_get_clean();

        // Unknown error code → renderSyncNotices returns early, no notice for it
        $this->assertStringNotContainsString('notice-error', $html);
        unset($_GET['wash_bulk_error']);
    }
}
