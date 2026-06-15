<?php
/**
 * Admin Main Class
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_GS_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wc_gs_sync_start', array($this, 'ajax_start_sync'));
        add_action('wp_ajax_wc_gs_sync_status', array($this, 'ajax_get_sync_status'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        
        // Initialize settings
        require_once WC_GS_SYNC_PLUGIN_PATH . 'includes/admin/class-settings.php';
        $this->settings = new WC_GS_Settings();
        
        // Initialize Google Sheets API
        require_once WC_GS_SYNC_PLUGIN_PATH . 'includes/class-google-sheets-api.php';
        $this->google_api = new WC_GS_Google_Sheets_API();
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main sync page
        add_submenu_page(
            'woocommerce',
            __('Google Sheets Sync', 'wc-google-sheets-sync'),
            __('Google Sheets Sync', 'wc-google-sheets-sync'),
            'manage_woocommerce',
            'wc-google-sheets-sync',
            array($this, 'admin_page')
        );
        
        // Settings page
        add_submenu_page(
            'woocommerce',
            __('Google Sheets Settings', 'wc-google-sheets-sync'),
            __('Sheets Settings', 'wc-google-sheets-sync'),
            'manage_woocommerce',
            'wc-google-sheets-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wc-google-sheets-sync') === false) {
            return;
        }
        
        wp_enqueue_style(
            'wc-gs-sync-admin',
            WC_GS_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WC_GS_SYNC_VERSION
        );
        
        // Load existing admin.js
        wp_enqueue_script(
            'wc-gs-sync-admin',
            WC_GS_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WC_GS_SYNC_VERSION,
            true
        );
        
        // Load sync functionality
        wp_enqueue_script(
            'wc-gs-sync-handler',
            WC_GS_SYNC_PLUGIN_URL . 'assets/js/sync.js',
            array('jquery'),
            WC_GS_SYNC_VERSION,
            true
        );
        
        // FIXED: Localize both scripts with correct variable names
        wp_localize_script('wc-gs-sync-admin', 'wcGsSyncAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_gs_sync_nonce')
        ));
        
        // Add global variables for sync.js
        wp_localize_script('wc-gs-sync-handler', 'wc_gs_sync_ajax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_gs_sync_nonce')
        ));
        
        // Make nonce available globally for sync.js
        wp_add_inline_script('wc-gs-sync-handler', 'var wc_gs_sync_nonce = "' . wp_create_nonce('wc_gs_sync_nonce') . '";', 'before');
    }
    
    /**
     * Handle OAuth callback
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-google-sheets-sync') {
            return;
        }
        
        if (!isset($_GET['auth']) || $_GET['auth'] !== 'callback') {
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-google-sheets-sync'));
        }
        
        if (isset($_GET['code'])) {
            // Handle successful authorization
            $result = $this->google_api->handle_auth_callback($_GET['code']);
            
            if (is_wp_error($result)) {
                $error_message = urlencode($result->get_error_message());
                wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync&auth_error=' . $error_message));
            } else {
                wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync&auth_success=1'));
            }
            exit;
        } elseif (isset($_GET['error'])) {
            // Handle authorization error
            $error_message = urlencode($_GET['error']);
            wp_redirect(admin_url('admin.php?page=wc-google-sheets-sync&auth_error=' . $error_message));
            exit;
        }
    }
    
    /**
     * Admin page callback - WITH ROUTING LOGIC
     */
    public function admin_page() {
        // Handle different actions
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        
        switch ($action) {
            case 'add-sheet':
                $this->render_add_sheet_page();
                break;
            case 'configure-sheet':
                $this->render_configure_sheet_page();
                break;
            default:
                $this->render_dashboard_page();
                break;
        }
    }
    
    /**
     * Render main dashboard page
     */
    private function render_dashboard_page() {
        include WC_GS_SYNC_PLUGIN_PATH . 'includes/admin/views/sync-dashboard.php';
    }
    
    /**
     * Render add sheet page
     */
    private function render_add_sheet_page() {
        $template_path = WC_GS_SYNC_PLUGIN_PATH . 'includes/admin/views/add-sheet.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="wrap"><h1>Add Sheet</h1><p>Template file not found: ' . $template_path . '</p></div>';
        }
    }
    
    /**
     * Render configure sheet page
     */
    private function render_configure_sheet_page() {
        include WC_GS_SYNC_PLUGIN_PATH . 'includes/admin/views/configure-sheet.php';
    }
    
    /**
     * Settings page callback
     */
    public function settings_page() {
        // Load the settings template
        include WC_GS_SYNC_PLUGIN_PATH . 'includes/admin/views/settings.php';
    }
    
    /**
     * AJAX start sync
     */
    public function ajax_start_sync() {
        check_ajax_referer('wc_gs_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-google-sheets-sync'));
        }
        
        // TODO: Start sync process via AJAX
        wp_send_json_success();
    }
    
    /**
     * AJAX get sync status
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('wc_gs_sync_nonce', 'nonce');
        
        // TODO: Get sync status via AJAX
        wp_send_json_success();
    }
}