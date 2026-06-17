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
            <li><?php printf(__('In the %s tab, enter your Google API Client ID, Client Secret and API Key, then connect your Google account.', 'wc-google-sheets-sync'), '<strong>' . __('Settings', 'wc-google-sheets-sync') . '</strong>'); ?></li>
            <li><?php printf(__('In the %1$s tab, click %2$s, pick your spreadsheet in Google’s file picker, then choose the tab to sync.', 'wc-google-sheets-sync'), '<strong>' . __('Sheets', 'wc-google-sheets-sync') . '</strong>', '<strong>' . __('Connect New Sheet', 'wc-google-sheets-sync') . '</strong>'); ?></li>
            <li><?php printf(__('Click %s to push the sheet into WooCommerce.', 'wc-google-sheets-sync'), '<strong>' . __('Sync Now', 'wc-google-sheets-sync') . '</strong>'); ?></li>
        </ol>
        <p class="description"><?php _e('Your sheet must have the column headers in row 1 and product data starting on row 2.', 'wc-google-sheets-sync'); ?></p>
        <p class="description">
            <strong><?php _e('Connecting to Google:', 'wc-google-sheets-sync'); ?></strong>
            <?php _e('After creating your Google API credentials, set the OAuth app to <strong>Production</strong> (so it doesn’t disconnect every 7 days). The plugin only asks for access to the sheet you pick, so you won’t see a “Google hasn’t verified this app” warning. See the Settings page for the full step-by-step.', 'wc-google-sheets-sync'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url(WC_GS_SYNC_TEMPLATE_URL); ?>" class="button button-secondary" target="_blank" rel="noopener">
                <?php _e('📋 Get the Google Sheet template', 'wc-google-sheets-sync'); ?>
            </a>
        </p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('Seeing a “403 Forbidden” after clicking Allow?', 'wc-google-sheets-sync'); ?></h2>
        <p><?php _e('If Google sends you back to your site and you land on a blank “403 Forbidden” page (often showing “nginx”), this is your <strong>server’s firewall blocking the return URL</strong> — it is not a plugin error and your credentials are fine.', 'wc-google-sheets-sync'); ?></p>
        <p><?php _e('Why it happens: after you approve access, Google returns you to a URL that contains <code>https://accounts.google.com</code> as part of the web address. Many web application firewalls (WAF) — including the “7G/8G” rules common on managed hosts such as xCloud, Cloudflare, and some nginx setups — treat a full <code>https://</code> link inside a URL as a possible attack and block the request before WordPress ever sees it.', 'wc-google-sheets-sync'); ?></p>
        <p><strong><?php _e('How to fix it:', 'wc-google-sheets-sync'); ?></strong></p>
        <ul class="wc-gs-help-list">
            <li><?php _e('Ask your host (or your firewall/WAF settings) to <strong>allow-list this admin URL</strong> so its security rules don’t block it:', 'wc-google-sheets-sync'); ?>
                <br><code><?php echo esc_html(admin_url('admin.php?page=wc-google-sheets-sync&auth=callback')); ?></code>
            </li>
            <li><?php _e('On xCloud: open your site’s <strong>Web Application Firewall / 7G Firewall</strong> settings and either add an exception for that admin path or temporarily disable the firewall, connect Google once, then re-enable it.', 'wc-google-sheets-sync'); ?></li>
            <li><?php _e('On Cloudflare or another WAF: create a rule to <strong>skip / allow</strong> requests to <code>wp-admin/admin.php</code> for your own admin IP, or whitelist the “query string contains a URL” rule for that path.', 'wc-google-sheets-sync'); ?></li>
        </ul>
        <p class="description"><?php _e('Once the firewall allows that callback URL, the connection completes normally. You only need to get through it the one time you connect (and again if you reconnect later).', 'wc-google-sheets-sync'); ?></p>
    </div>

    <div class="wc-gs-help-card">
        <h2><?php _e('“Requested entity was not found” when syncing or exporting?', 'wc-google-sheets-sync'); ?></h2>
        <p><?php _e('If a sync or export fails with a 404 / “Requested entity was not found” (or a permission error), it means the plugin no longer has access to that spreadsheet. This plugin only gets access to the exact sheets you pick in Google’s file picker, so this usually happens to sheets that were connected in an older version, or if access was revoked in your Google account.', 'wc-google-sheets-sync'); ?></p>
        <p><strong><?php _e('Fix it by reconnecting the sheet:', 'wc-google-sheets-sync'); ?></strong></p>
        <ol class="wc-gs-help-list">
            <li><?php _e('On the Sheets tab, remove the affected sheet.', 'wc-google-sheets-sync'); ?></li>
            <li><?php printf(__('Click %s and re-select the same spreadsheet in the Google picker (this grants the plugin access to that file).', 'wc-google-sheets-sync'), '<strong>' . __('Connect New Sheet', 'wc-google-sheets-sync') . '</strong>'); ?></li>
            <li><?php _e('Run the sync or export again.', 'wc-google-sheets-sync'); ?></li>
        </ol>
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
            <li><strong><?php _e('Attributes', 'wc-google-sheets-sync'); ?></strong> — <?php _e('columns between the “Attributes” marker and the “Meta” marker become product attributes (great for filters). The header is the attribute name; the cell holds the value(s).', 'wc-google-sheets-sync'); ?></li>
            <li><strong><?php _e('Meta', 'wc-google-sheets-sync'); ?></strong> — <?php _e('every column to the right of the “Meta” marker becomes a custom field on the product. The header becomes the field key (e.g. “Seat Height” → seat_height). Empty cells are skipped (and clear that field on re-sync). Works with ACF if you have a matching field.', 'wc-google-sheets-sync'); ?></li>
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
