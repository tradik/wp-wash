=== Wash: Blog Manager ===
Contributors: tradik
Tags: shopify, sync, content, blog, multisite
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.26.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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
* Search & Replace rules for content transformation before sync (Business plan)
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

== External services ==

This plugin connects to an external service to do its job of synchronising WordPress content into Shopify. Below is the full list of what is sent, when, and to whom — required disclosure under the WordPress.org Plugin Directory guidelines.

= Tradik Wash service (`app-wash.tradik.com`, `app-wash.tradik.dev`, `app-wash1.tradik.com`) =

The plugin sends data to the Tradik-hosted Wash service, which is the broker between your WordPress site and your Shopify store. The dev (`*.tradik.dev`) and regional (`app-wash1`) endpoints are only used when the site URL or installation matches; production sites use `app-wash.tradik.com`.

**What is sent:**

* WordPress post / page content being synced: title, slug, excerpt, rendered HTML body, status, categories, tags, author display name, featured-image URL, inline image URLs, custom fields and metafields the merchant has enabled in plugin Settings.
* WordPress site URL (used by the service to look up the matching Shopify store).
* The plugin's API key (set by the merchant during the Shopify-side "Connect" flow — this is the credential the service uses to authenticate the request).
* WordPress site name (sent once on initial connect, for display in the Shopify-side dashboard).
* Optional HTTP Basic Auth credentials (only if the merchant explicitly enters them in Settings → Wash so that the service can fetch images from a staging site that sits behind HTTP Basic Auth; never sent otherwise).
* The remote IP of the caller is used by the service as a rate-limit bucket key (the plugin's signed-media endpoint reads `$_SERVER['REMOTE_ADDR']` for this purpose).

**When it is sent:**

* On `save_post` for any post type the merchant has enabled (published posts/pages).
* When the merchant clicks the "Sync now" row action or the bulk "Publish / Re-publish / Unpublish to Shopify" action.
* When the merchant clicks "Refresh plan" or "Refresh settings" on the Settings page (the service returns the merchant's current Shopify plan and saved per-store settings; no content is sent).
* When the merchant clicks "Connect to Shopify" — a one-time handshake that posts the WordPress site URL and site name.

**Why it is sent:**

* The Tradik Wash service is the only path by which WordPress content reaches Shopify. The plugin does not call Shopify's API directly; the service does, using OAuth credentials the merchant authorised when installing the Shopify-side companion app.

**Service legal links:**

* Privacy Policy: [https://wash.tradik.com/privacy-policy.html](https://wash.tradik.com/privacy-policy.html)
* Documentation / Support: [https://wash.tradik.com/docs.html](https://wash.tradik.com/docs.html)

The plugin does not send any data to third-party analytics, telemetry, advertising, or tracking services. It does not contact any host other than the three listed above.

**Consent flow:**

On a fresh install the plugin is inert — no requests leave WordPress until the merchant opens **Settings → Wash → Connection**, ticks the "I understand that connecting this plugin will send my WordPress post content … to the Tradik Wash service" checkbox (which links to the Privacy Policy above and to this External services section), and then clicks **Connect to Shopify**. The Connect button is disabled until the consent checkbox is ticked. The decision is persisted in the `wash_consent_accepted` and `wash_consent_accepted_at` options and shown back to the merchant on the same screen ("Consent recorded on YYYY-MM-DD HH:MM"). Uninstall removes both options.

== Changelog ==

= 1.26.6 =
* Fixed content images staying on the source WordPress host (never rehosted to the Shopify CDN) when the body referenced a sized thumbnail of a `-scaled` original — e.g. `foo-1024x785.jpg` where WordPress stores the original as `foo-scaled.jpg`. `getAttachmentIdFromUrl()` stripped the size to `foo.jpg` and gave up, so the image was never uploaded and the rewriter left the source URL in place. It now also tries the `-scaled` form, mirroring the queue worker's `filenameVariants()`. Additionally, `buildContentImageIdMap()` now reads the canonical `wp-image-<id>` class straight off each `<img>` tag (the real attachment id Gutenberg/Bricks already embed) and maps that src directly to the id — immune to host rewrites and size/-scaled suffix mismatches that defeat `attachment_url_to_postid()`. URL-based resolution remains as a fallback for non-`<img>` references (PDFs, links).

= 1.26.5 =
* Added explicit data-processing consent flow on the Connection tab (wp.org Plugin Directory guideline #10). The "Connect to Shopify" button is now disabled until the merchant ticks a checkbox that explains exactly what content will be sent to `app-wash.tradik.com` and links to the Privacy Policy and the External services section of this readme. Consent is persisted in two options (`wash_consent_accepted`, `wash_consent_accepted_at`), shown back to the merchant on the same screen ("Consent recorded on YYYY-MM-DD HH:MM"), and removed by `uninstall.php`. New AJAX action `wash_save_consent` (capability + nonce gated) records the acceptance without leaving the page. No data leaves WordPress on a fresh install until the merchant interacts with this UI.

= 1.26.4 =
* wp.org submission compliance pass. (1) Plugin header license changed from MIT to "GPLv2 or later" in `wash.php` and `readme.txt` so it matches the wp.org Plugin Directory requirement (GPL-compatible umbrella; existing MIT-licensed source files remain MIT-compatible underneath). (2) Added a full `== External services ==` section documenting every host the plugin contacts (`app-wash.tradik.com`, `app-wash.tradik.dev`, `app-wash1.tradik.com`), what is sent (post content, images, metafields, site URL, API key, optional HTTP Basic Auth, REMOTE_ADDR rate-limit key), when (`save_post`, manual/bulk sync, settings refresh, connect handshake), why, and links to the Privacy Policy and documentation pages on `wash.tradik.com`. (3) Aligned the release-pipeline slug with the plugin's Text Domain: `PLUGIN_SLUG` in `.github/workflows/wordpress-release.yml` is now `wash-blog-manager` (was `wash`), so the wp.org SVN deploy lands at the correct slug and the inner ZIP folder matches; `.github/workflows/php.yml` CI artifact renamed from `washugin.zip` (typo) to `wash-blog-manager.zip` and now uses the same clean-tree exclusions as the release workflow; the root `npm run plugin:zip` script now produces `wash-blog-manager.zip` with the slug folder inside (was `wash-plugin.zip` with bare files at root, which WordPress's plugin installer rejects). No plugin behaviour change.

= 1.26.3 =
* Cleared the final three Plugin Check warnings. (1) RankMath redirections query in [class-wash-admin.php](packages/wordpress-plugin/includes/class-wash-admin.php) now uses the `%i` identifier placeholder (WP 6.2+) inside `$wpdb->prepare()` instead of interpolating the table name into the SQL string — eliminates `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` at the source rather than suppressing it. (2) `ajaxSaveSearchReplaceRules` `$_POST['rules']` block uses `phpcs:disable/enable` instead of a single-line `phpcs:ignore` so the multi-line ternary is fully covered. (3) `renderSyncNotices` `$_GET['wash_error_msg']` ternary is rewritten as an `if` block with an intermediate `$rawErrorMsg` variable; `sanitize_text_field()` is still applied, but rebreaking the call chain lets WPCS see it (it couldn't pierce the `urldecode()` wrapper). No behaviour change; all 261 PHPUnit tests still pass.

= 1.26.2 =
* Prefixed all top-level variables in `admin/views/settings-page.php` (~80 vars: `$isConnected`/`$shopifyStore`/`$planInfo`/…) and `uninstall.php` (4 vars: `$optionKeys`/`$siteIds`/`$siteId`/`$key`) with `$wash_` to satisfy `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound`. View scope is isolated (included from `WashAdmin::renderSettingsPage()`) so this is a renamer-only change with no functional impact; same-named locals inside other classes' methods are untouched.

= 1.26.1 =
* Round-two WordPress.org Plugin Check pass on top of 1.26.0. Sanitized the remaining JSON/array AJAX payloads (metafield config, mapping reorder, search-replace rules) with `map_deep(sanitize_text_field)` or per-field validation inside the foreach (no behaviour change — every field was already validated downstream). Wrapped admin-list-page $_GET reads (`wash_result`, `wash_error_msg`, `wash_bulk_action`, `wash_bulk_error`, bulk transient token) in `wp_unslash`/`sanitize_key` and switched to `isset()`+`absint()` for bulk-result counters. Suppressed the remaining "Recommended" warnings (NonceVerification on the Shopify-app callback handler and on read-only admin notice handlers; DirectDatabaseQuery on the RankMath redirections probe and the wash_sync custom table; meta_query slow-query notices; WPML/the_content/document_title_separator/https_local_ssl_verify hooks consumed from WPML and WordPress core; set_time_limit/print_r/error_log used inside debug-gated or batch paths) via inline `phpcs:ignore` with per-site justifications so they no longer drown the report. Added a phpcs:ignore for the WP 6.3-6.6 fallback `wp_get_global_styles_custom_css()` call (the WP 6.7+ `WP_Theme_JSON_Resolver` path is still preferred). All 261 PHPUnit tests still pass after extending `WashAdminBulkProgressTest::setUp()` with Brain\Monkey stubs for the two new helper calls.

= 1.26.0 =
* WordPress.org Plugin Check compliance pass with no logic changes. Renamed plugin text domain from `wash` to `wash-blog-manager` to match the slug (707 i18n calls + header, plus `languages/wash-*.po`/`wash.pot` renamed to `wash-blog-manager-*.po`/`wash-blog-manager.pot` so existing translations keep loading). Hardened input handling: all 8 `wp_redirect()` calls switched to `wp_safe_redirect()`; every superglobal read (`$_GET`/`$_POST`/`$_SERVER`) wrapped in `sanitize_text_field(wp_unslash(...))` (24 sites incl. nonce verification, basic auth headers, REMOTE_ADDR rate-limit key, AJAX rule reorder); `stripslashes()` replaced with `wp_unslash()` in `ajaxReorderMappings`. Added WP 6.7+ fallback for the deprecated `wp_get_global_styles_custom_css()` (reads merged theme.json via `WP_Theme_JSON_Resolver` when available). All 261 PHPUnit tests still green.

= 1.25.0 =
* RankMath FAQ blocks now survive the sync to Shopify. The block's nested rank-math-* wrappers were stripped by Shopify's article body sanitizer, leaving an unstyled list of questions with no schema. The plugin now rebuilds each FAQ as a Shopify-safe <details>/<summary> accordion (inline-styled, links preserved) and appends a FAQPage JSON-LD script for SEO parity with WordPress. Toggle via the `wash_transform_rankmath_faq` option (on by default).

= 1.24.1 =
* Fixes the 1.24.0 release pipeline so the signed media endpoint actually reaches production — the queue worker image failed to publish in 1.24.0 (out-of-sync lockfile), so image syncs from sites behind HTTP Basic Auth kept failing with 401. No plugin behaviour change vs 1.24.0.

= 1.20.9 =
* Added Rule ID badge to each row in the Blog Mappings list (admin UI). Click the badge to copy the ID to clipboard. Makes it easier to identify which rule is which when troubleshooting overlapping rules or referencing them in support tickets.

= 1.15.3 =
* Added Search & Replace tab — define text replacement rules applied to content before syncing to Shopify (Business plan)
* Useful for converting shortcodes, rewriting URLs, removing unwanted HTML attributes

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
