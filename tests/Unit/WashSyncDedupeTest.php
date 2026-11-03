<?php
/**
 * #341: the block editor saves a post twice when legacy meta boxes (Yoast, ACF)
 * are on screen, and both saves fire save_post. WashSync drops an auto-sync whose
 * payload is identical to one sent to the same store in the last DEDUPE_WINDOW
 * seconds. These tests cover the fingerprint and the transient round-trip.
 */
namespace Wash\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

class WashSyncDedupeTest extends TestCase
{
    /** @var array<string,array{0:mixed,1:int}> transient store: key => [value, ttl] */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \WashContext::setActiveId(null);
        $this->transients = [];
        Functions\when('get_option')->alias(fn ($key, $default = false) => $default);
        Functions\when('wp_json_encode')->alias(fn ($v) => json_encode($v));
        Functions\when('get_transient')->alias(fn ($k) => $this->transients[$k][0] ?? false);
        Functions\when('set_transient')->alias(function ($k, $v, $ttl) {
            $this->transients[$k] = [$v, $ttl];
            return true;
        });
    }

    protected function tearDown(): void
    {
        \WashContext::setActiveId(null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function call(string $method, array $args)
    {
        $ref = new \ReflectionMethod(\WashSync::class, $method);
        return $ref->invokeArgs(new \WashSync(), $args);
    }

    private function fingerprint(int $postId, array $payload, string $endpoint = '/api/content-sync'): array
    {
        return $this->call('syncFingerprint', [$postId, $endpoint, $payload]);
    }

    public function testSamePayloadGivesTheSameFingerprint(): void
    {
        $payload = ['action' => 'update', 'title' => 'Hello', 'seo' => ['metaTitle' => 'A']];
        $this->assertSame($this->fingerprint(5, $payload), $this->fingerprint(5, $payload));
    }

    public function testAChangedFieldOrPostOrEndpointChangesTheFingerprint(): void
    {
        $base = $this->fingerprint(5, ['title' => 'Hello', 'seo' => ['metaTitle' => 'A']]);
        $this->assertNotSame($base['hash'], $this->fingerprint(5, ['title' => 'Hello', 'seo' => ['metaTitle' => 'B']])['hash']);
        $this->assertNotSame($base['hash'], $this->fingerprint(5, ['title' => 'Hello', 'seo' => ['metaTitle' => 'A']], '/api/page-sync')['hash']);
        $this->assertNotSame($base['key'], $this->fingerprint(6, ['title' => 'Hello'])['key']);
    }

    public function testEachStoreHasItsOwnKey(): void
    {
        $primary = $this->fingerprint(5, ['title' => 'Hello']);
        \WashContext::setActiveId('second');
        $this->assertNotSame($primary['key'], $this->fingerprint(5, ['title' => 'Hello'])['key']);
    }

    public function testAPayloadIsOnlyAJustSentDuplicateAfterItWasRemembered(): void
    {
        $fingerprint = $this->fingerprint(5, ['title' => 'Hello']);
        $this->assertFalse($this->call('wasJustSent', [$fingerprint]));

        $this->call('rememberSent', [$fingerprint]);

        $this->assertTrue($this->call('wasJustSent', [$fingerprint]));
        $this->assertSame(\WashSync::DEDUPE_WINDOW, $this->transients[$fingerprint['key']][1]);
        $changed = $this->fingerprint(5, ['title' => 'Hello again']);
        $this->assertFalse($this->call('wasJustSent', [$changed]));
    }
}
