<?php
/**
 * WashConnectCode — connects a store from a one-time code generated in Shopify (#285).
 *
 * wp_send_json_* end the request; they are mocked to throw WashJsonResponse so
 * the tests can assert the payload and the side effects on WashConnection.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-connections-admin.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync-meta.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-connect-code.php';

class WashJsonResponse extends \RuntimeException
{
    public bool $success;
    /** @var mixed */
    public $data;
    public ?int $status;

    public function __construct(bool $success, $data, ?int $status = null)
    {
        parent::__construct($success ? 'success' : 'error');
        $this->success = $success;
        $this->data = $data;
        $this->status = $status;
    }
}

class WashConnectCodeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [];
    /** @var array<int,array<string,mixed>> */
    private array $requests = [];
    /** @var array<int,array{0:int,1:string}> queued [status, body] responses */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
        $this->options = ['wash_consent_accepted' => '1'];
        $this->requests = [];
        $this->responses = [];
        $_POST = ['code' => 'abcd-efgh-jkmn', 'mode' => 'primary', 'nonce' => 'n'];

        Functions\when('get_option')->alias(fn($k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->options[$k] = $v;
            return true;
        });
        Functions\when('__')->returnArg();
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('sanitize_key')->alias(fn($k) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)));
        Functions\when('wp_generate_password')->justReturn('abc123');
        Functions\when('get_site_url')->justReturn('https://blog.example.com/wp');
        Functions\when('get_bloginfo')->justReturn('My Blog');
        Functions\when('is_wp_error')->alias(fn($thing) => $thing instanceof \WP_Error);
        Functions\when('get_transient')->justReturn(['plan' => 'BUSINESS']);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('admin_url')->alias(fn($p = '') => 'http://wp/wp-admin/' . $p);
        Functions\when('add_query_arg')->alias(fn($k, $v, $url) => $url . '&' . $k . '=' . $v);
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('wp_remote_post')->alias(function ($url, $args) {
            $this->requests[] = ['url' => $url] + $args;
            $next = array_shift($this->responses) ?? [200, '{}'];
            return ['status' => $next[0], 'body' => $next[1]];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn($r) => $r['status']);
        Functions\when('wp_remote_retrieve_body')->alias(fn($r) => $r['body']);
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new WashJsonResponse(true, $data);
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null, $status = null) {
            throw new WashJsonResponse(false, $data, $status);
        });
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private function redeemReturns(array $payload): void
    {
        $this->responses[] = [200, json_encode(['success' => true] + $payload)];
    }

    private function redeem(): WashJsonResponse
    {
        try {
            (new \WashConnectCode())->ajaxRedeem();
        } catch (WashJsonResponse $response) {
            return $response;
        }
        $this->fail('ajaxRedeem() did not send a JSON response');
    }

    private function credentials(array $overrides = []): array
    {
        return $overrides + ['apiKey' => 'wp4s_new', 'store' => 'shop', 'isReauth' => false, 'hasBackup' => false];
    }

    public function testInitRegistersTheAjaxHandler(): void
    {
        Functions\expect('add_action')->once()->with('wp_ajax_wash_redeem_connect_code', \Mockery::type('array'));
        (new \WashConnectCode())->init();
        $this->addToAssertionCount(1);
    }

    public function testPrimaryRedeemStoresCredentialsAndRedirects(): void
    {
        $this->redeemReturns($this->credentials());

        $response = $this->redeem();

        $this->assertTrue($response->success);
        $this->assertSame('http://wp/wp-admin/options-general.php?page=wash&connected=1', $response->data['redirect']);
        $this->assertSame('wp4s_new', $this->options['wash_api_key']);
        $this->assertSame('shop', $this->options['wash_shopify_store']);
        $this->assertSame(
            ['code' => 'abcd-efgh-jkmn', 'siteUrl' => 'https://blog.example.com', 'siteName' => 'My Blog'],
            json_decode($this->requests[0]['body'], true)
        );
    }

    /** Record every bulk meta delete so a test can see what was cleared. */
    private function trackMetaDeletes(): array
    {
        $deleted = new \ArrayObject();
        Functions\when('delete_post_meta_by_key')->alias(function ($key) use ($deleted) {
            $deleted[] = $key;
            return true;
        });
        Functions\when('delete_transient')->justReturn(true);
        return [$deleted];
    }

    public function testConnectingADifferentStoreClearsTheOldStoresSyncState(): void
    {
        $this->options['wash_shopify_store'] = 'old-shop.myshopify.com';
        [$deleted] = $this->trackMetaDeletes();
        $this->redeemReturns($this->credentials(['store' => 'new-shop']));

        $this->assertTrue($this->redeem()->success);

        $this->assertSame('new-shop', $this->options['wash_shopify_store']);
        $this->assertContains('_wash_article_id', (array) $deleted);
        $this->assertContains('_wash_synced_at', (array) $deleted);
        $this->assertCount(count(\WashSyncMeta::KEYS), $deleted);
    }

    public function testReconnectingTheSameStoreKeepsSyncState(): void
    {
        $this->options['wash_shopify_store'] = 'shop.myshopify.com';
        [$deleted] = $this->trackMetaDeletes();
        $this->redeemReturns($this->credentials(['store' => 'shop']));

        $this->assertTrue($this->redeem()->success);

        $this->assertCount(0, $deleted);
    }

    public function testSiteOriginKeepsANonDefaultPort(): void
    {
        Functions\when('get_site_url')->justReturn('http://localhost:8080/');
        $this->redeemReturns($this->credentials());

        $this->redeem();

        $this->assertSame('http://localhost:8080', json_decode($this->requests[0]['body'], true)['siteUrl']);
    }

    public function testReconnectWithBackupRestoresSettings(): void
    {
        $this->redeemReturns($this->credentials(['isReauth' => true, 'hasBackup' => true]));
        Functions\when('wp_remote_get')->alias(function ($url, $args) {
            $this->requests[] = ['url' => $url] + $args;
            return ['status' => 200, 'body' => '{"success":true,"settings":{"syncOnPublish":"1"}}'];
        });

        $response = $this->redeem();

        $this->assertStringEndsWith('connected=1&restored=1', $response->data['redirect']);
        $backupCall = $this->requests[1];
        $this->assertStringEndsWith('/api/settings-sync', $backupCall['url']);
        $this->assertSame('wp4s_new', $backupCall['headers']['X-Wash-Key'], 'backup must be fetched with the new key');
        $this->assertSame('1', $this->options['wash_sync_on_publish']);
    }

    public function testReconnectWithoutBackupSaysNothingWasRestored(): void
    {
        $this->redeemReturns($this->credentials(['isReauth' => true]));

        $response = $this->redeem();

        $this->assertStringEndsWith('connected=1&restored=0', $response->data['redirect']);
        $this->assertCount(1, $this->requests);
    }

    public function testAddModeCreatesAnAdditionalConnection(): void
    {
        $_POST += ['appUrl' => 'https://app.custom', 'httpUser' => 'u', 'httpPass' => 'p'];
        $_POST['mode'] = 'add';
        $this->redeemReturns($this->credentials(['store' => 'second']));

        $response = $this->redeem();

        $this->assertTrue($response->success);
        $this->assertSame('https://app.custom/api/connect-code', $this->requests[0]['url']);
        $added = array_values(array_filter(\WashConnection::all(), fn($c) => empty($c['isPrimary'])));
        $this->assertCount(1, $added);
        $this->assertSame('second', $added[0]['store']);
        $this->assertSame('wp4s_new', $added[0]['apiKey']);
        $this->assertSame('https://app.custom', $added[0]['appUrl']);
        $this->assertStringContainsString('page=wash-conn-', $response->data['redirect']);
        $this->assertStringEndsWith('&added=1', $response->data['redirect']);
        $this->assertArrayNotHasKey('wash_api_key', $this->options, 'primary credentials must be untouched');
    }

    public function testAddModeRejectedWhenPlanDoesNotQualify(): void
    {
        $_POST['mode'] = 'add';
        Functions\when('get_transient')->justReturn(['plan' => 'FREE']);

        $response = $this->redeem();

        $this->assertFalse($response->success);
        $this->assertSame([], $this->requests);
    }

    public function testShowsTheAppsErrorWhenTheCodeIsRejected(): void
    {
        $this->responses[] = [400, '{"success":false,"error":"This connect code is invalid, expired or already used."}'];

        $response = $this->redeem();

        $this->assertFalse($response->success);
        $this->assertStringContainsString('expired', $response->data['message']);
        $this->assertArrayNotHasKey('wash_api_key', $this->options);
    }

    public function testRequiresACode(): void
    {
        $_POST['code'] = '   ';

        $response = $this->redeem();

        $this->assertFalse($response->success);
        $this->assertSame([], $this->requests);
    }

    public function testRequiresRecordedConsent(): void
    {
        $this->options['wash_consent_accepted'] = '0';

        $response = $this->redeem();

        $this->assertFalse($response->success);
        $this->assertSame([], $this->requests);
    }

    public function testVerifiesTheNonceBeforeRedeeming(): void
    {
        $checked = [];
        Functions\when('check_ajax_referer')->alias(function ($action, $field) use (&$checked) {
            $checked[] = [$action, $field];
            throw new WashJsonResponse(false, ['message' => 'bad nonce'], 403);
        });

        $response = $this->redeem();

        $this->assertSame([['wash_connect_code', 'nonce']], $checked);
        $this->assertSame(403, $response->status);
        $this->assertSame([], $this->requests);
    }

    public function testReconnectStillSucceedsWhenTheBackupCannotBeFetched(): void
    {
        $this->redeemReturns($this->credentials(['isReauth' => true, 'hasBackup' => true]));
        Functions\when('wp_remote_get')->justReturn(['status' => 500, 'body' => '{"error":"boom"}']);

        $response = $this->redeem();

        $this->assertTrue($response->success);
        $this->assertStringEndsWith('connected=1&restored=0', $response->data['redirect']);
        $this->assertSame('wp4s_new', $this->options['wash_api_key']);
    }

    public function testRejectsUsersWhoCannotManageOptions(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $response = $this->redeem();

        $this->assertFalse($response->success);
        $this->assertSame(403, $response->status);
        $this->assertSame([], $this->requests);
    }
}
