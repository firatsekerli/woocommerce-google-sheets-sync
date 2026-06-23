# Changelog

All notable changes to **WooCommerce Google Sheets Sync** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Gallery images extended to 20** (was 8): `Gallery Image 01` … `Gallery Image 20`
  (each with an optional `… Alt Text` column). The count is filterable via
  `wc_gs_gallery_image_count`. Export already handles any number of gallery columns.
- **`Slug` column.** Sets the product URL slug (`post_name`). If filled it is used
  (lowercased/hyphenated); if blank, the slug is derived from the product Name.
  Duplicate slugs get `-2`, `-3`… appended automatically (via
  `wp_unique_post_slug`) so they stay unique. Included in change detection.
- **Variable products (import).** A variable product is a parent row
  (`Type = variable`) plus one row per variation (`Type = variation`, `Parent` =
  the parent SKU), linked by SKU. The parent row defines the variation attributes
  (all values, pipe-separated); each variation row carries its own attribute
  values, SKU, price, stock, weight/dimensions, shipping class, tax class,
  virtual/downloadable, image, GTIN, description (Short Description) and
  enabled/disabled (Status). The engine processes parents before variations,
  matches variations by SKU or attribute combination, and deletes orphan
  variations no longer in the sheet (the sheet is the source of truth). Unchanged
  variations are skipped on re-sync (per-variation change detection). **Export to
  Sheet** also handles variable products, expanding each to a parent row plus one
  row per variation. (Gating variable products behind the paid tier is still
  pending the licensing layer.)
- **Sync Progress now counts variations separately** from products: a new
  "Variations" stat (7th) tallies variation rows so a variable product's
  variations aren't conflated with the product totals (Created/Updated/Deleted/
  Skipped now reflect products — simple + variable parents). The product/variation
  breakdown is also written to the log.
- **Virtual and downloadable simple products.** Five new optional columns:
  `Virtual` and `Downloadable` (yes/no), plus `Download Files`
  (`Name | URL` per line), `Download Limit` and `Download Expiry`. They flow
  both ways (import and export), are included in change detection, and each
  column is optional — leaving one out means the plugin won't touch that aspect.
  A product can be both virtual and downloadable (e.g. an e-book).
- A "Meta" marker column (mirrors "Attributes"): every column to the right of a
  `Meta` header is written as custom post meta on the product. The header is
  slugified into the meta key (never underscore-prefixed; filterable via
  `wcgs_meta_key`), empty cells are skipped and clear that key on re-sync, and
  values go through ACF when a matching field is registered (else plain post
  meta). Attributes now stop at the `Meta` marker; export reads meta back too.
- A "Help" tab (next to Sheets and Settings) explaining how the plugin works in
  plain language: sync direction, what writes back, special columns, why rows are
  skipped, automatic syncing, and tips.
- Always-visible "Sync Progress" panel below Connected Sheets showing six stats
  (Processed, Created, Updated, Deleted, Skipped, Errors) plus the failed-row
  list. It is pre-filled with the most recent run and updates live during a sync.
- Filterable connected-sheet limit (`wc_gs_sync_max_sheets`, default `0` =
  unlimited) for a future Pro/free split. When a limit is set and reached,
  connecting a new sheet is blocked server-side and the UI shows an upgrade
  notice; editing already-connected sheets is always allowed.
- **Per-attribute control via header flag tags.** An attribute column header can
  carry bracketed tags to control that attribute: `[hidden]` keeps it off the
  product page, `[no-vary]` keeps it from driving variations on a variable product
  (combinable, e.g. `Material [hidden][no-vary]`). The tags are stripped from the
  attribute name; with no tags the previous defaults apply (visible, and used for
  variations on a variable parent). The tags round-trip through Export.
- **"Cancel Sync" button** in the Sync Progress panel. It cancels any queued
  background batches and removes the job/state so a batch mid-flight aborts and
  finalize never runs, then marks the run "cancelled" and stops the live updates.
  Products already imported are left intact; only the remaining work and sheet
  write-back stop.

### Fixed
- **Front-end variation dropdown now lists options in sheet order.** Variation
  display order is set by the attribute's *term* order, which the plugin never set —
  so the dropdown fell back to WooCommerce's default (creation/term order) and
  didn't match the sheet, even after variations themselves were ordered. The
  attribute's terms are now ordered (via `wc_set_term_order`) to match the order
  their values appear in the row, so the dropdown follows the sheet. This is also
  applied to **unchanged/skipped** rows, so the order corrects itself on a normal
  re-sync without having to force-update every product. (Term order is global per
  attribute taxonomy — if two products list the same attribute's values in
  different orders, the most recently synced product wins.)
- **Variations now display in sheet order.** WooCommerce sorts the admin Variations
  list by `menu_order` then newest-ID-first, and the plugin never set a
  `menu_order`, so variations appeared in the reverse of their sheet rows. Each
  variation is now assigned a `menu_order` matching its row position under its
  parent (applied on every sync, including to unchanged variations), so the order
  on the product page matches the sheet. (Manually reordering variations in
  WooCommerce will be reset to sheet order on the next sync — the sheet is the
  source of truth.)
- **Products with images no longer spuriously re-"update" on every re-sync.** The
  change-detection hash includes the images, but an image is represented as a
  source URL on the first sync and as a matched attachment ID once it has been
  imported — so the representation flipped on the next sync and the product looked
  changed even when the sheet was untouched (products *without* images correctly
  skipped). The hash now normalizes each image to its stable source URL, so the
  URL→ID flip alone is not treated as a change. *(One-time effect on upgrade: the
  first sync after updating may show image-bearing products as "updated" as their
  stored hash is recomputed, then it settles and unchanged rows skip.)*
- **Write-back to the sheet is no longer slow enough to be killed mid-run.** After
  a large import, write-back split the cell updates into chunks of `Batch Size`
  ranges and slept `rate_limit_delay` (1s) between *every* chunk — hundreds of tiny
  throttled API calls that took many minutes and ran past Action Scheduler's
  per-action time limit, so the final batch was "marked as failed after 300
  seconds" with only part of the sheet updated. Write-back now sends up to ~500
  ranges per `values.batchUpdate` request (one API call handles many cells) with no
  inter-request delay on success (it only backs off when retrying a real failure),
  and reports live "Writing results back to sheet… (X of Y cells)" progress. The
  per-request size is filterable via `wc_gs_writeback_chunk_size`.
- **Image alt text is now applied.** The `Image Alt Text` and
  `Gallery Image NN Alt Text` columns were read from the sheet but never written
  to the media attachment. They now set `_wp_attachment_image_alt` on the featured,
  gallery and variation images (for both newly uploaded and existing/matched
  images); a blank cell leaves the existing alt unchanged. Round-trips with export.
- **A product created from the sheet no longer spuriously re-"updates" on the
  next sync.** The change-detection hash treated a value missing on a new product
  (empties are stripped when the ID is blank) differently from the same value
  present-but-empty on an update, so freshly created products always showed as
  "updated" once. The hash now canonicalizes empties recursively. *(One-time
  effect on upgrade: the first sync after updating may show existing products as
  updated as their stored hash is recomputed, then it settles.)*
- **Changing a product's Type now takes effect.** Switching a row from `variable`
  to `simple` (or vice-versa) was ignored when nothing else changed, because the
  change-detection hash excludes Type, and the simple-update path never converted
  the product type. The sync now detects a type mismatch on the matched product
  and converts it (variable→simple removes its variations; simple→variable was
  already handled).
- **Delete = yes now moves a product to Trash instead of deleting it
  permanently.** The deletion used `wp_delete_post($id, false)`, which only
  trashes the built-in post/page types — for the `product` custom type it deletes
  permanently. It now goes through `WC_Product::delete()` (trash for products;
  variations, which have no Trash, are still removed).

### Changed
- **Google connection now uses the non-sensitive `drive.file` scope and the
  Google Picker.** Instead of browsing your whole Drive (which needed the
  restricted `drive.readonly` scope and triggered the "Google hasn't verified
  this app" warning), you pick the spreadsheet with Google's own file picker and
  the plugin only gets access to the sheets you choose. This removes the
  unverified-app warning and the need for Google verification/CASA entirely. A
  new **Google API Key** setting is required for the picker (enable the Google
  Picker API in your Google Cloud project). Existing users must Disconnect and
  reconnect once, then re-pick their sheets via the picker.
- Replace the sync progress popup with the always-visible "Sync Progress" panel
  (below Connected Sheets); during a sync it updates live, and the page refreshes
  on completion to show the final stats.
- Combine the separate "Google Sheets Sync" and "Sheets Settings" admin menu
  entries into a single page with **Sheets** and **Settings** tabs.
- Remove the non-functional "Start New Sync" button (syncing is per-sheet via
  "Sync Now") and retire the unused `admin.js` scaffolding. Its stub
  `saveSettings` handler called `preventDefault()` on the settings form, which
  would have blocked saving once the settings moved onto the combined page.

### Security
- Update bundled Composer dependencies to clear all known advisories
  (`composer audit` reports none): guzzlehttp/psr7 -> 2.11.1, firebase/php-jwt
  -> 7.1.0, google/apiclient -> 2.19.3. phpseclib and paragonie are no longer
  required and were dropped from the vendor tree.
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
- Detect unchanged rows and skip them: an existing product is only updated when
  the data actually changed (tracked via a `_wc_gs_data_hash` on the product), so
  re-syncing an unchanged sheet now reports those rows as Skipped (not Updated)
  and avoids re-saving them. Force Update still forces an update.
- Stop re-downloading images on every sync (match imported media by source URL).
- Treat a row whose `Sync Status` is `deleted` as a tombstone: it is skipped and
  left untouched on subsequent syncs, so a deleted product is not recreated.
  Clear the `deleted` value to import the row again.
- Clear the `Delete` and `Force Update` cells in the sheet after a sync, so these
  one-time flags do not re-trigger on the next run (fixes the recurring
  "Cannot delete product: Product not found" error).
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
