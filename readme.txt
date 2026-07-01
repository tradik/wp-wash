=== Wash: Blog Manager ===
Contributors: tradik
Tags: shopify, sync, content, blog, multisite
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.28.2
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

* Privacy Policy: [https://wash.tradik.com/privacy-policy/](https://wash.tradik.com/privacy-policy/)
* Documentation / Support: [https://wash.tradik.com/docs/](https://wash.tradik.com/docs/)

The plugin does not send any data to third-party analytics, telemetry, advertising, or tracking services. It does not contact any host other than the three listed above.

**Consent flow:**

On a fresh install the plugin is inert — no requests leave WordPress until the merchant opens **Settings → Wash → Connection**, ticks the "I understand that connecting this plugin will send my WordPress post content … to the Tradik Wash service" checkbox (which links to the Privacy Policy above and to this External services section), and then clicks **Connect to Shopify**. The Connect button is disabled until the consent checkbox is ticked. The decision is persisted in the `wash_consent_accepted` and `wash_consent_accepted_at` options and shown back to the merchant on the same screen ("Consent recorded on YYYY-MM-DD HH:MM"). Uninstall removes both options.

== Changelog ==

= 1.28.2 =
Fixed multi-store publishing so a post only reaches stores it is mapped to.

* **A post no longer publishes to a store it isn't mapped to.** With more than one Shopify store connected, a post was fanned out to every store and — where no blog-mapping rule matched — published to that store's first blog anyway. A US-mapped post could therefore appear on the UK store. Now, with 2+ stores connected (or once a store defines mapping rules), a post publishes ONLY to stores whose rules it matches. Default (first-blog) publishing remains only for a single store with no rules (legacy).
* **Transient errors no longer misroute.** If a store's rules can't be read momentarily, publishing to it is skipped rather than guessed, so a hiccup never lands content on the wrong store.

= 1.28.1 =
Fixed market translation sync and multi-store status/links on the Posts list.

* **Blog-mapping "Market & Locale" no longer offers an invalid locale for markets without a storefront.** A market without a web presence derived its locale from the country code; for markets like the US ("us") or UK ("gb") that is not a valid Shopify locale, so Shopify rejected it ("Locale is invalid") and translations silently failed to register. These markets now fall back to the shop's primary locale. Markets whose country code is already a valid shop locale (e.g. "fr", "de") are left exactly as before, so existing working setups are untouched.
* **"View on Shopify" and "Re-sync" now match the green sync icon on the Posts list.** For a post synced only to an additional (non-primary) store, the row actions wrongly showed "Sync to Shopify" (as if never synced) and hid the "View on Shopify" link, even though the green icon reported it synced. Row actions now read the same per-store sync state as the icon, so a synced post always shows "Re-sync to Shopify" plus a "View on …" link per store.
* **The green sync icon is never a dead link.** When a public storefront URL can't be built (e.g. a market with no web presence), the icon and the "View on Shopify" link now fall back to the Shopify admin editor instead of an empty link.
* **Manual sync no longer misreports success as "queued"/failed.** Syncing a post that targets a secondary store now reports the real outcome across every store, instead of reading only the primary store's metadata.

= 1.28.0 =
Multi-store — each connected Shopify store now has its own full settings, independent of every other store (#252).

* **Every additional store opens the full plugin, not a stripped-down form.** Opening a store under Settings → Wash now shows the complete tabbed UI (Connection, Blogs, Redirections, Hreflangs, Meta Fields, Authors, Files, Content, Search & Replace, Sync, Advanced) scoped to that store, with its name in the heading so you always know which store you are configuring.
* **Independent settings per store.** Blog mappings, redirections, markets, locales, meta-field config, search-and-replace rules, sync preferences — and every other option — are stored and read per connection, so changing one store never bleeds into another. The primary (first) store is unchanged.
* **Single navigation, single add-store flow.** One top-level "Wash" entry; additional stores appear as their own Settings submenu items. The duplicate "Connections" page is gone — connect or remove stores from one place (a "New connection" button reveals the same connect panel the primary store uses, consent row included). Adding stores still requires a Business/Enterprise plan on the primary connection.
* **Per-store status on the Posts list.** With more than one store enabled, the Shopify column shows one icon per store (synced / queued / failed / not-synced), each linking to that store's article, so you can see a post's status across every site at a glance.
* **Disabled stores read as paused (grey), live stores green** — at a glance in the connections list.

= 1.27.3 =
Fix — bulk re-publish no longer shows a 504 timeout on large batches (#253).

* **No more 504 on big migrations** — bulk Publish / Re-publish / Unpublish processes posts in short, time-bounded AJAX batches (default up to 10 posts or ~20s per request, whichever comes first), so a 100–200 post run never holds a single request open long enough for the reverse proxy (Cloudflare) to return a 504. The live progress bar and the final "synced / skipped / errors" summary are unchanged.
* **A timed-out batch no longer abandons the rest** — if one batch request still fails, the progress page retries it and then skips ahead instead of stopping the whole run (the server keeps processing in the background), so the migration finishes on the first pass.

= 1.27.2 =
Fix — "View on Shopify" links lost the market subfolder, and unpublish didn't stick (#255).

* **Correct market subfolder** — "View on Shopify" links (posts list, editor, Gutenberg) now build the URL from your Shopify Markets web presence, so a localized post links to e.g. `/de-ch/blogs/...` (or `/fr-eu/...`) and uses your real storefront domain, instead of dropping the prefix and 404-ing on the localized market. The market is derived automatically from the rule's country (e.g. `fr-fr` → the market that covers France) when no market is set on the rule.
* **No-mapping guard** — a post whose language matches no blog-mapping rule is flagged "No mapping" in the posts list and is no longer published to a guessed blog; blog-mapping rules without a Shopify market assigned are flagged in Settings ("no market — no localized URL").
* **Unpublish sticks** — "Unpublish from Shopify" now clears the synced status and pauses auto-sync, so a still-published post is not immediately re-pushed; the delete callback no longer re-stores the article id (which made the "synced" icon come back).
* **Bulk unpublish no longer silently skips** — it reports how many posts were skipped and why (not synced / not published / no mapping), and detects posts synced to a secondary store (multi-store).
* **Each post syncs to its own Shopify article** — translation merging (many locale posts onto one article) is off by default; it raced under bulk and could leave a "synced" status whose link 404s. Bulk "Publish to Shopify" now syncs every selected post (create or update) instead of skipping already-synced ones.

= 1.27.1 =
Fix — content images no longer stay on the WordPress source after sync (#249).

* The signed media endpoint now flushes any buffered output before streaming a file, so a stray newline from another plugin/theme can't corrupt the image bytes (Shopify was rejecting them as "invalid image data", leaving the post pointing at the source).
* Synced posts that still have an image served from the source are now flagged in the posts list (hover the Shopify column) instead of showing a silent success.

= 1.27.0 =
Multi-store — connect multiple Shopify stores from one WordPress site (#216).

* **Connections manager** (Settings → Wash: Connections) — add a store via Shopify OAuth or by pasting its API key, then enable/disable or remove it. Each additional store keeps its own sync settings.
* **Fan-out sync** — publishing (and unpublish/delete) now reaches every enabled store; inbound sync results route back to the right store by its API key.
* **Plan-gated** — adding stores requires the Business or Enterprise plan; a downgrade automatically disables the additional stores.
* Single-store installs are unchanged — existing setups keep working exactly as before, with no migration.

= 1.26.9 =
Hardening release — bundles the post-launch code audit (CRIT / HIGH / MED / LOW fixes).

* **Reliable image sync** — the image-sync callback loop is now closed end-to-end, so body images consistently rehost to the Shopify CDN instead of staying on the WordPress host (CRIT-3, #133). Two cases that could serve or reference the wrong image bytes are fixed (MED-10/19, #152/#161).
* **Safer multi-locale publishing** — a bulk *Unpublish* no longer deletes a Shopify article shared across locales (HIGH-4, #138). Sync status is no longer cleared when the app returns an error, so a transient failure can no longer mark an already-synced post "not synced" (HIGH-8, #141).
* **Metafields no longer silently dropped** when syncing (MED-8/11/12, #150/#153/#154).
* **Signed, replay-protected callbacks** on the Shopify → WordPress write path (MED-13 / LOW-3, #155/#164).
* **Plugin self-update** — WordPress now shows the standard "update available" notice and the plugin updates itself from the connected Shopify app (LOW-6, #167).
* **Host-agnostic internal links** — internal article links are rewritten by path, so they survive WordPress/Shopify domain differences (#179).
* **Robustness** — atomic redirect lock + canonical site-URL handling (LOW-12/4, #173/#165), rate-limiter throttle-pause and dead-code removal (MED-18 / LOW-2, #160/#163), WP↔app↔worker payload-contract alignment (LOW-17, #178), batched cleanup and opt-in data purge on uninstall (LOW-8/11/15, #169/#172/#176/#174), and timestamp / usage-record / definition-cache / filter edge-case fixes (LOW-5/7/10/14).

= 1.26.8 =
* wp.org Plugin Directory review fix — **"not connected" admin notice no longer shown dashboard-wide** (Guideline 11 — do not hijack the admin dashboard). The setup notice was hooked to `admin_notices` with no screen check and wasn't dismissible, so it appeared on every wp-admin page. It is now scoped to the content screens where it is actionable (post list + post editor) and is dismissible. The Shopify product/collection sync notices were already scoped to the Media library screen.
* wp.org Plugin Directory review note — **shortcode/block passthrough callbacks documented as intentional raw passthroughs** (guideline "Callback return values must be escaped"). The four auto-registered passthroughs for custom Search & Replace blocks/shortcodes (in `class-wash-sync.php` and `class-wash-blocks.php`) return their inner `$content` unchanged. They exist only so WordPress does not strip those custom entities, and their output is rendered through `the_content` when building the article body synced to Shopify — so escaping or `wp_kses`-ing them would corrupt outbound Shopify content. The content is already KSES-sanitized at save time for users without `unfiltered_html`. Each site now carries a documented `phpcs:ignore`, and a regression test pins the verbatim-passthrough contract. No behaviour change.
* wp.org Plugin Directory review fix — **per-post permission check on the `/sync-status` REST route** (guideline "Check permission_callback in REST API Route"). All seven `wash/v1` routes already declared a `permission_callback`, but `GET /sync-status/<post_id>` used a global `edit_posts` check, so any contributor/author could read — and, on the timeout path, mutate (`_wash_sync_*` meta) — the sync state of posts they cannot edit. It now gates on the per-post `edit_post` meta-capability via a new `checkEditPostPermission()`. Legitimate Gutenberg use (checking the post you're editing) is unaffected. The post-agnostic Shopify product/collection lookups keep the global `edit_posts` check (they expose no per-post data).
* wp.org Plugin Directory review fix — **broken header / readme URLs corrected** (guideline "The URL(s) declared in your plugin seems to be invalid"). The optional `Plugin URI` header (which pointed at a private GitHub repo and returned 404) has been removed — it is not a required header. The Privacy Policy link now points at the live `https://wash.tradik.com/privacy-policy/` page, and every documentation link in the readme and the Settings UI now uses the live path-based docs (e.g. `https://wash.tradik.com/docs/custom-fields/`) instead of the old stale pages that returned 404. All replacement URLs were verified to return HTTP 200.
* wp.org Plugin Directory review fix — **CSRF nonce on the Shopify connect callback** (guideline "Nonces and User Permissions Before Processing Requests"). `WashAdmin::handleCallback()` writes `wash_api_key` / `wash_shopify_store` from the post-OAuth redirect; it already required `manage_options`, but a logged-in admin could be tricked into clicking a forged link that overwrote their stored API key. The connect button now embeds a one-time `wash_connect` nonce in the `return_url`; the Shopify app round-trips that URL back verbatim (it only appends `api_key`/`store`), so the callback verifies the nonce with `wp_verify_nonce()` before storing anything and otherwise redirects to a clear error. No Shopify-side change was needed. A full audit confirmed every AJAX handler (`wp_ajax_*`), the manual/bulk/media sync entry points, the Settings API form and the bulk-action dispatch already enforce nonce + capability checks.
* wp.org Plugin Directory review fix — **enqueue admin JS instead of inline `<script>` tags** (guideline "Use wp_enqueue commands"). The bulk-sync progress page (`includes/class-wash-admin.php`) now loads its driver from `admin/js/bulk-progress.js` via `wp_enqueue_script()`, with the per-run token, nonce, totals, redirect and translated status strings passed through `wp_localize_script()` (object `WashBulkProgress`). The two admin scripts in `admin/views/settings-page.php` (meta-field config saver and the settings-form/locked-tab initialiser) are now delivered with `wp_add_inline_script()` attached to the already-enqueued `wash-admin` handle — output-buffered so every PHP interpolation still resolves to plain JS. No behaviour change for merchants. The `<style>` block in `class-wash-page-builders.php::wrapWithCss()` and the FAQPage JSON-LD `<script>` in `class-wash-sync.php` were also flagged but are **not** enqueueable: both are embedded into the self-contained HTML exported to Shopify, not output on a WordPress page, so they remain inline by design.

= 1.26.7 =
* Added a bulk **"Refresh all sync statuses"** button (Settings → Wash → Sync) that rebuilds the synced / not-synced indicators for every published post from Shopify — without re-syncing content. Use it to recover the status dots after they disappear (e.g. when the plugin is *deleted* and reinstalled: WordPress runs `uninstall.php`, which clears all `_wash_*` post meta). It reconciles in batches against `/api/check-status` (the same source of truth the per-post "Refresh status" row action uses) and shows live progress. The per-post and bulk paths now share one reconcile routine.

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
