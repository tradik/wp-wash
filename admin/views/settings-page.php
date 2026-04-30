<?php
if (!defined('ABSPATH')) {
    exit;
}

$isConnected = !empty(get_option('wash_api_key'));
$shopifyStore = get_option('wash_shopify_store', '');
$shopifyAppSlug = (new WashApiClient())->getShopifyAppSlug();

// Get WordPress permalink structure for URL examples
$permalinkStructure = get_option('permalink_structure', '/%postname%/');
$permalinkExample = str_replace(
    ['%year%', '%monthnum%', '%day%', '%postname%', '%post_id%', '%category%', '%author%'],
    [date('Y'), date('m'), date('d'), '{slug}', '{id}', '{category}', '{author}'],
    $permalinkStructure ?: '/{slug}/'
);
$permalinkExample = home_url($permalinkExample);

// Multi-language permalink (with {lang} prefix)
$multiLangPermalink = home_url('/{lang}' . str_replace(home_url(''), '', $permalinkExample));

// Category base from WordPress settings
$categoryBase = get_option('category_base', 'category') ?: 'category';
$categoryExample = home_url('/' . $categoryBase . '/{category}/');

// Fetch plan info for feature gating
$planInfo = null;
$features = [];
$upgradeUrl = '';
$planName = 'FREE';
$permissions = [];

// Check if plan refresh was requested
$forceRefreshPlan = isset($_GET['refresh_plan']) && $_GET['refresh_plan'] === '1';

$connectionError = '';

if ($isConnected) {
    $apiClient = new WashApiClient();

    // Force refresh plan info and permissions if requested via query parameter
    if ($forceRefreshPlan) {
        $apiClient->clearPlanInfoCache();
        $apiClient->clearPermissionsCache();
    }

    $planInfo = $apiClient->getPlanInfo($forceRefreshPlan);
    if (is_wp_error($planInfo)) {
        $connectionError = $planInfo->get_error_message();
    } elseif (!empty($planInfo['features'])) {
        $features = $planInfo['features'];
        $upgradeUrl = $planInfo['upgradeUrl'] ?? '';
        $planName = $planInfo['plan'] ?? 'FREE';
    }

    // Local feature overrides for features not yet returned by API
    if (in_array($planName, ['BUSINESS', 'ENTERPRISE'], true)) {
        $features['searchReplace'] = true;
    }

    // Fetch permissions from Shopify App
    $permissions = $apiClient->getPermissions();
    if (is_wp_error($permissions)) {
        $permissions = []; // Default to empty = all allowed
    }

    // Check if user can modify settings based on admin-only permissions
    $userCanModifySettings = $apiClient->canUserModifySettings($permissions);
} else {
    $userCanModifySettings = true; // Not connected, allow access
}

// If not defined above (e.g., not connected), default to true
if (!isset($userCanModifySettings)) {
    $userCanModifySettings = true;
}

// Tab to permission field mapping
$tabPermissions = [
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

// Check if update refresh was requested
$forceRefreshUpdates = isset($_GET['refresh_updates']) && $_GET['refresh_updates'] === '1';

// Check if settings sync was requested
$forceRefreshSettings = isset($_GET['refresh_settings']) && $_GET['refresh_settings'] === '1';

// Sync settings from Shopify if requested
if ($forceRefreshSettings && $isConnected) {
    $apiClient = $apiClient ?? new WashApiClient();
    $settingsResponse = $apiClient->getBackedUpSettings();
    if (!is_wp_error($settingsResponse) && !empty($settingsResponse['settings'])) {
        $apiClient->restoreSettings($settingsResponse['settings']);
    }
}

// Check for plugin update
$updateAvailable = false;
$latestVersion = WASH_VERSION;
if ($isConnected) {
    $updater = new WashUpdater();

    // Force refresh if requested
    if ($forceRefreshUpdates) {
        $updater->clearCache();
        // Also clear WordPress plugin update transient
        delete_site_transient('update_plugins');
    }

    $versionInfo = $updater->getVersionInfo($forceRefreshUpdates);
    if (!is_wp_error($versionInfo) && !empty($versionInfo['plugin']['latestVersion'])) {
        $latestVersion = $versionInfo['plugin']['latestVersion'];
        // Only show update if latestVersion is actually newer than current
        if (version_compare($latestVersion, WASH_VERSION, '>')) {
            $updateAvailable = true;
        }
    }
}

// Feature to tab mapping
$tabFeatures = [
    'blogs' => 'blogMapping',
    'redirections' => 'smartRedirections',
    'hreflangs' => 'hreflang',
    'metafields' => 'metafields',
    'authors' => 'authorMapping',
    'searchreplace' => 'searchReplace',
];

// Required plans for locked features
$featurePlans = [
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
function wash_is_feature_locked($featureName, $features) {
    if (empty($features)) {
        return false; // No plan info, don't lock
    }
    return empty($features[$featureName]);
}

/**
 * Check if a tab is locked by Shopify permissions.
 */
function wash_is_permission_locked($tab, $tabPermissions, $permissions) {
    if (empty($permissions)) {
        return false; // No permissions info, don't lock
    }
    $permissionField = $tabPermissions[$tab] ?? null;
    if (!$permissionField) {
        return false; // Tab has no permission field
    }
    return isset($permissions[$permissionField]) && $permissions[$permissionField] === false;
}

/**
 * Render a tab link with optional locked state (plan or permission).
 */
function wash_render_tab($tab, $label, $features, $tabFeatures, $featurePlans, $upgradeUrl, $isActive = false, $permissions = [], $tabPermissions = [], $isConnected = true) {
    $featureName = $tabFeatures[$tab] ?? null;
    $isPlanLocked = $featureName && wash_is_feature_locked($featureName, $features);
    $isPermissionLocked = wash_is_permission_locked($tab, $tabPermissions, $permissions);

    // Disable all tabs except 'connection' when not connected
    $isDisconnected = !$isConnected && $tab !== 'connection';

    $isLocked = $isPlanLocked || $isPermissionLocked;
    $requiredPlan = $featurePlans[$featureName] ?? 'PRO';

    $classes = 'nav-tab';
    if ($isActive) {
        $classes .= ' nav-tab-active';
    }
    if ($isLocked) {
        $classes .= ' nav-tab-locked';
    }
    if ($isDisconnected) {
        $classes .= ' nav-tab-disabled';
    }

    $lockIcon = '';
    if ($isLocked) {
        $lockIcon = ' <span class="dashicons dashicons-lock" style="font-size: 14px; opacity: 0.6;"></span>';
    }

    if ($isDisconnected) {
        $title = esc_attr__('Connect to Shopify first', 'wash');
    } elseif ($isPlanLocked) {
        $title = sprintf(esc_attr__('Requires %s plan', 'wash'), $requiredPlan);
    } elseif ($isPermissionLocked) {
        $title = esc_attr__('Locked by Shopify Admin', 'wash');
    } else {
        $title = '';
    }

    printf(
        '<a href="#%s" class="%s" data-tab="%s" data-locked="%s" data-disabled="%s" data-required-plan="%s" data-upgrade-url="%s" title="%s">%s%s</a>',
        esc_attr($tab),
        esc_attr($classes),
        esc_attr($tab),
        $isLocked ? '1' : '0',
        $isDisconnected ? '1' : '0',
        esc_attr($requiredPlan),
        esc_url($upgradeUrl),
        esc_attr($title),
        esc_html($label),
        $lockIcon
    );
}

/**
 * Get tab content attributes for locked state (plan or permission).
 */
function wash_get_tab_content_class($tab, $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions = [], $tabPermissions = []) {
    $featureName = $tabFeatures[$tab] ?? null;
    $isPlanLocked = $featureName && wash_is_feature_locked($featureName, $features);
    $isPermissionLocked = wash_is_permission_locked($tab, $tabPermissions, $permissions);
    $requiredPlan = $featurePlans[$featureName] ?? 'PRO';
    $isLocked = $isPlanLocked || $isPermissionLocked;

    $class = 'wash-tab-content';
    if ($isPlanLocked) {
        $class .= ' wash-tab-content--locked';
    }
    if ($isPermissionLocked) {
        $class .= ' wash-tab-content--permission-locked';
    }

    return [
        'class' => $class,
        'isLocked' => $isLocked,
        'isPlanLocked' => $isPlanLocked,
        'isPermissionLocked' => $isPermissionLocked,
        'requiredPlan' => $requiredPlan,
        'upgradeUrl' => $upgradeUrl,
    ];
}

/**
 * Render a plan-locked banner.
 */
function wash_render_locked_banner($requiredPlan, $upgradeUrl) {
    ?>
    <div class="wash-locked-banner">
        <div class="wash-locked-banner__text">
            <span class="dashicons dashicons-lock"></span>
            <span><?php printf(esc_html__('This feature requires %s plan', 'wash'), esc_html($requiredPlan)); ?></span>
        </div>
        <div class="wash-locked-banner__actions">
            <a href="<?php echo esc_url($upgradeUrl); ?>" class="button" target="_blank"><?php esc_html_e('Upgrade Now', 'wash'); ?></a>
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
            <strong style="color: #856404;"><?php esc_html_e('Settings Locked by Shopify Admin', 'wash'); ?></strong>
            <p style="margin: 4px 0 0; color: #856404; font-size: 13px;">
                <?php esc_html_e('This section is controlled from the Shopify App. Changes made here will not be saved.', 'wash'); ?>
            </p>
        </div>
    </div>
    <?php
}
?>
<div class="wrap wash-settings">
    <?php if ($forceRefreshPlan && empty($connectionError)) : ?>
    <div class="notice notice-success is-dismissible" style="margin: 15px 0;">
        <p><?php esc_html_e('Plan information refreshed successfully.', 'wash'); ?></p>
    </div>
    <?php endif; ?>
    <?php if ($isConnected && !empty($connectionError)) : ?>
    <div class="notice notice-error" style="margin: 15px 0;">
        <p>
            <strong>Wash:</strong>
            <?php
            printf(
                /* translators: %s = error message */
                esc_html__('Cannot connect to Shopify App: %s', 'wash'),
                '<code>' . esc_html($connectionError) . '</code>'
            );
            ?>
        </p>
        <p style="margin-top: 4px; font-size: 12px; color: #646970;">
            <?php esc_html_e('Check that your API key is valid, the Shopify App is running, and your server\'s IP is not blocked by a firewall. If you use HTTP Basic Auth, verify the credentials in the Connection tab.', 'wash'); ?>
        </p>
    </div>
    <?php endif; ?>
    <h1>
        <img src="<?php echo esc_url(washPluginUrl() . 'admin/assets/logo.png'); ?>" alt="Wash" style="height: 28px; width: auto; margin-right: 8px; vertical-align: middle;" />
        <?php esc_html_e('Wash Settings', 'wash'); ?>
        <span style="font-size: 12px; font-weight: normal; color: #646970; margin-left: 8px; vertical-align: middle;">
            v<?php echo esc_html(WASH_VERSION); ?>
        </span>
        <?php if ($isConnected && $planName) : ?>
            <span class="wash-plan-badge" style="font-size: 12px; font-weight: 600; background: <?php echo $planName === 'FREE' ? '#f0f0f1' : '#d4edda'; ?>; color: <?php echo $planName === 'FREE' ? '#666' : '#155724'; ?>; padding: 4px 10px; border-radius: 12px; margin-left: 10px; vertical-align: middle;">
                <?php echo esc_html($planName); ?>
            </span>
        <?php endif; ?>
        <?php if ($isConnected) : ?>
            <a href="<?php echo esc_url(add_query_arg(['refresh_plan' => '1', 'refresh_updates' => '1', 'refresh_settings' => '1'])); ?>" title="<?php esc_attr_e('Sync all: settings, plan info, and check for updates', 'wash'); ?>" style="color: #646970; margin-left: 8px; vertical-align: middle; text-decoration: none;">
                <span class="dashicons dashicons-update" style="font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span>
            </a>
        <?php endif; ?>
        <?php if ($updateAvailable) : ?>
            <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" style="font-size: 12px; font-weight: 600; background: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 12px; margin-left: 8px; vertical-align: middle; text-decoration: none;">
                <?php printf(esc_html__('Update available: v%s', 'wash'), esc_html($latestVersion)); ?>
            </a>
        <?php endif; ?>
    </h1>

    <?php if ($isConnected && !is_wp_error($planInfo) && !empty($planInfo['usage'])) : ?>
    <div class="wash-usage-summary" style="background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; padding: 12px 15px; margin: 15px 0; display: flex; gap: 30px; font-size: 13px;">
        <div>
            <strong><?php esc_html_e('Articles:', 'wash'); ?></strong>
            <?php
            $articlesUsed = $planInfo['usage']['articlesUsed'] ?? 0;
            $articlesLimit = $planInfo['usage']['articlesLimit'] ?? -1;
            if ($articlesLimit === -1) {
                echo esc_html($articlesUsed) . ' ' . esc_html__('(unlimited)', 'wash');
            } else {
                echo esc_html($articlesUsed) . ' / ' . esc_html($articlesLimit);
            }
            ?>
        </div>
        <div>
            <strong><?php esc_html_e('Edits:', 'wash'); ?></strong>
            <?php
            $editsUsed = $planInfo['usage']['editsUsed'] ?? 0;
            $editsLimit = $planInfo['usage']['editsLimit'] ?? -1;
            if ($editsLimit === -1) {
                echo esc_html($editsUsed) . ' ' . esc_html__('(unlimited)', 'wash');
            } else {
                echo esc_html($editsUsed) . ' / ' . esc_html($editsLimit);
            }
            ?>
        </div>
        <?php if ($planName === 'FREE' && !empty($upgradeUrl)) : ?>
        <div style="margin-left: auto;">
            <a href="<?php echo esc_url($upgradeUrl); ?>" target="_blank" style="color: #0073aa; text-decoration: none;">
                <?php esc_html_e('Upgrade Plan', 'wash'); ?> →
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isConnected && !$userCanModifySettings) : ?>
    <div class="notice notice-warning" style="margin: 15px 0;">
        <p>
            <span class="dashicons dashicons-visibility" style="color: #856404; vertical-align: text-bottom; margin-right: 5px;"></span>
            <strong><?php esc_html_e('View Only Mode', 'wash'); ?></strong> —
            <?php esc_html_e('Your account does not have permission to modify Wash settings. Settings are displayed in read-only mode. Contact your administrator for access.', 'wash'); ?>
        </p>
    </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper wash-tabs">
        <?php wash_render_tab('connection', __('Connection', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, true, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('blogs', __('Blogs', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('redirections', __('Redirections', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('hreflangs', __('Hreflangs', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('metafields', __('Meta Fields', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('authors', __('Authors', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('files', __('Files & Media', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('content', __('Content Processing', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('searchreplace', __('Search & Replace', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('sync', __('Sync Settings', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
        <?php wash_render_tab('advanced', __('Advanced', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, false, $permissions, $tabPermissions, $isConnected); ?>
    </nav>

    <form method="post" action="options.php" id="wash-settings-form"<?php echo $userCanModifySettings ? '' : ' class="wash-readonly-mode"'; ?>>
        <?php settings_fields('wash_settings'); ?>
        <input type="hidden" name="_wp_http_referer_tab" id="wash-current-tab" value="connection" />
        <input type="hidden" id="wash-user-can-modify" value="<?php echo $userCanModifySettings ? '1' : '0'; ?>" />

        <!-- Connection Tab -->
        <div class="wash-tab-content" id="tab-connection">
            <h2 class="title"><?php esc_html_e('Shopify Store Connection', 'wash'); ?></h2>

            <?php if ($isConnected) : ?>
                <!-- Connected state - simple view -->
                <div class="wash-connected-box" style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 20px; margin: 15px 0;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="width: 12px; height: 12px; background: #28a745; border-radius: 50%; display: inline-block;"></span>
                            <strong style="color: #155724;"><?php esc_html_e('Connected', 'wash'); ?></strong>
                            <?php if ($shopifyStore) : ?>
                                <span style="color: #155724;">
                                    <?php esc_html_e('to', 'wash'); ?>
                                    <a href="https://<?php echo esc_attr($shopifyStore); ?>.myshopify.com/admin" target="_blank" rel="noopener noreferrer" style="color: #155724; font-weight: 600;">
                                        <?php echo esc_html($shopifyStore); ?>.myshopify.com
                                    </a>
                                </span>
                            <?php endif; ?>
                        </div>
                        <button type="button" id="wash-disconnect-btn" class="button" style="color: #dc3545; border-color: #dc3545;">
                            <span class="dashicons dashicons-dismiss" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Disconnect', 'wash'); ?>
                        </button>
                    </div>
                </div>
                <!-- Hidden fields for connected state -->
                <input type="hidden" name="wash_shopify_store" value="<?php echo esc_attr($shopifyStore); ?>" />
                <input type="hidden" name="wash_api_key" value="<?php echo esc_attr(get_option('wash_api_key')); ?>" />
            <?php else : ?>
                <!-- Not connected - show connection form -->
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wash_shopify_store"><?php esc_html_e('Shopify Store', 'wash'); ?></label>
                        </th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="text" id="wash_shopify_store" name="wash_shopify_store"
                                       value="<?php echo esc_attr($shopifyStore); ?>"
                                       class="regular-text" placeholder="your-store"
                                       style="max-width: 200px;" />
                                <span style="color: #666;">.myshopify.com</span>
                            </div>
                            <p class="description"><?php esc_html_e('Your Shopify store handle (e.g., "my-store" from my-store.myshopify.com)', 'wash'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" id="wash-connect-btn" class="button button-primary" style="display: none;">
                                <span class="dashicons dashicons-admin-links" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Connect to Shopify', 'wash'); ?>
                            </button>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </div>

        <!-- Blogs Tab -->
        <?php $blogsTabAttrs = wash_get_tab_content_class('blogs', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($blogsTabAttrs['class']); ?>" id="tab-blogs" style="display: none;" data-locked="<?php echo $blogsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($blogsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($blogsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($blogsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php elseif (!empty($blogsTabAttrs['isPlanLocked'])) : ?>
                <?php wash_render_locked_banner($blogsTabAttrs['requiredPlan'], $blogsTabAttrs['upgradeUrl']); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Blog Routing Rules', 'wash'); ?></h2>
            <p class="description">
                <?php esc_html_e('Route WordPress posts to different Shopify blogs based on language or category.', 'wash'); ?>
                <?php esc_html_e('Higher priority rules are matched first. Default rule is used when no other rule matches.', 'wash'); ?>
            </p>

            <?php if ($isConnected) : ?>
                <div id="wash-blog-mappings">
                    <div style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center;">
                        <button type="button" id="wash-add-mapping" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Add Mapping Rule', 'wash'); ?>
                        </button>
                        <button type="button" id="wash-export-mappings" class="button" title="<?php esc_attr_e('Export rules as JSON', 'wash'); ?>">
                            <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Export', 'wash'); ?>
                        </button>
                        <button type="button" id="wash-import-mappings" class="button" title="<?php esc_attr_e('Import rules from JSON', 'wash'); ?>">
                            <span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Import', 'wash'); ?>
                        </button>
                        <input type="file" id="wash-import-mappings-file" accept=".json" style="display:none;" />
                    </div>

                    <div class="wash-mappings-list" id="wash-mappings-list">
                        <!-- Mappings loaded via JavaScript -->
                        <p class="wash-loading"><?php esc_html_e('Loading mappings...', 'wash'); ?></p>
                    </div>

                    <!-- Add/Edit Mapping Form (hidden by default) -->
                    <div id="wash-mapping-form" style="display: none; margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3 id="wash-mapping-form-title"><?php esc_html_e('Add Mapping Rule', 'wash'); ?></h3>
                        <input type="hidden" id="wash-mapping-id" value="" />

                        <?php
                        $hreflangsHandler = new WashHreflangs();
                        $translationPlugin = $hreflangsHandler->detectActivePlugin();
                        $availableLanguages = $translationPlugin ? $hreflangsHandler->getAvailableLanguages() : [];
                        // Fetch categories across all languages (WPML per-language context returns 0)
                        $currentLang = null;
                        if ($translationPlugin === 'wpml' && function_exists('wpml_get_current_language')) {
                            $currentLang = wpml_get_current_language();
                            do_action('wpml_switch_language', 'all');
                        } elseif ($translationPlugin === 'polylang' && function_exists('pll_current_language')) {
                            $currentLang = pll_current_language();
                            PLL()->curlang = null;
                        }
                        $categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);
                        if ($currentLang !== null) {
                            if ($translationPlugin === 'wpml') {
                                do_action('wpml_switch_language', $currentLang);
                            } elseif ($translationPlugin === 'polylang') {
                                PLL()->curlang = PLL()->model->get_language($currentLang);
                            }
                        }
                        ?>
                        <input type="hidden" id="wash-rule-type" value="default" />

                        <!-- WordPress Source section -->
                        <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px;">
                            <h4 style="margin: 0 0 10px; display: flex; align-items: center; gap: 8px; color: #0073aa;">
                                <span class="dashicons dashicons-wordpress"></span>
                                <?php esc_html_e('WordPress Source', 'wash'); ?>
                                <span style="font-weight: normal; color: #6b7280; font-size: 12px;">— <?php esc_html_e('which posts match this rule', 'wash'); ?></span>
                            </h4>
                            <table class="form-table" role="presentation" style="margin-top: 0;">
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('WP Post Types', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <fieldset id="wash-wp-post-types-fieldset">
                                            <?php
                                            $allPostTypes = get_post_types(['public' => true], 'objects');
                                            foreach ($allPostTypes as $pt) :
                                                if ($pt->name === 'attachment') continue;
                                            ?>
                                                <label style="display: inline-block; margin-right: 14px; margin-bottom: 6px;">
                                                    <input type="checkbox" class="wash-pt-checkbox" value="<?php echo esc_attr($pt->name); ?>"
                                                        <?php echo in_array($pt->name, ['post'], true) ? 'checked' : ''; ?> />
                                                    <?php echo esc_html($pt->label); ?>
                                                    <code style="font-size: 11px; color: #666;">(<?php echo esc_html($pt->name); ?>)</code>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <input type="hidden" id="wash-wp-post-types" value="post" />
                                        <p class="description"><?php esc_html_e('Select which WordPress post types this rule applies to.', 'wash'); ?></p>
                                    </td>
                                </tr>
                                <tr id="wash-category-row">
                                    <th scope="row">
                                        <label for="wash-rule-value-cat"><?php esc_html_e('Category', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-rule-value-cat" class="regular-text">
                                            <option value=""><?php esc_html_e('All categories (catch-all)', 'wash'); ?></option>
                                            <?php
                                            // Deduplicate categories by slug — WPML/Polylang return one entry per language
                                            $seenSlugs = [];
                                            foreach ($categories as $cat) :
                                                if (isset($seenSlugs[$cat->slug])) continue;
                                                $seenSlugs[$cat->slug] = true;
                                            ?>
                                                <option value="<?php echo esc_attr($cat->slug); ?>">
                                                    <?php echo esc_html($cat->name . ' (' . $cat->slug . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('Filter by WordPress category slug. Combine with Language below for per-market routing (e.g. art + de-ch → Swiss German articles).', 'wash'); ?></p>
                                    </td>
                                </tr>
                                <?php if ($translationPlugin) : ?>
                                <tr>
                                    <th scope="row">
                                        <label for="wash-rule-value-lang"><?php esc_html_e('Language', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-rule-value-lang" class="regular-text">
                                            <option value=""><?php esc_html_e('All languages', 'wash'); ?></option>
                                            <?php foreach ($availableLanguages as $lang) : ?>
                                                <option value="<?php echo esc_attr($lang['code']); ?>">
                                                    <?php echo esc_html($lang['name'] . ' (' . $lang['code'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">
                                            <?php printf(esc_html__('Detected plugin: %s', 'wash'), '<strong>' . esc_html($translationPlugin) . '</strong>'); ?>
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
                                <?php esc_html_e('Shopify Destination', 'wash'); ?>
                                <span style="font-weight: normal; color: #6b7280; font-size: 12px;">— <?php esc_html_e('where matched posts are synced', 'wash'); ?></span>
                            </h4>
                            <table class="form-table" role="presentation" style="margin-top: 0;">
                                <tr>
                                    <th scope="row">
                                        <label for="wash-destination"><?php esc_html_e('Type', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-destination" class="regular-text">
                                            <option value="article"><?php esc_html_e('Blog Article (/blogs/...)', 'wash'); ?></option>
                                            <option value="page"><?php esc_html_e('Page (/pages/...)', 'wash'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr id="wash-blog-row">
                                    <th scope="row">
                                        <label for="wash-shopify-blog"><?php esc_html_e('Target Blog', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-shopify-blog" class="regular-text" style="margin-bottom: 10px;">
                                            <option value=""><?php esc_html_e('Select a blog...', 'wash'); ?></option>
                                            <option value="_new"><?php esc_html_e('➕ Create new blog', 'wash'); ?></option>
                                        </select>
                                        <div id="wash-new-blog-row" style="display: none; margin-bottom: 10px;">
                                            <input type="text" id="wash-new-blog-name" class="regular-text" placeholder="<?php esc_attr_e('New blog name (e.g., blog-en)', 'wash'); ?>" />
                                        </div>
                                    </td>
                                </tr>
                                <tr id="wash-domain-row">
                                    <th scope="row">
                                        <label for="wash-destination-domain"><?php esc_html_e('Destination Domain', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-destination-domain" class="regular-text">
                                            <option value=""><?php esc_html_e('Default (primary domain)', 'wash'); ?></option>
                                            <!-- Options loaded via JavaScript from /api/shop-domains -->
                                        </select>
                                        <p class="description"><?php esc_html_e('Custom domain for preview URLs (e.g., shopifydev.hilo.com)', 'wash'); ?></p>
                                    </td>
                                </tr>
                                <tr id="wash-market-locale-row">
                                    <th scope="row">
                                        <label for="wash-market-locale"><?php esc_html_e('Market & Locale', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <select id="wash-market-locale" class="regular-text">
                                            <option value=""><?php esc_html_e('Default (primary market)', 'wash'); ?></option>
                                            <!-- Options loaded via JavaScript from Shopify Markets API -->
                                        </select>
                                        <p class="description"><?php esc_html_e('Select market and language for URL routing (e.g., /en-ch for Switzerland English)', 'wash'); ?></p>
                                    </td>
                                </tr>
                                <tr style="display:none;">
                                    <th scope="row">
                                        <label for="wash-priority"><?php esc_html_e('Priority', 'wash'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="wash-priority" class="small-text" value="0" min="0" max="100" />
                                        <p class="description"><?php esc_html_e('Managed by drag-and-drop order', 'wash'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p class="submit">
                            <button type="button" id="wash-save-mapping" class="button button-primary">
                                <?php esc_html_e('Save Mapping', 'wash'); ?>
                            </button>
                            <button type="button" id="wash-cancel-mapping" class="button">
                                <?php esc_html_e('Cancel', 'wash'); ?>
                            </button>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- URL Structure Comparison -->
            <details id="wash-url-structure-guide" style="margin-top: 30px; border: 1px solid #c3c4c7; border-radius: 4px;">
                <summary style="padding: 12px 15px; background: #f6f7f7; cursor: pointer; font-weight: 600; border-radius: 4px;">
                    <span class="dashicons dashicons-info" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('URL Structure Guide', 'wash'); ?>
                </summary>
                <div style="padding: 15px;">
                    <p><?php esc_html_e('How your URLs transform from WordPress to Shopify:', 'wash'); ?></p>
                    <table class="widefat striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th style="width: 15%;"><?php esc_html_e('Type', 'wash'); ?></th>
                                <th style="width: 42.5%; background: #fef7f1;">
                                    <span class="dashicons dashicons-wordpress" style="color: #21759b; vertical-align: text-bottom;"></span>
                                    <?php esc_html_e('WordPress (Current)', 'wash'); ?>
                                </th>
                                <th style="width: 42.5%; background: #f0fff4;">
                                    <span class="dashicons dashicons-store" style="color: #96bf48; vertical-align: text-bottom;"></span>
                                    <?php esc_html_e('Shopify (After Sync)', 'wash'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Blog Posts', 'wash'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html($permalinkExample); ?></code><br>
                                    <span style="color: #666; font-size: 11px;">
                                        <?php esc_html_e('Current setting:', 'wash'); ?>
                                        <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" style="text-decoration: none;">
                                            <code style="font-size: 10px;"><?php echo esc_html($permalinkStructure ?: __('Plain', 'wash')); ?></code>
                                        </a>
                                    </span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($shopifyStore ?: '{store}'); ?>.myshopify.com/blogs/{blog}/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Blog handle configured above', 'wash'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Multi-language', 'wash'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html($multiLangPermalink); ?></code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Language prefix + permalink structure', 'wash'); ?></span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($shopifyStore ?: '{store}'); ?>.myshopify.com/blogs/{blog}-{lang}/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Option A: Language in blog handle', 'wash'); ?></span><br>
                                    <code style="margin-top: 4px; display: inline-block;">https://<?php echo esc_html($shopifyStore ?: '{store}'); ?>.myshopify.com/{lang}/blogs/{blog}/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Option B: Shopify Markets subfolders', 'wash'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Pages', 'wash'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html(home_url('/{slug}/')); ?></code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Page slug becomes URL', 'wash'); ?></span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($shopifyStore ?: '{store}'); ?>.myshopify.com/pages/{slug}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Prefixed with /pages/', 'wash'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Categories', 'wash'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html($categoryExample); ?></code><br>
                                    <span style="color: #666; font-size: 11px;">
                                        <?php esc_html_e('Category base:', 'wash'); ?>
                                        <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>" style="text-decoration: none;">
                                            <code style="font-size: 10px;">/<?php echo esc_html($categoryBase); ?>/</code>
                                        </a>
                                    </span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://<?php echo esc_html($shopifyStore ?: '{store}'); ?>.myshopify.com/blogs/{blog}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Categories become blogs', 'wash'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Images', 'wash'); ?></strong></td>
                                <td style="background: #fef7f1;">
                                    <code><?php echo esc_html(content_url('/uploads/{year}/{month}/{file}')); ?></code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Served from WordPress', 'wash'); ?></span>
                                </td>
                                <td style="background: #f0fff4;">
                                    <code>https://cdn.shopify.com/s/files/1/{id}/{file}</code><br>
                                    <span style="color: #666; font-size: 11px;"><?php esc_html_e('Shopify CDN delivery', 'wash'); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin-top: 10px; font-size: 12px; color: #666;">
                        <span class="dashicons dashicons-info-outline" style="font-size: 14px; vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Variables: {slug} = post/page slug, {blog} = Shopify blog handle, {lang} = language code (en, de, pl), {store} = Shopify store name', 'wash'); ?>
                    </p>
                </div>
            </details>
        </div>

        <!-- Redirections Tab -->
        <?php $redirectionsTabAttrs = wash_get_tab_content_class('redirections', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($redirectionsTabAttrs['class']); ?>" id="tab-redirections" style="display: none;" data-locked="<?php echo $redirectionsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($redirectionsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($redirectionsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($redirectionsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('URL Redirections Management', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Manage 301 redirects from WordPress URLs to Shopify URLs for SEO preservation during migration.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Smart Redirections', 'wash'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="wash_redirections_enabled" value="0" />
                                <input type="checkbox" name="wash_redirections_enabled" id="wash_redirections_enabled" value="1" <?php checked(get_option('wash_redirections_enabled', '0'), '1'); ?>>
                                <?php esc_html_e('Automatically create redirects when posts are synced to Shopify', 'wash'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, Wash will create 301 redirects from your WordPress URLs to the corresponding Shopify URLs automatically during sync.', 'wash'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit">
                <button type="button" id="wash-save-redirections-settings" class="button button-primary">
                    <?php esc_html_e('Save Settings', 'wash'); ?>
                </button>
                <span id="wash-redirections-save-status" style="margin-left: 10px;"></span>
            </p>

            <?php if ($isConnected) : ?>
            <!-- Redirections Management Section -->
            <div class="wash-redirections-manager" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; display: flex; justify-content: space-between; align-items: center;">
                    <span>
                        <span class="dashicons dashicons-randomize" style="color: #2271b1;"></span>
                        <?php esc_html_e('Redirections Manager', 'wash'); ?>
                    </span>
                    <a href="https://wash.tradik.com/docs/smart-redirections/" target="_blank" style="font-size: 12px; font-weight: normal; color: #2271b1; text-decoration: none;">
                        <span class="dashicons dashicons-book-alt" style="font-size: 14px; vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Documentation', 'wash'); ?>
                    </a>
                </h3>

                <!-- Stats Bar -->
                <div id="wash-redirect-stats" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px 15px; margin-bottom: 20px;">
                    <span class="dashicons dashicons-chart-bar" style="color: #2271b1; vertical-align: middle;"></span>
                    <span id="wash-stats-text"><?php esc_html_e('Loading redirect stats...', 'wash'); ?></span>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                    <button type="button" id="wash-fetch-wp-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Sync from Old Website', 'wash'); ?>
                    </button>
                    <button type="button" id="wash-generate-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Generate from Synced Posts', 'wash'); ?>
                    </button>
                    <button type="button" id="wash-load-shopify-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-cloud" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Load from Shopify', 'wash'); ?>
                    </button>
                    <button type="button" id="wash-fix-session" class="button" style="display: none; background: #d63638; border-color: #d63638; color: #fff;">
                        <span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Fix Session Scopes', 'wash'); ?>
                    </button>
                </div>

                <!-- Textarea for Redirections -->
                <div style="margin-bottom: 15px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                        <label for="wash-redirections-textarea" style="font-weight: 600;">
                            <?php esc_html_e('Redirections List', 'wash'); ?>
                            <span style="font-weight: normal; color: #666;">
                                (<?php esc_html_e('format: /old-path → /new-path', 'wash'); ?>)
                            </span>
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" id="wash-verify-redirections" class="button button-secondary" title="<?php esc_attr_e('Verify syntax', 'wash'); ?>">
                                <span class="dashicons dashicons-yes-alt" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Verify', 'wash'); ?>
                            </button>
                            <button type="button" id="wash-unlock-redirections" class="button" title="<?php esc_attr_e('Unlock to edit', 'wash'); ?>">
                                <span class="dashicons dashicons-lock" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Unlock', 'wash'); ?>
                            </button>
                        </div>
                    </div>
                    <div id="wash-redirections-container" style="position: relative;">
                        <textarea id="wash-redirections-textarea" rows="15" readonly style="width: 100%; font-family: monospace; font-size: 13px; background: #f6f7f7; cursor: not-allowed;" placeholder="<?php esc_attr_e('/blog/my-post → /blogs/news/my-post
/about-us → /pages/about-us
/category/news → /blogs/news

# Lines starting with # are comments', 'wash'); ?>"></textarea>
                        <div id="wash-redirections-overlay" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.02); cursor: pointer; display: flex; align-items: center; justify-content: center;">
                            <span style="background: #fff; padding: 8px 16px; border-radius: 4px; border: 1px solid #c3c4c7; color: #666; font-size: 13px;">
                                <span class="dashicons dashicons-lock" style="vertical-align: text-bottom; color: #d63638;"></span>
                                <?php esc_html_e('Click unlock button to edit', 'wash'); ?>
                            </span>
                        </div>
                    </div>
                    <div id="wash-verify-results" style="display: none; margin-top: 10px; padding: 12px; border-radius: 4px;"></div>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e('One redirection per line. Separators: →, ->, =>. Lines starting with # are comments.', 'wash'); ?>
                    </p>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button type="button" id="wash-sync-redirections" class="button button-primary button-large" disabled title="<?php esc_attr_e('Unlock to enable sync', 'wash'); ?>">
                        <span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Sync Redirections to Shopify', 'wash'); ?>
                    </button>
                    <span id="wash-sync-status" style="display: none;"></span>
                </div>

                <!-- Sync Results -->
                <div id="wash-sync-results" style="display: none; margin-top: 20px; padding: 15px; background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px;">
                    <h4 style="margin-top: 0; color: #155724;">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Sync Complete', 'wash'); ?>
                    </h4>
                    <ul id="wash-sync-results-list" style="margin: 0; padding-left: 20px;"></ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Hreflangs Tab -->
        <?php $hreflangsTabAttrs = wash_get_tab_content_class('hreflangs', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($hreflangsTabAttrs['class']); ?>" id="tab-hreflangs" style="display: none;" data-locked="<?php echo $hreflangsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($hreflangsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($hreflangsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($hreflangsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Hreflang Tags Configuration', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Configure hreflang tags for multi-language SEO on Shopify.', 'wash'); ?></p>

            <?php
            $hreflangsHandler = new WashHreflangs();
            $activePlugin = $hreflangsHandler->getActivePluginName();
            $hasPlugin = $hreflangsHandler->isMultilingualPluginActive();
            ?>

            <!-- Detected Plugin Status -->
            <div class="wash-info-section" style="background: <?php echo $hasPlugin ? '#f0fff4' : '#fef0f0'; ?>; border: 2px solid <?php echo $hasPlugin ? '#00a32a' : '#d63638'; ?>; border-radius: 8px; padding: 20px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span class="dashicons <?php echo $hasPlugin ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $hasPlugin ? '#00a32a' : '#d63638'; ?>;"></span>
                    <div>
                        <h3 style="margin: 0 0 5px 0;"><?php esc_html_e('Multilingual Plugin Status', 'wash'); ?></h3>
                        <p style="margin: 0; font-size: 15px;">
                            <?php if ($hasPlugin): ?>
                                <?php printf(
                                    esc_html__('Detected: %s', 'wash'),
                                    '<strong style="color: #00a32a;">' . esc_html($activePlugin) . '</strong>'
                                ); ?>
                                <br><span style="color: #666;"><?php esc_html_e('Translations will be automatically detected and synced.', 'wash'); ?></span>
                            <?php else: ?>
                                <strong style="color: #d63638;"><?php esc_html_e('No multilingual plugin detected', 'wash'); ?></strong>
                                <br><span style="color: #666;"><?php esc_html_e('Install WPML, Polylang, TranslatePress, or Weglot to enable hreflang syncing.', 'wash'); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Hreflangs', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_hreflangs_enabled" value="0" />
                            <input type="checkbox" name="wash_hreflangs_enabled" value="1"
                                <?php checked(get_option('wash_hreflangs_enabled'), '1'); ?>
                                <?php disabled(!$hasPlugin); ?> />
                            <?php esc_html_e('Sync hreflang data to Shopify metafields', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, language relationship data from your multilingual plugin will be synced as metafields.', 'wash'); ?></p>
                    </td>
                </tr>
            </table>

            <!-- How It Works -->
            <div class="wash-accordion wash-accordion--info">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-info"></span>
                        <?php esc_html_e('How Hreflang Syncing Works', 'wash'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <p><?php esc_html_e('When enabled, wash will sync the following data as Shopify article metafields:', 'wash'); ?></p>
                    <table class="widefat" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Metafield Key', 'wash'); ?></th>
                                <th><?php esc_html_e('Namespace', 'wash'); ?></th>
                                <th><?php esc_html_e('Example Value', 'wash'); ?></th>
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
                        <?php esc_html_e('Each translation gets its own metafield. When a translation is removed in WordPress, the corresponding metafield is automatically cleared in Shopify.', 'wash'); ?>
                    </p>
                </div>
            </div>

            <!-- Liquid Code Example -->
            <div class="wash-accordion wash-accordion--dark">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-editor-code"></span>
                        <?php esc_html_e('Liquid Code for theme.liquid', 'wash'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <p style="color: #999; font-size: 13px;"><?php esc_html_e('Add this code inside the &lt;head&gt; section of your theme (layout/theme.liquid):', 'wash'); ?></p>
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
                        <?php esc_html_e('This code automatically outputs all hreflang tags including x-default.', 'wash'); ?>
                    </p>
                </div>
            </div>

            <!-- Important Warning -->
            <div class="wash-accordion wash-accordion--warning">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Important: Shopify Theme Configuration', 'wash'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <p style="font-size: 14px; line-height: 1.6;">
                        <?php esc_html_e('Shopify does NOT natively output hreflang tags for blog articles. You must manually add the Liquid code above to your theme.', 'wash'); ?>
                    </p>
                    <ol style="margin-left: 20px; line-height: 2;">
                        <li>
                            <strong><?php esc_html_e('Edit Your Theme', 'wash'); ?></strong><br>
                            <span style="color: #666;"><?php esc_html_e('Go to Online Store → Themes → Edit code → layout/theme.liquid', 'wash'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Add the Liquid Code', 'wash'); ?></strong><br>
                            <span style="color: #666;"><?php esc_html_e('Paste the code above inside the &lt;head&gt; section, before &lt;/head&gt;', 'wash'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Save and Test', 'wash'); ?></strong><br>
                            <span style="color: #666;"><?php esc_html_e('View page source on an article to verify hreflang tags are present', 'wash'); ?></span>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- Alternative: Shopify Markets -->
            <div class="wash-accordion wash-accordion--tip">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-lightbulb"></span>
                        <?php esc_html_e('Alternative: Shopify Markets', 'wash'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px;">
                        <div>
                            <p><?php esc_html_e('For stores with Shopify Plus or compatible plans, consider using Shopify Markets:', 'wash'); ?></p>
                            <ul style="margin-left: 20px; line-height: 1.8;">
                                <li><?php esc_html_e('Built-in multi-language support', 'wash'); ?></li>
                                <li><?php esc_html_e('Automatic hreflang tag generation', 'wash'); ?></li>
                                <li><?php esc_html_e('No theme customization required', 'wash'); ?></li>
                            </ul>
                            <p style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                <strong><?php esc_html_e('Note:', 'wash'); ?></strong> <?php esc_html_e('Shopify Markets works independently of this plugin. Use this feature when Markets is not available or you need custom hreflang handling.', 'wash'); ?>
                            </p>
                        </div>
                        <a href="https://wash.tradik.com/docs/hreflangs.html" target="_blank" rel="noopener noreferrer" class="button" style="flex-shrink: 0;">
                            <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                            <?php esc_html_e('Documentation', 'wash'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Meta Fields Tab -->
        <?php $metafieldsTabAttrs = wash_get_tab_content_class('metafields', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($metafieldsTabAttrs['class']); ?>" id="tab-metafields" style="display: none;" data-locked="<?php echo $metafieldsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($metafieldsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($metafieldsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($metafieldsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Meta Fields Configuration', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('WordPress detects available meta fields from your posts. Toggle sync per field.', 'wash'); ?></p>

            <?php if (get_option('wash_sync_metafields', '1') !== '1') : ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; color: #856404;">
                    <?php esc_html_e('Metafield sync is disabled. Enable "Sync Metafields" in the Sync tab to use this configuration.', 'wash'); ?>
                </div>
            <?php endif; ?>

            <div id="wash-metafields-content" style="<?php echo get_option('wash_sync_metafields', '1') !== '1' ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">
            <?php
            $metafieldsHandler = new WashMetafields();
            $fieldConfig = $metafieldsHandler->getFieldConfig();
            $sourceBadges = [
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
                    <?php esc_html_e('Refresh Fields', 'wash'); ?>
                </button>
                <span id="wash-fields-status" style="font-size: 13px; color: #646970;"></span>
            </div>

            <?php if (empty($fieldConfig)) : ?>
                <div style="background: #f6f6f7; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px;">
                    <p style="margin: 0; color: #646970;"><?php esc_html_e('No fields detected. Click Refresh to scan your posts.', 'wash'); ?></p>
                </div>
            <?php else : ?>
                <table class="widefat striped" id="wash-meta-fields-table" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;"><?php esc_html_e('Sync', 'wash'); ?></th>
                            <th><?php esc_html_e('Field', 'wash'); ?></th>
                            <th><?php esc_html_e('Namespace', 'wash'); ?></th>
                            <th><?php esc_html_e('Source', 'wash'); ?></th>
                            <th><?php esc_html_e('Type', 'wash'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fieldConfig as $i => $field) :
                            $badge = $sourceBadges[$field['source']] ?? $sourceBadges['custom'];
                        ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="wash-field-toggle"
                                        data-index="<?php echo (int)$i; ?>"
                                        <?php checked(!empty($field['syncEnabled'])); ?>
                                        style="width: 16px; height: 16px;" />
                                </td>
                                <td><strong><?php echo esc_html($field['label'] ?? $field['key']); ?></strong></td>
                                <td><code style="font-size: 12px;"><?php echo esc_html($field['namespace']); ?></code></td>
                                <td>
                                    <span style="background: <?php echo esc_attr($badge['bg']); ?>; color: <?php echo esc_attr($badge['color']); ?>; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                        <?php echo esc_html($field['source']); ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px; color: #646970;"><?php echo esc_html($field['type']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <script>
            (function() {
                var fieldConfig = <?php echo wp_json_encode($fieldConfig); ?>;
                var saveTimer = null;
                var statusEl = document.getElementById('wash-fields-status');

                function saveFieldConfig() {
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(function() {
                        if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Saving...', 'wash')); ?>';
                        fetch(washAdmin.ajaxUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=wash_save_meta_field_config&nonce=' + washAdmin.nonce + '&config=' + encodeURIComponent(JSON.stringify(fieldConfig))
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!statusEl) return;
                            if (!data.success) {
                                statusEl.textContent = '<?php echo esc_js(__('Error saving', 'wash')); ?>';
                            } else if (data.data && data.data.synced) {
                                statusEl.textContent = '<?php echo esc_js(__('Saved & synced to Shopify', 'wash')); ?>';
                            } else {
                                statusEl.textContent = '<?php echo esc_js(__('Saved (Shopify sync pending)', 'wash')); ?>';
                            }
                        })
                        .catch(function() {
                            if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Error saving', 'wash')); ?>';
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
                    if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Scanning...', 'wash')); ?>';

                    fetch(washAdmin.ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wash_detect_meta_fields&nonce=' + washAdmin.nonce
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            statusEl.textContent = data.data.fields.length + ' <?php echo esc_js(__('fields detected', 'wash')); ?>';
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            statusEl.textContent = '<?php echo esc_js(__('Error', 'wash')); ?>';
                        }
                        btn.disabled = false;
                    })
                    .catch(function() {
                        if (statusEl) statusEl.textContent = '<?php echo esc_js(__('Error', 'wash')); ?>';
                        btn.disabled = false;
                    });
                });
            })();
            </script>

            <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                <a href="https://wash.tradik.com/docs/custom-fields.html" target="_blank" rel="noopener noreferrer" class="button" style="margin-right: 10px;">
                    <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                    <?php esc_html_e('Documentation', 'wash'); ?>
                </a>
            </div>
            </div><!-- /wash-metafields-content -->
        </div>

        <!-- Authors Tab -->
        <?php $authorsTabAttrs = wash_get_tab_content_class('authors', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($authorsTabAttrs['class']); ?>" id="tab-authors" style="display: none;" data-locked="<?php echo $authorsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($authorsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($authorsTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($authorsTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Author Mapping', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Map WordPress users to custom author names displayed on Shopify articles.', 'wash'); ?></p>

            <?php
            $authorsEnabled = get_option('wash_authors_enabled', '0') === '1';
            $authorMappings = get_option('wash_author_mappings', []);
            if (!is_array($authorMappings)) {
                $authorMappings = [];
            }
            $wpUsers = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Author Mapping', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_authors_enabled" value="0" />
                            <input type="checkbox" name="wash_authors_enabled" value="1" id="wash-authors-enabled"
                                <?php checked($authorsEnabled); ?> />
                            <?php esc_html_e('Override WordPress author names with custom values', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When disabled, articles use the original WordPress author display name.', 'wash'); ?></p>
                    </td>
                </tr>
            </table>

            <div id="wash-author-mappings-section" style="<?php echo $authorsEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                <h3 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #c3c4c7;">
                    <?php esc_html_e('User → Author Name Mapping', 'wash'); ?>
                </h3>

                <?php if (empty($wpUsers)) : ?>
                    <p style="color: #666;"><?php esc_html_e('No users found.', 'wash'); ?></p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width: 800px;">
                        <thead>
                            <tr>
                                <th style="width: 30%;"><?php esc_html_e('WordPress User', 'wash'); ?></th>
                                <th style="width: 35%;"><?php esc_html_e('Current Display Name', 'wash'); ?></th>
                                <th style="width: 35%;"><?php esc_html_e('Shopify Author Name', 'wash'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wpUsers as $user) : ?>
                                <?php
                                $userId = $user->ID;
                                $currentMapping = isset($authorMappings[$userId]) ? $authorMappings[$userId] : '';
                                ?>
                                <tr>
                                    <td>
                                        <code><?php echo esc_html($user->user_login); ?></code>
                                        <span style="color: #666; font-size: 12px;">(ID: <?php echo esc_html($userId); ?>)</span>
                                    </td>
                                    <td>
                                        <?php echo esc_html($user->display_name); ?>
                                    </td>
                                    <td>
                                        <input type="text"
                                               name="wash_author_mappings[<?php echo esc_attr($userId); ?>]"
                                               value="<?php echo esc_attr($currentMapping); ?>"
                                               class="regular-text"
                                               placeholder="<?php echo esc_attr($user->display_name); ?>"
                                               style="width: 100%;" />
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="description" style="margin-top: 15px;">
                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                        <?php esc_html_e('Leave empty to use the original WordPress display name. Changes apply to newly synced articles.', 'wash'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 25px 0; max-width: 800px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px;">
                    <div>
                        <h4 style="margin-top: 0;">
                            <span class="dashicons dashicons-lightbulb" style="color: #2271b1;"></span>
                            <?php esc_html_e('How Author Mapping Works', 'wash'); ?>
                        </h4>
                        <ul style="margin-left: 20px; line-height: 1.8; margin-bottom: 0;">
                            <li><strong><?php esc_html_e('Disabled:', 'wash'); ?></strong> <?php esc_html_e('Articles show the WordPress author\'s display name (e.g., "admin" or "John Doe")', 'wash'); ?></li>
                            <li><strong><?php esc_html_e('Enabled:', 'wash'); ?></strong> <?php esc_html_e('Articles show the custom name you specify in the mapping table', 'wash'); ?></li>
                            <li><strong><?php esc_html_e('Use cases:', 'wash'); ?></strong> <?php esc_html_e('Pen names, company branding, consistent author attribution across sites', 'wash'); ?></li>
                        </ul>
                    </div>
                    <a href="https://wash.tradik.com/docs/author-mapping.html" target="_blank" rel="noopener noreferrer" class="button" style="flex-shrink: 0;">
                        <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                        <?php esc_html_e('Documentation', 'wash'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Files & Media Tab -->
        <?php $filesTabAttrs = wash_get_tab_content_class('files', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($filesTabAttrs['class']); ?>" id="tab-files" style="display: none;">
            <?php if (!empty($filesTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Files & Media Configuration', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how images and media files are handled when syncing to Shopify.', 'wash'); ?></p>

            <?php
            $filesEnabled = get_option('wash_files_enabled', '1') === '1';
            $webpConvert = get_option('wash_webp_convert', '0') === '1';
            $allowedFileTypes = get_option('wash_allowed_file_types', ['images']);
            if (!is_array($allowedFileTypes)) {
                $allowedFileTypes = ['images'];
            }
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Upload Images', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_files_enabled" value="0" />
                            <input type="checkbox" name="wash_files_enabled" value="1" id="wash-files-enabled"
                                <?php checked($filesEnabled); ?> />
                            <?php esc_html_e('Enable image uploads to Shopify Files', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, media attachments will be uploaded to Shopify CDN. Disable to skip image syncing.', 'wash'); ?></p>
                    </td>
                </tr>
                <tr id="wash-webp-row" style="<?php echo $filesEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                    <th scope="row"><?php esc_html_e('Convert to WebP', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_webp_convert" value="0" />
                            <input type="checkbox" name="wash_webp_convert" value="1" id="wash-webp-convert"
                                <?php checked($webpConvert); ?> />
                            <?php esc_html_e('Convert images to modern WebP format', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('WebP provides better compression and faster load times. Original format preserved if conversion fails.', 'wash'); ?></p>
                    </td>
                </tr>
                <tr id="wash-file-types-row" style="<?php echo $filesEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                    <th scope="row"><?php esc_html_e('Allowed File Types', 'wash'); ?></th>
                    <td>
                        <fieldset>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="images"
                                    <?php checked(in_array('images', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Images (JPEG, PNG, GIF, WebP, SVG)', 'wash'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="pdf"
                                    <?php checked(in_array('pdf', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('PDF Documents', 'wash'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="video"
                                    <?php checked(in_array('video', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Videos (MP4, MOV, WebM)', 'wash'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 1 GB each', 'wash'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="audio"
                                    <?php checked(in_array('audio', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Audio (MP3, WAV, OGG)', 'wash'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wash_allowed_file_types[]" value="documents"
                                    <?php checked(in_array('documents', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Documents (DOC, XLS, PPT, TXT)', 'wash'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wash'); ?></span>
                            </label>
                        </fieldset>
                        <p class="description" style="margin-top: 10px;">
                            <?php esc_html_e('Select which file types should be synced to Shopify Files.', 'wash'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- FAQ Section -->
            <h3 style="margin-top: 30px; margin-bottom: 15px;">
                <span class="dashicons dashicons-book" style="color: #2271b1;"></span>
                <?php esc_html_e('FAQ', 'wash'); ?>
            </h3>

            <!-- FAQ: How Image Syncing Works -->
            <details class="wash-faq" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #2271b1; outline: none;">
                    <span class="dashicons dashicons-format-image" style="vertical-align: middle;"></span>
                    <?php esc_html_e('How does image syncing work?', 'wash'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #c5d9ed;">
                    <ul style="margin-left: 20px; line-height: 1.8;">
                        <li><?php esc_html_e('Images are uploaded to Shopify CDN for faster global delivery', 'wash'); ?></li>
                        <li><?php esc_html_e('Image URLs in post content are automatically rewritten to Shopify CDN URLs', 'wash'); ?></li>
                        <li><?php esc_html_e('Featured images are synced as article images in Shopify', 'wash'); ?></li>
                        <li><?php esc_html_e('Use the Media Library to manually sync individual images', 'wash'); ?></li>
                    </ul>
                </div>
            </details>

            <!-- FAQ: WebP Format Benefits -->
            <details class="wash-faq" style="background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #00a32a; outline: none;">
                    <span class="dashicons dashicons-performance" style="vertical-align: middle;"></span>
                    <?php esc_html_e('What are the benefits of WebP format?', 'wash'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #96bf48;">
                    <table class="widefat" style="max-width: 600px;">
                        <tbody>
                            <tr>
                                <td style="width: 30%;"><strong><?php esc_html_e('Compression', 'wash'); ?></strong></td>
                                <td><?php esc_html_e('25-35% smaller than JPEG at same quality', 'wash'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Browser Support', 'wash'); ?></strong></td>
                                <td><?php esc_html_e('All modern browsers (Chrome, Firefox, Safari, Edge)', 'wash'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Supported Inputs', 'wash'); ?></strong></td>
                                <td><?php esc_html_e('JPEG, PNG, GIF, TIFF', 'wash'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <!-- FAQ: Shopify Files Limits -->
            <details class="wash-faq" style="background: #f0f6fc; border: 1px solid #0969da; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #0969da; outline: none;">
                    <span class="dashicons dashicons-info-outline" style="vertical-align: middle;"></span>
                    <?php esc_html_e('What are Shopify Files limits?', 'wash'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #0969da;">
                    <p style="margin: 0 0 10px;">
                        <?php esc_html_e('File size and storage limits are enforced by Shopify and vary by plan. Files exceeding these limits will be skipped during sync.', 'wash'); ?>
                    </p>
                    <a href="https://help.shopify.com/en/manual/shopify-admin/productivity-tools/file-uploads" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="margin-top: 5px;">
                        <span class="dashicons dashicons-external" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('View Shopify Files Documentation', 'wash'); ?>
                    </a>
                </div>
            </details>

            <!-- Troubleshooting: Featured Image -->
            <details class="wash-troubleshooting" style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #826200; outline: none;">
                    <span class="dashicons dashicons-editor-help" style="color: #dba617; vertical-align: middle;"></span>
                    <?php esc_html_e('Featured image not showing on Shopify?', 'wash'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dba617;">
                    <p style="margin-top: 0;">
                        <?php esc_html_e('If the featured image is visible in Shopify Admin but not on the blog page, your Shopify theme template needs to be updated.', 'wash'); ?>
                    </p>

                    <h5 style="margin: 15px 0 10px;"><?php esc_html_e('Solution: Add this code to your Shopify theme', 'wash'); ?></h5>
                    <p><?php esc_html_e('In Shopify Admin, go to:', 'wash'); ?> <strong>Online Store → Themes → Edit code</strong></p>
                    <p><?php esc_html_e('Find your article template (e.g.', 'wash'); ?> <code>sections/main-article.liquid</code> <?php esc_html_e('or', 'wash'); ?> <code>templates/article.liquid</code><?php esc_html_e(') and add:', 'wash'); ?></p>

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
                            <?php esc_html_e('Pro tip:', 'wash'); ?>
                        </strong>
                        <span style="color: #555;">
                            <?php esc_html_e('You can also use our ready-made template from', 'wash'); ?>
                            <code>packages/shopify-theme-example/sections/wp-article-content.liquid</code>
                        </span>
                    </div>
                </div>
            </details>

            <!-- Troubleshooting: Content Images -->
            <details class="wash-troubleshooting" style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #826200; outline: none;">
                    <span class="dashicons dashicons-editor-help" style="color: #dba617; vertical-align: middle;"></span>
                    <?php esc_html_e('Images in post content not showing?', 'wash'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dba617;">
                    <p style="margin-top: 0;"><?php esc_html_e('If images within the article body are not displaying:', 'wash'); ?></p>

                    <ol style="margin-left: 20px; line-height: 1.8;">
                        <li>
                            <strong><?php esc_html_e('Check if images are synced:', 'wash'); ?></strong>
                            <?php esc_html_e('Go to Media Library and verify images have a green checkmark (synced) icon.', 'wash'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Sync images first:', 'wash'); ?></strong>
                            <?php esc_html_e('Click on an image and use "Sync to Shopify" button, then re-sync the post.', 'wash'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Check WordPress debug.log:', 'wash'); ?></strong>
                            <?php esc_html_e('Look for lines with [wash] to see sync status and any errors.', 'wash'); ?>
                        </li>
                    </ol>

                    <div style="display: flex; justify-content: space-between; align-items: center; background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px; margin-top: 15px;">
                        <div>
                            <strong style="color: #2271b1;">
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Note:', 'wash'); ?>
                            </strong>
                            <span style="color: #555;">
                                <?php esc_html_e('Images are automatically synced when publishing a post. The sync rewrites WordPress URLs to Shopify CDN URLs in the content.', 'wash'); ?>
                            </span>
                        </div>
                        <a href="https://wash.tradik.com/docs/images-and-files.html" target="_blank" rel="noopener noreferrer" class="button" style="flex-shrink: 0; margin-left: 15px;">
                            <span class="dashicons dashicons-book" style="vertical-align: text-bottom; margin-right: 4px;"></span>
                            <?php esc_html_e('Documentation', 'wash'); ?>
                        </a>
                    </div>
                </div>
            </details>
        </div>

        <!-- Sync Tab -->
        <?php $syncTabAttrs = wash_get_tab_content_class('sync', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($syncTabAttrs['class']); ?>" id="tab-sync" style="display: none;">
            <?php if (!empty($syncTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Sync Preferences', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how content is synchronized to Shopify.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Auto-sync on Publish', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_on_publish" value="0" />
                            <input type="checkbox" name="wash_sync_on_publish" value="1"
                                <?php checked(get_option('wash_sync_on_publish'), '1'); ?> />
                            <?php esc_html_e('Automatically sync posts when published or updated', 'wash'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Metafields', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_metafields" value="0" />
                            <input type="checkbox" name="wash_sync_metafields" value="1"
                                <?php checked(get_option('wash_sync_metafields'), '1'); ?> />
                            <?php esc_html_e('Sync core and plugin meta fields to Shopify metafields', 'wash'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Rewrite Internal URLs', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_rewrite_internal_urls" value="0" />
                            <input type="checkbox" name="wash_rewrite_internal_urls" value="1"
                                <?php checked(get_option('wash_rewrite_internal_urls', '1'), '1'); ?> />
                            <?php esc_html_e('Convert internal WordPress links to Shopify URL format', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Rewrites links like /blog/post-slug to /blogs/{blog-handle}/post-slug and /page-slug to /pages/page-slug during sync.', 'wash'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Post Types', 'wash'); ?></th>
                    <td>
                        <?php
                        $syncPostTypes = array_map('trim', explode(',', get_option('wash_post_types', 'post,page')));
                        $publicPostTypes = get_post_types(['public' => true], 'objects');
                        ?>
                        <fieldset>
                            <?php foreach ($publicPostTypes as $pt) :
                                if ($pt->name === 'attachment') continue;
                            ?>
                                <label style="display: inline-block; margin-right: 14px; margin-bottom: 6px;">
                                    <input type="checkbox" class="wash-sync-pt-checkbox" value="<?php echo esc_attr($pt->name); ?>"
                                        <?php checked(in_array($pt->name, $syncPostTypes, true)); ?> />
                                    <?php echo esc_html($pt->label); ?>
                                    <code style="font-size: 11px; color: #666;">(<?php echo esc_html($pt->name); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <input type="hidden" id="wash_post_types" name="wash_post_types"
                               value="<?php echo esc_attr(get_option('wash_post_types', 'post,page')); ?>" />
                        <p class="description"><?php esc_html_e('Select which post types to synchronize to Shopify.', 'wash'); ?></p>
                    </td>
                </tr>
            </table>

            <div style="margin-top: 20px;">
                <a href="https://wash.tradik.com/docs/page-sync.html" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                    <span class="dashicons dashicons-book" style="font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php esc_html_e('Documentation', 'wash'); ?>
                </a>
            </div>
        </div>

        <!-- Content Processing Tab -->
        <?php $contentTabAttrs = wash_get_tab_content_class('content', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($contentTabAttrs['class']); ?>" id="tab-content" style="display: none;">
            <?php if (!empty($contentTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Content Processing', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how content is processed during sync to Shopify.', 'wash'); ?></p>

            <?php $allowedHtmlTags = get_option('wash_allowed_html_tags', ''); ?>

            <h3 style="margin-bottom: 15px;">
                <span class="dashicons dashicons-editor-code" style="color: #2271b1;"></span>
                <?php esc_html_e('Custom HTML Tags Whitelist', 'wash'); ?>
            </h3>

            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('By default, WordPress strips unknown HTML tags for security. Add custom tag patterns to preserve third-party embed codes (Klaviyo forms, chat widgets, etc.).', 'wash'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Allowed HTML Tag Patterns', 'wash'); ?></th>
                    <td>
                        <input type="text" name="wash_allowed_html_tags" value="<?php echo esc_attr($allowedHtmlTags); ?>"
                            placeholder="klaviyo-*,chat-*,mddb-*" class="regular-text" style="max-width: 400px; font-family: monospace;" />
                        <p class="description"><?php esc_html_e('Comma-separated patterns. Use * for wildcard (e.g., klaviyo-* matches klaviyo-form, klaviyo-embed).', 'wash'); ?></p>
                    </td>
                </tr>
            </table>

            <div style="display: flex; align-items: flex-start; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; max-width: 600px; flex: 1;">
                    <strong><?php esc_html_e('Examples:', 'wash'); ?></strong><br />
                    <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">klaviyo-*</code> — <?php esc_html_e('Klaviyo forms and embeds', 'wash'); ?><br />
                    <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">chat-*</code> — <?php esc_html_e('Chat widgets (Tidio, Intercom, etc.)', 'wash'); ?><br />
                    <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">mddb-*</code> — <?php esc_html_e('MDDB custom elements', 'wash'); ?>
                </div>
                <a href="https://wash.tradik.com/docs/content-processing.html" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                    <span class="dashicons dashicons-book" style="font-size: 16px; width: 16px; height: 16px;"></span>
                    <?php esc_html_e('Documentation', 'wash'); ?>
                </a>
            </div>
        </div>

        <!-- Search & Replace Tab -->
        <?php $searchReplaceTabAttrs = wash_get_tab_content_class('searchreplace', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($searchReplaceTabAttrs['class']); ?>" id="tab-searchreplace" style="display: none;" data-locked="<?php echo $searchReplaceTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($searchReplaceTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($searchReplaceTabAttrs['upgradeUrl']); ?>">
            <?php if (!empty($searchReplaceTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php elseif (!empty($searchReplaceTabAttrs['isPlanLocked'])) : ?>
                <?php wash_render_locked_banner($searchReplaceTabAttrs['requiredPlan'], $searchReplaceTabAttrs['upgradeUrl']); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Search & Replace Rules', 'wash'); ?></h2>
            <p class="description">
                <?php esc_html_e('Define text replacements applied to post content before syncing to Shopify. Useful for converting shortcodes, rewriting links, or replacing any text patterns.', 'wash'); ?>
            </p>

            <div id="wash-search-replace-rules" style="margin-top: 20px;">
                <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span id="wash-sr-add-rule"></span>
                    <button type="button" id="wash-sr-save-rules" class="button button-primary">
                        <span class="dashicons dashicons-saved" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Save Rules', 'wash'); ?>
                    </button>
                    <button type="button" id="wash-sr-export-rules" class="button" title="<?php esc_attr_e('Export rules as JSON', 'wash'); ?>">
                        <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Export', 'wash'); ?>
                    </button>
                    <button type="button" id="wash-sr-import-rules" class="button" title="<?php esc_attr_e('Import rules from JSON', 'wash'); ?>">
                        <span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Import', 'wash'); ?>
                    </button>
                    <input type="file" id="wash-sr-import-file" accept=".json" style="display:none;" />
                    <span id="wash-sr-save-status" style="margin-left: 10px;"></span>
                </div>

                <table class="widefat wash-sr-table" id="wash-sr-table">
                    <thead>
                        <tr>
                            <th style="width: 3%;" title="<?php esc_attr_e('Drag to reorder', 'wash'); ?>">⇅</th>
                            <th style="width: 3%;">#</th>
                            <th style="width: 7%;"><?php esc_html_e('Type', 'wash'); ?></th>
                            <th style="width: 34%;"><?php esc_html_e('Search (find this)', 'wash'); ?></th>
                            <th style="width: 3%; text-align: center;">→</th>
                            <th style="width: 34%;"><?php esc_html_e('Replace (with this)', 'wash'); ?></th>
                            <th style="width: 7%; text-align: center;"><?php esc_html_e('Actions', 'wash'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wash-sr-tbody">
                        <!-- Rules loaded via JavaScript -->
                        <tr id="wash-sr-loading">
                            <td colspan="7" style="text-align: center; padding: 20px; color: #666;">
                                <?php esc_html_e('Loading rules...', 'wash'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; align-items: flex-start; gap: 20px; margin-top: 30px; flex-wrap: wrap;">
                <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; max-width: 600px; flex: 1;">
                    <strong><?php esc_html_e('Rule Types:', 'wash'); ?></strong>
                    <div style="margin-top: 8px;">
                        <span style="background:#6b7280;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">Aa Text</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Exact string replacement', 'wash'); ?></span>
                    </div>
                    <div style="margin-top: 6px;">
                        <span style="background:#2563eb;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">🔗 URL</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Replace in href/src attributes only', 'wash'); ?></span>
                    </div>
                    <div style="margin-top: 6px;">
                        <span style="background:#7c3aed;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">[ ] Shortcode</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Match [tag] or [tag]...[/tag], auto-registers unregistered shortcodes', 'wash'); ?></span>
                    </div>
                    <div style="margin-top: 6px;">
                        <span style="background:#ea580c;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">▦ Block</span>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Match Gutenberg blocks (wp:blockname)', 'wash'); ?></span>
                    </div>
                    <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #c5d9ed;">
                        <strong><?php esc_html_e('Variable:', 'wash'); ?></strong>
                        <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px; font-size: 12px;">%content%</code>
                        <span style="color: #666; font-size: 12px;"> — <?php esc_html_e('Captures inner content (HTML stripped) from shortcodes/blocks', 'wash'); ?></span>
                    </div>
                </div>
                <div style="background: #fff8e6; border: 1px solid #ffc96b; border-radius: 4px; padding: 15px; max-width: 400px; flex: 1;">
                    <strong style="color: #856404;">
                        <span class="dashicons dashicons-warning" style="font-size: 16px; vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Important:', 'wash'); ?>
                    </strong>
                    <ul style="margin: 8px 0 0; padding-left: 18px; color: #856404; font-size: 13px;">
                        <li><?php esc_html_e('Rules are applied in order, top to bottom.', 'wash'); ?></li>
                        <li><?php esc_html_e('Search is case-sensitive and uses exact text matching.', 'wash'); ?></li>
                        <li><?php esc_html_e('Replacements are applied to the final HTML content before sending to Shopify.', 'wash'); ?></li>
                        <li><?php esc_html_e('Test your rules carefully — incorrect replacements can break content.', 'wash'); ?></li>
                    </ul>
                </div>
            </div>

            <!-- Reference lists: scanned WP content + Shopify links -->
            <div style="display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <details id="wash-sr-wp-refs" style="border: 1px solid #e2e8f0; border-radius: 4px; padding: 12px; background: #fafafa;">
                        <summary style="cursor: pointer; font-weight: 600; font-size: 13px;">
                            <?php esc_html_e('WordPress — detected content', 'wash'); ?>
                            <span id="wash-sr-wp-count" style="color: #999; font-weight: normal;"></span>
                        </summary>
                        <div id="wash-sr-wp-list" style="margin-top: 10px; max-height: 300px; overflow-y: auto; font-size: 12px;">
                            <span style="color: #999;"><?php esc_html_e('Loading...', 'wash'); ?></span>
                        </div>
                    </details>
                </div>
                <div style="flex: 1; min-width: 300px;">
                    <details id="wash-sr-shopify-refs" style="border: 1px solid #e2e8f0; border-radius: 4px; padding: 12px; background: #fafafa;">
                        <summary style="cursor: pointer; font-weight: 600; font-size: 13px;">
                            <?php esc_html_e('Shopify — available links', 'wash'); ?>
                            <span id="wash-sr-shopify-count" style="color: #999; font-weight: normal;"></span>
                        </summary>
                        <div id="wash-sr-shopify-list" style="margin-top: 10px; max-height: 300px; overflow-y: auto; font-size: 12px;">
                            <span style="color: #999;"><?php esc_html_e('Loading...', 'wash'); ?></span>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <!-- Advanced Tab -->
        <?php $advancedTabAttrs = wash_get_tab_content_class('advanced', $features, $tabFeatures, $featurePlans, $upgradeUrl, $permissions, $tabPermissions); ?>
        <div class="<?php echo esc_attr($advancedTabAttrs['class']); ?>" id="tab-advanced" style="display: none;">
            <?php if (!empty($advancedTabAttrs['isPermissionLocked'])) : ?>
                <?php wash_render_permission_locked_banner(); ?>
            <?php endif; ?>
            <h2 class="title"><?php esc_html_e('Advanced Settings', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Additional configuration options.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Block Search Engines', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_block_robots" value="0" />
                            <input type="checkbox" name="wash_block_robots" value="1"
                                <?php checked(get_option('wash_block_robots', '1'), '1'); ?> />
                            <?php esc_html_e('Discourage search engines from indexing this site (robots.txt)', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Recommended for demo/staging sites. Outputs "Disallow: /" in robots.txt.', 'wash'); ?></p>
                        <p class="description" style="margin-top: 8px;">
                            <span class="dashicons dashicons-info-outline" style="color: #2271b1; font-size: 14px; vertical-align: text-bottom;"></span>
                            <?php esc_html_e('For full protection:', 'wash'); ?>
                            <a href="<?php echo esc_url(admin_url('options-reading.php')); ?>"><?php esc_html_e('Settings → Reading', 'wash'); ?></a>
                            <?php esc_html_e('→ "Discourage search engines" + password protect the site.', 'wash'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 24px 0;" />

            <h3><?php esc_html_e('Gutenberg Blocks', 'wash'); ?></h3>
            <p class="description"><?php esc_html_e('Configure how Gutenberg blocks are processed and synced to Shopify.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Custom Blocks', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_blocks_enabled" value="0" />
                            <input type="checkbox" name="wash_blocks_enabled" value="1"
                                <?php checked(get_option('wash_blocks_enabled', '1'), '1'); ?> />
                            <?php esc_html_e('Enable Wash custom blocks in Gutenberg editor', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Adds custom Wash blocks (Shopify Product, Shopify Collection, Sync Status) to the Gutenberg editor.', 'wash'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Include Block CSS', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_blocks_css" value="0" />
                            <input type="checkbox" name="wash_sync_blocks_css" value="1"
                                <?php checked(get_option('wash_sync_blocks_css', '0'), '1'); ?> />
                            <?php esc_html_e('Include WordPress block styles in synced content', 'wash'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Includes WordPress block CSS styles in synced content for consistent visual appearance on Shopify.', 'wash'); ?>
                            <a href="https://wash.tradik.com/docs/wordpress-blocks/#shopify-theme-css" target="_blank" rel="noopener"><?php esc_html_e('Learn how to configure your Shopify theme', 'wash'); ?> &rarr;</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Block Structure', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="wash_sync_blocks_meta" value="0" />
                            <input type="checkbox" name="wash_sync_blocks_meta" value="1"
                                <?php checked(get_option('wash_sync_blocks_meta', '1'), '1'); ?> />
                            <?php esc_html_e('Save block structure as Shopify metafields', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Saves Gutenberg block structure as JSON metafield, allowing Shopify themes to render blocks dynamically.', 'wash'); ?></p>
                    </td>
                </tr>
            </table>

            <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 24px 0;" />

            <h3><?php esc_html_e('Sync Settings', 'wash'); ?></h3>
            <p class="description"><?php esc_html_e('Synchronize settings between WordPress and Shopify App.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Sync from Shopify', 'wash'); ?></th>
                    <td>
                        <button type="button" id="wash-sync-from-shopify" class="button button-secondary">
                            <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Sync from Shopify', 'wash'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Pull settings from Shopify App and apply them here. Use this if you changed settings in Shopify Admin.', 'wash'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr style="border: none; border-top: 1px solid #ccd0d4; margin: 24px 0;" />

            <h3><?php esc_html_e('Reset Settings', 'wash'); ?></h3>
            <p class="description"><?php esc_html_e('Reset all Wash settings to their default values. This will not affect your Shopify connection or synced content.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Reset to Defaults', 'wash'); ?></th>
                    <td>
                        <button type="button" id="wash-reset-to-defaults" class="button button-secondary">
                            <span class="dashicons dashicons-image-rotate" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Reset All Settings', 'wash'); ?>
                        </button>
                        <p class="description" style="color: #d63638;">
                            <?php esc_html_e('Warning: This will reset all sync settings, metafield options, file settings, and advanced options to their default values.', 'wash'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div id="wash-submit-wrapper">
            <?php submit_button(); ?>
        </div>
    </form>
</div>

<script>
(function() {
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
                '<span><?php echo esc_js(__('This feature requires', 'wash')); ?> <strong>' + requiredPlan + '</strong> <?php echo esc_js(__('plan', 'wash')); ?></span>' +
                '</div>' +
                '<div class="wash-locked-banner__actions">' +
                (upgradeUrl ? '<a href="' + upgradeUrl + '" target="_blank" class="button button-primary"><?php echo esc_js(__('Upgrade Now', 'wash')); ?></a>' : '') +
                '</div>';

            // Insert banner as first child
            panel.insertBefore(banner, panel.firstChild);
        }
    });

    // Submit button visibility based on tab and connection state
    var submitWrapper = document.getElementById('wash-submit-wrapper');
    var isConnected = <?php echo $isConnected ? 'true' : 'false'; ?>;

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

    // Show/hide manual config
    // Connect button visibility
    var storeInput = document.getElementById('wash_shopify_store');
    var connectBtn = document.getElementById('wash-connect-btn');

    function updateConnectButton() {
        var store = storeInput.value.trim();
        if (store && connectBtn) {
            connectBtn.style.display = 'inline-flex';
        } else if (connectBtn) {
            connectBtn.style.display = 'none';
        }
    }

    if (storeInput) {
        storeInput.addEventListener('input', updateConnectButton);
        updateConnectButton();
    }

    // Connect button click
    if (connectBtn) {
        connectBtn.addEventListener('click', function() {
            var store = storeInput.value.trim().replace('.myshopify.com', '').toLowerCase();

            // Validate store handle format
            if (!/^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(store) && !/^[a-z0-9]$/.test(store)) {
                alert('<?php echo esc_js(__('Invalid store name. Use only lowercase letters, numbers, and hyphens.', 'wash')); ?>');
                return;
            }

            // Show loading state
            connectBtn.disabled = true;
            connectBtn.innerHTML = '<span class="dashicons dashicons-update" style="animation: rotation 1s infinite linear; vertical-align: text-bottom;"></span> <?php echo esc_js(__('Checking...', 'wash')); ?>';

            // Verify store exists by checking if it responds
            fetch('https://' + store + '.myshopify.com/admin', { mode: 'no-cors', cache: 'no-store' })
                .finally(function() {
                    // Redirect regardless - Shopify will show proper error if store doesn't exist
                    var returnUrl = encodeURIComponent(window.location.href.split('?')[0] + '?page=wash&wash_callback=1');
                    var siteUrl = encodeURIComponent(window.location.protocol + '//' + window.location.host);
                    var siteName = encodeURIComponent('<?php echo esc_js(get_bloginfo('name')); ?>');

                    var appSlug = '<?php echo esc_js($shopifyAppSlug); ?>';
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
    if (mappingsList && <?php echo $isConnected ? 'true' : 'false'; ?>) {
        loadMappings();
        loadShopifyBlogs();
        loadShopifyMarkets();
        loadShopDomains();
    }

    function loadMappings() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wash_get_mappings&nonce=<?php echo wp_create_nonce('wash_mappings'); ?>')
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
                mappingsList.innerHTML = '<p class="notice notice-error"><?php echo esc_js(__('Failed to load mappings', 'wash')); ?></p>';
            });
    }

    function loadShopifyBlogs() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wash_get_shopify_blogs&nonce=<?php echo wp_create_nonce('wash_mappings'); ?>')
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
        shopifyBlogSelect.innerHTML = '<option value=""><?php echo esc_js(__('Select a blog...', 'wash')); ?></option>';
        shopifyBlogs.forEach(function(blog) {
            var opt = document.createElement('option');
            opt.value = blog.id;
            opt.textContent = blog.title + ' (' + blog.handle + ')';
            shopifyBlogSelect.appendChild(opt);
        });
        var newOpt = document.createElement('option');
        newOpt.value = '_new';
        newOpt.textContent = '➕ <?php echo esc_js(__('Create new blog', 'wash')); ?>';
        shopifyBlogSelect.appendChild(newOpt);
        shopifyBlogSelect.value = currentVal;
    }

    function loadShopifyMarkets() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wash_get_shopify_markets&nonce=<?php echo wp_create_nonce('wash_mappings'); ?>')
            .then(function(r) { return r.json(); })
            .then(function(response) {
                if (response.success) {
                    shopifyMarkets = response.data.markets || [];
                    updateMarketLocaleSelect();
                    renderMappings();
                }
            });
    }

    var shopDomains = [];
    var domainSelect = document.getElementById('wash-destination-domain');

    function loadShopDomains() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wash_get_shop_domains&nonce=<?php echo wp_create_nonce('wash_mappings'); ?>')
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
        domainSelect.innerHTML = '<option value=""><?php echo esc_js(__('Default (primary domain)', 'wash')); ?></option>';
        shopDomains.forEach(function(domain) {
            var opt = document.createElement('option');
            opt.value = domain.host;
            opt.textContent = domain.host + (domain.primary ? ' (primary)' : '') + (domain.marketName ? ' — ' + domain.marketName : '');
            domainSelect.appendChild(opt);
        });
        domainSelect.value = currentVal;
    }

    function updateMarketLocaleSelect() {
        if (!marketLocaleSelect) return;
        var currentVal = marketLocaleSelect.value;
        marketLocaleSelect.innerHTML = '<option value=""><?php echo esc_js(__('Default (primary market)', 'wash')); ?></option>';

        shopifyMarkets.forEach(function(market) {
            var optgroup = document.createElement('optgroup');
            optgroup.label = market.name + (market.primary ? ' (primary)' : '');
            var hasOptions = false;

            if (market.webPresence && market.webPresence.defaultLocale) {
                // Market with web presence — show all configured locales
                var defaultLocale = market.webPresence.defaultLocale;
                var opt = document.createElement('option');
                opt.value = market.id + '|' + defaultLocale;
                opt.textContent = market.name + ' / ' + defaultLocale + ' (default)';
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
                // Market without web presence — derive locale from first region code
                var regionCode = (market.regions && market.regions[0]) ? market.regions[0].code : null;
                var locale = regionCode ? regionCode.toLowerCase() : 'en';
                var opt = document.createElement('option');
                opt.value = market.id + '|' + locale;
                opt.textContent = market.name + ' / ' + locale;
                optgroup.appendChild(opt);
                hasOptions = true;
            }

            if (hasOptions) {
                marketLocaleSelect.appendChild(optgroup);
            }
        });

        marketLocaleSelect.value = currentVal;
    }

    var wpPermalink = '<?php echo esc_js($permalinkExample); ?>';
    var wpBase = '<?php echo esc_js(home_url('/')); ?>';
    var wpCategoryBase = '<?php echo esc_js($categoryBase); ?>';
    var shopifyDomain = 'https://<?php echo esc_js($shopifyStore ?: '{store}'); ?>.myshopify.com';

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
            '<code style="color:#155724;font-size:11px;">' + shopifyUrl + '</code>';
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

    function renderMappings() {
        if (!mappingsList) return;
        if (mappingsData.length === 0) {
            mappingsList.innerHTML = '<p style="color: #666;"><?php echo esc_js(__('No mapping rules configured. Posts will sync to the default Shopify blog.', 'wash')); ?></p>';
            return;
        }

        var html = '<table class="widefat striped" id="wash-mappings-table"><thead><tr>' +
            '<th style="width:30px;" title="<?php echo esc_js(__('Drag to reorder priority', 'wash')); ?>">⇅</th>' +
            '<th><?php echo esc_js(__('Rule', 'wash')); ?></th>' +
            '<th><?php echo esc_js(__('Target', 'wash')); ?></th>' +
            '<th><?php echo esc_js(__('Actions', 'wash')); ?></th>' +
            '</tr></thead><tbody id="wash-mappings-tbody">';

        var overlapGroups = detectOverlapGroups(mappingsData);

        mappingsData.forEach(function(m) {
            var ruleLabel, badgeClass;
            if (m.ruleType === 'default') {
                ruleLabel = '<?php echo esc_js(__('Default', 'wash')); ?>';
                badgeClass = 'background:#28a745;';
            } else if (m.ruleType === 'language') {
                ruleLabel = '<?php echo esc_js(__('Language', 'wash')); ?>: ' + (m.ruleValue || '');
                badgeClass = 'background:#007bff;';
            } else if (m.ruleType === 'language_category') {
                var parts = (m.ruleValue || '').split(':');
                ruleLabel = '<?php echo esc_js(__('Language', 'wash')); ?>: ' + (parts[0] || '') + ' + <?php echo esc_js(__('Category', 'wash')); ?>: ' + (parts[1] || '');
                badgeClass = 'background:#6f42c1;';
            } else {
                ruleLabel = '<?php echo esc_js(__('Category', 'wash')); ?>: ' + (m.ruleValue || '');
                badgeClass = 'background:#fd7e14;';
            }
            var destBadge = m.destination === 'page'
                ? '<span style="background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;margin-right:4px;">PAGE</span>'
                : '<span style="background:#e0e7ff;color:#3730a3;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;margin-right:4px;">ARTICLE</span>';
            var typesTag = '<span style="background:#f3f4f6;color:#6b7280;padding:1px 5px;border-radius:3px;font-size:11px;margin-left:4px;">' + (m.wpPostTypes || 'post') + '</span>';
            var urlExample = getUrlExample(m);
            var targetLabel = m.destination === 'page' ? '/pages/' : (getBlogHandle(m.shopifyBlogId) || m.shopifyBlogHandle || m.shopifyBlogId);

            var overlap = overlapGroups[m.id];
            var rowBg = overlap ? 'background:' + overlap.color + ';' : '';
            var warnIcon = overlap ? '<span title="<?php echo esc_js(__('Overlapping rule — higher priority wins', 'wash')); ?>" style="color:#b45309;font-size:14px;margin-right:4px;cursor:help;">&#9888;</span>' : '';

            html += '<tr class="wash-mapping-row" data-id="' + m.id + '" data-priority="' + m.priority + '" style="' + rowBg + '">' +
                '<td class="wash-mapping-drag" style="cursor:grab;text-align:center;color:#999;font-size:16px;user-select:none;vertical-align:middle;">☰</td>' +
                '<td>' +
                    warnIcon +
                    destBadge +
                    '<span style="' + badgeClass + 'color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">' + ruleLabel + '</span>' +
                    typesTag +
                    '<div style="margin-top:6px;padding:4px 6px;background:#f8f9fa;border-radius:3px;border:1px solid #e9ecef;">' + urlExample + '</div>' +
                '</td>' +
                '<td>' + targetLabel + '</td>' +
                '<td>' +
                '<button type="button" class="button button-small wash-edit-mapping" data-id="' + m.id + '"><?php echo esc_js(__('Edit', 'wash')); ?></button> ' +
                '<button type="button" class="button button-small wash-duplicate-mapping" data-id="' + m.id + '"><?php echo esc_js(__('Duplicate', 'wash')); ?></button> ' +
                '<button type="button" class="button button-small wash-delete-mapping" data-id="' + m.id + '" style="color:#dc3545;"><?php echo esc_js(__('Delete', 'wash')); ?></button>' +
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
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'wash_reorder_mappings',
                            nonce: '<?php echo wp_create_nonce('wash_mappings'); ?>',
                            updates: JSON.stringify(updates),
                        },
                        success: function(res) {
                            if (res.success) {
                                washMappingsToast('<?php echo esc_js(__('Order saved', 'wash')); ?>');
                            } else {
                                washMappingsToast('<?php echo esc_js(__('Failed to save order', 'wash')); ?>', 'error');
                            }
                        },
                        error: function() {
                            washMappingsToast('<?php echo esc_js(__('Failed to save order', 'wash')); ?>', 'error');
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

    /** Ensure a value exists as an option in a select, adding it if missing. */
    function ensureSelectOption(select, value) {
        if (!select || !value) return;
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].value === value) return;
        }
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = value + ' (Shopify market)';
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
                alert('<?php echo esc_js(__('No mapping rules to export.', 'wash')); ?>');
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
                    alert('<?php echo esc_js(__('Invalid JSON file.', 'wash')); ?>');
                    return;
                }
                if (!Array.isArray(imported) || imported.length === 0) {
                    alert('<?php echo esc_js(__('No mapping rules found in file.', 'wash')); ?>');
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
                var confirmMsg = '<?php echo esc_js(__('This will delete all existing mapping rules and import', 'wash')); ?> ' + imported.length + ' <?php echo esc_js(__('rules.', 'wash')); ?>';
                if (fixed > 0) {
                    confirmMsg += '\n\n' + fixed + ' <?php echo esc_js(__('rules had missing ruleValue — auto-recovered from names.', 'wash')); ?>';
                }
                confirmMsg += '\n\n<?php echo esc_js(__('Continue?', 'wash')); ?>';
                if (!confirm(confirmMsg)) {
                    return;
                }
                importBtn.disabled = true;
                importBtn.textContent = '<?php echo esc_js(__('Importing...', 'wash')); ?>';

                // Step 1: delete all existing mappings
                var deletePromises = mappingsData.map(function(m) {
                    var fd = new FormData();
                    fd.append('action', 'wash_delete_mapping');
                    fd.append('nonce', '<?php echo wp_create_nonce('wash_mappings'); ?>');
                    fd.append('mappingId', m.id);
                    return fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
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
                            fd.append('nonce', '<?php echo wp_create_nonce('wash_mappings'); ?>');
                            fd.append('destination', rule.destination || 'article');
                            fd.append('wpPostTypes', rule.wpPostTypes || 'post');
                            fd.append('ruleType', rule.ruleType || 'default');
                            fd.append('ruleValue', rule.ruleValue || '');
                            fd.append('shopifyBlogId', rule.shopifyBlogId || '');
                            fd.append('newBlogName', '');
                            fd.append('priority', rule.priority !== undefined ? rule.priority : (imported.length - idx) * 10);
                            fd.append('marketId', rule.shopifyMarketId || '');
                            fd.append('shopifyLocale', rule.shopifyLocale || '');
                            return fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd })
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
                        importBtn.innerHTML = '<span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span> <?php echo esc_js(__('Import', 'wash')); ?>';
                        loadMappings();
                        if (window.washClearDirty) washClearDirty('blogs');
                        var msg = '<?php echo esc_js(__('Import complete:', 'wash')); ?> ' + created + ' <?php echo esc_js(__('created', 'wash')); ?>';
                        if (failed > 0) {
                            msg += ', ' + failed + ' <?php echo esc_js(__('failed', 'wash')); ?>:\n' + errors.join('\n');
                        }
                        alert(msg);
                    });
                }).catch(function() {
                    importBtn.disabled = false;
                    importBtn.innerHTML = '<span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span> <?php echo esc_js(__('Import', 'wash')); ?>';
                    alert('<?php echo esc_js(__('Import failed.', 'wash')); ?>');
                });
            };
            reader.readAsText(file);
        });
    }

    if (addMappingBtn) {
        addMappingBtn.addEventListener('click', function() {
            document.getElementById('wash-mapping-id').value = '';
            document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Add Mapping Rule', 'wash')); ?>';
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
                nonce: '<?php echo wp_create_nonce('wash_mappings'); ?>',
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
            saveMappingBtn.textContent = '<?php echo esc_js(__('Saving...', 'wash')); ?>';

            var formData = new FormData();
            Object.keys(data).forEach(function(key) { formData.append(key, data[key]); });

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(response) {
                saveMappingBtn.disabled = false;
                saveMappingBtn.textContent = '<?php echo esc_js(__('Save Mapping', 'wash')); ?>';
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
                saveMappingBtn.textContent = '<?php echo esc_js(__('Save Mapping', 'wash')); ?>';
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
        document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Edit Mapping Rule', 'wash')); ?>';
        if (destinationSelect) destinationSelect.value = mapping.destination || 'article';
        setPostTypeCheckboxes(mapping.wpPostTypes || 'post');
        updateDestinationFields();
        setRuleSelectors(mapping.ruleType, mapping.ruleValue);
        shopifyBlogSelect.value = mapping.shopifyBlogId || '';
        document.getElementById('wash-priority').value = mapping.priority;
        newBlogRow.style.display = 'none';
        // Set market/locale — use exact marketId|locale if available, fallback to locale match
        if (marketLocaleSelect && mapping.shopifyLocale) {
            var exactMatch = mapping.shopifyMarketId
                ? mapping.shopifyMarketId + '|' + mapping.shopifyLocale
                : null;
            if (exactMatch) {
                marketLocaleSelect.value = exactMatch;
            }
            if (!exactMatch || !marketLocaleSelect.value) {
                // Fallback: find first option matching locale
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
    }

    // --- Duplicate mapping support ---
    var duplicateOriginal = null;

    function duplicateMapping(id) {
        var mapping = mappingsData.find(function(m) { return m.id === id; });
        if (!mapping) return;

        // Open form as NEW (empty ID) but pre-filled with source data
        document.getElementById('wash-mapping-id').value = '';
        document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Duplicate Mapping Rule', 'wash')); ?>';
        if (destinationSelect) destinationSelect.value = mapping.destination || 'article';
        setPostTypeCheckboxes(mapping.wpPostTypes || 'post');
        updateDestinationFields();
        setRuleSelectors(mapping.ruleType, mapping.ruleValue);
        shopifyBlogSelect.value = mapping.shopifyBlogId || '';
        document.getElementById('wash-priority').value = '0';
        newBlogRow.style.display = 'none';
        if (marketLocaleSelect && mapping.shopifyLocale && mapping.shopifyMarketId) {
            marketLocaleSelect.value = mapping.shopifyMarketId + '|' + mapping.shopifyLocale;
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
            saveMappingBtn.title = '<?php echo esc_js(__('Change at least one field before saving', 'wash')); ?>';
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
        if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this mapping?', 'wash')); ?>')) return;

        var formData = new FormData();
        formData.append('action', 'wash_delete_mapping');
        formData.append('nonce', '<?php echo wp_create_nonce('wash_mappings'); ?>');
        formData.append('mappingId', id);

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
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
</script>
