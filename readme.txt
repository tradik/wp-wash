=== Wash: Blog Manager ===
Contributors: tradik
Tags: shopify, sync, content, blog, multisite
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.14.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Synchronize WordPress content, images, and metafields to Shopify blogs in real-time.

== Description ==

Wash: Blog Manager bridges your WordPress editorial workflow with Shopify's blog platform.
Edit content in WordPress and have it automatically published to your Shopify store.

**Features:**

* Automatic content sync on publish/update/delete
* Image upload to Shopify CDN with URL rewriting
* Metafield sync (ACF, custom fields, SEO plugins)
* Hreflang sync for multilingual sites (WPML, Polylang, TranslatePress, Weglot)
* WP Multisite → multiple Shopify blogs
* Yoast SEO and RankMath integration
* HMAC-signed API communication
* Rate limit handling with exponential backoff

== Installation ==

1. Upload the `wash` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to Settings → Wash
4. Enter your Shopify App URL, API Key, and API Secret
5. Click "Test Connection" to verify

== Frequently Asked Questions ==

= Does this require a custom Shopify app? =

Yes. The companion Shopify app (included in this monorepo) must be deployed and installed on your Shopify store.

= Does it support WP Multisite? =

Yes. Each site in the network maps to a separate Shopify blog.

== Changelog ==

= 1.12.13 =
* Updated POT translation template with all new strings
* Added dynamic Shopify app slug for dev/prod environments
* Fixed duplicate getAppUrl() method in API client

= 1.10.5 =
* Fixed plugin update download - authentication headers now properly sent

= 1.10.4 =
* Added plugin version display in settings header
* Added "Update available" notification with link to updates page
* Simplified connection UI - removed internal debug options

= 1.10.3 =
* Fixed sync status column icon alignment in posts list

= 1.10.2 =
* Added test blocks documentation (docs/test-blocks-for-wash.md)
* Gutenberg blocks test content: heading, paragraph, image, gallery, cover, columns, list, quote, buttons, separator, table, embed, RankMath FAQ

= 1.8.0 =
* Added hreflang sync for multilingual sites
* Supports WPML, Polylang, TranslatePress, Weglot, and Lokalise
* Each translation syncs as individual Shopify metafield
* Auto-detection of active multilingual plugin
* Liquid template code provided for Shopify theme integration

= 1.6.3 =
* Added option to rewrite internal WordPress URLs to Shopify format
* Unified Shopify compliance webhooks to single endpoint
* Fixed HMAC verification for mandatory privacy webhooks
* Hardened Docker security configuration

= 1.6.2 =
* Improved multisite support
* Enhanced files and media synchronization

= 1.6.1 =
* Added files media sync class
* Bug fixes and performance improvements

= 1.6.0 =
* Added metafields synchronization
* SEO plugins integration (Yoast, RankMath)

= 1.5.0 =
* Image upload to Shopify CDN
* URL rewriting for images

= 1.0.0 =
* Initial release
* Content sync, image sync, metafields, multisite support
