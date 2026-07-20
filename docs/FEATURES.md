# WooCommerce Google Sheets Sync — Complete Feature Reference

A full inventory of everything the plugin does. For the exact per-column format,
see [`SHEET_COLUMNS.md`](SHEET_COLUMNS.md); for the variable-products design, see
[`VARIABLE_PRODUCTS_PLAN.md`](VARIABLE_PRODUCTS_PLAN.md).

---

## 1. What it is

Run your WooCommerce catalog from a Google Sheet. Edit products in the sheet and
push the changes into WooCommerce with one click or on a schedule; or export your
existing WooCommerce products into a sheet so it becomes the master copy.

- **Primary direction:** Google Sheet → WooCommerce (import).
- **Reverse direction:** WooCommerce → Google Sheet (export / write-back).
- **Source of truth:** the sheet (with a few deliberate exceptions — see §9).

---

## 2. Sync directions

### 2.1 Sheet → WooCommerce (import)
- **Create, update, and delete** products from sheet rows.
- Each row is matched to a product by **ID → SKU → Name**, in that order.
- **Manual** (the *Sync Now* button per connected sheet) or **scheduled** (§12).
- **Change detection:** unchanged rows are skipped (a per-product `_wc_gs_data_hash`
  is compared), so re-syncs are fast and only touch what changed.
- **Delete** via a `Delete` column (`yes`) — trashes the product (variations, which
  have no trash, are force-deleted). A `Sync Status = deleted` tombstone prevents a
  deleted row from being recreated.

### 2.2 WooCommerce → Sheet (export)
- *Export Products to Sheet* writes every product (simple + variable) into the
  connected sheet, replacing the data rows and keeping the header row.
- Variable products expand to a **parent row + one row per variation**.
- **Blank sheet:** the plugin writes a complete, round-trippable header template —
  the full fixed column set (incl. `Slug`, `Image Alt Text`, `Gallery Image 01–20`
  with alt-text columns) **plus** an `Attributes` marker + a column per global
  attribute + a `Meta` marker — so exported variable products re-import correctly.
- **Existing header row is respected** — export fills values into whatever columns
  the sheet already has.

### 2.3 Two-way fields
- **Quantity** is genuinely two-way: if stock changed in WooCommerce after the last
  sync (e.g. orders), WooCommerce wins and the value is written back to the sheet.
- **SKU** and **GTIN** are strictly **sheet-driven** (the sheet always wins; not
  written back from WooCommerce), except that auto-generated SKUs are written back
  once so they're pinned (§9).

---

## 3. Product types

- **Simple products** — full field support.
- **Variable products** — a parent row (`Type = variable`) plus one row per
  variation (`Type = variation`, `Parent` = the parent's SKU), linked by SKU:
  - Parent row defines the variation attributes (all values, pipe-separated).
  - Variation rows carry a single value per attribute + variation-level fields
    (price, sale schedule, stock, weight/dimensions, shipping/tax class,
    virtual/downloadable + downloads, image, GTIN, description via *Short
    Description*, enabled/disabled via *Status*).
  - **Variation matching:** by ID → SKU → exact attribute combination.
  - **Orphan variations** (in WooCommerce but no longer in the sheet) are deleted —
    only for parents that had at least one variation row this run (safety).
  - **Order:** variations are ordered on the product page and admin to match the
    sheet (see §5.3).
  - Variable products are also fully supported by **export**.

---

## 4. Field coverage

Matched by **header name** (case-sensitive). Full list and formats in
`SHEET_COLUMNS.md`. Highlights:

- **Identity / stock:** ID, SKU, GTIN·UPC·EAN·ISBN, Stock Management, Quantity,
  Stock Status, Backorder, Low Stock Threshold, Sold Individually.
- **Basics:** Name, Slug, Description, Short Description, Type, Parent.
- **Virtual / downloadable:** Virtual, Downloadable, Download Files, Download
  Limit, Download Expiry.
- **Status / visibility:** Status, Visibility (public/private/password), Password,
  Catalog Visibility, Featured.
- **Pricing / tax:** Regular Price, Sale Price, Sale Start/End Date, Tax Status,
  Tax Class.
- **Shipping:** Weight, Dimension (L/W/H), Shipping Class.
- **Relationships / taxonomy:** Upsells, Cross-sells, Category Path (§6), Tags.
- **Misc:** Purchase Note, Position (menu order), Allow Reviews.
- **Read-only / control:** Sync Status, Sync Error, Last Synced (written back);
  Force Update, Delete (one-time controls, auto-cleared after use).

**Blank-cell semantics:** for most fields a blank cell means *leave unchanged*
(the value is only applied when present). Optional columns that are *absent
entirely* (e.g. Virtual/Downloadable) leave the product's current value untouched.

---

## 5. Attributes

### 5.1 Global attributes via a positional marker
- The **`Attributes`** column is a marker; every column to its right (up to a
  `Meta` marker, or the end) is a global product attribute (`pa_*`) used for
  filtering / layered nav. Header = attribute name; cell = value(s)
  (comma/semicolon/pipe separated).
- Slug collisions are handled automatically (e.g. `WS` and `W&S` stay separate).

### 5.2 Per-attribute control via header flag tags
Bracketed tags in an attribute's **header** control that attribute (combinable):
- **`[hidden]`** — attribute is not shown on the product page (still created and
  usable for filtering/variations). e.g. `Material [hidden]`.
- **`[no-vary]`** — on a variable product, not used for variations (kept as a plain
  display/filter attribute). e.g. `Material [no-vary]`.
- Combine: `Material [hidden][no-vary]`. No tags = defaults (visible; used for
  variations on a variable product). Tags round-trip through export.

### 5.3 Ordering
- **Variation order** (admin list + which variation a selection maps to) matches
  the sheet rows (via `menu_order`).
- **Front-end attribute dropdown order** matches the order values appear in the row
  (via the global attribute *term* order). Self-heals on every sync (writes only
  when the order actually drifted). *Note: term order is global per attribute, so
  if two products list the same attribute's values in different orders, the most
  recently synced product wins.*

---

## 6. Categories

- **`Category Path`** with `>` for hierarchy (`Electronics > Phones > iPhone`) —
  the product is added to the leaf **and** all its ancestors.
- **Multiple categories:** separate branches with `|`
  (`Brush Cutters | Trenchers > Mini`). Separator filterable.
- Categories are **replaced** on each sync (sheet is source of truth).

---

## 7. Custom meta & integrations

- The **`Meta`** column is a marker; every column to its right becomes custom post
  meta. The header is slugified to the meta key (underscores; filterable), empty
  cells clear the key on re-sync.
- **ACF-aware:** if an ACF field with that name exists, the value is written via
  `update_field()` (else plain post meta). Round-trips on export.
- **SEO plugins (Rank Math / Yoast):** target their meta keys directly by naming a
  Meta column accordingly (e.g. `Rank Math Title` → `rank_math_title`), or remap
  via the `wcgs_meta_key` filter.

---

## 8. Images

- **Featured image** (`Image`) + up to **20 gallery images** (`Gallery Image 01–20`),
  each with an optional **alt-text** column (`… Alt Text`). Gallery count filterable.
- Values are **image URLs**; alt text is applied to the media-library attachment
  and to variation images.
- **Deduplication:** an image is reused (not re-downloaded) when it was previously
  imported from the same URL (matched by a stored `_wc_gs_source_url`), or when the
  sheet cell holds the image's exact local WordPress URL. Otherwise it downloads a
  fresh copy once, then reuses it on later syncs.
- **Cleanup:** images a product no longer uses are removed from the media library
  (unless used by another product).
- **Safety / reliability:** URLs are SSRF-validated (`wp_http_validate_url` blocks
  localhost/internal hosts); downloads are time-capped so one slow/unreachable
  image can't stall a batch.

---

## 9. SKU handling

- **Matching:** products by ID → SKU → Name; variations by ID → SKU → attribute
  combination.
- **Auto-generation:**
  - Simple/parent rows with a blank SKU get a generated SKU (from Name) written back
    to the sheet.
  - **Variation** rows with a blank SKU get `<parent SKU>-01`, `-02`, … (first
    unused number), written back — so later syncs match by SKU rather than by
    attribute combination.
- **Sheet-driven SKU & GTIN:** the sheet always wins for these; a value changed
  directly in WooCommerce is overwritten by the sheet and **not** written back.
- **Uniqueness validation** understands matching: a row whose SKU already belongs to
  the product it will update is not flagged as a duplicate.

---

## 10. Change detection & write-back

- **Change detection:** a canonical `_wc_gs_data_hash` per product/variation; an
  unchanged row is skipped (counts as *Skipped*). Image identity in the hash is
  normalized so a URL→attachment-ID flip doesn't look like a change.
- **Force Update** column bypasses change detection for a row.
- **Write-back** (Sheet gets updated after a sync): product/variation **ID**, **Sync
  Status**, **Sync Error**, **Last Synced**, generated **SKU**, live **Quantity**,
  and GTIN where applicable. Write-back is batched into a few large API requests
  (fast) and reports live progress.

---

## 11. Background processing & reliability

- **Inline first batch** for immediate feedback, then the remainder runs in the
  background via **Action Scheduler**.
- **Per-batch wall-clock budget:** a batch yields after a time budget (not just a
  row count), so image-heavy rows can't overrun host request limits.
- **Host-resilient chaining:** each background pass does one short batch then queues
  the next — so it survives hosts that kill long requests, with **no server cron
  required**.
- **Self-heal:** if the background chain breaks, the dashboard's progress polling
  re-queues the sync from the last saved offset.
- **Cancel Sync** button — cancels queued batches and stops a run cleanly (products
  already imported are kept).
- **Sync Progress panel** — live progress bar + stats: Processed, Created, Updated,
  Deleted, Skipped, **Variations** (counted separately), Errors, per-run timing, and
  a failed-row list.
- **Throttling settings:** Batch Size, Rate Limit Delay, Max Retries (§14).

---

## 12. Automatic (scheduled) sync

- **Global auto-sync** switch + interval (Hourly / Twice Daily / Daily / Weekly)
  via WP-Cron.
- **Per-sheet** opt-in — only sheets flagged "include in scheduled syncs" run.
- Guidance provided for a **real server cron** (for reliable, on-time scheduling on
  low-traffic sites).

---

## 13. Google connection

- **OAuth** using the **non-sensitive `drive.file` scope** — no "unverified app"
  warning and no Google verification/CASA required.
- **Google Picker** to choose the specific spreadsheet (the plugin only accesses
  sheets you pick).
- **Bring-your-own credentials:** Client ID + Client Secret + an API Key (with the
  Picker API enabled), entered in Settings.
- Connection status, connect/disconnect, and multiple connected sheets supported
  (with a filterable sheet limit for a future Pro tier).

---

## 14. Settings

- **Google API:** Client ID, Client Secret (write-only; never re-rendered), API Key.
- **Sync Configuration:** Batch Size (1–100), Rate Limit Delay (retry back-off),
  Max Retries, **Debug Logging** (off by default).
- **Automatic Sync:** enable + interval, with server-cron guidance.
- Per-sheet configuration: tab name, include-in-auto-sync.

---

## 15. Deletion & uninstall

- **Row-level delete** via the `Delete` column.
- **Uninstall** removes plugin options, tokens, transients, and job/state; drops the
  legacy (unused) logs table. (Product meta and generated attributes are left
  intact by design.)

---

## 16. Security

- All AJAX and admin actions are **nonce- and capability-gated**
  (`manage_woocommerce`); no anonymous (`nopriv`) endpoints.
- OAuth callback is **CSRF-protected** (state nonce) and uses safe redirects.
- All SQL uses `$wpdb->prepare`; output is escaped; image imports are SSRF-guarded.
- Debug logging is off by default and never logs tokens/secrets.

---

## 17. Developer hooks (filters)

| Filter | Purpose | Default |
|---|---|---|
| `wc_gs_sync_max_sheets` | Max connected sheets (0 = unlimited) | `0` |
| `wc_gs_gallery_image_count` | Number of gallery image columns | `20` |
| `wcgs_meta_key` | Remap a Meta column header → meta key | slugified header |
| `wc_gs_category_path_separator` | Separator for multiple category paths | `\|` |
| `wc_gs_writeback_chunk_size` | Cell ranges per write-back API request | `500` |
| `wc_gs_image_download_timeout` | Per-image download timeout (seconds) | `20` |
| `wc_gs_batch_time_limit` | Per-batch wall-clock budget (seconds) | `15` |
| `wc_gs_sheet_read_columns` | Column span read from the sheet | `ZZ` |
| `wc_gs_debug_logging` | Force debug logging on/off | WP_DEBUG or setting |
| `wc_gs_license_enforced` | Turn Pro gating on/off | `false` (or `WC_GS_LICENSE_ENFORCE`) |
| `wc_gs_license_base_requires_license` | Require a license for base features too | `false` |
| `wc_gs_license_tier_for_product` | Map an LMFWC productId → tier | any valid → `pro` |
| `wc_gs_license_grace_days` | Offline grace before downgrading | `14` |
| `wc_gs_license_can` | Override a single gate decision | (computed) |

Background hook: `wc_gs_process_sync_batch` (Action Scheduler, group `wc-gs-sync`).

---

## 18. Licensing (Pro gating)

The plugin ships one build with an optional Pro layer (`WC_GS_License`) that
talks to *License Manager for WooCommerce* on the store. **Enforcement is OFF by
default**, so every feature below is available until a site turns it on
(`WC_GS_LICENSE_ENFORCE` or the `wc_gs_license_enforced` filter). Gated features:

| Feature key | Gates |
|---|---|
| `variable_products` | Variable-product import **and** export (variation rows) |
| `auto_sync` | Scheduled automatic syncing |
| `multi_sheet` | More than one connected sheet |
| `advanced_fields` | Custom `Meta` columns (ACF/SEO) + multiple category paths |

Everything else (simple products, manual import/export, all standard fields, one
sheet) is base. A **License** section on the Settings tab activates, re-checks,
and removes a key; status is cached and re-validated daily with a grace period.
See `docs/LICENSING.md` for the full model and store-side setup.

---

## 19. Known constraints

- **Attribute term order is global** per attribute taxonomy (see §5.3).
- A variable parent synced with **zero variation rows keeps** its existing
  variations (safety).
- Blank cells can't clear certain fields (e.g. Sale Price, a previously-set
  variation Shipping Class) — they mean "leave unchanged."
- The **Image column requires a URL** (a bare filename is ignored).
- Very large variable products are slow in WooCommerce itself (not a plugin limit).
