<?php
/**
 * Add Sheet Template
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize Google API
$google_api = new WC_GS_Google_Sheets_API();

// Check authentication
if (!$google_api->is_authenticated()) {
    wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync'));
    exit;
}

// Enforce the connected-sheet limit (Pro/free gate; unlimited by default)
if (WC_GS_Admin::sheet_limit_reached()) {
    echo '<div class="wrap wc-gs-sync-wrap"><h1>' . esc_html__('Connect Google Sheet', 'wc-google-sheets-sync') . '</h1>';
    echo '<div class="notice notice-error"><p>' . esc_html__('You have reached the maximum number of connected sheets for your plan.', 'wc-google-sheets-sync') . '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=wc-google-sheets-sync')) . '" class="button">' . esc_html__('Back to Dashboard', 'wc-google-sheets-sync') . '</a></p></div>';
    return;
}

// Handle search
$search_term = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
$page_token = isset($_GET['page_token']) ? sanitize_text_field(wp_unslash($_GET['page_token'])) : null;

// Get spreadsheets
if (!empty($search_term)) {
    $spreadsheets_result = $google_api->search_spreadsheets($search_term);
    $spreadsheets = is_wp_error($spreadsheets_result) ? array() : $spreadsheets_result;
    $next_page_token = null;
} else {
    $spreadsheets_result = $google_api->list_spreadsheets($page_token);
    if (is_wp_error($spreadsheets_result)) {
        $spreadsheets = array();
        $next_page_token = null;
    } else {
        $spreadsheets = $spreadsheets_result['files'];
        $next_page_token = $spreadsheets_result['nextPageToken'];
    }
}
?>

<div class="wrap wc-gs-sync-wrap">
    <div class="wc-gs-sync-header">
        <h1>
            <?php _e('Connect Google Sheet', 'wc-google-sheets-sync'); ?>
            <a href="<?php echo admin_url('admin.php?page=wc-google-sheets-sync'); ?>" class="page-title-action">
                <?php _e('← Back to Dashboard', 'wc-google-sheets-sync'); ?>
            </a>
        </h1>
    </div>

    <div class="wc-gs-template-info">
        <p><strong><?php _e('First time?', 'wc-google-sheets-sync'); ?></strong> <?php _e('Start from our template so your columns match what the sync expects. Make a copy, replace the sample rows with your products, then pick it below.', 'wc-google-sheets-sync'); ?></p>
        <p>
            <a href="<?php echo esc_url(WC_GS_SYNC_TEMPLATE_URL); ?>" class="button button-secondary" target="_blank" rel="noopener">
                <?php _e('📋 Get the Google Sheet template', 'wc-google-sheets-sync'); ?>
            </a>
        </p>
    </div>

    <!-- Search Form -->
    <div class="wc-gs-search-form">
        <form method="get" action="">
            <input type="hidden" name="page" value="wc-google-sheets-sync" />
            <input type="hidden" name="action" value="add-sheet" />
            <p class="search-box">
                <label class="screen-reader-text" for="sheet-search-input"><?php _e('Search Sheets:', 'wc-google-sheets-sync'); ?></label>
                <input type="search" id="sheet-search-input" name="search" value="<?php echo esc_attr($search_term); ?>" placeholder="<?php _e('Search your Google Sheets...', 'wc-google-sheets-sync'); ?>" />
                <input type="submit" id="search-submit" class="button" value="<?php _e('Search Sheets', 'wc-google-sheets-sync'); ?>" />
                <?php if (!empty($search_term)): ?>
                    <a href="<?php echo admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet'); ?>" class="button">
                        <?php _e('Show All', 'wc-google-sheets-sync'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </form>
    </div>
    
    <?php if (is_wp_error($spreadsheets_result)): ?>
        <div class="notice notice-error">
            <p><?php printf(esc_html__('Error loading spreadsheets: %s', 'wc-google-sheets-sync'), esc_html($spreadsheets_result->get_error_message())); ?></p>
        </div>
    <?php endif; ?>
    
    <!-- Spreadsheets List -->
    <div class="wc-gs-spreadsheets-list">
        <?php if (!empty($search_term)): ?>
            <h2><?php printf(__('Search Results for "%s"', 'wc-google-sheets-sync'), esc_html($search_term)); ?></h2>
        <?php else: ?>
            <h2><?php _e('Your Google Sheets', 'wc-google-sheets-sync'); ?></h2>
        <?php endif; ?>
        
        <?php if (empty($spreadsheets)): ?>
            <div class="wc-gs-no-sheets">
                <?php if (!empty($search_term)): ?>
                    <p><?php _e('No spreadsheets found matching your search.', 'wc-google-sheets-sync'); ?></p>
                <?php else: ?>
                    <p><?php _e('No spreadsheets found in your Google Drive.', 'wc-google-sheets-sync'); ?></p>
                    <p>
                        <a href="https://sheets.google.com" target="_blank" class="button button-secondary">
                            <?php _e('Create New Google Sheet', 'wc-google-sheets-sync'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="wc-gs-sheets-grid">
                <?php foreach ($spreadsheets as $sheet): ?>
                    <div class="wc-gs-sheet-card">
                        <div class="wc-gs-sheet-info">
                            <h3 class="wc-gs-sheet-title">
                                <a href="<?php echo esc_url($sheet->getWebViewLink()); ?>" target="_blank">
                                    <?php echo esc_html($sheet->getName()); ?>
                                </a>
                            </h3>
                            <p class="wc-gs-sheet-meta">
                                <?php 
                                $modified = new DateTime($sheet->getModifiedTime());
                                printf(__('Last modified: %s', 'wc-google-sheets-sync'), 
                                    $modified->format('M j, Y g:i A')
                                ); 
                                ?>
                            </p>
                        </div>
                        <div class="wc-gs-sheet-actions">
                            <a href="<?php echo esc_url($sheet->getWebViewLink()); ?>" 
                               target="_blank" 
                               class="button button-secondary">
                                <?php _e('View Sheet', 'wc-google-sheets-sync'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-google-sheets-sync&action=configure-sheet&sheet_id=' . urlencode($sheet->getId()))); ?>"
                               class="button button-primary">
                                <?php _e('Connect This Sheet', 'wc-google-sheets-sync'); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($next_page_token && empty($search_term)): ?>
                <div class="wc-gs-pagination">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet&page_token=' . urlencode($next_page_token))); ?>"
                       class="button">
                        <?php _e('Load More Sheets', 'wc-google-sheets-sync'); ?>
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.wc-gs-sheets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.wc-gs-sheet-card {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.wc-gs-sheet-title {
    margin: 0 0 10px 0;
    font-size: 16px;
}

.wc-gs-sheet-title a {
    color: #1e73be;
    text-decoration: none;
}

.wc-gs-sheet-title a:hover {
    text-decoration: underline;
}

.wc-gs-sheet-meta {
    color: #666;
    font-size: 13px;
    margin-bottom: 15px;
}

.wc-gs-sheet-actions {
    display: flex;
    gap: 10px;
}

.wc-gs-no-sheets {
    text-align: center;
    padding: 40px 20px;
    background: #f9f9f9;
    border-radius: 8px;
    margin-top: 20px;
}

.wc-gs-search-form {
    background: #fff;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 8px;
    margin-bottom: 20px;
}

.wc-gs-pagination {
    text-align: center;
    margin-top: 30px;
}
</style>