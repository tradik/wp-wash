<?php
/**
 * "This store uninstalled Wash" state for the primary connection (#294).
 *
 * Rendered above the connect form when the app answers CONNECTION_INACTIVE: the
 * key is dead, so the green "Connected" box would be wrong. The old key and store
 * stay in hidden fields so saving settings does not wipe them. A reinstall within
 * the 48h grace reactivates the connection and this notice goes away on its own;
 * connecting another store with a code replaces them.
 *
 * Expects $wash_shopifyStore from settings-page.php.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wash_inactiveHost = (strpos((string) $wash_shopifyStore, '.') !== false) ? $wash_shopifyStore : $wash_shopifyStore . '.myshopify.com';
?>
<div class="notice notice-warning inline wash-store-inactive" style="margin: 15px 0;">
    <p>
        <strong><?php esc_html_e('This store no longer has Wash installed.', 'wash-blog-manager'); ?></strong>
        <?php
        if ($wash_shopifyStore) {
            printf(
                /* translators: %s: Shopify store domain, e.g. mystore.myshopify.com */
                esc_html__('%s uninstalled the Wash app, or disabled this connection, so posts no longer sync.', 'wash-blog-manager'),
                '<code>' . esc_html($wash_inactiveHost) . '</code>'
            );
        }
        ?>
    </p>
    <p><?php esc_html_e('Reinstall Wash in that store to pick up where you left off, or connect a different store with a connect code below.', 'wash-blog-manager'); ?></p>
</div>
<input type="hidden" name="wash_shopify_store" value="<?php echo esc_attr($wash_shopifyStore); ?>" />
<input type="hidden" name="wash_api_key" value="<?php echo esc_attr(WashContext::option('wash_api_key')); ?>" />
