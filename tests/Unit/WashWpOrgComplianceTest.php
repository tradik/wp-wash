<?php
/**
 * wp.org Plugin Directory review compliance guards.
 *
 * These are source-level regression tests for two review items addressed in
 * 1.26.8. They don't exercise runtime behaviour (the behavioural paths are
 * covered in WashAdminSecurityTest / WashAdminBulkProgressTest) — they pin the
 * exact properties the wp.org reviewer checks for, so a future edit that
 * reintroduces an inline script or drops the connect-callback nonce fails CI.
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;

class WashWpOrgComplianceTest extends TestCase
{
    private function pluginDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every PHP file the plugin renders to the browser must deliver JS/CSS via
     * the enqueue API — no literal <script>/<style> output elements.
     *
     * We tokenise and inspect only T_INLINE_HTML (text emitted outside
     * `<?php ?>`). PHP comments (T_COMMENT) and the <style>/<script> strings
     * that are concatenated into the HTML exported to Shopify
     * (T_CONSTANT_ENCAPSED_STRING in class-wash-page-builders / class-wash-sync)
     * are correctly ignored — those are not output on a WordPress page.
     */
    public function testNoInlineScriptOrStyleOutputTags(): void
    {
        $files = array_merge(
            glob($this->pluginDir() . '/includes/*.php') ?: [],
            glob($this->pluginDir() . '/admin/views/*.php') ?: []
        );
        $this->assertNotEmpty($files, 'expected plugin source files to scan');

        $offenders = [];
        foreach ($files as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)
                    && $token[0] === T_INLINE_HTML
                    && preg_match('/<(script|style)\b/i', $token[1], $m)
                ) {
                    $offenders[] = basename($file) . ':' . $token[2] . ' <' . strtolower($m[1]);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Inline <script>/<style> output found — enqueue it instead "
            . "(wp_enqueue_script/style, wp_add_inline_script/style): "
            . implode(', ', $offenders)
        );
    }

    /**
     * Redeeming a connect code writes credentials, so it must be CSRF- and
     * capability-gated: connections.js gets a `wash_connect_code` nonce and
     * WashConnectCode::ajaxRedeem() checks it and manage_options first.
     */
    public function testConnectCodeCsrfWiringPresent(): void
    {
        $admin = (string) file_get_contents($this->pluginDir() . '/includes/class-wash-connections-admin.php');
        $code  = (string) file_get_contents($this->pluginDir() . '/includes/class-wash-connect-code.php');

        $this->assertStringContainsString('wp_create_nonce(WashConnectCode::NONCE)', $admin);
        $this->assertStringContainsString("const NONCE = 'wash_connect_code';", $code);
        $this->assertMatchesRegularExpression('/check_ajax_referer\(\s*self::NONCE\s*,\s*\'nonce\'\s*\)/', $code);
        $this->assertLessThan(
            strpos($code, 'redeemConnectCode('),
            strpos($code, "current_user_can('manage_options')"),
            'capability must be checked before the code is redeemed'
        );
    }

    /**
     * Shopify App Store requirement 2.3.1 (#285): no plugin UI may ask the
     * merchant to type a myshopify.com domain or store handle.
     */
    public function testConnectUiNeverAsksForTheShopDomain(): void
    {
        // The connect form and its script: no domain wording, no store-addressed URL.
        foreach (['/admin/views/partials/connect-form.php', '/admin/js/connections.js'] as $file) {
            $src = (string) file_get_contents($this->pluginDir() . $file);
            $this->assertStringNotContainsString('myshopify.com', $src, $file . ' mentions myshopify.com');
            $this->assertStringNotContainsString('admin.shopify.com/store/', $src, $file . ' builds a store-specific admin URL');
        }
        // The views may DISPLAY the connected store (and carry it in a hidden field on
        // settings save), but must not offer a field to type one.
        foreach (['/admin/views/connections-page.php', '/admin/views/settings-page.php'] as $file) {
            $src = (string) file_get_contents($this->pluginDir() . $file);
            $this->assertDoesNotMatchRegularExpression('/<input(?![^>]*type="hidden")[^>]+(name|id)="(store|wash_shopify_store|wash-oauth-store|wash-store)"/', $src, $file);
        }
    }

    /**
     * Every register_rest_route() must declare a permission_callback, and the
     * post-scoped /sync-status route must use the per-post check (not the
     * global edit_posts one) — both wp.org review items for REST routes.
     */
    public function testRestRoutesHavePermissionCallbacks(): void
    {
        $src = (string) file_get_contents($this->pluginDir() . '/includes/class-wash-callback.php');

        // No route may be registered without a permission_callback.
        $routeCount = preg_match_all('/register_rest_route\(/', $src);
        $permCount = preg_match_all('/[\'"]permission_callback[\'"]\s*=>/', $src);
        $this->assertSame(
            $routeCount,
            $permCount,
            "Every register_rest_route() needs a permission_callback ($routeCount routes, $permCount callbacks)"
        );

        // The /sync-status/<post_id> route must gate on the per-post capability.
        $this->assertMatchesRegularExpression(
            "#/sync-status/.*?'permission_callback'\s*=>\s*\[\\\$this,\s*'checkEditPostPermission'\]#s",
            $src,
            'sync-status must use checkEditPostPermission (per-post edit_post), not the global edit_posts check'
        );
    }

    /**
     * Public-facing wash.tradik.com links must use the live path-based URLs.
     * The legacy `.html` pages (privacy-policy.html, docs/*.html) all 404 and
     * were flagged by the wp.org reviewer; guard against their reintroduction.
     */
    public function testNoStaleHtmlSiteUrls(): void
    {
        $files = array_merge(
            [$this->pluginDir() . '/readme.txt'],
            glob($this->pluginDir() . '/admin/views/*.php') ?: [],
            glob($this->pluginDir() . '/includes/*.php') ?: []
        );

        $offenders = [];
        foreach ($files as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $i => $line) {
                if (preg_match('#wash\.tradik\.com/[^\s"\'\)\]]*\.html#i', $line, $m)) {
                    $offenders[] = basename($file) . ':' . ($i + 1) . ' ' . $m[0];
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Stale .html wash.tradik.com URLs (these 404 — use the path-based docs/privacy URLs): '
            . implode(', ', $offenders)
        );
    }

    /**
     * `Plugin URI` is optional; if present it must not point at the private
     * GitHub repo that the reviewer got a 404 from. (Author URI / the org page
     * is public and intentionally retained.)
     */
    public function testPluginUriHeaderIsNotAPrivateRepo(): void
    {
        $header = (string) file_get_contents($this->pluginDir() . '/wash.php');

        $this->assertDoesNotMatchRegularExpression(
            '#^\s*\*\s*Plugin URI:\s*https://github\.com/tradik/(wash|wp-wash)\b#mi',
            $header,
            'Plugin URI must not point at a private GitHub repo (404 for the reviewer)'
        );
    }
}
