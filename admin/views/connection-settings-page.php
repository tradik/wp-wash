<?php
/**
 * Per-connection sync settings view (#216). Renders the shared settings schema for
 * one additional connection; values read through WashContext (active id = this
 * connection) and save via WashConnectionsAdmin::handleSaveConnectionSettings().
 * Included from WashConnectionsAdmin::renderConnectionSettings() with
 * $washConnectionId and $washConnection in scope.
 */
if (!defined('ABSPATH')) {
    exit;
}

$washConnectionId = WashContext::activeId();
$washConnection   = WashConnection::get($washConnectionId);

$washLabels = array(
    'wash_post_types'             => __('Post types to sync (comma-separated)', 'wash-blog-manager'),
    'wash_sync_on_publish'        => __('Sync automatically on publish', 'wash-blog-manager'),
    'wash_sync_metafields'        => __('Sync metafields (ACF / SEO)', 'wash-blog-manager'),
    'wash_hreflangs_enabled'      => __('Sync hreflang translations', 'wash-blog-manager'),
    'wash_authors_enabled'        => __('Sync author information', 'wash-blog-manager'),
    'wash_files_enabled'          => __('Sync images & files', 'wash-blog-manager'),
    'wash_rewrite_internal_urls'  => __('Rewrite internal URLs', 'wash-blog-manager'),
    'wash_blocks_enabled'         => __('Sync blocks', 'wash-blog-manager'),
    'wash_sync_blocks_meta'       => __('Sync block metadata', 'wash-blog-manager'),
    'wash_sync_blocks_css'        => __('Sync block CSS', 'wash-blog-manager'),
    'wash_transform_rankmath_faq' => __('Transform Rank Math FAQ', 'wash-blog-manager'),
    'wash_redirections_enabled'   => __('Create redirects on slug change', 'wash-blog-manager'),
    'wash_sync_inline'            => __('Sync inline (no background queue)', 'wash-blog-manager'),
);

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
$washSaved = isset($_GET['saved']) || isset($_GET['added']);
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap">
    <h1><?php printf(
        /* translators: %s: Shopify store domain */
        esc_html__('Wash settings — %s', 'wash-blog-manager'),
        esc_html($washConnection['store'] !== '' ? $washConnection['store'] : $washConnectionId)
    ); ?></h1>
    <p>
        <a href="<?php echo esc_url(admin_url('options-general.php?page=' . WashConnectionsAdmin::PAGE)); ?>">
            &larr; <?php esc_html_e('Back to connections', 'wash-blog-manager'); ?>
        </a>
    </p>

    <?php if ($washSaved) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'wash-blog-manager'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wash_save_connection_settings'); ?>
        <input type="hidden" name="action" value="wash_save_connection_settings">
        <input type="hidden" name="washConnectionId" value="<?php echo esc_attr($washConnectionId); ?>">

        <table class="form-table">
            <tbody>
            <?php foreach (WashConnectionsAdmin::settingsSchema() as $washKey => $washType) :
                $washLabel = isset($washLabels[$washKey]) ? $washLabels[$washKey] : $washKey;
                if ($washType === 'checkbox') :
                    $washChecked = WashContext::option($washKey, '0') === '1';
                    ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($washLabel); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($washKey); ?>" value="1" <?php checked($washChecked); ?>>
                                <?php esc_html_e('Enabled', 'wash-blog-manager'); ?>
                            </label>
                        </td>
                    </tr>
                <?php else :
                    $washValue = WashContext::option($washKey, 'post,page');
                    ?>
                    <tr>
                        <th scope="row"><label for="wash-field-<?php echo esc_attr($washKey); ?>"><?php echo esc_html($washLabel); ?></label></th>
                        <td>
                            <input type="text" id="wash-field-<?php echo esc_attr($washKey); ?>"
                                   name="<?php echo esc_attr($washKey); ?>"
                                   value="<?php echo esc_attr($washValue); ?>" class="regular-text">
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php submit_button(__('Save settings', 'wash-blog-manager')); ?>
    </form>
</div>
