<?php
/**
 * Add Sheet — choose a Google Sheet via the Google Picker.
 *
 * Uses the non-sensitive `drive.file` scope: access is granted only to the sheet
 * the user picks here, which is why there is no "unverified app" warning.
 *
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize Google API
$google_api = new WC_GS_Google_Sheets_API();

// Must be connected to Google
if (!$google_api->is_authenticated()) {
    wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync'));
    exit;
}

// Connected-sheet limit (Pro/free gate; unlimited by default)
if (WC_GS_Admin::sheet_limit_reached()) {
    echo '<div class="wrap wc-gs-sync-wrap"><h1>' . esc_html__('Connect Google Sheet', 'wc-google-sheets-sync') . '</h1>';
    echo '<div class="notice notice-error"><p>' . esc_html__('You have reached the maximum number of connected sheets for your plan.', 'wc-google-sheets-sync') . '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=wc-google-sheets-sync')) . '" class="button">' . esc_html__('Back to Dashboard', 'wc-google-sheets-sync') . '</a></p></div>';
    return;
}

// Values the Google Picker needs
$settings  = new WC_GS_Settings();
$client_id = (string) $settings->get_option('google_client_id');
$api_key   = (string) $settings->get_option('google_api_key');

// App ID = Google Cloud project number (the part of the client ID before the dash)
$app_id = (strpos($client_id, '-') !== false) ? substr($client_id, 0, strpos($client_id, '-')) : '';

// Current OAuth access token (already refreshed by is_authenticated() above)
$token = get_option('wc_gs_sync_access_token');
$access_token = (is_array($token) && isset($token['access_token'])) ? $token['access_token'] : '';

$configure_url = admin_url('admin.php?page=wc-google-sheets-sync&action=configure-sheet');
?>

<div class="wrap wc-gs-sync-wrap">
    <div class="wc-gs-sync-header">
        <h1>
            <?php _e('Connect Google Sheet', 'wc-google-sheets-sync'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-google-sheets-sync')); ?>" class="page-title-action">
                <?php _e('Back to Dashboard', 'wc-google-sheets-sync'); ?>
            </a>
        </h1>
    </div>

    <div class="wc-gs-template-info">
        <p><strong><?php _e('First time?', 'wc-google-sheets-sync'); ?></strong> <?php _e('Start from our template so your columns match what the sync expects. Make a copy, replace the sample rows with your products, then choose it below.', 'wc-google-sheets-sync'); ?></p>
        <p>
            <a href="<?php echo esc_url(WC_GS_SYNC_TEMPLATE_URL); ?>" class="button button-secondary" target="_blank" rel="noopener">
                <?php _e('Get the Google Sheet template', 'wc-google-sheets-sync'); ?>
            </a>
        </p>
    </div>

    <?php if (empty($api_key)): ?>
        <div class="notice notice-warning">
            <p>
                <?php printf(
                    /* translators: %s: Settings tab link */
                    __('To choose a sheet you need a Google API key. Add one in %s (and enable the Google Picker API in your Google Cloud project).', 'wc-google-sheets-sync'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-google-sheets-sync&tab=settings')) . '">' . esc_html__('Settings', 'wc-google-sheets-sync') . '</a>'
                ); ?>
            </p>
        </div>
    <?php else: ?>
        <div class="wc-gs-picker-box">
            <h2><?php _e('Choose your Google Sheet', 'wc-google-sheets-sync'); ?></h2>
            <p class="description"><?php _e('Click below and pick the spreadsheet you want to sync. The plugin only gets access to the sheet you choose.', 'wc-google-sheets-sync'); ?></p>
            <p>
                <button type="button" id="wc-gs-pick-sheet" class="button button-primary"><?php _e('Select a Google Sheet', 'wc-google-sheets-sync'); ?></button>
            </p>
            <p id="wc-gs-picker-status" class="description"></p>
        </div>

        <script type="text/javascript">
            var wcGsPicker = {
                token: '<?php echo esc_js($access_token); ?>',
                appId: '<?php echo esc_js($app_id); ?>',
                apiKey: '<?php echo esc_js($api_key); ?>',
                configureUrl: '<?php echo esc_url_raw($configure_url); ?>'
            };
        </script>
        <script src="https://apis.google.com/js/api.js"></script>
        <script type="text/javascript">
        (function () {
            var pickerReady = false;
            var statusEl = document.getElementById('wc-gs-picker-status');

            function loadPicker() {
                if (window.gapi) {
                    gapi.load('picker', function () { pickerReady = true; });
                }
            }
            loadPicker();

            document.getElementById('wc-gs-pick-sheet').addEventListener('click', function () {
                if (!pickerReady || !window.google || !window.google.picker) {
                    statusEl.textContent = 'Loading the Google Picker… please click again in a moment.';
                    loadPicker();
                    return;
                }

                var view = new google.picker.DocsView(google.picker.ViewId.SPREADSHEETS)
                    .setIncludeFolders(false)
                    .setSelectFolderEnabled(false);

                var picker = new google.picker.PickerBuilder()
                    .setAppId(wcGsPicker.appId)
                    .setOAuthToken(wcGsPicker.token)
                    .setDeveloperKey(wcGsPicker.apiKey)
                    .addView(view)
                    .setCallback(function (data) {
                        if (data.action === google.picker.Action.PICKED && data.docs && data.docs[0]) {
                            statusEl.textContent = 'Opening configuration…';
                            window.location.href = wcGsPicker.configureUrl + '&sheet_id=' + encodeURIComponent(data.docs[0].id);
                        }
                    })
                    .build();

                picker.setVisible(true);
            });
        })();
        </script>
    <?php endif; ?>
</div>

<style>
.wc-gs-picker-box {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    padding: 20px;
    margin-top: 16px;
    max-width: 700px;
}
.wc-gs-picker-box h2 { margin-top: 0; }
</style>
