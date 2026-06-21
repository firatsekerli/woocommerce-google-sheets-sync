# Variable Products — Design & Implementation Plan

Status: **planned** (premium feature). This document is the reference for adding
variable-product support to the sync. It is grounded in the current engine
(`includes/class-wc-gs-sync-handler.php`, `class-wc-gs-product-data-builder.php`,
`class-wc-gs-product-exporter.php`).

## 1. Goals & decisions (locked)

- **Scope: full** — variations get the complete field set (price, sale schedule,
  stock, weight/dimensions, shipping class, image, virtual/downloadable +
  download files/limit/expiry, GTIN, tax class, enabled/disabled).
- **One sheet** (not a separate tab). Variations reuse the same columns as simple
  products, so a dedicated sheet adds management/export overhead without removing
  the parent/child structure.
- **Parent reference: by SKU.** Variation rows name their parent's SKU.
- **Orphan variations are deleted.** The sheet is the source of truth: a variation
  that exists in WooCommerce but is no longer in the sheet is removed, so the user
  is never unaware of a hidden variation.
- **Reuse columns on variation rows:** `Short Description` → the variation
  description; `Status` (`publish`/`private`) → variation enabled/disabled.

## 2. Sheet model (row-per-variation)

A variable product is one **parent** row plus one **variation** row per variation,
linked by the parent's SKU.

| Type | Parent | SKU | Color | Size | Regular Price | Quantity |
|---|---|---|---|---|---|---|
| `variable` |  | TSHIRT | `Red \| Blue` | `S \| M \| L` |  |  |
| `variation` | TSHIRT | TSHIRT-RED-S | Red | S | 19.99 | 10 |
| `variation` | TSHIRT | TSHIRT-RED-M | Red | M | 19.99 | 5 |
| `variation` | TSHIRT | TSHIRT-BLU-L | Blue | L | 21.99 | 8 |

- **Parent row** (`Type = variable`): product-level fields + each variation
  attribute column lists **all** values (pipe-separated). Parent price is *not*
  set (WooCommerce derives it from variations).
- **Variation row** (`Type = variation`, `Parent` = parent SKU): one value per
  variation attribute + variation-level fields.

`simple` rows are unchanged (a "parent" with no children).

## 3. New / changed columns

- **`Parent`** (new): variation rows → parent SKU. Blank on simple/variable rows.
- **`Type`** becomes meaningful: `simple` | `variable` | `variation` (default
  `simple`). Today it is read but ignored.
- No other new columns — everything else is reused (see the column map).

### Column map (where each existing column applies)

- **Shared (simple + variation):** SKU, GTIN, Regular/Sale Price, Sale Start/End,
  Stock Management, Quantity, Stock Status, Backorder, Low Stock Threshold,
  Weight, Dimension L/W/H, Shipping Class, Virtual, Downloadable, Download Files,
  Download Limit, Download Expiry, Tax Class, Image.
- **Parent-only (variable parent = product level):** Name, Description, Short
  Description, Status, Visibility, Password, Catalog Visibility, Featured,
  Category Path, Tags, Upsells, Cross-sells, Purchase Note, Position, Allow
  Reviews, Tax Status, Sold Individually, Gallery Images, Attributes, Meta.
- **Variation-only:** `Parent`; the attribute columns hold a single value;
  `Short Description` = variation description; `Status` = enabled/disabled.
- **Not on variations (ignored on variation rows):** Name, full Description,
  Categories, Tags, Catalog Visibility, Featured, Upsells/Cross-sells, Reviews,
  Sold Individually, Gallery, Tax Status.

## 4. Engine design — the core change

The current engine sweeps rows **forward in offset batches** and treats every row
as independent, deriving the sheet row number positionally
(`$google_sheet_row = $offset + $i + 2`). Variations need their parent created
first, and the parent needs the union of its children's attribute values. So:

### 4a. Job build: classify + reorder + carry the sheet row

In `start_background_sync()`, after reading rows, build an ordered list of **work
items** instead of a raw row array:

```
work_item = array(
  'row'        => <original cell array>,
  'sheet_row'  => <real 1-based sheet row>,   // was derived positionally
  'kind'       => 'simple' | 'variable' | 'variation',
  'parent_sku' => <string, variation rows only>,
)
```

Sort so **all parents (simple + variable) come before all variations**. Because
batches only move forward, every parent is processed before any variation. The
positional row-number formula is replaced by `work_item['sheet_row']` so write-back
still targets the correct cells after reordering.

### 4b. Two-pass via ordering (no second sweep needed)

- **Pass 1 (parents):** `process_row_into_state` branches on `kind`. `variable`
  → create/update a `WC_Product_Variable`; set its attributes with
  `is_variation = true` and the pipe-listed values from the parent row. Record
  `state['parent_ids'][parent_sku] = parent_id`.
- **Pass 2 (variations):** resolve the parent via
  `state['parent_ids'][parent_sku]` (persisted in the job state across background
  batches). Create/update the `WC_Product_Variation`, applying the shared setters
  (reusing the simple-product apply logic) plus the attribute map. Record each
  touched variation in `state['parent_seen_variations'][parent_id][]`.

A variation whose parent SKU is unknown (typo / parent missing) → row error
("Parent SKU 'X' not found"), counted like any other row failure.

### 4c. Variation matching (identity)

1. By **SKU** if the variation row has one.
2. Else by **parent + exact attribute combination** (find the existing variation
   of that parent whose attribute values match).
3. Else **create** a new variation.

### 4d. Orphan variation deletion (in `finalize_sync`)

For every parent touched this run, compare its existing variations against
`state['parent_seen_variations'][parent_id]` and delete any not seen (force
delete the variation post). Only runs for parents that appeared in the sheet, so
untouched products are never altered.

### 4e. Change detection

Variations get their own `_wc_gs_data_hash` (hash of the variation field subset +
attribute map). Parent hash covers product-level fields + the attribute
definition (names + value sets + which are `used_for_variations`).

## 5. Write-back

- Variation rows write back their **own** variation ID / SKU / Quantity / GTIN.
- Parent rows write back the parent ID.
- `find_column_indices()` + `write_sync_results_back()` are keyed by `sheet_row`
  (already row-number keyed), so they work unchanged once items carry `sheet_row`.

## 6. Export (Woo → Sheet)

`WC_GS_Product_Exporter` changes from "one row per product" to:

- For a **variable** product: emit a parent row (`Type = variable`, attribute
  columns = all values) **then** one row per variation (`Type = variation`,
  `Parent` = parent SKU, attribute columns = that variation's values, variation
  fields filled).
- Simple products: unchanged.

## 7. Licensing

Variable support is the paid tier. Gate it behind the licensing layer
(`PRE_LAUNCH_TODO.md`): if unlicensed, `variable`/`variation` rows are skipped
with a clear "Variable products require a Pro license" row message, and simple
products keep working.

## 8. Phased delivery

- **Phase A — import core:** job classify/reorder/sheet_row refactor; data-builder
  branching; create/update variable parents with `used_for_variations`; create/
  update variations with the full shared field set; SKU + attribute-combo
  matching; parent-id map in state. (No export yet.)
- **Phase B — lifecycle:** orphan variation deletion; variation change detection +
  write-back (variation ID/SKU/Qty/GTIN); enabled/default variation.
- **Phase C — round-trip & ship:** export variable products; template + docs +
  Help updates; license gate; large-catalog testing.

## 9. Files to change

- `class-wc-gs-product-data-builder.php` — `Type`/`Parent` parsing, row-kind
  classifier, variation data structure, parent attribute value-set + variation
  flag.
- `class-wc-gs-sync-handler.php` — work-item job build + ordering; `sheet_row`
  plumbing; `process_row_into_state` branch by kind; variable/variation
  create/update; parent-id map; orphan deletion in `finalize_sync`; variation
  hashing/write-back.
- `class-wc-gs-product-exporter.php` — multi-row variable export.
- Template sheet + `docs/SHEET_COLUMNS.md` + Help tab — `Type`/`Parent` + a worked
  variable example.

## 10. Risks & open questions

- **Batching/ordering at scale:** a 50-variation product = 51 work items that must
  stay coherent across background batches. The parent-id map and seen-variation
  sets live in the persisted `$state` option — validate memory/size for large
  catalogs (already a noted concern for the single-option job payload).
- **Variation identity with blank SKUs:** relies on attribute-combo matching;
  changing a variation's attributes can look like delete+create.
- **Accidental deletion:** orphan deletion is powerful — consider a safety cap
  (e.g. refuse if it would delete an unexpectedly large share of a product's
  variations) before shipping.
- **Default/enabled variation** semantics (the pre-selected variation) — handled
  in Phase B.
