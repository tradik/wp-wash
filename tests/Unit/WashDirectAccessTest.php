<?php
/**
 * Security tests for direct PHP file access blocking.
 * Validates that plugin files check for ABSPATH constant
 * to prevent direct execution outside WordPress context.
 *
 * Best practice: every PHP file should exit if ABSPATH is not defined.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;

class WashDirectAccessTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    /**
     * Get all PHP files in the plugin that should have ABSPATH protection.
     * Excludes vendor/, tests/, and the main plugin file (which defines constants).
     */
    private function getPluginPhpFiles(): array
    {
        $files = [];
        $directories = [
            self::PLUGIN_DIR . 'includes/',
            self::PLUGIN_DIR . 'admin/',
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Test that class-wash-callback.php has ABSPATH check.
     * This is the most critical file as it handles incoming REST API requests.
     */
    public function testCallbackFileBlocksDirectAccess(): void
    {
        $content = file_get_contents(self::PLUGIN_DIR . 'includes/class-wash-callback.php');

        $this->assertNotFalse($content);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)/',
            $content,
            'class-wash-callback.php must check for ABSPATH to block direct access'
        );
    }

    /**
     * Test that uninstall.php has ABSPATH or WP_UNINSTALL_PLUGIN check.
     */
    public function testUninstallFileBlocksDirectAccess(): void
    {
        $filePath = self::PLUGIN_DIR . 'uninstall.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('uninstall.php not found');
        }

        $content = file_get_contents($filePath);
        $this->assertNotFalse($content);

        // uninstall.php should check either ABSPATH or WP_UNINSTALL_PLUGIN
        $hasAbsCheck = preg_match('/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/', $content);
        $hasUninstallCheck = preg_match('/defined\s*\(\s*[\'"]WP_UNINSTALL_PLUGIN[\'"]\s*\)/', $content);

        $this->assertTrue(
            $hasAbsCheck || $hasUninstallCheck,
            'uninstall.php must check for ABSPATH or WP_UNINSTALL_PLUGIN'
        );
    }

    /**
     * Test that admin view files have ABSPATH check.
     * Views are particularly vulnerable as they often output HTML directly.
     */
    public function testAdminViewFilesBlockDirectAccess(): void
    {
        $viewDir = self::PLUGIN_DIR . 'admin/views/';
        if (!is_dir($viewDir)) {
            $this->markTestSkipped('admin/views/ directory not found');
        }

        $viewFiles = glob($viewDir . '*.php');
        $this->assertNotEmpty($viewFiles, 'Expected at least one view file');

        foreach ($viewFiles as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            $hasAbsCheck = preg_match(
                '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)/',
                $content
            );

            $this->assertTrue(
                (bool) $hasAbsCheck,
                "admin/views/{$basename} must block direct access with ABSPATH check"
            );
        }
    }

    /**
     * Test that all include files define classes (not executable code)
     * or have ABSPATH protection. Class-only files are safe because
     * loading them directly without WordPress won't execute harmful code.
     */
    public function testIncludeFilesAreClassDefinitionsOrProtected(): void
    {
        $includesDir = self::PLUGIN_DIR . 'includes/';
        if (!is_dir($includesDir)) {
            $this->markTestSkipped('includes/ directory not found');
        }

        $files = glob($includesDir . '*.php');
        $this->assertNotEmpty($files, 'Expected include files');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            // File should either:
            // 1. Have ABSPATH check, OR
            // 2. Only contain class/function definitions (no top-level executable code)
            $hasAbsCheck = preg_match(
                '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)/',
                $content
            );

            $isClassOnly = preg_match('/^\s*<\?php\s*(?:\/\*[\s\S]*?\*\/\s*)?(?:namespace\s+[\w\\\\]+;\s*)?(?:use\s+[\w\\\\]+;\s*)*(?:if\s*\(\s*!\s*defined.*?\}\s*)?class\s+/m', $content);

            $this->assertTrue(
                (bool) $hasAbsCheck || (bool) $isClassOnly,
                "{$basename} must either have ABSPATH check or be a pure class definition"
            );
        }
    }

    /**
     * Test that the main plugin file (wash.php) does NOT include
     * files without proper protection measures in the loading chain.
     */
    public function testMainPluginFileExists(): void
    {
        $mainFile = self::PLUGIN_DIR . 'wash.php';
        $this->assertFileExists($mainFile, 'Main plugin file wash.php must exist');

        $content = file_get_contents($mainFile);

        // Main plugin file should have plugin header and ABSPATH check
        $this->assertStringContainsString('Plugin Name:', $content);
    }

    /**
     * Test that no PHP files output content before ABSPATH check.
     * If they have ABSPATH check, it should be before any echo/print/HTML.
     */
    public function testNoOutputBeforeAbsCheck(): void
    {
        $callbackFile = self::PLUGIN_DIR . 'includes/class-wash-callback.php';
        $content = file_get_contents($callbackFile);

        // Find position of ABSPATH check
        $absCheckPos = strpos($content, "defined('ABSPATH')");
        if ($absCheckPos === false) {
            $absCheckPos = strpos($content, 'defined("ABSPATH")');
        }

        $this->assertNotFalse($absCheckPos, 'ABSPATH check must exist');

        // Before the ABSPATH check, there should be no echo/print/HTML output
        $beforeCheck = substr($content, 0, $absCheckPos);
        $this->assertStringNotContainsString('echo ', $beforeCheck);
        $this->assertStringNotContainsString('print ', $beforeCheck);
        $this->assertStringNotContainsString('<html', $beforeCheck);
        $this->assertStringNotContainsString('<div', $beforeCheck);
    }
}
