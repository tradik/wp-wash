<?php
if (!defined('ABSPATH')) {
    exit;
}

// Multi-store (#252): everything on this page is scoped to the ACTIVE connection
// (WashContext resolves it from the wash-conn-<id> page slug; primary on `wash`).
// Credentials live in the connection registry for additional stores, so read them
// from the connection — not from raw options.
$wash_connection = WashContext::connection();
$wash_isPrimary = WashContext::activeId() === WashConnection::PRIMARY;
$wash_isConnected = !empty($wash_connection['apiKey']);
$wash_shopifyStore = $wash_connection['store'];
$wash_shopifyAppSlug = WashContext::apiClient()->getShopifyAppSlug();

// Get WordPress permalink structure for URL examples
$wash_permalinkStructure = get_option('permalink_structure', '/%postname%/');
$wash_permalinkExample = str_replace(
    ['%year%', '%monthnum%', '%day%', '%postname%', '%post_id%', '%category%', '%author%'],
    [gmdate('Y'), gmdate('m'), gmdate('d'), '{slug}', '{id}', '{category}', '{author}'],
    $wash_permalinkStructure ?: '/{slug}/'
);
$wash_permalinkExample = home_url($wash_permalinkExample);

// Multi-language permalink (with {lang} prefix)
$wash_multiLangPermalink = home_url('/{lang}' . str_replace(home_url(''), '', $wash_permalinkExample));

// Category base from WordPress settings
$wash_categoryBase = get_option('category_base', 'category') ?: 'category';
$wash_categoryExample = home_url('/' . $wash_categoryBase . '/{category}/');

// Fetch plan info for feature gating
$wash_planInfo = null;
$wash_features = [];
$wash_upgradeUrl = '';
$wash_planName = 'FREE';
$wash_permissions = [];

// Check if plan refresh was requested. Read-only UI toggle on an admin page that's already capability-gated by the WP menu system; a nonce isn't applicable for a self-link refresh.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI toggle, value is only compared to '1' and never stored/echoed.
$wash_forceRefreshPlan = isset($_GET['refresh_plan']) && $_GET['refresh_plan'] === '1';

$wash_connectionError = '';

if ($wash_isConnected) {
    $wash_apiClient = WashContext::apiClient();

    // Force refresh plan info and permissions if requested via query parameter
    if ($wash_forceRefreshPlan) {
        $wash_apiClient->clearPlanInfoCache();
        $wash_apiClient->clearPermissionsCache();
    }

    $wash_planInfo = $wash_apiClient->getPlanInfo($wash_forceRefreshPlan);
    if (is_wp_error($wash_planInfo)) {
        $wash_connectionError = $wash_planInfo->get_error_message();
    } elseif (!empty($wash_planInfo['features'])) {
        $wash_features = $wash_planInfo['features'];
        $wash_upgradeUrl = $wash_planInfo['upgradeUrl'] ?? '';
        $wash_planName = $wash_planInfo['plan'] ?? 'FREE';
    }

    // Local feature overrides for features not yet returned by API
    if (in_array($wash_planName, ['BUSINESS', 'ENTERPRISE'], true)) {
        $wash_features['searchReplace'] = true;
    }

    // Fetch permissions from Shopify App
    $wash_permissions = $wash_apiClient->getPermissions();
    if (is_wp_error($wash_permissions)) {
        $wash_permissions = []; // Default to empty = all allowed
    }

    // Check if user can modify settings based on admin-only permissions
    $wash_userCanModifySettings = $wash_apiClient->canUserModifySettings($wash_permissions);
} else {
    $wash_userCanModifySettings = true; // Not connected, allow access
}

// If not defined above (e.g., not connected), default to true
if (!isset($wash_userCanModifySettings)) {
    $wash_userCanModifySettings = true;
}

// Tab to permission field mapping
$wash_tabPermissions = [
    'sync' => 'canEditSync',
    'blogs' => 'canEditBlogs',
    'hreflangs' => 'canEditHreflangs',
    'metafields' => 'canEditMeta',
    'authors' => 'canEditAuthors',
    'files' => 'canEditFiles',
    'content' => 'canEditContent',
    'redirections' => 'canEditRedirections',
    'searchreplace' => 'canEditSearchReplace',
    'advanced' => 'canEditAdvanced',
];

// Check if settings sync was requested
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI toggle (see refresh_plan above); value only compared to '1', never stored/echoed.
$wash_forceRefreshSettings = isset($_GET['refresh_settings']) && $_GET['refresh_settings'] === '1';

// Sync settings from Shopify if requested
if ($wash_forceRefreshSettings && $wash_isConnected) {
    $wash_apiClient = $wash_apiClient ?? WashContext::apiClient();
    $wash_settingsResponse = $wash_apiClient->getBackedUpSettings();
    if (!is_wp_error($wash_settingsResponse) && !empty($wash_settingsResponse['settings'])) {
        $wash_apiClient->restoreSettings($wash_settingsResponse['settings']);
    }
}

// Feature to tab mapping
$wash_tabFeatures = [
    'blogs' => 'blogMapping',
    'redirections' => 'smartRedirections',
    'hreflangs' => 'hreflang',
    'metafields' => 'metafields',
    'authors' => 'authorMapping',
    'searchreplace' => 'searchReplace',
];

// Required plans for locked features
$wash_featurePlans = [
    'blogMapping' => 'PRO',
    'smartRedirections' => 'BUSINESS',
    'hreflang' => 'PRO',
    'metafields' => 'PRO',
    'authorMapping' => 'PRO',
    'acfSync' => 'BUSINESS',
    'seoSync' => 'PRO',
    'searchReplace' => 'BUSINESS',
];

/**
 * Check if a tab/feature is locked (plan-based).
 */
function wash_is_feature_locked($wash_featureName, $wash_features) {
    if (empty($wash_features)) {
        return false; // No plan info, don't lock
    }
    return empty($wash_features[$wash_featureName]);
}

/**
 * Check if a tab is locked by Shopify permissions.
 */
function wash_is_permission_locked($wash_tab, $wash_tabPermissions, $wash_permissions) {
    if (empty($wash_permissions)) {
        return false; // No permissions info, don't lock
    }
    $wash_permissionField = $wash_tabPermissions[$wash_tab] ?? null;
    if (!$wash_permissionField) {
        return false; // Tab has no permission field
    }
    return isset($wash_permissions[$wash_permissionField]) && $wash_permissions[$wash_permissionField] === false;
}

/**
 * Render a tab link with optional locked state (plan or permission).
 */
function wash_render_tab($wash_tab, $wash_label, $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_isActive = false, $wash_permissions = [], $wash_tabPermissions = [], $wash_isConnected = true) {
    $wash_featureName = $wash_tabFeatures[$wash_tab] ?? null;
    $wash_isPlanLocked = $wash_featureName && wash_is_feature_locked($wash_featureName, $wash_features);
    $wash_isPermissionLocked = wash_is_permission_locked($wash_tab, $wash_tabPermissions, $wash_permissions);

    // Disable all tabs except 'connection' when not connected
    $wash_isDisconnected = !$wash_isConnected && $wash_tab !== 'connection';

    $wash_isLocked = $wash_isPlanLocked || $wash_isPermissionLocked;
    $wash_requiredPlan = $wash_featurePlans[$wash_featureName] ?? 'PRO';

    $wash_classes = 'nav-tab';
    if ($wash_isActive) {
        $wash_classes .= ' nav-tab-active';
    }
    if ($wash_isLocked) {
        $wash_classes .= ' nav-tab-locked';
    }
    if ($wash_isDisconnected) {
        $wash_classes .= ' nav-tab-disabled';
    }

    $wash_lockIcon = '';
    if ($wash_isLocked) {
        $wash_lockIcon = ' <span class="dashicons dashicons-lock" style="font-size: 14px; opacity: 0.6;"></span>';
    }

    if ($wash_isDisconnected) {
        $wash_title = esc_attr__('Connect to Shopify first', 'wash-blog-manager');
    } elseif ($wash_isPlanLocked) {
        // translators: %s is the name of the Shopify plan required to use this feature.
        $wash_title = sprintf(esc_attr__('Requires %s plan', 'wash-blog-manager'), $wash_requiredPlan);
    } elseif ($wash_isPermissionLocked) {
        $wash_title = esc_attr__('Locked by Shopify Admin', 'wash-blog-manager');
    } else {
        $wash_title = '';
    }

    printf(
        '<a href="#%s" class="%s" data-tab="%s" data-locked="%s" data-disabled="%s" data-required-plan="%s" data-upgrade-url="%s" title="%s">%s%s</a>',
        esc_attr($wash_tab),
        esc_attr($wash_classes),
        esc_attr($wash_tab),
        $wash_isLocked ? '1' : '0',
        $wash_isDisconnected ? '1' : '0',
        esc_attr($wash_requiredPlan),
        esc_url($wash_upgradeUrl),
        esc_attr($wash_title),
        esc_html($wash_label),
        wp_kses_post($wash_lockIcon)
    );
}

/**
 * Get tab content attributes for locked state (plan or permission).
 */
function wash_get_tab_content_class($wash_tab, $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions = [], $wash_tabPermissions = []) {
    $wash_featureName = $wash_tabFeatures[$wash_tab] ?? null;
    $wash_isPlanLocked = $wash_featureName && wash_is_feature_locked($wash_featureName, $wash_features);
    $wash_isPermissionLocked = wash_is_permission_locked($wash_tab, $wash_tabPermissions, $wash_permissions);
    $wash_requiredPlan = $wash_featurePlans[$wash_featureName] ?? 'PRO';
    $wash_isLocked = $wash_isPlanLocked || $wash_isPermissionLocked;

    $wash_class = 'wash-tab-content';
    if ($wash_isPlanLocked) {
        $wash_class .= ' wash-tab-content--locked';
    }
    if ($wash_isPermissionLocked) {
        $wash_class .= ' wash-tab-content--permission-locked';
    }

    return [
        'class' => $wash_class,
        'isLocked' => $wash_isLocked,
        'isPlanLocked' => $wash_isPlanLocked,
        'isPermissionLocked' => $wash_isPermissionLocked,
        'requiredPlan' => $wash_requiredPlan,
        'upgradeUrl' => $wash_upgradeUrl,
    ];
}

/**
 * Render a plan-locked banner.
 */
function wash_render_locked_banner($wash_requiredPlan, $wash_upgradeUrl) {
    ?>
    <div class="wash-locked-banner">
        <div class="wash-locked-banner__text">
            <span class="dashicons dashicons-lock"></span>
            <span>
                <?php
                // translators: %s is the name of the Shopify plan required to use this feature.
                printf(esc_html__('This feature requires %s plan', 'wash-blog-manager'), esc_html($wash_requiredPlan));
                ?>
            </span>
        </div>
        <div class="wash-locked-banner__actions">
            <a href="<?php echo esc_url($wash_upgradeUrl); ?>" class="button" target="_blank"><?php esc_html_e('Upgrade Now', 'wash-blog-manager'); ?></a>
        </div>
    </div>
    <?php
}

/**
 * Render a permission locked banner.
 */
function wash_render_permission_locked_banner() {
    ?>
    <div class="wash-permission-locked-banner" style="background: #fff8e6; border: 1px solid #ffc96b; border-radius: 4px; padding: 12px 15px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
        <span class="dashicons dashicons-lock" style="color: #856404; font-size: 20px;"></span>
        <div>
            <strong style="color: #856404;"><?php esc_html_e('Settings Locked by Shopify Admin', 'wash-blog-manager'); ?></strong>
            <p style="margin: 4px 0 0; color: #856404; font-size: 13px;">
                <?php esc_html_e('This section is controlled from the Shopify App. Changes made here will not be saved.', 'wash-blog-manager'); ?>
            </p>
        </div>
    </div>
    <?php
}
?>
<div class="wrap wash-settings">
    <?php
    // Connection was rejected because the CSRF nonce was missing/expired
    // (handleCallback()). Read-only display on this manage_options-gated page.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice, no state change.
    if (isset($_GET['connect_error']) && $_GET['connect_error'] === 'state') : ?>
    <div class="notice notice-error is-dismissible" style="margin: 15px 0;">
        <p>
            <strong>Wash:</strong>
            <?php esc_html_e('Connection could not be verified (the security token was missing or expired). Please click "Connect to Shopify" again to retry.', 'wash-blog-manager'); ?>
        </p>
    </div>
    <?php endif; ?>
    <?php if ($wash_forceRefreshPlan && empty($wash_connectionError)) : ?>
    <div class="notice notice-success is-dismissible" style="margin: 15px 0;">
        <p><?php esc_html_e('Plan information refreshed successfully.', 'wash-blog-manager'); ?></p>
    </div>
    <?php endif; ?>
    <?php if ($wash_isConnected && !empty($wash_connectionError)) : ?>
    <div class="notice notice-error" style="margin: 15px 0;">
        <p>
            <strong>Wash:</strong>
            <?php
            printf(
                /* translators: %s = error message */
                esc_html__('Cannot connect to Shopify App: %s', 'wash-blog-manager'),
                '<code>' . esc_html($wash_connectionError) . '</code>'
            );
            ?>
        </p>
        <p style="margin-top: 4px; font-size: 12px; color: #646970;">
            <?php esc_html_e('Check that your API key is valid, the Shopify App is running, and your server\'s IP is not blocked by a firewall. If you use HTTP Basic Auth, verify the credentials in the Connection tab.', 'wash-blog-manager'); ?>
        </p>
    </div>
    <?php endif; ?>
    <h1>
        <img src="<?php echo esc_url(washPluginUrl() . 'admin/assets/logo.png'); ?>" alt="Wash" style="height: 28px; width: auto; margin-right: 8px; vertical-align: middle;" />
        <?php esc_html_e('Wash Settings', 'wash-blog-manager'); ?>
        <?php if ($wash_shopifyStore !== '') :
            // Which store am I configuring? Show it in the heading (#252) — essential
            // once there is more than one connection.
            $wash_h1Host = (strpos($wash_shopifyStore, '.') !== false) ? $wash_shopifyStore : $wash_shopifyStore . '.myshopify.com';
            ?>
            <span style="font-size: 15px; font-weight: 600; color: #1d2327; margin-left: 8px; vertical-align: middle;">
                · <?php echo esc_html($wash_h1Host); ?>
            </span>
        <?php endif; ?>
        <span style="font-size: 12px; font-weight: normal; color: #646970; margin-left: 8px; vertical-align: middle;">
            v<?php echo esc_html(WASH_VERSION); ?>
        </span>
        <?php if ($wash_isConnected && $wash_planName) : ?>
            <span class="wash-plan-badge" style="font-size: 12px; font-weight: 600; background: <?php echo $wash_planName === 'FREE' ? '#f0f0f1' : '#d4edda'; ?>; color: <?php echo $wash_planName === 'FREE' ? '#666' : '#155724'; ?>; padding: 4px 10px; border-radius: 12px; margin-left: 10px; vertical-align: middle;">
                <?php echo esc_html($wash_planName); ?>
            </span>
        <?php endif; ?>
        <?php if ($wash_isConnected) : ?>
            <a href="<?php echo esc_url(add_query_arg(['refresh_plan' => '1', 'refresh_settings' => '1'])); ?>" title="<?php esc_attr_e('Sync all: settings and plan info', 'wash-blog-manager'); ?>" style="color: #646970; margin-left: 8px; vertical-align: middle; text-decoration: none;">
                <span class="dashicons dashicons-update" style="font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span>
            </a>
        <?php endif; ?>
    </h1>

    <?php if ($wash_isConnected && !is_wp_error($wash_planInfo) && !empty($wash_planInfo['usage'])) : ?>
    <div class="wash-usage-summary" style="background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; padding: 12px 15px; margin: 15px 0; display: flex; gap: 30px; font-size: 13px;">
        <div>
            <strong><?php esc_html_e('Articles:', 'wash-blog-manager'); ?></strong>
            <?php
            $wash_articlesUsed = $wash_planInfo['usage']['articlesUsed'] ?? 0;
            $wash_articlesLimit = $wash_planInfo['usage']['articlesLimit'] ?? -1;
            if ($wash_articlesLimit === -1) {
                echo esc_html($wash_articlesUsed) . ' ' . esc_html__('(unlimited)', 'wash-blog-manager');
            } else {
                echo esc_html($wash_articlesUsed) . ' / ' . esc_html($wash_articlesLimit);
            }
            ?>
        </div>
        <div>
            <strong><?php esc_html_e('Edits:', 'wash-blog-manager'); ?></strong>
            <?php
            $wash_editsUsed = $wash_planInfo['usage']['editsUsed'] ?? 0;
            $wash_editsLimit = $wash_planInfo['usage']['editsLimit'] ?? -1;
            if ($wash_editsLimit === -1) {
                echo esc_html($wash_editsUsed) . ' ' . esc_html__('(unlimited)', 'wash-blog-manager');
            } else {
                echo esc_html($wash_editsUsed) . ' / ' . esc_html($wash_editsLimit);
            }
            ?>
        </div>
        <?php if ($wash_planName === 'FREE' && !empty($wash_upgradeUrl)) : ?>
        <div style="margin-left: auto;">
            <a href="<?php echo esc_url($wash_upgradeUrl); ?>" target="_blank" style="color: #0073aa; text-decoration: none;">
                <?php esc_html_e('Upgrade Plan', 'wash-blog-manager'); ?> →
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($wash_isConnected && !$wash_userCanModifySettings) : ?>
    <div class="notice notice-warning" style="margin: 15px 0;">
        <p>
            <span class="dashicons dashicons-visibility" style="color: #856404; vertical-align: text-bottom; margin-right: 5px;"></span>
            <strong><?php esc_html_e('View Only Mode', 'wash-blog-manager'); ?></strong> —
            <?php esc_html_e('Your account does not have permission to modify Wash settings. Settings are displayed in read-only mode. Contact your administrator for access.', 'wash-blog-manager'); ?>
        </p>
    </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper wash-tabs">
        <?php wash_render_tab('connection', __('Connection', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, true, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('blogs', __('Blogs', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('redirections', __('Redirections', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('hreflangs', __('Hreflangs', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('metafields', __('Meta Fields', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('authors', __('Authors', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('files', __('Files & Media', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('content', __('Content Processing', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('searchreplace', __('Search & Replace', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('sync', __('Sync Settings', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
        <?php wash_render_tab('advanced', __('Advanced', 'wash-blog-manager'), $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, false, $wash_permissions, $wash_tabPermissions, $wash_isConnected); ?>
    </nav>

    <form method="post" action="options.php" id="wash-settings-form"<?php echo $wash_userCanModifySettings ? '' : ' class="wash-readonly-mode"'; ?>>
        <?php settings_fields('wash_settings'); ?>
        <input type="hidden" name="_wp_http_referer_tab" id="wash-current-tab" value="connection" />
        <input type="hidden" id="wash-user-can-modify" value="<?php echo $wash_userCanModifySettings ? '1' : '0'; ?>" />
        <?php // Multi-store (#252): tells the Settings API save which store these values belong to. ?>
        <input type="hidden" name="washConnectionId" value="<?php echo esc_attr(WashContext::activeId()); ?>" />

        <!-- Connection Tab -->
        <div class="wash-tab-content" id="tab-connection">
            <h2 class="title"><?php esc_html_e('Shopify Store Connection', 'wash-blog-manager'); ?></h2>

            <?php if (!$wash_isPrimary) :
                // Additional store: show its identity here; pausing/removing it lives
                // in the Store Connections hub (those are separate admin-post forms
                // that must not nest inside this settings form).
                $wash_storeHost = (strpos($wash_shopifyStore, '.') !== false) ? $wash_shopifyStore : $wash_shopifyStore . '.myshopify.com';
                ?>
                <div class="wash-connected-box" style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 20px; margin: 15px 0;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="width: 12px; height: 12px; background: #28a745; border-radius: 50%; display: inline-block;"></span>
                        <strong style="color: #155724;"><?php esc_html_e('Connected', 'wash-blog-manager'); ?></strong>
                        <span style="color: #155724;">
                            <?php esc_html_e('to', 'wash-blog-manager'); ?>
                            <a href="https://<?php echo esc_attr($wash_storeHost); ?>/admin" target="_blank" rel="noopener noreferrer" style="color: #155724; font-weight: 600;">
                                <?php echo esc_html($wash_storeHost); ?>
                            </a>
                        </span>
                    </div>
                </div>
                <p class="description" style="margin-top: 12px;">
                    <?php
                    printf(
                        /* translators: %s: link to the Store Connections hub */
                        esc_html__('These settings apply only to this store. To pause or remove it, go to %s.', 'wash-blog-manager'),
                        '<a href="' . esc_url(admin_url('options-general.php?page=wash&tab=connection')) . '">' . esc_html__('Store Connections', 'wash-blog-manager') . '</a>'
                    );
                    ?>
                </p>
            <?php elseif ($wash_isConnected) : ?>
                <!-- Connected state - simple view -->
                <div class="wash-connected-box" style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 20px; margin: 15px 0;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="width: 12px; height: 12px; background: #28a745; border-radius: 50%; display: inline-block;"></span>
                            <strong style="color: #155724;"><?php esc_html_e('Connected', 'wash-blog-manager'); ?></strong>
                            <?php if ($wash_shopifyStore) : ?>
                                <span style="color: #155724;">
                                    <?php esc_html_e('to', 'wash-blog-manager'); ?>
                                    <a href="https://<?php echo esc_attr($wash_shopifyStore); ?>.myshopify.com/admin" target="_blank" rel="noopener noreferrer" style="color: #155724; font-weight: 600;">
                                        <?php echo esc_html($wash_shopifyStore); ?>.myshopify.com
                                    </a>
                                </span>
                            <?php endif; ?>
                        </div>
                        <button type="button" id="wash-disconnect-btn" class="button" style="color: #dc3545; border-color: #dc3545;">
                            <span class="dashicons dashicons-dismiss" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Disconnect', 'wash-blog-manager'); ?>
                        </button>
                    </div>
                </div>
                <!-- Hidden fields for connected state -->
                <input type="hidden" name="wash_shopify_store" value="<?php echo esc_attr($wash_shopifyStore); ?>" />
                <input type="hidden" name="wash_api_key" value="<?php echo esc_attr(WashContext::option('wash_api_key')); ?>" />
            <?php else : ?>
                <!-- Not connected — single reusable connect form (#264), shared with the add-store panel. -->
                <?php $wash_addMode = false; include_once WASH_PLUGIN_DIR . 'admin/views/partials/connect-form.php'; ?>
            <?php endif; ?>
        </div>

        <!-- Blogs Tab -->
        <?php $wash_blogsTabAttrs = wash_get_tab_content_class('blogs', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_blogsTabAttrs['class']); ?>" id="tab-blogs" style="display: none;" data-locked="<?php echo $wash_blogsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($wash_blogsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($wash_blogsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($wash_blogsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php elseif (!empty($wash_blogsTabAttrs['isPlanLocked'])) : ?>
                <?php wash_render_locked_banner($wash_blogsTabAttrs['requiredPlan'], $wash_blogsTabAttrs['upgradeUrl']); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Blog Routing Rules', 'wash-blog-manager'); ?></h2>
            <p class="description">
                <?php esc_html_e('Route WordPress posts to different Shopify blogs based on language or category.', 'wash-blog-manager'); ?>
                <?php esc_html_e('Higher priority rules are matched first. Default rule is used when no other rule matches.', 'wash-blog-manager'); ?>
            </p>

            <?php if ($wash_isConnected) : ?>
                <div id="wash-blog-mappings">
                    <div class="wash-mappings-toolbar">
                        <div class="wash-mappings-toolbar-left">
                            <button type="button" id="wash-add-mapping" class="button button-primary">
                                <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Add Mapping Rule', 'wash-blog-manager'); ?>
                            </button>
                            <button type="button" id="wash-export-mappings" class="button" title="<?php esc_attr_e('Export rules as JSON', 'wash-blog-manager'); ?>">
                                <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Export', 'wash-blog-manager'); ?>
                            </button>
                            <button type="button" id="wash-import-mappings" class="button" title="<?php esc_attr_e('Import rules from JSON', 'wash-blog-manager'); ?>">
                                <span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Import', 'wash-blog-manager'); ?>
                            </button>
                            <input type="file" id="wash-import-mappings-file" accept=".json" style="display:none;" />
                        </div>
                        <div id="wash-mappings-filter-slot" data-no-dirty="1"></div>
                    </div>

                    <div class="wash-mappings-list" id="wash-mappings-list">
                        <!-- Mappings loaded via JavaScript -->
                        <p class="wash-loading"><?php esc_html_e('Loading mappings...', 'wash-blog-manager'); ?></p>
                    </div>

                    <!-- Add/Edit Mapping Form (hidden by default) -->
                    <div id="wash-mapping-form" style="display: none; margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3 id="wash-mapping-form-title"><?php esc_html_e('Add Mapping Rule', 'wash-blog-manager'); ?></h3>
                        <input type="hidden" id="wash-mapping-id" value="" />

                        <?php
                        $wash_hreflangsHandler = new WashHreflangs();
                        $wash_translationPlugin = $wash_hreflangsHandler->detectActivePlugin();
                        $wash_availableLanguages = $wash_translationPlugin ? $wash_hreflangsHandler->getAvailableLanguages() : [];
                        // Fetch categories across all languages (WPML per-language context returns 0)
                        $wash_currentLang = null;
                        if ($wash_translationPlugin === 'wpml' && function_exists('wpml_get_current_language')) {
                            $wash_currentLang = wpml_get_current_language();
                            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML plugin action.
                            do_action('wpml_switch_language', 'all');
                        } elseif ($wash_translationPlugin === 'polylang' && function_exists('pll_current_language')) {
                            $wash_currentLang = pll_current_language();
                            PLL()->curlang = null;
                        }
                        $wash_categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);
                        if ($wash_currentLang !== null) {
                            if ($wash_translationPlugin === 'wpml') {
                                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML plugin action.
                                do_action('wpml_switch_language', $wash_currentLang);
                            } elseif ($wash_translationPlugin === 'polylang') {
                                PLL()->curlang = PLL()->model->get_language($wash_currentLang);
                            }
                        }
                        ?>
                        <input type="hidden" id="wash-rule-type" value="default" />

                        <!-- WordPress Source section -->
                        <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px;">
                            <h4 style="margin: 0 0 10px; display: flex; align-items: center; gap: 8px; color: #0073aa;">
                                <span class="dashicons dashicons-wordpress"></span>
                                <?php esc_html_e('WordPress Source', 'wash-blog-manager'); ?>
                                <span style="font-weight: normal; color: #6b7280; font-size: 12px;">— <?php esc_html_e('which posts match this rule', 'wash-blog-manager'); ?></span>
                            </h4>
                            <table class="form-table" role="presentation" style="margin-top: 0;">
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('WP Post Types', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <fieldset id="wash-wp-post-types-fieldset">
                                            <?php
                                            $wash_allPostTypes = get_post_types(['public' => true], 'objects');
                                            foreach ($wash_allPostTypes as $wash_pt) :
                                                if ($wash_pt->name === 'attachment') continue;
                                            ?>
                                                <label style="display: inline-block; margin-right: 14px; margin-bottom: 6px;">
                                                    <input type="checkbox" class="wash-pt-checkbox" value="<?php echo esc_attr($wash_pt->name); ?>"
                                                        <?php echo in_array($wash_pt->name, ['post'], true) ? 'checked' : ''; ?> />
                                                    <?php echo esc_html($wash_pt->label); ?>
                                                    <code style="font-size: 11px; color: #666;">(<?php echo esc_html($wash_pt->name); ?>)</code>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <input type="hidden" id="wash-wp-post-types" value="post" />
                                        <p class="description"><?php esc_html_e('Select which WordPress post types this rule applies to.', 'wash-blog-manager'); ?></p>
                                    </td>
                                </tr>
                                <tr id="wash-category-row">
                                    <th scope="row">
                                        <label for="wash-rule-value-cat"><?php esc_html_e('Category', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-rule-value-cat" class="regular-text">
                                            <option value=""><?php esc_html_e('All categories (catch-all)', 'wash-blog-manager'); ?></option>
                                            <?php
                                            // Deduplicate categories by slug — WPML/Polylang return one entry per language
                                            $wash_seenSlugs = [];
                                            foreach ($wash_categories as $wash_cat) :
                                                if (isset($wash_seenSlugs[$wash_cat->slug])) continue;
                                                $wash_seenSlugs[$wash_cat->slug] = true;
                                            ?>
                                                <option value="<?php echo esc_attr($wash_cat->slug); ?>">
                                                    <?php echo esc_html($wash_cat->name . ' (' . $wash_cat->slug . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('Filter by WordPress category slug. Combine with Language below for per-market routing (e.g. art + de-ch → Swiss German articles).', 'wash-blog-manager'); ?></p>
                                    </td>
                                </tr>
                                <?php if ($wash_translationPlugin) : ?>
                                <tr>
                                    <th scope="row">
                                        <label for="wash-rule-value-lang"><?php esc_html_e('Language', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-rule-value-lang" class="regular-text">
                                            <option value=""><?php esc_html_e('All languages', 'wash-blog-manager'); ?></option>
                                            <?php foreach ($wash_availableLanguages as $wash_lang) : ?>
                                                <option value="<?php echo esc_attr($wash_lang['code']); ?>">
                                                    <?php echo esc_html($wash_lang['name'] . ' (' . $wash_lang['code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">
                                            <?php
                                            // translators: %s is the name of the detected translation plugin.
                                            printf(esc_html__('Detected plugin: %s', 'wash-blog-manager'), '<strong>' . esc_html($wash_translationPlugin) . '</strong>');
                                            ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>

                        <!-- Shopify Destination section -->
                        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px;">
                            <h4 style="margin: 0 0 10px; display: flex; align-items: center; gap: 8px; color: #065f46;">
                                <span class="dashicons dashicons-cart"></span>
                                <?php esc_html_e('Shopify Destination', 'wash-blog-manager'); ?>
                                <span style="font-weight: normal; color: #6b7280; font-size: 12px;">— <?php esc_html_e('where matched posts are synced', 'wash-blog-manager'); ?></span>
                            </h4>
                            <table class="form-table" role="presentation" style="margin-top: 0;">
                                <tr>
                                    <th scope="row">
                                        <label for="wash-destination"><?php esc_html_e('Type', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-destination" class="regular-text">
                                            <option value="article"><?php esc_html_e('Blog Article (/blogs/...)', 'wash-blog-manager'); ?></option>
                                            <option value="page"><?php esc_html_e('Page (/pages/...)', 'wash-blog-manager'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr id="wash-blog-row">
                                    <th scope="row">
                                        <label for="wash-shopify-blog"><?php esc_html_e('Target Blog', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-shopify-blog" class="regular-text" style="margin-bottom: 10px;">
                                            <option value=""><?php esc_html_e('Select a blog...', 'wash-blog-manager'); ?></option>
                                            <option value="_new"><?php esc_html_e('➕ Create new blog', 'wash-blog-manager'); ?></option>
                                        </select>
                                        <div id="wash-new-blog-row" style="display: none; margin-bottom: 10px;">
                                            <input type="text" id="wash-new-blog-name" class="regular-text" placeholder="<?php esc_attr_e('New blog name (e.g., blog-en)', 'wash-blog-manager'); ?>" />
                                        </div>
                                    </td>
                                </tr>
                                <tr id="wash-domain-row">
                                    <th scope="row">
                                        <label for="wash-destination-domain"><?php esc_html_e('Destination Domain', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-destination-domain" class="regular-text">
                                            <option value=""><?php esc_html_e('Default (primary domain)', 'wash-blog-manager'); ?></option>
                                            <!-- Options loaded via JavaScript from /api/shop-domains -->
                                        </select>
                                        <p class="description"><?php esc_html_e('Custom domain for preview URLs (e.g., shopifydev.domain.com)', 'wash-blog-manager'); ?></p>
                                    </td>
                                </tr>
                                <tr id="wash-market-locale-row">
                                    <th scope="row">
                                        <label for="wash-market-locale"><?php esc_html_e('Market & Locale', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-market-locale" class="regular-text">
                                            <option value=""><?php esc_html_e('Default (primary market)', 'wash-blog-manager'); ?></option>
                                            <!-- Options loaded via JavaScript from Shopify Markets / Locales API -->
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('🌍 Global = one shop-wide translation (Shopify Translate & Adapt). 📍 Market = override applied only when the buyer is in that market.', 'wash-blog-manager'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr style="display:none;">
                                    <th scope="row">
                                        <label for="wash-priority"><?php esc_html_e('Priority', 'wash-blog-manager'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="wash-priority" class="small-text" value="0" min="0" max="100" />
                                        <p class="description"><?php esc_html_e('Managed by drag-and-drop order', 'wash-blog-manager'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p class="submit">
                            <button type="button" id="wash-save-mapping" class="button button-primary">
                                <?php esc_html_e('Save Mapping', 'wash-blog-manager'); ?>
                            </button>
                            <button type="button" id="wash-cancel-mapping" class="button">
                                <?php esc_html_e('Cancel', 'wash-blog-manager'); ?>
                            </button>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- URL Structure Comparison -->
            <details id="wash-url-structure-guide" style="margin-top: 30px; border: 1px solid #c3c4c7; border-radius: 4px;">
                <summary style="padding: 12px 15px; background: #f6f7f7; cursor: pointer; font-weight: 600; border-radius: 4px;">
                    <span class="dashicons dashicons-info" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('URL Structure Guide', 'wash-blog-manager'); ?>
                </summary>
                <div style="padding: 15px;">
                    <p><?php esc_html_e('How your URLs transform from WordPress to Shopify:', 'wash-blog-manager'); ?></p>
                    <table class="widefat striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th style="width: 15%;"><?php esc_html_e('Type', 'wash-blog-manager'); ?></th>
                                <th style="width: 42.5%; background: #fef7f1;">
                                    <span class="dashicons dashicons-wordpress" style="color: #21759b; vertical-align: text-bottom;"></span>
                                    <?php esc_html_e('WordPress (Current)', 'wash-blog-manager'); ?>
                                </th>
                                <th style="width: 42.5%; background: #f0fff4;">
                                    <span class="dashicons dashicons-store" style="color: #96bf48; vertical-align: text-bottom;"></span>
                                    <?php esc_html_e('Shopify (After Sync)', 'wash-blog-manager'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Blog Posts', 'wash-blog-manager'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html($wash_permalinkExample); ?></code><br>
                                    <span style="color: #666; font-size: 11px;">
                                        <?php esc_html_e('Current setting:', 'wash-blog-manager'); ?>
                                        <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" style="text-decoration: none;">
                                            <code style="font-size: 10px;"><?php echo esc_html($wash_permalinkStructure ?: __('Plain', 'wash-blog-manager')); ?></code>
                                        </a>
                                    </span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($wash_shopifyStore ?: '{store}'); ?>.myshopify.com/blogs/{blog}/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Blog handle configured above', 'wash-blog-manager'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Multi-language', 'wash-blog-manager'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html($wash_multiLangPermalink); ?></code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Language prefix + permalink structure', 'wash-blog-manager'); ?></span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($wash_shopifyStore ?: '{store}'); ?>.myshopify.com/blogs/{blog}-{lang}/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Option A: Language in blog handle', 'wash-blog-manager'); ?></span><br>
                                    <code style="margin-top: 4px; display: inline-block;">https://<?php echo esc_html($wash_shopifyStore ?: '{store}'); ?>.myshopify.com/{lang}/blogs/{blog}/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Option B: Shopify Markets subfolders', 'wash-blog-manager'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Pages', 'wash-blog-manager'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html(home_url('/{slug}/')); ?></code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Page slug becomes URL', 'wash-blog-manager'); ?></span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($wash_shopifyStore ?: '{store}'); ?>.myshopify.com/pages/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Prefixed with /pages/', 'wash-blog-manager'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Categories', 'wash-blog-manager'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html($wash_categoryExample); ?></code><br>
                                    <span style="color: #666; font-size: 11px;">
                                        <?php esc_html_e('Category base:', 'wash-blog-manager'); ?>
                                        <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" style="text-decoration: none;">
                                            <code style="font-size: 10px;">/<?php echo esc_html($wash_categoryBase); ?>/</code>
                                        </a>
                                    </span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($wash_shopifyStore ?: '{store}'); ?>.myshopify.com/blogs/{blog}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Categories become blogs', 'wash-blog-manager'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Images', 'wash-blog-manager'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html(content_url('/uploads/{year}/{month}/{file}')); ?></code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Served from WordPress', 'wash-blog-manager'); ?></span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://cdn.shopify.com/s/files/1/{id}/{file}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Shopify CDN delivery', 'wash-blog-manager'); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin-top: 10px; font-size: 12px; color: #666;">
                        <span class="dashicons dashicons-info-outline" style="font-size: 14px; vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Variables: {slug} = post/page slug, {blog} = Shopify blog handle, {lang} = language code (en, de, pl), {store} = Shopify store name', 'wash-blog-manager'); ?>
                    </p>
                </div>
            </details>
        </div>

        <!-- Redirections Tab -->
        <?php $wash_redirectionsTabAttrs = wash_get_tab_content_class('redirections', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_redirectionsTabAttrs['class']); ?>" id="tab-redirections" style="display: none;" data-locked="<?php echo $wash_redirectionsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($wash_redirectionsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($wash_redirectionsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($wash_redirectionsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('URL Redirections Management', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Manage 301 redirects from WordPress URLs to Shopify URLs for SEO preservation during migration.', 'wash-blog-manager'); ?></p>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Smart Redirections', 'wash-blog-manager'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="wash_redirections_enabled" value="0" />
                                <input type="checkbox" name="wash_redirections_enabled" id="wash_redirections_enabled" value="1" <?php checked(WashContext::option('wash_redirections_enabled', '0'), '1'); ?>>
                                <?php esc_html_e('Automatically create redirects when posts are synced to Shopify', 'wash-blog-manager'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, Wash will create 301 redirects from your WordPress URLs to the corresponding Shopify URLs automatically during sync.', 'wash-blog-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit">
                <button type="button" id="wash-save-redirections-settings" class="button button-primary">
                    <?php esc_html_e('Save Settings', 'wash-blog-manager'); ?>
                </button>
                <span id="wash-redirections-save-status" style="margin-left: 10px;"></span>
            </p>

            <?php if ($wash_isConnected) : ?>
            <!-- Redirections Management Section -->
            <div class="wash-redirections-manager" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; display: flex; justify-content: space-between; align-items: center;">
                    <span>
                        <span class="dashicons dashicons-randomize" style="color: #2271b1;"></span>
                        <?php esc_html_e('Redirections Manager', 'wash-blog-manager'); ?>
                    </span>
                    <a href="https://wash.tradik.com/docs/smart-redirections/" target="_blank" style="font-size: 12px; font-weight: normal; color: #2271b1; text-decoration: none;">
                        <span class="dashicons dashicons-book-alt" style="font-size: 14px; vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Documentation', 'wash-blog-manager'); ?>
                    </a>
                </h3>

                <!-- Stats Bar -->
                <div id="wash-redirect-stats" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px 15px; margin-bottom: 20px;">
                    <span class="dashicons dashicons-chart-bar" style="color: #2271b1; vertical-align: middle;"></span>
                    <span id="wash-stats-text"><?php esc_html_e('Loading redirect stats...', 'wash-blog-manager'); ?></span>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                    <button type="button" id="wash-fetch-wp-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Sync from Old Website', 'wash-blog-manager'); ?>
                    </button>
                    <button type="button" id="wash-generate-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Generate from Synced Posts', 'wash-blog-manager'); ?>
                    </button>
                    <button type="button" id="wash-load-shopify-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-cloud" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Load from Shopify', 'wash-blog-manager'); ?>
                    </button>
                    <button type="button" id="wash-fix-session" class="button" style="display: none; background: #d63638; border-color: #d63638; color: #fff;">
                        <span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Fix Session Scopes', 'wash-blog-manager'); ?>
                    </button>
                </div>

                <!-- Textarea for Redirections -->
                <div style="margin-bottom: 15px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                        <label for="wash-redirections-textarea" style="font-weight: 600;">
                            <?php esc_html_e('Redirections List', 'wash-blog-manager'); ?>
                            <span style="font-weight: normal; color: #666;">
                                (<?php esc_html_e('format: /old-path → /new-path', 'wash-blog-manager'); ?>)
                            </span>
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" id="wash-verify-redirections" class="button button-secondary" title="<?php esc_attr_e('Verify syntax', 'wash-blog-manager'); ?>">
                                <span class="dashicons dashicons-yes-alt" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Verify', 'wash-blog-manager'); ?>
                            </button>
                            <button type="button" id="wash-unlock-redirections" class="button" title="<?php esc_attr_e('Unlock to edit', 'wash-blog-manager'); ?>">
                                <span class="dashicons dashicons-lock" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Unlock', 'wash-blog-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <div id="wash-redirections-container" style="position: relative;">
                        <textarea id="wash-redirections-textarea" rows="15" readonly style="width: 100%; font-family: monospace; font-size: 13px; background: #f6f7f7; cursor: not-allowed;" placeholder="<?php esc_attr_e('/blog/my-post → /blogs/news/my-post
/about-us → /pages/about-us
/category/news → /blogs/news

# Lines starting with # are comments', 'wash-blog-manager'); ?>"></textarea>
                        <div id="wash-redirections-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.02); cursor: pointer; display: flex; align-items: center; justify-content: center;">
                            <span style="background: #fff; padding: 8px 16px; border-radius: 4px; border: 1px solid #c3c4c7; color: #666; font-size: 13px;">
                                <span class="dashicons dashicons-lock" style="vertical-align: text-bottom; color: #d63638;"></span>
                                <?php esc_html_e('Click unlock button to edit', 'wash-blog-manager'); ?>
                            </span>
                        </div>
                    </div>
                    <div id="wash-verify-results" style="display: none; margin-top: 10px; padding: 12px; border-radius: 4px;"></div>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e('One redirection per line. Separators: →, ->, =>. Lines starting with # are comments.', 'wash-blog-manager'); ?>
                    </p>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button type="button" id="wash-sync-redirections" class="button button-primary button-large" disabled title="<?php esc_attr_e('Unlock to enable sync', 'wash-blog-manager'); ?>">
                        <span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Sync Redirections to Shopify', 'wash-blog-manager'); ?>
                    </button>
                    <span id="wash-sync-status" style="display: none;"></span>
                </div>

                <!-- Sync Results -->
                <div id="wash-sync-results" style="display: none; margin-top: 20px; padding: 15px; background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px;">
                    <h4 style="margin-top: 0; color: #155724;">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Sync Complete', 'wash-blog-manager'); ?>
                    </h4>
                    <ul id="wash-sync-results-list" style="margin: 0; padding-left: 20px;"></ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Hreflangs Tab -->
        <?php $wash_hreflangsTabAttrs = wash_get_tab_content_class('hreflangs', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_hreflangsTabAttrs['class']); ?>" id="tab-hreflangs" style="display: none;" data-locked="<?php echo $wash_hreflangsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($wash_hreflangsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($wash_hreflangsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($wash_hreflangsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Hreflang Tags Configuration', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Configure hreflang tags for multi-language SEO on Shopify.', 'wash-blog-manager'); ?></p>

            <?php
            $wash_hreflangsHandler = new WashHreflangs();
            $wash_activePlugin = $wash_hreflangsHandler->getActivePluginName();
            $wash_hasPlugin = $wash_hreflangsHandler->isMultilingualPluginActive();
            ?>

            <!-- Detected Plugin Status -->
            <div class="wash-info-section" style="background: <?php echo $wash_hasPlugin ? '#f0fff4' : '#fef0f0'; ?>; border: 2px solid <?php echo $wash_hasPlugin ? '#00a32a' : '#d63638'; ?>; border-radius: 8px; padding: 20px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span class="dashicons <?php echo $wash_hasPlugin ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $wash_hasPlugin ? '#00a32a' : '#d63638'; ?>;"></span>
                    <div>
                        <h3 style="margin: 0 0 5px 0;"><?php esc_html_e('Multilingual Plugin Status', 'wash-blog-manager'); ?></h3>
                        <p style="margin: 0; font-size: 15px;">
                            <?php if ($wash_hasPlugin): ?>
                                <?php printf(
                                    // translators: %s is the name of the detected plugin.
                                    esc_html__('Detected: %s', 'wash-blog-manager'),
                                    '<strong style="color: #00a32a;">' . esc_html($wash_activePlugin) . '</strong>'
                                ); ?>
                                <br><span style="color: #666;"><?php esc_html_e('Translations will be automatically detected and synced.', 'wash-blog-manager'); ?></span>
                            <?php else: ?>
                                <strong style="color: #d63638;"><?php esc_html_e('No multilingual plugin detected', 'wash-blog-manager'); ?></strong>
                                <br><span style="color: #666;"><?php esc_html_e('Install WPML, Polylang, TranslatePress, or Weglot to enable hreflang syncing.', 'wash-blog-manager'); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Hreflangs', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_hreflangs_enabled" value="0" />
                            <input type="checkbox" name="wash_hreflangs_enabled" value="1"
                                <?php checked(WashContext::option('wash_hreflangs_enabled'), '1'); ?>
                                <?php disabled(!$wash_hasPlugin); ?> />
                            <?php esc_html_e('Sync hreflang data to Shopify metafields', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, language relationship data from your multilingual plugin will be synced as metafields.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <!-- How It Works -->
            <div class="wash-accordion wash-accordion--info">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-info"></span>
                        <?php esc_html_e('How Hreflang Syncing Works', 'wash-blog-manager'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <p><?php esc_html_e('When enabled, wash will sync the following data as Shopify article metafields:', 'wash-blog-manager'); ?></p>
                    <table class="widefat" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Metafield Key', 'wash-blog-manager'); ?></th>
                                <th><?php esc_html_e('Namespace', 'wash-blog-manager'); ?></th>
                                <th><?php esc_html_e('Example Value', 'wash-blog-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>hreflang_en_us</code></td>
                                <td><code>wash_hreflang</code></td>
                                <td><code>https://store.myshopify.com/blogs/news/article-en</code></td>
                            </tr>
                            <tr>
                                <td><code>hreflang_de_de</code></td>
                                <td><code>wash_hreflang</code></td>
                                <td><code>https://store.myshopify.com/blogs/news/artikel-de</code></td>
                            </tr>
                            <tr>
                                <td><code>hreflang_pl_pl</code></td>
                                <td><code>wash_hreflang</code></td>
                                <td><code>https://store.myshopify.com/blogs/news/artykul-pl</code></td>
                            </tr>
                            <tr>
                                <td><code>hreflang_x_default</code></td>
                                <td><code>wash_hreflang</code></td>
                                <td><code>https://store.myshopify.com/blogs/news/article-en</code></td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin-top: 15px; color: #666; font-size: 13px;">
                        <span class="dashicons dashicons-info-outline"></span>
                        <?php esc_html_e('Each translation gets its own metafield. When a translation is removed in WordPress, the corresponding metafield is automatically cleared in Shopify.', 'wash-blog-manager'); ?>
                    </p>
                </div>
            </div>

            <!-- Liquid Code Example -->
            <div class="wash-accordion wash-accordion--dark">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-editor-code"></span>
                        <?php esc_html_e('Liquid Code for theme.liquid', 'wash-blog-manager'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <p style="color: #999; font-size: 13px;"><?php esc_html_e('Add this code inside the &lt;head&gt; section of your theme (layout/theme.liquid):', 'wash-blog-manager'); ?></p>
                    <pre style="background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5;">&lt;!-- Wash Hreflang Tags --&gt;
{% if article %}
  {% assign ns = 'wash_hreflang' %}

  {% for metafield in article.metafields[ns] %}
    {% if metafield.key contains 'hreflang_' %}
      {% assign lang_code = metafield.key | remove: 'hreflang_' | replace: '_', '-' %}
      &lt;link rel="alternate" hreflang="{{ lang_code }}" href="{{ metafield.value }}" /&gt;
    {% endif %}
  {% endfor %}
{% endif %}</pre>
                    <p style="color: #999; font-size: 12px; margin-top: 10px;">
                        <span class="dashicons dashicons-info-outline" style="color: #999;"></span>
                        <?php esc_html_e('This code automatically outputs all hreflang tags including x-default.', 'wash-blog-manager'); ?>
                    </p>
                </div>
            </div>

            <!-- Important Warning -->
            <div class="wash-accordion wash-accordion--warning">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Important: Shopify Theme Configuration', 'wash-blog-manager'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <p style="font-size: 14px; line-height: 1.6;">
                        <?php esc_html_e('Shopify does NOT natively output hreflang tags for blog articles. You must manually add the Liquid code above to your theme.', 'wash-blog-manager'); ?>
                    </p>
                    <ol style="margin-left: 20px; line-height: 2;">
                        <li>
                            <strong><?php esc_html_e('Edit Your Theme', 'wash-blog-manager'); ?></strong><br>
                            <span style="color: #666;"><?php esc_html_e('Go to Online Store → Themes → Edit code → layout/theme.liquid', 'wash-blog-manager'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Add the Liquid Code', 'wash-blog-manager'); ?></strong><br>
                            <span style="color: #666;"><?php esc_html_e('Paste the code above inside the &lt;head&gt; section, before &lt;/head&gt;', 'wash-blog-manager'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Save and Test', 'wash-blog-manager'); ?></strong><br>
                            <span style="color: #666;"><?php esc_html_e('View page source on an article to verify hreflang tags are present', 'wash-blog-manager'); ?></span>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- Alternative: Shopify Markets -->
            <div class="wash-accordion wash-accordion--tip">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-lightbulb"></span>
                        <?php esc_html_e('Alternative: Shopify Markets', 'wash-blog-manager'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px;">
                        <div>
                            <p><?php esc_html_e('For stores with Shopify Plus or compatible plans, consider using Shopify Markets:', 'wash-blog-manager'); ?></p>
                            <ul style="margin-left: 20px; line-height: 1.8;">
                                <li><?php esc_html_e('Built-in multi-language support', 'wash-blog-manager'); ?></li>
                                <li><?php esc_html_e('Automatic hreflang tag generation', 'wash-blog-manager'); ?></li>
                                <li><?php esc_html_e('No theme customization required', 'wash-blog-manager'); ?></li>
                            </ul>
                            <p style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                <strong><?php esc_html_e('Note:', 'wash-blog-manager'); ?></strong> <?php esc_html_e('Shopify Markets works independently of this plugin. Use this feature when Markets is not available or you need custom hreflang handling.', 'wash-blog-manager'); ?>
                            </p>
                        </div>
                        <a href="https://wash.tradik.com/docs/hreflangs/" target="_blank" rel="noopener noreferrer" class="button" style="flex-shrink: 0;">
                            <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                            <?php esc_html_e('Documentation', 'wash-blog-manager'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Meta Fields Tab -->
        <?php $wash_metafieldsTabAttrs = wash_get_tab_content_class('metafields', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_metafieldsTabAttrs['class']); ?>" id="tab-metafields" style="display: none;" data-locked="<?php echo $wash_metafieldsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($wash_metafieldsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($wash_metafieldsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($wash_metafieldsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Meta Fields Configuration', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('WordPress detects available meta fields from your posts. Toggle sync per field.', 'wash-blog-manager'); ?></p>

            <?php if (WashContext::option('wash_sync_metafields', '1') !== '1') : ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; color: #856404;">
                    <?php esc_html_e('Metafield sync is disabled. Enable "Sync Metafields" in the Sync tab to use this configuration.', 'wash-blog-manager'); ?>
                </div>
            <?php endif; ?>

            <div id="wash-metafields-content" style="<?php echo WashContext::option('wash_sync_metafields', '1') !== '1' ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
            <?php
            $wash_metafieldsHandler = new WashMetafields();
            $wash_fieldConfig = $wash_metafieldsHandler->getFieldConfig();
            $wash_sourceBadges = [
                'wordpress' => ['bg' => '#e8f4fd', 'color' => '#21759b'],
                'yoast'     => ['bg' => '#f5e6f0', 'color' => '#a4286a'],
                'rankmath'  => ['bg' => '#fde8e8', 'color' => '#e74c3c'],
                'acf'       => ['bg' => '#e6f9f0', 'color' => '#00875a'],
                'custom'    => ['bg' => '#f1f1f1', 'color' => '#646970'],
            ];
            ?>

            <div style="margin: 16px 0 12px; display: flex; align-items: center; gap: 10px;">
                <button type="button" id="wash-refresh-fields" class="button">
                    <span class="dashicons dashicons-update" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                    <?php esc_html_e('Refresh Fields', 'wash-blog-manager'); ?>
                </button>
                <span id="wash-fields-status" style="font-size: 13px; color: #646970;"></span>
            </div>

            <?php if (empty($wash_fieldConfig)) : ?>
                <div style="background: #f6f6f7; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px;">
                    <p style="margin: 0; color: #646970;"><?php esc_html_e('No fields detected. Click Refresh to scan your posts.', 'wash-blog-manager'); ?></p>
                </div>
            <?php else : ?>
                <table class="widefat striped" id="wash-meta-fields-table" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;"><?php esc_html_e('Sync', 'wash-blog-manager'); ?></th>
                            <th><?php esc_html_e('Field', 'wash-blog-manager'); ?></th>
                            <th><?php esc_html_e('Namespace', 'wash-blog-manager'); ?></th>
                            <th><?php esc_html_e('Source', 'wash-blog-manager'); ?></th>
                            <th><?php esc_html_e('Type', 'wash-blog-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($wash_fieldConfig as $wash_i => $wash_field) :
                            $wash_badge = $wash_sourceBadges[$wash_field['source']] ?? $wash_sourceBadges['custom'];
                        ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="wash-field-toggle"
                                        data-index="<?php echo (int)$wash_i; ?>"
                                        <?php checked(!empty($wash_field['syncEnabled'])); ?>
                                        style="width: 16px; height: 16px;" />
                                </td>
                                <td><strong><?php echo esc_html($wash_field['label'] ?? $wash_field['key']); ?></strong></td>
                                <td><code style="font-size: 12px;"><?php echo esc_html($wash_field['namespace']); ?></code></td>
                                <td>
                                    <span style="background: <?php echo esc_attr($wash_badge['bg']); ?>; color: <?php echo esc_attr($wash_badge['color']); ?>; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                        <?php echo esc_html($wash_field['source']); ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px; color: #646970;"><?php echo esc_html($wash_field['type']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php
            // wp.org compliance: deliver this admin JS via wp_add_inline_script()
            // instead of an inline script element. The block is buffered so all
            // PHP interpolations (esc_js, wp_json_encode) resolve to pure JS,
            // then attached after the already-enqueued `wash-admin` handle.
            ob_start();
            ?>
            (function() {
                var fieldConfig = <?php echo wp_json_encode($wash_fieldConfig); ?>;
                var saveTimer = null;
                var statusEl = document.getElementById('wash-fields-status');

                function saveFieldConfig() {
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(function() {
                        if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Saving...', 'wash-blog-manager')); ?>';
                        fetch(washAdmin.ajaxUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=wash_save_meta_field_config&nonce=' + washAdmin.nonce + '&config=' + encodeURIComponent(JSON.stringify(fieldConfig))
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!statusEl) return;
                            if (!data.success) {
                                statusEl.textContent = '<?php echo esc_js(__('Error saving', 'wash-blog-manager')); ?>';
                            } else if (data.data && data.data.synced) {
                                statusEl.textContent = '<?php echo esc_js(__('Saved & synced to Shopify', 'wash-blog-manager')); ?>';
                            } else {
                                statusEl.textContent = '<?php echo esc_js(__('Saved (Shopify sync pending)', 'wash-blog-manager')); ?>';
                            }
                        })
                        .catch(function() {
                            if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Error saving', 'wash-blog-manager')); ?>';
                        });
                    }, 300);
                }

                document.querySelectorAll('.wash-field-toggle').forEach(function(cb) {
                    cb.addEventListener('change', function() {
                        var idx = parseInt(this.dataset.index, 10);
                        fieldConfig[idx].syncEnabled = this.checked;
                        saveFieldConfig();
                    });
                });

                document.getElementById('wash-refresh-fields')?.addEventListener('click', function() {
                    var btn = this;
                    btn.disabled = true;
                    if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Scanning...', 'wash-blog-manager')); ?>';

                    fetch(washAdmin.ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wash_detect_meta_fields&nonce=' + washAdmin.nonce
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            statusEl.textContent = data.data.fields.length + ' <?php echo esc_js(__('fields detected', 'wash-blog-manager')); ?>';
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            statusEl.textContent = '<?php echo esc_js(__('Error', 'wash-blog-manager')); ?>';
                        }
                        btn.disabled = false;
                    })
                    .catch(function() {
                        if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Error', 'wash-blog-manager')); ?>';
                        btn.disabled = false;
                    });
                });
            })();
            <?php wp_add_inline_script('wash-admin', ob_get_clean(), 'after'); ?>

            <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                <a href="https://wash.tradik.com/docs/custom-fields/" target="_blank" rel="noopener noreferrer" class="button" style="margin-right: 10px;">
                    <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                    <?php esc_html_e('Documentation', 'wash-blog-manager'); ?>
                </a>
            </div>
            </div><!-- /wash-metafields-content -->
        </div>

        <!-- Authors Tab -->
        <?php $wash_authorsTabAttrs = wash_get_tab_content_class('authors', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_authorsTabAttrs['class']); ?>" id="tab-authors" style="display: none;" data-locked="<?php echo $wash_authorsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($wash_authorsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($wash_authorsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($wash_authorsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Author Mapping', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Map WordPress users to custom author names displayed on Shopify articles.', 'wash-blog-manager'); ?></p>

            <?php
            $wash_authorsEnabled = WashContext::option('wash_authors_enabled', '0') === '1';
            $wash_authorMappings = WashContext::option('wash_author_mappings', []);
            if (!is_array($wash_authorMappings)) {
                $wash_authorMappings = [];
            }
            $wash_wpUsers = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Author Mapping', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_authors_enabled" value="0" />
                            <input type="checkbox" name="wash_authors_enabled" value="1" id="wash-authors-enabled"
                                <?php checked($wash_authorsEnabled); ?> />
                            <?php esc_html_e('Override WordPress author names with custom values', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When disabled, articles use the original WordPress author display name.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <div id="wash-author-mappings-section" style="<?php echo $wash_authorsEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                <h3 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #c3c4c7;">
                    <?php esc_html_e('User → Author Name Mapping', 'wash-blog-manager'); ?>
                </h3>

                <?php if (empty($wash_wpUsers)) : ?>
                    <p style="color: #666;"><?php esc_html_e('No users found.', 'wash-blog-manager'); ?></p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width: 800px;">
                        <thead>
                            <tr>
                                <th style="width: 30%;"><?php esc_html_e('WordPress User', 'wash-blog-manager'); ?></th>
                                <th style="width: 35%;"><?php esc_html_e('Current Display Name', 'wash-blog-manager'); ?></th>
                                <th style="width: 35%;"><?php esc_html_e('Shopify Author Name', 'wash-blog-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wash_wpUsers as $wash_user) : ?>
                                <?php
                                $wash_userId = $wash_user->ID;
                                $wash_currentMapping = isset($wash_authorMappings[$wash_userId]) ? $wash_authorMappings[$wash_userId] : '';
                                ?>
                                <tr>
                                    <td>
                                        <code><?php echo esc_html($wash_user->user_login); ?></code>
                                        <span style="color: #666; font-size: 12px;">(ID: <?php echo esc_html($wash_userId); ?>)</span>
                                    </td>
                                    <td>
                                        <?php echo esc_html($wash_user->display_name); ?>
                                    </td>
                                    <td>
                                        <input type="text"
                                               name="wash_author_mappings[<?php echo esc_attr($wash_userId); ?>]"
                                               value="<?php echo esc_attr($wash_currentMapping); ?>"
                                               class="regular-text"
                                               placeholder="<?php echo esc_attr($wash_user->display_name); ?>"
                                               style="width: 100%;" />
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="description" style="margin-top: 15px;">
                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                        <?php esc_html_e('Leave empty to use the original WordPress display name. Changes apply to newly synced articles.', 'wash-blog-manager'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 25px 0; max-width: 800px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px;">
                    <div>
                        <h4 style="margin-top: 0;">
                            <span class="dashicons dashicons-lightbulb" style="color: #2271b1;"></span>
                            <?php esc_html_e('How Author Mapping Works', 'wash-blog-manager'); ?>
                        </h4>
                        <ul style="margin-left: 20px; line-height: 1.8; margin-bottom: 0;">
                            <li><strong><?php esc_html_e('Disabled:', 'wash-blog-manager'); ?></strong> <?php esc_html_e('Articles show the WordPress author\'s display name (e.g., "admin" or "John Doe")', 'wash-blog-manager'); ?></li>
                            <li><strong><?php esc_html_e('Enabled:', 'wash-blog-manager'); ?></strong> <?php esc_html_e('Articles show the custom name you specify in the mapping table', 'wash-blog-manager'); ?></li>
                            <li><strong><?php esc_html_e('Use cases:', 'wash-blog-manager'); ?></strong> <?php esc_html_e('Pen names, company branding, consistent author attribution across sites', 'wash-blog-manager'); ?></li>
                        </ul>
                    </div>
                    <a href="https://wash.tradik.com/docs/author-mapping/" target="_blank" rel="noopener noreferrer" class="button" style="flex-shrink: 0;">
                        <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                        <?php esc_html_e('Documentation', 'wash-blog-manager'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Files & Media Tab -->
        <?php $wash_filesTabAttrs = wash_get_tab_content_class('files', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_filesTabAttrs['class']); ?>" id="tab-files" style="display: none;">
            <?php if (!empty($wash_filesTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Files & Media Configuration', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how images and media files are handled when syncing to Shopify.', 'wash-blog-manager'); ?></p>

            <?php
            $wash_filesEnabled = WashContext::option('wash_files_enabled', '1') === '1';
            $wash_webpConvert = WashContext::option('wash_webp_convert', '0') === '1';
            $wash_allowedFileTypes = WashContext::option('wash_allowed_file_types', ['images']);
            if (!is_array($wash_allowedFileTypes)) {
                $wash_allowedFileTypes = ['images'];
            }
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Upload Images', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_files_enabled" value="0" />
                            <input type="checkbox" name="wash_files_enabled" value="1" id="wash-files-enabled"
                                <?php checked($wash_filesEnabled); ?> />
                            <?php esc_html_e('Enable image uploads to Shopify Files', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, media attachments will be uploaded to Shopify CDN. Disable to skip image syncing.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
                <tr id="wash-webp-row" style="<?php echo $wash_filesEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                    <th scope="row"><?php esc_html_e('Convert to WebP', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_webp_convert" value="0" />
                            <input type="checkbox" name="wash_webp_convert" value="1" id="wash-webp-convert"
                                <?php checked($wash_webpConvert); ?> />
                            <?php esc_html_e('Convert images to modern WebP format', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('WebP provides better compression and faster load times. Original format preserved if conversion fails.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
                <tr id="wash-file-types-row" style="<?php echo $wash_filesEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                    <th scope="row"><?php esc_html_e('Allowed File Types', 'wash-blog-manager'); ?></th>
                    <td>
                        <fieldset>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="images"
                                    <?php checked(in_array('images', $wash_allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Images (JPEG, PNG, GIF, WebP, SVG)', 'wash-blog-manager'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash-blog-manager'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="pdf"
                                    <?php checked(in_array('pdf', $wash_allowedFileTypes, true)); ?> />
                                <?php esc_html_e('PDF Documents', 'wash-blog-manager'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash-blog-manager'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="video"
                                    <?php checked(in_array('video', $wash_allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Videos (MP4, MOV, WebM)', 'wash-blog-manager'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 1 GB each', 'wash-blog-manager'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="audio"
                                    <?php checked(in_array('audio', $wash_allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Audio (MP3, WAV, OGG)', 'wash-blog-manager'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash-blog-manager'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="documents"
                                    <?php checked(in_array('documents', $wash_allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Documents (DOC, XLS, PPT, TXT)', 'wash-blog-manager'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash-blog-manager'); ?></span>
                            </label>
                        </fieldset>
                        <p class="description" style="margin-top: 10px;">
                            <?php esc_html_e('Select which file types should be synced to Shopify Files.', 'wash-blog-manager'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- FAQ Section -->
            <h3 style="margin-top: 30px; margin-bottom: 15px;">
                <span class="dashicons dashicons-book" style="color: #2271b1;"></span>
                <?php esc_html_e('FAQ', 'wash-blog-manager'); ?>
            </h3>

            <!-- FAQ: How Image Syncing Works -->
            <details class="wash-faq" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #2271b1; outline: none;">
                    <span class="dashicons dashicons-format-image" style="vertical-align: middle;"></span>
                    <?php esc_html_e('How does image syncing work?', 'wash-blog-manager'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #c5d9ed;">
                    <ul style="margin-left: 20px; line-height: 1.8;">
                        <li><?php esc_html_e('Images are uploaded to Shopify CDN for faster global delivery', 'wash-blog-manager'); ?></li>
                        <li><?php esc_html_e('Image URLs in post content are automatically rewritten to Shopify CDN URLs', 'wash-blog-manager'); ?></li>
                        <li><?php esc_html_e('Featured images are synced as article images in Shopify', 'wash-blog-manager'); ?></li>
                        <li><?php esc_html_e('Use the Media Library to manually sync individual images', 'wash-blog-manager'); ?></li>
                    </ul>
                </div>
            </details>

            <!-- FAQ: WebP Format Benefits -->
            <details class="wash-faq" style="background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #00a32a; outline: none;">
                    <span class="dashicons dashicons-performance" style="vertical-align: middle;"></span>
                    <?php esc_html_e('What are the benefits of WebP format?', 'wash-blog-manager'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #96bf48;">
                    <table class="widefat" style="max-width: 600px;">
                        <tbody>
                            <tr>
                                <td style="width: 30%;"><strong><?php esc_html_e('Compression', 'wash-blog-manager'); ?></strong></td>
                                <td><?php esc_html_e('25-35% smaller than JPEG at same quality', 'wash-blog-manager'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Browser Support', 'wash-blog-manager'); ?></strong></td>
                                <td><?php esc_html_e('All modern browsers (Chrome, Firefox, Safari, Edge)', 'wash-blog-manager'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Supported Inputs', 'wash-blog-manager'); ?></strong></td>
                                <td><?php esc_html_e('JPEG, PNG, GIF, TIFF', 'wash-blog-manager'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <!-- FAQ: Shopify Files Limits -->
            <details class="wash-faq" style="background: #f0f6fc; border: 1px solid #0969da; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #0969da; outline: none;">
                    <span class="dashicons dashicons-info-outline" style="vertical-align: middle;"></span>
                    <?php esc_html_e('What are Shopify Files limits?', 'wash-blog-manager'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #0969da;">
                    <p style="margin: 0 0 10px;">
                        <?php esc_html_e('File size and storage limits are enforced by Shopify and vary by plan. Files exceeding these limits will be skipped during sync.', 'wash-blog-manager'); ?>
                    </p>
                    <a href="https://help.shopify.com/en/manual/shopify-admin/productivity-tools/file-uploads" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="margin-top: 5px;">
                        <span class="dashicons dashicons-external" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('View Shopify Files Documentation', 'wash-blog-manager'); ?>
                    </a>
                </div>
            </details>

            <!-- Troubleshooting: Featured Image -->
            <details class="wash-troubleshooting" style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #826200; outline: none;">
                    <span class="dashicons dashicons-editor-help" style="color: #dba617; vertical-align: middle;"></span>
                    <?php esc_html_e('Featured image not showing on Shopify?', 'wash-blog-manager'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dba617;">
                    <p style="margin-top: 0;">
                        <?php esc_html_e('If the featured image is visible in Shopify Admin but not on the blog page, your Shopify theme template needs to be updated.', 'wash-blog-manager'); ?>
                    </p>

                    <h5 style="margin: 15px 0 10px;"><?php esc_html_e('Solution: Add this code to your Shopify theme', 'wash-blog-manager'); ?></h5>
                    <p><?php esc_html_e('In Shopify Admin, go to:', 'wash-blog-manager'); ?> <strong>Online Store → Themes → Edit code</strong></p>
                    <p><?php esc_html_e('Find your article template (e.g.', 'wash-blog-manager'); ?> <code>sections/main-article.liquid</code> <?php esc_html_e('or', 'wash-blog-manager'); ?> <code>templates/article.liquid</code><?php esc_html_e(') and add:', 'wash-blog-manager'); ?></p>

                    <pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5;">
<span style="color: #6a9955;">{%- comment -%} Featured Image from WordPress {%- endcomment -%}</span>
<span style="color: #c586c0;">{%- if</span> article.image <span style="color: #c586c0;">-%}</span>
  &lt;figure class="article-featured-image"&gt;
    &lt;img
      src="<span style="color: #ce9178;">{{ article.image | image_url: width: 1200 }}</span>"
      alt="<span style="color: #ce9178;">{{ article.image.alt | default: article.title }}</span>"
      loading="eager"
      width="1200"
    /&gt;
  &lt;/figure&gt;
<span style="color: #c586c0;">{%- endif -%}</span></pre>

                    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-top: 15px;">
                        <strong style="color: #1e1e1e;">
                            <span class="dashicons dashicons-lightbulb" style="color: #dba617;"></span>
                            <?php esc_html_e('Pro tip:', 'wash-blog-manager'); ?>
                        </strong>
                        <span style="color: #555;">
                            <?php esc_html_e('You can also use our ready-made template from', 'wash-blog-manager'); ?>
                            <code>packages/shopify-theme-example/sections/wp-article-content.liquid</code>
                        </span>
                    </div>
                </div>
            </details>

            <!-- Troubleshooting: Content Images -->
            <details class="wash-troubleshooting" style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #826200; outline: none;">
                    <span class="dashicons dashicons-editor-help" style="color: #dba617; vertical-align: middle;"></span>
                    <?php esc_html_e('Images in post content not showing?', 'wash-blog-manager'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dba617;">
                    <p style="margin-top: 0;"><?php esc_html_e('If images within the article body are not displaying:', 'wash-blog-manager'); ?></p>

                    <ol style="margin-left: 20px; line-height: 1.8;">
                        <li>
                            <strong><?php esc_html_e('Check if images are synced:', 'wash-blog-manager'); ?></strong>
                            <?php esc_html_e('Go to Media Library and verify images have a green checkmark (synced) icon.', 'wash-blog-manager'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Sync images first:', 'wash-blog-manager'); ?></strong>
                            <?php esc_html_e('Click on an image and use "Sync to Shopify" button, then re-sync the post.', 'wash-blog-manager'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Check WordPress debug.log:', 'wash-blog-manager'); ?></strong>
                            <?php esc_html_e('Look for lines with [wash] to see sync status and any errors.', 'wash-blog-manager'); ?>
                        </li>
                    </ol>

                    <div style="display: flex; justify-content: space-between; align-items: center; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px; margin-top: 15px;">
                        <div>
                            <strong style="color: #2271b1;">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Note:', 'wash-blog-manager'); ?>
                            </strong>
                            <span style="color: #555;">
                                <?php esc_html_e('Images are automatically synced when publishing a post. The sync rewrites WordPress URLs to Shopify CDN URLs in the content.', 'wash-blog-manager'); ?>
                            </span>
                        </div>
                        <a href="https://wash.tradik.com/docs/images-and-files/" target="_blank" rel="noopener noreferrer" class="button" style="flex-shrink: 0; margin-left: 15px;">
                            <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                            <?php esc_html_e('Documentation', 'wash-blog-manager'); ?>
                        </a>
                    </div>
                </div>
            </details>
        </div>

        <!-- Sync Tab -->
        <?php $wash_syncTabAttrs = wash_get_tab_content_class('sync', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_syncTabAttrs['class']); ?>" id="tab-sync" style="display: none;">
            <?php if (!empty($wash_syncTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Sync Preferences', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how content is synchronized to Shopify.', 'wash-blog-manager'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Auto-sync on Publish', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_on_publish" value="0" />
                            <input type="checkbox" name="wash_sync_on_publish" value="1"
                                <?php checked(WashContext::option('wash_sync_on_publish'), '1'); ?> />
                            <?php esc_html_e('Automatically sync posts when published or updated', 'wash-blog-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Metafields', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_metafields" value="0" />
                            <input type="checkbox" name="wash_sync_metafields" value="1"
                                <?php checked(WashContext::option('wash_sync_metafields'), '1'); ?> />
                            <?php esc_html_e('Sync core and plugin meta fields to Shopify metafields', 'wash-blog-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Rewrite Internal URLs', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_rewrite_internal_urls" value="0" />
                            <input type="checkbox" name="wash_rewrite_internal_urls" value="1"
                                <?php checked(WashContext::option('wash_rewrite_internal_urls', '1'), '1'); ?> />
                            <?php esc_html_e('Convert internal WordPress links to Shopify URL format', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Rewrites links like /blog/post-slug to /blogs/{blog-handle}/post-slug and /page-slug to /pages/page-slug during sync.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
<tr>
                    <th scope="row"><?php esc_html_e('Bulk publish: inline progress mode', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_inline" value="0" />
                            <input type="checkbox" name="wash_sync_inline" value="1"
                                <?php checked(WashContext::option('wash_sync_inline', '1'), '1'); ?> />
                            <?php esc_html_e('Run bulk publish through the JS progress page (recommended)', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('ON (default): bulk action redirects to a live progress bar; the browser drives N batches via AJAX, immune to PHP / proxy timeouts and works on private / password-protected sites where WP-Cron does not fire. OFF: legacy WP-Cron mode — each sync is queued as a single cron event. Requires WP-Cron to actually run; broken on idle sites.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Auto-clear stale sync errors', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_auto_clear_errors" value="0" />
                            <input type="checkbox" name="wash_auto_clear_errors" value="1"
                                <?php checked(WashContext::option('wash_auto_clear_errors', '1'), '1'); ?> />
                            <?php esc_html_e('Daily WP-Cron job clears sync errors older than 30 days on published posts', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Stops the red "sync failed" icon from accumulating indefinitely. Adjustable via the wash_sync_error_retention_days filter.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Post Types', 'wash-blog-manager'); ?></th>
                    <td>
                        <?php
                        $wash_syncPostTypes = array_map('trim', explode(',', WashContext::option('wash_post_types', 'post,page')));
                        $wash_publicPostTypes = get_post_types(['public' => true], 'objects');
                        ?>
                        <fieldset>
                            <?php foreach ($wash_publicPostTypes as $wash_pt) :
                                if ($wash_pt->name === 'attachment') continue;
                            ?>
                                <label style="display: inline-block; margin-right: 14px; margin-bottom: 6px;">
                                    <input type="checkbox" class="wash-sync-pt-checkbox" value="<?php echo esc_attr($wash_pt->name); ?>"
                                        <?php checked(in_array($wash_pt->name, $wash_syncPostTypes, true)); ?> />
                                    <?php echo esc_html($wash_pt->label); ?>
                                    <code style="font-size: 11px; color: #666;">(<?php echo esc_html($wash_pt->name); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <input type="hidden" id="wash_post_types" name="wash_post_types"
                               value="<?php echo esc_attr(WashContext::option('wash_post_types', 'post,page')); ?>" />
                        <p class="description"><?php esc_html_e('Select which post types to synchronize to Shopify.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <div style="margin-top: 20px;">
                <a href="https://wash.tradik.com/docs/page-sync/" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                    <span class="dashicons dashicons-book" style="font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php esc_html_e('Documentation', 'wash-blog-manager'); ?>
                </a>
            </div>
        </div>

        <!-- Content Processing Tab -->
        <?php $wash_contentTabAttrs = wash_get_tab_content_class('content', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_contentTabAttrs['class']); ?>" id="tab-content" style="display: none;">
            <?php if (!empty($wash_contentTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Content Processing', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how content is processed during sync to Shopify.', 'wash-blog-manager'); ?></p>

            <?php $wash_allowedHtmlTags = WashContext::option('wash_allowed_html_tags', ''); ?>

            <h3 style="margin-bottom: 15px;">
                <span class="dashicons dashicons-editor-code" style="color: #2271b1;"></span>
                <?php esc_html_e('Custom HTML Tags Whitelist', 'wash-blog-manager'); ?>
            </h3>

            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('By default, WordPress strips unknown HTML tags for security. Add custom tag patterns to preserve third-party embed codes (Klaviyo forms, chat widgets, etc.).', 'wash-blog-manager'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Allowed HTML Tag Patterns', 'wash-blog-manager'); ?></th>
                    <td>
                        <input type="text" name="wash_allowed_html_tags" value="<?php echo esc_attr($wash_allowedHtmlTags); ?>"
                            placeholder="klaviyo-*,chat-*,mddb-*" class="regular-text" style="max-width: 400px; font-family: monospace;" />
                        <p class="description"><?php esc_html_e('Comma-separated patterns. Use * for wildcard (e.g., klaviyo-* matches klaviyo-form, klaviyo-embed).', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <div style="display: flex; align-items: flex-start; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; max-width: 600px; flex: 1;">
                    <strong><?php esc_html_e('Examples:', 'wash-blog-manager'); ?></strong><br />
                    <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">klaviyo-*</code> — <?php esc_html_e('Klaviyo forms and embeds', 'wash-blog-manager'); ?><br />
                    <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">chat-*</code> — <?php esc_html_e('Chat widgets (Tidio, Intercom, etc.)', 'wash-blog-manager'); ?><br />
                    <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">mddb-*</code> — <?php esc_html_e('MDDB custom elements', 'wash-blog-manager'); ?>
                </div>
                <a href="https://wash.tradik.com/docs/content-processing/" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                    <span class="dashicons dashicons-book" style="font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php esc_html_e('Documentation', 'wash-blog-manager'); ?>
                </a>
            </div>
        </div>

        <!-- Search & Replace Tab -->
        <?php $wash_searchReplaceTabAttrs = wash_get_tab_content_class('searchreplace', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_searchReplaceTabAttrs['class']); ?>" id="tab-searchreplace" style="display: none;" data-locked="<?php echo $wash_searchReplaceTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($wash_searchReplaceTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($wash_searchReplaceTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($wash_searchReplaceTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php elseif (!empty($wash_searchReplaceTabAttrs['isPlanLocked'])) : ?>
                <?php wash_render_locked_banner($wash_searchReplaceTabAttrs['requiredPlan'], $wash_searchReplaceTabAttrs['upgradeUrl']); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Search & Replace Rules', 'wash-blog-manager'); ?></h2>
            <p class="description">
                <?php esc_html_e('Define text replacements applied to post content before syncing to Shopify. Useful for converting shortcodes, rewriting links, or replacing any text patterns.', 'wash-blog-manager'); ?>
            </p>

            <div id="wash-search-replace-rules" style="margin-top: 20px;">
                <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span id="wash-sr-add-rule"></span>
                    <button type="button" id="wash-sr-save-rules" class="button button-primary">
                        <span class="dashicons dashicons-saved" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Save Rules', 'wash-blog-manager'); ?>
                    </button>
                    <button type="button" id="wash-sr-export-rules" class="button" title="<?php esc_attr_e('Export rules as JSON', 'wash-blog-manager'); ?>">
                        <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Export', 'wash-blog-manager'); ?>
                    </button>
                    <button type="button" id="wash-sr-import-rules" class="button" title="<?php esc_attr_e('Import rules from JSON', 'wash-blog-manager'); ?>">
                        <span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Import', 'wash-blog-manager'); ?>
                    </button>
                    <input type="file" id="wash-sr-import-file" accept=".json" style="display:none;" />
                    <span id="wash-sr-save-status" style="margin-left: 10px;"></span>
                </div>

                <table class="widefat wash-sr-table" id="wash-sr-table">
                    <thead>
                        <tr>
                            <th style="width: 3%;" title="<?php esc_attr_e('Drag to reorder', 'wash-blog-manager'); ?>">⇅</th>
                            <th style="width: 3%;">#</th>
                            <th style="width: 7%;"><?php esc_html_e('Type', 'wash-blog-manager'); ?></th>
                            <th style="width: 34%;"><?php esc_html_e('Search (find this)', 'wash-blog-manager'); ?></th>
                            <th style="width: 3%; text-align: center;">→</th>
                            <th style="width: 34%;"><?php esc_html_e('Replace (with this)', 'wash-blog-manager'); ?></th>
                            <th style="width: 7%; text-align: center;"><?php esc_html_e('Actions', 'wash-blog-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wash-sr-tbody">
                        <!-- Rules loaded via JavaScript -->
                        <tr id="wash-sr-loading">
                            <td colspan="7" style="text-align: center; padding: 20px; color: #666;">
                                <?php esc_html_e('Loading rules...', 'wash-blog-manager'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; align-items: flex-start; gap: 20px; margin-top: 30px; flex-wrap: wrap;">
                <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; max-width: 600px; flex: 1;">
                    <strong><?php esc_html_e('Rule Types:', 'wash-blog-manager'); ?></strong>
                    <div style="margin-top: 8px;">
                        <span style="background:#6b7280;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">Aa Text</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Exact string replacement', 'wash-blog-manager'); ?></span>
                    </div>
                    <div style="margin-top: 6px;">
                        <span style="background:#2563eb;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">🔗 URL</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Replace in href/src attributes only', 'wash-blog-manager'); ?></span>
                    </div>
                    <div style="margin-top: 6px;">
                        <span style="background:#7c3aed;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">[ ] Shortcode</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Match [tag] or [tag]...[/tag], auto-registers unregistered shortcodes', 'wash-blog-manager'); ?></span>
                    </div>
                    <div style="margin-top: 6px;">
                        <span style="background:#ea580c;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">▦ Block</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Match Gutenberg blocks (wp:blockname)', 'wash-blog-manager'); ?></span>
                    </div>
                    <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #c5d9ed;">
                        <strong><?php esc_html_e('Variable:', 'wash-blog-manager'); ?></strong>
                        <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">%content%</code>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Captures inner content (HTML stripped) from shortcodes/blocks', 'wash-blog-manager'); ?></span>
                    </div>
                </div>
                <div style="background: #fff8e6; border: 1px solid #ffc96b; border-radius: 4px; padding: 15px; max-width: 400px; flex: 1;">
                    <strong style="color: #856404;">
                        <span class="dashicons dashicons-warning" style="font-size: 16px; vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Important:', 'wash-blog-manager'); ?>
                    </strong>
                    <ul style="margin: 8px 0 0; padding-left: 18px; color: #856404; font-size: 13px;">
                        <li><?php esc_html_e('Rules are applied in order, top to bottom.', 'wash-blog-manager'); ?></li>
                        <li><?php esc_html_e('Search is case-sensitive and uses exact text matching.', 'wash-blog-manager'); ?></li>
                        <li><?php esc_html_e('Replacements are applied to the final HTML content before sending to Shopify.', 'wash-blog-manager'); ?></li>
                        <li><?php esc_html_e('Test your rules carefully — incorrect replacements can break content.', 'wash-blog-manager'); ?></li>
                    </ul>
                </div>
            </div>

            <!-- Reference lists: scanned WP content + Shopify links -->
            <div style="display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <details id="wash-sr-wp-refs" style="border: 1px solid #e2e8f0; border-radius: 4px; padding: 12px; background: #fafafa;">
                        <summary style="cursor: pointer; font-weight: 600; font-size: 13px;">
                            <?php esc_html_e('WordPress — detected content', 'wash-blog-manager'); ?>
                            <span id="wash-sr-wp-count" style="color: #999; font-weight: normal;"></span>
                        </summary>
                        <div id="wash-sr-wp-list" style="margin-top: 10px; max-height: 300px; overflow-y: auto; font-size: 12px;">
                            <span style="color: #999;"><?php esc_html_e('Loading...', 'wash-blog-manager'); ?></span>
                        </div>
                    </details>
                </div>
                <div style="flex: 1; min-width: 300px;">
                    <details id="wash-sr-shopify-refs" style="border: 1px solid #e2e8f0; border-radius: 4px; padding: 12px; background: #fafafa;">
                        <summary style="cursor: pointer; font-weight: 600; font-size: 13px;">
                            <?php esc_html_e('Shopify — available links', 'wash-blog-manager'); ?>
                            <span id="wash-sr-shopify-count" style="color: #999; font-weight: normal;"></span>
                        </summary>
                        <div id="wash-sr-shopify-list" style="margin-top: 10px; max-height: 300px; overflow-y: auto; font-size: 12px;">
                            <span style="color: #999;"><?php esc_html_e('Loading...', 'wash-blog-manager'); ?></span>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <!-- Advanced Tab -->
        <?php $wash_advancedTabAttrs = wash_get_tab_content_class('advanced', $wash_features, $wash_tabFeatures, $wash_featurePlans, $wash_upgradeUrl, $wash_permissions, $wash_tabPermissions); ?>
        <div class="<?php echo esc_attr($wash_advancedTabAttrs['class']); ?>" id="tab-advanced" style="display: none;">
            <?php if (!empty($wash_advancedTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Advanced Settings', 'wash-blog-manager'); ?></h2>
            <p class="description"><?php esc_html_e('Additional configuration options.', 'wash-blog-manager'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Block Search Engines', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_block_robots" value="0" />
                            <input type="checkbox" name="wash_block_robots" value="1"
                                <?php checked(WashContext::option('wash_block_robots', '1'), '1'); ?> />
                            <?php esc_html_e('Discourage search engines from indexing this site (robots.txt)', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Recommended for demo/staging sites. Outputs "Disallow: /" in robots.txt.', 'wash-blog-manager'); ?></p>
                        <p class="description" style="margin-top: 8px;">
                            <span class="dashicons dashicons-info-outline" style="color: #2271b1; font-size: 14px; vertical-align: text-bottom;"></span>
                            <?php esc_html_e('For full protection:', 'wash-blog-manager'); ?>
                            <a href="<?php echo esc_url(admin_url('options-reading.php')); ?>"><?php esc_html_e('Settings → Reading', 'wash-blog-manager'); ?></a>
                            <?php esc_html_e('→ "Discourage search engines" + password protect the site.', 'wash-blog-manager'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php
            $wash_removeData = WashContext::option('wash_uninstall_remove_data', '0');
            // Options come from data so the markup is written once (no repeated
            // <option> lines for Sonar to flag as duplicated).
            $wash_uninstallChoices = [
                '0' => __('Keep all my data (default)', 'wash-blog-manager'),
                '1' => __('Delete all Wash settings and per-post Shopify mappings', 'wash-blog-manager'),
            ];
            ?>
            <div style="margin-top: 16px; padding: 12px 16px; border: 1px solid #f0c5c5; border-radius: 4px; background: #fcf4f4; max-width: 760px;">
                <p style="margin: 0 0 8px;">
                    <span class="dashicons dashicons-warning" style="color: #b32d2e; font-size: 14px; vertical-align: text-bottom;"></span>
                    <label for="wash_uninstall_remove_data"><strong><?php esc_html_e('When the plugin is uninstalled', 'wash-blog-manager'); ?></strong></label>
                </p>
                <select name="wash_uninstall_remove_data" id="wash_uninstall_remove_data">
                    <?php foreach ($wash_uninstallChoices as $wash_choiceVal => $wash_choiceLabel) : ?>
                        <option value="<?php echo esc_attr($wash_choiceVal); ?>" <?php selected($wash_removeData, $wash_choiceVal); ?>><?php echo esc_html($wash_choiceLabel); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Kept by default so a reinstall (or accidental delete) does not lose every post\'s Shopify article ID. "Delete" purges all wash_* options and _wash_* meta and cannot be undone.', 'wash-blog-manager'); ?></p>
            </div>

            <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 24px 0;" />

            <h3><?php esc_html_e('Gutenberg Blocks', 'wash-blog-manager'); ?></h3>
            <p class="description"><?php esc_html_e('Configure how Gutenberg blocks are processed and synced to Shopify.', 'wash-blog-manager'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Custom Blocks', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_blocks_enabled" value="0" />
                            <input type="checkbox" name="wash_blocks_enabled" value="1"
                                <?php checked(WashContext::option('wash_blocks_enabled', '1'), '1'); ?> />
                            <?php esc_html_e('Enable Wash custom blocks in Gutenberg editor', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Adds custom Wash blocks (Shopify Product, Shopify Collection, Sync Status) to the Gutenberg editor.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Include Block CSS', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_blocks_css" value="0" />
                            <input type="checkbox" name="wash_sync_blocks_css" value="1"
                                <?php checked(WashContext::option('wash_sync_blocks_css', '0'), '1'); ?> />
                            <?php esc_html_e('Include WordPress block styles in synced content', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Includes WordPress block CSS styles in synced content for consistent visual appearance on Shopify.', 'wash-blog-manager'); ?>
                            <a href="https://wash.tradik.com/docs/wordpress-blocks/#shopify-theme-css" target="_blank" rel="noopener"><?php esc_html_e('Learn how to configure your Shopify theme', 'wash-blog-manager'); ?> &rarr;</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Block Structure', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_blocks_meta" value="0" />
                            <input type="checkbox" name="wash_sync_blocks_meta" value="1"
                                <?php checked(WashContext::option('wash_sync_blocks_meta', '1'), '1'); ?> />
                            <?php esc_html_e('Save block structure as Shopify metafields', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Saves Gutenberg block structure as JSON metafield, allowing Shopify themes to render blocks dynamically.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('RankMath FAQ Blocks', 'wash-blog-manager'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_transform_rankmath_faq" value="0" />
                            <input type="checkbox" name="wash_transform_rankmath_faq" value="1"
                                <?php checked(WashContext::option('wash_transform_rankmath_faq', '1'), '1'); ?> />
                            <?php esc_html_e('Convert RankMath FAQ blocks to a Shopify-safe accordion', 'wash-blog-manager'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Rebuilds RankMath FAQ blocks as styled <details> accordions and adds FAQPage schema, so they survive Shopify\'s HTML sanitizer instead of becoming unstyled headings.', 'wash-blog-manager'); ?></p>
                    </td>
                </tr>
            </table>

            <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 24px 0;" />

            <h3><?php esc_html_e('Sync Settings', 'wash-blog-manager'); ?></h3>
            <p class="description"><?php esc_html_e('Synchronize settings between WordPress and Shopify App.', 'wash-blog-manager'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Sync from Shopify', 'wash-blog-manager'); ?></th>
                    <td>
                        <button type="button" id="wash-sync-from-shopify" class="button button-secondary">
                            <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Sync from Shopify', 'wash-blog-manager'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Pull settings from Shopify App and apply them here. Use this if you changed settings in Shopify Admin.', 'wash-blog-manager'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Refresh sync statuses', 'wash-blog-manager'); ?></th>
                    <td>
                        <button type="button" id="wash-refresh-statuses" class="button button-secondary">
                            <span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Refresh all sync statuses', 'wash-blog-manager'); ?>
                        </button>
                        <span id="wash-refresh-statuses-progress" style="margin-left: 10px;"></span>
                        <p class="description">
                            <?php esc_html_e('Rebuild the synced / not-synced indicators for every post from Shopify, without re-syncing content. Use this if the status dots disappeared (e.g. after re-installing the plugin).', 'wash-blog-manager'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 24px 0;" />

            <h3><?php esc_html_e('Reset Settings', 'wash-blog-manager'); ?></h3>
            <p class="description"><?php esc_html_e('Reset all Wash settings to their default values. This will not affect your Shopify connection or synced content.', 'wash-blog-manager'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Reset to Defaults', 'wash-blog-manager'); ?></th>
                    <td>
                        <button type="button" id="wash-reset-to-defaults" class="button button-secondary">
                            <span class="dashicons dashicons-image-rotate" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Reset All Settings', 'wash-blog-manager'); ?>
                        </button>
                        <p class="description" style="color: #d63638;">
                            <?php esc_html_e('Warning: This will reset all sync settings, metafield options, file settings, and advanced options to their default values.', 'wash-blog-manager'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div id="wash-submit-wrapper">
            <?php submit_button(); ?>
        </div>
    </form>

    <?php
    // Multi-store hub (#216, #252): the single place to add/manage extra Shopify
    // stores, folded into the Connection tab. Rendered OUTSIDE the settings <form>
    // because each row's actions are their own admin-post forms (no nested forms).
    // Shown with a qualifying plan, or when extra stores already exist (so a
    // downgraded merchant can still reach/disable them); a free single-store
    // install sees nothing new. JS ties its visibility to the Connection tab.
    if ($wash_isConnected && (WashConnectionsAdmin::planAllowsMultiStore() || WashConnection::count() > 1)) :
        WashConnectionsAdmin::enforcePlanDowngrade(); // reflect a lapsed plan on open
        ?>
        <div id="wash-connection-extra" style="display:none;">
            <hr style="border:none;border-top:1px solid #ccd0d4;margin:24px 0;max-width:900px;" />
            <?php include_once WASH_PLUGIN_DIR . 'admin/views/connections-page.php'; ?>
        </div>
    <?php endif; ?>
</div>

<?php
// wp.org compliance: deliver this admin JS via wp_add_inline_script() instead of
// an inline script element. Buffered so PHP interpolations resolve to pure JS,
// then attached after the already-enqueued `wash-admin` handle.
ob_start();
?>
(function() {
    // Multi-store (#252): tag each admin-ajax POST body with the active connection id
    // so blog mappings / meta saves target the store whose page this is. The GET tab
    // calls already carry the id baked into their URLs. The id is rendered SERVER-SIDE
    // from the active WashContext (this page is pinned to its connection), so it never
    // depends on a JS localize that could resolve to primary. A plain helper is used
    // at each POST site rather than overriding the built-in window.fetch.
    var washConnId = '<?php echo esc_js(WashContext::activeId()); ?>';
    function washWithConn(fd) {
        if (fd instanceof FormData && !fd.has('washConnectionId')) {
            fd.append('washConnectionId', washConnId);
        }
        return fd;
    }

    // Sync Post Types checkboxes → hidden input
    var syncPtCheckboxes = document.querySelectorAll('.wash-sync-pt-checkbox');
    var syncPtHidden = document.getElementById('wash_post_types');
    if (syncPtCheckboxes.length && syncPtHidden) {
        syncPtCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var checked = [];
                syncPtCheckboxes.forEach(function(c) { if (c.checked) checked.push(c.value); });
                syncPtHidden.value = checked.join(',');
            });
        });
    }

    var currentTabInput = document.getElementById('wash-current-tab');

    // Initialize locked tab content panels
    document.querySelectorAll('.wash-tab-content--locked').forEach(function(panel) {
        var requiredPlan = panel.getAttribute('data-required-plan') || 'PRO';
        var upgradeUrl = panel.getAttribute('data-upgrade-url') || '';

        // Disable all form elements inside locked panel
        panel.querySelectorAll('input, select, textarea, button').forEach(function(el) {
            el.disabled = true;
            el.setAttribute('tabindex', '-1');
        });

        // Add upgrade banner at the top of the panel (only if not already present from PHP)
        if (!panel.querySelector('.wash-locked-banner') && !panel.querySelector('.wash-permission-locked-banner')) {
            var banner = document.createElement('div');
            banner.className = 'wash-locked-banner';
            banner.innerHTML = '<div class="wash-locked-banner__text">' +
                '<span class="dashicons dashicons-lock"></span>' +
                '<span><?php echo esc_js(__('This feature requires', 'wash-blog-manager')); ?> <strong>' + requiredPlan + '</strong> <?php echo esc_js(__('plan', 'wash-blog-manager')); ?></span>' +
                '</div>' +
                '<div class="wash-locked-banner__actions">' +
                (upgradeUrl ? '<a href="' + upgradeUrl + '" target="_blank" class="button button-primary"><?php echo esc_js(__('Upgrade Now', 'wash-blog-manager')); ?></a>' : '') +
                '</div>';

            // Insert banner as first child
            panel.insertBefore(banner, panel.firstChild);
        }
    });

    // Submit button visibility based on tab and connection state
    var submitWrapper = document.getElementById('wash-submit-wrapper');
    var isConnected = <?php echo $wash_isConnected ? 'true' : 'false'; ?>;

    function updateSubmitVisibility(tabId) {
        if (!submitWrapper) return;
        // Hide submit on tabs that use AJAX (Connection, Blogs, Redirections)
        var ajaxTabs = ['connection', 'blogs', 'redirections', 'searchreplace'];
        if (ajaxTabs.indexOf(tabId) !== -1) {
            submitWrapper.style.display = 'none';
        } else {
            submitWrapper.style.display = 'block';
        }
    }

    // The multi-store panel sits outside the settings <form> (its admin-post forms
    // must not nest), so the tab loop above can't toggle it. Show it only while the
    // Connection tab is active.
    var connectionExtra = document.getElementById('wash-connection-extra');
    function syncConnectionExtra(tabId) {
        if (connectionExtra) {
            connectionExtra.style.display = (tabId === 'connection') ? 'block' : 'none';
        }
    }

    // Tab switching
    document.querySelectorAll('.wash-tabs .nav-tab').forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var tabId = this.getAttribute('data-tab');

            document.querySelectorAll('.wash-tabs .nav-tab').forEach(function(t) {
                t.classList.remove('nav-tab-active');
            });
            this.classList.add('nav-tab-active');

            document.querySelectorAll('.wash-tab-content').forEach(function(content) {
                content.style.display = 'none';
            });
            document.getElementById('tab-' + tabId).style.display = 'block';

            // Update hidden field and hash for persistence
            if (currentTabInput) currentTabInput.value = tabId;
            window.location.hash = tabId;

            // Update submit button visibility
            updateSubmitVisibility(tabId);
            syncConnectionExtra(tabId);
        });
    });

    // Restore from hash or URL parameter
    var hash = window.location.hash.replace('#', '');
    var urlParams = new URLSearchParams(window.location.search);
    var tabParam = urlParams.get('tab') || hash;
    var activeTab = 'connection'; // default tab

    if (tabParam && document.getElementById('tab-' + tabParam)) {
        document.querySelectorAll('.wash-tabs .nav-tab').forEach(function(t) {
            t.classList.remove('nav-tab-active');
        });
        document.querySelector('.wash-tabs .nav-tab[data-tab="' + tabParam + '"]').classList.add('nav-tab-active');
        document.querySelectorAll('.wash-tab-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.getElementById('tab-' + tabParam).style.display = 'block';
        if (currentTabInput) currentTabInput.value = tabParam;
        activeTab = tabParam;
    } else if (hash && document.getElementById('tab-' + hash)) {
        document.querySelectorAll('.wash-tabs .nav-tab').forEach(function(t) {
            t.classList.remove('nav-tab-active');
        });
        document.querySelector('.wash-tabs .nav-tab[data-tab="' + hash + '"]').classList.add('nav-tab-active');
        document.querySelectorAll('.wash-tab-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.getElementById('tab-' + hash).style.display = 'block';
        activeTab = hash;
    }

    // Initial submit visibility based on active tab
    updateSubmitVisibility(activeTab);
    syncConnectionExtra(activeTab);

    // Show/hide manual config
    // Connect button visibility
    var storeInput = document.getElementById('wash_shopify_store');
    var connectBtn = document.getElementById('wash-connect-btn');
    var consentCheckbox = document.getElementById('wash-consent-checkbox');
    var consentAccepted = <?php echo $wash_consentAccepted ? 'true' : 'false'; ?>;

    function updateConnectButton() {
        if (!connectBtn) return;
        var store = storeInput ? storeInput.value.trim() : '';
        if (store) {
            connectBtn.style.display = 'inline-flex';
            // Even when visible, keep the button disabled until consent is recorded.
            connectBtn.disabled = !consentAccepted;
        } else {
            connectBtn.style.display = 'none';
        }
    }

    if (storeInput) {
        storeInput.addEventListener('input', updateConnectButton);
        updateConnectButton();
    }

    // Persist explicit consent to the Tradik Wash data-processing terms
    // (wp.org Plugin Directory guideline #10). Once accepted, the option is
    // sticky — re-checking the box again is a no-op; un-checking it is not
    // allowed via the UI (would only matter after a Disconnect).
    if (consentCheckbox) {
        consentCheckbox.addEventListener('change', function() {
            if (!consentCheckbox.checked || consentAccepted) {
                return;
            }
            var fd = new FormData();
            fd.append('action', 'wash_save_consent');
            fd.append('nonce', '<?php echo esc_js(wp_create_nonce('wash_consent')); ?>');
            consentCheckbox.disabled = true;
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: washWithConn(fd) })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (json && json.success) {
                        consentAccepted = true;
                        updateConnectButton();
                    } else {
                        // Revert UI state if the server rejected the request.
                        consentCheckbox.checked = false;
                        consentCheckbox.disabled = false;
                        alert('<?php echo esc_js(__('Failed to record consent. Please refresh the page and try again.', 'wash-blog-manager')); ?>');
                    }
                })
                .catch(function() {
                    consentCheckbox.checked = false;
                    consentCheckbox.disabled = false;
                });
        });
    }

    // Connect button click
    if (connectBtn) {
        connectBtn.addEventListener('click', function() {
            var store = storeInput.value.trim().replace('.myshopify.com', '').toLowerCase();

            // Validate store handle format
            if (!/^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(store) && !/^[a-z0-9]$/.test(store)) {
                alert('<?php echo esc_js(__('Invalid store name. Use only lowercase letters, numbers, and hyphens.', 'wash-blog-manager')); ?>');
                return;
            }

            // Show loading state
            connectBtn.disabled = true;
            connectBtn.innerHTML = '<span class="dashicons dashicons-update" style="animation: rotation 1s infinite linear; vertical-align: text-bottom;"></span> <?php echo esc_js(__('Checking...', 'wash-blog-manager')); ?>';

            // Verify store exists by checking if it responds
            fetch('https://' + store + '.myshopify.com/admin', { mode: 'no-cors', cache: 'no-store' })
                .finally(function() {
                    // Redirect regardless - Shopify will show proper error if store doesn't exist.
                    // CSRF: embed a one-time nonce in the return_url. The Shopify app
                    // redirects this URL back verbatim (only appending api_key/store), so
                    // handleCallback() can verify the request actually originated here.
                    var returnUrl = encodeURIComponent(window.location.href.split('?')[0] + '?page=wash&wash_callback=1&wash_state=<?php echo esc_js(wp_create_nonce('wash_connect')); ?>');
                    var siteUrl = encodeURIComponent(window.location.protocol + '//' + window.location.host);
                    var siteName = encodeURIComponent('<?php echo esc_js(get_bloginfo('name')); ?>');

                    var appSlug = '<?php echo esc_js($wash_shopifyAppSlug); ?>';
                    var connectUrl = 'https://admin.shopify.com/store/' + store + '/apps/' + appSlug + '/app/connections' +
                        '?action=connect' +
                        '&return_url=' + returnUrl +
                        '&site_url=' + siteUrl +
                        '&site_name=' + siteName;

                    window.location.href = connectUrl;
                });
        });
    }

    // Blog Mappings Management
    var mappingsList = document.getElementById('wash-mappings-list');
    var mappingForm = document.getElementById('wash-mapping-form');
    var addMappingBtn = document.getElementById('wash-add-mapping');
    var saveMappingBtn = document.getElementById('wash-save-mapping');
    var cancelMappingBtn = document.getElementById('wash-cancel-mapping');
    var ruleTypeSelect = document.getElementById('wash-rule-type');
    var ruleValueRow = document.getElementById('wash-rule-value-row');
    var shopifyBlogSelect = document.getElementById('wash-shopify-blog');
    var newBlogRow = document.getElementById('wash-new-blog-row');
    var marketLocaleSelect = document.getElementById('wash-market-locale');

    var mappingsData = [];
    var shopifyBlogs = [];
    var shopifyMarkets = [];
    var shopLocales = [];

    /** Show a brief toast notification near the mappings list */
    function washMappingsToast(message, type) {
        type = type || 'success';
        var el = document.createElement('div');
        el.textContent = message;
        el.style.cssText = 'position:fixed;top:40px;right:20px;z-index:99999;padding:10px 18px;border-radius:4px;font-size:13px;font-weight:500;box-shadow:0 2px 8px rgba(0,0,0,.15);transition:opacity .3s;'
            + (type === 'success' ? 'background:#00a32a;color:#fff;' : 'background:#d63638;color:#fff;');
        document.body.appendChild(el);
        setTimeout(function() { el.style.opacity = '0'; }, 2000);
        setTimeout(function() { el.remove(); }, 2400);
    }
    var destinationSelect = document.getElementById('wash-destination');
    var wpPostTypesInput = document.getElementById('wash-wp-post-types');
    var ptCheckboxes = document.querySelectorAll('.wash-pt-checkbox');
    var categoryRow = document.getElementById('wash-category-row');
    var blogRow = document.getElementById('wash-blog-row');
    var marketLocaleRow = document.getElementById('wash-market-locale-row');

    // Sync checkboxes → hidden input
    function syncPostTypeCheckboxes() {
        var checked = [];
        ptCheckboxes.forEach(function(cb) {
            if (cb.checked) checked.push(cb.value);
        });
        if (wpPostTypesInput) wpPostTypesInput.value = checked.join(',');
    }

    // Set checkboxes from comma-separated string
    function setPostTypeCheckboxes(value) {
        var types = (value || 'post').split(',').map(function(t) { return t.trim().toLowerCase(); });
        ptCheckboxes.forEach(function(cb) {
            cb.checked = types.indexOf(cb.value.toLowerCase()) > -1;
        });
        syncPostTypeCheckboxes();
    }

    ptCheckboxes.forEach(function(cb) {
        cb.addEventListener('change', syncPostTypeCheckboxes);
    });

    // Show/hide fields based on destination
    function updateDestinationFields() {
        var dest = destinationSelect ? destinationSelect.value : 'article';
        var isPage = dest === 'page';
        if (categoryRow) categoryRow.style.display = isPage ? 'none' : '';
        if (blogRow) blogRow.style.display = isPage ? 'none' : '';
        // Market/locale always visible (pages need it for translations too)
        if (isPage && ruleValueCat) ruleValueCat.value = '';
    }
    if (destinationSelect) {
        destinationSelect.addEventListener('change', function() {
            var isPage = this.value === 'page';
            setPostTypeCheckboxes(isPage ? 'page' : 'post');
            updateDestinationFields();
        });
    }

    // Load mappings on page load (only if connected)
    if (mappingsList && <?php echo $wash_isConnected ? 'true' : 'false'; ?>) {
        loadMappings();
        loadShopifyBlogs();
        loadShopifyMarkets();
        loadShopLocales();
        loadShopDomains();
    }

    function loadMappings() {
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=wash_get_mappings&nonce=<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>&washConnectionId=<?php echo esc_attr(WashContext::activeId()); ?>')
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    mappingsData = response.data.mappings || [];
                    renderMappings();
                } else {
                    mappingsList.innerHTML = '<p class="notice notice-error">' + (response.data || 'Failed to load mappings') + '</p>';
                }
            })
            .catch(function() {
                mappingsList.innerHTML = '<p class="notice notice-error"><?php echo esc_js(__('Failed to load mappings', 'wash-blog-manager')); ?></p>';
            });
    }

    function loadShopifyBlogs() {
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=wash_get_shopify_blogs&nonce=<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>&washConnectionId=<?php echo esc_attr(WashContext::activeId()); ?>')
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    shopifyBlogs = response.data.blogs || [];
                    updateBlogSelect();
                    renderMappings();
                }
            });
    }

    function updateBlogSelect() {
        if (!shopifyBlogSelect) return;
        var currentVal = shopifyBlogSelect.value;
        shopifyBlogSelect.innerHTML = '<option value=""><?php echo esc_js(__('Select a blog...', 'wash-blog-manager')); ?></option>';
        shopifyBlogs.forEach(function(blog) {
            var opt = document.createElement('option');
            opt.value = blog.id;
            opt.textContent = blog.title + ' (' + blog.handle + ')';
            shopifyBlogSelect.appendChild(opt);
        });
        var newOpt = document.createElement('option');
        newOpt.value = '_new';
        newOpt.textContent = '➕ <?php echo esc_js(__('Create new blog', 'wash-blog-manager')); ?>';
        shopifyBlogSelect.appendChild(newOpt);
        shopifyBlogSelect.value = currentVal;
    }

    function loadShopifyMarkets() {
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=wash_get_shopify_markets&nonce=<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>&washConnectionId=<?php echo esc_attr(WashContext::activeId()); ?>')
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    shopifyMarkets = response.data.markets || [];
                    updateMarketLocaleSelect();
                    renderMappings();
                }
            });
    }

    function loadShopLocales() {
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=wash_get_shop_locales&nonce=<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>&washConnectionId=<?php echo esc_attr(WashContext::activeId()); ?>')
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    shopLocales = response.data.locales || [];
                    updateMarketLocaleSelect();
                }
            });
    }

    var shopDomains = [];
    var domainSelect = document.getElementById('wash-destination-domain');

    function loadShopDomains() {
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=wash_get_shop_domains&nonce=<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>&washConnectionId=<?php echo esc_attr(WashContext::activeId()); ?>')
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    shopDomains = response.data.domains || [];
                    updateDomainSelect();
                }
            });
    }

    function updateDomainSelect() {
        if (!domainSelect) return;
        var currentVal = domainSelect.value;
        domainSelect.innerHTML = '<option value=""><?php echo esc_js(__('Default (primary domain)', 'wash-blog-manager')); ?></option>';
        shopDomains.forEach(function(domain) {
            var opt = document.createElement('option');
            opt.value = domain.host;
            opt.textContent = domain.host + (domain.primary ? ' (primary)' : '') + (domain.marketName ? ' — ' + domain.marketName : '');
            domainSelect.appendChild(opt);
        });
        domainSelect.value = currentVal;
    }

    /** Primary published shop locale (e.g. "en"), or first available, or '' if unknown. */
    function washPrimaryShopLocale() {
        if (!shopLocales || !shopLocales.length) return '';
        var primary = shopLocales.find(function (l) { return l.primary; });
        return (primary || shopLocales[0]).locale || '';
    }

    /** True if a locale is a published shop locale (case-insensitive). */
    function washIsShopLocale(locale) {
        if (!locale) return false;
        var needle = String(locale).toLowerCase();
        return (shopLocales || []).some(function (l) {
            return String(l.locale || '').toLowerCase() === needle;
        });
    }

    function updateMarketLocaleSelect() {
        if (!marketLocaleSelect) return;
        var currentVal = marketLocaleSelect.value;
        marketLocaleSelect.innerHTML = '<option value=""><?php echo esc_js(__('Default (primary market)', 'wash-blog-manager')); ?></option>';

        // 🌍 Global (no market) — shop-wide Translate & Adapt translations
        if (shopLocales && shopLocales.length > 0) {
            var globalGroup = document.createElement('optgroup');
            globalGroup.label = '<?php echo esc_js(__('🌍 Global (no market)', 'wash-blog-manager')); ?>';
            shopLocales.forEach(function(loc) {
                var opt = document.createElement('option');
                opt.value = '|' + loc.locale;
                opt.textContent = (loc.name || loc.locale) + ' (' + loc.locale + ')' + (loc.primary ? ' — primary' : '');
                globalGroup.appendChild(opt);
            });
            marketLocaleSelect.appendChild(globalGroup);
        }

        shopifyMarkets.forEach(function(market) {
            var optgroup = document.createElement('optgroup');
            optgroup.label = '📍 ' + market.name + (market.primary ? ' (primary)' : '');
            var hasOptions = false;

            if (market.webPresence && market.webPresence.defaultLocale) {
                // Market with web presence — show all configured locales
                var defaultLocale = market.webPresence.defaultLocale;
                var opt = document.createElement('option');
                opt.value = market.id + '|' + defaultLocale;
                opt.textContent = market.name + ' / ' + defaultLocale + ' (market default)';
                optgroup.appendChild(opt);
                hasOptions = true;

                if (market.webPresence.alternateLocales) {
                    market.webPresence.alternateLocales.forEach(function(locale) {
                        var altOpt = document.createElement('option');
                        altOpt.value = market.id + '|' + locale;
                        altOpt.textContent = market.name + ' / ' + locale;
                        optgroup.appendChild(altOpt);
                    });
                }
            } else {
                // Market without web presence — locale derived from first region code.
                // A country code is a valid locale only by coincidence (e.g. "fr"),
                // while "us"/"gb" are rejected by Shopify ("Locale is invalid").
                // To never disturb a working setup, only override when we can PROVE the
                // code is invalid — i.e. the shop-locale list is loaded and excludes it.
                // Without that proof (locales not yet/never loaded) keep legacy behavior.
                var regionCode = (market.regions && market.regions[0] && market.regions[0].code)
                    ? String(market.regions[0].code).toLowerCase() : null;
                var locale, isFallback = false;
                if (shopLocales && shopLocales.length && !washIsShopLocale(regionCode)) {
                    locale = washPrimaryShopLocale(); // proven-invalid code → shop default
                    isFallback = true;
                } else {
                    locale = regionCode || 'en'; // valid code, or unverifiable → as before
                }
                if (locale) {
                    var opt = document.createElement('option');
                    opt.value = market.id + '|' + locale;
                    opt.textContent = market.name + ' / ' + locale + (isFallback ? ' (shop default)' : '');
                    optgroup.appendChild(opt);
                    hasOptions = true;
                }
            }

            if (hasOptions) {
                marketLocaleSelect.appendChild(optgroup);
            }
        });

        marketLocaleSelect.value = currentVal;
    }

    var wpPermalink = '<?php echo esc_js($wash_permalinkExample); ?>';
    var wpBase = '<?php echo esc_js(home_url('/')); ?>';
    var wpCategoryBase = '<?php echo esc_js($wash_categoryBase); ?>';
    var shopifyDomain = 'https://<?php echo esc_js($wash_shopifyStore ?: '{store}'); ?>.myshopify.com';

    function getBlogHandle(blogId) {
        for (var i = 0; i < shopifyBlogs.length; i++) {
            if (String(shopifyBlogs[i].id) === String(blogId)) {
                return shopifyBlogs[i].handle;
            }
        }
        return null;
    }

    /**
     * Find market data by ID from loaded shopifyMarkets.
     */
    function getMarketById(marketId) {
        if (!marketId) return null;
        for (var i = 0; i < shopifyMarkets.length; i++) {
            if (String(shopifyMarkets[i].id) === String(marketId)) {
                return shopifyMarkets[i];
            }
        }
        return null;
    }

    /**
     * Build the real Shopify destination URL from mapping data + markets config.
     */
    function getUrlExample(m) {
        var wpUrl;
        var isPage = m.destination === 'page';
        var handle = getBlogHandle(m.shopifyBlogId) || m.shopifyBlogHandle || 'news';
        var market = getMarketById(m.shopifyMarketId);

        // Warn when a language-oriented rule has NO Shopify market assigned: the
        // storefront subfolder (/de-ch/, /en-ca/ …) lives in Shopify Markets, so
        // without a market this rule's content has no localized URL and "View on
        // Shopify" 404s. ruleValue is only a matching key, not a subfolder (#255).
        var localeOriented = !!m.shopifyLocale || m.ruleType === 'language' || m.ruleType === 'language_category';
        var noMarketWarn = (localeOriented && !market)
            ? ' <span style="display:inline-block;color:#b32d2e;font-weight:600;font-size:11px;" title="'
                + 'This rule targets a language but has no Shopify market assigned. The storefront subfolder comes from Shopify Markets, so without a market this content has no localized URL and “View on Shopify” will 404. Assign a market to this rule.">'
                + '⚠ ' + <?php echo wp_json_encode(__('no market — no localized URL', 'wash-blog-manager')); ?> + '</span>'
            : '';

        // WP source URL
        if (isPage) {
            // Pages use flat URL: /page-slug/
            wpUrl = m.ruleType === 'language' && m.ruleValue
                ? wpBase + m.ruleValue + '/{page-slug}/'
                : wpBase + '{page-slug}/';
        } else if (m.ruleType === 'default') {
            wpUrl = wpPermalink;
        } else if (m.ruleType === 'language') {
            wpUrl = wpBase + m.ruleValue + '/' + wpPermalink.replace(wpBase, '');
        } else if (m.ruleType === 'category') {
            wpUrl = wpBase + wpCategoryBase + '/' + m.ruleValue + '/{slug}/';
        } else if (m.ruleType === 'language_category') {
            var parts = (m.ruleValue || '').split(':');
            wpUrl = wpBase + (parts[0] || '') + '/' + wpCategoryBase + '/' + (parts[1] || '') + '/{slug}/';
        }

        // Shopify destination URL — use real market config if available
        var shopifyBase = shopifyDomain;
        var pathPrefix = '';

        if (market && market.webPresence) {
            var wp = market.webPresence;
            // Use rootUrl for this locale if available
            if (wp.rootUrls && m.shopifyLocale) {
                for (var i = 0; i < wp.rootUrls.length; i++) {
                    if (wp.rootUrls[i].locale === m.shopifyLocale) {
                        var rootUrl = wp.rootUrls[i].url.replace(/\/$/, '');
                        var destPath = m.destination === 'page' ? '/pages/{slug}' : '/blogs/' + handle + '/{slug}';
                        return '<code style="color:#8b5a00;font-size:11px;">' + (wpUrl || wpPermalink) + '</code>' +
                            ' <span style="color:#2271b1;">→</span> ' +
                            '<code style="color:#155724;font-size:11px;">' + rootUrl + destPath + '</code>';
                    }
                }
            }
            // Fallback: use subfolderSuffix
            if (wp.subfolderSuffix) {
                pathPrefix = '/' + wp.subfolderSuffix;
            }
            // Use market domain if different from default
            if (wp.domainUrl) {
                shopifyBase = wp.domainUrl.replace(/\/$/, '');
            }
        } else if (m.shopifyLocale) {
            // No market data loaded yet — show locale as path prefix
            pathPrefix = '/' + m.shopifyLocale;
        }

        var shopifyPath = m.destination === 'page' ? '/pages/{slug}' : '/blogs/' + handle + '/{slug}';
        var shopifyUrl = shopifyBase + pathPrefix + shopifyPath;

        return '<code style="color:#8b5a00;font-size:11px;">' + (wpUrl || wpPermalink) + '</code>' +
            ' <span style="color:#2271b1;">→</span> ' +
            '<code style="color:#155724;font-size:11px;">' + shopifyUrl + '</code>' + noMarketWarn;
    }

    /**
     * Detect overlapping rules and group them by color.
     * Returns map: mappingId -> groupIndex (for coloring).
     */
    var OVERLAP_COLORS = ['#fef9e7', '#eaf4fc', '#edf7ed', '#f5f0fa', '#e8f6f3', '#f4f6f0', '#f0f4fa', '#f6f5ee'];
    function detectOverlapGroups(mappings) {
        // Build adjacency: which IDs overlap with which
        var edges = [];
        for (var i = 0; i < mappings.length; i++) {
            var a = mappings[i];
            if (a.ruleType === 'default') continue;
            var aTypes = (a.wpPostTypes || 'post').split(',').map(function(t) { return t.trim(); });
            for (var j = i + 1; j < mappings.length; j++) {
                var b = mappings[j];
                if (b.ruleType === 'default') continue;
                var bTypes = (b.wpPostTypes || 'post').split(',').map(function(t) { return t.trim(); });
                var shared = aTypes.filter(function(t) { return bTypes.indexOf(t) > -1; });
                var sameLocale = (a.shopifyLocale || '') === (b.shopifyLocale || '');
                var sameMarket = (a.shopifyMarketId || '') === (b.shopifyMarketId || '');
                var sameDest = (a.destination || 'article') === (b.destination || 'article');
                if (shared.length > 0 && a.ruleType === b.ruleType && a.ruleValue === b.ruleValue && sameLocale && sameMarket && sameDest) {
                    edges.push([a.id, b.id]);
                }
            }
        }
        // Union-find to group connected overlaps
        var parent = {};
        function find(x) { return parent[x] === x ? x : (parent[x] = find(parent[x])); }
        function union(x, y) { parent[find(x)] = find(y); }
        edges.forEach(function(e) {
            if (!parent[e[0]]) parent[e[0]] = e[0];
            if (!parent[e[1]]) parent[e[1]] = e[1];
            union(e[0], e[1]);
        });
        // Assign group index per root
        var rootToGroup = {};
        var groupCount = 0;
        var result = {}; // mappingId -> { groupIndex, color }
        Object.keys(parent).forEach(function(id) {
            var root = find(id);
            if (rootToGroup[root] === undefined) {
                rootToGroup[root] = groupCount++;
            }
            var gi = rootToGroup[root];
            result[id] = { group: gi, color: OVERLAP_COLORS[gi % OVERLAP_COLORS.length] };
        });
        return result;
    }

    /**
     * Validate a single mapping. Returns array of issues, each as
     * { msg: 'problem description', fix: 'how to fix it' }.
     * Empty array = mapping looks good.
     */
    function validateMapping(m, allMappings) {
        var issues = [];
        var ruleType = m.ruleType || 'default';
        var ruleValue = m.ruleValue || '';
        var dest = m.destination || 'article';

        if (ruleType !== 'default' && !ruleValue) {
            issues.push({
                msg: '<?php echo esc_js(__('Rule value is empty', 'wash-blog-manager')); ?>',
                fix: '<?php echo esc_js(__('Open Edit and pick a Category and/or Language matching this rule.', 'wash-blog-manager')); ?>'
            });
        }

        if (ruleType === 'language_category' && ruleValue && ruleValue.indexOf(':') === -1) {
            issues.push({
                msg: '<?php echo esc_js(__('Rule value should use "language:category" format', 'wash-blog-manager')); ?>',
                fix: '<?php echo esc_js(__('Open Edit and re-select both Language and Category dropdowns — current value is malformed.', 'wash-blog-manager')); ?>'
            });
        }

        if (dest === 'article' && !m.shopifyBlogId && m.shopifyBlogHandle !== '_new') {
            issues.push({
                msg: '<?php echo esc_js(__('No target Shopify blog selected', 'wash-blog-manager')); ?>',
                fix: '<?php echo esc_js(__('Open Edit and pick a blog from "Target Blog" dropdown (or create a new one).', 'wash-blog-manager')); ?>'
            });
        }

        if ((ruleType === 'language' || ruleType === 'language_category') && !m.shopifyLocale) {
            issues.push({
                msg: '<?php echo esc_js(__('No Shopify locale — translations will not be registered', 'wash-blog-manager')); ?>',
                fix: '<?php echo esc_js(__('Open Edit and pick a value in "Market & Locale" — either 🌍 Global or a market-scoped entry.', 'wash-blog-manager')); ?>'
            });
        }
        // NOTE: shopifyLocale set + shopifyMarketId null is a valid state
        // ("🌍 Global / no market" — shop-wide Translate & Adapt translation).
        // No warning here.

        if (ruleType === 'language_category' && m.wpBlogName && ruleValue) {
            var nameMatch = m.wpBlogName.match(/(?:language_category:\s*)?([a-z0-9-]+):([a-z0-9-]+)/i);
            var valueMatch = ruleValue.match(/^([a-z0-9-]+):([a-z0-9-]+)$/i);
            if (nameMatch && valueMatch) {
                var nameLangShort = nameMatch[1].toLowerCase().split('-')[0];
                var valueLangShort = valueMatch[1].toLowerCase().split('-')[0];
                if (nameLangShort !== valueLangShort) {
                    issues.push({
                        msg: '<?php echo esc_js(__('Rule name and value disagree', 'wash-blog-manager')); ?> ("' + nameMatch[1] + '" vs "' + valueMatch[1] + '")',
                        fix: '<?php echo esc_js(__('Open Edit and re-select Language. The stored value differs from what the name says — likely from an old import.', 'wash-blog-manager')); ?>'
                    });
                }
            }
        }

        if (ruleType !== 'default' && ruleValue) {
            var myTypes = (m.wpPostTypes || 'post').split(',').map(function(t) { return t.trim().toLowerCase(); });
            var conflicts = allMappings.filter(function(other) {
                if (other.id === m.id) return false;
                if ((other.ruleType || 'default') !== ruleType) return false;
                if ((other.ruleValue || '') !== ruleValue) return false;
                // Real conflict requires the same destination AND overlapping wpPostTypes.
                // Article + Page sharing a language rule is fine — they handle different WP post types.
                if ((other.destination || 'article') !== dest) return false;
                var otherTypes = (other.wpPostTypes || 'post').split(',').map(function(t) { return t.trim().toLowerCase(); });
                return myTypes.some(function(t) { return otherTypes.indexOf(t) !== -1; });
            });
            if (conflicts.length > 0) {
                issues.push({
                    msg: '<?php echo esc_js(__('Duplicate rule — another mapping with the same destination and post type already matches', 'wash-blog-manager')); ?>',
                    fix: '<?php echo esc_js(__('Delete one of the duplicates, or change the rule value / post types so each mapping is unique.', 'wash-blog-manager')); ?>'
                });
            }
        }

        return issues;
    }

    var mappingsSortKey = null; // null | 'rule' | 'blog' | 'translation'
    var mappingsSortDir = 'asc'; // 'asc' | 'desc'
    var mappingsFilterTarget = ''; // '' = all; otherwise blog handle or '__pages__'
    var mappingsFilterLanguage = ''; // '' = all; otherwise ruleValue (e.g. 'de-de')
    var mappingsFilterTranslation = ''; // '' = all; otherwise shopifyLocale code (e.g. 'de') or '__none__' / '__global__' / '__market__'
    var mappingsFilterDestination = ''; // '' = all; 'article' | 'page'

    function targetKey(m) {
        if (m.destination === 'page') return '__pages__';
        return getBlogHandle(m.shopifyBlogId) || m.shopifyBlogHandle || m.shopifyBlogId || '';
    }

    function targetLabelText(m) {
        return m.destination === 'page' ? '/pages/' : targetKey(m);
    }

    function attachFilterListeners() {
        var t = document.getElementById('wash-filter-target');
        if (t) {
            t.addEventListener('change', function() {
                mappingsFilterTarget = this.value;
                renderMappings();
            });
        }
        var l = document.getElementById('wash-filter-language');
        if (l) {
            l.addEventListener('change', function() {
                mappingsFilterLanguage = this.value;
                renderMappings();
            });
        }
        var d = document.getElementById('wash-filter-destination');
        if (d) {
            d.addEventListener('change', function() {
                mappingsFilterDestination = this.value;
                renderMappings();
            });
        }
        var x = document.getElementById('wash-filter-translation');
        if (x) {
            x.addEventListener('change', function() {
                mappingsFilterTranslation = this.value;
                renderMappings();
            });
        }
        var r = document.getElementById('wash-filter-reset');
        if (r) {
            r.addEventListener('click', function() {
                mappingsFilterTarget = '';
                mappingsFilterLanguage = '';
                mappingsFilterDestination = '';
                mappingsFilterTranslation = '';
                renderMappings();
            });
        }
    }

    function buildFilterToolbar() {
        var targets = {};
        var languages = {};
        var translations = {};
        var destinations = {};
        mappingsData.forEach(function(m) {
            var tk = targetKey(m);
            if (tk) targets[tk] = targetLabelText(m);
            if (m.ruleType === 'language' && m.ruleValue) {
                languages[m.ruleValue] = m.ruleValue;
            }
            if (m.shopifyLocale) {
                translations[m.shopifyLocale] = m.shopifyLocale;
            }
            destinations[m.destination || 'article'] = true;
        });

        var totalCount = mappingsData.length;
        var visibleCount = mappingsData.filter(function(m) {
            if (mappingsFilterTarget && targetKey(m) !== mappingsFilterTarget) return false;
            if (mappingsFilterLanguage && (m.ruleType !== 'language' || (m.ruleValue || '') !== mappingsFilterLanguage)) return false;
            if (mappingsFilterDestination && (m.destination || 'article') !== mappingsFilterDestination) return false;
            if (mappingsFilterTranslation) {
                var hasLocale = !!m.shopifyLocale;
                var hasMarket = !!m.shopifyMarketId;
                if (mappingsFilterTranslation === '__global__' && !(hasLocale && !hasMarket)) return false;
                else if (mappingsFilterTranslation === '__market__' && !(hasLocale && hasMarket)) return false;
                else if (mappingsFilterTranslation === '__none__' && hasLocale) return false;
                else if (mappingsFilterTranslation.indexOf('__') !== 0 && (m.shopifyLocale || '') !== mappingsFilterTranslation) return false;
            }
            return true;
        }).length;
        var filterActive = mappingsFilterTarget || mappingsFilterLanguage || mappingsFilterTranslation || mappingsFilterDestination;

        var html = '';
        html += '<span style="color:#374151;font-weight:500;"><?php echo esc_js(__('Filter:', 'wash-blog-manager')); ?></span>';

        // Destination filter
        html += '<label style="display:flex;align-items:center;gap:4px;color:#4b5563;"><?php echo esc_js(__('Destination', 'wash-blog-manager')); ?>: ';
        html += '<select id="wash-filter-destination" style="font-size:12px;padding:2px 6px;">';
        html += '<option value=""><?php echo esc_js(__('All', 'wash-blog-manager')); ?></option>';
        ['article', 'page'].forEach(function(d) {
            if (!destinations[d]) return;
            var sel = (d === mappingsFilterDestination) ? ' selected' : '';
            var label = d === 'article' ? '<?php echo esc_js(__('Article', 'wash-blog-manager')); ?>' : '<?php echo esc_js(__('Page', 'wash-blog-manager')); ?>';
            html += '<option value="' + d + '"' + sel + '>' + label + '</option>';
        });
        html += '</select></label>';

        // Target (blog/page) filter
        html += '<label style="display:flex;align-items:center;gap:4px;color:#4b5563;"><?php echo esc_js(__('Blog/Page', 'wash-blog-manager')); ?>: ';
        html += '<select id="wash-filter-target" style="font-size:12px;padding:2px 6px;">';
        html += '<option value=""><?php echo esc_js(__('All', 'wash-blog-manager')); ?></option>';
        Object.keys(targets).sort().forEach(function(k) {
            var sel = (k === mappingsFilterTarget) ? ' selected' : '';
            html += '<option value="' + k + '"' + sel + '>' + targets[k] + '</option>';
        });
        html += '</select></label>';

        // WP language filter
        html += '<label style="display:flex;align-items:center;gap:4px;color:#4b5563;"><?php echo esc_js(__('WP Language', 'wash-blog-manager')); ?>: ';
        html += '<select id="wash-filter-language" style="font-size:12px;padding:2px 6px;">';
        html += '<option value=""><?php echo esc_js(__('All', 'wash-blog-manager')); ?></option>';
        Object.keys(languages).sort().forEach(function(k) {
            var sel = (k === mappingsFilterLanguage) ? ' selected' : '';
            html += '<option value="' + k + '"' + sel + '>' + k + '</option>';
        });
        html += '</select></label>';

        // Translation filter (shopifyLocale + scope)
        html += '<label style="display:flex;align-items:center;gap:4px;color:#4b5563;"><?php echo esc_js(__('Translation', 'wash-blog-manager')); ?>: ';
        html += '<select id="wash-filter-translation" style="font-size:12px;padding:2px 6px;">';
        html += '<option value=""><?php echo esc_js(__('All', 'wash-blog-manager')); ?></option>';
        // Scope groups
        html += '<optgroup label="<?php echo esc_js(__('Scope', 'wash-blog-manager')); ?>">';
        ['__global__', '__market__', '__none__'].forEach(function(k) {
            var sel = (k === mappingsFilterTranslation) ? ' selected' : '';
            var label = k === '__global__' ? '🌍 <?php echo esc_js(__('Global only', 'wash-blog-manager')); ?>'
                : k === '__market__' ? '📍 <?php echo esc_js(__('Market-scoped', 'wash-blog-manager')); ?>'
                : '— <?php echo esc_js(__('Primary only', 'wash-blog-manager')); ?>';
            html += '<option value="' + k + '"' + sel + '>' + label + '</option>';
        });
        html += '</optgroup>';
        // Locale codes
        if (Object.keys(translations).length > 0) {
            html += '<optgroup label="<?php echo esc_js(__('Locale', 'wash-blog-manager')); ?>">';
            Object.keys(translations).sort().forEach(function(k) {
                var sel = (k === mappingsFilterTranslation) ? ' selected' : '';
                html += '<option value="' + k + '"' + sel + '>' + k + '</option>';
            });
            html += '</optgroup>';
        }
        html += '</select></label>';

        if (filterActive) {
            html += '<button type="button" id="wash-filter-reset" class="button button-small" style="margin-left:auto;"><?php echo esc_js(__('Reset filters', 'wash-blog-manager')); ?></button>';
        }

        html += '<span style="color:#6b7280;font-size:12px;">' + visibleCount + ' / ' + totalCount + '</span>';
        return html;
    }

    function renderFilterToolbar() {
        var slot = document.getElementById('wash-mappings-filter-slot');
        if (!slot) return;
        if (!mappingsData || mappingsData.length === 0) {
            slot.innerHTML = '';
            return;
        }
        slot.innerHTML = buildFilterToolbar();
        attachFilterListeners();
    }

    function getSortValue(m, key) {
        if (key === 'rule') {
            return (m.ruleType || 'default') + ':' + (m.ruleValue || '');
        }
        if (key === 'blog') {
            return (m.destination === 'page' ? '/pages/' : (getBlogHandle(m.shopifyBlogId) || m.shopifyBlogHandle || '')).toLowerCase();
        }
        if (key === 'translation') {
            // Order: Global (0) → Market (1) → none (2), then by locale
            if (m.shopifyLocale && !m.shopifyMarketId) return '0:' + m.shopifyLocale;
            if (m.shopifyLocale && m.shopifyMarketId) return '1:' + (m.shopifyLocale || '');
            return '2:';
        }
        return '';
    }

    function sortMappingsBy(key) {
        if (mappingsSortKey === key) {
            // Toggle: asc → desc → off (back to priority order)
            if (mappingsSortDir === 'asc') {
                mappingsSortDir = 'desc';
            } else {
                mappingsSortKey = null;
                mappingsSortDir = 'asc';
            }
        } else {
            mappingsSortKey = key;
            mappingsSortDir = 'asc';
        }
        renderMappings();
    }

    function sortIndicator(key) {
        if (mappingsSortKey !== key) return '<span style="color:#9ca3af;font-size:10px;margin-left:4px;">⇅</span>';
        return '<span style="color:#1d4ed8;font-size:10px;margin-left:4px;font-weight:bold;">' + (mappingsSortDir === 'asc' ? '▲' : '▼') + '</span>';
    }

    function renderMappings() {
        if (!mappingsList) return;
        renderFilterToolbar();
        if (mappingsData.length === 0) {
            mappingsList.innerHTML = '<p style="color: #666;"><?php echo esc_js(__('No mapping rules configured. Posts will sync to the default Shopify blog.', 'wash-blog-manager')); ?></p>';
            return;
        }

        var dragHeaderTitle = mappingsSortKey
            ? '<?php echo esc_js(__('Drag disabled while sorted by column — click the same header twice to clear sort.', 'wash-blog-manager')); ?>'
            : '<?php echo esc_js(__('Drag to reorder priority', 'wash-blog-manager')); ?>';

        var html = '';
        html += '<table class="widefat striped wash-mappings-hover" id="wash-mappings-table"><thead><tr>' +
            '<th style="width:30px;" title="' + dragHeaderTitle + '">⇅</th>' +
            '<th class="wash-sortable" data-sort="rule" style="cursor:pointer;user-select:none;"><?php echo esc_js(__('Rule', 'wash-blog-manager')); ?>' + sortIndicator('rule') + '</th>' +
            '<th class="wash-sortable" data-sort="blog" style="cursor:pointer;user-select:none;"><?php echo esc_js(__('Blog / Page', 'wash-blog-manager')); ?>' + sortIndicator('blog') + '</th>' +
            '<th class="wash-sortable" data-sort="translation" style="cursor:pointer;user-select:none;"><?php echo esc_js(__('Translation', 'wash-blog-manager')); ?>' + sortIndicator('translation') + '</th>' +
            '<th><?php echo esc_js(__('Actions', 'wash-blog-manager')); ?></th>' +
            '</tr></thead><tbody id="wash-mappings-tbody">';

        var overlapGroups = detectOverlapGroups(mappingsData);

        var rowsToRender = mappingsData.filter(function(m) {
            if (mappingsFilterTarget && targetKey(m) !== mappingsFilterTarget) return false;
            if (mappingsFilterLanguage && (m.ruleType !== 'language' || (m.ruleValue || '') !== mappingsFilterLanguage)) return false;
            if (mappingsFilterDestination && (m.destination || 'article') !== mappingsFilterDestination) return false;
            if (mappingsFilterTranslation) {
                var hasLocale = !!m.shopifyLocale;
                var hasMarket = !!m.shopifyMarketId;
                if (mappingsFilterTranslation === '__global__' && !(hasLocale && !hasMarket)) return false;
                else if (mappingsFilterTranslation === '__market__' && !(hasLocale && hasMarket)) return false;
                else if (mappingsFilterTranslation === '__none__' && hasLocale) return false;
                else if (mappingsFilterTranslation.indexOf('__') !== 0 && (m.shopifyLocale || '') !== mappingsFilterTranslation) return false;
            }
            return true;
        });

        if (rowsToRender.length === 0) {
            mappingsList.innerHTML = '<p style="color:#6b7280;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;text-align:center;"><?php echo esc_js(__('No mappings match the current filters.', 'wash-blog-manager')); ?></p>';
            return;
        }

        if (mappingsSortKey) {
            rowsToRender = rowsToRender.slice().sort(function(a, b) {
                var av = getSortValue(a, mappingsSortKey);
                var bv = getSortValue(b, mappingsSortKey);
                if (av < bv) return mappingsSortDir === 'asc' ? -1 : 1;
                if (av > bv) return mappingsSortDir === 'asc' ? 1 : -1;
                return 0;
            });
        }

        rowsToRender.forEach(function(m, idx) {
            var ruleLabel, badgeClass;
            if (m.ruleType === 'default') {
                ruleLabel = '<?php echo esc_js(__('Default', 'wash-blog-manager')); ?>';
                badgeClass = 'background:#28a745;';
            } else if (m.ruleType === 'language') {
                ruleLabel = '<?php echo esc_js(__('Language', 'wash-blog-manager')); ?>: ' + (m.ruleValue || '');
                badgeClass = 'background:#007bff;';
            } else if (m.ruleType === 'language_category') {
                var parts = (m.ruleValue || '').split(':');
                ruleLabel = '<?php echo esc_js(__('Language', 'wash-blog-manager')); ?>: ' + (parts[0] || '') + ' + <?php echo esc_js(__('Category', 'wash-blog-manager')); ?>: ' + (parts[1] || '');
                badgeClass = 'background:#6f42c1;';
            } else {
                ruleLabel = '<?php echo esc_js(__('Category', 'wash-blog-manager')); ?>: ' + (m.ruleValue || '');
                badgeClass = 'background:#fd7e14;';
            }
            var destBadge = m.destination === 'page'
                ? '<span style="background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;margin-right:4px;">PAGE</span>'
                : '<span style="background:#e0e7ff;color:#3730a3;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;margin-right:4px;">ARTICLE</span>';
            var typesTag = '<span style="background:#f3f4f6;color:#6b7280;padding:1px 5px;border-radius:3px;font-size:11px;margin-left:4px;">' + (m.wpPostTypes || 'post') + '</span>';
            var urlExample = getUrlExample(m);
            var targetLabel = m.destination === 'page' ? '/pages/' : (getBlogHandle(m.shopifyBlogId) || m.shopifyBlogHandle || m.shopifyBlogId);

            // Scope badge — where the translation lands in Shopify
            var scopeBadge = '';
            if (m.shopifyLocale && !m.shopifyMarketId) {
                scopeBadge = '<div title="<?php echo esc_js(__('Shop-wide translation (Translate & Adapt). No market scope.', 'wash-blog-manager')); ?>" style="display:inline-block;background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;">🌍 Global / ' + m.shopifyLocale.toUpperCase() + '</div>';
            } else if (m.shopifyLocale && m.shopifyMarketId) {
                var marketForBadge = getMarketById(m.shopifyMarketId);
                var marketBadgeName = marketForBadge ? marketForBadge.name : '?';
                scopeBadge = '<div title="<?php echo esc_js(__('Market-scoped translation. Visible only to buyers in this market.', 'wash-blog-manager')); ?>" style="display:inline-block;background:#eff6ff;color:#1e3a8a;border:1px solid #93c5fd;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;">📍 ' + marketBadgeName + ' / ' + m.shopifyLocale + '</div>';
            } else {
                scopeBadge = '<div title="<?php echo esc_js(__('Primary language only — no translation registered.', 'wash-blog-manager')); ?>" style="display:inline-block;background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;padding:1px 6px;border-radius:3px;font-size:11px;">— primary only</div>';
            }

            var overlap = overlapGroups[m.id];
            var rowBg = overlap ? 'background:' + overlap.color + ';' : '';
            var warnIcon = overlap ? '<span title="<?php echo esc_js(__('Overlapping rule — higher priority wins', 'wash-blog-manager')); ?>" style="color:#b45309;font-size:14px;margin-right:4px;cursor:help;">&#9888;</span>' : '';

            // Configuration validation — show error icon with hints if rule is misconfigured
            var validationIssues = validateMapping(m, mappingsData);
            var errorIcon = '';
            if (validationIssues.length > 0) {
                var tipLines = validationIssues.map(function(issue) {
                    return '• ' + issue.msg + '\n  → ' + issue.fix;
                }).join('\n\n');
                var tipTitle = '<?php echo esc_js(__('Configuration issues:', 'wash-blog-manager')); ?>\n\n' + tipLines;
                errorIcon = '<span title="' + tipTitle.replace(/"/g, '&quot;') + '" style="color:#dc2626;font-size:16px;margin-right:4px;cursor:help;font-weight:bold;">&#10071;</span>';
            }

            var displayNumber = '#' + (idx + 1);
            var idTooltip = m.id + ' — <?php echo esc_js(__('click to copy', 'wash-blog-manager')); ?>';
            var ruleIdBadge = '<span title="' + idTooltip + '" class="wash-mapping-id" data-id="' + m.id + '" style="display:inline-block;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;font-weight:600;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;padding:1px 8px;border-radius:10px;margin-right:6px;cursor:copy;user-select:none;">' + displayNumber + '</span>';

            html += '<tr class="wash-mapping-row" data-id="' + m.id + '" data-priority="' + m.priority + '" style="' + rowBg + '">' +
                '<td class="wash-mapping-drag" style="cursor:grab;text-align:center;color:#999;font-size:16px;user-select:none;vertical-align:middle;">☰</td>' +
                '<td>' +
                    ruleIdBadge +
                    errorIcon +
                    warnIcon +
                    destBadge +
                    '<span style="' + badgeClass + 'color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">' + ruleLabel + '</span>' +
                    typesTag +
                    '<div style="margin-top:6px;padding:4px 6px;background:#f8f9fa;border-radius:3px;border:1px solid #e9ecef;">' + urlExample + '</div>' +
                '</td>' +
                '<td style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#1f2937;">' + targetLabel + '</td>' +
                '<td>' + scopeBadge + '</td>' +
                '<td style="white-space:nowrap;">' +
                '<button type="button" class="button button-small wash-edit-mapping wash-icon-btn" data-id="' + m.id + '" title="<?php echo esc_js(__('Edit', 'wash-blog-manager')); ?>" aria-label="<?php echo esc_js(__('Edit', 'wash-blog-manager')); ?>"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button> ' +
                '<button type="button" class="button button-small wash-duplicate-mapping wash-icon-btn" data-id="' + m.id + '" title="<?php echo esc_js(__('Duplicate', 'wash-blog-manager')); ?>" aria-label="<?php echo esc_js(__('Duplicate', 'wash-blog-manager')); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button> ' +
                '<button type="button" class="button button-small wash-delete-mapping wash-icon-btn" data-id="' + m.id + '" title="<?php echo esc_js(__('Delete', 'wash-blog-manager')); ?>" aria-label="<?php echo esc_js(__('Delete', 'wash-blog-manager')); ?>" style="color:#dc3545;"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>' +
                '</td></tr>';
        });

        html += '</tbody></table>';

        mappingsList.innerHTML = html;

        // Attach event listeners
        mappingsList.querySelectorAll('.wash-edit-mapping').forEach(function(btn) {
            btn.addEventListener('click', function() {
                editMapping(this.getAttribute('data-id'));
            });
        });
        mappingsList.querySelectorAll('.wash-delete-mapping').forEach(function(btn) {
            btn.addEventListener('click', function() {
                deleteMapping(this.getAttribute('data-id'));
            });
        });
        mappingsList.querySelectorAll('.wash-duplicate-mapping').forEach(function(btn) {
            btn.addEventListener('click', function() {
                duplicateMapping(this.getAttribute('data-id'));
            });
        });
        mappingsList.querySelectorAll('.wash-mapping-id').forEach(function(el) {
            el.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(id);
                }
            });
        });

        // Sortable column headers
        mappingsList.querySelectorAll('th.wash-sortable').forEach(function(th) {
            th.addEventListener('click', function() {
                sortMappingsBy(this.getAttribute('data-sort'));
            });
        });

        // Enable drag-and-drop reordering
        if (jQuery && jQuery.fn.sortable) {
            jQuery('#wash-mappings-tbody').sortable({
                handle: '.wash-mapping-drag',
                items: '.wash-mapping-row',
                axis: 'y',
                cursor: 'grabbing',
                placeholder: 'wash-sr-placeholder',
                tolerance: 'pointer',
                update: function() {
                    // Update priority based on new order and save
                    var rows = document.querySelectorAll('.wash-mapping-row');
                    var updates = [];
                    rows.forEach(function(row, idx) {
                        var id = row.getAttribute('data-id');
                        var newPriority = (rows.length - idx) * 10;
                        row.setAttribute('data-priority', newPriority);
                        updates.push({ id: id, priority: newPriority });
                    });
                    // Save new priorities via AJAX
                    jQuery.ajax({
                        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                        type: 'POST',
                        data: {
                            action: 'wash_reorder_mappings',
                            nonce: '<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>',
                            updates: JSON.stringify(updates),
                        },
                        success: function(res) {
                            if (res.success) {
                                washMappingsToast('<?php echo esc_js(__('Order saved', 'wash-blog-manager')); ?>');
                            } else {
                                washMappingsToast('<?php echo esc_js(__('Failed to save order', 'wash-blog-manager')); ?>', 'error');
                            }
                        },
                        error: function() {
                            washMappingsToast('<?php echo esc_js(__('Failed to save order', 'wash-blog-manager')); ?>', 'error');
                        }
                    });
                },
            });
        }

    }

    var ruleValueLang = document.getElementById('wash-rule-value-lang');
    var ruleValueCat = document.getElementById('wash-rule-value-cat');
    var ruleTypeHidden = document.getElementById('wash-rule-type');

    /**
     * Derive ruleType and ruleValue from the two selectors.
     * Both can be set = combined rule, or one = single filter, none = default.
     */
    function getRuleData() {
        var lang = ruleValueLang ? ruleValueLang.value : '';
        var cat = ruleValueCat ? ruleValueCat.value : '';
        if (lang && cat) return { ruleType: 'language_category', ruleValue: lang + ':' + cat };
        if (lang) return { ruleType: 'language', ruleValue: lang };
        if (cat) return { ruleType: 'category', ruleValue: cat };
        return { ruleType: 'default', ruleValue: '' };
    }

    // Region aliases (ISO ↔ common) — must match queue-worker REGION_ALIASES
    var REGION_ALIASES = { gb: 'uk', uk: 'gb' };

    /** Ensure a value exists as an option in a select, adding it as legacy if missing. */
    function ensureSelectOption(select, value) {
        if (!select || !value) return;
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].value === value) return;
        }
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = value + ' (legacy — re-save to migrate)';
        opt.style.color = '#b45309';
        select.appendChild(opt);
    }

    /** Set selectors from saved mapping data */
    function setRuleSelectors(ruleType, ruleValue) {
        if (ruleValueLang) ruleValueLang.value = '';
        if (ruleValueCat) ruleValueCat.value = '';
        if (!ruleType || ruleType === 'default') return;

        var langVal = '';
        var catVal = '';
        if (ruleType === 'language') {
            langVal = ruleValue || '';
        } else if (ruleType === 'category') {
            catVal = ruleValue || '';
        } else if (ruleType === 'language_category' && ruleValue) {
            var parts = ruleValue.split(':');
            langVal = parts[0] || '';
            catVal = parts[1] || '';
        }

        if (langVal && ruleValueLang) {
            ensureSelectOption(ruleValueLang, langVal);
            ruleValueLang.value = langVal;
        }
        if (catVal && ruleValueCat) {
            ensureSelectOption(ruleValueCat, catVal);
            ruleValueCat.value = catVal;
        }
    }

    // Show/hide new blog input
    if (shopifyBlogSelect) {
        shopifyBlogSelect.addEventListener('change', function() {
            newBlogRow.style.display = this.value === '_new' ? 'block' : 'none';
        });
    }

    // Add mapping button
    var exportBtn = document.getElementById('wash-export-mappings');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            if (!mappingsData || mappingsData.length === 0) {
                alert('<?php echo esc_js(__('No mapping rules to export.', 'wash-blog-manager')); ?>');
                return;
            }
            var json = JSON.stringify(mappingsData, null, 2);
            var blob = new Blob([json], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var now = new Date();
            var ts = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + '_' + String(now.getHours()).padStart(2, '0') + String(now.getMinutes()).padStart(2, '0') + String(now.getSeconds()).padStart(2, '0');
            var a = document.createElement('a');
            a.href = url;
            a.download = 'wash-mapping-rules_' + ts + '.json';
            a.click();
            URL.revokeObjectURL(url);
        });
    }

    var importBtn = document.getElementById('wash-import-mappings');
    var importFileInput = document.getElementById('wash-import-mappings-file');
    if (importBtn && importFileInput) {
        importBtn.addEventListener('click', function() {
            importFileInput.click();
        });
        importFileInput.addEventListener('change', function() {
            var file = this.files[0];
            if (!file) return;
            this.value = '';
            var reader = new FileReader();
            reader.onload = function(e) {
                var imported;
                try {
                    imported = JSON.parse(e.target.result);
                } catch (err) {
                    alert('<?php echo esc_js(__('Invalid JSON file.', 'wash-blog-manager')); ?>');
                    return;
                }
                if (!Array.isArray(imported) || imported.length === 0) {
                    alert('<?php echo esc_js(__('No mapping rules found in file.', 'wash-blog-manager')); ?>');
                    return;
                }
                // Auto-fix: recover ruleValue from wpBlogName if missing
                var fixed = 0;
                imported.forEach(function(rule) {
                    if (rule.ruleValue || !rule.wpBlogName) return;
                    var name = rule.wpBlogName;
                    // "language_category: fr-ch:news-ch-fr" → ruleType=language_category, ruleValue=fr-ch:news-ch-fr
                    // "language: de-de" → ruleType=language, ruleValue=de-de
                    var match = name.match(/^(language_category|language|category):\s*(.+?)(?:\s*\(.*\))?$/);
                    if (match) {
                        var derivedType = match[1];
                        var derivedValue = match[2].trim();
                        if (derivedValue) {
                            rule.ruleValue = derivedValue;
                            if (!rule.ruleType || rule.ruleType === 'default') {
                                rule.ruleType = derivedType;
                            }
                            fixed++;
                        }
                    }
                });
                var confirmMsg = '<?php echo esc_js(__('This will delete all existing mapping rules and import', 'wash-blog-manager')); ?> ' + imported.length + ' <?php echo esc_js(__('rules.', 'wash-blog-manager')); ?>';
                if (fixed > 0) {
                    confirmMsg += '\n\n' + fixed + ' <?php echo esc_js(__('rules had missing ruleValue — auto-recovered from names.', 'wash-blog-manager')); ?>';
                }
                confirmMsg += '\n\n<?php echo esc_js(__('Continue?', 'wash-blog-manager')); ?>';
                if (!confirm(confirmMsg)) {
                    return;
                }
                importBtn.disabled = true;
                importBtn.textContent = '<?php echo esc_js(__('Importing...', 'wash-blog-manager')); ?>';

                // Step 1: delete all existing mappings
                var deletePromises = mappingsData.map(function(m) {
                    var fd = new FormData();
                    fd.append('action', 'wash_delete_mapping');
                    fd.append('nonce', '<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>');
                    fd.append('mappingId', m.id);
                    return fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: washWithConn(fd) }).then(function(r) { return r.json(); });
                });

                Promise.all(deletePromises).then(function() {
                    // Step 2: create imported mappings sequentially
                    var chain = Promise.resolve();
                    var created = 0;
                    var failed = 0;
                    var errors = [];
                    imported.forEach(function(rule, idx) {
                        chain = chain.then(function() {
                            var fd = new FormData();
                            fd.append('action', 'wash_create_mapping');
                            fd.append('nonce', '<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>');
                            fd.append('destination', rule.destination || 'article');
                            fd.append('wpPostTypes', rule.wpPostTypes || 'post');
                            fd.append('ruleType', rule.ruleType || 'default');
                            fd.append('ruleValue', rule.ruleValue || '');
                            fd.append('shopifyBlogId', rule.shopifyBlogId || '');
                            fd.append('newBlogName', '');
                            fd.append('priority', rule.priority !== undefined ? rule.priority : (imported.length - idx) * 10);
                            fd.append('marketId', rule.shopifyMarketId || '');
                            fd.append('shopifyLocale', rule.shopifyLocale || '');
                            return fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: washWithConn(fd) })
                                .then(function(r) { return r.json(); })
                                .then(function(resp) {
                                    if (resp.success) {
                                        created++;
                                    } else {
                                        failed++;
                                        errors.push('#' + (idx + 1) + ' ' + (rule.wpBlogName || rule.ruleType) + ': ' + (resp.data || 'unknown error'));
                                    }
                                });
                        });
                    });
                    return chain.then(function() {
                        importBtn.disabled = false;
                        importBtn.innerHTML = '<span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span> <?php echo esc_js(__('Import', 'wash-blog-manager')); ?>';
                        loadMappings();
                        if (window.washClearDirty) washClearDirty('blogs');
                        var msg = '<?php echo esc_js(__('Import complete:', 'wash-blog-manager')); ?> ' + created + ' <?php echo esc_js(__('created', 'wash-blog-manager')); ?>';
                        if (failed > 0) {
                            msg += ', ' + failed + ' <?php echo esc_js(__('failed', 'wash-blog-manager')); ?>:\n' + errors.join('\n');
                        }
                        alert(msg);
                    });
                }).catch(function() {
                    importBtn.disabled = false;
                    importBtn.innerHTML = '<span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span> <?php echo esc_js(__('Import', 'wash-blog-manager')); ?>';
                    alert('<?php echo esc_js(__('Import failed.', 'wash-blog-manager')); ?>');
                });
            };
            reader.readAsText(file);
        });
    }

    if (addMappingBtn) {
        addMappingBtn.addEventListener('click', function() {
            document.getElementById('wash-mapping-id').value = '';
            document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Add Mapping Rule', 'wash-blog-manager')); ?>';
            if (destinationSelect) destinationSelect.value = 'article';
            setPostTypeCheckboxes('post');
            updateDestinationFields();
            setRuleSelectors('default', '');
            shopifyBlogSelect.value = '';
            document.getElementById('wash-priority').value = '0';
            if (marketLocaleSelect) marketLocaleSelect.value = '';
            newBlogRow.style.display = 'none';
            mappingForm.style.display = 'block';
        });
    }

    // Cancel button
    if (cancelMappingBtn) {
        cancelMappingBtn.addEventListener('click', function() {
            mappingForm.style.display = 'none';
        });
    }

    // Save mapping
    if (saveMappingBtn) {
        saveMappingBtn.addEventListener('click', function() {
            var mappingId = document.getElementById('wash-mapping-id').value;
            // Parse market|locale value
            var marketLocaleValue = marketLocaleSelect ? marketLocaleSelect.value : '';
            var marketId = '';
            var shopifyLocale = '';
            if (marketLocaleValue && marketLocaleValue.indexOf('|') > -1) {
                var parts = marketLocaleValue.split('|');
                marketId = parts[0];
                shopifyLocale = parts[1];
            }

            var dest = destinationSelect ? destinationSelect.value : 'article';
            var data = {
                action: mappingId ? 'wash_update_mapping' : 'wash_create_mapping',
                nonce: '<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>',
                mappingId: mappingId,
                destination: dest,
                wpPostTypes: wpPostTypesInput ? wpPostTypesInput.value : 'post',
                ruleType: getRuleData().ruleType,
                ruleValue: getRuleData().ruleValue,
                shopifyBlogId: dest === 'article' ? shopifyBlogSelect.value : '',
                newBlogName: document.getElementById('wash-new-blog-name').value,
                priority: document.getElementById('wash-priority').value,
                marketId: marketId,
                shopifyLocale: shopifyLocale
            };

            saveMappingBtn.disabled = true;
            saveMappingBtn.textContent = '<?php echo esc_js(__('Saving...', 'wash-blog-manager')); ?>';

            var formData = new FormData();
            Object.keys(data).forEach(function(key) { formData.append(key, data[key]); });

            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                body: washWithConn(formData)
            })
            .then(function(r) { return r.json(); })
            .then(function(response) {
                saveMappingBtn.disabled = false;
                saveMappingBtn.textContent = '<?php echo esc_js(__('Save Mapping', 'wash-blog-manager')); ?>';
                if (response.success) {
                    mappingForm.style.display = 'none';
                    loadMappings();
                    if (response.data.blogCreated) {
                        loadShopifyBlogs();
                    }
                    if (window.washClearDirty) washClearDirty('blogs');
                } else {
                    alert(response.data || 'Failed to save mapping');
                }
            })
            .catch(function() {
                saveMappingBtn.disabled = false;
                saveMappingBtn.textContent = '<?php echo esc_js(__('Save Mapping', 'wash-blog-manager')); ?>';
                alert('Failed to save mapping');
            });
        });
    }

    function editMapping(id) {
        var mapping = mappingsData.find(function(m) { return m.id === id; });
        if (!mapping) return;

        duplicateOriginal = null;
        checkDuplicateChanged();

        document.getElementById('wash-mapping-id').value = mapping.id;
        document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Edit Mapping Rule', 'wash-blog-manager')); ?>';
        if (destinationSelect) destinationSelect.value = mapping.destination || 'article';
        setPostTypeCheckboxes(mapping.wpPostTypes || 'post');
        updateDestinationFields();
        setRuleSelectors(mapping.ruleType, mapping.ruleValue);
        shopifyBlogSelect.value = mapping.shopifyBlogId || '';
        document.getElementById('wash-priority').value = mapping.priority;
        newBlogRow.style.display = 'none';
        // Set market/locale — value format is "marketId|locale" (marketId empty = 🌍 Global)
        if (marketLocaleSelect && mapping.shopifyLocale) {
            var exactMatch = (mapping.shopifyMarketId || '') + '|' + mapping.shopifyLocale;
            marketLocaleSelect.value = exactMatch;
            if (!marketLocaleSelect.value) {
                // Fallback: find first option matching locale (e.g. when target market was deleted)
                for (var i = 0; i < marketLocaleSelect.options.length; i++) {
                    var optVal = marketLocaleSelect.options[i].value;
                    if (optVal && optVal.split('|')[1] === mapping.shopifyLocale) {
                        marketLocaleSelect.value = optVal;
                        break;
                    }
                }
            }
        } else if (marketLocaleSelect) {
            marketLocaleSelect.value = '';
        }
        mappingForm.style.display = 'block';
        // Scroll to form so the user sees what they're editing
        setTimeout(function() {
            mappingForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
    }

    // --- Duplicate mapping support ---
    var duplicateOriginal = null;

    function duplicateMapping(id) {
        var mapping = mappingsData.find(function(m) { return m.id === id; });
        if (!mapping) return;

        // Open form as NEW (empty ID) but pre-filled with source data
        document.getElementById('wash-mapping-id').value = '';
        document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Duplicate Mapping Rule', 'wash-blog-manager')); ?>';
        if (destinationSelect) destinationSelect.value = mapping.destination || 'article';
        setPostTypeCheckboxes(mapping.wpPostTypes || 'post');
        updateDestinationFields();
        setRuleSelectors(mapping.ruleType, mapping.ruleValue);
        shopifyBlogSelect.value = mapping.shopifyBlogId || '';
        document.getElementById('wash-priority').value = '0';
        newBlogRow.style.display = 'none';
        if (marketLocaleSelect && mapping.shopifyLocale) {
            // Empty marketId = Global (no market scope)
            marketLocaleSelect.value = (mapping.shopifyMarketId || '') + '|' + mapping.shopifyLocale;
        } else if (marketLocaleSelect) {
            marketLocaleSelect.value = '';
        }
        mappingForm.style.display = 'block';

        // Capture original from actual form state (after fields are set)
        // This ensures comparison uses exactly the same format as getDuplicateCurrentValues()
        setTimeout(function() {
            duplicateOriginal = getDuplicateCurrentValues();
            checkDuplicateChanged();
            mappingForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
    }

    function getDuplicateCurrentValues() {
        var marketLocaleValue = marketLocaleSelect ? marketLocaleSelect.value : '';
        var mId = '', sLocale = '';
        if (marketLocaleValue && marketLocaleValue.indexOf('|') > -1) {
            var p = marketLocaleValue.split('|');
            mId = p[0];
            sLocale = p[1];
        }
        return {
            destination: destinationSelect ? destinationSelect.value : 'article',
            wpPostTypes: wpPostTypesInput ? wpPostTypesInput.value : 'post',
            ruleType: getRuleData().ruleType,
            ruleValue: getRuleData().ruleValue || '',
            shopifyBlogId: shopifyBlogSelect ? shopifyBlogSelect.value : '',
            shopifyLocale: sLocale,
            shopifyMarketId: mId,
        };
    }

    function checkDuplicateChanged() {
        if (!duplicateOriginal) {
            mappingForm.style.border = '';
            mappingForm.style.background = '';
            saveMappingBtn.disabled = false;
            return;
        }
        var current = getDuplicateCurrentValues();
        var identical = (
            current.destination === duplicateOriginal.destination &&
            current.wpPostTypes === duplicateOriginal.wpPostTypes &&
            current.ruleType === duplicateOriginal.ruleType &&
            current.ruleValue === duplicateOriginal.ruleValue &&
            current.shopifyBlogId === duplicateOriginal.shopifyBlogId &&
            current.shopifyLocale === duplicateOriginal.shopifyLocale &&
            current.shopifyMarketId === duplicateOriginal.shopifyMarketId
        );
        if (identical) {
            mappingForm.style.border = '2px solid #d4a017';
            mappingForm.style.background = '#fef9e7';
            saveMappingBtn.disabled = true;
            saveMappingBtn.title = '<?php echo esc_js(__('Change at least one field before saving', 'wash-blog-manager')); ?>';
        } else {
            mappingForm.style.border = '1px solid #ddd';
            mappingForm.style.background = '#f9f9f9';
            saveMappingBtn.disabled = false;
            saveMappingBtn.title = '';
        }
    }

    // Listen for changes on all form fields to re-validate duplicate
    [destinationSelect, shopifyBlogSelect, marketLocaleSelect, domainSelect].forEach(function(el) {
        if (el) el.addEventListener('change', checkDuplicateChanged);
    });
    document.querySelectorAll('.wash-pt-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            // wpPostTypes hidden input is updated by existing handler, delay check
            setTimeout(checkDuplicateChanged, 50);
        });
    });
    document.querySelectorAll('#wash-rule-value-cat, #wash-rule-value-lang').forEach(function(el) {
        el.addEventListener('change', checkDuplicateChanged);
    });
    // Clear duplicate state when Add or Edit opens
    var origAddClick = addMappingBtn ? addMappingBtn.onclick : null;
    if (addMappingBtn) {
        addMappingBtn.addEventListener('click', function() { duplicateOriginal = null; checkDuplicateChanged(); });
    }

    function deleteMapping(id) {
        if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this mapping?', 'wash-blog-manager')); ?>')) return;

        var formData = new FormData();
        formData.append('action', 'wash_delete_mapping');
        formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('wash_mappings')); ?>');
        formData.append('mappingId', id);

        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
            method: 'POST',
            body: washWithConn(formData)
        })
        .then(function(r) { return r.json(); })
        .then(function(response) {
            if (response.success) {
                loadMappings();
                if (window.washClearDirty) washClearDirty('blogs');
            } else {
                alert(response.data || 'Failed to delete mapping');
            }
        });
    }

    // Author mapping toggle
    var authorsEnabledCheckbox = document.getElementById('wash-authors-enabled');
    var authorMappingsSection = document.getElementById('wash-author-mappings-section');

    if (authorsEnabledCheckbox && authorMappingsSection) {
        authorsEnabledCheckbox.addEventListener('change', function() {
            if (this.checked) {
                authorMappingsSection.style.opacity = '1';
                authorMappingsSection.style.pointerEvents = 'auto';
            } else {
                authorMappingsSection.style.opacity = '0.5';
                authorMappingsSection.style.pointerEvents = 'none';
            }
        });
    }

    // Files & Media toggle
    var filesEnabledCheckbox = document.getElementById('wash-files-enabled');
    var webpRow = document.getElementById('wash-webp-row');
    var fileTypesRow = document.getElementById('wash-file-types-row');

    if (filesEnabledCheckbox) {
        filesEnabledCheckbox.addEventListener('change', function() {
            var rows = [webpRow, fileTypesRow];
            rows.forEach(function(row) {
                if (row) {
                    if (filesEnabledCheckbox.checked) {
                        row.style.opacity = '1';
                        row.style.pointerEvents = 'auto';
                    } else {
                        row.style.opacity = '0.5';
                        row.style.pointerEvents = 'none';
                    }
                }
            });
        });
    }

})();
<?php wp_add_inline_script('wash-admin', ob_get_clean(), 'after'); ?>
