<?php
/**
 * Plugin Name: WooCommerce Google Sheets Sync
 * Plugin URI: https://ultimatesubscriptions.com/
 * Description: Sync WooCommerce products with Google Sheets for easy bulk product management and updates.
 * Version: 1.3.0
 * Author: Wapiti Digital
 * Author URI: https://wapiti.digital/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-google-sheets-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.8
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_GS_SYNC_VERSION', '1.3.0');
define('WC_GS_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_GS_SYNC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_GS_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Google Sheets template (the "/copy" endpoint prompts the user to make their own copy)
define('WC_GS_SYNC_TEMPLATE_URL', 'https://docs.google.com/spreadsheets/d/1Qm6dMcI6C8-viF_GcPII-itRl-S63JThY1wyt2OHuRM/copy');

// Include Composer autoloader
if (file_exists(WC_GS_SYNC_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once WC_GS_SYNC_PLUGIN_PATH . 'vendor/autoload.php';
}

if (!function_exists('wc_gs_log')) {
    /**
     * Gated debug logger. Writes to the PHP error log only when debug logging is
     * enabled — either WordPress `WP_DEBUG` is on, or the plugin's "Debug logging"
     * setting is on (off by default). So a normal production sync writes nothing
     * and the log file doesn't grow. Filterable via `wc_gs_debug_logging`.
     */
    function wc_gs_log($message) {
        static $enabled = null;
        if ($enabled === null) {
            $on = (defined('WP_DEBUG') && WP_DEBUG);
            if (!$on) {
                $options = get_option('wc_gs_sync_options', array());
                $on = !empty($options['debug_logging']);
            }
            $enabled = (bool) apply_filters('wc_gs_debug_logging', $on);
        }
        if ($enabled) {
            error_log(is_scalar($message) ? (string) $message : print_r($message, true));
        }
    }
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
        
        // Drop the legacy, unused sync-logs table if a prior version created it
        $this->cleanup_legacy_tables();

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
     * Remove the legacy sync-logs table. Earlier versions created a
     * `wc_gs_sync_logs` table on activation but never wrote to it (the DB logger
     * was removed). The plugin no longer creates it; drop it if a prior version
     * left one behind, and clear its stored DB version.
     */
    private function cleanup_legacy_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_gs_sync_logs';
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        delete_option('wc_gs_sync_db_version');
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_options = array(
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_api_key' => '',
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