# Changelog

All notable changes to **WooCommerce Google Sheets Sync** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
