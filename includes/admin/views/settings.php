<?php
/**
 * Settings Template
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get settings instance
$settings = new WC_GS_Settings();
$options = $settings->get_options();

// Show success message
if (isset($_GET['message']) && $_GET['message'] === 'settings_saved') {
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully!', 'wc-google-sheets-sync') . '</p></div>';
}
?>

<div class="wrap wc-gs-sync-wrap">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wc-gs-sync-settings-form">
        <?php wp_nonce_field('wc_gs_sync_settings_nonce'); ?>
        <input type="hidden" name="action" value="wc_gs_sync_save_settings">
        
        <h2><?php _e('Google API Configuration', 'wc-google-sheets-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Google Client ID', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <input type="text" name="google_client_id" value="<?php echo esc_attr($options['google_client_id']); ?>" class="regular-text" />
                    <p class="description">
                        <?php _e('Enter your Google API Client ID. ', 'wc-google-sheets-sync'); ?>
                        <a href="https://console.developers.google.com/" target="_blank"><?php _e('Get your credentials here', 'wc-google-sheets-sync'); ?></a>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Google Client Secret', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <input type="password" name="google_client_secret" value="" autocomplete="new-password" class="regular-text"
                           placeholder="<?php echo !empty($options['google_client_secret']) ? esc_attr__('•••••••• (saved)', 'wc-google-sheets-sync') : ''; ?>" />
                    <p class="description"><?php _e('Enter your Google API Client Secret. Leave blank to keep the currently saved secret.', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Google API Key', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <input type="text" name="google_api_key" value="<?php echo esc_attr(isset($options['google_api_key']) ? $options['google_api_key'] : ''); ?>" class="regular-text" />
                    <p class="description"><?php _e('Required for the “Select a Google Sheet” picker. Create an API key in the same Google Cloud project (and enable the Google Picker API). See the setup steps below.', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php _e('Sync Configuration', 'wc-google-sheets-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Batch Size', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <input type="number" name="batch_size" value="<?php echo esc_attr($options['batch_size']); ?>" min="1" max="100" class="small-text" />
                    <p class="description"><?php _e('Rows processed per batch (1-100). Syncs run inline; a very large catalog that cannot finish quickly is continued in the background (Action Scheduler) in chunks of this size.', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Rate Limit Delay (ms)', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <input type="number" name="rate_limit_delay" value="<?php echo esc_attr($options['rate_limit_delay']); ?>" min="100" max="10000" class="small-text" />
                    <p class="description"><?php _e('Delay between API calls in milliseconds (100-10000)', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Max Retries', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <input type="number" name="max_retries" value="<?php echo esc_attr($options['max_retries']); ?>" min="1" max="10" class="small-text" />
                    <p class="description"><?php _e('Maximum number of retry attempts for failed requests (1-10)', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Debug Logging', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="debug_logging" value="1" <?php checked(!empty($options['debug_logging'])); ?> />
                        <?php _e('Write detailed sync logs to the PHP error log', 'wc-google-sheets-sync'); ?>
                    </label>
                    <p class="description"><?php _e('Off by default. Turn on only when troubleshooting — it writes a lot per sync. (Also enabled automatically when WP_DEBUG is on.)', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php _e('Automatic Sync', 'wc-google-sheets-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Enable Auto Sync', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="auto_sync_enabled" value="1" <?php checked($options['auto_sync_enabled']); ?> />
                        <?php _e('Automatically sync products on a schedule', 'wc-google-sheets-sync'); ?>
                    </label>
                    <p class="description"><?php _e('Master switch. Only sheets with "Include this sheet in scheduled automatic syncs" checked (on each sheet\'s configure screen) will be synced.', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Sync Interval', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <select name="auto_sync_interval">
                        <option value="hourly" <?php selected($options['auto_sync_interval'], 'hourly'); ?>><?php _e('Hourly', 'wc-google-sheets-sync'); ?></option>
                        <option value="twicedaily" <?php selected($options['auto_sync_interval'], 'twicedaily'); ?>><?php _e('Twice Daily', 'wc-google-sheets-sync'); ?></option>
                        <option value="daily" <?php selected($options['auto_sync_interval'], 'daily'); ?>><?php _e('Daily', 'wc-google-sheets-sync'); ?></option>
                        <option value="weekly" <?php selected($options['auto_sync_interval'], 'weekly'); ?>><?php _e('Weekly', 'wc-google-sheets-sync'); ?></option>
                    </select>
                    <p class="description"><?php _e('How often to automatically sync products', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Reliable Scheduling', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <p class="description">
                        <?php _e('Automatic sync runs on WP-Cron, which is triggered by site traffic — on low-traffic stores scheduled syncs may run late. For reliable, on-time syncing, run WP-Cron from a real server cron:', 'wc-google-sheets-sync'); ?>
                    </p>
                    <ol class="description" style="margin-left: 1.5em;">
                        <li>
                            <?php _e('Add this to <code>wp-config.php</code>:', 'wc-google-sheets-sync'); ?>
                            <br><code>define('DISABLE_WP_CRON', true);</code>
                        </li>
                        <li>
                            <?php _e('Add a server cron job (for example, every 5 minutes):', 'wc-google-sheets-sync'); ?>
                            <br><code>*/5 * * * * wget -q -O - <?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?> &gt;/dev/null 2&gt;&amp;1</code>
                        </li>
                    </ol>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('Save Settings', 'wc-google-sheets-sync')); ?>
    </form>
    
    <hr>

    <h2><?php _e('Setup Instructions', 'wc-google-sheets-sync'); ?></h2>
    <ol>
        <li><?php _e('Go to the', 'wc-google-sheets-sync'); ?> <a href="https://console.cloud.google.com/" target="_blank" rel="noopener"><?php _e('Google Cloud Console', 'wc-google-sheets-sync'); ?></a> <?php _e('and create (or select) a project.', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Enable three APIs for that project: <strong>Google Sheets API</strong>, <strong>Google Drive API</strong>, and <strong>Google Picker API</strong>.', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Configure the <strong>OAuth consent screen</strong> (User type: External).', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Create credentials → <strong>OAuth client ID</strong> → application type <strong>Web application</strong>.', 'wc-google-sheets-sync'); ?></li>
        <li>
            <?php _e('Under <strong>Authorized redirect URIs</strong>, add this exact URL:', 'wc-google-sheets-sync'); ?>
            <br><code><?php echo esc_html(admin_url('admin.php?page=wc-google-sheets-sync&auth=callback')); ?></code>
        </li>
        <li><?php _e('Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> into the fields above.', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Create credentials → <strong>API key</strong>. Copy it into the <strong>Google API Key</strong> field above (used by the sheet picker). For security, you can restrict the key to the Google Picker API and your site’s domain.', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Click <strong>Save Settings</strong>, then connect your Google account.', 'wc-google-sheets-sync'); ?></li>
    </ol>

    <div class="notice notice-info inline" style="margin: 12px 0; padding: 10px 14px;">
        <p style="margin-top:0;"><strong><?php _e('Good to know:', 'wc-google-sheets-sync'); ?></strong></p>
        <ul style="margin-bottom:0; list-style: disc; margin-left: 1.5em;">
            <li>
                <strong><?php _e('No “unverified app” warning.', 'wc-google-sheets-sync'); ?></strong>
                <?php _e('This plugin only requests access to the single spreadsheet you pick (the non-sensitive <code>drive.file</code> scope), so Google does not show the scary “Google hasn’t verified this app” screen and you do not need to submit the app for verification.', 'wc-google-sheets-sync'); ?>
            </li>
            <li>
                <strong><?php _e('Still publish your app to Production.', 'wc-google-sheets-sync'); ?></strong>
                <?php _e('In the Google Cloud Console → OAuth consent screen (or “Audience”), click <strong>Publish app</strong>. While it stays in “Testing”, Google expires the connection every 7 days and you’ll be disconnected weekly. Publishing to Production stops that.', 'wc-google-sheets-sync'); ?>
            </li>
            <li>
                <strong><?php _e('You pick the sheet, not a folder list.', 'wc-google-sheets-sync'); ?></strong>
                <?php _e('When you connect a sheet you’ll use Google’s own file picker to choose it. The plugin can only read/write the spreadsheets you select there.', 'wc-google-sheets-sync'); ?>
            </li>
        </ul>
    </div>
</div>