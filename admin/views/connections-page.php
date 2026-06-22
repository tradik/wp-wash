<?php
/**
 * Connections manager view (#216). Lists every Shopify store connection and lets
 * the admin add one (manual paste or OAuth), enable/disable it, or remove it.
 * Included from WashConnectionsAdmin::renderManager().
 */
if (!defined('ABSPATH')) {
    exit;
}

$washConnections = WashConnection::all();

// Status notices from redirect query args (read-only; no state change).
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$washNotice = '';
if (isset($_GET['added'])) {
    $washNotice = __('Connection added.', 'wash-blog-manager');
} elseif (isset($_GET['removed'])) {
    $washNotice = __('Connection removed.', 'wash-blog-manager');
} elseif (isset($_GET['toggled'])) {
    $washNotice = __('Connection updated.', 'wash-blog-manager');
} elseif (isset($_GET['error'])) {
    $washNotice = __('Could not complete the request. Please check the details and try again.', 'wash-blog-manager');
}
$washIsError = isset($_GET['error']);
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap">
    <h1><?php esc_html_e('Wash: Store Connections', 'wash-blog-manager'); ?></h1>
    <p class="description">
        <?php esc_html_e('Connect one or more Shopify stores. Published content syncs to every enabled connection.', 'wash-blog-manager'); ?>
    </p>

    <?php if ($washNotice !== '') : ?>
        <div class="notice <?php echo $washIsError ? 'notice-error' : 'notice-success'; ?> is-dismissible">
            <p><?php echo esc_html($washNotice); ?></p>
        </div>
    <?php endif; ?>

    <table class="widefat striped" style="margin-top:1em;max-width:900px;">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Store', 'wash-blog-manager'); ?></th>
                <th scope="col"><?php esc_html_e('Type', 'wash-blog-manager'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'wash-blog-manager'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'wash-blog-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($washConnections as $washId => $washConn) :
            $washIsPrimary = !empty($washConn['isPrimary']);
            $washStore     = $washConn['store'] !== '' ? $washConn['store'] : __('(not set)', 'wash-blog-manager');
            $washEnabled   = !empty($washConn['enabled']);
            $washSettingsUrl = $washIsPrimary
                ? admin_url('options-general.php?page=wash')
                : admin_url('options-general.php?page=wash-conn-' . $washId);
            ?>
            <tr>
                <td><strong><?php echo esc_html($washStore); ?></strong></td>
                <td><?php echo $washIsPrimary
                    ? esc_html__('Primary', 'wash-blog-manager')
                    : esc_html__('Additional', 'wash-blog-manager'); ?></td>
                <td><?php echo $washEnabled
                    ? esc_html__('Enabled', 'wash-blog-manager')
                    : esc_html__('Disabled', 'wash-blog-manager'); ?></td>
                <td>
                    <a href="<?php echo esc_url($washSettingsUrl); ?>" class="button button-small"><?php esc_html_e('Settings', 'wash-blog-manager'); ?></a>
                    <?php if (!$washIsPrimary) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('wash_toggle_connection'); ?>
                            <input type="hidden" name="action" value="wash_toggle_connection">
                            <input type="hidden" name="washConnectionId" value="<?php echo esc_attr($washId); ?>">
                            <input type="hidden" name="enabled" value="<?php echo $washEnabled ? '0' : '1'; ?>">
                            <button type="submit" class="button button-small">
                                <?php echo $washEnabled
                                    ? esc_html__('Disable', 'wash-blog-manager')
                                    : esc_html__('Enable', 'wash-blog-manager'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"
                              onsubmit="return confirm('<?php echo esc_js(__('Remove this connection? Synced content stays on the store.', 'wash-blog-manager')); ?>');">
                            <?php wp_nonce_field('wash_remove_connection'); ?>
                            <input type="hidden" name="action" value="wash_remove_connection">
                            <input type="hidden" name="washConnectionId" value="<?php echo esc_attr($washId); ?>">
                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Remove', 'wash-blog-manager'); ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:2em;"><?php esc_html_e('Add a store', 'wash-blog-manager'); ?></h2>
    <div style="display:flex;gap:2em;flex-wrap:wrap;max-width:900px;">
        <div style="flex:1;min-width:320px;">
            <h3><?php esc_html_e('Connect with Shopify (recommended)', 'wash-blog-manager'); ?></h3>
            <p class="description"><?php esc_html_e('Installs the app on the store and links it automatically.', 'wash-blog-manager'); ?></p>
            <p>
                <label for="wash-oauth-store"><?php esc_html_e('Store domain', 'wash-blog-manager'); ?></label><br>
                <input type="text" id="wash-oauth-store" class="regular-text" placeholder="mystore.myshopify.com">
            </p>
            <button type="button" class="button button-primary" id="wash-oauth-connect"><?php esc_html_e('Connect with Shopify', 'wash-blog-manager'); ?></button>
        </div>
        <div style="flex:1;min-width:320px;">
            <h3><?php esc_html_e('Enter credentials manually', 'wash-blog-manager'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
        </div>
    </div>
</div>
