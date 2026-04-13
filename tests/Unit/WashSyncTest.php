<?php
/** Unit tests for WashSync: resolveSeoVariables, getMappedAuthorName, getPrimaryCategory. */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class WashSyncTest extends TestCase
{
    private $sync;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Mock WP functions needed by WashApiClient constructor
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_shopify_store' => 'test-store',
                'wash_api_key' => 'test-key',
                'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);

        $this->sync = new \WashSync();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->sync, ...$args);
    }

    /** Set up mocks for resolveSeoVariables with given post title, sep, sitename. */
    private function mockSeoContext(string $title, string $sep = '-', string $site = 'Blog'): void
    {
        Functions\when('get_post')->justReturn((object) ['post_title' => $title, 'post_author' => 1]);
        Functions\when('apply_filters')->justReturn($sep);
        Functions\when('get_bloginfo')->justReturn($site);
        Functions\when('get_the_excerpt')->justReturn('');
        Functions\when('get_the_date')->justReturn('2026-01-01');
        Functions\when('get_the_author_meta')->justReturn('Author');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('get_term')->justReturn(null);
    }

    // -- resolveSeoVariables --

    public function testResolveSeoVariablesReplacesPercentTitle(): void
    {
        $this->mockSeoContext('Hello World', '-', 'My Blog');
        $this->assertSame('Hello World | Blog', $this->invokePrivate('resolveSeoVariables', ['%title% | Blog', 42]));
    }

    public function testResolveSeoVariablesReplacesSepAndSitename(): void
    {
        $this->mockSeoContext('Post', '|', 'MySite');
        $this->assertSame('Post | MySite', $this->invokePrivate('resolveSeoVariables', ['%title% %sep% %sitename%', 1]));
    }

    public function testResolveSeoVariablesHandlesYoastDoublePercent(): void
    {
        $this->mockSeoContext('Yoast Post');
        $this->assertSame('Yoast Post - Blog', $this->invokePrivate('resolveSeoVariables', ['%%title%% %%sep%% %%sitename%%', 1]));
    }

    public function testResolveSeoVariablesCleansUnresolvedVars(): void
    {
        $this->mockSeoContext('Clean');
        $this->assertSame('Clean end', $this->invokePrivate('resolveSeoVariables', ['%title% %unknown_var% end', 1]));
    }

    public function testResolveSeoVariablesCollapsesMultipleSpaces(): void
    {
        $this->mockSeoContext('Title');
        $this->assertSame('Title end', $this->invokePrivate('resolveSeoVariables', ['%title%  %foo%  %bar%  end', 1]));
    }

    // ---------------------------------------------------------------
    // getMappedAuthorName
    // ---------------------------------------------------------------

    public function testGetMappedAuthorNameReturnsDisplayNameWhenMappingDisabled(): void
    {
        Functions\when('get_the_author_meta')->alias(function ($field, $id) {
            return match ($field) {
                'display_name' => 'John Doe',
                default => '',
            };
        });
        Functions\when('get_bloginfo')->justReturn('Blog');

        $result = $this->invokePrivate('getMappedAuthorName', [5]);
        $this->assertSame('John Doe', $result);
    }

    public function testGetMappedAuthorNameReturnsMappedNameWhenEnabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_authors_enabled' => '1',
                'wash_author_mappings' => [5 => 'Custom Author'],
                default => $default,
            };
        });
        Functions\when('get_the_author_meta')->justReturn('John Doe');
        Functions\when('get_bloginfo')->justReturn('Blog');

        // Need a fresh instance to pick up new get_option mock
        $sync = new \WashSync();
        $ref = new \ReflectionMethod(\WashSync::class, 'getMappedAuthorName');
        $ref->setAccessible(true);
        $result = $ref->invoke($sync, 5);
        $this->assertSame('Custom Author', $result);
    }

    public function testGetMappedAuthorNameFallbackChain(): void
    {
        Functions\when('get_the_author_meta')->alias(function ($field, $id) {
            return match ($field) {
                'display_name' => '',
                'user_login' => '',
                'user_nicename' => 'nicename-user',
                default => '',
            };
        });
        Functions\when('get_bloginfo')->justReturn('Blog');

        $result = $this->invokePrivate('getMappedAuthorName', [5]);
        $this->assertSame('nicename-user', $result);
    }

    public function testGetMappedAuthorNameReturnsSiteNameForZeroAuthor(): void
    {
        Functions\when('get_the_author_meta')->justReturn('');
        Functions\when('get_bloginfo')->justReturn('My Site');

        $result = $this->invokePrivate('getMappedAuthorName', [0]);
        $this->assertSame('My Site', $result);
    }

    // ---------------------------------------------------------------
    // getPrimaryCategory
    // ---------------------------------------------------------------

    public function testGetPrimaryCategoryReturnsRankMathPrimary(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key, $single) {
            return $key === 'rank_math_primary_category' ? '10' : '';
        });
        $term = (object) ['name' => 'RankMath Cat', 'term_id' => 10];
        Functions\when('get_term')->justReturn($term);
        Functions\when('is_wp_error')->justReturn(false);

        $result = $this->invokePrivate('getPrimaryCategory', [1]);
        $this->assertSame('RankMath Cat', $result);
    }

    public function testGetPrimaryCategoryReturnsFirstCategoryWhenNoPrimary(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_term')->justReturn(null);
        $cat = (object) ['name' => 'General', 'slug' => 'general'];
        Functions\when('get_the_category')->justReturn([$cat]);

        $result = $this->invokePrivate('getPrimaryCategory', [1]);
        $this->assertSame('General', $result);
    }

    public function testGetPrimaryCategoryReturnsEmptyWhenNone(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_term')->justReturn(null);
        Functions\when('get_the_category')->justReturn([]);

        $result = $this->invokePrivate('getPrimaryCategory', [1]);
        $this->assertSame('', $result);
    }
}
