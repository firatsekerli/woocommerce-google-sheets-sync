# CLAUDE.md — Project Guide for WooCommerce Google Sheets Sync

Context for any Claude session working on this plugin. Read this first.

## What this is
A WordPress/WooCommerce plugin that syncs products **from a Google Sheet → WooCommerce**
(one main direction) plus an **Export** (WooCommerce → Sheet). Version `1.2.0`.
Repo: `firatsekerli/woocommerce-google-sheets-sync`.

- **Working branch:** `claude/tender-thompson-1c8tz3` (develop here; commit + push here).
- Owner runs a dev/staging site on **xCloud** (`wapitiplugintest.1wp.site`).

## Architecture / key files
- `woocommerce-google-sheets-sync.php` — main plugin file, constants, activation/defaults.
- `includes/class-wc-gs-sync-handler.php` — **the engine** (largest file): AJAX handlers,
  batched background sync, per-row processing, create/update/delete, variable
  products, write-back, change detection, image handling.
- `includes/class-wc-gs-product-data-builder.php` — turns a sheet row into a
  `$product_data` array (field mapping, attributes, meta, images, slug, categories).
- `includes/class-wc-gs-product-exporter.php` — WooCommerce product → sheet row(s).
- `includes/class-google-sheets-api.php` — Google API wrapper (OAuth, read/write).
- `includes/admin/` — settings, dashboard, views (`add-sheet`, `configure-sheet`,
  `settings`, `sync-dashboard`, `help`).
- `vendor/` — trimmed Google API client (Sheets/Drive/Oauth2 only). **Ships** in the zip.
- `docs/` — `SHEET_COLUMNS.md` (full column reference), `VARIABLE_PRODUCTS_PLAN.md`,
  `PRE_LAUNCH_TODO.md`, `examples/`.

## How the sync works (engine flow)
1. `start_background_sync()` reads the sheet, builds ordered **work items**
   (`build_work_items`): each carries its real `sheet_row`, `kind`
   (simple/variable/variation) and `parent_sku`. **Parents are sorted before
   variations** so a variation's parent exists when it's processed.
2. Processed in **batches** (`process_sync_batch` → `run_sync_batch` →
   `process_row_into_state`). First batch(es) run **inline** (15s budget); the
   remainder hands off to **Action Scheduler** (25s budget/batch). Batch size is a
   setting (default 10, max 100).
3. Per row: `process_product_row` (simple), `process_variable_parent_row`, or
   `process_variation_row`. Change detection via `_wc_gs_data_hash` post meta
   (`compute_product_hash` — canonicalizes empties so new vs updated hash match).
4. `finalize_sync` → `reconcile_variable_products` (delete orphan variations,
   re-sync parents) → `write_sync_results_back` (IDs, SKU/GTIN/Qty, status,
   clear Delete/Force-Update cells).

## Sheet columns (see docs/SHEET_COLUMNS.md for the full list)
- Matched by **header name** (case-sensitive), except two **positional markers**:
  **`Attributes`** (columns to its right, up to `Meta`, are product attributes /
  variation attributes) and **`Meta`** (columns to its right become custom
  fields / post meta; header slugified to the key; ACF-aware).
- Notable: `Slug`, `Type` (simple/variable/variation), `Parent` (variation→parent
  SKU), `Virtual`/`Downloadable`/`Download Files`/`Download Limit`/`Download Expiry`,
  `Gallery Image 01–20` (+ `… Alt Text`), control columns `Force Update` / `Delete`,
  read-only `Sync Status`/`Sync Error`/`Last Synced`.
- **Two-way fields:** `SKU`, `GTIN`, `Quantity` — if edited in WooCommerce after the
  last sync (e.g. stock sold), WooCommerce wins and the value is written back.

## Major features implemented (this branch)
- **`drive.file` scope + Google Picker** — no "unverified app" warning, no
  verification needed. Requires a **Google API Key** setting + Picker API enabled.
  Existing users must disconnect/reconnect and re-pick sheets.
- **Virtual & downloadable** products.
- **Variable products** — full: parent + variation rows, attribute matching,
  orphan-variation deletion, per-variation change detection, bidirectional
  SKU/GTIN/Quantity, and **export** (parent + variation rows).
- **Sync Progress** panel with a 7th **Variations** stat (counted separately from
  products).
- **Slug** column; **gallery extended to 20**; **image alt text** applied on import.
- Security hardening pass (XSS, nonces, caps, SSRF, token logging, etc.).

## Key decisions (don't re-litigate)
- **One sheet** for simple + variable; row-per-variation; parent linked by **SKU**.
- Sheet is the **source of truth**, BUT a variable parent synced with **zero
  variation rows keeps** its variations (safety). **This is an OPEN decision** the
  owner was unsure about — revisit before launch.
- **Blank cell = "leave unchanged"** for `!empty()`-guarded fields (price, weight,
  description, sale price…). Notably **Sale Price can't be cleared by blanking** —
  flagged as a possible enhancement.
- `Category Path` is a **single hierarchical path** (`A > B > C`); no multi-category
  separator (owner aware; left as-is for now).
- Tests were built then **removed from the branch** (owner didn't want them shipped);
  the real bug fix they caught was kept.

## Open / not done
- **License gate** for variable products (needs a licensing layer — Lemon
  Squeezy/Freemius). Hook `wc_gs_sync_max_sheets` already exists.
- Items in `docs/PRE_LAUNCH_TODO.md`: gate debug logging behind a switch, bump
  compat headers, reconcile plugin metadata/branding, decide on unused
  `wc_gs_sync_logs` table, finalize release/version, external security review.
- The "zero variation rows" orphan rule decision (above).
- Optional perf: wrap batches in `wp_defer_term_counting`/`wp_suspend_cache_invalidation`.
- Optional: make Sale Price (and similar) clearable; multi-category `Category Path`.

## Gotchas / environment
- **xCloud WAF blocks the OAuth callback (403)** because the return URL contains
  `https://accounts.google.com`. Allow-list `wp-admin/admin.php?page=wc-google-
  sheets-sync&auth=callback` or relax the firewall during connect. (Documented in Help tab.)
- xCloud's **loopback is blocked**, so Action Scheduler dispatch lags (~36s between
  batches). For big imports: raise **Batch Size to 100**, run `wp action-scheduler
  run` via WP-CLI, and/or set a real server cron. First import is the heavy one;
  re-syncs skip unchanged rows.
- Large variable products (e.g. a 256-variation product in the owner's catalog) are
  slow in WooCommerce itself — that's a WooCommerce limitation, not the plugin.
- This dev sandbox can't run real WooCommerce/Google (wordpress.org blocked, no DB);
  real testing is **manual on the dev site**.

## Conventions
- Commit messages end with the `Co-Authored-By` + `Claude-Session` trailers.
- Don't bump the version or make unrequested changes without asking.
- `.distignore` is NOT present (tests were removed); if dev files are added later,
  exclude them from the shipped zip.
- Keep replies/PR comments frugal.
