<?php
/**
 * Multi-store management section (#216, #252).
 *
 * Embedded inside the primary Manager page's Connection tab (settings-page.php),
 * NOT a standalone page — one nav entry, one add-store flow. Each additional
 * Shopify store is rendered as its OWN connection panel, identical in look to the
 * primary "Shopify Store Connection" panel above it (per owner feedback), with a
 * link to that store's sync settings plus enable/disable/remove. Its action forms
 * post to admin-post.php and are rendered OUTSIDE the main settings <form> by the
 * includer (no nested forms).
 */
if (!defined('ABSPATH')) {
    exit;
}

$washConnections = WashConnection::all();
$washCanAdd      = WashConnectionsAdmin::planAllowsMultiStore();

// Status notices from redirect query args (read-only; no state change).
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$washNotice = '';
if (isset($_GET['added'])) {
    $washNotice = __('Connection added.', 'wash-blog-manager');
} elseif (isset($_GET['removed'])) {
    $washNotice = __('Connection removed.', 'wash-blog-manager');
} elseif (isset($_GET['toggled'])) {
    $washNotice = __('Connection updated.', 'wash-blog-manager');
} elseif (isset($_GET['error']) && $_GET['error'] === 'plan') {
    $washNotice = __('Adding stores requires the Business or Enterprise plan.', 'wash-blog-manager');
} elseif (isset($_GET['error'])) {
    $washNotice = __('Could not complete the request. Please check the details and try again.', 'wash-blog-manager');
}
$washIsError = isset($_GET['error']);
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<h2 class="title"><?php esc_html_e('Store Connections', 'wash-blog-manager'); ?></h2>
<p class="description">
    <?php esc_html_e('Connect one or more Shopify stores. Published content syncs to every enabled connection.', 'wash-blog-manager'); ?>
</p>

<?php if ($washNotice !== '') : ?>
    <div class="notice <?php echo $washIsError ? 'notice-error' : 'notice-success'; ?> is-dismissible">
        <p><?php echo esc_html($washNotice); ?></p>
    </div>
<?php endif; ?>

<?php
foreach ($washConnections as $washId => $washConn) :
    if (!empty($washConn['isPrimary'])) {
        continue; // the primary is the "Shopify Store Connection" panel above
    }
    $washStore   = $washConn['store'] !== '' ? $washConn['store'] : __('(not set)', 'wash-blog-manager');
    $washEnabled = !empty($washConn['enabled']);
    // Primary stores a bare handle; additional stores a full domain or handle.
    $washHost    = (strpos($washStore, '.') !== false) ? $washStore : $washStore . '.myshopify.com';
    $washSettingsUrl = admin_url('options-general.php?page=wash-conn-' . $washId);
    // Live = green (unchanged); disabled/paused = grey, like a disconnected store.
    $washBg     = $washEnabled ? '#d4edda' : '#f0f0f1';
    $washBorder = $washEnabled ? '#c3e6cb' : '#dcdcde';
    $washDot    = $washEnabled ? '#28a745' : '#8c8f94';
    $washText   = $washEnabled ? '#155724' : '#50575e';
    ?>
    <div class="wash-connected-box" style="background: <?php echo esc_attr($washBg); ?>; border: 1px solid <?php echo esc_attr($washBorder); ?>; border-radius: 4px; padding: 20px; margin: 15px 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="width: 12px; height: 12px; background: <?php echo esc_attr($washDot); ?>; border-radius: 50%; display: inline-block;"></span>
                <strong style="color: <?php echo esc_attr($washText); ?>;"><?php echo $washEnabled ? esc_html__('Connected', 'wash-blog-manager') : esc_html__('Disabled', 'wash-blog-manager'); ?></strong>
                <span style="color: <?php echo esc_attr($washText); ?>;">
                    <?php esc_html_e('to', 'wash-blog-manager'); ?>
                    <a href="https://<?php echo esc_attr($washHost); ?>/admin" target="_blank" rel="noopener noreferrer" style="color: <?php echo esc_attr($washText); ?>; font-weight: 600;">
                        <?php echo esc_html($washHost); ?>
                    </a>
                </span>
            </div>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <a href="<?php echo esc_url($washSettingsUrl); ?>" class="button"><?php esc_html_e('Settings', 'wash-blog-manager'); ?></a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <?php wp_nonce_field('wash_toggle_connection'); ?>
                    <input type="hidden" name="action" value="wash_toggle_connection">
                    <input type="hidden" name="washConnectionId" value="<?php echo esc_attr($washId); ?>">
                    <input type="hidden" name="enabled" value="<?php echo $washEnabled ? '0' : '1'; ?>">
                    <button type="submit" class="button">
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
                    <button type="submit" class="button" style="color:#dc3545;border-color:#dc3545;"><?php esc_html_e('Remove', 'wash-blog-manager'); ?></button>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if (!$washCanAdd) : ?>
    <div class="notice notice-warning inline" style="margin-top:2em;">
        <p><?php esc_html_e('Adding more stores requires the Business or Enterprise plan. Existing connections keep working — upgrade to add another store.', 'wash-blog-manager'); ?></p>
    </div>
<?php else : ?>
<p style="margin-top:2em;">
    <button type="button" class="button button-primary" id="wash-new-connection-btn" aria-expanded="false" aria-controls="wash-new-connection">
        <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
        <?php esc_html_e('New connection', 'wash-blog-manager'); ?>
    </button>
</p>

<!-- Hidden until "New connection" is clicked: the same connect fields as the primary above (CSS layout, not a form-table). -->
<div id="wash-new-connection" style="display:none; max-width:900px;">
    <p><label for="wash-oauth-store"><strong><?php esc_html_e('Shopify Store', 'wash-blog-manager'); ?></strong></label></p>
    <p style="display: flex; align-items: center; gap: 8px;">
        <input type="text" id="wash-oauth-store" class="regular-text" placeholder="your-store" style="max-width: 200px;">
        <span style="color: #666;">.myshopify.com</span>
    </p>
    <p class="description"><?php esc_html_e('Your Shopify store handle (e.g., "my-store" from my-store.myshopify.com)', 'wash-blog-manager'); ?></p>
    <div style="margin: 12px 0;">
        <strong style="display:block; margin-bottom:8px;"><?php esc_html_e('Data processing consent', 'wash-blog-manager'); ?></strong>
        <?php include_once WASH_PLUGIN_DIR . 'admin/views/partials/consent-row.php'; ?>
    </div>
    <p>
        <button type="button" class="button button-primary" id="wash-oauth-connect">
            <span class="dashicons dashicons-admin-links" style="vertical-align: text-bottom;"></span>
            <?php esc_html_e('Connect to Shopify', 'wash-blog-manager'); ?>
        </button>
    </p>
    <details style="margin-top:8px;">
        <summary><?php esc_html_e('Enter credentials manually', 'wash-blog-manager'); ?></summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
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
</div>
<?php endif; ?>
