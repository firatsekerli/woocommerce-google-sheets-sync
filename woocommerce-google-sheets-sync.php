<?php
/**
 * Plugin Name: WooCommerce Google Sheets Sync
 * Plugin URI: https://ultimatesubscriptions.com/
 * Description: Sync WooCommerce products with Google Sheets for easy bulk product management and updates.
 * Version: 1.2.0
 * Author: Wapiti Digital
 * Author URI: https://ultimatesubscriptions.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-google-sheets-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.9
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_GS_SYNC_VERSION', '1.2.0');
define('WC_GS_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_GS_SYNC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_GS_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include Composer autoloader
if (file_exists(WC_GS_SYNC_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once WC_GS_SYNC_PLUGIN_PATH . 'vendor/autoload.php';
}

/**
 * Main Plugin Class
 */
class WC_Google_Sheets_Sync {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Declare WooCommerce HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
        
        // Load text domain for translations
        load_plugin_textdomain('wc-google-sheets-sync', false, dirname(WC_GS_SYNC_PLUGIN_BASENAME) . '/languages');
        
        // Initialize admin functionality
        if (is_admin()) {
            $this->init_admin();
        }
        
        // Initialize frontend functionality (if needed)
        $this->init_frontend();
        
        // FIXED: Always initialize AJAX handlers, even for frontend AJAX calls
        $this->init_ajax_handlers();
    }
    
    /**
     * Initialize admin functionality
     */
    private function init_admin() {
        // Include admin classes here
        require_once WC_GS_SYNC_PLUGIN_PATH . 'includes/admin/class-admin.php';
        
        // Initialize admin class
        new WC_GS_Admin();
    }

    /**
     * FIXED: Initialize AJAX handlers separately
     */
    private function init_ajax_handlers() {
        // Include required dependencies first
        $this->include_required_classes();
        
        // Include and initialize sync handler (which registers AJAX actions)
        if (file_exists(WC_GS_SYNC_PLUGIN_PATH . 'includes/class-wc-gs-sync-handler.php')) {
            require_once WC_GS_SYNC_PLUGIN_PATH . 'includes/class-wc-gs-sync-handler.php';
            
            // FIXED: Actually instantiate the sync handler class
            new WC_GS_Sync_Handler();
        }
    }
    
    /**
     * Include required classes for sync functionality
     */
    private function include_required_classes() {
        $required_files = array(
            'includes/class-google-sheets-api.php',
            'includes/class-wc-gs-product-data-builder.php',
            'includes/admin/class-settings.php'
        );
        
        foreach ($required_files as $file) {
            $file_path = WC_GS_SYNC_PLUGIN_PATH . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * Initialize frontend functionality
     */
    private function init_frontend() {
        // Include frontend classes here if needed
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            wp_die(__('This plugin requires WordPress 5.0 or higher.', 'wc-google-sheets-sync'));
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            wp_die(__('This plugin requires PHP 7.4 or higher.', 'wc-google-sheets-sync'));
        }
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            wp_die(__('This plugin requires WooCommerce to be installed and active.', 'wc-google-sheets-sync'));
        }
        
        // Create database tables if needed
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear any scheduled events
        wp_clear_scheduled_hook('wc_gs_sync_scheduled_import');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for sync logs
        $table_name = $wpdb->prefix . 'wc_gs_sync_logs';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            sheet_id varchar(255) NOT NULL,
            sync_type varchar(50) NOT NULL,
            status varchar(50) NOT NULL,
            products_processed int(11) DEFAULT 0,
            products_success int(11) DEFAULT 0,
            products_failed int(11) DEFAULT 0,
            error_message text,
            started_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            completed_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY (id),
            KEY sheet_id (sheet_id),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store database version
        add_option('wc_gs_sync_db_version', '1.0');
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_options = array(
            'google_client_id' => '',
            'google_client_secret' => '',
            'batch_size' => 10,
            'rate_limit_delay' => 300,
            'max_retries' => 3,
            'auto_sync_enabled' => false,
            'auto_sync_interval' => 'hourly'
        );
        
        add_option('wc_gs_sync_options', $default_options);
    }
    
    /**
     * Declare WooCommerce compatibility
     */
    public function declare_woocommerce_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    /**
     * Show notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        $message = sprintf(
            __('WooCommerce Google Sheets Sync requires %s to be installed and active.', 'wc-google-sheets-sync'),
            '<strong>' . __('WooCommerce', 'wc-google-sheets-sync') . '</strong>'
        );
        
        echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
    }
}

/**
 * Initialize the plugin
 */
function wc_google_sheets_sync() {
    return WC_Google_Sheets_Sync::get_instance();
}

// Start the plugin
wc_google_sheets_sync();