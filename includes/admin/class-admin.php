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
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('admin_init', array($this, 'handle_dashboard_actions'));
        add_action('admin_init', array($this, 'handle_configure_sheet_save'));
        
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
        // Single combined page (Sheets dashboard + Settings tabs)
        add_submenu_page(
            'woocommerce',
            __('Google Sheets Sync', 'wc-google-sheets-sync'),
            __('Google Sheets Sync', 'wc-google-sheets-sync'),
            'manage_woocommerce',
            'wc-google-sheets-sync',
            array($this, 'admin_page')
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
            // CSRF protection: verify the OAuth "state" nonce we set on the auth URL
            $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
            if (!wp_verify_nonce($state, 'wc_gs_oauth_state')) {
                wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&auth_error=' . urlencode(__('Invalid authentication state. Please try connecting again.', 'wc-google-sheets-sync'))));
                exit;
            }

            // Handle successful authorization
            $code = sanitize_text_field(wp_unslash($_GET['code']));
            $result = $this->google_api->handle_auth_callback($code);

            if (is_wp_error($result)) {
                $error_message = urlencode($result->get_error_message());
                wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&auth_error=' . $error_message));
            } else {
                wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&auth_success=1'));
            }
            exit;
        } elseif (isset($_GET['error'])) {
            // Handle authorization error
            $error_message = urlencode(sanitize_text_field(wp_unslash($_GET['error'])));
            wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&auth_error=' . $error_message));
            exit;
        }
    }

    /**
     * Handle dashboard state-changing actions (remove sheet, disconnect) early,
     * before any output, with capability + nonce checks. Previously these ran
     * inside the dashboard view after output (redirects failed, checks late).
     */
    public function handle_dashboard_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-google-sheets-sync') {
            return;
        }
        if (!isset($_GET['action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_GET['action']));

        if ($action === 'remove-sheet') {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('Insufficient permissions', 'wc-google-sheets-sync'));
            }
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wc_gs_remove_sheet')) {
                wp_die(__('Security check failed', 'wc-google-sheets-sync'));
            }

            $sheet_id = isset($_GET['sheet_id']) ? sanitize_text_field(wp_unslash($_GET['sheet_id'])) : '';
            $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
            if ($sheet_id !== '' && isset($connected_sheets[$sheet_id])) {
                unset($connected_sheets[$sheet_id]);
                update_option('wc_gs_sync_connected_sheets', $connected_sheets);
            }

            wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&sheet_removed=1'));
            exit;
        }

        if ($action === 'disconnect') {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('Insufficient permissions', 'wc-google-sheets-sync'));
            }
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'wc_gs_disconnect')) {
                wp_die(__('Security check failed', 'wc-google-sheets-sync'));
            }

            $this->google_api->disconnect();

            wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&disconnected=1'));
            exit;
        }
    }

    /**
     * Handle the "Connect This Sheet" save before any output so the redirect
     * works and capability/nonce checks run early.
     */
    public function handle_configure_sheet_save() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-google-sheets-sync') {
            return;
        }
        if (!isset($_GET['action']) || $_GET['action'] !== 'configure-sheet') {
            return;
        }
        if (!isset($_POST['action']) || $_POST['action'] !== 'save_sheet_config') {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-google-sheets-sync'));
        }

        $nonce = isset($_POST['wc_gs_config_nonce']) ? sanitize_text_field(wp_unslash($_POST['wc_gs_config_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wc_gs_sheet_config')) {
            wp_die(__('Security check failed', 'wc-google-sheets-sync'));
        }

        $sheet_id = isset($_GET['sheet_id']) ? sanitize_text_field(wp_unslash($_GET['sheet_id'])) : '';
        if ($sheet_id === '' && isset($_POST['sheet_id'])) {
            $sheet_id = sanitize_text_field(wp_unslash($_POST['sheet_id']));
        }
        if ($sheet_id === '') {
            wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet'));
            exit;
        }

        if (!$this->google_api->is_authenticated()) {
            wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync'));
            exit;
        }

        $sheet_info = $this->google_api->get_sheet_info($sheet_id);
        if (is_wp_error($sheet_info)) {
            wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&action=add-sheet&error=' . urlencode($sheet_info->get_error_message())));
            exit;
        }

        // Preserve created_at / last_synced when editing an existing connection
        $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
        $existing = isset($connected_sheets[$sheet_id]) ? $connected_sheets[$sheet_id] : array();

        $connected_sheets[$sheet_id] = array(
            'sheet_id'          => $sheet_id,
            'sheet_title'       => $sheet_info['title'],
            'sheet_url'         => $sheet_info['url'],
            'sheet_tab'         => isset($_POST['sheet_tab']) ? sanitize_text_field(wp_unslash($_POST['sheet_tab'])) : '',
            'auto_sync_enabled' => isset($_POST['auto_sync_enabled']),
            'created_at'        => isset($existing['created_at']) ? $existing['created_at'] : current_time('mysql'),
            'last_synced'       => isset($existing['last_synced']) ? $existing['last_synced'] : null,
        );

        update_option('wc_gs_sync_connected_sheets', $connected_sheets);

        wp_safe_redirect(admin_url('admin.php?page=wc-google-sheets-sync&sheet_connected=1'));
        exit;
    }
    
    /**
     * Admin page callback - WITH ROUTING LOGIC
     */
    public function admin_page() {
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';

        // Sub-flows render their own full screen (no tabs)
        if ($action === 'add-sheet') {
            $this->render_add_sheet_page();
            return;
        }
        if ($action === 'configure-sheet') {
            $this->render_configure_sheet_page();
            return;
        }

        // Main tabbed screen: Sheets dashboard + Settings
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'dashboard';
        if (!in_array($tab, array('dashboard', 'settings'), true)) {
            $tab = 'dashboard';
        }

        $tabs = array(
            'dashboard' => __('Sheets', 'wc-google-sheets-sync'),
            'settings'  => __('Settings', 'wc-google-sheets-sync'),
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Google Sheets Sync', 'wc-google-sheets-sync') . '</h1>';
        echo '<nav class="nav-tab-wrapper wp-clearfix">';
        foreach ($tabs as $key => $label) {
            $url = admin_url('admin.php?page=wc-google-sheets-sync' . ('dashboard' === $key ? '' : '&tab=' . $key));
            $active = ($tab === $key) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '</div>';

        if ('settings' === $tab) {
            $this->settings_page();
        } else {
            $this->render_dashboard_page();
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
}