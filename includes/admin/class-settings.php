<?php
/**
 * Settings Page
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_GS_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_post_wc_gs_sync_save_settings', array($this, 'save_settings'));
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting('wc_gs_sync_settings', 'wc_gs_sync_options', array($this, 'validate_settings'));
    }
    
    /**
     * Save settings via admin_post
     */
    public function save_settings() {
        // Check nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wc_gs_sync_settings_nonce')) {
            wp_die(__('Security check failed', 'wc-google-sheets-sync'));
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-google-sheets-sync'));
        }
        
        // Get current options
        $options = $this->get_options();
        
        // Update options
        $options['google_client_id'] = sanitize_text_field($_POST['google_client_id']);
        $options['google_client_secret'] = sanitize_text_field($_POST['google_client_secret']);
        $options['batch_size'] = intval($_POST['batch_size']);
        $options['rate_limit_delay'] = intval($_POST['rate_limit_delay']);
        $options['max_retries'] = intval($_POST['max_retries']);
        $options['auto_sync_enabled'] = isset($_POST['auto_sync_enabled']);
        $options['auto_sync_interval'] = sanitize_text_field($_POST['auto_sync_interval']);
        
        // Save options
        update_option('wc_gs_sync_options', $options);
        
        // Redirect back with success message
        wp_redirect(add_query_arg(array(
            'page' => 'wc-google-sheets-settings',
            'message' => 'settings_saved'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Get plugin options
     */
    public function get_options() {
        return get_option('wc_gs_sync_options', array());
    }
    
    /**
     * Get specific option
     */
    public function get_option($key, $default = null) {
        $options = $this->get_options();
        return isset($options[$key]) ? $options[$key] : $default;
    }
    
    /**
     * Update option
     */
    public function update_option($key, $value) {
        $options = $this->get_options();
        $options[$key] = $value;
        update_option('wc_gs_sync_options', $options);
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // TODO: Include settings template
    }
    
    /**
     * Validate settings
     */
    public function validate_settings($input) {
        // TODO: Validate and sanitize settings input
        return $input;
    }
}