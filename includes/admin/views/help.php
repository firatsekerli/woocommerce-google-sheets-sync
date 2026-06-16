<?php
/**
 * Help / How it works
 *
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-gs-sync-wrap wc-gs-help">

    <div class="wc-gs-help-card">
        <h2><?php _e('What this plugin does', 'wc-google-sheets-sync'); ?></h2>
        <p><?php _e('It connects a Google Sheet to your WooCommerce store so you can manage your products from the sheet. You edit products in the sheet (prices, stock, names, images, and more), then click Sync and the changes are applied to WooCommerce.', 'wc-google-sheets-sync'); ?></p>
        <p><strong><?php _e('The Google Sheet is the “source of truth.”', 'wc-google-sheets-sync'); ?></strong> <?php _e('Make your changes in the sheet — not in WooCommerce — and the sync keeps WooCommerce up to date.', 'wc-google-sheets-sync'); ?></p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('Getting started (3 steps)', 'wc-google-sheets-sync'); ?></h2>
        <ol>
            <li><?php printf(__('In the %s tab, enter your Google API Client ID and Secret, then connect your Google account.', 'wc-google-sheets-sync'), '<strong>' . __('Settings', 'wc-google-sheets-sync') . '</strong>'); ?></li>
            <li><?php printf(__('In the %1$s tab, click %2$s and choose your spreadsheet and the tab to sync.', 'wc-google-sheets-sync'), '<strong>' . __('Sheets', 'wc-google-sheets-sync') . '</strong>', '<strong>' . __('Connect New Sheet', 'wc-google-sheets-sync') . '</strong>'); ?></li>
            <li><?php printf(__('Click %s to push the sheet into WooCommerce.', 'wc-google-sheets-sync'), '<strong>' . __('Sync Now', 'wc-google-sheets-sync') . '</strong>'); ?></li>
        </ol>
        <p class="description"><?php _e('Your sheet must have the column headers in row 1 and product data starting on row 2.', 'wc-google-sheets-sync'); ?></p>
        <p>
            <a href="<?php echo esc_url(WC_GS_SYNC_TEMPLATE_URL); ?>" class="button button-secondary" target="_blank" rel="noopener">
                <?php _e('📋 Get the Google Sheet template', 'wc-google-sheets-sync'); ?>
            </a>
        </p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('Which way does the sync go?', 'wc-google-sheets-sync'); ?></h2>
        <ul class="wc-gs-help-list">
            <li><strong><?php _e('Sync Now (Sheet → WooCommerce):', 'wc-google-sheets-sync'); ?></strong> <?php _e('reads your sheet and creates, updates, or deletes products in WooCommerce. This is the main flow.', 'wc-google-sheets-sync'); ?></li>
            <li><strong><?php _e('Export Products to Sheet (WooCommerce → Sheet):', 'wc-google-sheets-sync'); ?></strong> <?php _e('fills the sheet with all your existing WooCommerce products. Use this once at the start so the sheet matches your store.', 'wc-google-sheets-sync'); ?></li>
        </ul>
        <p><strong><?php _e('Important:', 'wc-google-sheets-sync'); ?></strong> <?php _e('A normal sync does NOT copy your WooCommerce edits back into the sheet (with a couple of exceptions below). If you change a product directly in WooCommerce, the sheet will not show it. Edit in the sheet instead.', 'wc-google-sheets-sync'); ?></p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('What gets written back to the sheet', 'wc-google-sheets-sync'); ?></h2>
        <p><?php _e('After a sync, the plugin fills in a few helper columns for you:', 'wc-google-sheets-sync'); ?></p>
        <ul class="wc-gs-help-list">
            <li><strong>ID</strong> — <?php _e('the product’s ID (so the row stays linked to that product).', 'wc-google-sheets-sync'); ?></li>
            <li><strong>SKU</strong> — <?php _e('if you left it blank, an auto-generated SKU is written in.', 'wc-google-sheets-sync'); ?></li>
            <li><strong>Sync Status / Sync Error / Last Synced</strong> — <?php _e('shows whether the row synced, any error, and when.', 'wc-google-sheets-sync'); ?></li>
        </ul>
        <p><?php _e('In addition, three fields are “two-way”: SKU, GTIN and Quantity. If you change one of these directly in WooCommerce after a sync, the next sync copies the WooCommerce value back into the sheet (WooCommerce wins for those three). Every other field flows only from the sheet to WooCommerce.', 'wc-google-sheets-sync'); ?></p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('Special columns you should know', 'wc-google-sheets-sync'); ?></h2>
        <ul class="wc-gs-help-list">
            <li><strong>Delete</strong> — <?php _e('put “yes” to move that product to Trash on the next sync. The cell is cleared automatically afterwards, and the row’s Sync Status becomes “deleted” so it is left alone from then on.', 'wc-google-sheets-sync'); ?></li>
            <li><strong>Force Update</strong> — <?php _e('put “yes” to force the sheet to overwrite WooCommerce for that row (ignores the change checks below). The cell is cleared after the sync.', 'wc-google-sheets-sync'); ?></li>
            <li><strong>Sync Status = deleted</strong> — <?php _e('a row marked “deleted” is skipped so the product is not re-created. Clear that cell if you want to import the row again.', 'wc-google-sheets-sync'); ?></li>
            <li><strong><?php _e('Attributes', 'wc-google-sheets-sync'); ?></strong> — <?php _e('every column to the right of the “Attributes” column becomes a product attribute (great for filters). The header is the attribute name; the cell holds the value(s).', 'wc-google-sheets-sync'); ?></li>
        </ul>
        <p><?php printf(__('For the full list of columns and accepted values, see %s in the plugin folder.', 'wc-google-sheets-sync'), '<code>docs/SHEET_COLUMNS.md</code>'); ?></p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('Why some rows say “Skipped”', 'wc-google-sheets-sync'); ?></h2>
        <p><?php _e('To keep syncs fast, the plugin only updates a product when its row actually changed since the last sync. If a row is unchanged, it is counted as “Skipped” and left as-is. This is normal — it means there was nothing to do.', 'wc-google-sheets-sync'); ?></p>
        <p><?php _e('If you changed a product directly in WooCommerce (not in the sheet) and want the sheet to take over again, set Force Update = yes on that row, or make the change in the sheet.', 'wc-google-sheets-sync'); ?></p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('Automatic syncing', 'wc-google-sheets-sync'); ?></h2>
        <p><?php printf(__('Turn on %1$s in Settings (the master switch and how often), and tick %2$s on each sheet you want included. Automatic sync runs on WordPress cron, which is triggered by site traffic — for reliable, on-time syncing, set up a real server cron (instructions are on the Settings page).', 'wc-google-sheets-sync'), '<strong>' . __('Enable Auto Sync', 'wc-google-sheets-sync') . '</strong>', '<strong>' . __('Include this sheet in scheduled automatic syncs', 'wc-google-sheets-sync') . '</strong>'); ?></p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('Tips &amp; common gotchas', 'wc-google-sheets-sync'); ?></h2>
        <ul class="wc-gs-help-list">
            <li><?php _e('Always edit in the sheet, not in WooCommerce, so the two stay in step.', 'wc-google-sheets-sync'); ?></li>
            <li><?php _e('Format the GTIN / barcode column as plain text in Google Sheets, otherwise long numbers get shown in scientific notation and lose leading zeros.', 'wc-google-sheets-sync'); ?></li>
            <li><?php _e('Image columns take a full image URL. The same image is only downloaded once and reused on later syncs.', 'wc-google-sheets-sync'); ?></li>
            <li><?php _e('Use the Category Path column with “>” for sub-categories, e.g. Wine > Red Wine > Bordeaux.', 'wc-google-sheets-sync'); ?></li>
            <li><?php _e('This version manages simple products.', 'wc-google-sheets-sync'); ?></li>
        </ul>
    </div>

</div>

<style>
.wc-gs-help-card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 18px 22px;
    margin-bottom: 16px;
    max-width: 900px;
}
.wc-gs-help-card h2 {
    margin-top: 0;
    font-size: 16px;
}
.wc-gs-help-card p { font-size: 14px; line-height: 1.6; }
.wc-gs-help-list { list-style: disc; margin: 0 0 0 20px; }
.wc-gs-help-list li { margin-bottom: 8px; font-size: 14px; line-height: 1.5; }
.wc-gs-help ol li { margin-bottom: 8px; font-size: 14px; line-height: 1.5; }
</style>
