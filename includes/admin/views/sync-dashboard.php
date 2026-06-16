<?php
/**
 * Sync Dashboard Template
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize Google API
try {
    $google_api = new WC_GS_Google_Sheets_API();
    $is_authenticated = $google_api->is_authenticated();
    $user_info = $is_authenticated ? $google_api->get_user_info() : false;
} catch (Exception $e) {
    error_log('WC Google Sheets Dashboard Error: ' . $e->getMessage());
    $is_authenticated = false;
    $user_info = false;
}

// Show messages
if (isset($_GET['auth_success'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Successfully connected to Google Sheets!', 'wc-google-sheets-sync') . '</p></div>';
}

if (isset($_GET['auth_error'])) {
    $error_message = sanitize_text_field(wp_unslash($_GET['auth_error']));
    echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(esc_html__('Google authentication failed: %s', 'wc-google-sheets-sync'), esc_html($error_message)) . '</p></div>';
}

if (isset($_GET['sheet_connected'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Google Sheet connected successfully!', 'wc-google-sheets-sync') . '</p></div>';
}

if (isset($_GET['sheet_removed'])) {
    echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Google Sheet connection removed.', 'wc-google-sheets-sync') . '</p></div>';
}

if (isset($_GET['disconnected'])) {
    echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Successfully disconnected from Google Sheets.', 'wc-google-sheets-sync') . '</p></div>';
}
?>

<div class="wrap wc-gs-sync-wrap">
    <div class="wc-gs-sync-header">
        <h1><?php _e('Google Sheets Sync Dashboard', 'wc-google-sheets-sync'); ?></h1>
    </div>
    
    <div class="wc-gs-sync-status">
        <h2><?php _e('Google Connection Status', 'wc-google-sheets-sync'); ?></h2>
        <?php if ($is_authenticated && $user_info): ?>
            <div class="wc-gs-sync-status wc-gs-sync-success">
                <p>
                    <strong><?php _e('✓ Connected to Google', 'wc-google-sheets-sync'); ?></strong><br>
                    <?php printf(__('Logged in as: %s (%s)', 'wc-google-sheets-sync'), 
                        esc_html($user_info->getName()), 
                        esc_html($user_info->getEmail())
                    ); ?>
                </p>
                <p class="description">
                    <?php _e('Note: If you encounter permission errors when browsing sheets, you may need to reconnect to grant additional permissions.', 'wc-google-sheets-sync'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wc-google-sheets-sync&action=disconnect'), 'wc_gs_disconnect')); ?>"
                       class="button" 
                       onclick="return confirm('<?php _e('Are you sure you want to disconnect from Google?', 'wc-google-sheets-sync'); ?>')">
                        <?php _e('Disconnect from Google', 'wc-google-sheets-sync'); ?>
                    </a>
                </p>
            </div>
        <?php else: ?>
            <div class="wc-gs-sync-status wc-gs-sync-error">
                <p><strong><?php _e('✗ Not connected to Google', 'wc-google-sheets-sync'); ?></strong></p>
                <p><?php _e('You need to connect to Google to access your spreadsheets.', 'wc-google-sheets-sync'); ?></p>
                <?php
                $auth_url = $google_api->get_auth_url();
                if ($auth_url): ?>
                    <p>
                        <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">
                            <?php _e('Connect to Google Sheets', 'wc-google-sheets-sync'); ?>
                        </a>
                    </p>
                <?php else: ?>
                    <p class="description">
                        <?php printf(
                            __('Please configure your Google API credentials in %s first.', 'wc-google-sheets-sync'),
                            '<a href="' . admin_url('admin.php?page=wc-google-sheets-settings') . '">' . __('Settings', 'wc-google-sheets-sync') . '</a>'
                        ); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($is_authenticated): ?>
    <div class="wc-gs-sync-actions">
        <h2><?php _e('Actions', 'wc-google-sheets-sync'); ?></h2>
        <button type="button" class="button button-primary wc-gs-sync-start">
            <?php _e('Start New Sync', 'wc-google-sheets-sync'); ?>
        </button>
        
        <a href="<?php echo admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet'); ?>" class="button">
            <?php _e('Connect New Sheet', 'wc-google-sheets-sync'); ?>
        </a>
    </div>
    
    <div class="wc-gs-sync-progress" style="display: none;">
        <h3><?php _e('Sync Progress', 'wc-google-sheets-sync'); ?></h3>
        <div class="wc-gs-sync-progress">
            <div class="wc-gs-sync-progress-bar" style="width: 0%;"></div>
        </div>
        <p class="wc-gs-sync-progress-text">0%</p>
    </div>
    
    <div class="wc-gs-sync-sheets">
        <h2><?php _e('Connected Sheets', 'wc-google-sheets-sync'); ?></h2>
        
        <?php
        // Get connected sheets
        $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
        
        if (!empty($connected_sheets) && is_array($connected_sheets)): ?>
            <div class="wc-gs-connected-sheets-list">
                <?php foreach ($connected_sheets as $sheet_id => $sheet_config): 
                    // Ensure sheet_config is an array and has required keys
                    if (!is_array($sheet_config)) continue;
                    
                    $sheet_title = isset($sheet_config['sheet_title']) ? $sheet_config['sheet_title'] : 'Unknown Sheet';
                    $sheet_url = isset($sheet_config['sheet_url']) ? $sheet_config['sheet_url'] : '#';
                    $sheet_tab = isset($sheet_config['sheet_tab']) ? $sheet_config['sheet_tab'] : 'Unknown';
                    $auto_sync_enabled = isset($sheet_config['auto_sync_enabled']) ? $sheet_config['auto_sync_enabled'] : false;
                    $last_synced = isset($sheet_config['last_synced']) ? $sheet_config['last_synced'] : null;
                ?>
                    <div class="wc-gs-connected-sheet-card">
                        <div class="wc-gs-sheet-header">
                            <h3 class="wc-gs-sheet-title">
                                <a href="<?php echo esc_url($sheet_url); ?>" target="_blank">
                                    <?php echo esc_html($sheet_title); ?>
                                </a>
                            </h3>
                            <div class="wc-gs-sheet-actions">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-google-sheets-sync&action=configure-sheet&sheet_id=' . urlencode($sheet_id))); ?>"
                                   class="button button-small">
                                    <?php _e('Edit', 'wc-google-sheets-sync'); ?>
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wc-google-sheets-sync&action=remove-sheet&sheet_id=' . urlencode($sheet_id)), 'wc_gs_remove_sheet')); ?>" 
                                   class="button button-small button-link-delete"
                                   onclick="return confirm('<?php _e('Are you sure you want to remove this sheet connection?', 'wc-google-sheets-sync'); ?>')">
                                    <?php _e('Remove', 'wc-google-sheets-sync'); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="wc-gs-sheet-details">
                            <div class="wc-gs-sheet-meta">
                                <span class="wc-gs-meta-item">
                                    <strong><?php _e('Tab:', 'wc-google-sheets-sync'); ?></strong> 
                                    <?php echo esc_html($sheet_tab); ?>
                                </span>
                                
                                <span class="wc-gs-meta-item">
                                    <strong><?php _e('Auto Sync:', 'wc-google-sheets-sync'); ?></strong>
                                    <?php echo $auto_sync_enabled ? 
                                        '<span class="wc-gs-status-enabled">' . __('Enabled', 'wc-google-sheets-sync') . '</span>' : 
                                        '<span class="wc-gs-status-disabled">' . __('Disabled', 'wc-google-sheets-sync') . '</span>'; ?>
                                </span>
                                
                                <span class="wc-gs-meta-item">
                                    <strong><?php _e('Last Sync:', 'wc-google-sheets-sync'); ?></strong> 
                                    <?php 
                                    if ($last_synced) {
                                        echo esc_html(date('M j, Y g:i A', strtotime($last_synced)));
                                    } else {
                                        echo '<span class="wc-gs-status-never">' . __('Never', 'wc-google-sheets-sync') . '</span>';
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <div class="wc-gs-sheet-sync-actions">
                                <button type="button" class="button button-primary button-small wc-gs-sync-sheet"
                                        data-sheet-id="<?php echo esc_attr($sheet_id); ?>">
                                    <?php _e('Sync Now', 'wc-google-sheets-sync'); ?>
                                </button>
                                <button type="button" class="button button-secondary button-small wc-gs-export-sheet"
                                        data-sheet-id="<?php echo esc_attr($sheet_id); ?>">
                                    <?php _e('Export Products to Sheet', 'wc-google-sheets-sync'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p><?php _e('No sheets connected yet.', 'wc-google-sheets-sync'); ?></p>
            <p class="description">
                <?php _e('Use the "Connect New Sheet" button above to add Google Sheets for product syncing.', 'wc-google-sheets-sync'); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
    var wc_gs_sync_nonce = '<?php echo esc_js(wp_create_nonce('wc_gs_sync_nonce')); ?>';
</script>

<?php
// The remove-sheet and disconnect actions are handled before any output in
// WC_GS_Admin::handle_dashboard_actions() (with capability + nonce checks).
?>