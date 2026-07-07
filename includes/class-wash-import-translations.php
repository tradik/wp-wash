<?php
/**
 * Reverse import translations (Phase B): import a Shopify article's Translate &
 * Adapt locale variants as linked translated WordPress posts.
 *
 * Only Polylang and WPML support separate translated posts (the import target).
 * When neither is active this is a no-op and only the source-locale article is
 * imported — the UI surfaces a "install Polylang/WPML" notice.
 *
 * Each variant is stamped `_wash_skip_autosync` (anti-loop) but NOT
 * `_wash_article_id` — that identity stays on the source post so the writer's
 * dedup is unambiguous; variants are re-found via the multilingual plugin.
 */
if (!defined('ABSPATH')) {
    exit; // Prevent direct file access.
}

class WashImportTranslations {

    /**
     * Import all locale variants for a freshly-imported source post.
     *
     * @param int   $sourcePostId Source (primary-locale) post ID.
     * @param array $article      Normalized source article.
     * @param array $translations Locale => { title, body, summary, handle, ... }.
     * @param array $options      Import options.
     */
    public function apply($sourcePostId, array $article, array $translations, array $options) {
        $plugin = $this->detectPlugin();
        if (!$plugin) {
            return; // No multilingual plugin — source-only import.
        }

        foreach ($translations as $locale => $data) {
            $locale = (string) $locale;
            if (!$this->localeConfigured($plugin, $locale)) {
                washLog("[wash] import: locale {$locale} not configured in {$plugin} — skipping variant");
                continue;
            }
            $translatedId = $this->upsertTranslatedPost($plugin, $sourcePostId, $article, $locale, (array) $data, $options);
            if (!$translatedId) {
                continue;
            }
            update_post_meta($translatedId, WashContext::metaKey('_wash_skip_autosync'), '1');
            $this->link($plugin, $sourcePostId, $translatedId, $locale);
        }
    }

    /**
     * Which multilingual plugin is active (seam for tests).
     *
     * @return string|null 'polylang' | 'wpml' | null.
     */
    protected function detectPlugin() {
        $plugin = (new WashHreflangs())->detectActivePlugin();
        return in_array($plugin, array('polylang', 'wpml'), true) ? $plugin : null;
    }

    /**
     * Whether the target locale exists in the active plugin's configuration.
     *
     * @param string $plugin Active plugin.
     * @param string $locale Target locale.
     * @return bool
     */
    private function localeConfigured($plugin, $locale) {
        if ($plugin === 'polylang' && function_exists('pll_languages_list')) {
            $slugs = pll_languages_list(array('fields' => 'slug'));
            return is_array($slugs) && in_array($locale, $slugs, true);
        }
        // WPML: assume configured (link step fails soft if not).
        return true;
    }

    /**
     * Create or update the translated post for a locale.
     *
     * @return int Translated post ID, or 0 on failure.
     */
    private function upsertTranslatedPost($plugin, $sourcePostId, array $article, $locale, array $data, array $options) {
        $existingId = $this->existingTranslationId($plugin, $sourcePostId, $locale);
        $body       = $data['body'] ?? ($article['bodyHtml'] ?? '');

        $arr = array(
            'post_type'    => (($options['postType'] ?? 'post') === 'page') ? 'page' : 'post',
            'post_title'   => wp_strip_all_tags($data['title'] ?? ($article['title'] ?? '')),
            'post_content' => current_user_can('unfiltered_html') ? $body : wp_kses_post($body),
            'post_excerpt' => $data['summary'] ?? ($article['summaryHtml'] ?? ''),
            'post_status'  => get_post_status($sourcePostId) ?: 'draft',
        );
        if (!empty($data['handle'])) {
            $arr['post_name'] = sanitize_title($data['handle']);
        }

        if ($existingId) {
            $arr['ID'] = $existingId;
            $result = wp_update_post($arr, true);
        } else {
            $result = wp_insert_post($arr, true);
        }
        if (is_wp_error($result)) {
            washLog('[wash] import: translation upsert failed (' . $locale . '): ' . $result->get_error_message());
            return 0;
        }
        return (int) $result;
    }

    /**
     * Find an existing translation of the source post for a locale, or 0.
     *
     * @return int
     */
    private function existingTranslationId($plugin, $sourcePostId, $locale) {
        if ($plugin === 'polylang' && function_exists('pll_get_post')) {
            $id = pll_get_post($sourcePostId, $locale);
            return $id ? (int) $id : 0;
        }
        if ($plugin === 'wpml') {
            $id = apply_filters('wpml_object_id', $sourcePostId, 'post', false, $locale);
            return $id ? (int) $id : 0;
        }
        return 0;
    }

    /**
     * Link a translated post to its source in the active plugin's translation group.
     */
    private function link($plugin, $sourcePostId, $translatedId, $locale) {
        if ($plugin === 'polylang') {
            $this->linkPolylang($sourcePostId, $translatedId, $locale);
        } elseif ($plugin === 'wpml') {
            $this->linkWpml($sourcePostId, $translatedId, $locale);
        }
    }

    private function linkPolylang($sourcePostId, $translatedId, $locale) {
        if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
            return;
        }
        pll_set_post_language($translatedId, $locale);

        $group = array($locale => $translatedId);
        if (function_exists('pll_get_post_language')) {
            $sourceLocale = pll_get_post_language($sourcePostId);
            if ($sourceLocale) {
                $group[$sourceLocale] = $sourcePostId;
            }
        }
        pll_save_post_translations($group);
    }

    private function linkWpml($sourcePostId, $translatedId, $locale) {
        $trid = apply_filters('wpml_element_trid', null, $sourcePostId, 'post_post');
        $sourceLocale = apply_filters('wpml_element_language_code', null, array('element_id' => $sourcePostId, 'element_type' => 'post_post'));
        do_action('wpml_set_element_language_details', array(
            'element_id'           => $translatedId,
            'element_type'         => 'post_post',
            'trid'                 => $trid,
            'language_code'        => $locale,
            'source_language_code' => $sourceLocale,
        ));
    }
}
