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

if (isset($_GET['sheet_limit'])) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('You have reached the maximum number of connected sheets for your plan.', 'wc-google-sheets-sync') . '</p></div>';
}
?>

<div class="wrap wc-gs-sync-wrap">
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
                            '<a href="' . esc_url(admin_url('admin.php?page=wc-google-sheets-sync&tab=settings')) . '">' . __('Settings', 'wc-google-sheets-sync') . '</a>'
                        ); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($is_authenticated): ?>
    <div class="wc-gs-sync-actions">
        <h2><?php _e('Actions', 'wc-google-sheets-sync'); ?></h2>
        <?php if (WC_GS_Admin::sheet_limit_reached()): ?>
            <button type="button" class="button button-primary" disabled>
                <?php _e('Connect New Sheet', 'wc-google-sheets-sync'); ?>
            </button>
            <p class="description"><?php _e('You have reached the maximum number of connected sheets for your plan.', 'wc-google-sheets-sync'); ?></p>
        <?php else: ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet')); ?>" class="button button-primary">
            <?php _e('Connect New Sheet', 'wc-google-sheets-sync'); ?>
        </a>
        <?php endif; ?>
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

    <?php
    // Always-visible Sync Progress panel (below Connected Sheets). Pre-filled with
    // the most recent run; updated live by sync.js during a sync.
    $latest_result = null;
    $latest_title = '';
    $latest_time = 0;
    if (!empty($connected_sheets) && is_array($connected_sheets)) {
        foreach ($connected_sheets as $cfg) {
            if (!is_array($cfg) || empty($cfg['last_result']) || !is_array($cfg['last_result'])) {
                continue;
            }
            $t = isset($cfg['last_result']['completed_at']) ? strtotime($cfg['last_result']['completed_at']) : 0;
            if ($t >= $latest_time) {
                $latest_time = $t;
                $latest_result = $cfg['last_result'];
                $latest_title = isset($cfg['sheet_title']) ? $cfg['sheet_title'] : '';
            }
        }
    }
    $lr = is_array($latest_result) ? $latest_result : array();
    $lr_total    = isset($lr['total']) ? (int) $lr['total'] : 0;
    $lr_created  = isset($lr['created']) ? (int) $lr['created'] : 0;
    $lr_updated  = isset($lr['updated']) ? (int) $lr['updated'] : 0;
    $lr_deleted  = isset($lr['deleted']) ? (int) $lr['deleted'] : 0;
    $lr_skipped  = isset($lr['skipped']) ? (int) $lr['skipped'] : 0;
    $lr_variations = isset($lr['variations']) ? (int) $lr['variations'] : 0;
    $lr_errors   = (isset($lr['errors']) && is_array($lr['errors'])) ? $lr['errors'] : array();
    $lr_errcount = isset($lr['error_count']) ? (int) $lr['error_count'] : count($lr_errors);
    ?>
    <div id="wc-gs-sync-progress-panel" class="wc-gs-sync-progress-panel">
        <h2><?php _e('Sync Progress', 'wc-google-sheets-sync'); ?></h2>

        <p id="sync-sheet-name" class="wc-gs-sync-sheet-name"><?php
            if ($latest_title !== '') {
                printf(esc_html__('Sheet: %s', 'wc-google-sheets-sync'), esc_html($latest_title));
            }
        ?></p>

        <div id="sync-progress-bar-wrap" class="wc-gs-progress-bar" style="display: none;">
            <div id="sync-progress-bar" class="wc-gs-progress-fill"></div>
            <span id="sync-progress-text" class="wc-gs-progress-text">0%</span>
        </div>

        <p id="sync-current-step" class="wc-gs-current-step">
            <?php
            if ($latest_time) {
                printf(esc_html__('Last sync: %s', 'wc-google-sheets-sync'), esc_html(date('M j, Y g:i A', $latest_time)));
            } else {
                _e('No syncs yet.', 'wc-google-sheets-sync');
            }
            ?>
        </p>

        <?php
        $tm = (isset($lr['timing']) && is_array($lr['timing'])) ? $lr['timing'] : null;
        if ($tm):
            $tm_total = (int) (($tm['read_ms'] ?? 0) + ($tm['dispatch_ms'] ?? 0) + ($tm['process_ms'] ?? 0) + ($tm['writeback_ms'] ?? 0));
        ?>
        <p class="description wc-gs-timing">
            <?php printf(
                esc_html__('Timing: %1$ss total — read %2$ss, queue wait %3$ss, process %4$ss, write-back %5$ss', 'wc-google-sheets-sync'),
                number_format($tm_total / 1000, 1),
                number_format((int) ($tm['read_ms'] ?? 0) / 1000, 1),
                number_format((int) ($tm['dispatch_ms'] ?? 0) / 1000, 1),
                number_format((int) ($tm['process_ms'] ?? 0) / 1000, 1),
                number_format((int) ($tm['writeback_ms'] ?? 0) / 1000, 1)
            ); ?>
        </p>
        <?php endif; ?>

        <div class="wc-gs-sync-stats">
            <div class="wc-gs-stat-item">
                <span class="wc-gs-stat-label"><?php _e('Processed:', 'wc-google-sheets-sync'); ?></span>
                <span><span id="sync-processed-rows"><?php echo $lr_total; ?></span> / <span id="sync-total-rows"><?php echo $lr_total; ?></span></span>
            </div>
            <div class="wc-gs-stat-item">
                <span class="wc-gs-stat-label"><?php _e('Created:', 'wc-google-sheets-sync'); ?></span>
                <span id="sync-created-products" class="wc-gs-stat-success"><?php echo $lr_created; ?></span>
            </div>
            <div class="wc-gs-stat-item">
                <span class="wc-gs-stat-label"><?php _e('Updated:', 'wc-google-sheets-sync'); ?></span>
                <span id="sync-updated-products" class="wc-gs-stat-info"><?php echo $lr_updated; ?></span>
            </div>
            <div class="wc-gs-stat-item">
                <span class="wc-gs-stat-label"><?php _e('Deleted:', 'wc-google-sheets-sync'); ?></span>
                <span id="sync-deleted-products"><?php echo $lr_deleted; ?></span>
            </div>
            <div class="wc-gs-stat-item">
                <span class="wc-gs-stat-label"><?php _e('Skipped:', 'wc-google-sheets-sync'); ?></span>
                <span id="sync-skipped-rows" class="wc-gs-stat-warning"><?php echo $lr_skipped; ?></span>
            </div>
            <div class="wc-gs-stat-item">
                <span class="wc-gs-stat-label" title="<?php esc_attr_e('Variations of variable products (counted separately from products)', 'wc-google-sheets-sync'); ?>"><?php _e('Variations:', 'wc-google-sheets-sync'); ?></span>
                <span id="sync-variations" class="wc-gs-stat-info"><?php echo $lr_variations; ?></span>
            </div>
            <div class="wc-gs-stat-item">
                <span class="wc-gs-stat-label"><?php _e('Errors:', 'wc-google-sheets-sync'); ?></span>
                <span id="sync-error-count" class="wc-gs-stat-error"><?php echo $lr_errcount; ?></span>
            </div>
        </div>

        <div id="sync-errors" class="wc-gs-sync-errors"<?php echo empty($lr_errors) ? ' style="display: none;"' : ''; ?>>
            <h4><?php _e('Errors:', 'wc-google-sheets-sync'); ?></h4>
            <ul id="sync-errors-list">
                <?php foreach ($lr_errors as $err): ?>
                    <li><?php printf(esc_html__('Row %1$s: %2$s', 'wc-google-sheets-sync'), esc_html((string) (isset($err['row']) ? $err['row'] : '?')), esc_html(isset($err['message']) ? $err['message'] : '')); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div id="sync-messages" class="wc-gs-sync-messages"></div>
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