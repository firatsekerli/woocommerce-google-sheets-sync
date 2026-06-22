# Sheet Column Reference

This is the complete reference for every column the **WooCommerce Google Sheets Sync**
plugin understands, with accepted values and formats. Use it to set up or fill in your
Google Sheet.

## How columns are matched

- **By header name** — almost every column is found by its exact header text (it is
  **case-sensitive**), so you can reorder columns freely.
- **By position (two markers)** — the **`Attributes`** and **`Meta`** columns are
  *markers* (their own cells stay blank). Columns between `Attributes` and `Meta`
  become **global product attributes**; columns after `Meta` become **custom post
  meta (custom fields)**. If there is no `Meta` marker, every column after
  `Attributes` is an attribute (as before).

## Product fields

| Column Name | Accepted Values / Format |
|---|---|
| **ID** | Leave blank for new products; filled by the plugin. Numeric WooCommerce product ID — used to match existing products first. |
| **SKU** | Optional; unique across products; auto-generated if blank. |
| **GTIN, UPC, EAN, or ISBN** | Number; saved as `_global_unique_id`. Must be 8, 12, 13, or 14 digits (spaces/hyphens allowed) and unique. |
| **Stock Management** | `yes` / `true` / `1` = enabled; `no` / `false` / `0` / blank = disabled. |
| **Quantity** | Number (e.g. 50); used only if Stock Management = yes. |
| **Stock Status** | `instock`, `outofstock`, or `onbackorder` (blank = instock). |
| **Backorder** | `yes` / `true` / `1` = allowed; `no` / `false` / `0` / blank = not allowed. |
| **Low Stock Threshold** | Optional number. Only allowed if Stock Management = yes. |
| **Sold Individually** | `yes` / `true` / `1` = one per order; `no` / `false` / `0` / blank = multiple allowed. |
| **Name** | Product title (required). |
| **Slug** | The URL slug (`post_name`). If filled, it is used (lowercased/hyphenated); if blank, the slug is derived from the Name. Duplicates get `-2`, `-3`… appended automatically to stay unique. |
| **Description** | Full description (HTML/text). |
| **Short Description** | Short summary (HTML/text). |
| **Type** | `simple` (default), `variable` (a parent with variations), or `variation` (one variation of a variable parent). **Virtual and downloadable are not types** — they are flags on a simple/variation product (use the `Virtual` / `Downloadable` columns). See "Variable products" below. |
| **Parent** | Variation rows only: the **SKU** of the variable parent this variation belongs to. Blank for `simple` and `variable` rows. |
| **Virtual** | `yes` / `true` / `1` = virtual (no shipping, e.g. a service or download); `no` / `false` / `0` / blank = physical. Optional column — omit it to leave the current value unchanged. |
| **Downloadable** | `yes` / `true` / `1` = downloadable; `no` / `false` / `0` / blank = not downloadable. Usually paired with **Download Files**. Optional column — omit it to leave the current value unchanged. |
| **Download Files** | One file per line (or separated by `;`), each as `Name \| URL` — e.g. `User Manual \| https://example.com/manual.pdf`. If you omit the `Name \|` part, the file name is taken from the URL. An empty cell (when the column exists) clears the product's files. |
| **Download Limit** | Number of allowed downloads per purchase. Blank = unlimited. |
| **Download Expiry** | Days the download link stays valid after purchase. Blank = never expires. |
| **Status** | `publish`, `draft`, `pending`, or `private`. |
| **Visibility** | `public`, `private`, or `password` (WordPress post visibility). `password` needs the **Password** column filled. Blank = leave Status in control. |
| **Password** | Text; used only when Visibility = `password`. |
| **Catalog Visibility** | `visible`, `catalog`, `search`, or `hidden`. Blank = leave unchanged. |
| **Featured** | `yes` / `true` / `1` = featured; `no` / `false` / `0` / blank = not featured. |
| **Regular Price** | Numeric (e.g. 19.99). |
| **Sale Price** | Optional numeric; blank = leave unchanged. |
| **Sale Start Date** | `YYYY-MM-DD` or blank. |
| **Sale End Date** | `YYYY-MM-DD` or blank. |
| **Tax Status** | `taxable`, `shipping`, or `none`. Blank = leave unchanged. |
| **Tax Class** | `Standard` (or blank) = standard rate; otherwise the name of a tax class you created in WooCommerce (e.g. `Reduced rate`, `Zero rate`). |
| **Purchase Note** | Text shown to the customer after purchase. Blank = leave unchanged. |
| **Position** | Integer (menu/sort order). Blank = leave unchanged. |
| **Allow Reviews** | `yes` = allow, `no` = disallow. Blank = leave unchanged. |
| **Weight** | Number (e.g. 0.5). |
| **Dimension (L)** | Number. |
| **Dimension (W)** | Number. |
| **Dimension (H)** | Number. |
| **Shipping Class** | Name of an existing or new shipping class (e.g. `Heavy`). |
| **Upsells** | Product IDs or SKUs, separated by comma / semicolon / pipe. |
| **Cross-sells** | Product IDs or SKUs, separated by comma / semicolon / pipe. |
| **Category Path** | Use `>` for hierarchy (e.g. `Electronics > Phones > iPhone`). |
| **Tags** | Comma, semicolon, or pipe separated (e.g. `coffee, organic, fair-trade`). |
| **Sync Status** | Read-only — filled by the system (`synced`, `error`, or `deleted`). A row showing `deleted` is skipped on future syncs (so the product isn't recreated); clear this cell to import the row again. |
| **Sync Error** | Read-only — filled by the system. |
| **Last Synced** | Read-only — filled by the system. |
| **Force Update** | Blank normally; `yes` / `y` / `1` / `true` / `force` = sheet overrides WooCommerce (skips conflict protection for SKU/GTIN/Quantity). |
| **Delete** | `yes` / `y` / `1` / `true` / `delete` = move the product to Trash. |
| **Image** | Full image URL (the featured image). |
| **Gallery Image 01–20** | Full image URLs (gallery positions 1–20). Headers are zero-padded to two digits (`Gallery Image 01` … `Gallery Image 20`). |
| **Image Alt Text** / **Gallery Image NN Alt Text** | Optional alt text for the corresponding image. |
| **Attributes** | Marker column — leave its cells blank. Columns to its right (up to a `Meta` marker, or the end) become global attributes (`pa_*`) used for filtering. |
| *(columns between Attributes and Meta, e.g. Country, Region…)* | Header = attribute name; cell = one or more values (comma / semicolon / pipe separated). Creates filterable global attributes. |
| **Meta** | Marker column — leave its cells blank. Every column **to its right** becomes a custom field (post meta) on the product. |
| *(columns after Meta, e.g. Seat Height, Source OID…)* | Header is slugified into the meta key (`Seat Height` → `seat_height`); the cell is the value. A non-empty cell writes the meta; an **empty** cell deletes that key on re-sync. Uses ACF if a matching field is registered, otherwise plain post meta. |

## Notes

- **Booleans** like Featured, Sold Individually, Stock Management and Backorder are
  treated as the source of truth: a blank cell means "off" (not featured / multiple
  allowed / stock management off / backorders not allowed).
- **Conflict resolution:** for SKU, GTIN and Quantity, if a product was edited in
  WooCommerce after the last sync, WooCommerce wins and the value is written back to
  the sheet. Use **Force Update** to make the sheet always win.
- **Virtual & downloadable** are still simple products. A virtual product drops
  shipping fields; a downloadable product serves the files in **Download Files**.
  A product can be both (e.g. an e-book): set `Virtual = yes` and
  `Downloadable = yes`. The `Virtual`, `Downloadable`, `Download Files`,
  `Download Limit` and `Download Expiry` columns are all optional — leave a column
  out entirely and the plugin won't touch that aspect of your products.

## Variable products

A variable product is **one parent row plus one row per variation**, linked by the
parent's SKU (see `docs/examples/variable-test.csv`).

- **Parent row:** `Type = variable`, give it a `SKU`, and in each variation
  attribute column (to the right of the `Attributes` marker) list **all** values,
  pipe-separated (e.g. Size = `Small | Medium | Large`). Don't set a price on the
  parent — the price comes from the variations.
- **Variation rows:** `Type = variation`, `Parent` = the parent's SKU, each
  variation attribute column holds **one** value (e.g. `Small`), plus that
  variation's own SKU, price, stock, weight, image, etc. On a variation row,
  `Short Description` becomes the variation description and `Status` controls
  enabled (`publish`) / disabled (`private`).
- **The sheet is the source of truth:** a variation that exists in WooCommerce but
  is no longer in the sheet is deleted on sync. (Safety: this only happens for a
  parent that has at least one variation row in the sheet, so syncing just the
  parent never wipes its variations.)
- **Attributes** must be placed to the right of the `Attributes` marker column. Two-word
  headers are fine (e.g. `Wine Region` becomes `pa_wine-region`); the display name keeps
  its spaces. Headers that would otherwise produce the same slug (e.g. `WS` and `W&S`,
  which both reduce to `ws`) are kept separate automatically — the second one gets a
  suffixed slug (`pa_ws-2`) and is matched by its exact header text.
