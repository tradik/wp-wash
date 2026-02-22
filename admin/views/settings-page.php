<?php
$isConnected = !empty(get_option('wp4shpy_api_key')) && !empty(get_option('wp4shpy_api_secret'));
$shopifyStore = get_option('wp4shpy_shopify_store', '');

// Fetch plan info for feature gating
$planInfo = null;
$features = [];
$upgradeUrl = '';
$planName = 'FREE';

if ($isConnected) {
    $apiClient = new wp4shpyApiClient();
    $planInfo = $apiClient->getPlanInfo();
    if (!is_wp_error($planInfo) && !empty($planInfo['features'])) {
        $features = $planInfo['features'];
        $upgradeUrl = $planInfo['upgradeUrl'] ?? '';
        $planName = $planInfo['plan'] ?? 'FREE';
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
function wp4shpy_is_feature_locked($featureName, $features) {
    if (empty($features)) {
        return false; // No plan info, don't lock
    }
    return empty($features[$featureName]);
}

/**
 * Render a tab link with optional locked state.
 */
function wp4shpy_render_tab($tab, $label, $features, $tabFeatures, $featurePlans, $upgradeUrl, $isActive = false) {
    $featureName = $tabFeatures[$tab] ?? null;
    $isLocked = $featureName && wp4shpy_is_feature_locked($featureName, $features);
    $requiredPlan = $featurePlans[$featureName] ?? 'PRO';

    $classes = 'nav-tab';
    if ($isActive) {
        $classes .= ' nav-tab-active';
    }
    if ($isLocked) {
        $classes .= ' nav-tab-locked';
    }

    $lockIcon = $isLocked ? ' <span class="dashicons dashicons-lock" style="font-size: 14px; opacity: 0.6;"></span>' : '';
    $title = $isLocked ? sprintf(esc_attr__('Requires %s plan', 'wp4shpy'), $requiredPlan) : '';

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
?>
<div class="wrap wp4shpy-settings">
    <h1>
        <img src="<?php echo esc_url(wp4shpy_plugin_url() . 'admin/assets/logo.png'); ?>" alt="wp4shpy" style="height: 28px; width: auto; margin-right: 8px; vertical-align: middle;" />
        <?php esc_html_e('wp4shpy Settings', 'wp4shpy'); ?>
        <?php if ($isConnected && $planName) : ?>
            <span class="wp4shpy-plan-badge" style="font-size: 12px; font-weight: 600; background: <?php echo $planName === 'FREE' ? '#f0f0f1' : '#d4edda'; ?>; color: <?php echo $planName === 'FREE' ? '#666' : '#155724'; ?>; padding: 4px 10px; border-radius: 12px; margin-left: 10px; vertical-align: middle;">
                <?php echo esc_html($planName); ?>
            </span>
        <?php endif; ?>
    </h1>

    <?php if ($isConnected && !empty($planInfo['usage'])) : ?>
    <div class="wp4shpy-usage-summary" style="background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; padding: 12px 15px; margin: 15px 0; display: flex; gap: 30px; font-size: 13px;">
        <div>
            <strong><?php esc_html_e('Articles:', 'wp4shpy'); ?></strong>
            <?php
            $articlesUsed = $planInfo['usage']['articlesUsed'] ?? 0;
            $articlesLimit = $planInfo['usage']['articlesLimit'] ?? -1;
            if ($articlesLimit === -1) {
                echo esc_html($articlesUsed) . ' ' . esc_html__('(unlimited)', 'wp4shpy');
            } else {
                echo esc_html($articlesUsed) . ' / ' . esc_html($articlesLimit);
            }
            ?>
        </div>
        <div>
            <strong><?php esc_html_e('Edits:', 'wp4shpy'); ?></strong>
            <?php
            $editsUsed = $planInfo['usage']['editsUsed'] ?? 0;
            $editsLimit = $planInfo['usage']['editsLimit'] ?? -1;
            if ($editsLimit === -1) {
                echo esc_html($editsUsed) . ' ' . esc_html__('(unlimited)', 'wp4shpy');
            } else {
                echo esc_html($editsUsed) . ' / ' . esc_html($editsLimit);
            }
            ?>
        </div>
        <?php if ($planName === 'FREE' && !empty($upgradeUrl)) : ?>
        <div style="margin-left: auto;">
            <a href="<?php echo esc_url($upgradeUrl); ?>" target="_blank" style="color: #0073aa; text-decoration: none;">
                <?php esc_html_e('Upgrade Plan', 'wp4shpy'); ?> →
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper wp4shpy-tabs">
        <?php wp4shpy_render_tab('connection', __('Connection', 'wp4shpy'), $features, $tabFeatures, $featurePlans, $upgradeUrl, true); ?>
        <?php wp4shpy_render_tab('blogs', __('Blogs', 'wp4shpy'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wp4shpy_render_tab('redirections', __('Redirections', 'wp4shpy'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wp4shpy_render_tab('hreflangs', __('Hreflangs', 'wp4shpy'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wp4shpy_render_tab('metafields', __('Meta Fields', 'wp4shpy'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <?php wp4shpy_render_tab('authors', __('Authors', 'wp4shpy'), $features, $tabFeatures, $featurePlans, $upgradeUrl); ?>
        <a href="#files" class="nav-tab" data-tab="files"><?php esc_html_e('Files & Media', 'wp4shpy'); ?></a>
        <a href="#sync" class="nav-tab" data-tab="sync"><?php esc_html_e('Sync Settings', 'wp4shpy'); ?></a>
        <a href="#advanced" class="nav-tab" data-tab="advanced"><?php esc_html_e('Advanced', 'wp4shpy'); ?></a>
    </nav>

    <div class="wp4shpy-connection-status" id="wp4shpy-connection-status" style="display:none;">
        <div class="notice" id="wp4shpy-notice">
            <p id="wp4shpy-notice-text"></p>
        </div>
    </div>

    <form method="post" action="options.php" id="wp4shpy-settings-form">
        <?php settings_fields('wp4shpy_settings'); ?>
        <input type="hidden" name="_wp_http_referer_tab" id="wp4shpy-current-tab" value="connection" />

        <!-- Connection Tab -->
        <div class="wp4shpy-tab-content" id="tab-connection">
            <h2 class="title"><?php esc_html_e('Shopify Store Connection', 'wp4shpy'); ?></h2>

            <?php if ($isConnected) : ?>
                <div class="wp4shpy-connected-box" style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 15px; margin: 15px 0;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="width: 12px; height: 12px; background: #28a745; border-radius: 50%; display: inline-block;"></span>
                        <strong style="color: #155724;"><?php esc_html_e('Connected', 'wp4shpy'); ?></strong>
                        <?php if ($shopifyStore) : ?>
                            <span style="color: #155724;">to <code><?php echo esc_html($shopifyStore); ?>.myshopify.com</code></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="wp4shpy_shopify_store"><?php esc_html_e('Shopify Store', 'wp4shpy'); ?></label>
                    </th>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="text" id="wp4shpy_shopify_store" name="wp4shpy_shopify_store"
                                   value="<?php echo esc_attr($shopifyStore); ?>"
                                   class="regular-text" placeholder="your-store"
                                   style="max-width: 200px;" />
                            <span style="color: #666;">.myshopify.com</span>
                        </div>
                        <p class="description"><?php esc_html_e('Your Shopify store handle (e.g., "my-store" from my-store.myshopify.com)', 'wp4shpy'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <button type="button" id="wp4shpy-connect-btn" class="button button-primary" style="display: none;">
                            <span class="dashicons dashicons-admin-links" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Connect to Shopify', 'wp4shpy'); ?>
                        </button>
                        <?php if ($isConnected) : ?>
                            <button type="button" id="wp4shpy-disconnect-btn" class="button" style="color: #dc3545;">
                                <span class="dashicons dashicons-dismiss" style="vertical-align: text-bottom;"></span>
                                <?php esc_html_e('Disconnect', 'wp4shpy'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php if (!$isConnected) : ?>
            <div id="wp4shpy-manual-config" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ccc;">
                <h3><?php esc_html_e('Manual Configuration', 'wp4shpy'); ?></h3>
                <p class="description"><?php esc_html_e('If automatic connection fails, you can enter credentials manually.', 'wp4shpy'); ?></p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wp4shpy_api_key"><?php esc_html_e('API Key', 'wp4shpy'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="wp4shpy_api_key" name="wp4shpy_api_key"
                                   value="<?php echo esc_attr(get_option('wp4shpy_api_key')); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wp4shpy_api_secret"><?php esc_html_e('API Secret', 'wp4shpy'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="wp4shpy_api_secret" name="wp4shpy_api_secret"
                                   value="<?php echo esc_attr(get_option('wp4shpy_api_secret')); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                </table>

                <p>
                    <a href="#" id="wp4shpy-hide-manual"><?php esc_html_e('Hide manual configuration', 'wp4shpy'); ?></a>
                </p>
            </div>

            <p style="margin-top: 15px;">
                <a href="#" id="wp4shpy-show-manual"><?php esc_html_e('Enter credentials manually', 'wp4shpy'); ?></a>
            </p>
            <?php else : ?>
            <!-- Hidden fields for connected state -->
            <input type="hidden" name="wp4shpy_api_key" value="<?php echo esc_attr(get_option('wp4shpy_api_key')); ?>" />
            <input type="hidden" name="wp4shpy_api_secret" value="<?php echo esc_attr(get_option('wp4shpy_api_secret')); ?>" />
            <?php endif; ?>

            <details class="wp4shpy-http-auth" style="margin-top: 30px; border: 1px solid #c3c4c7; border-radius: 4px; padding: 0;">
                <summary style="padding: 12px 15px; background: #f6f7f7; cursor: pointer; font-weight: 600; border-radius: 4px;">
                    <?php esc_html_e('HTTP Authentication (Optional)', 'wp4shpy'); ?>
                    <span style="font-weight: normal; color: #646970; margin-left: 10px;"><?php esc_html_e('For apps behind Basic Auth', 'wp4shpy'); ?></span>
                </summary>
                <div style="padding: 15px;">
                    <table class="form-table" role="presentation" style="margin-top: 0;">
                        <tr>
                            <th scope="row">
                                <label for="wp4shpy_http_user"><?php esc_html_e('HTTP Username', 'wp4shpy'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="wp4shpy_http_user" name="wp4shpy_http_user"
                                       value="<?php echo esc_attr(get_option('wp4shpy_http_user')); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wp4shpy_http_pass"><?php esc_html_e('HTTP Password', 'wp4shpy'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="wp4shpy_http_pass" name="wp4shpy_http_pass"
                                       value="<?php echo esc_attr(get_option('wp4shpy_http_pass')); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                    </table>
                </div>
            </details>

            <p style="margin-top: 20px;">
                <button type="button" id="wp4shpy-test-connection" class="button button-secondary">
                    <span class="dashicons dashicons-networking" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('Test Connection', 'wp4shpy'); ?>
                </button>
                <button type="button" id="wp4shpy-debug-session" class="button button-secondary" style="margin-left: 10px;">
                    <span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('Debug Session', 'wp4shpy'); ?>
                </button>
                <button type="button" id="wp4shpy-reset-session" class="button button-secondary" style="margin-left: 10px; color: #d63638;">
                    <span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('Reset Session', 'wp4shpy'); ?>
                </button>
            </p>
            <div id="wp4shpy-debug-output" style="display: none; margin-top: 15px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                <pre style="white-space: pre-wrap; word-break: break-all; margin: 0; font-size: 12px;"></pre>
            </div>
        </div>

        <!-- Blogs Tab -->
        <div class="wp4shpy-tab-content" id="tab-blogs" style="display: none;">
            <h2 class="title"><?php esc_html_e('Blog Routing Rules', 'wp4shpy'); ?></h2>
            <p class="description">
                <?php esc_html_e('Route WordPress posts to different Shopify blogs based on language or category.', 'wp4shpy'); ?>
                <?php esc_html_e('Higher priority rules are matched first. Default rule is used when no other rule matches.', 'wp4shpy'); ?>
            </p>

            <?php if (!$isConnected) : ?>
                <div class="notice notice-warning" style="margin: 15px 0;">
                    <p><?php esc_html_e('Connect to Shopify first to configure blog mappings.', 'wp4shpy'); ?></p>
                </div>
            <?php else : ?>
                <div id="wp4shpy-blog-mappings">
                    <div class="wp4shpy-mappings-list" id="wp4shpy-mappings-list">
                        <!-- Mappings loaded via JavaScript -->
                        <p class="wp4shpy-loading"><?php esc_html_e('Loading mappings...', 'wp4shpy'); ?></p>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="button" id="wp4shpy-add-mapping" class="button button-primary">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
                            <?php esc_html_e('Add Mapping Rule', 'wp4shpy'); ?>
                        </button>
                    </div>

                    <!-- Add/Edit Mapping Form (hidden by default) -->
                    <div id="wp4shpy-mapping-form" style="display: none; margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3 id="wp4shpy-mapping-form-title"><?php esc_html_e('Add Mapping Rule', 'wp4shpy'); ?></h3>
                        <input type="hidden" id="wp4shpy-mapping-id" value="" />

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="wp4shpy-rule-type"><?php esc_html_e('Rule Type', 'wp4shpy'); ?></label>
                                </th>
                                <td>
                                    <select id="wp4shpy-rule-type" class="regular-text">
                                        <option value="default"><?php esc_html_e('Default (catch-all)', 'wp4shpy'); ?></option>
                                        <option value="language"><?php esc_html_e('By Language', 'wp4shpy'); ?></option>
                                        <option value="category"><?php esc_html_e('By Category', 'wp4shpy'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="wp4shpy-rule-value-row">
                                <th scope="row">
                                    <label for="wp4shpy-rule-value"><?php esc_html_e('Value', 'wp4shpy'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="wp4shpy-rule-value" class="regular-text" placeholder="en, de, pl..." />
                                    <p class="description" id="wp4shpy-rule-value-help">
                                        <?php esc_html_e('Language code (en, de, pl) or category slug', 'wp4shpy'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="wp4shpy-shopify-blog"><?php esc_html_e('Target Shopify Blog', 'wp4shpy'); ?></label>
                                </th>
                                <td>
                                    <select id="wp4shpy-shopify-blog" class="regular-text">
                                        <option value=""><?php esc_html_e('Select a blog...', 'wp4shpy'); ?></option>
                                        <option value="_new"><?php esc_html_e('➕ Create new blog', 'wp4shpy'); ?></option>
                                    </select>
                                    <div id="wp4shpy-new-blog-row" style="display: none; margin-top: 10px;">
                                        <input type="text" id="wp4shpy-new-blog-name" class="regular-text" placeholder="<?php esc_attr_e('New blog name (e.g., blog-en)', 'wp4shpy'); ?>" />
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="wp4shpy-priority"><?php esc_html_e('Priority', 'wp4shpy'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="wp4shpy-priority" class="small-text" value="0" min="0" max="100" />
                                    <p class="description"><?php esc_html_e('Higher priority rules are matched first (0-100)', 'wp4shpy'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="button" id="wp4shpy-save-mapping" class="button button-primary">
                                <?php esc_html_e('Save Mapping', 'wp4shpy'); ?>
                            </button>
                            <button type="button" id="wp4shpy-cancel-mapping" class="button">
                                <?php esc_html_e('Cancel', 'wp4shpy'); ?>
                            </button>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Redirections Tab -->
        <div class="wp4shpy-tab-content" id="tab-redirections" style="display: none;">
            <h2 class="title"><?php esc_html_e('URL Redirections Management', 'wp4shpy'); ?></h2>
            <p class="description"><?php esc_html_e('Manage 301 redirects from WordPress URLs to Shopify URLs for SEO preservation during migration.', 'wp4shpy'); ?></p>

            <?php if ($isConnected) : ?>
            <!-- Redirections Management Section -->
            <div class="wp4shpy-redirections-manager" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-randomize" style="color: #2271b1;"></span>
                    <?php esc_html_e('Redirections Manager', 'wp4shpy'); ?>
                </h3>

                <!-- Stats Bar -->
                <div id="wp4shpy-redirect-stats" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px 15px; margin-bottom: 20px;">
                    <span class="dashicons dashicons-chart-bar" style="color: #2271b1; vertical-align: middle;"></span>
                    <span id="wp4shpy-stats-text"><?php esc_html_e('Loading redirect stats...', 'wp4shpy'); ?></span>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
                    <button type="button" id="wp4shpy-fetch-wp-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Sync from Old Website', 'wp4shpy'); ?>
                    </button>
                    <button type="button" id="wp4shpy-generate-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Generate from Synced Posts', 'wp4shpy'); ?>
                    </button>
                    <button type="button" id="wp4shpy-load-shopify-redirections" class="button button-secondary">
                        <span class="dashicons dashicons-cloud" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Load from Shopify', 'wp4shpy'); ?>
                    </button>
                    <button type="button" id="wp4shpy-fix-session" class="button" style="display: none; background: #d63638; border-color: #d63638; color: #fff;">
                        <span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Fix Session Scopes', 'wp4shpy'); ?>
                    </button>
                </div>

                <!-- Textarea for Redirections -->
                <div style="margin-bottom: 15px;">
                    <label for="wp4shpy-redirections-textarea" style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <?php esc_html_e('Redirections List', 'wp4shpy'); ?>
                        <span style="font-weight: normal; color: #666;">
                            (<?php esc_html_e('format: /old-path -> /new-path', 'wp4shpy'); ?>)
                        </span>
                    </label>
                    <textarea id="wp4shpy-redirections-textarea" rows="15" style="width: 100%; font-family: monospace; font-size: 13px;" placeholder="<?php esc_attr_e('/blog/my-post -> /blogs/news/my-post
/about-us -> /pages/about-us
/category/news -> /blogs/news

# Lines starting with # are comments', 'wp4shpy'); ?>"></textarea>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e('Enter one redirection per line. You can use ->, =>, or → as separator. Lines starting with # are ignored.', 'wp4shpy'); ?>
                    </p>
                </div>

                <!-- Sync Button -->
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button type="button" id="wp4shpy-sync-redirections" class="button button-primary button-large">
                        <span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e('Sync Redirections to Shopify', 'wp4shpy'); ?>
                    </button>
                    <span id="wp4shpy-sync-status" style="display: none;"></span>
                </div>

                <!-- Sync Results -->
                <div id="wp4shpy-sync-results" style="display: none; margin-top: 20px; padding: 15px; background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px;">
                    <h4 style="margin-top: 0; color: #155724;">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Sync Complete', 'wp4shpy'); ?>
                    </h4>
                    <ul id="wp4shpy-sync-results-list" style="margin: 0; padding-left: 20px;"></ul>
                </div>
            </div>
            <?php else : ?>
            <div class="notice notice-warning" style="margin: 15px 0;">
                <p><?php esc_html_e('Connect to Shopify first to manage redirections.', 'wp4shpy'); ?></p>
            </div>
            <?php endif; ?>

            <!-- Informational Guide -->
            <details style="margin-top: 30px; border: 1px solid #c3c4c7; border-radius: 4px;">
                <summary style="padding: 12px 15px; background: #f6f7f7; cursor: pointer; font-weight: 600; border-radius: 4px;">
                    <span class="dashicons dashicons-info" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e('URL Structure Guide', 'wp4shpy'); ?>
                </summary>
                <div style="padding: 15px;">

            <!-- Current WordPress URL Structure -->
            <div class="wp4shpy-info-section" style="background: #fef7f1; border: 1px solid #f0c674; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #8b5a00;">
                    <span class="dashicons dashicons-wordpress" style="color: #21759b;"></span>
                    <?php esc_html_e('WordPress URL Structure (Current)', 'wp4shpy'); ?>
                </h3>
                <p><?php esc_html_e('Your WordPress site currently serves content with these URL patterns:', 'wp4shpy'); ?></p>
                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td style="width: 25%;"><strong><?php esc_html_e('Blog Posts', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/blog/my-post-title/</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Permalink structure depends on Settings → Permalinks', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Pages', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/about-us/</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Page slug becomes the URL', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Categories', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/category/news/</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Category archives with pagination', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Images', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>https://your-wp-site.com/wp-content/uploads/2024/01/image.jpg</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Served from your WordPress server', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Shopify URL Structure -->
            <div class="wp4shpy-info-section" style="background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #5c6ac4;">
                    <span class="dashicons dashicons-store" style="color: #96bf48;"></span>
                    <?php esc_html_e('Shopify URL Structure (After Sync)', 'wp4shpy'); ?>
                </h3>
                <p><?php esc_html_e('After syncing, your content will be available on Shopify with these URL patterns:', 'wp4shpy'); ?></p>
                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td style="width: 25%;"><strong><?php esc_html_e('Blog Posts', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>https://<?php echo esc_html($shopifyStore ?: 'your-store'); ?>.myshopify.com/blogs/<strong>news</strong>/my-post-title</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Blog handle is required in Shopify URLs. Configure in Blogs tab.', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Multi-language Posts', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>/blogs/<strong>blog-en</strong>/my-post</code> → <?php esc_html_e('English', 'wp4shpy'); ?><br>
                                <code>/blogs/<strong>blog-de</strong>/mein-beitrag</code> → <?php esc_html_e('German', 'wp4shpy'); ?><br>
                                <code>/blogs/<strong>blog-pl</strong>/moj-wpis</code> → <?php esc_html_e('Polish', 'wp4shpy'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Each language gets its own blog with routing rules.', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Pages', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>https://<?php echo esc_html($shopifyStore ?: 'your-store'); ?>.myshopify.com/pages/about-us</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Pages are prefixed with /pages/ in Shopify.', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Images', 'wp4shpy'); ?></strong></td>
                            <td>
                                <code>https://cdn.shopify.com/s/files/1/xxxx/image.jpg</code><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Images are uploaded to Shopify CDN for faster global delivery.', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Image Handling -->
            <div class="wp4shpy-info-section" style="background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-format-image" style="color: #2271b1;"></span>
                    <?php esc_html_e('Image Handling', 'wp4shpy'); ?>
                </h3>
                <table class="widefat" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Image Type', 'wp4shpy'); ?></th>
                            <th><?php esc_html_e('What Happens', 'wp4shpy'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Featured Image', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Uploaded to Shopify and set as article image. Displayed in blog listings and article headers.', 'wp4shpy'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Content Images', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Images in post content (img tags) are uploaded to Shopify CDN. URLs in HTML are automatically rewritten.', 'wp4shpy'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('External Images', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Images from external URLs (not WordPress) are kept as-is. Make sure external sources are reliable.', 'wp4shpy'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Image Sizes', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Original full-size images are uploaded. Shopify automatically generates responsive sizes.', 'wp4shpy'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- What Needs Manual Attention -->
            <div class="wp4shpy-info-section" style="background: #fff8e5; border: 1px solid #f0c674; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                    <?php esc_html_e('What Needs Manual Attention', 'wp4shpy'); ?>
                </h3>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong><?php esc_html_e('Internal Links:', 'wp4shpy'); ?></strong> <?php esc_html_e('Links to other WordPress posts/pages in your content are NOT automatically rewritten. Update them manually or use search-replace.', 'wp4shpy'); ?></li>
                    <li><strong><?php esc_html_e('301 Redirects:', 'wp4shpy'); ?></strong> <?php esc_html_e('Set up redirects from old WordPress URLs to new Shopify URLs to preserve SEO. Use Shopify Admin → Settings → Navigation → URL Redirects.', 'wp4shpy'); ?></li>
                    <li><strong><?php esc_html_e('Menu Links:', 'wp4shpy'); ?></strong> <?php esc_html_e('Shopify navigation menus need to be configured separately. WordPress menus are not synced.', 'wp4shpy'); ?></li>
                    <li><strong><?php esc_html_e('Canonical URLs:', 'wp4shpy'); ?></strong> <?php esc_html_e('If keeping WordPress active, set canonical URLs to Shopify to avoid duplicate content penalties.', 'wp4shpy'); ?></li>
                </ul>
            </div>

            <!-- Shopify Publications -->
            <div class="wp4shpy-info-section" style="background: #f5f0ff; border: 1px solid #7c3aed; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-megaphone" style="color: #7c3aed;"></span>
                    <?php esc_html_e('Shopify Publications (Sales Channels)', 'wp4shpy'); ?>
                </h3>
                <p><?php esc_html_e('Shopify uses "Publications" to control where your content is visible. By default, synced articles are published to your Online Store.', 'wp4shpy'); ?></p>
                <table class="widefat" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Publication', 'wp4shpy'); ?></th>
                            <th><?php esc_html_e('Description', 'wp4shpy'); ?></th>
                            <th><?php esc_html_e('Status', 'wp4shpy'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Online Store', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Your main Shopify storefront (yourstore.myshopify.com or custom domain)', 'wp4shpy'); ?></td>
                            <td><span style="color: #00a32a;">✓ <?php esc_html_e('Published', 'wp4shpy'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Point of Sale', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('In-person sales via Shopify POS app', 'wp4shpy'); ?></td>
                            <td><span style="color: #666;">— <?php esc_html_e('Not applicable', 'wp4shpy'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Buy Button', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Embeddable buy buttons for external websites', 'wp4shpy'); ?></td>
                            <td><span style="color: #666;">— <?php esc_html_e('Not applicable', 'wp4shpy'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Headless / Hydrogen', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Custom storefronts using Storefront API', 'wp4shpy'); ?></td>
                            <td><span style="color: #00a32a;">✓ <?php esc_html_e('Available via API', 'wp4shpy'); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Markets (International)', 'wp4shpy'); ?></strong></td>
                            <td><?php esc_html_e('Region-specific content for international markets', 'wp4shpy'); ?></td>
                            <td><span style="color: #2271b1;">⚙ <?php esc_html_e('Configure in Shopify', 'wp4shpy'); ?></span></td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top: 15px; color: #666; font-size: 13px;">
                    <span class="dashicons dashicons-info" style="font-size: 16px;"></span>
                    <?php esc_html_e('To change publication settings for synced articles, go to Shopify Admin → Online Store → Blog posts → Edit article → Visibility.', 'wp4shpy'); ?>
                </p>
            </div>

            <!-- Shopify Redirect Limits -->
            <div class="wp4shpy-info-section" style="background: #fef0f0; border: 1px solid #d63638; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-info-outline" style="color: #d63638;"></span>
                    <?php esc_html_e('Shopify Redirect Limits', 'wp4shpy'); ?>
                </h3>
                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td style="width: 30%;"><strong><?php esc_html_e('Maximum Redirects', 'wp4shpy'); ?></strong></td>
                            <td>
                                <?php esc_html_e('100,000 URL redirects per store (all plans)', 'wp4shpy'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('If you need more, consider using a third-party redirect app.', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Bulk Import', 'wp4shpy'); ?></strong></td>
                            <td>
                                <?php esc_html_e('You can import redirects via CSV file in Shopify Admin → Settings → Navigation → URL Redirects → Import.', 'wp4shpy'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Format: two columns - "Redirect from" and "Redirect to" (relative paths).', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Path Only', 'wp4shpy'); ?></strong></td>
                            <td>
                                <?php esc_html_e('Redirects work with paths only (e.g., /old-page → /new-page). Domain is not included.', 'wp4shpy'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Query strings (? parameters) are ignored in redirect matching.', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('No Wildcards', 'wp4shpy'); ?></strong></td>
                            <td>
                                <?php esc_html_e('Shopify does not support wildcard redirects (e.g., /blog/* → /blogs/news/*).', 'wp4shpy'); ?><br>
                                <span style="color: #666; font-size: 12px;"><?php esc_html_e('Each URL must be redirected individually. Apps like "Bulk Redirect" can help.', 'wp4shpy'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Processing Time', 'wp4shpy'); ?></strong></td>
                            <td>
                                <?php esc_html_e('Large CSV imports may take several minutes to process. Redirects become active immediately after import.', 'wp4shpy'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- SEO Checklist -->
            <div class="wp4shpy-info-section" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                    <?php esc_html_e('SEO Migration Checklist', 'wp4shpy'); ?>
                </h3>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li>☐ <?php esc_html_e('Export list of all WordPress URLs before migration', 'wp4shpy'); ?></li>
                    <li>☐ <?php esc_html_e('Create 301 redirects in Shopify for each old URL', 'wp4shpy'); ?></li>
                    <li>☐ <?php esc_html_e('Update Google Search Console with new sitemap', 'wp4shpy'); ?></li>
                    <li>☐ <?php esc_html_e('Check meta titles and descriptions synced correctly', 'wp4shpy'); ?></li>
                    <li>☐ <?php esc_html_e('Verify images load from Shopify CDN', 'wp4shpy'); ?></li>
                    <li>☐ <?php esc_html_e('Test internal links and update broken ones', 'wp4shpy'); ?></li>
                    <li>☐ <?php esc_html_e('Set up robots.txt to block WordPress from search engines (if keeping it)', 'wp4shpy'); ?></li>
                </ul>
            </div>

                </div>
            </details>
        </div>

        <!-- Hreflangs Tab -->
        <div class="wp4shpy-tab-content" id="tab-hreflangs" style="display: none;">
            <h2 class="title"><?php esc_html_e('Hreflang Tags Configuration', 'wp4shpy'); ?></h2>
            <p class="description"><?php esc_html_e('Configure hreflang tags for multi-language SEO on Shopify.', 'wp4shpy'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Hreflangs', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_hreflangs_enabled" value="1"
                                <?php checked(get_option('wp4shpy_hreflangs_enabled'), '1'); ?> />
                            <?php esc_html_e('Sync hreflang data to Shopify metafields', 'wp4shpy'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, language relationship data will be synced as metafields.', 'wp4shpy'); ?></p>
                    </td>
                </tr>
            </table>

            <!-- Important Warning -->
            <div class="wp4shpy-info-section" style="background: #fef0f0; border: 2px solid #d63638; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #d63638;">
                    <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                    <?php esc_html_e('Important: Shopify Hreflang Implementation', 'wp4shpy'); ?>
                </h3>
                <p style="font-size: 14px; line-height: 1.6;">
                    <?php esc_html_e('Shopify does NOT natively support hreflang tags in blog articles. To implement proper hreflang tags, you need to:', 'wp4shpy'); ?>
                </p>
                <ol style="margin-left: 20px; line-height: 2;">
                    <li>
                        <strong><?php esc_html_e('Contact Shopify Support', 'wp4shpy'); ?></strong><br>
                        <span style="color: #666;"><?php esc_html_e('Request them to enable "Script Injection" at store level. This allows custom code in the &lt;head&gt; section of your theme.', 'wp4shpy'); ?></span>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Edit Your Theme', 'wp4shpy'); ?></strong><br>
                        <span style="color: #666;"><?php esc_html_e('Add Liquid code to your theme\'s theme.liquid or article template to read hreflang metafields and output proper hreflang tags.', 'wp4shpy'); ?></span>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Define Metafield Structure', 'wp4shpy'); ?></strong><br>
                        <span style="color: #666;"><?php esc_html_e('Hreflang relationships must be stored in Shopify metafields with a consistent namespace.', 'wp4shpy'); ?></span>
                    </li>
                </ol>
            </div>

            <!-- How It Works -->
            <div class="wp4shpy-info-section" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                    <?php esc_html_e('How Hreflang Syncing Works', 'wp4shpy'); ?>
                </h3>
                <p><?php esc_html_e('When enabled, wp4shpy will sync the following data as Shopify article metafields:', 'wp4shpy'); ?></p>
                <table class="widefat" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Metafield', 'wp4shpy'); ?></th>
                            <th><?php esc_html_e('Description', 'wp4shpy'); ?></th>
                            <th><?php esc_html_e('Example Value', 'wp4shpy'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>hreflang.language</code></td>
                            <td><?php esc_html_e('ISO language code of this article', 'wp4shpy'); ?></td>
                            <td><code>en</code>, <code>de</code>, <code>pl</code></td>
                        </tr>
                        <tr>
                            <td><code>hreflang.translations</code></td>
                            <td><?php esc_html_e('JSON array of alternate language versions', 'wp4shpy'); ?></td>
                            <td><code>[{"lang":"de","url":"/blogs/blog-de/artikel"}]</code></td>
                        </tr>
                        <tr>
                            <td><code>hreflang.x_default</code></td>
                            <td><?php esc_html_e('URL of the default/fallback version', 'wp4shpy'); ?></td>
                            <td><code>/blogs/news/article</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Liquid Code Example -->
            <div class="wp4shpy-info-section" style="background: #1e1e1e; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #fff;">
                    <span class="dashicons dashicons-editor-code" style="color: #96bf48;"></span>
                    <?php esc_html_e('Liquid Code for theme.liquid', 'wp4shpy'); ?>
                </h3>
                <p style="color: #999; font-size: 13px;"><?php esc_html_e('Add this code inside the &lt;head&gt; section of your theme:', 'wp4shpy'); ?></p>
                <pre style="background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5;">{% if article %}
  {% assign hreflang_lang = article.metafields.hreflang.language %}
  {% assign hreflang_translations = article.metafields.hreflang.translations %}
  {% assign hreflang_default = article.metafields.hreflang.x_default %}

  {% if hreflang_lang %}
    &lt;link rel="alternate" hreflang="{{ hreflang_lang }}" href="{{ canonical_url }}" /&gt;
  {% endif %}

  {% if hreflang_translations %}
    {% assign translations = hreflang_translations | parse_json %}
    {% for trans in translations %}
      &lt;link rel="alternate" hreflang="{{ trans.lang }}" href="{{ shop.url }}{{ trans.url }}" /&gt;
    {% endfor %}
  {% endif %}

  {% if hreflang_default %}
    &lt;link rel="alternate" hreflang="x-default" href="{{ shop.url }}{{ hreflang_default }}" /&gt;
  {% endif %}
{% endif %}</pre>
            </div>

            <!-- Contact Support -->
            <div class="wp4shpy-info-section" style="background: #fff8e5; border: 1px solid #f0c674; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0;">
                    <span class="dashicons dashicons-phone" style="color: #8b5a00;"></span>
                    <?php esc_html_e('Shopify Support Contact', 'wp4shpy'); ?>
                </h3>
                <p><?php esc_html_e('To enable script injection for hreflang tags, contact Shopify Plus support:', 'wp4shpy'); ?></p>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong><?php esc_html_e('Shopify Plus:', 'wp4shpy'); ?></strong> <?php esc_html_e('Contact your Merchant Success Manager or use the Priority Support channel.', 'wp4shpy'); ?></li>
                    <li><strong><?php esc_html_e('Regular Plans:', 'wp4shpy'); ?></strong> <?php esc_html_e('Visit help.shopify.com and request "Script Tag API access" or "Checkout extensibility" for hreflang implementation.', 'wp4shpy'); ?></li>
                </ul>
                <p style="margin-top: 15px; padding: 10px; background: #fff; border-radius: 4px;">
                    <strong><?php esc_html_e('Alternative:', 'wp4shpy'); ?></strong> <?php esc_html_e('Use Shopify Markets for built-in multi-language support with automatic hreflang tags (requires Shopify Plus or compatible plan).', 'wp4shpy'); ?>
                </p>
            </div>
        </div>

        <!-- Meta Fields Tab -->
        <div class="wp4shpy-tab-content" id="tab-metafields" style="display: none;">
            <h2 class="title"><?php esc_html_e('Meta Fields Configuration', 'wp4shpy'); ?></h2>
            <p class="description"><?php esc_html_e('Select which meta field providers to sync to Shopify metafields. Click Save Changes to apply.', 'wp4shpy'); ?></p>

            <div class="wp4shpy-meta-providers" style="margin-top: 20px;">
                <?php
                $wpCoreEnabled = get_option('wp4shpy_meta_wp_core', '1') === '1';
                $yoastInstalled = defined('WPSEO_VERSION');
                $yoastEnabled = $yoastInstalled && get_option('wp4shpy_meta_yoast') === '1';
                $rankMathInstalled = class_exists('RankMath');
                $rankMathEnabled = $rankMathInstalled && get_option('wp4shpy_meta_rankmath') === '1';
                $acfInstalled = class_exists('ACF');
                $acfEnabled = $acfInstalled && get_option('wp4shpy_meta_acf') === '1';
                ?>

                <!-- WordPress Core -->
                <div class="wp4shpy-meta-card" style="background: <?php echo $wpCoreEnabled ? '#f0fff4' : '#fff'; ?>; border: 2px solid <?php echo $wpCoreEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px;">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="wp4shpy_meta_wp_core" value="1"
                                    <?php checked($wpCoreEnabled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-wordpress" style="color: #21759b;"></span>
                                <?php esc_html_e('WordPress Core', 'wp4shpy'); ?>
                                <span style="background: #21759b; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Recommended', 'wp4shpy'); ?></span>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync WordPress post meta (featured image, excerpt, custom fields)', 'wp4shpy'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <code>_thumbnail_id</code>, <code>post_excerpt</code>, <code>_wp_page_template</code>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Yoast SEO -->
                <div class="wp4shpy-meta-card" style="background: <?php echo $yoastEnabled ? '#f0fff4' : ($yoastInstalled ? '#fff' : '#f6f7f7'); ?>; border: 2px solid <?php echo $yoastEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px; <?php echo !$yoastInstalled ? 'opacity: 0.7;' : ''; ?>">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: <?php echo $yoastInstalled ? 'pointer' : 'not-allowed'; ?>;">
                                <input type="checkbox" name="wp4shpy_meta_yoast" value="1"
                                    <?php checked($yoastEnabled); ?>
                                    <?php disabled(!$yoastInstalled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span style="background: #a4286a; color: #fff; width: 20px; height: 20px; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">Y</span>
                                <?php esc_html_e('Yoast SEO', 'wp4shpy'); ?>
                                <?php if (!$yoastInstalled) : ?>
                                    <span style="background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Not Installed', 'wp4shpy'); ?></span>
                                <?php elseif ($yoastInstalled) : ?>
                                    <span style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Detected', 'wp4shpy'); ?></span>
                                <?php endif; ?>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync Yoast SEO meta (title, description, focus keyword)', 'wp4shpy'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <code>_yoast_wpseo_title</code>, <code>_yoast_wpseo_metadesc</code>, <code>_yoast_wpseo_focuskw</code>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Rank Math -->
                <div class="wp4shpy-meta-card" style="background: <?php echo $rankMathEnabled ? '#f0fff4' : ($rankMathInstalled ? '#fff' : '#f6f7f7'); ?>; border: 2px solid <?php echo $rankMathEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px; <?php echo !$rankMathInstalled ? 'opacity: 0.7;' : ''; ?>">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: <?php echo $rankMathInstalled ? 'pointer' : 'not-allowed'; ?>;">
                                <input type="checkbox" name="wp4shpy_meta_rankmath" value="1"
                                    <?php checked($rankMathEnabled); ?>
                                    <?php disabled(!$rankMathInstalled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span style="background: #e74c3c; color: #fff; width: 20px; height: 20px; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">R</span>
                                <?php esc_html_e('Rank Math', 'wp4shpy'); ?>
                                <?php if (!$rankMathInstalled) : ?>
                                    <span style="background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Not Installed', 'wp4shpy'); ?></span>
                                <?php else : ?>
                                    <span style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Detected', 'wp4shpy'); ?></span>
                                <?php endif; ?>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync Rank Math SEO meta (title, description, focus keyword)', 'wp4shpy'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <code>rank_math_title</code>, <code>rank_math_description</code>, <code>rank_math_focus_keyword</code>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ACF -->
                <div class="wp4shpy-meta-card" style="background: <?php echo $acfEnabled ? '#f0fff4' : ($acfInstalled ? '#fff' : '#f6f7f7'); ?>; border: 2px solid <?php echo $acfEnabled ? '#00a32a' : '#c3c4c7'; ?>; border-radius: 8px; padding: 20px; margin-bottom: 15px; <?php echo !$acfInstalled ? 'opacity: 0.7;' : ''; ?>">
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex-shrink: 0;">
                            <label style="display: flex; align-items: center; cursor: <?php echo $acfInstalled ? 'pointer' : 'not-allowed'; ?>;">
                                <input type="checkbox" name="wp4shpy_meta_acf" value="1"
                                    <?php checked($acfEnabled); ?>
                                    <?php disabled(!$acfInstalled); ?>
                                    style="width: 20px; height: 20px; margin: 0;" />
                            </label>
                        </div>
                        <div style="flex-grow: 1;">
                            <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;">
                                <span style="background: #00d084; color: #fff; width: 20px; height: 20px; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold;">ACF</span>
                                <?php esc_html_e('Advanced Custom Fields', 'wp4shpy'); ?>
                                <?php if (!$acfInstalled) : ?>
                                    <span style="background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Not Installed', 'wp4shpy'); ?></span>
                                <?php else : ?>
                                    <span style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: normal;"><?php esc_html_e('Detected', 'wp4shpy'); ?></span>
                                <?php endif; ?>
                            </h4>
                            <p style="margin: 0 0 8px 0; color: #1e1e1e;"><?php esc_html_e('Sync ACF custom fields to Shopify metafields', 'wp4shpy'); ?></p>
                            <p style="margin: 0; color: #646970; font-size: 12px;">
                                <?php esc_html_e('All ACF fields → namespace', 'wp4shpy'); ?> <code>acf.{field_name}</code>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <h4 style="margin-top: 0;">
                    <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                    <?php esc_html_e('How Meta Fields are Synced', 'wp4shpy'); ?>
                </h4>
                <p style="margin-bottom: 10px;"><?php esc_html_e('WordPress meta fields are converted to Shopify metafields using the following format:', 'wp4shpy'); ?></p>
                <table class="widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source', 'wp4shpy'); ?></th>
                            <th><?php esc_html_e('Shopify Namespace', 'wp4shpy'); ?></th>
                            <th><?php esc_html_e('Example', 'wp4shpy'); ?></th>
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
        <div class="wp4shpy-tab-content" id="tab-authors" style="display: none;">
            <h2 class="title"><?php esc_html_e('Author Mapping', 'wp4shpy'); ?></h2>
            <p class="description"><?php esc_html_e('Map WordPress users to custom author names displayed on Shopify articles.', 'wp4shpy'); ?></p>

            <?php
            $authorsEnabled = get_option('wp4shpy_authors_enabled', '0') === '1';
            $authorMappings = get_option('wp4shpy_author_mappings', []);
            if (!is_array($authorMappings)) {
                $authorMappings = [];
            }
            $wpUsers = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Author Mapping', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_authors_enabled" value="1" id="wp4shpy-authors-enabled"
                                <?php checked($authorsEnabled); ?> />
                            <?php esc_html_e('Override WordPress author names with custom values', 'wp4shpy'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When disabled, articles use the original WordPress author display name.', 'wp4shpy'); ?></p>
                    </td>
                </tr>
            </table>

            <div id="wp4shpy-author-mappings-section" style="<?php echo $authorsEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                <h3 style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #c3c4c7;">
                    <?php esc_html_e('User → Author Name Mapping', 'wp4shpy'); ?>
                </h3>

                <?php if (empty($wpUsers)) : ?>
                    <p style="color: #666;"><?php esc_html_e('No users found.', 'wp4shpy'); ?></p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width: 800px;">
                        <thead>
                            <tr>
                                <th style="width: 30%;"><?php esc_html_e('WordPress User', 'wp4shpy'); ?></th>
                                <th style="width: 35%;"><?php esc_html_e('Current Display Name', 'wp4shpy'); ?></th>
                                <th style="width: 35%;"><?php esc_html_e('Shopify Author Name', 'wp4shpy'); ?></th>
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
                                               name="wp4shpy_author_mappings[<?php echo esc_attr($userId); ?>]"
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
                        <?php esc_html_e('Leave empty to use the original WordPress display name. Changes apply to newly synced articles.', 'wp4shpy'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 25px 0; max-width: 800px;">
                <h4 style="margin-top: 0;">
                    <span class="dashicons dashicons-lightbulb" style="color: #2271b1;"></span>
                    <?php esc_html_e('How Author Mapping Works', 'wp4shpy'); ?>
                </h4>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li><strong><?php esc_html_e('Disabled:', 'wp4shpy'); ?></strong> <?php esc_html_e('Articles show the WordPress author\'s display name (e.g., "admin" or "John Doe")', 'wp4shpy'); ?></li>
                    <li><strong><?php esc_html_e('Enabled:', 'wp4shpy'); ?></strong> <?php esc_html_e('Articles show the custom name you specify in the mapping table', 'wp4shpy'); ?></li>
                    <li><strong><?php esc_html_e('Use cases:', 'wp4shpy'); ?></strong> <?php esc_html_e('Pen names, company branding, consistent author attribution across sites', 'wp4shpy'); ?></li>
                </ul>
            </div>
        </div>

        <!-- Files & Media Tab -->
        <div class="wp4shpy-tab-content" id="tab-files" style="display: none;">
            <h2 class="title"><?php esc_html_e('Files & Media Configuration', 'wp4shpy'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how images and media files are handled when syncing to Shopify.', 'wp4shpy'); ?></p>

            <?php
            $filesEnabled = get_option('wp4shpy_files_enabled', '1') === '1';
            $webpConvert = get_option('wp4shpy_webp_convert', '0') === '1';
            $allowedFileTypes = get_option('wp4shpy_allowed_file_types', ['images']);
            if (!is_array($allowedFileTypes)) {
                $allowedFileTypes = ['images'];
            }
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Upload Images', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_files_enabled" value="1" id="wp4shpy-files-enabled"
                                <?php checked($filesEnabled); ?> />
                            <?php esc_html_e('Enable image uploads to Shopify Files', 'wp4shpy'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, media attachments will be uploaded to Shopify CDN. Disable to skip image syncing.', 'wp4shpy'); ?></p>
                    </td>
                </tr>
                <tr id="wp4shpy-webp-row" style="<?php echo $filesEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                    <th scope="row"><?php esc_html_e('Convert to WebP', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_webp_convert" value="1" id="wp4shpy-webp-convert"
                                <?php checked($webpConvert); ?> />
                            <?php esc_html_e('Convert images to modern WebP format', 'wp4shpy'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('WebP provides better compression and faster load times. Original format preserved if conversion fails.', 'wp4shpy'); ?></p>
                    </td>
                </tr>
                <tr id="wp4shpy-file-types-row" style="<?php echo $filesEnabled ? '' : 'opacity: 0.5; pointer-events: none;'; ?>">
                    <th scope="row"><?php esc_html_e('Allowed File Types', 'wp4shpy'); ?></th>
                    <td>
                        <fieldset>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wp4shpy_allowed_file_types[]" value="images"
                                    <?php checked(in_array('images', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Images (JPEG, PNG, GIF, WebP, SVG)', 'wp4shpy'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wp4shpy'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wp4shpy_allowed_file_types[]" value="pdf"
                                    <?php checked(in_array('pdf', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('PDF Documents', 'wp4shpy'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wp4shpy'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wp4shpy_allowed_file_types[]" value="video"
                                    <?php checked(in_array('video', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Videos (MP4, MOV, WebM)', 'wp4shpy'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 1 GB each', 'wp4shpy'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wp4shpy_allowed_file_types[]" value="audio"
                                    <?php checked(in_array('audio', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Audio (MP3, WAV, OGG)', 'wp4shpy'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wp4shpy'); ?></span>
                            </label>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="wp4shpy_allowed_file_types[]" value="documents"
                                    <?php checked(in_array('documents', $allowedFileTypes, true)); ?> />
                                <?php esc_html_e('Documents (DOC, XLS, PPT, TXT)', 'wp4shpy'); ?>
                                <span style="color: #666; font-size: 12px;">— <?php esc_html_e('max 20 MB each', 'wp4shpy'); ?></span>
                            </label>
                        </fieldset>
                        <p class="description" style="margin-top: 10px;">
                            <?php esc_html_e('Select which file types should be synced to Shopify Files.', 'wp4shpy'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- FAQ Section -->
            <h3 style="margin-top: 30px; margin-bottom: 15px;">
                <span class="dashicons dashicons-book" style="color: #2271b1;"></span>
                <?php esc_html_e('FAQ', 'wp4shpy'); ?>
            </h3>

            <!-- FAQ: How Image Syncing Works -->
            <details class="wp4shpy-faq" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #2271b1; outline: none;">
                    <span class="dashicons dashicons-format-image" style="vertical-align: middle;"></span>
                    <?php esc_html_e('How does image syncing work?', 'wp4shpy'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #c5d9ed;">
                    <ul style="margin-left: 20px; line-height: 1.8;">
                        <li><?php esc_html_e('Images are uploaded to Shopify CDN for faster global delivery', 'wp4shpy'); ?></li>
                        <li><?php esc_html_e('Image URLs in post content are automatically rewritten to Shopify CDN URLs', 'wp4shpy'); ?></li>
                        <li><?php esc_html_e('Featured images are synced as article images in Shopify', 'wp4shpy'); ?></li>
                        <li><?php esc_html_e('Use the Media Library to manually sync individual images', 'wp4shpy'); ?></li>
                    </ul>
                </div>
            </details>

            <!-- FAQ: WebP Format Benefits -->
            <details class="wp4shpy-faq" style="background: #f0fff4; border: 1px solid #96bf48; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #00a32a; outline: none;">
                    <span class="dashicons dashicons-performance" style="vertical-align: middle;"></span>
                    <?php esc_html_e('What are the benefits of WebP format?', 'wp4shpy'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #96bf48;">
                    <table class="widefat" style="max-width: 600px;">
                        <tbody>
                            <tr>
                                <td style="width: 30%;"><strong><?php esc_html_e('Compression', 'wp4shpy'); ?></strong></td>
                                <td><?php esc_html_e('25-35% smaller than JPEG at same quality', 'wp4shpy'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Browser Support', 'wp4shpy'); ?></strong></td>
                                <td><?php esc_html_e('All modern browsers (Chrome, Firefox, Safari, Edge)', 'wp4shpy'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Supported Inputs', 'wp4shpy'); ?></strong></td>
                                <td><?php esc_html_e('JPEG, PNG, GIF, TIFF', 'wp4shpy'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <!-- FAQ: Shopify Files Limits -->
            <details class="wp4shpy-faq" style="background: #fef0f0; border: 1px solid #d63638; border-radius: 4px; padding: 15px; margin: 10px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #d63638; outline: none;">
                    <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                    <?php esc_html_e('What are Shopify Files limits?', 'wp4shpy'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #d63638;">
                    <table class="widefat" style="margin-bottom: 15px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('File Type', 'wp4shpy'); ?></th>
                                <th><?php esc_html_e('Max File Size', 'wp4shpy'); ?></th>
                                <th><?php esc_html_e('Supported Formats', 'wp4shpy'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Images', 'wp4shpy'); ?></strong></td>
                                <td>20 MB</td>
                                <td>JPEG, PNG, GIF, WebP, HEIC, SVG, AVIF, BMP, ICO</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Videos', 'wp4shpy'); ?></strong></td>
                                <td>1 GB</td>
                                <td>MP4, MOV, WebM, AVI, WMV, FLV, 3GP, MKV</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Audio', 'wp4shpy'); ?></strong></td>
                                <td>20 MB</td>
                                <td>MP3, WAV, OGG, AAC, M4A, FLAC</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Documents', 'wp4shpy'); ?></strong></td>
                                <td>20 MB</td>
                                <td>PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV</td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('3D Models', 'wp4shpy'); ?></strong></td>
                                <td>15 MB</td>
                                <td>GLB, GLTF, USDZ</td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="padding: 10px; background: #fff5f5; border-radius: 4px;">
                        <strong><?php esc_html_e('Storage Limits:', 'wp4shpy'); ?></strong>
                        <ul style="margin: 10px 0 0 20px; line-height: 1.6;">
                            <li><?php esc_html_e('Basic plan: Unlimited images + 1 GB for other files', 'wp4shpy'); ?></li>
                            <li><?php esc_html_e('Shopify plan: Unlimited images + 5 GB for other files', 'wp4shpy'); ?></li>
                            <li><?php esc_html_e('Advanced plan: Unlimited images + 10 GB for other files', 'wp4shpy'); ?></li>
                            <li><?php esc_html_e('Plus plan: Unlimited images + unlimited storage', 'wp4shpy'); ?></li>
                        </ul>
                    </div>
                    <p style="margin-top: 15px; margin-bottom: 0; color: #666; font-size: 12px;">
                        <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
                        <?php esc_html_e('Files larger than these limits will be skipped during sync. Check file sizes before uploading.', 'wp4shpy'); ?>
                    </p>
                </div>
            </details>

            <!-- Troubleshooting: Featured Image -->
            <details class="wp4shpy-troubleshooting" style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #826200; outline: none;">
                    <span class="dashicons dashicons-editor-help" style="color: #dba617; vertical-align: middle;"></span>
                    <?php esc_html_e('Featured image not showing on Shopify?', 'wp4shpy'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dba617;">
                    <p style="margin-top: 0;">
                        <?php esc_html_e('If the featured image is visible in Shopify Admin but not on the blog page, your Shopify theme template needs to be updated.', 'wp4shpy'); ?>
                    </p>

                    <h5 style="margin: 15px 0 10px;"><?php esc_html_e('Solution: Add this code to your Shopify theme', 'wp4shpy'); ?></h5>
                    <p><?php esc_html_e('In Shopify Admin, go to:', 'wp4shpy'); ?> <strong>Online Store → Themes → Edit code</strong></p>
                    <p><?php esc_html_e('Find your article template (e.g.', 'wp4shpy'); ?> <code>sections/main-article.liquid</code> <?php esc_html_e('or', 'wp4shpy'); ?> <code>templates/article.liquid</code><?php esc_html_e(') and add:', 'wp4shpy'); ?></p>

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
                            <?php esc_html_e('Pro tip:', 'wp4shpy'); ?>
                        </strong>
                        <span style="color: #555;">
                            <?php esc_html_e('You can also use our ready-made template from', 'wp4shpy'); ?>
                            <code>packages/shopify-theme-example/sections/wp-article-content.liquid</code>
                        </span>
                    </div>
                </div>
            </details>

            <!-- Troubleshooting: Content Images -->
            <details class="wp4shpy-troubleshooting" style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <summary style="cursor: pointer; font-weight: 600; color: #826200; outline: none;">
                    <span class="dashicons dashicons-editor-help" style="color: #dba617; vertical-align: middle;"></span>
                    <?php esc_html_e('Images in post content not showing?', 'wp4shpy'); ?>
                </summary>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dba617;">
                    <p style="margin-top: 0;"><?php esc_html_e('If images within the article body are not displaying:', 'wp4shpy'); ?></p>

                    <ol style="margin-left: 20px; line-height: 1.8;">
                        <li>
                            <strong><?php esc_html_e('Check if images are synced:', 'wp4shpy'); ?></strong>
                            <?php esc_html_e('Go to Media Library and verify images have a green checkmark (synced) icon.', 'wp4shpy'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Sync images first:', 'wp4shpy'); ?></strong>
                            <?php esc_html_e('Click on an image and use "Sync to Shopify" button, then re-sync the post.', 'wp4shpy'); ?>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Check WordPress debug.log:', 'wp4shpy'); ?></strong>
                            <?php esc_html_e('Look for lines with [wp4shpy] to see sync status and any errors.', 'wp4shpy'); ?>
                        </li>
                    </ol>

                    <div style="background: #f0f6fc; border: 1px solid #c5d9ed; border-radius: 4px; padding: 12px; margin-top: 15px;">
                        <strong style="color: #2271b1;">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('Note:', 'wp4shpy'); ?>
                        </strong>
                        <span style="color: #555;">
                            <?php esc_html_e('Images are automatically synced when publishing a post. The sync rewrites WordPress URLs to Shopify CDN URLs in the content.', 'wp4shpy'); ?>
                        </span>
                    </div>
                </div>
            </details>
        </div>

        <!-- Sync Tab -->
        <div class="wp4shpy-tab-content" id="tab-sync" style="display: none;">
            <h2 class="title"><?php esc_html_e('Sync Preferences', 'wp4shpy'); ?></h2>
            <p class="description"><?php esc_html_e('Configure how content is synchronized to Shopify.', 'wp4shpy'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Auto-sync on Publish', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_sync_on_publish" value="1"
                                <?php checked(get_option('wp4shpy_sync_on_publish'), '1'); ?> />
                            <?php esc_html_e('Automatically sync posts when published or updated', 'wp4shpy'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Images', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_sync_images" value="1"
                                <?php checked(get_option('wp4shpy_sync_images'), '1'); ?> />
                            <?php esc_html_e('Upload media attachments to Shopify CDN', 'wp4shpy'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync Metafields', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_sync_metafields" value="1"
                                <?php checked(get_option('wp4shpy_sync_metafields'), '1'); ?> />
                            <?php esc_html_e('Sync ACF fields and custom meta to Shopify metafields', 'wp4shpy'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Rewrite Internal URLs', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_rewrite_internal_urls" value="1"
                                <?php checked(get_option('wp4shpy_rewrite_internal_urls', '1'), '1'); ?> />
                            <?php esc_html_e('Convert internal WordPress links to Shopify URL format', 'wp4shpy'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Rewrites links like /blog/post-slug to /blogs/{blog-handle}/post-slug and /page-slug to /pages/page-slug during sync.', 'wp4shpy'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp4shpy_post_types"><?php esc_html_e('Post Types', 'wp4shpy'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="wp4shpy_post_types" name="wp4shpy_post_types"
                               value="<?php echo esc_attr(get_option('wp4shpy_post_types', 'post')); ?>"
                               class="regular-text" />
                        <p class="description"><?php esc_html_e('Comma-separated list of post types to sync (e.g. post,page,custom_type)', 'wp4shpy'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Advanced Tab -->
        <div class="wp4shpy-tab-content" id="tab-advanced" style="display: none;">
            <h2 class="title"><?php esc_html_e('Advanced Settings', 'wp4shpy'); ?></h2>
            <p class="description"><?php esc_html_e('Additional configuration options.', 'wp4shpy'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Block Search Engines', 'wp4shpy'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp4shpy_block_robots" value="1"
                                <?php checked(get_option('wp4shpy_block_robots', '1'), '1'); ?> />
                            <?php esc_html_e('Discourage search engines from indexing this site (robots.txt)', 'wp4shpy'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Recommended for demo/staging sites. Outputs "Disallow: /" in robots.txt.', 'wp4shpy'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<script>
(function() {
    var currentTabInput = document.getElementById('wp4shpy-current-tab');

    // Tab switching
    document.querySelectorAll('.wp4shpy-tabs .nav-tab').forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var tabId = this.getAttribute('data-tab');

            document.querySelectorAll('.wp4shpy-tabs .nav-tab').forEach(function(t) {
                t.classList.remove('nav-tab-active');
            });
            this.classList.add('nav-tab-active');

            document.querySelectorAll('.wp4shpy-tab-content').forEach(function(content) {
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
        document.querySelectorAll('.wp4shpy-tabs .nav-tab').forEach(function(t) {
            t.classList.remove('nav-tab-active');
        });
        document.querySelector('.wp4shpy-tabs .nav-tab[data-tab="' + tabParam + '"]').classList.add('nav-tab-active');
        document.querySelectorAll('.wp4shpy-tab-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.getElementById('tab-' + tabParam).style.display = 'block';
        if (currentTabInput) currentTabInput.value = tabParam;
    } else if (hash && document.getElementById('tab-' + hash)) {
        document.querySelectorAll('.wp4shpy-tabs .nav-tab').forEach(function(t) {
            t.classList.remove('nav-tab-active');
        });
        document.querySelector('.wp4shpy-tabs .nav-tab[data-tab="' + hash + '"]').classList.add('nav-tab-active');
        document.querySelectorAll('.wp4shpy-tab-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.getElementById('tab-' + hash).style.display = 'block';
    }

    // Show/hide manual config
    var showManual = document.getElementById('wp4shpy-show-manual');
    var hideManual = document.getElementById('wp4shpy-hide-manual');
    var manualConfig = document.getElementById('wp4shpy-manual-config');

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
    var storeInput = document.getElementById('wp4shpy_shopify_store');
    var connectBtn = document.getElementById('wp4shpy-connect-btn');

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
            var returnUrl = encodeURIComponent(window.location.href.split('?')[0] + '?page=wp4shpy&wp4shpy_callback=1');
            var siteUrl = encodeURIComponent(window.location.protocol + '//' + window.location.host);
            var siteName = encodeURIComponent('<?php echo esc_js(get_bloginfo('name')); ?>');

            // Redirect to Shopify Admin -> App -> Connections page
            var connectUrl = 'https://admin.shopify.com/store/' + store + '/apps/wp4shpy/app/connections' +
                '?action=connect' +
                '&return_url=' + returnUrl +
                '&site_url=' + siteUrl +
                '&site_name=' + siteName;

            window.location.href = connectUrl;
        });
    }

    // Disconnect button
    var disconnectBtn = document.getElementById('wp4shpy-disconnect-btn');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', function() {
            if (confirm('<?php echo esc_js(__('Are you sure you want to disconnect from Shopify?', 'wp4shpy')); ?>')) {
                // Clear credentials via AJAX
                var formData = new FormData();
                formData.append('action', 'wp4shpy_disconnect');
                formData.append('nonce', '<?php echo wp_create_nonce('wp4shpy_disconnect'); ?>');

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
    var mappingsList = document.getElementById('wp4shpy-mappings-list');
    var mappingForm = document.getElementById('wp4shpy-mapping-form');
    var addMappingBtn = document.getElementById('wp4shpy-add-mapping');
    var saveMappingBtn = document.getElementById('wp4shpy-save-mapping');
    var cancelMappingBtn = document.getElementById('wp4shpy-cancel-mapping');
    var ruleTypeSelect = document.getElementById('wp4shpy-rule-type');
    var ruleValueRow = document.getElementById('wp4shpy-rule-value-row');
    var shopifyBlogSelect = document.getElementById('wp4shpy-shopify-blog');
    var newBlogRow = document.getElementById('wp4shpy-new-blog-row');

    var mappingsData = [];
    var shopifyBlogs = [];

    // Load mappings on page load (only if connected)
    if (mappingsList && <?php echo $isConnected ? 'true' : 'false'; ?>) {
        loadMappings();
        loadShopifyBlogs();
    }

    function loadMappings() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wp4shpy_get_mappings&nonce=<?php echo wp_create_nonce('wp4shpy_mappings'); ?>')
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
                mappingsList.innerHTML = '<p class="notice notice-error">Failed to load mappings</p>';
            });
    }

    function loadShopifyBlogs() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wp4shpy_get_shopify_blogs&nonce=<?php echo wp_create_nonce('wp4shpy_mappings'); ?>')
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
        shopifyBlogSelect.innerHTML = '<option value=""><?php echo esc_js(__('Select a blog...', 'wp4shpy')); ?></option>';
        shopifyBlogs.forEach(function(blog) {
            var opt = document.createElement('option');
            opt.value = blog.id;
            opt.textContent = blog.title + ' (' + blog.handle + ')';
            shopifyBlogSelect.appendChild(opt);
        });
        var newOpt = document.createElement('option');
        newOpt.value = '_new';
        newOpt.textContent = '➕ <?php echo esc_js(__('Create new blog', 'wp4shpy')); ?>';
        shopifyBlogSelect.appendChild(newOpt);
        shopifyBlogSelect.value = currentVal;
    }

    function renderMappings() {
        if (!mappingsList) return;
        if (mappingsData.length === 0) {
            mappingsList.innerHTML = '<p style="color: #666;"><?php echo esc_js(__('No mapping rules configured. Posts will sync to the default Shopify blog.', 'wp4shpy')); ?></p>';
            return;
        }

        var html = '<table class="widefat striped"><thead><tr>' +
            '<th><?php echo esc_js(__('Rule', 'wp4shpy')); ?></th>' +
            '<th><?php echo esc_js(__('Target Blog', 'wp4shpy')); ?></th>' +
            '<th><?php echo esc_js(__('Priority', 'wp4shpy')); ?></th>' +
            '<th><?php echo esc_js(__('Actions', 'wp4shpy')); ?></th>' +
            '</tr></thead><tbody>';

        mappingsData.forEach(function(m) {
            var ruleLabel = m.ruleType === 'default' ? '<?php echo esc_js(__('Default', 'wp4shpy')); ?>' :
                (m.ruleType === 'language' ? '<?php echo esc_js(__('Language', 'wp4shpy')); ?>: ' : '<?php echo esc_js(__('Category', 'wp4shpy')); ?>: ') + (m.ruleValue || '');
            var badgeClass = m.ruleType === 'default' ? 'background:#28a745;' : (m.ruleType === 'language' ? 'background:#007bff;' : 'background:#fd7e14;');

            html += '<tr>' +
                '<td><span style="' + badgeClass + 'color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">' + ruleLabel + '</span></td>' +
                '<td>' + (m.shopifyBlogHandle || m.shopifyBlogId) + '</td>' +
                '<td>' + m.priority + '</td>' +
                '<td>' +
                '<button type="button" class="button button-small wp4shpy-edit-mapping" data-id="' + m.id + '">Edit</button> ' +
                '<button type="button" class="button button-small wp4shpy-delete-mapping" data-id="' + m.id + '" style="color:#dc3545;">Delete</button>' +
                '</td></tr>';
        });

        html += '</tbody></table>';
        mappingsList.innerHTML = html;

        // Attach event listeners
        mappingsList.querySelectorAll('.wp4shpy-edit-mapping').forEach(function(btn) {
            btn.addEventListener('click', function() {
                editMapping(this.getAttribute('data-id'));
            });
        });
        mappingsList.querySelectorAll('.wp4shpy-delete-mapping').forEach(function(btn) {
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
            document.getElementById('wp4shpy-mapping-id').value = '';
            document.getElementById('wp4shpy-mapping-form-title').textContent = '<?php echo esc_js(__('Add Mapping Rule', 'wp4shpy')); ?>';
            ruleTypeSelect.value = 'default';
            document.getElementById('wp4shpy-rule-value').value = '';
            shopifyBlogSelect.value = '';
            document.getElementById('wp4shpy-priority').value = '0';
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
            var mappingId = document.getElementById('wp4shpy-mapping-id').value;
            var data = {
                action: mappingId ? 'wp4shpy_update_mapping' : 'wp4shpy_create_mapping',
                nonce: '<?php echo wp_create_nonce('wp4shpy_mappings'); ?>',
                mappingId: mappingId,
                ruleType: ruleTypeSelect.value,
                ruleValue: document.getElementById('wp4shpy-rule-value').value,
                shopifyBlogId: shopifyBlogSelect.value,
                newBlogName: document.getElementById('wp4shpy-new-blog-name').value,
                priority: document.getElementById('wp4shpy-priority').value
            };

            saveMappingBtn.disabled = true;
            saveMappingBtn.textContent = '<?php echo esc_js(__('Saving...', 'wp4shpy')); ?>';

            var formData = new FormData();
            Object.keys(data).forEach(function(key) { formData.append(key, data[key]); });

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(response) {
                saveMappingBtn.disabled = false;
                saveMappingBtn.textContent = '<?php echo esc_js(__('Save Mapping', 'wp4shpy')); ?>';
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
                saveMappingBtn.textContent = '<?php echo esc_js(__('Save Mapping', 'wp4shpy')); ?>';
                alert('Failed to save mapping');
            });
        });
    }

    function editMapping(id) {
        var mapping = mappingsData.find(function(m) { return m.id === id; });
        if (!mapping) return;

        document.getElementById('wp4shpy-mapping-id').value = mapping.id;
        document.getElementById('wp4shpy-mapping-form-title').textContent = '<?php echo esc_js(__('Edit Mapping Rule', 'wp4shpy')); ?>';
        ruleTypeSelect.value = mapping.ruleType;
        document.getElementById('wp4shpy-rule-value').value = mapping.ruleValue || '';
        shopifyBlogSelect.value = mapping.shopifyBlogId;
        document.getElementById('wp4shpy-priority').value = mapping.priority;
        ruleValueRow.style.display = mapping.ruleType === 'default' ? 'none' : '';
        newBlogRow.style.display = 'none';
        mappingForm.style.display = 'block';
    }

    function deleteMapping(id) {
        if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this mapping?', 'wp4shpy')); ?>')) return;

        var formData = new FormData();
        formData.append('action', 'wp4shpy_delete_mapping');
        formData.append('nonce', '<?php echo wp_create_nonce('wp4shpy_mappings'); ?>');
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
    var authorsEnabledCheckbox = document.getElementById('wp4shpy-authors-enabled');
    var authorMappingsSection = document.getElementById('wp4shpy-author-mappings-section');

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
    var filesEnabledCheckbox = document.getElementById('wp4shpy-files-enabled');
    var webpRow = document.getElementById('wp4shpy-webp-row');
    var fileTypesRow = document.getElementById('wp4shpy-file-types-row');

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
