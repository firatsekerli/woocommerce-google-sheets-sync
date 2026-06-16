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
    <h1><?php _e('Google Sheets Sync Settings', 'wc-google-sheets-sync'); ?></h1>
    
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="wc-gs-sync-settings-form">
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
        </table>
        
        <h2><?php _e('Sync Configuration', 'wc-google-sheets-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Batch Size', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <input type="number" name="batch_size" value="<?php echo esc_attr($options['batch_size']); ?>" min="1" max="100" class="small-text" />
                    <p class="description"><?php _e('Number of products to process in each batch (1-100)', 'wc-google-sheets-sync'); ?></p>
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
        <li><?php _e('Go to the', 'wc-google-sheets-sync'); ?> <a href="https://console.developers.google.com/" target="_blank"><?php _e('Google Developers Console', 'wc-google-sheets-sync'); ?></a></li>
        <li><?php _e('Create a new project or select an existing one', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Enable the Google Sheets API', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Create credentials (OAuth 2.0 Client ID)', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Add your website domain to authorized domains', 'wc-google-sheets-sync'); ?></li>
        <li><?php _e('Copy the Client ID and Client Secret to the fields above', 'wc-google-sheets-sync'); ?></li>
    </ol>
</div>