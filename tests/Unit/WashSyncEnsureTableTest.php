<?php
/**
 * Regression test for WashSync's wash_sync table self-heal.
 *
 * Background: a dev environment may load the plugin without firing the activator
 * hook (e.g. plugin code dropped into wp-content/plugins by hand, DB restored
 * from a backup that predates the wash_sync table). Before the fix, every
 * post save triggered "WordPress database error Table bitnami_wordpress.wp_wash_sync
 * doesn't exist" warnings. WashSync::logSync() now calls ensureSyncTable() which
 * delegates to WashActivator::createSyncTable() when the table is missing.
 *
 * Full integration is out of scope for unit tests (requires real $wpdb + ABSPATH
 * for dbDelta). These specs guard the contract: the public surface area exists
 * and the helpers are wired correctly.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wash-activator.php';

class WashSyncEnsureTableTest extends TestCase
{
    public function testCreateSyncTableIsPublicSoWashSyncCanInvokeIt(): void
    {
        $this->assertTrue(method_exists('WashActivator', 'createSyncTable'));

        $reflection = new \ReflectionMethod('WashActivator', 'createSyncTable');
        $this->assertTrue(
            $reflection->isPublic(),
            'createSyncTable() must be public for the WashSync::ensureSyncTable() self-heal path'
        );
        $this->assertTrue(
            $reflection->isStatic(),
            'createSyncTable() must remain static — invoked without instantiating the activator'
        );
    }

    public function testWashSyncDefinesEnsureSyncTablePrivateHelper(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
        require_once dirname(__DIR__, 2) . '/includes/class-wash-hreflangs.php';
        require_once dirname(__DIR__, 2) . '/includes/class-wash-multisite.php';
        require_once dirname(__DIR__, 2) . '/includes/class-wash-sync.php';

        $this->assertTrue(class_exists('WashSync'));

        // ensureSyncTable is the linchpin: it's the bridge between logSync()
        // and the activator's table-creation routine.
        $reflection = new \ReflectionClass('WashSync');
        $this->assertTrue(
            $reflection->hasMethod('ensureSyncTable'),
            'WashSync must declare ensureSyncTable() — the self-heal entry point'
        );
        $method = $reflection->getMethod('ensureSyncTable');
        $this->assertTrue(
            $method->isPrivate(),
            'ensureSyncTable() should remain private (internal contract with logSync)'
        );

        $params = $method->getParameters();
        $this->assertCount(1, $params, 'ensureSyncTable() takes the fully-qualified table name');
        $this->assertSame('tableName', $params[0]->getName());
    }
}
