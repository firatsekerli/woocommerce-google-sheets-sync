<?php
/**
 * Configure Sheet Template
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get sheet ID from URL
$sheet_id = isset($_GET['sheet_id']) ? sanitize_text_field($_GET['sheet_id']) : '';

if (empty($sheet_id)) {
    wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet'));
    exit;
}

// Initialize Google API
$google_api = new WC_GS_Google_Sheets_API();

// Check authentication
if (!$google_api->is_authenticated()) {
    wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync'));
    exit;
}

// Get sheet information
$sheet_info = $google_api->get_sheet_info($sheet_id);
if (is_wp_error($sheet_info)) {
    wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet&error=' . urlencode($sheet_info->get_error_message())));
    exit;
}

// Get sheet data for preview (first 10 rows, extended column range)
$sheet_data = $google_api->get_sheet_data($sheet_id, 'A1:ZZ20');
$headers = !empty($sheet_data) ? $sheet_data[0] : array();
$preview_rows = !empty($sheet_data) ? array_slice($sheet_data, 1, 8) : array();

// Filter out empty headers and their corresponding columns
$filtered_headers = array();
$column_indices = array();
foreach ($headers as $index => $header) {
    if (!empty(trim($header))) {
        $filtered_headers[] = $header;
        $column_indices[] = $index;
    }
}

// Handle form submission
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_sheet_config') {
    // Verify nonce
    if (!wp_verify_nonce($_POST['wc_gs_config_nonce'], 'wc_gs_sheet_config')) {
        wp_die(__('Security check failed', 'wc-google-sheets-sync'));
    }
    
    // Preserve created_at / last_synced when editing an existing connection
    $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
    $existing = isset($connected_sheets[$sheet_id]) ? $connected_sheets[$sheet_id] : array();

    // Save sheet configuration
    $sheet_config = array(
        'sheet_id' => $sheet_id,
        'sheet_title' => $sheet_info['title'],
        'sheet_url' => $sheet_info['url'],
        'sheet_tab' => sanitize_text_field($_POST['sheet_tab']),
        'auto_sync_enabled' => isset($_POST['auto_sync_enabled']),
        'created_at' => isset($existing['created_at']) ? $existing['created_at'] : current_time('mysql'),
        'last_synced' => isset($existing['last_synced']) ? $existing['last_synced'] : null,
    );

    // Add or update this sheet
    $connected_sheets[$sheet_id] = $sheet_config;

    // Save to database
    update_option('wc_gs_sync_connected_sheets', $connected_sheets);
    
    // Redirect with success message
    wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync&sheet_connected=1'));
    exit;
}

// Load existing configuration (if editing) to pre-fill the form
$connected_sheets_display = get_option('wc_gs_sync_connected_sheets', array());
$existing_config = isset($connected_sheets_display[$sheet_id]) ? $connected_sheets_display[$sheet_id] : array();
$existing_tab = isset($existing_config['sheet_tab']) ? $existing_config['sheet_tab'] : '';
$existing_auto = !empty($existing_config['auto_sync_enabled']);
?>

<div class="wrap wc-gs-sync-wrap">
    <div class="wc-gs-sync-header">
        <h1>
            <?php _e('Configure Google Sheet', 'wc-google-sheets-sync'); ?>
            <a href="<?php echo admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet'); ?>" class="page-title-action">
                <?php _e('← Back to Sheet Selection', 'wc-google-sheets-sync'); ?>
            </a>
        </h1>
    </div>
    
    <!-- Sheet Information -->
    <div class="wc-gs-sheet-info-card">
        <h2><?php _e('Sheet Information', 'wc-google-sheets-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php _e('Sheet Name:', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <strong><?php echo esc_html($sheet_info['title']); ?></strong>
                    <a href="<?php echo esc_url($sheet_info['url']); ?>" target="_blank" class="button button-small">
                        <?php _e('View in Google Sheets', 'wc-google-sheets-sync'); ?>
                    </a>
                </td>
            </tr>
            <tr>
                <th><?php _e('Sheet ID:', 'wc-google-sheets-sync'); ?></th>
                <td><code><?php echo esc_html($sheet_id); ?></code></td>
            </tr>
            <tr>
                <th><?php _e('Available Tabs:', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <?php 
                    $sheet_names = array();
                    foreach ($sheet_info['sheets'] as $sheet) {
                        $sheet_names[] = $sheet->getProperties()->getTitle();
                    }
                    echo esc_html(implode(', ', $sheet_names));
                    ?>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- Data Preview -->
    <div class="wc-gs-data-preview">
        <h2><?php _e('Data Preview', 'wc-google-sheets-sync'); ?></h2>
        <?php if (!empty($sheet_data)): ?>
            <div class="wc-gs-preview-table-wrapper">
                <table class="wc-gs-preview-table">
                    <thead>
                        <tr>
                            <?php foreach ($filtered_headers as $header): ?>
                                <th><?php echo esc_html($header); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview_rows as $row): ?>
                            <tr>
                                <?php 
                                foreach ($column_indices as $col_index):
                                    $cell_value = isset($row[$col_index]) ? $row[$col_index] : '';
                                ?>
                                    <td><?php echo esc_html($cell_value); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="description"><?php _e('Showing first 5 rows of data for preview.', 'wc-google-sheets-sync'); ?></p>
        <?php else: ?>
            <p><?php _e('No data found in this sheet.', 'wc-google-sheets-sync'); ?></p>
        <?php endif; ?>
    </div>
    
    <!-- Configuration Form -->
    <form method="post" action="" class="wc-gs-config-form">
        <?php wp_nonce_field('wc_gs_sheet_config', 'wc_gs_config_nonce'); ?>
        <input type="hidden" name="action" value="save_sheet_config">
        <input type="hidden" name="sheet_id" value="<?php echo esc_attr($sheet_id); ?>">
        
        <h2><?php _e('Sync Configuration', 'wc-google-sheets-sync'); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Sheet Tab', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <select name="sheet_tab" required>
                        <option value=""><?php _e('Select a tab...', 'wc-google-sheets-sync'); ?></option>
                        <?php foreach ($sheet_info['sheets'] as $sheet):
                            $tab_title = $sheet->getProperties()->getTitle(); ?>
                            <option value="<?php echo esc_attr($tab_title); ?>" <?php selected($existing_tab, $tab_title); ?>>
                                <?php echo esc_html($tab_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e('Select which tab/worksheet to sync with. Row 1 must contain the column headers; product data starts on row 2.', 'wc-google-sheets-sync'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php _e('Auto Sync', 'wc-google-sheets-sync'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="auto_sync_enabled" value="1" <?php checked($existing_auto); ?>>
                        <?php _e('Include this sheet in scheduled automatic syncs', 'wc-google-sheets-sync'); ?>
                    </label>
                    <p class="description">
                        <?php printf(
                            /* translators: %s: "Enable Auto Sync" settings label */
                            __('Also requires %s to be turned on in Settings. Sheets without this option are only synced when you click "Sync Now".', 'wc-google-sheets-sync'),
                            '<strong>' . __('Enable Auto Sync', 'wc-google-sheets-sync') . '</strong>'
                        ); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <h2><?php _e('Sheet Template Requirements', 'wc-google-sheets-sync'); ?></h2>
        <div class="wc-gs-template-info">
            <p><strong><?php _e('Important:', 'wc-google-sheets-sync'); ?></strong> <?php _e('Your Google Sheet must use our standard template format for the sync to work properly.', 'wc-google-sheets-sync'); ?></p>
            <p>
                <a href="#" class="button button-secondary" target="_blank">
                    <?php _e('📋 Get Template Google Sheet', 'wc-google-sheets-sync'); ?>
                </a>
                <span class="description"><?php _e('Copy our template and replace your data, then come back to connect it.', 'wc-google-sheets-sync'); ?></span>
            </p>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" class="button button-primary" value="<?php _e('Connect This Sheet', 'wc-google-sheets-sync'); ?>">
            <a href="<?php echo admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet'); ?>" class="button">
                <?php _e('Cancel', 'wc-google-sheets-sync'); ?>
            </a>
        </p>
    </form>
</div>

<style>
.wc-gs-sheet-info-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.wc-gs-data-preview {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.wc-gs-preview-table-wrapper {
    overflow-x: auto;
    margin-bottom: 10px;
}

.wc-gs-preview-table {
    border-collapse: collapse;
    width: 100%;
    min-width: 600px;
}

.wc-gs-preview-table th,
.wc-gs-preview-table td {
    border: 1px solid #ddd;
    padding: 8px 12px;
    text-align: left;
}

.wc-gs-preview-table th {
    background-color: #f5f5f5;
    font-weight: bold;
}

.wc-gs-config-form {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
}

.wc-gs-template-info {
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 20px;
}

.wc-gs-template-info p {
    margin: 0 0 10px 0;
}

.wc-gs-template-info p:last-child {
    margin-bottom: 0;
}
</style>