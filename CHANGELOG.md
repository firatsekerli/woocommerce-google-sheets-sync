# Changelog

All notable changes to **WooCommerce Google Sheets Sync** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Fix admin-context XSS in the sync progress UI: per-row error messages (which
  embed sheet-derived values like SKU/GTIN/name) were injected into the DOM as
  HTML in `sync.js`. They are now rendered with `.text()`.
- Escape the Google API error message in the add-sheet view (`esc_html`).
- Refuse to delete a post via the sheet "Delete" column unless it is actually a
  `product` / `product_variation`.
- Escape the Google Drive search query per Drive rules instead of `addslashes()`.
- Stop logging the OAuth authorization code and full token payload (access /
  refresh tokens) to `debug.log`.
- Add capability checks (`manage_woocommerce`) to the remove-sheet, disconnect,
  and configure-sheet save actions, which previously relied on a nonce alone.
- Move the remove-sheet, disconnect, and configure-sheet save handlers to early
  `admin_init` hooks so capability/nonce checks and redirects run before output
  (the configure-sheet save previously redirected after render).
- Add OAuth CSRF protection: pass a `state` nonce on the auth URL and verify it
  on the callback.
- Add nonce verification to the sync-progress AJAX endpoint (and send it from JS).
- SSRF protection on image imports via `wp_http_validate_url()` (blocks
  localhost / internal IPs).
- Never re-render the saved Google Client Secret into the settings HTML; keep the
  stored value when the field is left blank.
- Remove the Google access token, connected sheets, and leftover job/state
  options on uninstall; add the direct-access guard to the data-builder class.
- Guard and `wp_unslash()` request inputs (nonces, sheet ids, OAuth code/error/
  state), use `wp_safe_redirect()`, and escape previously unescaped output (auth
  error message, dynamic admin URLs).

### Fixed
- Attribute column headers that sanitize to the same slug (e.g. `WS` and `W&S`
  both -> `pa_ws`) no longer collide into one attribute. Attributes are now
  matched/created by their exact label, and a unique slug is generated
  (`ws`, `ws-2`, …) when needed. Export resolves attributes by label too.
  (Note: attributes that already collided on a previous version are not
  auto-cleaned — remove the mixed attribute and re-sync.)

### Changed
- Simplified the per-sheet configure screen: removed the non-functional
  **Sync Direction**, **Header Row**, and **Data Start Row** fields (the engine
  assumes row 1 = headers, row 2 = data; direction is handled by the Sync Now /
  Export buttons). The configure screen now also pre-fills when editing an
  existing connection.
- Wired up **per-sheet Auto Sync**: scheduled syncs now only run for sheets that
  have "Include this sheet in scheduled automatic syncs" checked. The global
  Enable Auto Sync setting acts as the master switch + interval.
- Syncs now run in the background via **Action Scheduler** (bundled with
  WooCommerce), processed in `batch_size`-sized chunks instead of inline in the
  AJAX request. This removes PHP timeout/memory risk on large catalogs and makes
  scheduled auto-sync reliable. Falls back to inline batched processing if
  Action Scheduler is unavailable. Per-sync state is persisted between batches so
  write-back still runs once at the end.

## [1.1.0]

### Added
- Export all existing WooCommerce products into a connected sheet (WooCommerce → Sheet).
- Working scheduled auto-sync via WP-Cron (hourly / twicedaily / daily / weekly), driven
  by the Automatic Sync settings, with a server-cron setup hint on the settings page.
- Global product attributes for filtering / layered navigation (the `Attributes` marker
  column; every column to its right becomes a `pa_*` attribute).
- Upsells and cross-sells (linked by product ID or SKU).
- Tax Status, Tax Class, Purchase Note, Position (menu order), and Allow Reviews columns.
- `Force Update` column to bypass conflict resolution and make the sheet authoritative.
- Separate handling for Visibility (public/private/password) and Catalog Visibility
  (visible/catalog/search/hidden).
- `docs/SHEET_COLUMNS.md`: complete column reference with accepted values and formats.

### Fixed
- Duplicate sync handler instantiation caused each sync to run twice.
- Fatal error when setting a product's shipping class (used a non-existent method).
- Low Stock Threshold validation rejected valid input (boolean vs string check).
- Undefined `$original_quantity` variable in the quantity write-back logic.
- Several parsed fields (featured, catalog visibility, sale dates, sold individually,
  dimensions, shipping class) were never applied to products.

### Changed
- Catalog Visibility now reads the `Catalog Visibility` column (previously the
  `Visibility` column was misused for it).
- Settings save now validates/sanitizes input; added capability checks and guarded
  request superglobals on the sync/export AJAX handlers.
- Throttle settings (batch size, rate limit delay, max retries) are now actually used,
  and long-running syncs raise the PHP time/memory limits.
- Removed unused, non-functional scaffolding classes and dead AJAX stubs.
- Trimmed the bundled Composer dependencies to only the Google services in use
  (Sheets, Drive, Oauth2) and pinned this via the apiclient-services cleanup config.

## [1.0.0]

### Added
- Initial release: connect to Google Sheets via OAuth, bulk import/update products,
  sync logs, and support for images, categories and basic fields.
