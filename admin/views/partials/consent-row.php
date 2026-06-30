<?php
/**
 * Data-processing consent block (wp.org Plugin Directory guideline #10).
 *
 * Shared by the primary connect form (settings-page.php) and the "new connection"
 * panel (connections-page.php) so the consent UI is defined once (DRY) and looks
 * identical everywhere. Renders just the consent control (checkbox + text + the
 * "recorded on" note) — WRAPPER-AGNOSTIC, so the caller decides whether to place it
 * in a form-table cell or a plain div (no layout <table>/<th> here). Sets
 * $wash_consentAccepted in the including scope (consumed by the connect button).
 */
if (!defined('ABSPATH')) {
    exit;
}

$wash_consentAccepted = get_option('wash_consent_accepted', '0') === '1';
?>
<label for="wash-consent-checkbox" style="display: flex; gap: 8px; align-items: flex-start; max-width: 720px;">
    <input type="checkbox" id="wash-consent-checkbox" <?php checked($wash_consentAccepted); ?> <?php disabled($wash_consentAccepted); ?> />
    <span>
        <?php
        printf(
            /* translators: 1: opening anchor tag to the Privacy Policy, 2: closing anchor tag, 3: opening anchor to the External services section of readme.txt rendered on wordpress.org, 4: closing anchor tag */
            esc_html__('I understand that connecting this plugin will send my WordPress post content, images, custom fields, and site URL to the Tradik Wash service at app-wash.tradik.com (the broker that talks to Shopify). I have read the %1$sPrivacy Policy%2$s and the %3$sfull list of external services%4$s.', 'wash-blog-manager'),
            '<a href="https://wash.tradik.com/privacy-policy/" target="_blank" rel="noopener noreferrer">',
            '</a>',
            '<a href="https://wordpress.org/plugins/wash-blog-manager/#description" target="_blank" rel="noopener noreferrer">',
            '</a>'
        );
        ?>
    </span>
</label>
<?php if ($wash_consentAccepted) : ?>
    <p class="description" style="margin-top: 6px;">
        <?php
        printf(
            /* translators: %s: human-readable date when consent was recorded */
            esc_html__('Consent recorded on %s.', 'wash-blog-manager'),
            esc_html(wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) get_option('wash_consent_accepted_at', 0)))
        );
        ?>
    </p>
<?php endif; ?>
