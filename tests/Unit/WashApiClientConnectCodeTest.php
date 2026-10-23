<?php
/**
 * WashApiClient::redeemConnectCode() — exchanges a one-time connect code for
 * the connection's credentials (#285). It runs before any API key exists, so it
 * must not require or send X-Wash-Key.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-connection.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-context.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';

class WashApiClientConnectCodeTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private ?array $sent = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->sent = null;
        Functions\when('get_site_url')->justReturn('https://blog.example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('wp_remote_post')->alias(function ($url, $args) {
            $this->sent = ['url' => $url] + $args;
            return ['response' => ['code' => 200]];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function respond(int $code, string $body): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
    }

    private function client(array $connection = []): \WashApiClient
    {
        return new \WashApiClient($connection + ['appUrl' => 'https://app.example.com']);
    }

    public function testPostsCodeAndSiteWithoutApiKey(): void
    {
        $this->respond(200, '{"success":true,"apiKey":"wp4s_k","store":"shop"}');

        $result = $this->client()->redeemConnectCode('ABCD-EFGH-JKMN', 'https://blog.example.com', 'My Blog');

        $this->assertSame('wp4s_k', $result['apiKey']);
        $this->assertSame('shop', $result['store']);
        $this->assertSame('https://app.example.com/api/connect-code', $this->sent['url']);
        $this->assertSame(
            ['code' => 'ABCD-EFGH-JKMN', 'siteUrl' => 'https://blog.example.com', 'siteName' => 'My Blog'],
            json_decode($this->sent['body'], true)
        );
        $this->assertArrayNotHasKey('X-Wash-Key', $this->sent['headers']);
        $this->assertSame('application/json', $this->sent['headers']['Content-Type']);
    }

    public function testSendsHttpBasicAuthWhenConfigured(): void
    {
        $this->respond(200, '{"success":true,"apiKey":"k","store":"s"}');

        $this->client(['httpUser' => 'u', 'httpPass' => 'p'])->redeemConnectCode('ABCD-EFGH-JKMN', 'https://b', 'B');

        $this->assertSame('Basic ' . base64_encode('u:p'), $this->sent['headers']['Authorization']);
    }

    public function testReturnsTheAppsErrorMessage(): void
    {
        $this->respond(400, '{"success":false,"error":"This connect code is invalid, expired or already used."}');

        $result = $this->client()->redeemConnectCode('ABCD-EFGH-JKMN', 'https://b', 'B');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('invalid, expired or already used', $result->get_error_message());
    }

    public function testRejectsASuccessWithoutCredentials(): void
    {
        $this->respond(200, '{"success":true}');

        $result = $this->client()->redeemConnectCode('ABCD-EFGH-JKMN', 'https://b', 'B');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wash_connect_code', $result->get_error_code());
    }
}
