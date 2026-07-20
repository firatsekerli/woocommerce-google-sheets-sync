=== WooCommerce Google Sheets Sync ===
Contributors: wapiti-digital
Tags: woocommerce, google sheets, products, sync, bulk edit
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage your entire WooCommerce catalog from a Google Sheet: bulk create, update and delete simple products, with two-way sync and scheduled imports.

== Description ==

WooCommerce Google Sheets Sync lets you run your WooCommerce catalog from a Google Sheet. Edit products in the sheet, then push the changes into WooCommerce with a single click or on an automatic schedule. You can also export your existing WooCommerce products into the sheet so it becomes the master copy from day one.

The plugin matches each row to a product by ID, then SKU, then Name, and creates, updates or deletes the product accordingly. Key inventory fields (SKU, GTIN and Quantity) are kept in sync both ways, with conflict resolution that protects recent manual edits made in WooCommerce — and a "Force Update" column to override it when the sheet should always win.

== Features ==

* Connect to Google Sheets via Google OAuth
* Manage simple products directly from a sheet: create, update and delete
* Sheet to WooCommerce sync (manual button or scheduled)
* Export all existing WooCommerce products into the sheet (WooCommerce to Sheet)
* Two-way sync for SKU, GTIN and Quantity with timestamp-based conflict resolution
* "Force Update" column to make the sheet authoritative
* Write-back of new product IDs, generated SKUs, sync status, errors and timestamps
* Global product attributes for filtering / layered navigation
* Categories (hierarchical "Parent > Child" paths), tags, featured image and gallery
* Tax status / class, shipping class, dimensions, weight, stock management
* Upsells and cross-sells (by product ID or SKU)
* Post visibility (public / private / password) and catalog visibility
* Scheduled automatic syncing via WP-Cron (hourly, twice daily, daily, weekly)
* WooCommerce HPOS (High-Performance Order Storage) compatible

== Supported Product Fields ==

Each sheet column is matched by its header name (position independent), except the
attribute columns, which are every column placed to the right of an "Attributes"
marker column. Supported columns include:

ID, SKU, GTIN/UPC/EAN/ISBN, Name, Description, Short Description, Type, Status,
Visibility (public/private/password), Password, Catalog Visibility, Featured,
Regular Price, Sale Price, Sale Start Date, Sale End Date, Tax Status, Tax Class,
Stock Management, Quantity, Stock Status, Backorder, Low Stock Threshold,
Sold Individually, Weight, Dimension (L/W/H), Shipping Class, Purchase Note,
Position, Allow Reviews, Upsells, Cross-sells, Category Path, Tags, Image,
Gallery Image 01-08, Force Update, Delete, and any custom Attributes columns.

For the complete list of every column with its accepted values and format, see
`docs/SHEET_COLUMNS.md` in the plugin folder.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/woocommerce-google-sheets-sync/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure Google API credentials in WooCommerce > Sheets Settings
4. Connect your Google account in WooCommerce > Google Sheets Sync
5. Connect a Google Sheet, then use "Sync Now" (or "Export Products to Sheet" to populate the sheet from your existing catalog)

== Automatic Sync and Server Cron ==

Automatic syncing uses WP-Cron, which is triggered by site traffic. On low-traffic
stores scheduled syncs can run late. For reliable, on-time syncing, disable WP-Cron's
default behaviour and run it from a real server cron:

1. Add to `wp-config.php`:
   `define('DISABLE_WP_CRON', true);`
2. Add a server cron job (for example, every 5 minutes):
   `*/5 * * * * wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`

== Requirements ==

* WordPress 5.0 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* Google account with Google Sheets and Drive API access

== Frequently Asked Questions ==

= Is the sync two-way? =

The main flow is Sheet to WooCommerce and applies all fields. Write-back to the sheet
covers product IDs, generated SKUs, sync status/error/timestamp, and the bidirectional
fields SKU, GTIN and Quantity (subject to conflict resolution). Use "Export Products to
Sheet" to pull your full existing catalog into the sheet.

= How does conflict resolution work? =

For SKU, GTIN and Quantity, if a product was edited in WooCommerce after the last sync,
WooCommerce wins and the value is written back to the sheet; otherwise the sheet wins.
Set the "Force Update" column to yes to make the sheet always win.

= How do product attributes work? =

Every column to the right of the "Attributes" marker column is treated as its own
global attribute (pa_*), so values can be used with WooCommerce attribute/layered-nav
filters. The header is the attribute name; the cell holds one or more comma/semicolon/
pipe separated values.

= Which product types are supported? =

Simple products.

== Changelog ==

= 1.3.0 =
* Added: Full variable product support — parent + variation rows, attribute matching, orphan-variation cleanup, and variable-product export
* Added: Multiple categories per product (pipe-separated paths) and per-attribute control via header tags ([hidden], [no-vary])
* Added: Auto-generated variation SKUs (<parent SKU>-NN), a complete round-trippable export template, and a "Cancel Sync" button
* Added: "Debug logging" setting (off by default) so normal syncs write nothing to the log
* Fixed: Sheets with more than 999 products are no longer truncated
* Fixed: Background sync now survives hosts that kill long requests (no server cron needed); slow/unreachable or image-heavy rows no longer stall or fail a sync
* Fixed: Front-end variation and attribute-dropdown order now match the sheet
* Fixed: A row whose SKU matches an existing product is no longer wrongly rejected as a duplicate
* Changed: SKU and GTIN are now strictly sheet-driven (Quantity remains two-way); write-back to the sheet is much faster

= 1.1.0 =
* Added: Export all existing WooCommerce products into a connected sheet
* Added: Working scheduled auto-sync via WP-Cron (hourly/twicedaily/daily/weekly)
* Added: Global product attributes for filtering, plus upsells and cross-sells
* Added: Tax status/class, purchase note, position, allow reviews
* Added: "Force Update" column to bypass conflict resolution
* Added: Separate Visibility (public/private/password) and Catalog Visibility handling
* Fixed: Duplicate sync handler caused each sync to run twice
* Fixed: Fatal error when setting a product shipping class
* Fixed: Low Stock Threshold validation and other field-application bugs
* Improved: Capability checks, request hardening, and trimmed Composer dependencies

= 1.0.0 =
* Initial release
