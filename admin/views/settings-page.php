<?php
$isConnected = !empty(get_option('wash_api_key')) && !empty(get_option('wash_api_secret'));
$shopifyStore = get_option('wash_shopify_store', '');

// Fetch plan info for feature gating
$planInfo = null;
$features = [];
$upgradeUrl = '';
$planName = 'FREE';

if ($isConnected) {
    $apiClient = new WashApiClient();
    $planInfo = $apiClient->getPlanInfo();
    if (!is_wp_error($planInfo) && !empty($planInfo['features'])) {
        $features = $planInfo['features'];
        $upgradeUrl = $planInfo['upgradeUrl'] ?? '';
        $planName = $planInfo['plan'] ?? 'FREE';
    }
}

// Check for plugin update
$updateAvailable = false;
$latestVersion = WASH_VERSION;
if ($isConnected) {
    $updater = new WashUpdater();
    $versionInfo = $updater->getVersionInfo();
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
];

/**
 * Check if a tab/feature is locked.
 */
function wash_is_feature_locked($featureName, $features) {
    if (empty($features)) {
        return false; // No plan info, don't lock
    }
    return empty($features[$featureName]);
}

/**
 * Render a tab link with optional locked state.
 */
function wash_render_tab($tab, $label, $features, $tabFeatures, $featurePlans, $upgradeUrl, $isActive = false) {
    $featureName = $tabFeatures[$tab] ?? null;
    $isLocked = $featureName && wash_is_feature_locked($featureName, $features);
    $requiredPlan = $featurePlans[$featureName] ?? 'PRO';

    $classes = 'nav-tab';
    if ($isActive) {
        $classes .= ' nav-tab-active';
    }
    if ($isLocked) {
        $classes .= ' nav-tab-locked';
    }

    $lockIcon = $isLocked ? ' <span class="dashicons dashicons-lock" style="font-size: 14px; opacity: 0.6;"></span>' : '';
    $title = $isLocked ? sprintf(esc_attr__('Requires %s plan', 'wash'), $requiredPlan) : '';

    printf(
        '<a href="#%s" class="%s" data-tab="%s" data-locked="%s" data-required-plan="%s" data-upgrade-url="%s" title="%s">%s%s</a>',
        esc_attr($tab),
        esc_attr($classes),
        esc_attr($tab),
        $isLocked ? '1' : '0',
        esc_attr($requiredPlan),
        esc_url($upgradeUrl),
        esc_attr($title),
        esc_html($label),
        $lockIcon
    );
}

/**
 * Get tab content attributes for locked state.
 */
function wash_get_tab_content_class($tab, $features, $tabFeatures, $featurePlans, $upgradeUrl) {
    $featureName = $tabFeatures[$tab] ?? null;
    $isLocked = $featureName && wash_is_feature_locked($featureName, $features);
    $requiredPlan = $featurePlans[$featureName] ?? 'PRO';

    $class = 'wash-tab-content';
    if ($isLocked) {
        $class .= ' wash-tab-content--locked';
    }

    return [
        'class' => $class,
        'isLocked' => $isLocked,
        'requiredPlan' => $requiredPlan,
        'upgradeUrl' => $upgradeUrl,
    ];
}
?>
<div class="wrap wash-settings">
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

    <nav class="nav-tab-wrapper wash-tabs">
        <?php wash_render_tab('connection', __('Connection', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl, true); ?>
        <?php wash_render_tab('blogs', __('Blogs', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wash_render_tab('redirections', __('Redirections', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wash_render_tab('hreflangs', __('Hreflangs', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wash_render_tab('metafields', __('Meta Fields', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wash_render_tab('authors', __('Authors', 'wash'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <a href="#files" class="nav-tab" data-tab="files"><?php esc_html_e('Files & Media', 'wash'); ?></a>
        <a href="#sync" class="nav-tab" data-tab="sync"><?php esc_html_e('Sync Settings', 'wash'); ?></a>
        <a href="#advanced" class="nav-tab" data-tab="advanced"><?php esc_html_e('Advanced', 'wash'); ?></a>
    </nav>

    <div class="wash-connection-status" id="wash-connection-status" style="display:none;">
        <div class="notice" id="wash-notice">
            <p id="wash-notice-text"></p>
        </div>
    </div>

    <form method="post" action="options.php" id="wash-settings-form">
        <?php settings_fields('wash_settings'); ?>
        <input type="hidden" name="_wp_http_referer_tab" id="wash-current-tab" value="connection" />

        <!-- Connection Tab -->
        <div class="wash-tab-content" id="tab-connection">
            <h2 class="title"><?php esc_html_e('Shopify Store Connection', 'wash'); ?></h2>

            <?php if ($isConnected) : ?>
                <div class="wash-connected-box" style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 15px; margin: 15px 0;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="width: 12px; height: 12px; background: #28a745; border-radius: 50%; display: inline-block;"></span>
                        <strong style="color: #155724;"><?php esc_html_e('Connected', 'wash'); ?></strong>
                        <?php if ($shopifyStore) : ?>
                            <span style="color: #155724;">to <code><?php echo esc_html($shopifyStore); ?>.myshopify.com</code></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

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
                        <?php if ($isConnected) : ?>
                            <button type="button" id="wash-disconnect-btn" class="button" style="color: #dc3545;">
                                <span class="dashicons dashicons-dismiss" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Disconnect', 'wash'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php if (!$isConnected) : ?>
            <div id="wash-manual-config" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ccc;">
                <h3><?php esc_html_e('Manual Configuration', 'wash'); ?></h3>
                <p class="description"><?php esc_html_e('If automatic connection fails, you can enter credentials manually.', 'wash'); ?></p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wash_api_key"><?php esc_html_e('API Key', 'wash'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wash_api_key" name="wash_api_key"
                                   value="<?php echo esc_attr(get_option('wash_api_key')); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wash_api_secret"><?php esc_html_e('API Secret', 'wash'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="wash_api_secret" name="wash_api_secret"
                                   value="<?php echo esc_attr(get_option('wash_api_secret')); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                </table>

                <p>
                    <a href="#" id="wash-hide-manual"><?php esc_html_e('Hide manual configuration', 'wash'); ?></a>
                </p>
            </div>

            <p style="margin-top: 15px;">
                <a href="#" id="wash-show-manual"><?php esc_html_e('Enter credentials manually', 'wash'); ?></a>
            </p>
            <?php else : ?>
            <!-- Hidden fields for connected state -->
            <input type="hidden" name="wash_api_key" value="<?php echo esc_attr(get_option('wash_api_key')); ?>" />
            <input type="hidden" name="wash_api_secret" value="<?php echo esc_attr(get_option('wash_api_secret')); ?>" />
            <?php endif; ?>

            <?php if (isset($_GET['reset-connection'])) : ?>
            <p style="margin-top: 20px;">
                <button type="button" id="wash-reset-session" class="button button-secondary" style="color: #d63638;">
                    <span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('Reset Connection', 'wash'); ?>
                </button>
            </p>
            <?php endif; ?>
        </div>

        <!-- Blogs Tab -->
        <?php $blogsTabAttrs = wash_get_tab_content_class('blogs', $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <div class="<?php echo esc_attr($blogsTabAttrs['class']); ?>" id="tab-blogs" style="display: none;" data-locked="<?php echo $blogsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($blogsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($blogsTabAttrs['upgradeUrl']); ?>">
            <h2 class="title"><?php esc_html_e('Blog Routing Rules', 'wash'); ?></h2>
            <p class="description">
                <?php esc_html_e('Route WordPress posts to different Shopify blogs based on language or category.', 'wash'); ?>
                <?php esc_html_e('Higher priority rules are matched first. Default rule is used when no other rule matches.', 'wash'); ?>
            </p>

            <?php if (!$isConnected) : ?>
                <div class="notice notice-warning" style="margin: 15px 0;">
                    <p><?php esc_html_e('Connect to Shopify first to configure blog mappings.', 'wash'); ?></p>
                </div>
            <?php else : ?>
                <div id="wash-blog-mappings">
                    <div class="wash-mappings-list" id="wash-mappings-list">
                        <!-- Mappings loaded via JavaScript -->
                        <p class="wash-loading"><?php esc_html_e('Loading mappings...', 'wash'); ?></p>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="button" id="wash-add-mapping" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Add Mapping Rule', 'wash'); ?>
                        </button>
                    </div>

                    <!-- Add/Edit Mapping Form (hidden by default) -->
                    <div id="wash-mapping-form" style="display: none; margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3 id="wash-mapping-form-title"><?php esc_html_e('Add Mapping Rule', 'wash'); ?></h3>
                        <input type="hidden" id="wash-mapping-id" value="" />

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="wash-rule-type"><?php esc_html_e('Rule Type', 'wash'); ?></label>
                                </th>
                                <td>
                                    <select id="wash-rule-type" class="regular-text">
                                        <option value="default"><?php esc_html_e('Default (catch-all)', 'wash'); ?></option>
                                        <option value="language"><?php esc_html_e('By Language', 'wash'); ?></option>
                                        <option value="category"><?php esc_html_e('By Category', 'wash'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="wash-rule-value-row">
                                <th scope="row">
                                    <label for="wash-rule-value"><?php esc_html_e('Value', 'wash'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="wash-rule-value" class="regular-text" placeholder="en, de, pl..." />
                                    <p class="description" id="wash-rule-value-help">
                                        <?php esc_html_e('Language code (en, de, pl) or category slug', 'wash'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="wash-shopify-blog"><?php esc_html_e('Target Shopify Blog', 'wash'); ?></label>
                                </th>
                                <td>
                                    <select id="wash-shopify-blog" class="regular-text">
                                        <option value=""><?php esc_html_e('Select a blog...', 'wash'); ?></option>
                                        <option value="_new"><?php esc_html_e('➕ Create new blog', 'wash'); ?></option>
                                    </select>
                                    <div id="wash-new-blog-row" style="display: none; margin-top: 10px;">
                                        <input type="text" id="wash-new-blog-name" class="regular-text" placeholder="<?php esc_attr_e('New blog name (e.g., blog-en)', 'wash'); ?>" />
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="wash-priority"><?php esc_html_e('Priority', 'wash'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="wash-priority" class="small-text" value="0" min="0" max="100" />
                                    <p class="description"><?php esc_html_e('Higher priority rules are matched first (0-100)', 'wash'); ?></p>
                                </td>
                            </tr>
                        </table>

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
        </div>

        <!-- Redirections Tab -->
        <?php $redirectionsTabAttrs = wash_get_tab_content_class('redirections', $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <div class="<?php echo esc_attr($redirectionsTabAttrs['class']); ?>" id="tab-redirections" style="display: none;" data-locked="<?php echo $redirectionsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($redirectionsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($redirectionsTabAttrs['upgradeUrl']); ?>">
            <h2 class="title"><?php esc_html_e('URL Redirections Management', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Manage 301 redirects from WordPress URLs to Shopify URLs for SEO preservation during migration.', 'wash'); ?></p>

            <?php if ($isConnected) : ?>
            <!-- Redirections Management Section -->
            <div class="wash-redirections-manager" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-randomize" style="color: #2271b1;"></span>
                    <?php esc_html_e('Redirections Manager', 'wash'); ?>
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
                    <label for="wash-redirections-textarea" style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <?php esc_html_e('Redirections List', 'wash'); ?>
                        <span style="font-weight: normal; color: #666;">
                            (<?php esc_html_e('format: /old-path -> /new-path', 'wash'); ?>)
                        </span>
                    </label>
                    <textarea id="wash-redirections-textarea" rows="15" style="width: 100%; font-family: monospace; font-size: 13px;" placeholder="<?php esc_attr_e('/blog/my-post -> /blogs/news/my-post
/about-us -> /pages/about-us
/category/news -> /blogs/news

# Lines starting with # are comments', 'wash'); ?>"></textarea>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e('Enter one redirection per line. You can use ->, =>, or → as separator. Lines starting with # are ignored.', 'wash'); ?>
                    </p>
                </div>

                <!-- Sync Button -->
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button type="button" id="wash-sync-redirections" class="button button-primary button-large">
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
            <?php else : ?>
            <div class="notice notice-warning" style="margin: 15px 0;">
                <p><?php esc_html_e('Connect to Shopify first to manage redirections.', 'wash'); ?></p>
            </div>
            <?php endif; ?>

            <!-- Informational Guide -->
            <details style="margin-top: 30px; border: 1px solid #c3c4c7; border-radius: 4px;">
                <summary style="padding: 12px 15px; background: #f6f7f7; cursor: pointer; font-weight: 600; border-radius: 4px;">
                    <span class="dashicons dashicons-info" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('URL Structure Guide', 'wash'); ?>
                </summary>
                <div style="padding: 15px;">

            <!-- Current WordPress URL Structure -->
            <div class="wash-info-section" style="background: #fef7f1; border: 1px solid #f0c674; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #8b5a00;">
                    <span class="dashicons dashicons-wordpress" style="color: #21759b;"></span>
                    <?php esc_html_e('WordPress URL Structure (Current)', 'wash'); ?>
                </h3>
                <p><?php esc_html_e('Your WordPress site currently serves content with these URL patterns:', 'wash'); ?></p>
                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td style="width: 25%;"><strong><?php esc_html_e('Blog Posts', 'wash'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/blog/my-post-title/</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Permalink structure depends on Settings → Permalinks', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Pages', 'wash'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/about-us/</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Page slug becomes the URL', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Categories', 'wash'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/category/news/</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Category archives with pagination', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Images', 'wash'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/wp-content/uploads/2024/01/image.jpg</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Served from your WordPress server', 'wash'); ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Shopify URL Structure -->
            <div class="wash-info-section" style="background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #5c6ac4;">
                    <span class="dashicons dashicons-store" style="color: #96bf48;"></span>
                    <?php esc_html_e('Shopify URL Structure (After Sync)', 'wash'); ?>
                </h3>
                <p><?php esc_html_e('After syncing, your content will be available on Shopify with these URL patterns:', 'wash'); ?></p>
                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td style="width: 25%;"><strong><?php esc_html_e('Blog Posts', 'wash'); ?></strong></td>
                            <td>
                                <code>https://<?php echo esc_html($shopifyStore ?: 'your-store'); ?>.myshopify.com/blogs/<strong>news</strong>/my-post-title</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Blog handle is required in Shopify URLs. Configure in Blogs tab.', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Multi-language Posts', 'wash'); ?></strong></td>
                            <td>
                                <code>/blogs/<strong>blog-en</strong>/my-post</code> → <?php esc_html_e('English', 'wash'); ?><br>
                                <code>/blogs/<strong>blog-de</strong>/mein-beitrag</code> → <?php esc_html_e('German', 'wash'); ?><br>
                                <code>/blogs/<strong>blog-pl</strong>/moj-wpis</code> → <?php esc_html_e('Polish', 'wash'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Each language gets its own blog with routing rules.', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Pages', 'wash'); ?></strong></td>
                            <td>
                                <code>https://<?php echo esc_html($shopifyStore ?: 'your-store'); ?>.myshopify.com/pages/about-us</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Pages are prefixed with /pages/ in Shopify.', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Images', 'wash'); ?></strong></td>
                            <td>
                                <code>https://cdn.shopify.com/s/files/1/xxxx/image.jpg</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Images are uploaded to Shopify CDN for faster global delivery.', 'wash'); ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Image Handling -->
            <div class="wash-info-section" style="background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-format-image" style="color: #2271b1;"></span>
                    <?php esc_html_e('Image Handling', 'wash'); ?>
                </h3>
                <table class="widefat" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Image Type', 'wash'); ?></th>
                            <th><?php esc_html_e('What Happens', 'wash'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Featured Image', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Uploaded to Shopify and set as article image. Displayed in blog listings and article headers.', 'wash'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Content Images', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Images in post content (img tags) are uploaded to Shopify CDN. URLs in HTML are automatically rewritten.', 'wash'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('External Images', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Images from external URLs (not WordPress) are kept as-is. Make sure external sources are reliable.', 'wash'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Image Sizes', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Original full-size images are uploaded. Shopify automatically generates responsive sizes.', 'wash'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- What Needs Manual Attention -->
            <div class="wash-info-section" style="background: #fff8e5; border: 1px solid #f0c674; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                    <?php esc_html_e('What Needs Manual Attention', 'wash'); ?>
                </h3>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong><?php esc_html_e('Internal Links:', 'wash'); ?></strong> <?php esc_html_e('Links to other WordPress posts/pages in your content are NOT automatically rewritten. Update them manually or use search-replace.', 'wash'); ?></li>
                    <li><strong><?php esc_html_e('301 Redirects:', 'wash'); ?></strong> <?php esc_html_e('Set up redirects from old WordPress URLs to new Shopify URLs to preserve SEO. Use Shopify Admin → Settings → Navigation → URL Redirects.', 'wash'); ?></li>
                    <li><strong><?php esc_html_e('Menu Links:', 'wash'); ?></strong> <?php esc_html_e('Shopify navigation menus need to be configured separately. WordPress menus are not synced.', 'wash'); ?></li>
                    <li><strong><?php esc_html_e('Canonical URLs:', 'wash'); ?></strong> <?php esc_html_e('If keeping WordPress active, set canonical URLs to Shopify to avoid duplicate content penalties.', 'wash'); ?></li>
                </ul>
            </div>

            <!-- Shopify Publications -->
            <div class="wash-info-section" style="background: #f5f0ff; border: 1px solid #7c3aed; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-megaphone" style="color: #7c3aed;"></span>
                    <?php esc_html_e('Shopify Publications (Sales Channels)', 'wash'); ?>
                </h3>
                <p><?php esc_html_e('Shopify uses "Publications" to control where your content is visible. By default, synced articles are published to your Online Store.', 'wash'); ?></p>
                <table class="widefat" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Publication', 'wash'); ?></th>
                            <th><?php esc_html_e('Description', 'wash'); ?></th>
                            <th><?php esc_html_e('Status', 'wash'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Online Store', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Your main Shopify storefront (yourstore.myshopify.com or custom domain)', 'wash'); ?></td>
                            <td><span style="color: #00a32a;">✓ <?php esc_html_e('Published', 'wash'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Point of Sale', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('In-person sales via Shopify POS app', 'wash'); ?></td>
                            <td><span style="color: #666;">— <?php esc_html_e('Not applicable', 'wash'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Buy Button', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Embeddable buy buttons for external websites', 'wash'); ?></td>
                            <td><span style="color: #666;">— <?php esc_html_e('Not applicable', 'wash'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Headless / Hydrogen', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Custom storefronts using Storefront API', 'wash'); ?></td>
                            <td><span style="color: #00a32a;">✓ <?php esc_html_e('Available via API', 'wash'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Markets (International)', 'wash'); ?></strong></td>
                            <td><?php esc_html_e('Region-specific content for international markets', 'wash'); ?></td>
                            <td><span style="color: #2271b1;">⚙ <?php esc_html_e('Configure in Shopify', 'wash'); ?></span></td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top: 15px; color: #666; font-size: 13px;">
                    <span class="dashicons dashicons-info" style="font-size: 16px;"></span>
                    <?php esc_html_e('To change publication settings for synced articles, go to Shopify Admin → Online Store → Blog posts → Edit article → Visibility.', 'wash'); ?>
                </p>
            </div>

            <!-- Shopify Redirect Limits -->
            <div class="wash-info-section" style="background: #fef0f0; border: 1px solid #d63638; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-info-outline" style="color: #d63638;"></span>
                    <?php esc_html_e('Shopify Redirect Limits', 'wash'); ?>
                </h3>
                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td style="width: 30%;"><strong><?php esc_html_e('Maximum Redirects', 'wash'); ?></strong></td>
                            <td>
                                <?php esc_html_e('100,000 URL redirects per store (all plans)', 'wash'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('If you need more, consider using a third-party redirect app.', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Bulk Import', 'wash'); ?></strong></td>
                            <td>
                                <?php esc_html_e('You can import redirects via CSV file in Shopify Admin → Settings → Navigation → URL Redirects → Import.', 'wash'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Format: two columns - "Redirect from" and "Redirect to" (relative paths).', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Path Only', 'wash'); ?></strong></td>
                            <td>
                                <?php esc_html_e('Redirects work with paths only (e.g., /old-page → /new-page). Domain is not included.', 'wash'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Query strings (? parameters) are ignored in redirect matching.', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('No Wildcards', 'wash'); ?></strong></td>
                            <td>
                                <?php esc_html_e('Shopify does not support wildcard redirects (e.g., /blog/* → /blogs/news/*).', 'wash'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Each URL must be redirected individually. Apps like "Bulk Redirect" can help.', 'wash'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Processing Time', 'wash'); ?></strong></td>
                            <td>
                                <?php esc_html_e('Large CSV imports may take several minutes to process. Redirects become active immediately after import.', 'wash'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- SEO Checklist -->
            <div class="wash-info-section" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                    <?php esc_html_e('SEO Migration Checklist', 'wash'); ?>
                </h3>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li>☐ <?php esc_html_e('Export list of all WordPress URLs before migration', 'wash'); ?></li>
                    <li>☐ <?php esc_html_e('Create 301 redirects in Shopify for each old URL', 'wash'); ?></li>
                    <li>☐ <?php esc_html_e('Update Google Search Console with new sitemap', 'wash'); ?></li>
                    <li>☐ <?php esc_html_e('Check meta titles and descriptions synced correctly', 'wash'); ?></li>
                    <li>☐ <?php esc_html_e('Verify images load from Shopify CDN', 'wash'); ?></li>
                    <li>☐ <?php esc_html_e('Test internal links and update broken ones', 'wash'); ?></li>
                    <li>☐ <?php esc_html_e('Set up robots.txt to block WordPress from search engines (if keeping it)', 'wash'); ?></li>
                </ul>
            </div>

                </div>
            </details>
        </div>

        <!-- Hreflangs Tab -->
        <?php $hreflangsTabAttrs = wash_get_tab_content_class('hreflangs', $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <div class="<?php echo esc_attr($hreflangsTabAttrs['class']); ?>" id="tab-hreflangs" style="display: none;" data-locked="<?php echo $hreflangsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($hreflangsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($hreflangsTabAttrs['upgradeUrl']); ?>">
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
                            <input type="checkbox" name="wash_hreflangs_enabled" value="1"
                                <?php checked(get_option('wash_hreflangs_enabled'), '1'); ?>
                                <?php disabled(!$hasPlugin); ?> />
                            <?php esc_html_e('Sync hreflang data to Shopify metafields', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, language relationship data from your multilingual plugin will be synced as metafields.', 'wash'); ?></p>
                    </td>
                </tr>
            </table>

            <!-- Supported Plugins -->
            <div class="wash-accordion wash-accordion--info">
                <div class="wash-accordion__header" onclick="this.parentElement.classList.toggle('wash-accordion--open')">
                    <h3 class="wash-accordion__title">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e('Supported Multilingual Plugins', 'wash'); ?>
                    </h3>
                    <span class="dashicons dashicons-arrow-down-alt2 wash-accordion__toggle"></span>
                </div>
                <div class="wash-accordion__content">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>WPML</strong>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;"><?php esc_html_e('Full support with automatic translation detection', 'wash'); ?></p>
                        </div>
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>Polylang</strong>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;"><?php esc_html_e('Full support with locale detection', 'wash'); ?></p>
                        </div>
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>TranslatePress</strong>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;"><?php esc_html_e('Partial support (URL-based translations)', 'wash'); ?></p>
                        </div>
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>Weglot</strong>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;"><?php esc_html_e('Partial support (API-based)', 'wash'); ?></p>
                        </div>
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; border: 1px solid #ddd;">
                            <strong>Lokalise</strong>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;"><?php esc_html_e('Via WPML integration', 'wash'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

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
            </div>
        </div>

        <!-- Meta Fields Tab -->
        <?php $metafieldsTabAttrs = wash_get_tab_content_class('metafields', $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <div class="<?php echo esc_attr($metafieldsTabAttrs['class']); ?>" id="tab-metafields" style="display: none;" data-locked="<?php echo $metafieldsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($metafieldsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($metafieldsTabAttrs['upgradeUrl']); ?>">
            <h2 class="title"><?php esc_html_e('Meta Fields Configuration', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Select which meta field providers to sync to Shopify metafields. Click Save Changes to apply.', 'wash'); ?></p>

            <div class="wash-meta-providers" style="margin-top: 20px;">
                <?php
                $wpCoreEnabled = get_option('wash_meta_wp_core', '1') === '1';
                $yoastInstalled = defined('WPSEO_VERSION');
                $yoastEnabled = $yoastInstalled && get_option('wash_meta_yoast') === '1';
                $rankMathInstalled = class_exists('RankMath');
                $rankMathEnabled = $rankMathInstalled && get_option('wash_meta_rankmath') === '1';
                $acfInstalled = class_exists('ACF');
                $acfEnabled = $acfInstalled && get_option('wash_meta_acf') === '1';
                ?>

                <!-- WordPress Core -->
                <div class="wash-meta-card" style="background: <?php echo $wpCoreEnabled ? '#f0fff4' : '#fff'; ?>; border: 2px solid <?php echo $wpCoreEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px;">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="wash_meta_wp_core" value="1"
                                    <?php checked($wpCoreEnabled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-wordpress" style="color: #21759b;"></span>
                                <?php esc_html_e('WordPress Core', 'wash'); ?>
                                <span style="background: #21759b; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Recommended', 'wash'); ?></span>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync WordPress post meta (featured image, excerpt, custom fields)', 'wash'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <code>_thumbnail_id</code>, <code>post_excerpt</code>, <code>_wp_page_template</code>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Yoast SEO -->
                <div class="wash-meta-card" style="background: <?php echo $yoastEnabled ? '#f0fff4' : ($yoastInstalled ? '#fff' : '#f6f7f7'); ?>; border: 2px solid <?php echo $yoastEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px; <?php echo !$yoastInstalled ? 'opacity: 0.7;' : ''; ?>">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: <?php echo $yoastInstalled ? 'pointer' : 'not-allowed'; ?>;">
                                <input type="checkbox" name="wash_meta_yoast" value="1"
                                    <?php checked($yoastEnabled); ?>
                                    <?php disabled(!$yoastInstalled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span style="background: #a4286a; color: #fff; width: 20px; height: 20px; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">Y</span>
                                <?php esc_html_e('Yoast SEO', 'wash'); ?>
                                <?php if (!$yoastInstalled) : ?>
                                    <span style="background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Not Installed', 'wash'); ?></span>
                                <?php elseif ($yoastInstalled) : ?>
                                    <span style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Detected', 'wash'); ?></span>
                                <?php endif; ?>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync Yoast SEO meta (title, description, focus keyword)', 'wash'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <code>_yoast_wpseo_title</code>, <code>_yoast_wpseo_metadesc</code>, <code>_yoast_wpseo_focuskw</code>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Rank Math -->
                <div class="wash-meta-card" style="background: <?php echo $rankMathEnabled ? '#f0fff4' : ($rankMathInstalled ? '#fff' : '#f6f7f7'); ?>; border: 2px solid <?php echo $rankMathEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px; <?php echo !$rankMathInstalled ? 'opacity: 0.7;' : ''; ?>">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: <?php echo $rankMathInstalled ? 'pointer' : 'not-allowed'; ?>;">
                                <input type="checkbox" name="wash_meta_rankmath" value="1"
                                    <?php checked($rankMathEnabled); ?>
                                    <?php disabled(!$rankMathInstalled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span style="background: #e74c3c; color: #fff; width: 20px; height: 20px; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">R</span>
                                <?php esc_html_e('Rank Math', 'wash'); ?>
                                <?php if (!$rankMathInstalled) : ?>
                                    <span style="background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Not Installed', 'wash'); ?></span>
                                <?php else : ?>
                                    <span style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Detected', 'wash'); ?></span>
                                <?php endif; ?>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync Rank Math SEO meta (title, description, focus keyword)', 'wash'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <code>rank_math_title</code>, <code>rank_math_description</code>, <code>rank_math_focus_keyword</code>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ACF -->
                <div class="wash-meta-card" style="background: <?php echo $acfEnabled ? '#f0fff4' : ($acfInstalled ? '#fff' : '#f6f7f7'); ?>; border: 2px solid <?php echo $acfEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px; <?php echo !$acfInstalled ? 'opacity: 0.7;' : ''; ?>">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: <?php echo $acfInstalled ? 'pointer' : 'not-allowed'; ?>;">
                                <input type="checkbox" name="wash_meta_acf" value="1"
                                    <?php checked($acfEnabled); ?>
                                    <?php disabled(!$acfInstalled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span style="background: #00d084; color: #fff; width: 20px; height: 20px; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold;">ACF</span>
                                <?php esc_html_e('Advanced Custom Fields', 'wash'); ?>
                                <?php if (!$acfInstalled) : ?>
                                    <span style="background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Not Installed', 'wash'); ?></span>
                                <?php else : ?>
                                    <span style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Detected', 'wash'); ?></span>
                                <?php endif; ?>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync ACF custom fields to Shopify metafields', 'wash'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <?php esc_html_e('All ACF fields → namespace', 'wash'); ?> <code>acf.{field_name}</code>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <h4 style="margin-top: 0;">
                    <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                    <?php esc_html_e('How Meta Fields are Synced', 'wash'); ?>
                </h4>
                <p style="margin-bottom: 10px;"><?php esc_html_e('WordPress meta fields are converted to Shopify metafields using the following format:', 'wash'); ?></p>
                <table class="widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source', 'wash'); ?></th>
                            <th><?php esc_html_e('Shopify Namespace', 'wash'); ?></th>
                            <th><?php esc_html_e('Example', 'wash'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>WordPress Core</td>
                            <td><code>wordpress</code></td>
                            <td><code>wordpress.featured_image</code></td>
                        </tr>
                        <tr>
                            <td>Yoast SEO</td>
                            <td><code>seo</code></td>
                            <td><code>seo.title</code>, <code>seo.description</code></td>
                        </tr>
                        <tr>
                            <td>Rank Math</td>
                            <td><code>seo</code></td>
                            <td><code>seo.title</code>, <code>seo.description</code></td>
                        </tr>
                        <tr>
                            <td>ACF</td>
                            <td><code>acf</code></td>
                            <td><code>acf.{field_name}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Authors Tab -->
        <?php $authorsTabAttrs = wash_get_tab_content_class('authors', $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <div class="<?php echo esc_attr($authorsTabAttrs['class']); ?>" id="tab-authors" style="display: none;" data-locked="<?php echo $authorsTabAttrs['isLocked'] ? '1' : '0'; ?>" data-required-plan="<?php echo esc_attr($authorsTabAttrs['requiredPlan']); ?>" data-upgrade-url="<?php echo esc_url($authorsTabAttrs['upgradeUrl']); ?>">
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
                <h4 style="margin-top: 0;">
                    <span class="dashicons dashicons-lightbulb" style="color: #2271b1;"></span>
                    <?php esc_html_e('How Author Mapping Works', 'wash'); ?>
                </h4>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong><?php esc_html_e('Disabled:', 'wash'); ?></strong> <?php esc_html_e('Articles show the WordPress author\'s display name (e.g., "admin" or "John Doe")', 'wash'); ?></li>
                    <li><strong><?php esc_html_e('Enabled:', 'wash'); ?></strong> <?php esc_html_e('Articles show the custom name you specify in the mapping table', 'wash'); ?></li>
                    <li><strong><?php esc_html_e('Use cases:', 'wash'); ?></strong> <?php esc_html_e('Pen names, company branding, consistent author attribution across sites', 'wash'); ?></li>
                </ul>
            </div>
        </div>

        <!-- Files & Media Tab -->
        <div class="wash-tab-content" id="tab-files" style="display: none;">
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
            <details class="wash-faq" style="background: #fef0f0; border: 1px solid #d63638; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #d63638; outline: none;">
                    <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                    <?php esc_html_e('What are Shopify Files limits?', 'wash'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #d63638;">
                    <table class="widefat" style="margin-bottom: 15px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('File Type', 'wash'); ?></th>
                                <th><?php esc_html_e('Max File Size', 'wash'); ?></th>
                                <th><?php esc_html_e('Supported Formats', 'wash'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Images', 'wash'); ?></strong></td>
                                <td>20 MB</td>
                                <td>JPEG, PNG, GIF, WebP, HEIC, SVG, AVIF, BMP, ICO</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Videos', 'wash'); ?></strong></td>
                                <td>1 GB</td>
                                <td>MP4, MOV, WebM, AVI, WMV, FLV, 3GP, MKV</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Audio', 'wash'); ?></strong></td>
                                <td>20 MB</td>
                                <td>MP3, WAV, OGG, AAC, M4A, FLAC</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Documents', 'wash'); ?></strong></td>
                                <td>20 MB</td>
                                <td>PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('3D Models', 'wash'); ?></strong></td>
                                <td>15 MB</td>
                                <td>GLB, GLTF, USDZ</td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="padding: 10px; background: #fff5f5; border-radius: 4px;">
                        <strong><?php esc_html_e('Storage Limits:', 'wash'); ?></strong>
                        <ul style="margin: 10px 0 0 20px; line-height: 1.6;">
                            <li><?php esc_html_e('Basic plan: Unlimited images + 1 GB for other files', 'wash'); ?></li>
                            <li><?php esc_html_e('Shopify plan: Unlimited images + 5 GB for other files', 'wash'); ?></li>
                            <li><?php esc_html_e('Advanced plan: Unlimited images + 10 GB for other files', 'wash'); ?></li>
                            <li><?php esc_html_e('Plus plan: Unlimited images + unlimited storage', 'wash'); ?></li>
                        </ul>
                    </div>
                    <p style="margin-top: 15px; margin-bottom: 0; color: #666; font-size: 12px;">
                        <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
                        <?php esc_html_e('Files larger than these limits will be skipped during sync. Check file sizes before uploading.', 'wash'); ?>
                    </p>
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

                    <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px; margin-top: 15px;">
                        <strong style="color: #2271b1;">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('Note:', 'wash'); ?>
                        </strong>
                        <span style="color: #555;">
                            <?php esc_html_e('Images are automatically synced when publishing a post. The sync rewrites WordPress URLs to Shopify CDN URLs in the content.', 'wash'); ?>
                        </span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Sync Tab -->
        <div class="wash-tab-content" id="tab-sync" style="display: none;">
            <h2 class="title"><?php esc_html_e('Sync Preferences', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how content is synchronized to Shopify.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Auto-sync on Publish', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wash_sync_on_publish" value="1"
                                <?php checked(get_option('wash_sync_on_publish'), '1'); ?> />
                            <?php esc_html_e('Automatically sync posts when published or updated', 'wash'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Images', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wash_sync_images" value="1"
                                <?php checked(get_option('wash_sync_images'), '1'); ?> />
                            <?php esc_html_e('Upload media attachments to Shopify CDN', 'wash'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Metafields', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wash_sync_metafields" value="1"
                                <?php checked(get_option('wash_sync_metafields'), '1'); ?> />
                            <?php esc_html_e('Sync ACF fields and custom meta to Shopify metafields', 'wash'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Rewrite Internal URLs', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wash_rewrite_internal_urls" value="1"
                                <?php checked(get_option('wash_rewrite_internal_urls', '1'), '1'); ?> />
                            <?php esc_html_e('Convert internal WordPress links to Shopify URL format', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Rewrites links like /blog/post-slug to /blogs/{blog-handle}/post-slug and /page-slug to /pages/page-slug during sync.', 'wash'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wash_post_types"><?php esc_html_e('Post Types', 'wash'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wash_post_types" name="wash_post_types"
                               value="<?php echo esc_attr(get_option('wash_post_types', 'post,page')); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e('Comma-separated list of post types to sync (e.g. post,page,custom_type)', 'wash'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Advanced Tab -->
        <div class="wash-tab-content" id="tab-advanced" style="display: none;">
            <h2 class="title"><?php esc_html_e('Advanced Settings', 'wash'); ?></h2>
            <p class="description"><?php esc_html_e('Additional configuration options.', 'wash'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Block Search Engines', 'wash'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wash_block_robots" value="1"
                                <?php checked(get_option('wash_block_robots', '1'), '1'); ?> />
                            <?php esc_html_e('Discourage search engines from indexing this site (robots.txt)', 'wash'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Recommended for demo/staging sites. Outputs "Disallow: /" in robots.txt.', 'wash'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<script>
(function() {
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

        // Add upgrade banner at the top of the panel
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
    });

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
        });
    });

    // Restore from hash or URL parameter
    var hash = window.location.hash.replace('#', '');
    var urlParams = new URLSearchParams(window.location.search);
    var tabParam = urlParams.get('tab') || hash;

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
    } else if (hash && document.getElementById('tab-' + hash)) {
        document.querySelectorAll('.wash-tabs .nav-tab').forEach(function(t) {
            t.classList.remove('nav-tab-active');
        });
        document.querySelector('.wash-tabs .nav-tab[data-tab="' + hash + '"]').classList.add('nav-tab-active');
        document.querySelectorAll('.wash-tab-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.getElementById('tab-' + hash).style.display = 'block';
    }

    // Show/hide manual config
    var showManual = document.getElementById('wash-show-manual');
    var hideManual = document.getElementById('wash-hide-manual');
    var manualConfig = document.getElementById('wash-manual-config');

    if (showManual) {
        showManual.addEventListener('click', function(e) {
            e.preventDefault();
            manualConfig.style.display = 'block';
            showManual.style.display = 'none';
        });
    }
    if (hideManual) {
        hideManual.addEventListener('click', function(e) {
            e.preventDefault();
            manualConfig.style.display = 'none';
            showManual.style.display = 'inline';
        });
    }

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
            var store = storeInput.value.trim().replace('.myshopify.com', '');
            var returnUrl = encodeURIComponent(window.location.href.split('?')[0] + '?page=wash&wash_callback=1');
            var siteUrl = encodeURIComponent(window.location.protocol + '//' + window.location.host);
            var siteName = encodeURIComponent('<?php echo esc_js(get_bloginfo('name')); ?>');

            // Redirect to Shopify Admin -> App -> Connections page
            var connectUrl = 'https://admin.shopify.com/store/' + store + '/apps/wash/app/connections' +
                '?action=connect' +
                '&return_url=' + returnUrl +
                '&site_url=' + siteUrl +
                '&site_name=' + siteName;

            window.location.href = connectUrl;
        });
    }

    // Disconnect button
    var disconnectBtn = document.getElementById('wash-disconnect-btn');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', function() {
            if (confirm('<?php echo esc_js(__('Are you sure you want to disconnect from Shopify?', 'wash')); ?>')) {
                // Clear credentials via AJAX
                var formData = new FormData();
                formData.append('action', 'wash_disconnect');
                formData.append('nonce', '<?php echo wp_create_nonce('wash_disconnect'); ?>');

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                }).then(function() {
                    window.location.reload();
                });
            }
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

    var mappingsData = [];
    var shopifyBlogs = [];

    // Load mappings on page load (only if connected)
    if (mappingsList && <?php echo $isConnected ? 'true' : 'false'; ?>) {
        loadMappings();
        loadShopifyBlogs();
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

    function renderMappings() {
        if (!mappingsList) return;
        if (mappingsData.length === 0) {
            mappingsList.innerHTML = '<p style="color: #666;"><?php echo esc_js(__('No mapping rules configured. Posts will sync to the default Shopify blog.', 'wash')); ?></p>';
            return;
        }

        var html = '<table class="widefat striped"><thead><tr>' +
            '<th><?php echo esc_js(__('Rule', 'wash')); ?></th>' +
            '<th><?php echo esc_js(__('Target Blog', 'wash')); ?></th>' +
            '<th><?php echo esc_js(__('Priority', 'wash')); ?></th>' +
            '<th><?php echo esc_js(__('Actions', 'wash')); ?></th>' +
            '</tr></thead><tbody>';

        mappingsData.forEach(function(m) {
            var ruleLabel = m.ruleType === 'default' ? '<?php echo esc_js(__('Default', 'wash')); ?>' :
                (m.ruleType === 'language' ? '<?php echo esc_js(__('Language', 'wash')); ?>: ' : '<?php echo esc_js(__('Category', 'wash')); ?>: ') + (m.ruleValue || '');
            var badgeClass = m.ruleType === 'default' ? 'background:#28a745;' : (m.ruleType === 'language' ? 'background:#007bff;' : 'background:#fd7e14;');

            html += '<tr>' +
                '<td><span style="' + badgeClass + 'color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">' + ruleLabel + '</span></td>' +
                '<td>' + (m.shopifyBlogHandle || m.shopifyBlogId) + '</td>' +
                '<td>' + m.priority + '</td>' +
                '<td>' +
                '<button type="button" class="button button-small wash-edit-mapping" data-id="' + m.id + '"><?php echo esc_js(__('Edit', 'wash')); ?></button> ' +
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
    }

    // Show/hide rule value based on type
    if (ruleTypeSelect) {
        ruleTypeSelect.addEventListener('change', function() {
            ruleValueRow.style.display = this.value === 'default' ? 'none' : '';
        });
    }

    // Show/hide new blog input
    if (shopifyBlogSelect) {
        shopifyBlogSelect.addEventListener('change', function() {
            newBlogRow.style.display = this.value === '_new' ? 'block' : 'none';
        });
    }

    // Add mapping button
    if (addMappingBtn) {
        addMappingBtn.addEventListener('click', function() {
            document.getElementById('wash-mapping-id').value = '';
            document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Add Mapping Rule', 'wash')); ?>';
            ruleTypeSelect.value = 'default';
            document.getElementById('wash-rule-value').value = '';
            shopifyBlogSelect.value = '';
            document.getElementById('wash-priority').value = '0';
            ruleValueRow.style.display = 'none';
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
            var data = {
                action: mappingId ? 'wash_update_mapping' : 'wash_create_mapping',
                nonce: '<?php echo wp_create_nonce('wash_mappings'); ?>',
                mappingId: mappingId,
                ruleType: ruleTypeSelect.value,
                ruleValue: document.getElementById('wash-rule-value').value,
                shopifyBlogId: shopifyBlogSelect.value,
                newBlogName: document.getElementById('wash-new-blog-name').value,
                priority: document.getElementById('wash-priority').value
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

        document.getElementById('wash-mapping-id').value = mapping.id;
        document.getElementById('wash-mapping-form-title').textContent = '<?php echo esc_js(__('Edit Mapping Rule', 'wash')); ?>';
        ruleTypeSelect.value = mapping.ruleType;
        document.getElementById('wash-rule-value').value = mapping.ruleValue || '';
        shopifyBlogSelect.value = mapping.shopifyBlogId;
        document.getElementById('wash-priority').value = mapping.priority;
        ruleValueRow.style.display = mapping.ruleType === 'default' ? 'none' : '';
        newBlogRow.style.display = 'none';
        mappingForm.style.display = 'block';
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
