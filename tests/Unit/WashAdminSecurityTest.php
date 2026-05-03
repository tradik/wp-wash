<?php
/**
 * Security tests for WashAdmin AJAX handlers.
 * Validates: CSRF nonce verification, authorization (capability checks),
 * and proper error responses when security checks fail.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

class WashAdminSecurityTest extends TestCase
{
    private \WashAdmin $admin;
    private array $jsonResponses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                'wash_post_types' => 'post,page',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('__')->alias(fn($text) => $text);
        Functions\when('esc_html__')->alias(fn($text) => $text);

        $this->jsonResponses = [];

        Functions\when('wp_send_json_error')->alias(function ($msg = '') {
            $this->jsonResponses[] = ['type' => 'error', 'data' => $msg];
            throw new \RuntimeException('wp_send_json_error');
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->jsonResponses[] = ['type' => 'success', 'data' => $data];
            throw new \RuntimeException('wp_send_json_success');
        });

        $this->admin = new \WashAdmin();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // CSRF Protection: Nonce Verification
    // ---------------------------------------------------------------

    public function testTestConnectionRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->admin->ajaxTestConnection();
    }

    public function testDisconnectRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function ($action, $field) {
            if ($action !== 'wash_disconnect') {
                throw new \RuntimeException('wrong_nonce_action');
            }
            throw new \RuntimeException('nonce_failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->admin->ajaxDisconnect();
    }

    public function testResetToDefaultsRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->admin->ajaxResetToDefaults();
    }

    public function testGetMappingsRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function ($action) {
            if ($action === 'wash_mappings') {
                throw new \RuntimeException('nonce_failed');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->admin->ajaxGetMappings();
    }

    public function testSaveSearchReplaceRulesRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->admin->ajaxSaveSearchReplaceRules();
    }

    public function testManualSyncRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function () {
            throw new \RuntimeException('nonce_failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->admin->ajaxManualSync();
    }

    public function testCheckSyncStatusRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function ($action) {
            if ($action === 'wash_sync_status') {
                throw new \RuntimeException('nonce_failed');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->admin->ajaxCheckSyncStatus();
    }

    // ---------------------------------------------------------------
    // Authorization: Capability Checks (manage_options)
    // ---------------------------------------------------------------

    public function testTestConnectionRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxTestConnection();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testDisconnectRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxDisconnect();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testDebugSessionRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxDebugSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testResetSessionRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxResetSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testFixSessionRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxFixSession();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testResetToDefaultsRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxResetToDefaults();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testSyncSettingsFromShopifyRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxSyncSettingsFromShopify();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testCreateMappingRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxCreateMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testUpdateMappingRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxUpdateMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testDeleteMappingRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxDeleteMapping();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testDetectMetaFieldsRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxDetectMetaFields();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testSaveMetaFieldConfigRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxSaveMetaFieldConfig();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testGetShopifyRedirectionsRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxGetShopifyRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testSyncRedirectionsRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxSyncRedirections();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testSaveSearchReplaceRulesRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxSaveSearchReplaceRules();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testScanContentLinksRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxScanContentLinks();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // Authorization: edit_posts capability (lower privilege handlers)
    // ---------------------------------------------------------------

    public function testManualSyncRejectsUserWithoutEditPosts(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxManualSync();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testCheckSyncStatusRejectsUserWithoutEditPosts(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    // ---------------------------------------------------------------
    // handleManualSync: Nonce + capability via wp_verify_nonce
    // ---------------------------------------------------------------

    public function testHandleManualSyncIgnoresNonSyncAction(): void
    {
        $_GET['action'] = 'something_else';

        // Should return early without any side effects
        $this->admin->handleManualSync();

        // No exceptions = method returned safely
        $this->assertTrue(true);

        unset($_GET['action']);
    }

    public function testHandleManualSyncRejectsInvalidNonce(): void
    {
        $_GET['action'] = 'wash_sync';
        $_GET['post_id'] = '42';
        $_GET['_wpnonce'] = 'invalid_nonce';

        Functions\when('absint')->alias(fn($v) => abs(intval($v)));
        Functions\when('wp_verify_nonce')->justReturn(false);
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die: ' . $msg);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die: Security check failed');
        $this->admin->handleManualSync();

        unset($_GET['action'], $_GET['post_id'], $_GET['_wpnonce']);
    }

    public function testHandleManualSyncRejectsUnauthorizedUser(): void
    {
        $_GET['action'] = 'wash_sync';
        $_GET['post_id'] = '42';
        $_GET['_wpnonce'] = 'valid_nonce';

        Functions\when('absint')->alias(fn($v) => abs(intval($v)));
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('wp_die: ' . $msg);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die: You do not have permission to sync this post');
        $this->admin->handleManualSync();

        unset($_GET['action'], $_GET['post_id'], $_GET['_wpnonce']);
    }

    // ---------------------------------------------------------------
    // Settings Page: Capability check before rendering
    // ---------------------------------------------------------------

    public function testRenderSettingsPageRejectsUnauthorizedUser(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        $this->admin->renderSettingsPage();
        $output = ob_get_clean();

        // Should return early without rendering anything
        $this->assertEmpty($output);
    }

    // ---------------------------------------------------------------
    // handleCallback: Capability check + sanitization
    // ---------------------------------------------------------------

    public function testHandleCallbackRejectsUnauthorizedUser(): void
    {
        $_GET['page'] = 'wash';
        $_GET['wash_callback'] = '1';
        $_GET['api_key'] = 'some-key';

        Functions\when('current_user_can')->justReturn(false);

        // Should return early without processing
        $this->admin->handleCallback();
        // No redirect/update = safe
        $this->assertTrue(true);

        unset($_GET['page'], $_GET['wash_callback'], $_GET['api_key']);
    }

    // ---------------------------------------------------------------
    // Nonce action specificity: different actions use different nonces
    // ---------------------------------------------------------------

    public function testDisconnectUsesSpecificNonceAction(): void
    {
        $nonceActionUsed = null;
        Functions\when('check_ajax_referer')->alias(function ($action) use (&$nonceActionUsed) {
            $nonceActionUsed = $action;
            return true;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);

        try {
            $this->admin->ajaxDisconnect();
        } catch (\RuntimeException $e) {
            // wp_send_json_success throws
        }

        $this->assertSame('wash_disconnect', $nonceActionUsed);
    }

    public function testRedirectionsHandlersUseSpecificNonceAction(): void
    {
        $nonceActionUsed = null;
        Functions\when('check_ajax_referer')->alias(function ($action) use (&$nonceActionUsed) {
            $nonceActionUsed = $action;
            return true;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);

        $_POST['enabled'] = '1';
        Functions\when('update_option')->justReturn(true);

        try {
            $this->admin->ajaxSaveRedirectionsSettings();
        } catch (\RuntimeException $e) {
            // wp_send_json_success throws
        }

        $this->assertSame('wash_redirections', $nonceActionUsed);

        unset($_POST['enabled']);
    }

    public function testMappingsHandlersUseSpecificNonceAction(): void
    {
        $nonceActionUsed = null;
        Functions\when('check_ajax_referer')->alias(function ($action) use (&$nonceActionUsed) {
            $nonceActionUsed = $action;
            return true;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn(['body' => '{"mappings":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"mappings":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        try {
            $this->admin->ajaxGetMappings();
        } catch (\RuntimeException $e) {
            // wp_send_json_success/error
        }

        $this->assertSame('wash_mappings', $nonceActionUsed);
    }

    public function testSyncStatusUsesSpecificNonceAction(): void
    {
        $nonceActionUsed = null;
        Functions\when('check_ajax_referer')->alias(function ($action) use (&$nonceActionUsed) {
            $nonceActionUsed = $action;
            return true;
        });
        Functions\when('current_user_can')->justReturn(true);

        $_POST['post_ids'] = [];

        try {
            $this->admin->ajaxCheckSyncStatus();
        } catch (\RuntimeException $e) {
            // wp_send_json_error
        }

        $this->assertSame('wash_sync_status', $nonceActionUsed);

        unset($_POST['post_ids']);
    }

    // ---------------------------------------------------------------
    // ajaxGetShopLocales — security gating
    // ---------------------------------------------------------------

    public function testGetShopLocalesRejectsInvalidNonce(): void
    {
        Functions\when('check_ajax_referer')->alias(function ($action) {
            if ($action === 'wash_mappings') {
                throw new \RuntimeException('nonce_failed');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonce_failed');
        $this->admin->ajaxGetShopLocales();
    }

    public function testGetShopLocalesRejectsUnauthorizedUser(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        try {
            $this->admin->ajaxGetShopLocales();
        } catch (\RuntimeException $e) {
            // wp_send_json_error throws
        }

        $this->assertNotEmpty($this->jsonResponses);
        $this->assertSame('error', $this->jsonResponses[0]['type']);
        $this->assertSame('Unauthorized', $this->jsonResponses[0]['data']);
    }

    public function testGetShopLocalesUsesSpecificNonceAction(): void
    {
        $nonceActionUsed = null;
        Functions\when('check_ajax_referer')->alias(function ($action) use (&$nonceActionUsed) {
            $nonceActionUsed = $action;
            return true;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn(['body' => '{"locales":[]}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"locales":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        try {
            $this->admin->ajaxGetShopLocales();
        } catch (\RuntimeException $e) {
            // wp_send_json_success/error
        }

        $this->assertSame('wash_mappings', $nonceActionUsed);
    }
}
