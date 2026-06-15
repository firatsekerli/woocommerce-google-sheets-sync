<?php
/**
 * Sync Logs Template
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wc-gs-sync-wrap">
    <h1><?php _e('Sync Logs', 'wc-google-sheets-sync'); ?></h1>
    
    <div class="wc-gs-sync-logs-filter">
        <select name="sheet_filter">
            <option value=""><?php _e('All Sheets', 'wc-google-sheets-sync'); ?></option>
            <!-- TODO: Add sheet options -->
        </select>
        
        <select name="status_filter">
            <option value=""><?php _e('All Status', 'wc-google-sheets-sync'); ?></option>
            <option value="success"><?php _e('Success', 'wc-google-sheets-sync'); ?></option>
            <option value="failed"><?php _e('Failed', 'wc-google-sheets-sync'); ?></option>
            <option value="running"><?php _e('Running', 'wc-google-sheets-sync'); ?></option>
        </select>
        
        <button type="button" class="button"><?php _e('Filter', 'wc-google-sheets-sync'); ?></button>
    </div>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Date', 'wc-google-sheets-sync'); ?></th>
                <th><?php _e('Sheet', 'wc-google-sheets-sync'); ?></th>
                <th><?php _e('Type', 'wc-google-sheets-sync'); ?></th>
                <th><?php _e('Status', 'wc-google-sheets-sync'); ?></th>
                <th><?php _e('Products', 'wc-google-sheets-sync'); ?></th>
                <th><?php _e('Duration', 'wc-google-sheets-sync'); ?></th>
                <th><?php _e('Actions', 'wc-google-sheets-sync'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="7"><?php _e('No sync logs found.', 'wc-google-sheets-sync'); ?></td>
            </tr>
        </tbody>
    </table>
</div>