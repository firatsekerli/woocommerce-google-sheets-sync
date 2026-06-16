# Sheet Column Reference

This is the complete reference for every column the **WooCommerce Google Sheets Sync**
plugin understands, with accepted values and formats. Use it to set up or fill in your
Google Sheet.

## How columns are matched

- **By header name** — almost every column is found by its exact header text (it is
  **case-sensitive**), so you can reorder columns freely.
- **By position (one exception)** — the **`Attributes`** column is a *marker*: every
  column to its **right** is treated as an individual global product attribute.

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
| **Description** | Full description (HTML/text). |
| **Short Description** | Short summary (HTML/text). |
| **Type** | Simple products only. The cell is read but products are always managed as `simple`. Put `simple`. |
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
| **Gallery Image 01–08** | Full image URLs (positions 1–8). |
| **Image Alt Text** / **Gallery Image NN Alt Text** | Optional alt text for the corresponding image. |
| **Attributes** | Marker column — leave its cells blank. Every column **to its right** becomes a global attribute (`pa_*`) used for filtering. |
| *(columns after Attributes, e.g. Country, Region…)* | Header = attribute name; cell = one or more values (comma / semicolon / pipe separated). Creates filterable global attributes. |

## Notes

- **Booleans** like Featured, Sold Individually, Stock Management and Backorder are
  treated as the source of truth: a blank cell means "off" (not featured / multiple
  allowed / stock management off / backorders not allowed).
- **Conflict resolution:** for SKU, GTIN and Quantity, if a product was edited in
  WooCommerce after the last sync, WooCommerce wins and the value is written back to
  the sheet. Use **Force Update** to make the sheet always win.
- **Attributes** must be placed to the right of the `Attributes` marker column. Two-word
  headers are fine (e.g. `Wine Region` becomes `pa_wine-region`); the display name keeps
  its spaces. Headers that would otherwise produce the same slug (e.g. `WS` and `W&S`,
  which both reduce to `ws`) are kept separate automatically — the second one gets a
  suffixed slug (`pa_ws-2`) and is matched by its exact header text.
