<?php
/**
 * Uninstall WooCommerce Google Sheets Sync
 * 
 * @package WC_Google_Sheets_Sync
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options (includes the Google client id/secret and access token)
delete_option('wc_gs_sync_options');
delete_option('wc_gs_sync_db_version');
delete_option('wc_gs_sync_access_token');
delete_option('wc_gs_sync_connected_sheets');
delete_option('wc_gs_sync_last_sync_time');

// Drop custom tables
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_gs_sync_logs");

// Clear any scheduled events
wp_clear_scheduled_hook('wc_gs_sync_scheduled_import');

// Delete any leftover background-sync job/state options
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wc_gs_sync_job_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wc_gs_sync_state_%'");

// Delete any transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_gs_sync_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_gs_sync_%'");