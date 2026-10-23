<?php
/**
 * Single reusable "connect a Shopify store" form (#264, #285).
 *
 * Defined ONCE and included everywhere a store is connected: the primary store's
 * first connect AND every additional-store add. The includer sets one flag:
 *   $wash_addMode (bool) — false: connect the primary store; true: add another store.
 *
 * The merchant generates a one-time connect code in Wash inside their Shopify
 * admin and pastes it here; WashConnectCode redeems it over AJAX. The form never
 * asks for the store domain (Shopify App Store requirement 2.3.1).
 *
 * The fields deliberately have no name attributes and the button is type="button":
 * in primary mode this partial sits inside the settings <form action="options.php">,
 * so nothing here may be submitted with it. connections.js drives every
 * .wash-connect-form by its data-mode. consent-row.php sets $wash_consentAccepted.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wash_addMode = !empty($wash_addMode);
$wash_mode    = $wash_addMode ? 'add' : 'primary';
$wash_codeId  = 'wash-connect-code-' . $wash_mode;
?>
<div class="wash-connect-form" data-mode="<?php echo esc_attr($wash_mode); ?>" style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 24px 28px; max-width: 760px; margin: 16px 0; box-shadow: 0 1px 2px rgba(0,0,0,.04);">
    <strong style="display: block; color: #1d2327; margin-bottom: 8px;"><?php esc_html_e('Get a connect code in Shopify', 'wash-blog-manager'); ?></strong>
    <ol style="margin: 0 0 16px 20px; color: #1d2327;">
        <li>
            <?php
            printf(
                /* translators: 1: opening anchor tag to the Wash listing on the Shopify App Store, 2: closing anchor tag */
                esc_html__('Open %1$sWash in your Shopify admin%2$s (install it first if you have not).', 'wash-blog-manager'),
                '<a href="https://apps.shopify.com/wash" target="_blank" rel="noopener noreferrer">',
                '</a>'
            );
            ?>
        </li>
        <li><?php esc_html_e('Go to Connections → Add Connection and click "Generate connect code".', 'wash-blog-manager'); ?></li>
        <li><?php esc_html_e('Paste the code below. It works once and expires after 15 minutes.', 'wash-blog-manager'); ?></li>
    </ol>

    <label for="<?php echo esc_attr($wash_codeId); ?>" style="display: block; font-weight: 600; color: #1d2327; margin-bottom: 8px;"><?php esc_html_e('Connect code', 'wash-blog-manager'); ?></label>
    <input type="text" id="<?php echo esc_attr($wash_codeId); ?>" class="regular-text wash-connect-code-input"
           placeholder="ABCD-EFGH-JKMN" autocomplete="off" spellcheck="false" autocapitalize="characters"
           style="font-family: monospace; letter-spacing: .08em; max-width: 240px;">

    <div style="background: #f6f7f7; border: 1px solid #e0e0e0; border-radius: 6px; padding: 14px 16px; margin: 20px 0;">
        <strong style="display: block; margin-bottom: 8px; color: #1d2327;"><?php esc_html_e('Data processing consent', 'wash-blog-manager'); ?></strong>
        <?php include_once WASH_PLUGIN_DIR . 'admin/views/partials/consent-row.php'; ?>
    </div>

    <?php if ($wash_addMode) : ?>
        <details style="margin-bottom: 16px;">
            <summary style="cursor: pointer; color: #646970;"><?php esc_html_e('Advanced (optional)', 'wash-blog-manager'); ?></summary>
            <p>
                <label for="wash-connect-appurl"><?php esc_html_e('App URL', 'wash-blog-manager'); ?></label><br>
                <input type="url" id="wash-connect-appurl" class="regular-text wash-connect-appurl" placeholder="https://app-wash.tradik.com">
            </p>
            <p>
                <label for="wash-connect-httpuser"><?php esc_html_e('HTTP Basic user', 'wash-blog-manager'); ?></label><br>
                <input type="text" id="wash-connect-httpuser" class="regular-text wash-connect-httpuser" autocomplete="off">
            </p>
            <p>
                <label for="wash-connect-httppass"><?php esc_html_e('HTTP Basic password', 'wash-blog-manager'); ?></label><br>
                <input type="password" id="wash-connect-httppass" class="regular-text wash-connect-httppass" autocomplete="off">
            </p>
        </details>
    <?php endif; ?>

    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <button type="button" class="button button-primary button-hero wash-connect-code-btn" <?php disabled(!$wash_consentAccepted); ?>>
            <span class="dashicons dashicons-admin-links" style="vertical-align: text-bottom;" aria-hidden="true"></span>
            <?php esc_html_e('Connect to Shopify', 'wash-blog-manager'); ?>
        </button>
        <?php if (!$wash_consentAccepted) : ?>
            <span class="description"><?php esc_html_e('Tick the consent box above to enable the Connect button.', 'wash-blog-manager'); ?></span>
        <?php endif; ?>
    </div>
    <p class="wash-connect-error" role="alert" aria-live="assertive" style="display: none; margin: 12px 0 0; color: #b32d2e; font-weight: 600;"></p>
</div>
