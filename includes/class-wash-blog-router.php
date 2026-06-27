<?php
/**
 * Storefront URL generator from blog-mapping rules (#255).
 *
 * Reads the merchant's blog-mapping rules (the SAME rules the queue-worker uses
 * to route content at sync time), finds the rule that matches a given post by
 * language / category, and builds the post's Shopify storefront URL — market
 * subfolder + blog/page path. This is a faithful PHP port of the worker's
 * resolver (`pickRule` in queue-worker/src/services/contentSync.js), so the
 * plugin predicts exactly where the worker sent (or will send) the content.
 *
 * Everything is computed locally from cached rules + the post's own data:
 * no per-post network call, no worker round-trip, no re-sync, so it scales to
 * any number of posts and is always live (a rule change is picked up on the
 * next cache refresh, not a re-publish).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashBlogRouter {

    /** Region code aliases (mirror of the worker's REGION_ALIASES). */
    private static $regionAliases = array('gb' => 'uk', 'uk' => 'gb');

    /** Same-priority tie-breaker: most specific rule type wins. */
    private static $ruleSpecificity = array(
        'language_category' => 3,
        'language'          => 2,
        'category'          => 1,
        'default'           => 0,
    );

    /** Request-scoped memo of fetched API lists, keyed by "transientBase|connId". */
    private static $apiMemo = array();

    /**
     * The storefront URL for a synced post, derived from the matching
     * blog-mapping rule. Returns null when the post is not synced / no store.
     *
     * @param int $postId
     * @return string|null
     */
    public static function storefrontUrl($postId) {
        // Short-circuit (no store / not synced) BEFORE touching get_post(), so the
        // cheap guards run first and the heavy resolution only happens for synced posts.
        $store     = WashContext::connection()['store'] ?? '';
        $articleId = $store ? get_post_meta($postId, WashContext::metaKey('_wash_article_id'), true) : '';
        $pageId    = $store ? get_post_meta($postId, WashContext::metaKey('_wash_page_id'), true) : '';
        if (empty($store) || (empty($articleId) && empty($pageId))) {
            return null;
        }
        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        // Article vs page is decided by the WordPress post type (a WP post can be
        // routed to a Shopify page, and vice-versa, but the URL shape follows the
        // content type the same way the old builder did).
        $isPage = ($post->post_type === 'page');
        $handle = get_post_meta($postId, WashContext::metaKey('_wash_handle'), true) ?: $post->post_name;

        // The market subfolder is NOT the rule's ruleValue — it lives in Shopify
        // Markets. Resolve the real storefront base (e.g. .../de-ch/, .../en-ca/,
        // .../de-eu/) from the matched rule's market + locale via the cached Markets
        // web presence. A rule with no market assigned has no localized storefront
        // → primary domain root (#255).
        $rule = self::matchRule($postId, $isPage ? 'page' : 'article');
        $base = self::storefrontBase($store, $rule);

        $path = $isPage
            ? '/pages/' . $handle
            : '/blogs/' . self::articleBlogHandle($postId, $rule) . '/' . $handle;

        return $base . $path;
    }

    /**
     * The blog handle for an article URL. Prefers the article's ACTUAL blog
     * (translation variants live on the source article's blog, which can differ
     * from the matched rule's blog), then the rule's blog, then the stored handle.
     *
     * @param int        $postId
     * @param array|null $rule
     * @return string
     */
    private static function articleBlogHandle($postId, $rule) {
        $blogId = get_post_meta($postId, WashContext::metaKey('_wash_blog_id'), true);
        return self::blogHandleForId($blogId)
            ?: ($rule['shopifyBlogHandle'] ?? '')
            ?: (get_post_meta($postId, WashContext::metaKey('_wash_blog_handle'), true) ?: 'news');
    }

    /**
     * Does ANY active blog-mapping rule (any destination) apply to this post?
     * Used to gate publishing: a post whose language/category matches no rule has
     * no defined Shopify destination, so it must NOT be published — it is flagged
     * "No mapping" in the UI instead of being guessed onto some blog (#255).
     *
     * Fails OPEN: when no rules are cached at all (none configured, or a transient
     * API error returned an empty list) we cannot decide, so we do NOT block.
     *
     * @param int $postId
     * @return bool
     */
    public static function hasMapping($postId) {
        $rules = self::rules();
        if (empty($rules)) {
            return true; // can't determine → don't block
        }
        $language      = WashSync::getPostLanguage($postId);
        $langVariants  = $language ? self::expandLangVariants($language) : array();
        $categorySlugs = self::categorySlugs($postId);
        foreach ($rules as $rule) {
            $active = !isset($rule['isActive']) || $rule['isActive'];
            if ($active && self::ruleMatches($rule, $langVariants, $categorySlugs)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the blog-mapping rule that applies to this post (priority-first,
     * then rule-type specificity, then oldest), scoped to a destination.
     *
     * @param int    $postId
     * @param string $destination 'article' | 'page'
     * @return array|null matched rule, or null if none match
     */
    public static function matchRule($postId, $destination = 'article') {
        $language     = WashSync::getPostLanguage($postId); // e.g. "de-ch" or null
        $langVariants = $language ? self::expandLangVariants($language) : array();
        $categorySlugs = self::categorySlugs($postId);

        $rules = array_filter(self::rules(), static function ($r) use ($destination) {
            $active = !isset($r['isActive']) || $r['isActive'];
            return $active && ($r['destination'] ?? 'article') === $destination;
        });

        self::sortRules($rules);

        foreach ($rules as $rule) {
            if (self::ruleMatches($rule, $langVariants, $categorySlugs)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * The real storefront base URL for the rule's target market + locale, read
     * from the cached Shopify Markets web presence (rootUrls). e.g. a rule with
     * market=Switzerland + locale=de resolves to ".../de-ch". Falls back to the
     * primary domain (no subfolder) when the rule has no market assigned or the
     * locale has no published root URL — i.e. there is no localized storefront
     * for that rule, which the merchant must fix by assigning a market (#255).
     *
     * @param string     $store
     * @param array|null $rule
     * @return string base URL without a trailing slash
     */
    private static function storefrontBase($store, $rule) {
        $primary = self::primaryDomain($store);
        $locale  = $rule ? strtolower($rule['shopifyLocale'] ?? '') : '';
        $market  = $locale !== '' ? self::resolveMarket($rule) : null;
        if (!$market) {
            return $primary;
        }
        foreach (($market['webPresence']['rootUrls'] ?? array()) as $ru) {
            if (strtolower($ru['locale'] ?? '') === $locale && !empty($ru['url'])) {
                return rtrim($ru['url'], '/');
            }
        }
        return $primary;
    }

    /**
     * The Shopify market this rule targets. An explicit shopifyMarketId wins;
     * otherwise the market is DERIVED from the ruleValue's country (e.g. "fr-fr"
     * → FR), matched against each market's regions — so the storefront subfolder
     * comes straight from the blog mapping, with no separate market field to fill
     * in (#255). Each country belongs to exactly one market, so this is unambiguous.
     *
     * @param array $rule
     * @return array|null
     */
    private static function resolveMarket($rule) {
        if (!empty($rule['shopifyMarketId'])) {
            $explicit = self::market($rule['shopifyMarketId']);
            if ($explicit) {
                return $explicit;
            }
        }
        $country = self::countryFromRuleValue($rule['ruleValue'] ?? '');
        $found   = null;
        if ($country !== '') {
            foreach (self::markets() as $m) {
                foreach (($m['regions'] ?? array()) as $r) {
                    if (strtoupper((string) ($r['code'] ?? '')) === $country) {
                        $found = $m;
                        break 2;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Country code from a rule value: "fr-fr" → "FR", "de-ch" → "CH",
     * "fr-fr:news" → "FR". Empty when the value carries no region part.
     *
     * @param string $value
     * @return string
     */
    private static function countryFromRuleValue($value) {
        $v     = strtolower((string) $value);
        $colon = strpos($v, ':');
        if ($colon !== false) {
            $v = substr($v, 0, $colon);
        }
        $dash = strpos($v, '-');
        return $dash === false ? '' : strtoupper(substr($v, $dash + 1));
    }

    /**
     * The canonical storefront domain (the merchant's custom domain), taken from the
     * PRIMARY market's web presence so links point at the real shop domain, not
     * the {store}.myshopify.com fallback (#255, requested on the issue). Used as
     * the base for rules with no market (primary market, no subfolder).
     *
     * @param string $store
     * @return string base URL without a trailing slash
     */
    private static function primaryDomain($store) {
        foreach (self::markets() as $m) {
            $url = $m['webPresence']['domainUrl'] ?? '';
            if (!empty($m['primary']) && !empty($url)) {
                return rtrim($url, '/');
            }
        }
        return 'https://' . $store . '.myshopify.com';
    }

    /**
     * Find a cached Shopify market by its gid.
     *
     * @param string $marketId
     * @return array|null
     */
    private static function market($marketId) {
        foreach (self::markets() as $m) {
            if (($m['id'] ?? '') === $marketId) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Resolve a Shopify blog gid to its handle via the cached blogs list.
     *
     * @param string $blogId
     * @return string '' when unknown
     */
    private static function blogHandleForId($blogId) {
        if (empty($blogId)) {
            return '';
        }
        foreach (self::blogs() as $b) {
            if (($b['id'] ?? '') === $blogId && !empty($b['handle'])) {
                return $b['handle'];
            }
        }
        return '';
    }

    /**
     * Does a rule apply to the post's language variants / category slugs?
     * Faithful port of the worker's ruleMatches().
     */
    private static function ruleMatches($rule, $langVariants, $categorySlugs) {
        $value = (string) ($rule['ruleValue'] ?? '');
        $sep   = strpos($value, ':');
        switch ($rule['ruleType'] ?? '') {
            case 'default':
                $result = true;
                break;
            case 'language':
                $result = in_array($value, $langVariants, true);
                break;
            case 'category':
                $result = in_array($value, $categorySlugs, true);
                break;
            case 'language_category':
                $result = $sep !== false
                    && in_array(substr($value, 0, $sep), $langVariants, true)
                    && in_array(substr($value, $sep + 1), $categorySlugs, true);
                break;
            default:
                $result = false;
        }
        return $result;
    }

    /**
     * Order rules: priority desc, then type specificity desc, then oldest-first.
     * Mirrors the worker's v1 ordering (the served default).
     *
     * @param array $rules passed by reference, sorted in place
     */
    private static function sortRules(&$rules) {
        usort($rules, static function ($a, $b) {
            $byPriority = ((int) ($b['priority'] ?? 0)) - ((int) ($a['priority'] ?? 0));
            if ($byPriority !== 0) {
                return $byPriority;
            }
            $sa = self::$ruleSpecificity[$a['ruleType'] ?? ''] ?? -1;
            $sb = self::$ruleSpecificity[$b['ruleType'] ?? ''] ?? -1;
            if ($sb !== $sa) {
                return $sb - $sa;
            }
            return strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? ''));
        });
    }

    /**
     * Expand a language into its match variants, e.g. "en-gb" → [en-gb, en, gb, uk].
     * Faithful port of the worker's expandLangVariants().
     *
     * @param string $language
     * @return string[]
     */
    private static function expandLangVariants($language) {
        $variants = array($language);
        if (strpos($language, '-') !== false) {
            list($langCode, $regionCode) = explode('-', $language, 2);
            $variants[] = $langCode;
            $variants[] = $regionCode;
            if (isset(self::$regionAliases[$regionCode])) {
                $variants[] = self::$regionAliases[$regionCode];
            }
        }
        return $variants;
    }

    /**
     * The post's category slugs (for category / language_category rules).
     *
     * @param int $postId
     * @return string[]
     */
    private static function categorySlugs($postId) {
        if (!function_exists('get_the_terms')) {
            return array();
        }
        $terms = get_the_terms($postId, 'category');
        if (!$terms || is_wp_error($terms)) {
            return array();
        }
        return array_values(array_filter(array_map(static function ($t) {
            return $t->slug ?? null;
        }, $terms)));
    }

    /**
     * Blog-mapping rules for the active connection. Memoized per request and
     * cached in a transient (rules change rarely; a 5-minute TTL keeps "View on
     * Shopify" instant for every post while staying live to config changes).
     *
     * @return array list of rule arrays (empty on error)
     */
    private static function rules() {
        return self::cachedList('wash_blog_mapping_rules', '/api/blog-mappings', 'mappings');
    }

    /**
     * Shopify Markets (with web-presence rootUrls) for the active connection,
     * cached. The rootUrls hold the real storefront subfolders (/de-ch/, /en-ca/).
     *
     * @return array list of market arrays (empty on error)
     */
    private static function markets() {
        return self::cachedList('wash_shopify_markets', '/api/shopify-markets', 'markets');
    }

    /**
     * Shopify blogs (id → handle) for the active connection, cached.
     *
     * @return array list of blog arrays (empty on error)
     */
    private static function blogs() {
        return self::cachedList('wash_shopify_blogs', '/api/shopify-blogs', 'blogs');
    }

    /**
     * Fetch + cache a list from the app API for the active connection. Memoized
     * per request and stored in a transient (this data changes rarely; a
     * 5-minute TTL keeps "View on Shopify" instant for every post while staying
     * live to config changes — no per-post network call, no re-sync).
     *
     * @param string $transientBase e.g. 'wash_shopify_markets'
     * @param string $endpoint      app route, e.g. '/api/shopify-markets'
     * @param string $listKey       response array key, e.g. 'markets'
     * @return array the list (empty on error)
     */
    private static function cachedList($transientBase, $endpoint, $listKey) {
        $memoKey = $transientBase . '|' . WashContext::activeId();
        if (isset(self::$apiMemo[$memoKey])) {
            return self::$apiMemo[$memoKey];
        }

        $tkey   = WashContext::transientKey($transientBase);
        $cached = function_exists('get_transient') ? get_transient($tkey) : false;
        if (!is_array($cached)) {
            // Degrade gracefully: if the app API is unreachable (or, in tests, the
            // HTTP layer is unavailable) the call throws — treat that as "no data"
            // rather than fataling the admin page that is just rendering a link.
            try {
                $result = WashContext::apiClient()->get($endpoint);
            } catch (\Throwable $e) {
                $result = null;
            }
            $cached = (!is_array($result) || is_wp_error($result) || empty($result[$listKey]) || !is_array($result[$listKey]))
                ? array()
                : $result[$listKey];
            if (function_exists('set_transient')) {
                set_transient($tkey, $cached, 5 * MINUTE_IN_SECONDS);
            }
        }

        self::$apiMemo[$memoKey] = $cached;
        return $cached;
    }

    /**
     * Drop the cached rules / markets / blogs for the active connection (call
     * after a mapping or markets change so the next URL reflects it immediately).
     */
    public static function flushCache() {
        self::$apiMemo = array();
        foreach (array('wash_blog_mapping_rules', 'wash_shopify_markets', 'wash_shopify_blogs') as $base) {
            delete_transient(WashContext::transientKey($base));
        }
    }
}
