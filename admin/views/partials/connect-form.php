<?php
/**
 * Single reusable "connect a Shopify store" form (#264).
 *
 * Defined ONCE and included everywhere a store is connected — the primary store's
 * first connect AND every additional-store add — so the form is identical no matter
 * how many stores a merchant adds (DRY: no per-store copies). The includer sets one
 * flag before including:
 *   $wash_addMode (bool) — false: connect the primary store; true: add another store.
 *
 * The element IDs are mode-specific so each existing handler keeps driving its own
 * flow with no JS change: the primary's inline script binds #wash_shopify_store /
 * #wash-connect-btn, while connections.js binds #wash-oauth-store / #wash-oauth-connect
 * and runs the wash_add OAuth flow. consent-row.php sets $wash_consentAccepted (which
 * gates the button) in this scope. Rendered as a self-contained card (matching the
 * connected-state box) so it looks finished in every context.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wash_addMode = !empty($wash_addMode);
$wash_storeId = $wash_addMode ? 'wash-oauth-store' : 'wash_shopify_store';
$wash_btnId   = $wash_addMode ? 'wash-oauth-connect' : 'wash-connect-btn';
?>
<div class="wash-connect-form" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px 28px; max-width: 760px; margin: 16px 0; box-shadow: 0 1px 2px rgba(0,0,0,.04);">
    <label for="<?php echo esc_attr($wash_storeId); ?>" style="display: block; font-weight: 600; color: #1d2327; margin-bottom: 8px;"><?php esc_html_e('Shopify Store', 'wash-blog-manager'); ?></label>
    <div style="display: flex; align-items: center; gap: 8px;">
        <input type="text" id="<?php echo esc_attr($wash_storeId); ?>"
               <?php if (!$wash_addMode) : ?>name="wash_shopify_store" value="<?php echo esc_attr($wash_shopifyStore); ?>"<?php endif; ?>
               class="regular-text" placeholder="your-store" style="flex: 0 0 240px; max-width: 240px;">
        <span style="color: #646970; font-weight: 500;">.myshopify.com</span>
    </div>
    <p class="description" style="margin: 8px 0 0;"><?php esc_html_e('Your store handle is the "your-store" part of your-store.myshopify.com — find it in your Shopify admin URL (admin.shopify.com/store/your-store) or under Settings → Domains.', 'wash-blog-manager'); ?></p>

    <div style="background: #f6f7f7; border: 1px solid #e0e0e0; border-radius: 6px; padding: 14px 16px; margin: 20px 0;">
        <strong style="display: block; margin-bottom: 8px; color: #1d2327;"><?php esc_html_e('Data processing consent', 'wash-blog-manager'); ?></strong>
        <?php include_once WASH_PLUGIN_DIR . 'admin/views/partials/consent-row.php'; ?>
    </div>

    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <button type="button" class="button button-primary button-hero" id="<?php echo esc_attr($wash_btnId); ?>"
                <?php if (!$wash_addMode) : ?>style="display: none;"<?php endif; ?>
                <?php disabled(!$wash_consentAccepted); ?>>
            <span class="dashicons dashicons-admin-links" style="vertical-align: text-bottom;"></span>
            <?php esc_html_e('Connect to Shopify', 'wash-blog-manager'); ?>
        </button>
        <?php if (!$wash_consentAccepted) : ?>
            <span class="description"><?php esc_html_e('Tick the consent box above to enable the Connect button.', 'wash-blog-manager'); ?></span>
        <?php endif; ?>
    </div>

    <?php if ($wash_addMode) : ?>
        <details style="margin-top: 18px; border-top: 1px solid #f0f0f1; padding-top: 14px;">
            <summary style="cursor: pointer; color: #646970;"><?php esc_html_e('Enter credentials manually', 'wash-blog-manager'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
                <?php wp_nonce_field('wash_add_connection'); ?>
                <input type="hidden" name="action" value="wash_add_connection">
                <p>
                    <label for="wash-store"><?php esc_html_e('Store domain', 'wash-blog-manager'); ?></label><br>
                    <input type="text" id="wash-store" name="store" class="regular-text" placeholder="mystore.myshopify.com" required>
                </p>
                <p>
                    <label for="wash-apikey"><?php esc_html_e('API key', 'wash-blog-manager'); ?></label><br>
                    <input type="password" id="wash-apikey" name="apiKey" class="regular-text" placeholder="wp4s_…" autocomplete="off" required>
                </p>
                <details>
                    <summary><?php esc_html_e('Advanced (optional)', 'wash-blog-manager'); ?></summary>
                    <p>
                        <label for="wash-appurl"><?php esc_html_e('App URL', 'wash-blog-manager'); ?></label><br>
                        <input type="url" id="wash-appurl" name="appUrl" class="regular-text" placeholder="https://app-wash.tradik.com">
                    </p>
                    <p>
                        <label for="wash-httpuser"><?php esc_html_e('HTTP Basic user', 'wash-blog-manager'); ?></label><br>
                        <input type="text" id="wash-httpuser" name="httpUser" class="regular-text" autocomplete="off">
                    </p>
                    <p>
                        <label for="wash-httppass"><?php esc_html_e('HTTP Basic password', 'wash-blog-manager'); ?></label><br>
                        <input type="password" id="wash-httppass" name="httpPass" class="regular-text" autocomplete="off">
                    </p>
                </details>
                <p><button type="submit" class="button"><?php esc_html_e('Add connection', 'wash-blog-manager'); ?></button></p>
            </form>
        </details>
    <?php endif; ?>
</div>
