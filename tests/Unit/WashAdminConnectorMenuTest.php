<?php
/**
 * WashAdmin: the "Wash Connector" settings menu (#386), plus the capability
 * refusals, Redirection-plugin import and embed-provider scan that no other
 * test reached.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-admin.php';

if (!class_exists('Red_Item')) {
    /** Minimal Redirection-plugin item; tests set Red_Item::$items. */
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
    class_alias(RedItemStub::class, 'Red_Item');
}

/** Stand-in for the Redirection plugin's Red_Item. */
class RedItemStub
{
    /** @var RedItemStub[] */
    public static array $items = [];

    public function __construct(private bool $enabled, private string $type, private string $url, private array $data)
    {
    }

    public static function get_all(): array
    {
        return self::$items;
    }

    public function is_enabled(): bool { return $this->enabled; }
    public function get_action_type(): string { return $this->type; }
    public function get_url(): string { return $this->url; }
    public function get_action_data(): array { return $this->data; }
}

class WashAdminConnectorMenuTest extends TestCase
{
    private \WashAdmin $admin;
    private array $json = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
        Functions\when('__')->alias(fn($t) => $t);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->alias(fn($k, $d = '') => $d);
        Functions\when('wp_send_json_error')->alias(function ($data = '') {
            $this->json[] = ['error', $data];
            throw new \RuntimeException('json');
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->json[] = ['success', $data];
            throw new \RuntimeException('json');
        });
        $this->json = [];
        RedItemStub::$items = [];
        $this->admin = new \WashAdmin();
    }

    protected function tearDown(): void
    {
        RedItemStub::$items = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private function call(string $method): array
    {
        try {
            $this->admin->$method();
        } catch (\RuntimeException $e) {
            // wp_send_json_* ends the request
        }
        return $this->json[0];
    }

    public function testSettingsMenuAndPageTitleSayWashConnector(): void
    {
        $args = null;
        Functions\when('add_options_page')->alias(function (...$a) use (&$args) {
            $args = $a;
        });

        $this->admin->addMenuPage();

        $this->assertSame(['Wash Connector', 'Wash Connector', 'manage_options', 'wash'], array_slice($args, 0, 4));
    }

    public static function guardedHandlers(): array
    {
        return array_map(fn($m) => [$m], [
            'ajaxGetMappings', 'ajaxGetShopifyBlogs', 'ajaxGetShopifyMarkets',
            'ajaxGetShopDomains', 'ajaxReorderMappings', 'ajaxSaveRedirectionsSettings',
        ]);
    }

    #[DataProvider('guardedHandlers')]
    public function testHandlerRefusesUsersWithoutManageOptions(string $method): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->assertSame(['error', 'Unauthorized'], $this->call($method));
    }

    public function testShopLocalesReportsTheApiError(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_request_failed', 'App unreachable'));
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('http_request_failed', 'App unreachable'));
        Functions\when('is_wp_error')->alias(fn($v) => $v instanceof \WP_Error);
        Functions\when('get_site_url')->justReturn('https://example.com');

        [$type, $message] = $this->call('ajaxGetShopLocales');

        $this->assertSame('error', $type);
        $this->assertNotSame('', $message);
    }

    public function testRedirectionPluginUrlRedirectsAreImported(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function prepare(string $sql, ...$args): string { return $sql; }
            public function esc_like(string $s): string { return $s; }
            public function get_var(string $sql): ?string { return null; }
        };
        RedItemStub::$items = [
            new RedItemStub(true, 'url', '/old', ['url' => '/new']),
            new RedItemStub(false, 'url', '/off', ['url' => '/x']),
            new RedItemStub(true, 'random', '/rnd', []),
        ];

        [$type, $data] = $this->call('ajaxFetchWpRedirections');
        $wpdb = null;

        $this->assertSame('success', $type);
        $this->assertSame([['from' => '/old', 'to' => '/new', 'source' => 'Redirection']], $data['redirections']);
    }

    public function testContentScanCountsEmbedsPerProvider(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_posts')->justReturn([7]);
        Functions\when('get_post_field')->justReturn(
            '<!-- wp:embed {"url":"https://youtu.be/x","providerNameSlug":"youtube"} --><!-- /wp:embed -->'
            . '<!-- wp:embed {"url":"https://example.com/x"} --><!-- /wp:embed -->'
        );
        Functions\when('site_url')->alias(fn($p = '') => 'https://example.com' . $p);

        [$type, $data] = $this->call('ajaxScanContentLinks');

        $counts = array_column($data['blocks'], 'count', 'name');
        $this->assertSame('success', $type);
        $this->assertSame(2, $counts['wp:embed']);
        $this->assertSame(1, $counts['wp:embed/youtube']);
    }
}
