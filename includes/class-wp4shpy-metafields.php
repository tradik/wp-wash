<?php
/**
 * Metafields synchronization handler.
 * Sends WordPress custom fields, ACF fields, and SEO metadata
 * to Shopify as metafields on the corresponding article/page.
 * Supports automatic metafield definition creation.
 */
class wp4shpyMetafields {

    /** @var wp4shpyApiClient */
    private $apiClient;

    /** @var array ACF field type to Shopify metafield type mapping */
    private static $acfTypeMapping = [
        'text'           => 'single_line_text_field',
        'textarea'       => 'multi_line_text_field',
        'wysiwyg'        => 'multi_line_text_field',
        'number'         => 'number_decimal',
        'range'          => 'number_integer',
        'email'          => 'single_line_text_field',
        'url'            => 'url',
        'password'       => 'single_line_text_field',
        'image'          => 'url',
        'file'           => 'url',
        'select'         => 'single_line_text_field',
        'checkbox'       => 'json',
        'radio'          => 'single_line_text_field',
        'button_group'   => 'single_line_text_field',
        'true_false'     => 'boolean',
        'link'           => 'json',
        'post_object'    => 'number_integer',
        'page_link'      => 'url',
        'relationship'   => 'json',
        'taxonomy'       => 'json',
        'user'           => 'number_integer',
        'google_map'     => 'json',
        'date_picker'    => 'date',
        'date_time_picker' => 'date_time',
        'time_picker'    => 'single_line_text_field',
        'color_picker'   => 'color',
        'message'        => 'single_line_text_field',
        'accordion'      => 'json',
        'tab'            => 'json',
        'group'          => 'json',
        'repeater'       => 'json',
        'flexible_content' => 'json',
        'clone'          => 'json',
        'gallery'        => 'json',
        'oembed'         => 'url',
    ];

    /** @var array Namespace definitions */
    private static $namespaces = [
        'wordpress'  => 'wp4shopify',
        'acf'        => 'wp4shopify_acf',
        'seo'        => 'wp4shopify_seo',
        'custom'     => 'wp4shopify_custom',
    ];

    public function __construct() {
        $this->apiClient = new wp4shpyApiClient();
    }

    /**
     * Register hooks for metafield sync.
     */
    public function init() {
        if (get_option('wp4shpy_sync_metafields') !== '1') {
            return;
        }

        add_action('updated_post_meta', [$this, 'onMetaUpdate'], 10, 4);
        add_action('added_post_meta', [$this, 'onMetaAdd'], 10, 4);
        add_action('deleted_post_meta', [$this, 'onMetaDelete'], 10, 4);

        // ACF-specific hooks for better field type detection
        if (function_exists('acf_get_field')) {
            add_action('acf/save_post', [$this, 'onAcfSavePost'], 20);
        }
    }

    /**
     * Handle post meta update — sync changed metafield to Shopify.
     */
    public function onMetaUpdate($metaId, $postId, $metaKey, $metaValue) {
        $this->syncMetafield($postId, $metaKey, $metaValue);
    }

    /**
     * Handle post meta addition — sync new metafield to Shopify.
     */
    public function onMetaAdd($metaId, $postId, $metaKey, $metaValue) {
        $this->syncMetafield($postId, $metaKey, $metaValue);
    }

    /**
     * Handle post meta deletion — mark for deletion from Shopify.
     */
    public function onMetaDelete($metaId, $postId, $metaKey, $metaValue) {
        if ($this->shouldSkipMeta($metaKey)) {
            return;
        }

        $shopifyId = $this->getShopifyResourceId($postId);
        if (empty($shopifyId)) {
            return;
        }

        $namespace = $this->resolveNamespace($metaKey);
        $this->apiClient->post('/api/metafields', [
            'action'           => 'delete',
            'shopifyArticleId' => $shopifyId,
            'wpSiteId'         => is_multisite() ? get_current_blog_id() : 1,
            'metafieldKeys'    => [['namespace' => $namespace, 'key' => $this->sanitizeKey($metaKey)]],
        ]);
    }

    /**
     * Handle ACF save_post hook for better field type detection.
     */
    public function onAcfSavePost($postId) {
        if (get_option('wp4shpy_meta_acf') !== '1') {
            return;
        }

        $shopifyId = $this->getShopifyResourceId($postId);
        if (empty($shopifyId)) {
            return;
        }

        $this->syncAcfFields($postId, $shopifyId);
    }

    /**
     * Sync a single metafield to Shopify.
     */
    private function syncMetafield($postId, $metaKey, $metaValue) {
        if ($this->shouldSkipMeta($metaKey)) {
            return;
        }

        // Skip ACF fields here - they are handled by onAcfSavePost
        if ($this->isAcfField($metaKey)) {
            return;
        }

        $shopifyId = $this->getShopifyResourceId($postId);
        if (empty($shopifyId)) {
            return;
        }

        $namespace = $this->resolveNamespace($metaKey);
        $type = $this->inferType($metaValue);

        $this->apiClient->post('/api/metafields', [
            'action'           => 'upsert',
            'shopifyArticleId' => $shopifyId,
            'wpSiteId'         => is_multisite() ? get_current_blog_id() : 1,
            'metafields'       => [[
                'namespace' => $namespace,
                'key'       => $this->sanitizeKey($metaKey),
                'value'     => is_array($metaValue) ? wp_json_encode($metaValue) : (string) $metaValue,
                'type'      => $type,
            ]],
        ]);
    }

    /**
     * Sync all metafields for a post/page to Shopify.
     * Called during manual sync operations.
     *
     * @param int    $postId   WordPress post ID
     * @param string $shopifyId Shopify resource GID (article or page)
     * @return array|WP_Error Sync result
     */
    public function syncAllMetafields($postId, $shopifyId) {
        if (get_option('wp4shpy_sync_metafields') !== '1') {
            return ['skipped' => true, 'reason' => 'Metafields sync disabled'];
        }

        $metafields = [];

        // WordPress core meta
        if (get_option('wp4shpy_meta_wp_core', '1') === '1') {
            $metafields = array_merge($metafields, $this->buildWordPressMetafields($postId));
        }

        // ACF fields
        if (get_option('wp4shpy_meta_acf', '1') === '1' && function_exists('get_fields')) {
            $metafields = array_merge($metafields, $this->buildAcfMetafields($postId));
        }

        // SEO fields (Yoast/RankMath)
        if (get_option('wp4shpy_meta_yoast', '1') === '1' || get_option('wp4shpy_meta_rankmath', '1') === '1') {
            $metafields = array_merge($metafields, $this->buildSeoMetafields($postId));
        }

        // Custom fields (non-prefixed post meta)
        $metafields = array_merge($metafields, $this->buildCustomMetafields($postId));

        if (empty($metafields)) {
            return ['success' => true, 'count' => 0, 'message' => 'No metafields to sync'];
        }

        // First ensure definitions exist
        $definitionsResult = $this->ensureMetafieldDefinitions($metafields);
        error_log("[wp4shpy] Metafield definitions result: " . wp_json_encode($definitionsResult));

        // Then sync metafields
        $result = $this->apiClient->post('/api/metafields', [
            'action'           => 'upsert',
            'shopifyArticleId' => $shopifyId,
            'wpSiteId'         => is_multisite() ? get_current_blog_id() : 1,
            'metafields'       => $metafields,
        ]);

        if (is_wp_error($result)) {
            error_log("[wp4shpy] Metafields sync error: " . $result->get_error_message());
            return $result;
        }

        error_log("[wp4shpy] Metafields synced successfully. Count: " . count($metafields));
        return ['success' => true, 'count' => count($metafields), 'metafields' => $result['metafields'] ?? []];
    }

    /**
     * Build WordPress core metafields.
     */
    private function buildWordPressMetafields($postId) {
        $metafields = [];
        $post = get_post($postId);

        $metafields[] = [
            'namespace' => self::$namespaces['wordpress'],
            'key'       => 'wp_post_id',
            'value'     => (string) $postId,
            'type'      => 'number_integer',
        ];

        $metafields[] = [
            'namespace' => self::$namespaces['wordpress'],
            'key'       => 'wp_permalink',
            'value'     => get_permalink($postId),
            'type'      => 'url',
        ];

        $metafields[] = [
            'namespace' => self::$namespaces['wordpress'],
            'key'       => 'wp_post_type',
            'value'     => $post->post_type,
            'type'      => 'single_line_text_field',
        ];

        $metafields[] = [
            'namespace' => self::$namespaces['wordpress'],
            'key'       => 'wp_modified_date',
            'value'     => get_the_modified_date('Y-m-d', $postId),
            'type'      => 'date',
        ];

        // Featured image URL
        $thumbnailId = get_post_thumbnail_id($postId);
        if ($thumbnailId) {
            $imageUrl = wp_get_attachment_url($thumbnailId);
            if ($imageUrl) {
                $metafields[] = [
                    'namespace' => self::$namespaces['wordpress'],
                    'key'       => 'featured_image_url',
                    'value'     => $imageUrl,
                    'type'      => 'url',
                ];
            }
        }

        return $metafields;
    }

    /**
     * Build ACF metafields with proper type mapping.
     */
    private function buildAcfMetafields($postId) {
        $metafields = [];

        if (!function_exists('get_fields') || !function_exists('acf_get_field')) {
            return $metafields;
        }

        $fields = get_fields($postId);
        if (!$fields || !is_array($fields)) {
            return $metafields;
        }

        foreach ($fields as $fieldName => $fieldValue) {
            $fieldObject = acf_get_field($fieldName);
            if (!$fieldObject) {
                // Fallback: try to get field by selector
                $fieldObject = get_field_object($fieldName, $postId);
            }

            $metafield = $this->convertAcfFieldToMetafield($fieldName, $fieldValue, $fieldObject);
            if ($metafield) {
                $metafields[] = $metafield;
            }
        }

        return $metafields;
    }

    /**
     * Convert a single ACF field to Shopify metafield format.
     */
    private function convertAcfFieldToMetafield($fieldName, $fieldValue, $fieldObject = null) {
        if ($fieldValue === null || $fieldValue === '') {
            return null;
        }

        $acfType = $fieldObject['type'] ?? 'text';
        $shopifyType = self::$acfTypeMapping[$acfType] ?? 'single_line_text_field';

        // Special handling for complex field types
        $value = $this->formatAcfValue($fieldValue, $acfType);

        if ($value === null) {
            return null;
        }

        return [
            'namespace' => self::$namespaces['acf'],
            'key'       => $this->sanitizeKey($fieldName),
            'value'     => $value,
            'type'      => $shopifyType,
        ];
    }

    /**
     * Format ACF field value based on field type.
     */
    private function formatAcfValue($value, $acfType) {
        switch ($acfType) {
            case 'true_false':
                return $value ? 'true' : 'false';

            case 'image':
            case 'file':
                if (is_array($value) && isset($value['url'])) {
                    return $value['url'];
                }
                if (is_numeric($value)) {
                    return wp_get_attachment_url($value) ?: null;
                }
                return is_string($value) ? $value : null;

            case 'gallery':
                if (!is_array($value)) {
                    return null;
                }
                $urls = [];
                foreach ($value as $item) {
                    if (is_array($item) && isset($item['url'])) {
                        $urls[] = $item['url'];
                    } elseif (is_numeric($item)) {
                        $url = wp_get_attachment_url($item);
                        if ($url) $urls[] = $url;
                    }
                }
                return wp_json_encode($urls);

            case 'repeater':
            case 'flexible_content':
            case 'group':
                return wp_json_encode($this->flattenAcfComplexField($value));

            case 'link':
                if (is_array($value)) {
                    return wp_json_encode([
                        'url'    => $value['url'] ?? '',
                        'title'  => $value['title'] ?? '',
                        'target' => $value['target'] ?? '',
                    ]);
                }
                return is_string($value) ? $value : null;

            case 'relationship':
            case 'taxonomy':
                if (!is_array($value)) {
                    return null;
                }
                $ids = [];
                foreach ($value as $item) {
                    if (is_object($item)) {
                        $ids[] = $item->ID ?? $item->term_id ?? null;
                    } elseif (is_numeric($item)) {
                        $ids[] = (int) $item;
                    }
                }
                return wp_json_encode(array_filter($ids));

            case 'post_object':
            case 'user':
                if (is_object($value)) {
                    return (string) ($value->ID ?? '');
                }
                return is_numeric($value) ? (string) $value : null;

            case 'date_picker':
                // ACF returns Ymd format by default, convert to Y-m-d
                if (is_string($value) && strlen($value) === 8) {
                    return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
                }
                return $value;

            case 'date_time_picker':
                // Convert to ISO 8601 format
                $timestamp = strtotime($value);
                return $timestamp ? date('Y-m-d\TH:i:s', $timestamp) : null;

            case 'google_map':
                if (is_array($value)) {
                    return wp_json_encode([
                        'lat'     => $value['lat'] ?? null,
                        'lng'     => $value['lng'] ?? null,
                        'address' => $value['address'] ?? '',
                    ]);
                }
                return null;

            case 'checkbox':
                return is_array($value) ? wp_json_encode($value) : (string) $value;

            case 'number':
            case 'range':
                return is_numeric($value) ? (string) $value : null;

            case 'color_picker':
                // Ensure hex color format
                if (is_string($value) && preg_match('/^#?[a-fA-F0-9]{6}$/', $value)) {
                    return strpos($value, '#') === 0 ? $value : '#' . $value;
                }
                return null;

            default:
                if (is_array($value) || is_object($value)) {
                    return wp_json_encode($value);
                }
                return (string) $value;
        }
    }

    /**
     * Flatten complex ACF fields (repeaters, groups) for JSON storage.
     */
    private function flattenAcfComplexField($value, $depth = 0) {
        if ($depth > 5) {
            return $value; // Prevent infinite recursion
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $result[$key] = $this->flattenAcfComplexField($item, $depth + 1);
            } elseif (is_object($item)) {
                // Convert WP_Post or WP_Term objects to IDs
                $result[$key] = $item->ID ?? $item->term_id ?? (array) $item;
            } else {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    /**
     * Build SEO metafields from Yoast/RankMath.
     */
    private function buildSeoMetafields($postId) {
        $metafields = [];

        // Yoast SEO
        if (get_option('wp4shpy_meta_yoast', '1') === '1') {
            $yoastTitle = get_post_meta($postId, '_yoast_wpseo_title', true);
            $yoastDesc = get_post_meta($postId, '_yoast_wpseo_metadesc', true);
            $yoastFocusKw = get_post_meta($postId, '_yoast_wpseo_focuskw', true);

            if ($yoastTitle) {
                $metafields[] = [
                    'namespace' => self::$namespaces['seo'],
                    'key'       => 'meta_title',
                    'value'     => $yoastTitle,
                    'type'      => 'single_line_text_field',
                ];
            }
            if ($yoastDesc) {
                $metafields[] = [
                    'namespace' => self::$namespaces['seo'],
                    'key'       => 'meta_description',
                    'value'     => $yoastDesc,
                    'type'      => 'multi_line_text_field',
                ];
            }
            if ($yoastFocusKw) {
                $metafields[] = [
                    'namespace' => self::$namespaces['seo'],
                    'key'       => 'focus_keyword',
                    'value'     => $yoastFocusKw,
                    'type'      => 'single_line_text_field',
                ];
            }
        }

        // Rank Math
        if (get_option('wp4shpy_meta_rankmath', '1') === '1') {
            $rankTitle = get_post_meta($postId, 'rank_math_title', true);
            $rankDesc = get_post_meta($postId, 'rank_math_description', true);
            $rankFocusKw = get_post_meta($postId, 'rank_math_focus_keyword', true);

            // Only add if not already set by Yoast
            if ($rankTitle && !$this->hasMetafieldKey($metafields, 'meta_title')) {
                $metafields[] = [
                    'namespace' => self::$namespaces['seo'],
                    'key'       => 'meta_title',
                    'value'     => $rankTitle,
                    'type'      => 'single_line_text_field',
                ];
            }
            if ($rankDesc && !$this->hasMetafieldKey($metafields, 'meta_description')) {
                $metafields[] = [
                    'namespace' => self::$namespaces['seo'],
                    'key'       => 'meta_description',
                    'value'     => $rankDesc,
                    'type'      => 'multi_line_text_field',
                ];
            }
            if ($rankFocusKw && !$this->hasMetafieldKey($metafields, 'focus_keyword')) {
                $metafields[] = [
                    'namespace' => self::$namespaces['seo'],
                    'key'       => 'focus_keyword',
                    'value'     => $rankFocusKw,
                    'type'      => 'single_line_text_field',
                ];
            }
        }

        return $metafields;
    }

    /**
     * Build custom metafields from non-prefixed post meta.
     */
    private function buildCustomMetafields($postId) {
        $metafields = [];
        $allMeta = get_post_meta($postId);

        foreach ($allMeta as $key => $values) {
            if ($this->shouldSkipMeta($key)) {
                continue;
            }
            if (str_starts_with($key, '_')) {
                continue;
            }
            if ($this->isAcfField($key)) {
                continue; // ACF fields are handled separately
            }

            $value = count($values) === 1 ? $values[0] : $values;
            $type = $this->inferType($value);

            $metafields[] = [
                'namespace' => self::$namespaces['custom'],
                'key'       => $this->sanitizeKey($key),
                'value'     => is_array($value) ? wp_json_encode($value) : (string) $value,
                'type'      => $type,
            ];
        }

        return $metafields;
    }

    /**
     * Sync ACF fields specifically (called from ACF save hook).
     */
    private function syncAcfFields($postId, $shopifyId) {
        $metafields = $this->buildAcfMetafields($postId);

        if (empty($metafields)) {
            return;
        }

        // Ensure definitions exist
        $this->ensureMetafieldDefinitions($metafields);

        $this->apiClient->post('/api/metafields', [
            'action'           => 'upsert',
            'shopifyArticleId' => $shopifyId,
            'wpSiteId'         => is_multisite() ? get_current_blog_id() : 1,
            'metafields'       => $metafields,
        ]);
    }

    /**
     * Ensure metafield definitions exist in Shopify.
     * Creates definitions for any metafields that don't have one yet.
     */
    private function ensureMetafieldDefinitions(array $metafields) {
        $definitions = [];

        foreach ($metafields as $mf) {
            $key = $mf['namespace'] . '.' . $mf['key'];
            if (!isset($definitions[$key])) {
                $definitions[$key] = [
                    'namespace'   => $mf['namespace'],
                    'key'         => $mf['key'],
                    'type'        => $mf['type'],
                    'name'        => $this->generateDefinitionName($mf['key']),
                    'description' => $this->generateDefinitionDescription($mf['namespace'], $mf['key']),
                ];
            }
        }

        if (empty($definitions)) {
            return ['success' => true, 'count' => 0];
        }

        return $this->apiClient->post('/api/metafield-definitions', [
            'action'      => 'ensure',
            'definitions' => array_values($definitions),
        ]);
    }

    /**
     * Generate human-readable name for metafield definition.
     */
    private function generateDefinitionName($key) {
        $name = str_replace('_', ' ', $key);
        return ucwords($name);
    }

    /**
     * Generate description for metafield definition.
     */
    private function generateDefinitionDescription($namespace, $key) {
        $source = match($namespace) {
            'wp4shopify'        => 'WordPress',
            'wp4shopify_acf'    => 'ACF',
            'wp4shopify_seo'    => 'SEO',
            'wp4shopify_custom' => 'Custom Field',
            default             => 'WordPress',
        };
        return "Synced from {$source}: {$key}";
    }

    /**
     * Check if a metafield key already exists in array.
     */
    private function hasMetafieldKey(array $metafields, string $key) {
        foreach ($metafields as $mf) {
            if ($mf['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a meta key is an ACF field.
     */
    private function isAcfField($metaKey) {
        if (!function_exists('acf_get_field')) {
            return false;
        }

        // ACF internal fields start with underscore
        if (str_starts_with($metaKey, '_')) {
            return false;
        }

        // Check if ACF recognizes this field
        $field = acf_get_field($metaKey);
        return $field !== false;
    }

    /**
     * Get Shopify resource ID (article or page) for a WordPress post.
     */
    private function getShopifyResourceId($postId) {
        $articleId = get_post_meta($postId, '_wp4shpy_article_id', true);
        if ($articleId) {
            return $articleId;
        }

        $pageId = get_post_meta($postId, '_wp4shpy_page_id', true);
        return $pageId ?: null;
    }

    /**
     * Determine the metafield namespace based on the meta key origin.
     */
    private function resolveNamespace($metaKey) {
        if (str_starts_with($metaKey, 'rank_math_')) {
            return self::$namespaces['seo'];
        }
        if (str_starts_with($metaKey, '_yoast_wpseo_')) {
            return self::$namespaces['seo'];
        }

        return self::$namespaces['custom'];
    }

    /**
     * Infer Shopify metafield type from PHP value.
     */
    private function inferType($value) {
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'number_integer';
        if (is_float($value)) return 'number_decimal';
        if (is_array($value)) return 'json';
        if (is_string($value) && preg_match('/^https?:\/\//', $value)) return 'url';
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return 'date';
        if (is_string($value) && preg_match('/^#[a-fA-F0-9]{6}$/', $value)) return 'color';
        if (is_string($value) && strlen($value) > 255) return 'multi_line_text_field';

        return 'single_line_text_field';
    }

    /**
     * Sanitize a meta key for use as a Shopify metafield key.
     */
    private function sanitizeKey($key) {
        $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $key));
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        return substr(ltrim($sanitized, '_'), 0, 64);
    }

    /**
     * Check if a meta key should be skipped (internal WP keys).
     */
    private function shouldSkipMeta($metaKey) {
        $skipPrefixes = [
            '_wp4shpy_',
            '_edit_',
            '_wp_',
            '_oembed_',
            '_encloseme',
            '_pingme',
            '_menu_item_',
            '_wp_attached_file',
        ];

        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($metaKey, $prefix)) {
                return true;
            }
        }

        $skipExact = [
            '_thumbnail_id',
            '_wp_page_template',
            '_wp_old_slug',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
        ];

        return in_array($metaKey, $skipExact, true);
    }
}
