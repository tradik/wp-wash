<?php
/**
 * WordPress Multisite support.
 * Handles per-site blog mappings and network admin settings.
 */
class wp4shpyMultisite {

    /**
     * Initialize multisite hooks.
     */
    public function init() {
        add_action('network_admin_menu', [$this, 'addNetworkMenu']);
        add_action('wp_initialize_site', [$this, 'onSiteCreated'], 10, 1);
        add_action('wp_delete_site', [$this, 'onSiteDeleted'], 10, 1);
        add_filter('wp4shpy_sync_data', [$this, 'injectSiteData'], 10, 2);
    }

    /**
     * Add wp4shpy menu to network admin.
     */
    public function addNetworkMenu() {
        add_menu_page(
            __('wp4shpy Network', 'wp4shpy'),
            __('wp4shpy', 'wp4shpy'),
            'manage_network_options',
            'wp4shpy-network',
            [$this, 'renderNetworkSettingsPage'],
            'dashicons-update',
            80
        );
    }

    /**
     * When a new site is created in the network, register it for blog mapping.
     *
     * @param \WP_Site $newSite The newly created site
     */
    public function onSiteCreated($newSite) {
        $siteId = $newSite->blog_id;
        $blogName = get_blog_details($siteId)->blogname;

        $apiClient = new wp4shpyApiClient();
        $apiClient->post('/api/content-sync', [
            'action'     => 'register_blog',
            'wpSiteId'   => $siteId,
            'wpBlogName' => $blogName,
        ]);

        update_blog_option($siteId, 'wp4shpy_blog_name', $blogName);
    }

    /**
     * When a site is deleted, clean up the blog mapping.
     *
     * @param \WP_Site $oldSite The site being deleted
     */
    public function onSiteDeleted($oldSite) {
        // Blog mapping cleanup is handled on the Shopify App side
        // No local cleanup needed beyond WP's own option deletion
    }

    /**
     * Inject multisite-specific data into sync payload.
     *
     * @param array $data    Sync data payload
     * @param int   $postId  Post ID being synced
     * @return array Modified payload with site-specific data
     */
    public function injectSiteData($data, $postId) {
        $siteId = get_current_blog_id();
        $data['wpSiteId']   = $siteId;
        $data['wpBlogName'] = get_blog_option($siteId, 'wp4shpy_blog_name', get_bloginfo('name'));

        return $data;
    }

    /**
     * Get all sites in the network with their sync configuration.
     *
     * @return array List of sites with wp4shpy config
     */
    public function getNetworkSites() {
        $sites = get_sites(['number' => 100]);
        $siteData = [];

        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $siteData[] = [
                'siteId'    => $site->blog_id,
                'blogName'  => get_bloginfo('name'),
                'siteUrl'   => get_site_url(),
                'isActive'  => get_option('wp4shpy_sync_on_publish') === '1',
                'blogMapping' => get_option('wp4shpy_blog_name', ''),
            ];
            restore_current_blog();
        }

        return $siteData;
    }

    /**
     * Render the network admin settings page.
     */
    public function renderNetworkSettingsPage() {
        if (!current_user_can('manage_network_options')) {
            return;
        }

        $sites = $this->getNetworkSites();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('wp4shpy Network Settings', 'wp4shpy'); ?></h1>
            <p><?php esc_html_e('Manage blog-to-Shopify mappings for all sites in the network.', 'wp4shpy'); ?></p>

            <table class="widefat fixed striped" role="grid">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Site ID', 'wp4shpy'); ?></th>
                        <th><?php esc_html_e('Blog Name', 'wp4shpy'); ?></th>
                        <th><?php esc_html_e('URL', 'wp4shpy'); ?></th>
                        <th><?php esc_html_e('Sync Active', 'wp4shpy'); ?></th>
                        <th><?php esc_html_e('Shopify Blog', 'wp4shpy'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $site) : ?>
                    <tr>
                        <td><?php echo esc_html($site['siteId']); ?></td>
                        <td><?php echo esc_html($site['blogName']); ?></td>
                        <td><a href="<?php echo esc_url($site['siteUrl']); ?>"><?php echo esc_html($site['siteUrl']); ?></a></td>
                        <td>
                            <?php if ($site['isActive']) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color: #50B83C;"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-dismiss" style="color: #DE3618;"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($site['blogMapping'] ?: '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
