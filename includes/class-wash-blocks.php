<?php
/**
 * Gutenberg blocks parser and metadata extractor.
 * Parses blocks BEFORE apply_filters('the_content') to extract metadata
 * and optionally modify blocks before rendering.
 */
class WashBlocks {

    /** @var WashApiClient */
    private $apiClient;

    /** @var array Extracted block metadata */
    private $extractedMeta = [];

    /** @var array Block CSS collected during parsing */
    private $collectedCss = [];

    public function __construct() {
        $this->apiClient = new WashApiClient();
    }

    /**
     * Register WordPress hooks for block processing.
     */
    public function init() {
        // High priority to run before the_content
        add_filter('wash_before_sync_content', [$this, 'processBlocksBeforeSync'], 5, 2);

        // Register custom Wash blocks
        add_action('init', [$this, 'registerCustomBlocks']);

        // Enqueue block editor assets
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
    }

    /**
     * Parse blocks and extract metadata before sync.
     * Called from WashSync::buildPostData() via filter.
     *
     * @param string $content Raw post content (Gutenberg blocks)
     * @param int    $postId  WordPress post ID
     * @return array ['content' => string, 'blocksMeta' => array, 'blocksCss' => string]
     */
    public function processBlocksBeforeSync($content, $postId) {
        if (!function_exists('has_blocks') || !has_blocks($content)) {
            return [
                'content'    => $content,
                'blocksMeta' => [],
                'blocksCss'  => '',
            ];
        }

        $blocks = parse_blocks($content);
        $this->extractedMeta = [];
        $this->collectedCss = [];

        // Extract metadata from all blocks (including nested)
        $this->extractBlockMetadata($blocks);

        // Collect block-specific CSS
        $blocksCss = $this->getBlocksCss($blocks);

        // Optionally modify blocks before serialize
        $modifiedBlocks = $this->modifyBlocksForShopify($blocks, $postId);

        // Serialize back to content
        $serialized = serialize_blocks($modifiedBlocks);

        return [
            'content'    => $serialized,
            'blocksMeta' => $this->extractedMeta,
            'blocksCss'  => $blocksCss,
        ];
    }

    /**
     * Extract metadata from blocks recursively.
     *
     * @param array $blocks Parsed blocks array
     * @param int   $depth  Current nesting depth
     */
    private function extractBlockMetadata(array $blocks, int $depth = 0) {
        foreach ($blocks as $index => $block) {
            if (empty($block['blockName'])) {
                continue; // Skip empty blocks
            }

            $meta = [
                'name'   => $block['blockName'],
                'attrs'  => $block['attrs'] ?? [],
                'depth'  => $depth,
                'index'  => $index,
            ];

            // Extract specific metadata based on block type
            $meta = $this->enrichBlockMeta($block, $meta);

            $this->extractedMeta[] = $meta;

            // Process inner blocks recursively
            if (!empty($block['innerBlocks'])) {
                $this->extractBlockMetadata($block['innerBlocks'], $depth + 1);
            }
        }
    }

    /**
     * Enrich block metadata based on block type.
     *
     * @param array $block Block data
     * @param array $meta  Base metadata
     * @return array Enriched metadata
     */
    private function enrichBlockMeta(array $block, array $meta): array {
        switch ($block['blockName']) {
            case 'core/image':
                if (!empty($block['attrs']['id'])) {
                    $meta['imageId'] = $block['attrs']['id'];
                    $meta['imageUrl'] = wp_get_attachment_url($block['attrs']['id']);
                    $meta['imageAlt'] = $block['attrs']['alt'] ?? '';
                }
                break;

            case 'core/gallery':
                $meta['imageIds'] = $block['attrs']['ids'] ?? [];
                $meta['columns'] = $block['attrs']['columns'] ?? 3;
                break;

            case 'core/embed':
                $meta['embedUrl'] = $block['attrs']['url'] ?? '';
                $meta['embedProvider'] = $block['attrs']['providerNameSlug'] ?? '';
                $meta['embedType'] = $block['attrs']['type'] ?? '';
                break;

            case 'core/heading':
                $meta['level'] = $block['attrs']['level'] ?? 2;
                $meta['textAlign'] = $block['attrs']['textAlign'] ?? '';
                break;

            case 'core/paragraph':
                $meta['textAlign'] = $block['attrs']['align'] ?? '';
                $meta['fontSize'] = $block['attrs']['fontSize'] ?? '';
                break;

            case 'core/buttons':
            case 'core/button':
                $meta['buttonStyle'] = $block['attrs']['className'] ?? '';
                $meta['buttonUrl'] = $block['attrs']['url'] ?? '';
                break;

            case 'core/columns':
                $meta['columnsCount'] = count($block['innerBlocks'] ?? []);
                break;

            case 'core/cover':
                $meta['coverId'] = $block['attrs']['id'] ?? null;
                $meta['coverUrl'] = $block['attrs']['url'] ?? '';
                $meta['overlayColor'] = $block['attrs']['overlayColor'] ?? '';
                break;

            case 'core/table':
                $meta['hasFixedLayout'] = $block['attrs']['hasFixedLayout'] ?? false;
                break;

            // Custom Wash blocks
            case 'wash/shopify-product':
                $meta['shopifyProductId'] = $block['attrs']['productId'] ?? '';
                $meta['shopifyProductHandle'] = $block['attrs']['handle'] ?? '';
                $meta['displayType'] = $block['attrs']['displayType'] ?? 'card';
                break;

            case 'wash/shopify-collection':
                $meta['shopifyCollectionId'] = $block['attrs']['collectionId'] ?? '';
                $meta['shopifyCollectionHandle'] = $block['attrs']['handle'] ?? '';
                $meta['limit'] = $block['attrs']['limit'] ?? 4;
                break;
        }

        return $meta;
    }

    /**
     * Modify blocks for Shopify compatibility.
     *
     * @param array $blocks  Parsed blocks
     * @param int   $postId  WordPress post ID
     * @return array Modified blocks
     */
    private function modifyBlocksForShopify(array $blocks, int $postId): array {
        $modified = [];

        foreach ($blocks as $block) {
            // Handle custom Wash blocks
            if (str_starts_with($block['blockName'] ?? '', 'wash/')) {
                $block = $this->renderWashBlock($block);
            }

            // Process inner blocks recursively
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->modifyBlocksForShopify($block['innerBlocks'], $postId);
            }

            $modified[] = $block;
        }

        return $modified;
    }

    /**
     * Render custom Wash block to Shopify-compatible HTML.
     *
     * @param array $block Block data
     * @return array Modified block with innerHTML
     */
    private function renderWashBlock(array $block): array {
        switch ($block['blockName']) {
            case 'wash/shopify-product':
                $block['innerHTML'] = $this->renderShopifyProductBlock($block['attrs']);
                $block['innerContent'] = [$block['innerHTML']];
                break;

            case 'wash/shopify-collection':
                $block['innerHTML'] = $this->renderShopifyCollectionBlock($block['attrs']);
                $block['innerContent'] = [$block['innerHTML']];
                break;

            case 'wash/sync-status':
                // Remove sync-status block from output (editor-only)
                $block['innerHTML'] = '';
                $block['innerContent'] = [];
                break;
        }

        return $block;
    }

    /**
     * Render Shopify Product embed block.
     *
     * @param array $attrs Block attributes
     * @return string HTML output with Liquid code
     */
    private function renderShopifyProductBlock(array $attrs): string {
        $handle = $attrs['handle'] ?? '';
        $displayType = $attrs['displayType'] ?? 'card';
        $showPrice = $attrs['showPrice'] ?? true;
        $showAddToCart = $attrs['showAddToCart'] ?? true;

        if (empty($handle)) {
            return '<!-- Wash: No product selected -->';
        }

        // Output Liquid code for Shopify
        $liquid = sprintf(
            '<div class="wash-shopify-product wash-product--%s" data-product-handle="%s">',
            esc_attr($displayType),
            esc_attr($handle)
        );

        $liquid .= "\n{% assign wash_product = all_products['" . esc_attr($handle) . "'] %}";
        $liquid .= "\n{% if wash_product %}";
        $liquid .= "\n  {% render 'product-card', product: wash_product";

        if (!$showPrice) {
            $liquid .= ", show_price: false";
        }
        if (!$showAddToCart) {
            $liquid .= ", show_add_to_cart: false";
        }

        $liquid .= " %}";
        $liquid .= "\n{% else %}";
        $liquid .= "\n  <p class=\"wash-product-not-found\">Product not found: " . esc_html($handle) . "</p>";
        $liquid .= "\n{% endif %}";
        $liquid .= "\n</div>";

        return $liquid;
    }

    /**
     * Render Shopify Collection embed block.
     *
     * @param array $attrs Block attributes
     * @return string HTML output with Liquid code
     */
    private function renderShopifyCollectionBlock(array $attrs): string {
        $handle = $attrs['handle'] ?? '';
        $limit = intval($attrs['limit'] ?? 4);
        $columns = intval($attrs['columns'] ?? 4);

        if (empty($handle)) {
            return '<!-- Wash: No collection selected -->';
        }

        $liquid = sprintf(
            '<div class="wash-shopify-collection wash-columns--%d" data-collection-handle="%s">',
            $columns,
            esc_attr($handle)
        );

        $liquid .= "\n{% assign wash_collection = collections['" . esc_attr($handle) . "'] %}";
        $liquid .= "\n{% if wash_collection %}";
        $liquid .= "\n  {% for product in wash_collection.products limit: " . $limit . " %}";
        $liquid .= "\n    <div class=\"wash-collection-item\">";
        $liquid .= "\n      {% render 'product-card', product: product %}";
        $liquid .= "\n    </div>";
        $liquid .= "\n  {% endfor %}";
        $liquid .= "\n{% else %}";
        $liquid .= "\n  <p class=\"wash-collection-not-found\">Collection not found: " . esc_html($handle) . "</p>";
        $liquid .= "\n{% endif %}";
        $liquid .= "\n</div>";

        return $liquid;
    }

    /**
     * Collect CSS for blocks used in content.
     *
     * @param array $blocks Parsed blocks
     * @return string Combined CSS
     */
    private function getBlocksCss(array $blocks): string {
        $css = [];
        $blockTypes = $this->getUniqueBlockTypes($blocks);

        // Get theme.json custom CSS if available
        if (function_exists('wp_get_global_styles_custom_css')) {
            $customCss = wp_get_global_styles_custom_css();
            if ($customCss) {
                $css[] = "/* Theme Custom CSS */\n" . $customCss;
            }
        }

        // Get block-specific styles for core blocks
        foreach ($blockTypes as $blockType) {
            $blockCss = $this->getBlockStylesheet($blockType);
            if ($blockCss) {
                $css[] = "/* Block: {$blockType} */\n" . $blockCss;
            }
        }

        // Add Wash block styles
        $css[] = $this->getWashBlocksCss();

        // Add common WordPress block styles
        $css[] = $this->getCoreBlocksCss();

        return implode("\n\n", array_filter($css));
    }

    /**
     * Get unique block types from blocks array.
     *
     * @param array $blocks Parsed blocks
     * @return array Unique block type names
     */
    private function getUniqueBlockTypes(array $blocks): array {
        $types = [];

        foreach ($blocks as $block) {
            if (!empty($block['blockName'])) {
                $types[] = $block['blockName'];
            }
            if (!empty($block['innerBlocks'])) {
                $types = array_merge($types, $this->getUniqueBlockTypes($block['innerBlocks']));
            }
        }

        return array_unique($types);
    }

    /**
     * Get CSS for a specific block type.
     *
     * @param string $blockType Block type name
     * @return string|null CSS content or null
     */
    private function getBlockStylesheet(string $blockType): ?string {
        // Core blocks use wp-includes/blocks/{name}/style.css
        if (str_starts_with($blockType, 'core/')) {
            $blockName = str_replace('core/', '', $blockType);
            $stylePath = ABSPATH . "wp-includes/blocks/{$blockName}/style.css";

            if (file_exists($stylePath)) {
                return file_get_contents($stylePath);
            }
        }

        return null;
    }

    /**
     * Get CSS for custom Wash blocks.
     *
     * @return string CSS content
     */
    private function getWashBlocksCss(): string {
        return '
/* Wash Shopify Product Embed */
.wash-shopify-product {
    margin: 1.5em 0;
}
.wash-product--card {
    max-width: 400px;
}
.wash-product--full {
    max-width: 100%;
}
.wash-product-not-found {
    padding: 1em;
    background: #f8f8f8;
    border: 1px solid #ddd;
    color: #666;
    font-style: italic;
}

/* Wash Shopify Collection Grid */
.wash-shopify-collection {
    display: grid;
    gap: 1.5rem;
    margin: 1.5em 0;
}
.wash-columns--2 { grid-template-columns: repeat(2, 1fr); }
.wash-columns--3 { grid-template-columns: repeat(3, 1fr); }
.wash-columns--4 { grid-template-columns: repeat(4, 1fr); }
.wash-columns--5 { grid-template-columns: repeat(5, 1fr); }
.wash-columns--6 { grid-template-columns: repeat(6, 1fr); }

.wash-collection-item {
    min-width: 0;
}
.wash-collection-not-found {
    grid-column: 1 / -1;
    padding: 1em;
    background: #f8f8f8;
    border: 1px solid #ddd;
    color: #666;
    font-style: italic;
}

@media (max-width: 1024px) {
    .wash-columns--5,
    .wash-columns--6 {
        grid-template-columns: repeat(4, 1fr);
    }
}
@media (max-width: 768px) {
    .wash-shopify-collection {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .wash-shopify-collection {
        grid-template-columns: 1fr;
    }
}
';
    }

    /**
     * Get common WordPress core block CSS.
     *
     * @return string CSS content
     */
    private function getCoreBlocksCss(): string {
        return '
/* WordPress Core Block Styles for Shopify */

/* Typography */
.has-small-font-size { font-size: 0.875rem; }
.has-normal-font-size { font-size: 1rem; }
.has-medium-font-size { font-size: 1.25rem; }
.has-large-font-size { font-size: 1.5rem; }
.has-x-large-font-size { font-size: 2rem; }
.has-xx-large-font-size { font-size: 2.5rem; }

/* Alignment */
.has-text-align-left { text-align: left; }
.has-text-align-center { text-align: center; }
.has-text-align-right { text-align: right; }
.has-text-align-justify { text-align: justify; }

/* Columns */
.wp-block-columns {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
}
.wp-block-column {
    flex: 1;
    min-width: 200px;
}

/* Buttons */
.wp-block-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.wp-block-button__link {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    background: var(--wp--preset--color--primary, #000);
    color: #fff;
    text-decoration: none;
    border-radius: 4px;
    transition: opacity 0.2s;
}
.wp-block-button__link:hover {
    opacity: 0.85;
}
.wp-block-button.is-style-outline .wp-block-button__link {
    background: transparent;
    border: 2px solid currentColor;
    color: var(--wp--preset--color--primary, #000);
}

/* Quote */
.wp-block-quote {
    border-left: 4px solid var(--wp--preset--color--primary, #000);
    padding-left: 1rem;
    margin-left: 0;
    font-style: italic;
}
.wp-block-quote cite {
    display: block;
    margin-top: 0.5rem;
    font-size: 0.875rem;
    font-style: normal;
}

/* Pullquote */
.wp-block-pullquote {
    border-top: 4px solid;
    border-bottom: 4px solid;
    padding: 2rem 0;
    text-align: center;
    font-size: 1.5rem;
}

/* Separator */
.wp-block-separator {
    border: none;
    border-top: 2px solid #e0e0e0;
    margin: 2rem 0;
}
.wp-block-separator.is-style-wide {
    width: 100%;
}
.wp-block-separator.is-style-dots {
    border: none;
    text-align: center;
}
.wp-block-separator.is-style-dots::before {
    content: "···";
    font-size: 1.5rem;
    letter-spacing: 1rem;
}

/* Cover */
.wp-block-cover {
    position: relative;
    background-size: cover;
    background-position: center;
    min-height: 300px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}
.wp-block-cover__inner-container {
    position: relative;
    z-index: 1;
    max-width: 800px;
}

/* Media & Text */
.wp-block-media-text {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: center;
}
.wp-block-media-text.has-media-on-the-right {
    direction: rtl;
}
.wp-block-media-text.has-media-on-the-right > * {
    direction: ltr;
}
@media (max-width: 768px) {
    .wp-block-media-text {
        grid-template-columns: 1fr;
    }
    .wp-block-media-text.is-stacked-on-mobile {
        grid-template-columns: 1fr;
    }
}

/* Gallery */
.wp-block-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}
.wp-block-gallery.columns-2 { grid-template-columns: repeat(2, 1fr); }
.wp-block-gallery.columns-3 { grid-template-columns: repeat(3, 1fr); }
.wp-block-gallery.columns-4 { grid-template-columns: repeat(4, 1fr); }
.wp-block-gallery .wp-block-image {
    margin: 0;
}

/* Table */
.wp-block-table table {
    width: 100%;
    border-collapse: collapse;
}
.wp-block-table th,
.wp-block-table td {
    border: 1px solid #e0e0e0;
    padding: 0.75rem;
}
.wp-block-table.is-style-stripes tbody tr:nth-child(odd) {
    background: #f9f9f9;
}

/* Code */
.wp-block-code {
    background: #f4f4f4;
    padding: 1rem;
    border-radius: 4px;
    overflow-x: auto;
    font-family: monospace;
}
.wp-block-code code {
    background: none;
    padding: 0;
}

/* Preformatted */
.wp-block-preformatted {
    white-space: pre-wrap;
    font-family: monospace;
}

/* Group */
.wp-block-group {
    padding: var(--wp--style--block-gap, 1.5rem);
}
.wp-block-group.has-background {
    padding: 1.5rem;
}

/* Image */
.wp-block-image {
    margin: 1.5em 0;
}
.wp-block-image img {
    max-width: 100%;
    height: auto;
}
.wp-block-image.aligncenter {
    text-align: center;
}
.wp-block-image.alignleft {
    float: left;
    margin-right: 1.5rem;
}
.wp-block-image.alignright {
    float: right;
    margin-left: 1.5rem;
}
.wp-block-image figcaption {
    margin-top: 0.5rem;
    font-size: 0.875rem;
    color: #666;
    text-align: center;
}

/* Details */
.wp-block-details {
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 1rem;
}
.wp-block-details summary {
    cursor: pointer;
    font-weight: 600;
}
.wp-block-details[open] summary {
    margin-bottom: 1rem;
}

/* Spacer */
.wp-block-spacer {
    clear: both;
}
';
    }

    /**
     * Register custom Wash Gutenberg blocks.
     */
    public function registerCustomBlocks() {
        // Check if blocks are enabled
        if (get_option('wash_blocks_enabled', '1') !== '1') {
            return;
        }

        $blocksDir = WASH_PLUGIN_DIR . 'blocks/';

        // Register Shopify Product block
        if (file_exists($blocksDir . 'shopify-product/block.json')) {
            register_block_type($blocksDir . 'shopify-product');
        }

        // Register Shopify Collection block
        if (file_exists($blocksDir . 'shopify-collection/block.json')) {
            register_block_type($blocksDir . 'shopify-collection');
        }

        // Register Sync Status block
        if (file_exists($blocksDir . 'sync-status/block.json')) {
            register_block_type($blocksDir . 'sync-status');
        }
    }

    /**
     * Enqueue block editor assets.
     */
    public function enqueueBlockEditorAssets() {
        // Check if blocks are enabled
        if (get_option('wash_blocks_enabled', '1') !== '1') {
            return;
        }

        // Pass data to block editor
        wp_localize_script('wash-blocks-editor', 'washBlocksData', [
            'apiUrl'    => get_option('wash_api_url', ''),
            'apiKey'    => get_option('wash_api_key', ''),
            'restUrl'   => rest_url('wash/v1/'),
            'nonce'     => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Build metafields from block metadata.
     * Called from WashMetafields to add block data as metafields.
     *
     * @param array $blocksMeta Extracted block metadata
     * @return array Metafields array
     */
    public static function buildBlockMetafields(array $blocksMeta): array {
        $metafields = [];

        if (empty($blocksMeta)) {
            return $metafields;
        }

        // Block structure as JSON
        $metafields[] = [
            'namespace' => 'wash_blocks',
            'key'       => 'structure',
            'value'     => wp_json_encode($blocksMeta),
            'type'      => 'json',
        ];

        // Count of blocks by type
        $blockCounts = [];
        foreach ($blocksMeta as $block) {
            $type = $block['name'] ?? 'unknown';
            $blockCounts[$type] = ($blockCounts[$type] ?? 0) + 1;
        }

        $metafields[] = [
            'namespace' => 'wash_blocks',
            'key'       => 'counts',
            'value'     => wp_json_encode($blockCounts),
            'type'      => 'json',
        ];

        // Total block count
        $metafields[] = [
            'namespace' => 'wash_blocks',
            'key'       => 'total_count',
            'value'     => strval(count($blocksMeta)),
            'type'      => 'number_integer',
        ];

        // Extract Shopify product/collection references
        $shopifyRefs = [
            'products'    => [],
            'collections' => [],
        ];
        foreach ($blocksMeta as $block) {
            if (!empty($block['shopifyProductHandle'])) {
                $shopifyRefs['products'][] = $block['shopifyProductHandle'];
            }
            if (!empty($block['shopifyCollectionHandle'])) {
                $shopifyRefs['collections'][] = $block['shopifyCollectionHandle'];
            }
        }

        // Remove duplicates
        $shopifyRefs['products'] = array_values(array_unique($shopifyRefs['products']));
        $shopifyRefs['collections'] = array_values(array_unique($shopifyRefs['collections']));

        if (!empty($shopifyRefs['products']) || !empty($shopifyRefs['collections'])) {
            $metafields[] = [
                'namespace' => 'wash_blocks',
                'key'       => 'shopify_refs',
                'value'     => wp_json_encode($shopifyRefs),
                'type'      => 'json',
            ];
        }

        // Extract heading structure for TOC
        $headings = [];
        foreach ($blocksMeta as $block) {
            if ($block['name'] === 'core/heading' && isset($block['level'])) {
                $headings[] = [
                    'level' => $block['level'],
                    'depth' => $block['depth'] ?? 0,
                ];
            }
        }

        if (!empty($headings)) {
            $metafields[] = [
                'namespace' => 'wash_blocks',
                'key'       => 'headings',
                'value'     => wp_json_encode($headings),
                'type'      => 'json',
            ];
        }

        // Extract image IDs for preloading
        $imageIds = [];
        foreach ($blocksMeta as $block) {
            if (!empty($block['imageId'])) {
                $imageIds[] = $block['imageId'];
            }
            if (!empty($block['imageIds'])) {
                $imageIds = array_merge($imageIds, $block['imageIds']);
            }
            if (!empty($block['coverId'])) {
                $imageIds[] = $block['coverId'];
            }
        }
        $imageIds = array_values(array_unique(array_filter($imageIds)));

        if (!empty($imageIds)) {
            $metafields[] = [
                'namespace' => 'wash_blocks',
                'key'       => 'image_ids',
                'value'     => wp_json_encode($imageIds),
                'type'      => 'json',
            ];
        }

        return $metafields;
    }

    /**
     * Get block counts for a post.
     *
     * @param int $postId WordPress post ID
     * @return array Block counts by type
     */
    public static function getBlockCounts(int $postId): array {
        $post = get_post($postId);
        if (!$post || !has_blocks($post->post_content)) {
            return [];
        }

        $blocks = parse_blocks($post->post_content);
        $counts = [];

        $countBlocks = function($blocks) use (&$countBlocks, &$counts) {
            foreach ($blocks as $block) {
                if (!empty($block['blockName'])) {
                    $counts[$block['blockName']] = ($counts[$block['blockName']] ?? 0) + 1;
                }
                if (!empty($block['innerBlocks'])) {
                    $countBlocks($block['innerBlocks']);
                }
            }
        };

        $countBlocks($blocks);
        return $counts;
    }

    /**
     * Check if post has Wash custom blocks.
     *
     * @param int $postId WordPress post ID
     * @return bool True if post contains Wash blocks
     */
    public static function hasWashBlocks(int $postId): bool {
        $counts = self::getBlockCounts($postId);

        foreach ($counts as $blockName => $count) {
            if (str_starts_with($blockName, 'wash/')) {
                return true;
            }
        }

        return false;
    }
}
