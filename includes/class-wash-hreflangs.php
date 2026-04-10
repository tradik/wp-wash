<?php
/**
 * Hreflang synchronization handler.
 * Detects translations from WPML, Polylang, TranslatePress, Weglot, Lokalise
 * and syncs hreflang URLs as metafields to Shopify articles.
 *
 * Supported plugins:
 * - WPML (WordPress Multilingual Plugin)
 * - Polylang
 * - TranslatePress
 * - Weglot (API-based, limited support)
 * - Lokalise (requires WPML)
 */
class WashHreflangs {

    /** @var WashApiClient */
    private $apiClient;

    /** @var string Metafield namespace for hreflangs */
    private const NAMESPACE = 'wash_hreflang';

    /** @var array Cached active plugin detection */
    private static $activePlugin = null;

    public function __construct() {
        $this->apiClient = new WashApiClient();
    }

    /**
     * Initialize hreflang hooks.
     */
    public function init() {
        if (get_option('wash_hreflangs_enabled') !== '1') {
            return;
        }

        // Hook after main sync completes
        add_action('wash_post_synced', [$this, 'onPostSynced'], 10, 2);
    }

    /**
     * Handle post sync completion - sync hreflangs.
     *
     * @param int    $postId    WordPress post ID
     * @param string $shopifyId Shopify resource GID
     */
    public function onPostSynced($postId, $shopifyId) {
        if (empty($shopifyId)) {
            return;
        }

        $this->syncHreflangs($postId, $shopifyId);
    }

    /**
     * Sync hreflang metafields for a post to Shopify.
     * Called during manual sync or after automatic sync.
     *
     * @param int    $postId    WordPress post ID
     * @param string $shopifyId Shopify resource GID
     * @return array|WP_Error Sync result
     */
    public function syncHreflangs($postId, $shopifyId) {
        if (get_option('wash_hreflangs_enabled') !== '1') {
            return ['skipped' => true, 'reason' => 'Hreflangs sync disabled'];
        }

        $hreflangs = $this->buildHreflangMetafields($postId);

        // If no translations found, clear existing hreflang metafields
        if (empty($hreflangs)) {
            return $this->clearHreflangMetafields($shopifyId);
        }

        // Ensure metafield definitions exist
        $this->ensureHreflangDefinitions($hreflangs);

        // Sync hreflang metafields
        $result = $this->apiClient->post('/api/metafields', [
            'action'           => 'upsert',
            'shopifyArticleId' => $shopifyId,
            'wpSiteId'         => is_multisite() ? get_current_blog_id() : 1,
            'metafields'       => $hreflangs,
        ]);

        if (is_wp_error($result)) {
            error_log("[wash] Hreflangs sync error: " . $result->get_error_message());
            return $result;
        }

        error_log("[wash] Hreflangs synced successfully. Count: " . count($hreflangs));
        return ['success' => true, 'count' => count($hreflangs)];
    }

    /**
     * Build hreflang metafields from translation plugins.
     *
     * @param int $postId WordPress post ID
     * @return array Metafields array
     */
    public function buildHreflangMetafields($postId) {
        $translations = $this->getPostTranslations($postId);

        if (empty($translations)) {
            return [];
        }

        $metafields = [];

        foreach ($translations as $locale => $url) {
            if (empty($url)) {
                continue;
            }

            // Normalize locale format (en_US -> en-US)
            $hreflangLocale = str_replace('_', '-', $locale);

            $metafields[] = [
                'namespace' => self::NAMESPACE,
                'key'       => 'hreflang_' . strtolower(str_replace('-', '_', $hreflangLocale)),
                'value'     => $url,
                'type'      => 'url',
            ];
        }

        // Add x-default if we have translations
        if (!empty($metafields)) {
            $defaultUrl = $this->getDefaultLanguageUrl($postId, $translations);
            if ($defaultUrl) {
                $metafields[] = [
                    'namespace' => self::NAMESPACE,
                    'key'       => 'hreflang_x_default',
                    'value'     => $defaultUrl,
                    'type'      => 'url',
                ];
            }
        }

        return $metafields;
    }

    /**
     * Get all translations of a post with their URLs.
     *
     * @param int $postId WordPress post ID
     * @return array Associative array [locale => url]
     */
    public function getPostTranslations($postId) {
        $activePlugin = $this->detectActivePlugin();

        return match ($activePlugin) {
            'wpml'         => $this->getWpmlTranslations($postId),
            'polylang'     => $this->getPolylangTranslations($postId),
            'translatepress' => $this->getTranslatePressTranslations($postId),
            'weglot'       => $this->getWeglotTranslations($postId),
            default        => [],
        };
    }

    /**
     * Get the "source" (canonical) post ID for a translation group.
     * This is the post that should hold the Shopify article;
     * all other translations register as locale variants.
     *
     * Primary language post is returned regardless of which post in the group is passed in.
     *
     * @param int $postId WordPress post ID
     * @return array ['sourcePostId' => int, 'sourceLang' => string, 'currentLang' => string, 'isSource' => bool]
     */
    public function getTranslationGroupSource($postId) {
        $activePlugin = $this->detectActivePlugin();

        if ($activePlugin === 'polylang' && function_exists('pll_get_post_translations') && function_exists('pll_default_language')) {
            $pllTranslations = pll_get_post_translations($postId);
            if (!is_array($pllTranslations) || empty($pllTranslations)) {
                return null;
            }
            $defaultLang = pll_default_language('slug');
            $sourcePostId = $pllTranslations[$defaultLang] ?? null;
            if (!$sourcePostId) {
                // No default-lang version exists — use the lowest ID as source
                $sourcePostId = min(array_values($pllTranslations));
            }
            $currentLang = function_exists('pll_get_post_language') ? pll_get_post_language($postId, 'slug') : null;
            return [
                'sourcePostId' => (int) $sourcePostId,
                'sourceLang'   => $defaultLang,
                'currentLang'  => $currentLang,
                'isSource'     => (int) $sourcePostId === (int) $postId,
            ];
        }

        if ($activePlugin === 'wpml' && function_exists('wpml_get_language_information')) {
            $post = get_post($postId);
            if (!$post) {
                return null;
            }
            $elementType = 'post_' . $post->post_type;
            $trid = apply_filters('wpml_element_trid', null, $postId, $elementType);
            if (!$trid) {
                return null;
            }
            $wpmlTranslations = apply_filters('wpml_get_element_translations', null, $trid, $elementType);
            if (!is_array($wpmlTranslations) || empty($wpmlTranslations)) {
                return null;
            }

            $defaultLang = apply_filters('wpml_default_language', null);
            $sourcePostId = null;
            foreach ($wpmlTranslations as $langCode => $t) {
                if ($langCode === $defaultLang && !empty($t->element_id)) {
                    $sourcePostId = (int) $t->element_id;
                    break;
                }
            }
            if (!$sourcePostId) {
                // Use original_id or lowest element_id
                foreach ($wpmlTranslations as $t) {
                    if (!empty($t->original) && !empty($t->element_id)) {
                        $sourcePostId = (int) $t->element_id;
                        break;
                    }
                }
            }
            if (!$sourcePostId) {
                return null;
            }

            $currentLangInfo = wpml_get_language_information(null, $postId);
            $currentLang = $currentLangInfo['language_code'] ?? null;

            return [
                'sourcePostId' => $sourcePostId,
                'sourceLang'   => $defaultLang,
                'currentLang'  => $currentLang,
                'isSource'     => $sourcePostId === (int) $postId,
            ];
        }

        return null;
    }

    /**
     * Detect which multilingual plugin is active.
     *
     * @return string|null Plugin identifier or null
     */
    public function detectActivePlugin() {
        if (self::$activePlugin !== null) {
            return self::$activePlugin;
        }

        // WPML (including Lokalise which uses WPML)
        if (function_exists('wpml_get_language_information')) {
            self::$activePlugin = 'wpml';
            return self::$activePlugin;
        }

        // Polylang
        if (function_exists('pll_get_post_language')) {
            self::$activePlugin = 'polylang';
            return self::$activePlugin;
        }

        // TranslatePress
        if (class_exists('TRP_Translate_Press') || function_exists('trp_get_languages')) {
            self::$activePlugin = 'translatepress';
            return self::$activePlugin;
        }

        // Weglot
        if (function_exists('weglot_get_current_language') || class_exists('WeglotWP\\Services\\Language_Service_Weglot')) {
            self::$activePlugin = 'weglot';
            return self::$activePlugin;
        }

        self::$activePlugin = false;
        return null;
    }

    /**
     * Get list of available languages configured in the active translation plugin.
     * @return array Array of ['code' => 'en', 'name' => 'English'] or empty if no plugin
     */
    public function getAvailableLanguages() {
        $plugin = $this->detectActivePlugin();
        if (!$plugin) {
            return [];
        }

        switch ($plugin) {
            case 'wpml':
                return $this->getWpmlLanguages();
            case 'polylang':
                return $this->getPolylangLanguages();
            case 'translatepress':
                return $this->getTranslatePressLanguages();
            case 'weglot':
                return $this->getWeglotLanguages();
            default:
                return [];
        }
    }

    private function getWpmlLanguages() {
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        if (!is_array($languages)) {
            return [];
        }
        $result = [];
        foreach ($languages as $code => $lang) {
            $result[] = ['code' => $code, 'name' => $lang['native_name'] ?? $lang['translated_name'] ?? $code];
        }
        return $result;
    }

    private function getPolylangLanguages() {
        if (!function_exists('pll_languages_list')) {
            return [];
        }
        $slugs = pll_languages_list(['fields' => 'slug']);
        $names = pll_languages_list(['fields' => 'name']);
        $result = [];
        foreach ($slugs as $i => $slug) {
            $result[] = ['code' => $slug, 'name' => $names[$i] ?? $slug];
        }
        return $result;
    }

    private function getTranslatePressLanguages() {
        if (!class_exists('TRP_Translate_Press')) {
            return [];
        }
        $trp = \TRP_Translate_Press::get_trp_instance();
        $settings = $trp->get_component('settings');
        $trpSettings = $settings->get_settings();
        $result = [];
        $allLangs = $trpSettings['translation-languages'] ?? [];
        foreach ($allLangs as $lang) {
            $code = strtolower(substr($lang, 0, 2));
            $result[] = ['code' => $code, 'name' => $lang];
        }
        return $result;
    }

    private function getWeglotLanguages() {
        if (!function_exists('weglot_get_destination_languages')) {
            return [];
        }
        $result = [];
        $original = weglot_get_original_language();
        if ($original) {
            $result[] = ['code' => $original, 'name' => $original];
        }
        $destinations = weglot_get_destination_languages();
        foreach ($destinations as $lang) {
            $code = is_object($lang) ? $lang->getIso639() : (string)$lang;
            $result[] = ['code' => $code, 'name' => $code];
        }
        return $result;
    }

    /**
     * Get translations from WPML.
     *
     * @param int $postId WordPress post ID
     * @return array [locale => url]
     */
    private function getWpmlTranslations($postId) {
        $translations = [];

        if (!function_exists('wpml_get_language_information')) {
            return $translations;
        }

        // Get post type for element_type
        $post = get_post($postId);
        if (!$post) {
            return $translations;
        }

        $elementType = 'post_' . $post->post_type;

        // Get translation group ID
        $trid = apply_filters('wpml_element_trid', null, $postId, $elementType);
        if (!$trid) {
            return $translations;
        }

        // Get all translations in this group
        $wpmlTranslations = apply_filters('wpml_get_element_translations', null, $trid, $elementType);

        if (!is_array($wpmlTranslations)) {
            return $translations;
        }

        foreach ($wpmlTranslations as $langCode => $translationData) {
            if (empty($translationData->element_id)) {
                continue;
            }

            $translatedPostId = $translationData->element_id;
            $translatedPost = get_post($translatedPostId);

            // Only include published translations
            if (!$translatedPost || $translatedPost->post_status !== 'publish') {
                continue;
            }

            // Get locale from WPML
            $langDetails = apply_filters('wpml_element_language_details', null, [
                'element_id'   => $translatedPostId,
                'element_type' => $elementType,
            ]);

            $locale = $langDetails['locale'] ?? $langCode;

            // Get Shopify URL for this translation
            $shopifyUrl = $this->getShopifyUrlForPost($translatedPostId);
            if ($shopifyUrl) {
                $translations[$locale] = $shopifyUrl;
            }
        }

        return $translations;
    }

    /**
     * Get translations from Polylang.
     *
     * @param int $postId WordPress post ID
     * @return array [locale => url]
     */
    private function getPolylangTranslations($postId) {
        $translations = [];

        if (!function_exists('pll_get_post_translations') || !function_exists('pll_get_post_language')) {
            return $translations;
        }

        $pllTranslations = pll_get_post_translations($postId);

        if (!is_array($pllTranslations)) {
            return $translations;
        }

        foreach ($pllTranslations as $langSlug => $translatedPostId) {
            $translatedPost = get_post($translatedPostId);

            // Only include published translations
            if (!$translatedPost || $translatedPost->post_status !== 'publish') {
                continue;
            }

            // Get full locale from Polylang
            $locale = $this->getPolylangLocale($langSlug);

            // Get Shopify URL for this translation
            $shopifyUrl = $this->getShopifyUrlForPost($translatedPostId);
            if ($shopifyUrl) {
                $translations[$locale] = $shopifyUrl;
            }
        }

        return $translations;
    }

    /**
     * Get full locale from Polylang language slug.
     *
     * @param string $langSlug Language slug (e.g., 'en', 'de')
     * @return string Full locale (e.g., 'en-US', 'de-DE')
     */
    private function getPolylangLocale($langSlug) {
        if (!function_exists('PLL') || !method_exists(PLL(), 'model')) {
            return $langSlug;
        }

        $languages = PLL()->model->get_languages_list();

        foreach ($languages as $language) {
            if ($language->slug === $langSlug) {
                // Polylang stores locale as 'en_US'
                return str_replace('_', '-', $language->locale);
            }
        }

        return $langSlug;
    }

    /**
     * Get translations from TranslatePress.
     *
     * @param int $postId WordPress post ID
     * @return array [locale => url]
     */
    private function getTranslatePressTranslations($postId) {
        $translations = [];

        // TranslatePress stores translations differently - same post, different URL
        if (!class_exists('TRP_Translate_Press')) {
            return $translations;
        }

        $trpSettings = get_option('trp_settings', []);
        $defaultLanguage = $trpSettings['default-language'] ?? 'en_US';
        $publishedLanguages = $trpSettings['publish-languages'] ?? [$defaultLanguage];

        $post = get_post($postId);
        if (!$post || $post->post_status !== 'publish') {
            return $translations;
        }

        // TranslatePress uses URL-based translations
        $basePermalink = get_permalink($postId);

        foreach ($publishedLanguages as $locale) {
            $translatedUrl = $this->getTranslatePressUrl($basePermalink, $locale, $defaultLanguage);
            $shopifyUrl = $this->convertWpUrlToShopifyUrl($translatedUrl, $postId, $locale);

            if ($shopifyUrl) {
                $translations[str_replace('_', '-', $locale)] = $shopifyUrl;
            }
        }

        return $translations;
    }

    /**
     * Get TranslatePress URL for a specific language.
     *
     * @param string $baseUrl         Base WordPress URL
     * @param string $targetLocale    Target locale
     * @param string $defaultLanguage Default language
     * @return string Translated URL
     */
    private function getTranslatePressUrl($baseUrl, $targetLocale, $defaultLanguage) {
        if ($targetLocale === $defaultLanguage) {
            return $baseUrl;
        }

        $langSlug = strtolower(substr($targetLocale, 0, 2));
        $parsedUrl = wp_parse_url($baseUrl);
        $path = $parsedUrl['path'] ?? '/';

        // TranslatePress typically adds language prefix
        return $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/' . $langSlug . $path;
    }

    /**
     * Get translations from Weglot.
     * Note: Weglot is API-based, so we generate URLs based on their URL structure.
     *
     * @param int $postId WordPress post ID
     * @return array [locale => url]
     */
    private function getWeglotTranslations($postId) {
        $translations = [];

        // Weglot uses API-based translations with URL prefixes
        if (!function_exists('weglot_get_options') && !class_exists('WeglotWP\\Services\\Option_Service_Weglot')) {
            return $translations;
        }

        $post = get_post($postId);
        if (!$post || $post->post_status !== 'publish') {
            return $translations;
        }

        // Get Weglot settings
        $weglotOptions = function_exists('weglot_get_options') ? weglot_get_options() : [];
        $originalLanguage = $weglotOptions['original_language'] ?? 'en';
        $destinationLanguages = $weglotOptions['destination_languages'] ?? [];

        // Add original language
        $shopifyUrl = $this->getShopifyUrlForPost($postId);
        if ($shopifyUrl) {
            $translations[$originalLanguage] = $shopifyUrl;
        }

        // Weglot generates URLs with language prefix
        // For Shopify, we need to consider how these will be handled
        foreach ($destinationLanguages as $langData) {
            $langCode = is_array($langData) ? ($langData['language_to'] ?? '') : $langData;
            if (empty($langCode)) {
                continue;
            }

            // Weglot translations on Shopify would need special handling
            // Store the intended locale for template reference
            $translations[$langCode] = $shopifyUrl . '?lang=' . $langCode;
        }

        return $translations;
    }

    /**
     * Get Shopify article URL for a WordPress post.
     *
     * @param int $postId WordPress post ID
     * @return string|null Shopify URL or null
     */
    private function getShopifyUrlForPost($postId) {
        $shopifyStore = get_option('wash_shopify_store', '');
        if (empty($shopifyStore)) {
            return null;
        }

        $shopifyArticleId = get_post_meta($postId, '_wash_article_id', true);
        if (empty($shopifyArticleId)) {
            return null;
        }

        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        // Build Shopify URL
        $baseUrl = 'https://' . $shopifyStore . '.myshopify.com';

        if ($post->post_type === 'page') {
            return $baseUrl . '/pages/' . $post->post_name;
        }

        $blogHandle = get_post_meta($postId, '_wash_blog_handle', true);
        if (empty($blogHandle)) {
            $blogHandle = 'news';
        }

        return $baseUrl . '/blogs/' . $blogHandle . '/' . $post->post_name;
    }

    /**
     * Convert WordPress URL to Shopify URL format.
     *
     * @param string $wpUrl    WordPress URL
     * @param int    $postId   WordPress post ID
     * @param string $locale   Target locale
     * @return string|null Shopify URL
     */
    private function convertWpUrlToShopifyUrl($wpUrl, $postId, $locale) {
        // For TranslatePress, we need to map to the corresponding Shopify article
        // This is complex because TranslatePress doesn't create separate posts

        $baseShopifyUrl = $this->getShopifyUrlForPost($postId);
        if (!$baseShopifyUrl) {
            return null;
        }

        // Add locale suffix/parameter for Shopify handling
        // The actual URL structure depends on how the store handles multilingual
        return $baseShopifyUrl;
    }

    /**
     * Get URL for the default language.
     *
     * @param int   $postId       WordPress post ID
     * @param array $translations All translations
     * @return string|null Default URL
     */
    private function getDefaultLanguageUrl($postId, $translations) {
        $activePlugin = $this->detectActivePlugin();

        // Get default/original language based on plugin
        $defaultLocale = match ($activePlugin) {
            'wpml'         => $this->getWpmlDefaultLocale(),
            'polylang'     => $this->getPolylangDefaultLocale(),
            'translatepress' => $this->getTranslatePressDefaultLocale(),
            'weglot'       => $this->getWeglotDefaultLocale(),
            default        => get_locale(),
        };

        $defaultLocale = str_replace('_', '-', $defaultLocale);

        // Return URL for default locale if it exists in translations
        foreach ($translations as $locale => $url) {
            if (strtolower(str_replace('_', '-', $locale)) === strtolower($defaultLocale)) {
                return $url;
            }
        }

        // Fallback to first translation
        return reset($translations) ?: null;
    }

    /**
     * Get WPML default language locale.
     *
     * @return string Locale
     */
    private function getWpmlDefaultLocale() {
        if (defined('ICL_LANGUAGE_CODE')) {
            $defaultLang = apply_filters('wpml_default_language', null);
            if ($defaultLang) {
                $langDetails = apply_filters('wpml_language_details', null, $defaultLang);
                return $langDetails['locale'] ?? $defaultLang;
            }
        }
        return get_locale();
    }

    /**
     * Get Polylang default language locale.
     *
     * @return string Locale
     */
    private function getPolylangDefaultLocale() {
        if (function_exists('pll_default_language')) {
            $defaultLang = pll_default_language('slug');
            return $this->getPolylangLocale($defaultLang);
        }
        return get_locale();
    }

    /**
     * Get TranslatePress default language locale.
     *
     * @return string Locale
     */
    private function getTranslatePressDefaultLocale() {
        $trpSettings = get_option('trp_settings', []);
        return $trpSettings['default-language'] ?? get_locale();
    }

    /**
     * Get Weglot default language locale.
     *
     * @return string Locale
     */
    private function getWeglotDefaultLocale() {
        $weglotOptions = function_exists('weglot_get_options') ? weglot_get_options() : [];
        return $weglotOptions['original_language'] ?? 'en';
    }

    /**
     * Clear hreflang metafields from Shopify when no translations exist.
     *
     * @param string $shopifyId Shopify resource GID
     * @return array Result
     */
    private function clearHreflangMetafields($shopifyId) {
        $result = $this->apiClient->post('/api/metafields', [
            'action'           => 'delete_namespace',
            'shopifyArticleId' => $shopifyId,
            'wpSiteId'         => is_multisite() ? get_current_blog_id() : 1,
            'namespace'        => self::NAMESPACE,
        ]);

        if (is_wp_error($result)) {
            error_log("[wash] Hreflangs clear error: " . $result->get_error_message());
            return $result;
        }

        error_log("[wash] Hreflangs cleared for article");
        return ['success' => true, 'cleared' => true];
    }

    /**
     * Ensure hreflang metafield definitions exist in Shopify.
     *
     * @param array $metafields Hreflang metafields
     */
    private function ensureHreflangDefinitions(array $metafields) {
        $definitions = [];

        foreach ($metafields as $mf) {
            $key = $mf['namespace'] . '.' . $mf['key'];
            if (!isset($definitions[$key])) {
                $locale = str_replace(['hreflang_', '_'], ['', '-'], $mf['key']);
                $definitions[$key] = [
                    'namespace'   => $mf['namespace'],
                    'key'         => $mf['key'],
                    'type'        => 'url',
                    'name'        => 'Hreflang ' . strtoupper($locale),
                    'description' => 'Alternate language URL for ' . $locale,
                ];
            }
        }

        if (empty($definitions)) {
            return;
        }

        $this->apiClient->post('/api/metafield-definitions', [
            'action'      => 'ensure',
            'definitions' => array_values($definitions),
        ]);
    }

    /**
     * Get active multilingual plugin name for display.
     *
     * @return string Plugin name or 'None'
     */
    public function getActivePluginName() {
        $plugin = $this->detectActivePlugin();

        return match ($plugin) {
            'wpml'         => 'WPML',
            'polylang'     => 'Polylang',
            'translatepress' => 'TranslatePress',
            'weglot'       => 'Weglot',
            default        => __('None detected', 'wash'),
        };
    }

    /**
     * Check if any multilingual plugin is active.
     *
     * @return bool True if plugin detected
     */
    public function isMultilingualPluginActive() {
        return $this->detectActivePlugin() !== null;
    }

    /**
     * Get template code for Shopify Liquid.
     *
     * @return string Liquid code snippet
     */
    public static function getTemplateCode() {
        return <<<'LIQUID'
{% comment %}
  Wash Hreflang Tags
  Add this snippet to your theme's layout/theme.liquid in the <head> section
{% endcomment %}

{% if article %}
  {% assign ns = 'wash_hreflang' %}

  {% comment %} Loop through all hreflang metafields {% endcomment %}
  {% for metafield in article.metafields[ns] %}
    {% if metafield.key contains 'hreflang_' %}
      {% assign lang_code = metafield.key | remove: 'hreflang_' | replace: '_', '-' %}
      <link rel="alternate" hreflang="{{ lang_code }}" href="{{ metafield.value }}" />
    {% endif %}
  {% endfor %}
{% endif %}
LIQUID;
    }
}
