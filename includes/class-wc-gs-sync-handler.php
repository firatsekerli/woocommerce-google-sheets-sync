<?php
/**
 * WooCommerce Google Sheets Sync Handler
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_GS_Sync_Handler {
    
    private $google_api;
    
    public function __construct() {
        // Don't initialize Google API in constructor to avoid dependency issues
        $this->google_api = null;

        // Register AJAX handlers
        add_action('wp_ajax_wc_gs_sync_sheet', array($this, 'handle_sync_request'));
        add_action('wp_ajax_wc_gs_get_sync_progress', array($this, 'get_sync_progress'));
        add_action('wp_ajax_wc_gs_cancel_sync', array($this, 'cancel_sync'));
        add_action('wp_ajax_wc_gs_export_to_sheet', array($this, 'handle_export_request'));

        // Scheduled (auto) sync via WP-Cron
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('wc_gs_sync_scheduled_import', array($this, 'run_scheduled_sync'));
        add_action('update_option_wc_gs_sync_options', array($this, 'update_sync_schedule'), 10, 0);
        add_action('add_option_wc_gs_sync_options', array($this, 'update_sync_schedule'), 10, 0);

        // Background processing: each sync runs in batches via Action Scheduler
        add_action('wc_gs_process_sync_batch', array($this, 'process_sync_batch'), 10, 3);
    }
    
    /**
     * Get Google API instance (lazy loading)
     */
    private function get_google_api() {
        if ($this->google_api === null) {
            // Check if the class exists
            if (!class_exists('WC_GS_Google_Sheets_API')) {
                throw new Exception('Google Sheets API class not found. Please ensure the Google API is properly configured.');
            }
            $this->google_api = new WC_GS_Google_Sheets_API();
        }
        return $this->google_api;
    }

    /**
     * Read a plugin setting from wc_gs_sync_options with a fallback default.
     */
    private function get_setting($key, $default = null) {
        $options = get_option('wc_gs_sync_options', array());
        return isset($options[$key]) ? $options[$key] : $default;
    }

    /**
     * Register a 'weekly' cron schedule (hourly/twicedaily/daily are built in).
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once Weekly', 'wc-google-sheets-sync'),
            );
        }
        return $schedules;
    }

    /**
     * (Re)schedule the auto-sync cron event based on the current settings.
     * Hooked to option add/update so it stays in sync with the settings page.
     */
    public function update_sync_schedule() {
        $options  = get_option('wc_gs_sync_options', array());
        // Pro gate: scheduled auto-sync requires a Pro license once enforcement
        // is on. No-op while enforcement is off.
        $enabled  = !empty($options['auto_sync_enabled']) && wc_gs_can('auto_sync');
        $interval = isset($options['auto_sync_interval']) ? $options['auto_sync_interval'] : 'hourly';

        $valid_intervals = array('hourly', 'twicedaily', 'daily', 'weekly');
        if (!in_array($interval, $valid_intervals, true)) {
            $interval = 'hourly';
        }

        // Always clear any existing schedule first so interval changes take effect
        $timestamp = wp_next_scheduled('wc_gs_sync_scheduled_import');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_gs_sync_scheduled_import');
        }

        if ($enabled) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $interval, 'wc_gs_sync_scheduled_import');
            wc_gs_log('WC_GS_Sync: Auto-sync scheduled (' . $interval . ')');
        } else {
            wc_gs_log('WC_GS_Sync: Auto-sync disabled, schedule cleared');
        }
    }

    /**
     * Cron callback: run a sync for every connected sheet.
     */
    public function run_scheduled_sync() {
        $options = get_option('wc_gs_sync_options', array());
        if (empty($options['auto_sync_enabled']) || !wc_gs_can('auto_sync')) {
            return;
        }

        $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
        if (empty($connected_sheets) || !is_array($connected_sheets)) {
            return;
        }

        foreach ($connected_sheets as $sheet_id => $sheet_config) {
            if (!is_array($sheet_config)) {
                continue;
            }
            // Only include sheets that opted in to automatic syncing
            if (empty($sheet_config['auto_sync_enabled'])) {
                continue;
            }
            $this->start_background_sync($sheet_config);
        }
    }

    /**
     * AJAX: export all WooCommerce products into a connected sheet.
     */
    public function handle_export_request() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wc_gs_sync_nonce')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $sheet_id = isset($_POST['sheet_id']) ? sanitize_text_field(wp_unslash($_POST['sheet_id'])) : '';
        if (empty($sheet_id)) {
            wp_send_json_error('Invalid sheet ID');
        }

        $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
        if (!isset($connected_sheets[$sheet_id])) {
            wp_send_json_error('Sheet not found');
        }

        $result = $this->export_products_to_sheet($connected_sheets[$sheet_id]);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * Export every simple product into the given sheet, replacing its data rows
     * while preserving the header row.
     */
    private function export_products_to_sheet($sheet_config) {
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        require_once WC_GS_SYNC_PLUGIN_PATH . 'includes/class-wc-gs-product-exporter.php';

        $google_api = $this->get_google_api();
        if (!$google_api->is_authenticated()) {
            return new WP_Error('auth_error', 'Not authenticated with Google');
        }

        $spreadsheet_id = $sheet_config['sheet_id'];
        $tab = isset($sheet_config['sheet_tab']) ? $sheet_config['sheet_tab'] : 'Sheet1';

        // Read the existing header row to align the export to the sheet's columns
        $existing = $google_api->get_sheet_data($spreadsheet_id, $tab . '!1:1');
        if (is_wp_error($existing)) {
            return $this->friendly_sheet_access_error($existing);
        }

        $headers = (!empty($existing) && isset($existing[0]) && is_array($existing[0])) ? $existing[0] : array();

        // If the sheet has no header row yet, write a default template: the fixed
        // columns plus the "Attributes" marker + a column per global attribute in
        // the store + the "Meta" marker, so a blank-sheet export is a complete,
        // round-trippable template (variable-product attribute values export too).
        if (empty($headers)) {
            $headers = array_merge(
                $this->get_default_export_headers(),
                $this->get_default_attribute_meta_headers()
            );
            $write_headers = $google_api->batch_update_sheet($spreadsheet_id, array(
                array('range' => $tab . '!A1', 'values' => array($headers)),
            ));
            if (is_wp_error($write_headers)) {
                return $write_headers;
            }
        }

        // Build rows for every simple and variable product (paged to limit
        // memory). A variable product expands to a parent row plus one row per
        // variation.
        $exporter = new WC_GS_Product_Exporter();
        $rows = array();
        $paged = 1;

        do {
            $products = wc_get_products(array(
                'type'    => array('simple', 'variable'),
                'status'  => array('publish', 'draft', 'pending', 'private'),
                'limit'   => 100,
                'page'    => $paged,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ));

            foreach ($products as $product) {
                foreach ($exporter->build_product_rows($product, $headers) as $product_row) {
                    $rows[] = $product_row;
                }
            }
            $paged++;
        } while (count($products) === 100);

        // Clear existing data rows (keep the header), then write fresh data
        $clear = $google_api->clear_values($spreadsheet_id, $tab . '!A2:ZZ');
        if (is_wp_error($clear)) {
            return $clear;
        }

        if (!empty($rows)) {
            $chunks = array_chunk($rows, 500);
            $start_row = 2;
            foreach ($chunks as $chunk) {
                $write = $google_api->batch_update_sheet($spreadsheet_id, array(
                    array('range' => $tab . '!A' . $start_row, 'values' => $chunk),
                ));
                if (is_wp_error($write)) {
                    return $write;
                }
                $start_row += count($chunk);
            }
        }

        return array(
            'exported' => count($rows),
            'message'  => sprintf(__('Exported %d products to the sheet.', 'wc-google-sheets-sync'), count($rows)),
        );
    }

    /**
     * Default header row written when exporting to a sheet that has none.
     */
    private function get_default_export_headers() {
        $headers = array(
            'ID', 'SKU', 'GTIN, UPC, EAN, or ISBN', 'Stock Management', 'Quantity',
            'Stock Status', 'Backorder', 'Low Stock Threshold', 'Sold Individually',
            'Name', 'Slug', 'Description', 'Short Description', 'Type', 'Parent',
            'Virtual', 'Downloadable', 'Download Files', 'Download Limit', 'Download Expiry',
            'Status', 'Visibility', 'Password', 'Catalog Visibility', 'Featured',
            'Regular Price', 'Sale Price', 'Sale Start Date', 'Sale End Date',
            'Tax Status', 'Tax Class', 'Weight', 'Dimension (L)', 'Dimension (W)',
            'Dimension (H)', 'Shipping Class', 'Upsells', 'Cross-sells', 'Category Path',
            'Tags', 'Sync Status', 'Sync Error', 'Last Synced', 'Force Update', 'Delete',
            'Image', 'Image Alt Text',
        );

        // Gallery images with an alt-text column each, matching the import range
        // (filterable via wc_gs_gallery_image_count, default 20).
        $gallery_count = (int) apply_filters('wc_gs_gallery_image_count', 20);
        for ($n = 1; $n <= $gallery_count; $n++) {
            $label = sprintf('Gallery Image %02d', $n);
            $headers[] = $label;
            $headers[] = $label . ' Alt Text';
        }

        $headers[] = 'Purchase Note';
        $headers[] = 'Position';
        $headers[] = 'Allow Reviews';

        return $headers;
    }

    /**
     * Dynamic tail for a default export template: the "Attributes" marker followed
     * by a column for every global product attribute in the store, then the "Meta"
     * marker. Appended only when writing default headers to a fresh sheet, so a
     * blank-sheet export produces a complete template that round-trips variable
     * products (their attribute values land in these columns).
     */
    private function get_default_attribute_meta_headers() {
        $cols = array('Attributes');

        if (function_exists('wc_get_attribute_taxonomies')) {
            foreach (wc_get_attribute_taxonomies() as $tax) {
                $label = (isset($tax->attribute_label) && trim((string) $tax->attribute_label) !== '')
                    ? trim((string) $tax->attribute_label)
                    : (isset($tax->attribute_name) ? trim((string) $tax->attribute_name) : '');
                if ($label !== '') {
                    $cols[] = $label;
                }
            }
        }

        $cols[] = 'Meta';
        return $cols;
    }
    
    /**
     * NEW: Find column indices for write-back fields
     */
    private function find_column_indices($headers) {
        $columns = array();
        
        // Map of field names to possible header variations
        $field_mappings = array(
            'id' => array('ID', 'id', 'Product ID'),
			'sku' => array('SKU', 'sku', 'Product SKU', 'Product Code'), // NEW: Add SKU mapping
			'gtin' => array('GTIN, UPC, EAN, or ISBN', 'GTIN', 'UPC', 'EAN', 'ISBN'), // NEW: GTIN mapping
			'quantity' => array('Quantity', 'quantity', 'Stock Quantity', 'Stock', 'Qty'), // NEW: Add this line
            'sync_status' => array('Sync Status', 'sync_status', 'Status'),
            'sync_error' => array('Sync Error', 'sync_error', 'Error'),
            'last_synced' => array('Last Synced', 'last_synced', 'Last Updated', 'Synced At'),
			'delete' => array('Delete', 'delete', 'Remove', 'Delete Product'), // NEW: Add this line
			'force_update' => array('Force Update', 'force_update', 'Force')
        );
        
        foreach ($field_mappings as $field => $possible_headers) {
            foreach ($possible_headers as $header_name) {
                $index = array_search($header_name, $headers);
                if ($index !== false) {
                    $columns[$field] = array(
                        'index' => $index,
                        'letter' => $this->column_index_to_letter($index),
                        'header' => $header_name
                    );
                    wc_gs_log('WC_GS_Sync: Found ' . $field . ' column: "' . $header_name . '" at index ' . $index . ' (column ' . $columns[$field]['letter'] . ')');
                    break; // Found it, stop looking for this field
                }
            }
        }
        
        return $columns;
    }
    
    /**
     * NEW: Convert column index to letter (0=A, 1=B, etc.)
     */
    private function column_index_to_letter($index) {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intval($index / 26) - 1;
        }
        return $letter;
    }
    
    /**
     * Handle sync request from dashboard
     */
    public function handle_sync_request() {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wc_gs_sync_nonce')) {
            wp_die('Security check failed');
        }

        // Verify capability
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        $sheet_id = isset($_POST['sheet_id']) ? sanitize_text_field(wp_unslash($_POST['sheet_id'])) : '';

        if (empty($sheet_id)) {
            wp_send_json_error('Invalid sheet ID');
        }
        
        // Get sheet configuration
        $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
        
        if (!isset($connected_sheets[$sheet_id])) {
            wp_send_json_error('Sheet not found');
        }
        
        $sheet_config = $connected_sheets[$sheet_id];

        // Start the sync in the background (processed in batches via Action Scheduler)
        $sync_id = $this->start_background_sync($sheet_config);

        if (is_wp_error($sync_id)) {
            wp_send_json_error($sync_id->get_error_message());
        }

        wp_send_json_success(array(
            'sync_id' => $sync_id,
            'message' => 'Sync started successfully'
        ));
    }
    
    /**
     * Initialize sync progress tracking
     */
    private function init_sync_progress($sync_id, $sheet_config) {
        $progress_data = array(
            'sync_id' => $sync_id,
            'sheet_title' => $sheet_config['sheet_title'],
            'status' => 'starting',
            'progress' => 0,
            'total_rows' => 0,
            'processed_rows' => 0,
            'created_products' => 0,
            'updated_products' => 0,
            'skipped_rows' => 0,
            'variations' => 0,
            'errors' => array(),
            'current_step' => 'Initializing sync...',
            'started_at' => current_time('mysql'),
            'completed_at' => null
        );
        
        set_transient('wc_gs_sync_progress_' . $sync_id, $progress_data, 6 * HOUR_IN_SECONDS);
    }
    
    /**
     * Update sync progress
     */
    private function update_sync_progress($sync_id, $updates) {
        $progress = get_transient('wc_gs_sync_progress_' . $sync_id);
        if ($progress) {
            $progress = array_merge($progress, $updates);
            set_transient('wc_gs_sync_progress_' . $sync_id, $progress, 6 * HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Get sync progress for AJAX calls
     */
    public function get_sync_progress() {
        // Verify nonce
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wc_gs_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }

        // Verify capability
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        // sanitize_key constrains the id to [a-z0-9_-] so it can't be used to
        // craft arbitrary option/transient names when concatenated below.
        $sync_id = isset($_GET['sync_id']) ? sanitize_key(wp_unslash($_GET['sync_id'])) : '';
        if (empty($sync_id)) {
            wp_send_json_error('Invalid sync ID');
        }

        $progress = get_transient('wc_gs_sync_progress_' . $sync_id);

        if (!$progress) {
            wp_send_json_error('Sync progress not found');
        }

        // Self-heal: if the background chain broke (e.g. the host killed an async
        // request before it could queue the next batch), re-queue it from the last
        // saved offset. Runs off the dashboard's normal progress polling, so a
        // stalled sync recovers on its own with no server cron required.
        $this->maybe_resume_stalled_sync($sync_id, $progress);

        wp_send_json_success($progress);
    }

    /**
     * Re-queue a sync whose background chain has stalled. A host that kills the
     * Action Scheduler async request mid-batch can leave a job with no pending or
     * in-progress batch action and the next pass never queued. When the dashboard
     * polls progress we detect that and enqueue a fresh batch from the saved
     * offset. Heavily guarded so it never double-runs an active batch.
     */
    private function maybe_resume_stalled_sync($sync_id, $progress) {
        if (!is_array($progress)) {
            return;
        }
        $status = isset($progress['status']) ? $progress['status'] : '';
        if (!in_array($status, array('processing', 'starting'), true)) {
            return; // only active syncs
        }
        if (!function_exists('as_enqueue_async_action') || !function_exists('as_get_scheduled_actions')) {
            return; // no Action Scheduler — inline path handles it
        }

        // Throttle the (DB-touching) check so 1s polling doesn't hammer it.
        $chk_key = 'wc_gs_sync_resume_chk_' . $sync_id;
        if (get_transient($chk_key)) {
            return;
        }
        set_transient($chk_key, 1, 15); // re-check at most every 15s

        // If the job/state are gone, the sync finished or was cancelled.
        $job = get_option('wc_gs_sync_job_' . $sync_id);
        $state = get_option('wc_gs_sync_state_' . $sync_id);
        if (!is_array($job) || !is_array($state)) {
            return;
        }

        // Don't touch it if a batch is already queued or running.
        $active = as_get_scheduled_actions(array(
            'hook'     => 'wc_gs_process_sync_batch',
            'group'    => 'wc-gs-sync',
            'status'   => array('pending', 'in-progress'),
            'per_page' => 1,
        ), 'ids');
        if (!empty($active)) {
            return;
        }

        $offset = isset($state['next_offset']) ? (int) $state['next_offset'] : 0;
        as_enqueue_async_action('wc_gs_process_sync_batch', array($sync_id, $offset, 1), 'wc-gs-sync');
        wc_gs_log('WC_GS_Sync: Resumed stalled sync ' . $sync_id . ' from offset ' . $offset);
    }

    /**
     * AJAX: cancel an in-progress sync.
     *
     * Cancels any queued background batches, then deletes the job/state so that a
     * batch currently mid-flight aborts on its next step (run_sync_batch bails
     * when the job option is gone) and finalize never runs. The progress record
     * is marked "cancelled" so the dashboard stops polling. Products already
     * imported are left intact; only the remaining work (and sheet write-back)
     * stops.
     */
    public function cancel_sync() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wc_gs_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
        }

        // sanitize_key constrains the id to [a-z0-9_-] so it can't be used to
        // craft arbitrary option/transient names when concatenated below.
        $sync_id = isset($_POST['sync_id']) ? sanitize_key(wp_unslash($_POST['sync_id'])) : '';

        // Cancel any queued background batches for this plugin's sync group
        // (empty args array matches the action regardless of sync_id/offset).
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wc_gs_process_sync_batch', array(), 'wc-gs-sync');
        }

        if ($sync_id !== '') {
            delete_option('wc_gs_sync_job_' . $sync_id);
            delete_option('wc_gs_sync_state_' . $sync_id);

            $progress = get_transient('wc_gs_sync_progress_' . $sync_id);
            if (!is_array($progress)) {
                $progress = array();
            }
            $progress['status'] = 'cancelled';
            $progress['current_step'] = 'Sync cancelled.';
            $progress['completed_at'] = current_time('mysql');
            set_transient('wc_gs_sync_progress_' . $sync_id, $progress, HOUR_IN_SECONDS);

            wc_gs_log('WC_GS_Sync: Sync ' . $sync_id . ' cancelled by user');
        }

        wp_send_json_success(array('message' => 'Sync cancelled.'));
    }

    /**
     * Start a sync in the background. Reads the sheet once, stores the job, and
     * enqueues the first batch (processed via Action Scheduler, or inline if AS
     * is unavailable). Returns the sync id, or a WP_Error on failure.
     */
    private function start_background_sync($sheet_config) {
        $sync_id = uniqid('sync_');
        $this->init_sync_progress($sync_id, $sheet_config);

        $this->update_sync_progress($sync_id, array(
            'status' => 'reading',
            'current_step' => 'Reading data from Google Sheets...',
        ));

        $t_read = microtime(true);
        $sheet_data = $this->get_sheet_data($sheet_config);
        $read_ms = (int) round((microtime(true) - $t_read) * 1000);
        wc_gs_log('WC_GS_Timing: sheet read = ' . $read_ms . 'ms');

        if (is_wp_error($sheet_data)) {
            $this->update_sync_progress($sync_id, array(
                'status' => 'error',
                'current_step' => 'Sync failed: ' . $sheet_data->get_error_message(),
                'completed_at' => current_time('mysql'),
            ));
            return $sheet_data;
        }

        if (empty($sheet_data)) {
            $this->update_sync_progress($sync_id, array(
                'status' => 'error',
                'current_step' => 'Sync failed: No data found in sheet',
                'completed_at' => current_time('mysql'),
            ));
            return new WP_Error('no_data', 'No data found in sheet');
        }

        $headers = $sheet_data[0];               // Row 1 = headers
        $data_rows = array_slice($sheet_data, 1); // Remaining rows = product data

        // Build ordered "work items" so the engine can process all parents
        // (simple + variable) before any variations. Each item carries its real
        // sheet row number, so write-back still targets the right cells after the
        // reorder. (For a simple-only sheet this is a no-op: order is preserved.)
        $work_items = $this->build_work_items($data_rows, $headers);
        $total = count($work_items);

        // Persist the job payload so each background batch can pick up where the
        // previous one left off (options, not transients, for durability).
        // enqueued_at / read_ms are used to measure queue-dispatch latency.
        update_option('wc_gs_sync_job_' . $sync_id, array(
            'sheet_config' => $sheet_config,
            'headers' => $headers,
            'rows' => $work_items,
            'total' => $total,
            'read_ms' => $read_ms,
            'enqueued_at' => microtime(true),
        ), false);
        update_option('wc_gs_sync_state_' . $sync_id, $this->get_default_sync_state(), false);

        $this->update_sync_progress($sync_id, array(
            'total_rows' => $total,
            'status' => 'processing',
            'current_step' => 'Processing products...',
        ));

        // Process the first batch(es) inline for immediate results. Waiting for the
        // Action Scheduler async queue runner can lag many seconds on some hosts
        // (blocked loopback). We run inline up to a time budget and hand only the
        // remainder off to the background queue for large catalogs.
        wc_gs_log('WC_GS_Timing: starting first batch inline');
        $this->process_sync_batch($sync_id, 0);

        return $sync_id;
    }

    /**
     * Turn raw sheet rows into ordered work items for the engine.
     *
     * Each item is array('row' => <cells>, 'sheet_row' => <1-based row>,
     * 'kind' => simple|variable|variation, 'parent_sku' => <variation parent>).
     * Parents (simple + variable) are emitted first, in sheet order, then all
     * variations in sheet order — a stable partition so that, because batches
     * only move forward, every parent is processed before its variations. The
     * real sheet row number is captured here (it can no longer be derived from
     * the batch offset once rows are reordered).
     */
    private function build_work_items($data_rows, $headers) {
        require_once WC_GS_SYNC_PLUGIN_PATH . 'includes/class-wc-gs-product-data-builder.php';

        $parents = array();
        $variations = array();

        foreach ($data_rows as $i => $row) {
            $class = WC_GS_Product_Data_Builder::classify_row($row, $headers);
            $item = array(
                'row'        => $row,
                'sheet_row'  => $i + 2, // +1 for 1-based rows, +1 for the header row
                'kind'       => $class['kind'],
                'parent_sku' => $class['parent_sku'],
            );

            if ($class['kind'] === 'variation') {
                $variations[] = $item;
            } else {
                $parents[] = $item;
            }
        }

        return array_merge($parents, $variations);
    }

    /**
     * Action Scheduler callback: process one batch, then enqueue the next batch
     * (or, without Action Scheduler, loop through the remaining batches inline).
     */
    public function process_sync_batch($sync_id, $offset, $is_background = false) {
        $sync_id = (string) $sync_id;
        $offset = (int) $offset;
        $has_as = function_exists('as_enqueue_async_action');

        // Background pass (Action Scheduler's async HTTP runner): do exactly ONE
        // time-budgeted batch, queue the next pass, and return. Keeping each request
        // short is what makes background syncs survive hosts that kill long requests
        // (nginx/PHP-FPM request timeout → "recv() failed / connection reset"): a
        // long request would be killed mid-batch and never queue the next pass, so
        // the chain would stall. One short batch per request finishes well within
        // host limits and the queue chains itself — no server cron required.
        // (run_sync_batch caps its own wall-clock via wc_gs_batch_time_limit.)
        if ($is_background && $has_as) {
            $next = $this->run_sync_batch($sync_id, $offset);
            if ($next === null) {
                return; // finished (or aborted) — run_sync_batch finalized/cleaned up
            }
            as_enqueue_async_action('wc_gs_process_sync_batch', array($sync_id, (int) $next, 1), 'wc-gs-sync');
            return;
        }

        // Inline (the browser's Sync Now request) or no Action Scheduler available:
        // process within a short budget for immediate feedback, then hand the rest
        // to the background queue (or, without AS, keep going inline until done).
        $deadline = microtime(true) + 15;
        while (true) {
            $next = $this->run_sync_batch($sync_id, $offset);

            if ($next === null) {
                return; // Finished (or aborted)
            }

            $offset = (int) $next;

            if (microtime(true) >= $deadline) {
                if ($has_as) {
                    as_enqueue_async_action('wc_gs_process_sync_batch', array($sync_id, $offset, 1), 'wc-gs-sync');
                    return;
                }
                // No Action Scheduler at all: nothing else will continue this, so
                // keep processing inline until the job is done.
            }
        }
    }

    /**
     * Process a single batch of rows. Returns the next offset, or null when the
     * sync is finished (finalized) or aborted.
     */
    public function run_sync_batch($sync_id, $offset) {
        $job = get_option('wc_gs_sync_job_' . $sync_id);
        if (!is_array($job)) {
            wc_gs_log('WC_GS_Sync: Batch aborted, job data missing for ' . $sync_id);
            return null;
        }

        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $headers = $job['headers'];
        $rows = $job['rows'];
        $total = (int) $job['total'];

        $state = get_option('wc_gs_sync_state_' . $sync_id);
        if (!is_array($state)) {
            $state = $this->get_default_sync_state();
        }

        $batch_size = max(1, (int) $this->get_setting('batch_size', 10));

        // Measure how long Action Scheduler took to actually start this job
        // (the "Processing products..." wait the user sees).
        if ((int) $offset === 0 && isset($job['enqueued_at'])) {
            $dispatch_ms = (int) round((microtime(true) - $job['enqueued_at']) * 1000);
            $state['dispatch_ms'] = $dispatch_ms;
            $state['read_ms'] = isset($job['read_ms']) ? (int) $job['read_ms'] : 0;
            wc_gs_log('WC_GS_Timing: queue dispatch latency (enqueue -> first batch) = ' . $dispatch_ms . 'ms');
        }

        $t_batch = microtime(true);

        // Cap wall-clock per batch so image-heavy rows (featured + up to 20 gallery
        // images each) can't run a single batch past the server / Action Scheduler
        // limit — which would reset the async-runner connection and mark the batch
        // "failed after 300 seconds". We always process at least one row, then stop
        // once the budget is hit and hand the remaining rows to the next run. This
        // makes wall-clock the real limit and Batch Size just an upper bound.
        $batch_deadline = microtime(true) + max(5, (int) apply_filters('wc_gs_batch_time_limit', 15));
        $processed_in_slice = 0;

        try {
            $slice = array_slice($rows, $offset, $batch_size);
            foreach ($slice as $i => $item) {
                if (is_array($item) && isset($item['row'])) {
                    // Work item (current format): carries its real sheet row + kind.
                    $row = $item['row'];
                    $google_sheet_row = (int) $item['sheet_row'];
                    $kind = isset($item['kind']) ? $item['kind'] : 'simple';
                    $parent_sku = isset($item['parent_sku']) ? $item['parent_sku'] : '';
                } else {
                    // Legacy raw row (a job enqueued before this version): derive the
                    // sheet row positionally as before. offset 0, i 0 => row 2.
                    $row = $item;
                    $google_sheet_row = $offset + $i + 2;
                    $kind = 'simple';
                    $parent_sku = '';
                }
                $this->process_row_into_state($headers, $row, $google_sheet_row, $state, $kind, $parent_sku);
                $processed_in_slice++;

                // Yield after at least one row once the time budget is spent, so the
                // remaining rows continue on the next batch instead of overrunning.
                if (microtime(true) >= $batch_deadline) {
                    break;
                }
            }
        } catch (Exception $e) {
            wc_gs_log('WC_GS_Sync: Fatal batch error for ' . $sync_id . ': ' . $e->getMessage());
            $this->update_sync_progress($sync_id, array(
                'status' => 'error',
                'current_step' => 'Sync failed: ' . $e->getMessage(),
                'completed_at' => current_time('mysql'),
            ));
            delete_option('wc_gs_sync_job_' . $sync_id);
            delete_option('wc_gs_sync_state_' . $sync_id);
            return null;
        }

        $batch_ms = (int) round((microtime(true) - $t_batch) * 1000);
        $state['process_ms'] = (isset($state['process_ms']) ? (int) $state['process_ms'] : 0) + $batch_ms;
        wc_gs_log('WC_GS_Timing: batch at offset ' . (int) $offset . ' processed ' . $processed_in_slice . ' row(s) in ' . $batch_ms . 'ms');

        // Advance by the number actually processed (may be < batch_size if the
        // per-batch time budget cut the slice short).
        $processed = min($offset + max(1, $processed_in_slice), $total);

        // Persist the resume point so a stalled job can be continued from here
        // (used by the dashboard self-heal) without re-reading the action's offset.
        $state['next_offset'] = $processed;
        update_option('wc_gs_sync_state_' . $sync_id, $state, false);
        $percent = $total > 0 ? round(($processed / $total) * 100) : 100;

        $this->update_sync_progress($sync_id, array(
            'progress' => $percent,
            'processed_rows' => $processed,
            'created_products' => $state['created'],
            'updated_products' => $state['updated'],
            'deleted_products' => $state['deleted'],
            'skipped_rows' => $state['skipped'],
            'variations' => $state['variations_created'] + $state['variations_updated'] + $state['variations_deleted'] + $state['variations_skipped'],
            'errors' => $state['errors'],
            'current_step' => "Processing row {$processed} of {$total}...",
        ));

        if ($processed >= $total) {
            $this->finalize_sync($sync_id);
            return null;
        }

        return $processed; // Next offset
    }

    /**
     * Process one row into the accumulated sync state (extracted from the old
     * inline loop so it can run across separate background batches).
     */
    private function process_row_into_state($headers, $row, $google_sheet_row, &$state, $kind = 'simple', $parent_sku = '') {
        // Tombstone: a row already marked "deleted" in its Sync Status is skipped
        // and left completely untouched (no processing, no write-back), so a
        // deleted product is not recreated on the next sync. To bring it back,
        // the user clears the "deleted" value in the Sync Status cell.
        $status_idx = array_search('Sync Status', $headers);
        if ($status_idx !== false && isset($row[$status_idx])
            && strtolower(trim((string) $row[$status_idx])) === 'deleted') {
            $state['skipped']++;
            wc_gs_log('WC_GS_Sync: Row ' . $google_sheet_row . ' skipped (Sync Status = deleted)');
            return;
        }

        // One-time action columns: queue the Delete / Force Update cells for
        // clearing whenever they are set, regardless of the row's outcome. This
        // prevents them re-triggering on the next sync (e.g. a stale Delete=yes
        // throwing "product not found").
        $delete_idx = array_search('Delete', $headers);
        if ($delete_idx !== false && isset($row[$delete_idx])
            && in_array(strtolower(trim((string) $row[$delete_idx])), array('yes', 'y', '1', 'true', 'delete'), true)) {
            $state['clear_delete'][] = $google_sheet_row;
        }
        $force_idx = array_search('Force Update', $headers);
        if ($force_idx !== false && isset($row[$force_idx])
            && in_array(strtolower(trim((string) $row[$force_idx])), array('yes', 'y', '1', 'true', 'force'), true)) {
            $state['clear_force_update'][] = $google_sheet_row;
        }

        // Pro gate: variable products (parents + variations) require a Pro
        // license once enforcement is on. No-op while enforcement is off, so
        // this changes nothing for unlicensed builds today.
        if (($kind === 'variable' || $kind === 'variation') && !wc_gs_can('variable_products')) {
            $state['skipped']++;
            $state['sync_results'][$google_sheet_row] = array(
                'status' => 'error',
                'action' => 'skipped',
                'product_id' => 0,
                'error' => __('Variable products require a Pro license.', 'wc-google-sheets-sync'),
            );
            wc_gs_log('WC_GS_Sync: Row ' . $google_sheet_row . ' skipped (variable products need a Pro license)');
            return;
        }

        try {
            // Route by kind: simple products, variable parents, and variations
            // each take a different processor. Variations resolve their parent
            // from $state['parent_ids'] (built earlier this run, parents first).
            if ($kind === 'variable') {
                $result = $this->process_variable_parent_row($headers, $row, $google_sheet_row);
            } elseif ($kind === 'variation') {
                $result = $this->process_variation_row($headers, $row, $google_sheet_row, $parent_sku, $state);
            } else {
                $result = $this->process_product_row($headers, $row, $google_sheet_row);
            }

            // Map this parent's SKU -> product ID so variation rows can find it.
            if ($kind === 'variable' && !empty($result['product_id']) && !empty($result['sku'])) {
                $state['parent_ids'][$result['sku']] = $result['product_id'];
            }

            $state['sync_results'][$google_sheet_row] = array(
                'status' => 'success',
                'action' => $result['action'],
                'product_id' => $result['product_id'],
                'error' => null,
            );

            // Variation outcomes are tallied in their own counters so a variable
            // product's variations aren't conflated with products in the totals.
            $is_variation = ($kind === 'variation');

            if ($result['action'] === 'created') {
                $state[$is_variation ? 'variations_created' : 'created']++;
                $state['created_products'][$google_sheet_row] = $result['product_id'];
                if (isset($result['generated_sku'])) {
                    $state['sku_write_backs'][$google_sheet_row] = $result['generated_sku'];
                }
            } elseif ($result['action'] === 'updated') {
                $state[$is_variation ? 'variations_updated' : 'updated']++;
                if (!empty($result['missing_id'])) {
                    $state['missing_ids'][$google_sheet_row] = $result['product_id'];
                }
                if (isset($result['generated_sku'])) {
                    $state['sku_write_backs'][$google_sheet_row] = $result['generated_sku'];
                }
                if (isset($result['generated_quantity'])) {
                    $state['quantity_write_backs'][$google_sheet_row] = $result['generated_quantity'];
                }
                if (isset($result['gtin_changed']) && $result['gtin_changed']) {
                    $state['gtin_write_backs'][$google_sheet_row] = $result['current_gtin'];
                }
            } elseif ($result['action'] === 'deleted') {
                $state[$is_variation ? 'variations_deleted' : 'deleted']++;
            } else {
                $state[$is_variation ? 'variations_skipped' : 'skipped']++;
            }
        } catch (Exception $e) {
            $state['errors'][] = array(
                'row' => $google_sheet_row,
                'message' => $e->getMessage(),
            );
            // Count a failed product row as skipped (existing behavior); a failed
            // variation row is surfaced only in Errors, not the product totals.
            if ($kind !== 'variation') {
                $state['skipped']++;
            }
            $state['sync_results'][$google_sheet_row] = array(
                'status' => 'error',
                'action' => 'failed',
                'product_id' => null,
                'error' => $e->getMessage(),
            );
            wc_gs_log('WC_GS_Sync: Error processing row ' . $google_sheet_row . ': ' . $e->getMessage());
        }
    }

    /**
     * Finalize a completed sync: write results back to the sheet, stamp the
     * completion time, mark progress complete, and clean up the job data.
     */
    private function finalize_sync($sync_id) {
        $job = get_option('wc_gs_sync_job_' . $sync_id);
        $state = get_option('wc_gs_sync_state_' . $sync_id);

        $writeback_ms = 0;
        $read_ms     = (is_array($state) && isset($state['read_ms'])) ? (int) $state['read_ms'] : 0;
        $dispatch_ms = (is_array($state) && isset($state['dispatch_ms'])) ? (int) $state['dispatch_ms'] : 0;
        $process_ms  = (is_array($state) && isset($state['process_ms'])) ? (int) $state['process_ms'] : 0;

        if (is_array($job) && is_array($state)) {
            $sheet_config = $job['sheet_config'];
            $headers = $job['headers'];

            // Variable products: reconcile and re-sync parents now that all
            // variation rows have been processed.
            $this->reconcile_variable_products($state);

            // Preserve row-number keys by using + instead of array_merge
            $all_write_backs = $state['created_products'] + $state['missing_ids'];

            $clear_delete = isset($state['clear_delete']) ? $state['clear_delete'] : array();
            $clear_force_update = isset($state['clear_force_update']) ? $state['clear_force_update'] : array();

            if (!empty($all_write_backs) || !empty($state['sku_write_backs']) || !empty($state['quantity_write_backs']) || !empty($state['gtin_write_backs']) || !empty($state['sync_results']) || !empty($clear_delete) || !empty($clear_force_update)) {
                $t_wb = microtime(true);
                $this->write_sync_results_back(
                    $sync_id,
                    $sheet_config,
                    $headers,
                    $all_write_backs,
                    $state['sku_write_backs'],
                    $state['quantity_write_backs'],
                    $state['gtin_write_backs'],
                    $state['sync_results'],
                    $clear_delete,
                    $clear_force_update
                );
                $writeback_ms = (int) round((microtime(true) - $t_wb) * 1000);
            }

            // Update last sync time + summary in the sheet config and globally
            $connected_sheets = get_option('wc_gs_sync_connected_sheets', array());
            if (isset($sheet_config['sheet_id']) && isset($connected_sheets[$sheet_config['sheet_id']])) {
                $connected_sheets[$sheet_config['sheet_id']]['last_synced'] = current_time('mysql');
                $connected_sheets[$sheet_config['sheet_id']]['last_result'] = array(
                    'created'      => (int) $state['created'],
                    'updated'      => (int) $state['updated'],
                    'deleted'      => (int) $state['deleted'],
                    'skipped'      => (int) $state['skipped'],
                    'variations'   => (int) ($state['variations_created'] + $state['variations_updated'] + $state['variations_deleted'] + $state['variations_skipped']),
                    'variations_breakdown' => array(
                        'created' => (int) $state['variations_created'],
                        'updated' => (int) $state['variations_updated'],
                        'deleted' => (int) $state['variations_deleted'],
                        'skipped' => (int) $state['variations_skipped'],
                    ),
                    'error_count'  => count($state['errors']),
                    'errors'       => array_slice($state['errors'], 0, 50),
                    'total'        => isset($job['total']) ? (int) $job['total'] : 0,
                    'completed_at' => current_time('mysql'),
                    'timing'       => array(
                        'read_ms'      => $read_ms,
                        'dispatch_ms'  => $dispatch_ms,
                        'process_ms'   => $process_ms,
                        'writeback_ms' => $writeback_ms,
                    ),
                );
                update_option('wc_gs_sync_connected_sheets', $connected_sheets);
            }
            update_option('wc_gs_sync_last_sync_time', current_time('timestamp'));
        }

        // Timing breakdown so the bottleneck is measurable instead of guessed.
        $total_ms = $read_ms + $dispatch_ms + $process_ms + $writeback_ms;

        $timing_summary = sprintf(
            'Done in %.1fs (read %.1fs, queue wait %.1fs, process %.1fs, write-back %.1fs)',
            $total_ms / 1000, $read_ms / 1000, $dispatch_ms / 1000, $process_ms / 1000, $writeback_ms / 1000
        );
        wc_gs_log('WC_GS_Timing: ' . $timing_summary);

        // Log the product/variation breakdown so variation activity is visible.
        if (is_array($state)) {
            $variations_total = (int) ($state['variations_created'] + $state['variations_updated'] + $state['variations_deleted'] + $state['variations_skipped']);
            wc_gs_log(sprintf(
                'WC_GS_Sync: Products — created %d, updated %d, deleted %d, skipped %d. Variations — total %d (created %d, updated %d, deleted %d, skipped %d). Errors %d.',
                (int) $state['created'], (int) $state['updated'], (int) $state['deleted'], (int) $state['skipped'],
                $variations_total,
                (int) $state['variations_created'], (int) $state['variations_updated'], (int) $state['variations_deleted'], (int) $state['variations_skipped'],
                count($state['errors'])
            ));
        }

        $this->update_sync_progress($sync_id, array(
            'status' => 'completed',
            'progress' => 100,
            'variations' => is_array($state) ? (int) ($state['variations_created'] + $state['variations_updated'] + $state['variations_deleted'] + $state['variations_skipped']) : 0,
            'current_step' => 'Sync completed! ' . $timing_summary,
            'completed_at' => current_time('mysql'),
        ));

        delete_option('wc_gs_sync_job_' . $sync_id);
        delete_option('wc_gs_sync_state_' . $sync_id);
    }

    /**
     * After all rows are processed, reconcile each touched variable product:
     *
     * 1. Delete orphan variations — variations that exist in WooCommerce but were
     *    not present in the sheet this run (the sheet is the source of truth).
     *    Safety: only parents that had at least one variation row this run are
     *    reconciled, so syncing only the parent row never wipes its variations.
     * 2. Re-sync the parent (WC_Product_Variable::sync) so its price range and
     *    stock reflect the variations.
     *
     * Increments $state['deleted'] for each removed variation.
     */
    private function reconcile_variable_products(&$state) {
        // Delete orphan variations for parents that had variation rows this run.
        if (!empty($state['parent_seen_variations']) && is_array($state['parent_seen_variations'])) {
            foreach ($state['parent_seen_variations'] as $parent_id => $seen_ids) {
                $parent = wc_get_product($parent_id);
                if (!$parent || $parent->get_type() !== 'variable') {
                    continue;
                }
                $seen = array_map('intval', (array) $seen_ids);
                foreach ($parent->get_children() as $child_id) {
                    if (in_array((int) $child_id, $seen, true)) {
                        continue;
                    }
                    $orphan = wc_get_product($child_id);
                    if ($orphan && $orphan->get_type() === 'variation') {
                        $orphan->delete(true); // force delete (variations have no trash)
                        $state['variations_deleted']++;
                        wc_gs_log('WC_GS_Sync: Deleted orphan variation ' . (int) $child_id . ' of parent ' . (int) $parent_id);
                    }
                }
            }
        }

        // Order each parent's variations to match the sheet. WooCommerce sorts the
        // admin variation list by menu_order ASC then ID DESC, so without an
        // explicit menu_order variations appear newest-first — the reverse of the
        // sheet. parent_seen_variations holds this run's variations in sheet order
        // (including unchanged/skipped ones), so assign menu_order by position.
        // Only write when it actually changes, so re-syncs don't churn unchanged
        // variations.
        if (!empty($state['parent_seen_variations']) && is_array($state['parent_seen_variations'])) {
            foreach ($state['parent_seen_variations'] as $seen_ids) {
                $position = 0;
                foreach (array_map('intval', (array) $seen_ids) as $vid) {
                    if ($vid <= 0) {
                        continue;
                    }
                    if ((int) get_post_field('menu_order', $vid) !== $position) {
                        wp_update_post(array('ID' => $vid, 'menu_order' => $position));
                    }
                    $position++;
                }
            }
        }

        // Re-sync every parent created/updated this run (price range, stock…).
        if (!empty($state['parent_ids']) && class_exists('WC_Product_Variable')) {
            foreach (array_unique(array_map('intval', array_values($state['parent_ids']))) as $pid) {
                WC_Product_Variable::sync($pid);
            }
        }
    }

    /**
     * The empty accumulator used to track results across background batches.
     */
    private function get_default_sync_state() {
        return array(
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            // Variation outcomes are counted separately from products (a variable
            // product's parent counts as a product; its variations count here).
            'variations_created' => 0,
            'variations_updated' => 0,
            'variations_deleted' => 0,
            'variations_skipped' => 0,
            'errors' => array(),
            'created_products' => array(),
            'missing_ids' => array(),
            'sku_write_backs' => array(),
            'gtin_write_backs' => array(),
            'quantity_write_backs' => array(),
            'sync_results' => array(),
            'clear_delete' => array(),       // rows whose Delete cell should be cleared
            'clear_force_update' => array(), // rows whose Force Update cell should be cleared
            'parent_ids' => array(),         // variable parent SKU => product ID (for variations)
            'parent_seen_variations' => array(), // parent ID => [variation IDs seen this run]
            'read_ms' => 0,                  // timing: Google Sheets read
            'dispatch_ms' => 0,              // timing: queue dispatch latency
            'process_ms' => 0,               // timing: row processing (all batches)
        );
    }
    
    /**
     * NEW: Write sync results back to Google Sheets (IDs, status, errors, timestamps)
     */
    private function write_sync_results_back($sync_id, $sheet_config, $headers, $product_ids, $sku_write_backs, $quantity_write_backs, $gtin_write_backs, $sync_results, $clear_delete = array(), $clear_force_update = array()) {
        wc_gs_log('WC_GS_Sync: *** WRITE-BACK FUNCTION CALLED - NEW CODE RUNNING ***');
        
        if (empty($product_ids) && empty($sku_write_backs) && empty($quantity_write_backs) && empty($sync_results) && empty($clear_delete) && empty($clear_force_update)) {
			wc_gs_log('WC_GS_Sync: No data to write back');
			return;
		}
        
        try {
            $this->update_sync_progress($sync_id, array(
                'current_step' => 'Writing sync results back to sheet...'
            ));
            
            wc_gs_log('WC_GS_Sync: Starting write-back for ' . count($sync_results) . ' rows');
            wc_gs_log('WC_GS_Sync: Product IDs: ' . print_r($product_ids, true));
			wc_gs_log('WC_GS_Sync: SKU write-backs: ' . print_r($sku_write_backs, true));
            wc_gs_log('WC_GS_Sync: Sync results: ' . print_r($sync_results, true));
            
            $google_api = $this->get_google_api();
            
            if (!$google_api->is_authenticated()) {
                wc_gs_log('WC_GS_Sync: Cannot write back - not authenticated');
                return;
            }
            
            // Find column indices for all the fields we want to update
            $columns = $this->find_column_indices($headers);
            
            if (empty($columns)) {
                wc_gs_log('WC_GS_Sync: No valid columns found for write-back');
                return;
            }
            
            // Prepare batch update data
            $updates = array();
            $current_time = current_time('n/j/Y G:i:s'); // Match your existing format
            
            // Process each row that was synced
            foreach ($sync_results as $row_number => $result) {
                // ABSOLUTE SAFETY CHECK: Never write to header row (row 1) or invalid rows
                if ($row_number < 2) {
                    wc_gs_log('WC_GS_Sync: SKIPPING - Invalid row number: ' . $row_number . ' (header is row 1, data starts at row 2)');
                    continue;
                }

                // Unchanged rows: nothing to write back (the sheet already has the
                // ID, "synced" status and no error), so don't touch the sheet for
                // them. A fully unchanged sync makes no write-back API call at all.
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    continue;
                }

                // Write Product ID (if available and successful - but NOT for deleted products)
				if (isset($columns['id']) && isset($product_ids[$row_number]) && $result['action'] !== 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['id']['letter'] . $row_number,
						'values' => array(array($product_ids[$row_number]))
					);
				}

				// Clear Product ID for deleted products
				if (isset($columns['id']) && $result['action'] === 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['id']['letter'] . $row_number,
						'values' => array(array('')) // Clear the ID
					);
				}
				
				// NEW: Write SKU (if available and successful - but NOT for deleted products)
				if (isset($columns['sku']) && isset($sku_write_backs[$row_number]) && $result['action'] !== 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['sku']['letter'] . $row_number,
						'values' => array(array($sku_write_backs[$row_number]))
					);
					wc_gs_log('WC_GS_Sync: Added SKU write-back for row ' . $row_number . ': ' . $sku_write_backs[$row_number]);
				}

				// Clear SKU for deleted products
				if (isset($columns['sku']) && $result['action'] === 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['sku']['letter'] . $row_number,
						'values' => array(array(''))
					);
				}
				
				// NEW: Write Quantity (if available and successful - but NOT for deleted products)
				if (isset($columns['quantity']) && isset($quantity_write_backs[$row_number]) && $result['action'] !== 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['quantity']['letter'] . $row_number,
						'values' => array(array($quantity_write_backs[$row_number]))
					);
					wc_gs_log('WC_GS_Sync: Added Quantity write-back for row ' . $row_number . ': ' . $quantity_write_backs[$row_number]);
				}

				// Clear Quantity for deleted products
				if (isset($columns['quantity']) && $result['action'] === 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['quantity']['letter'] . $row_number,
						'values' => array(array(''))
					);
				}
				
				// NEW: Write GTIN (including empty values to clear sheet)
				if (isset($columns['gtin']) && isset($gtin_write_backs[$row_number]) && $result['action'] !== 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['gtin']['letter'] . $row_number,
						'values' => array(array($gtin_write_backs[$row_number])) // Could be empty string
					);
					wc_gs_log('WC_GS_Sync: Added GTIN write-back for row ' . $row_number . ': "' . $gtin_write_backs[$row_number] . '"');
				}

				// Clear GTIN for deleted products
				if (isset($columns['gtin']) && $result['action'] === 'deleted') {
					$updates[] = array(
						'range' => $sheet_config['sheet_tab'] . '!' . $columns['gtin']['letter'] . $row_number,
						'values' => array(array(''))
					);
				}
                
                // Write Sync Status
                if (isset($columns['sync_status'])) {
                    if ($result['status'] === 'success') {
						$status = ($result['action'] === 'deleted') ? 'deleted' : 'synced';
					} else {
						$status = 'error';
					}
                    $updates[] = array(
                        'range' => $sheet_config['sheet_tab'] . '!' . $columns['sync_status']['letter'] . $row_number,
                        'values' => array(array($status))
                    );
                }
                
                // Write Sync Error
                if (isset($columns['sync_error'])) {
                    $error_msg = ($result['status'] === 'error') ? $result['error'] : '';
                    $updates[] = array(
                        'range' => $sheet_config['sheet_tab'] . '!' . $columns['sync_error']['letter'] . $row_number,
                        'values' => array(array($error_msg))
                    );
                }
                
                // Write Last Synced timestamp (for successful syncs)
                if (isset($columns['last_synced']) && $result['status'] === 'success') {
                    $updates[] = array(
                        'range' => $sheet_config['sheet_tab'] . '!' . $columns['last_synced']['letter'] . $row_number,
                        'values' => array(array($current_time))
                    );
                }
                
                wc_gs_log('WC_GS_Sync: Prepared updates for row ' . $row_number . ' - Status: ' . $result['status'] . ', Product ID: ' . ($product_ids[$row_number] ?? 'none'));
            }

            // Clear one-time action columns (Delete / Force Update) so they don't
            // re-trigger on the next sync (e.g. a stale Delete=yes throwing errors).
            if (isset($columns['delete'])) {
                foreach (array_unique($clear_delete) as $row_number) {
                    if ($row_number < 2) { continue; }
                    $updates[] = array(
                        'range' => $sheet_config['sheet_tab'] . '!' . $columns['delete']['letter'] . $row_number,
                        'values' => array(array('')),
                    );
                }
            }
            if (isset($columns['force_update'])) {
                foreach (array_unique($clear_force_update) as $row_number) {
                    if ($row_number < 2) { continue; }
                    $updates[] = array(
                        'range' => $sheet_config['sheet_tab'] . '!' . $columns['force_update']['letter'] . $row_number,
                        'values' => array(array('')),
                    );
                }
            }

            if (empty($updates)) {
                wc_gs_log('WC_GS_Sync: No valid updates after filtering - all rows were invalid');
                return;
            }
            
            wc_gs_log('WC_GS_Sync: Prepared ' . count($updates) . ' total updates for batch write');

            // Write back in a few LARGE batchUpdate requests. The Google Sheets
            // values.batchUpdate endpoint accepts many ranges in a single request,
            // so there is no need to split into one-request-per-handful and sleep
            // between them — that turned a few-second job into hundreds of throttled
            // calls that ran past Action Scheduler's per-action time limit and got
            // killed mid-write-back. We send up to ~500 ranges per request with NO
            // inter-request delay on success; a pause happens only when retrying an
            // actual failure (e.g. a 429 rate-limit error).
            $chunk_ranges = max(1, (int) apply_filters('wc_gs_writeback_chunk_size', 500));
            $delay_ms = max(0, (int) $this->get_setting('rate_limit_delay', 1000));
            $max_retries = max(1, (int) $this->get_setting('max_retries', 3));

            $chunks = array_chunk($updates, $chunk_ranges);
            $chunk_count = count($chunks);
            $total_updates = count($updates);
            $written = 0;
            $had_error = false;

            foreach ($chunks as $chunk_index => $chunk) {
                $write_result = null;
                for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
                    $write_result = $google_api->batch_update_sheet($sheet_config['sheet_id'], $chunk);
                    if (!is_wp_error($write_result)) {
                        break;
                    }
                    wc_gs_log('WC_GS_Sync: Write-back batch ' . ($chunk_index + 1) . ' of ' . $chunk_count . ' attempt ' . $attempt . ' of ' . $max_retries . ' failed: ' . $write_result->get_error_message());
                    // Back off only on a real failure (rate limit / transient error).
                    if ($attempt < $max_retries && $delay_ms > 0) {
                        usleep($delay_ms * 1000);
                    }
                }

                if (is_wp_error($write_result)) {
                    $had_error = true;
                    wc_gs_log('WC_GS_Sync: Failed to write back batch ' . ($chunk_index + 1) . ': ' . $write_result->get_error_message());
                } else {
                    $written += count($chunk);
                }

                // Keep the panel visibly alive during write-back. There is no
                // per-request sleep on success, so this is just a status refresh.
                $this->update_sync_progress($sync_id, array(
                    'current_step' => sprintf(
                        'Writing results back to sheet… (%d of %d cells)',
                        $written,
                        $total_updates
                    ),
                ));
            }

            if ($had_error) {
                wc_gs_log('WC_GS_Sync: Write-back completed with errors; ' . $written . ' of ' . count($updates) . ' updates written');
            } else {
                wc_gs_log('WC_GS_Sync: Successfully wrote back ' . $written . ' updates including SKUs');

                // Update progress to show write-back completion
                $this->update_sync_progress($sync_id, array(
                    'current_step' => 'Sync completed! All data written back to sheet.'
                ));
            }
            
        } catch (Exception $e) {
            wc_gs_log('WC_GS_Sync: Exception during write-back: ' . $e->getMessage());
        }
    }
    
    /**
     * Get data from Google Sheet
     */
    private function get_sheet_data($sheet_config) {
        $google_api = $this->get_google_api();
        
        if (!$google_api->is_authenticated()) {
            return new WP_Error('auth_error', 'Not authenticated with Google');
        }
        
        // Read the whole tab with an OPEN-ENDED row range so large catalogs aren't
        // silently truncated. The old fixed "A1:ZZ1000" capped the import at 999
        // data rows (row 1 = header), so sheets with more products lost the rest.
        // The Sheets API returns only rows that actually contain data, so an
        // open-ended range doesn't pull empty rows. Column span filterable for
        // unusually wide sheets.
        $read_columns = (string) apply_filters('wc_gs_sheet_read_columns', 'ZZ');
        $range = $sheet_config['sheet_tab'] . '!A:' . $read_columns;

        // Retry transient API failures using the configured settings.
        $max_retries = max(1, (int) $this->get_setting('max_retries', 3));
        $delay_ms = max(0, (int) $this->get_setting('rate_limit_delay', 1000));

        $result = null;
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $result = $google_api->get_sheet_data($sheet_config['sheet_id'], $range);
            if (!is_wp_error($result)) {
                break;
            }
            wc_gs_log('WC_GS_Sync: Sheet read attempt ' . $attempt . ' of ' . $max_retries . ' failed: ' . $result->get_error_message());
            if ($attempt < $max_retries && $delay_ms > 0) {
                usleep($delay_ms * 1000);
            }
        }

        if (is_wp_error($result)) {
            return $this->friendly_sheet_access_error($result);
        }

        return $result;
    }

    /**
     * Turn a raw Google Sheets API access failure into actionable guidance.
     *
     * Since the plugin uses the per-file `drive.file` scope, the app can only
     * reach spreadsheets the user picked through the Google Picker. A sheet that
     * was connected before that change (or whose access was revoked) comes back
     * as a 404 "Requested entity was not found" / 403. Rewrite those into a
     * message that tells the user to reconnect the sheet; pass anything else
     * through unchanged.
     */
    private function friendly_sheet_access_error($error) {
        if (!is_wp_error($error)) {
            return $error;
        }

        $message = $error->get_error_message();
        if (stripos($message, 'not found') !== false
            || stripos($message, 'notFound') !== false
            || stripos($message, 'NOT_FOUND') !== false
            || stripos($message, 'permission') !== false
            || stripos($message, 'insufficient') !== false
            || stripos($message, '403') !== false) {
            return new WP_Error(
                'sheet_access',
                __('This Google Sheet is no longer accessible to the plugin. Remove it from the dashboard and add it again with "Connect New Sheet" so you can re-select it in the Google picker (this grants the plugin access to that file).', 'wc-google-sheets-sync')
            );
        }

        return $error;
    }
    
    /**
     * Process a single product row
     * UPDATED: Track when ID is missing for write-back
     */
    private function process_product_row($headers, $row, $row_number) {
        wc_gs_log('WC_GS_Sync: === PROCESSING ROW ' . $row_number . ' ===');
        
        $data_builder = new WC_GS_Product_Data_Builder();
        $product_data = $data_builder->build_product_data($row, $headers);
        
        wc_gs_log('WC_GS_Sync: Built product data for row ' . $row_number . ': ' . print_r($product_data, true));
		
		// NEW: Check if SKU was originally empty
		$original_sku = '';
		$sku_index = array_search('SKU', $headers);
		if ($sku_index !== false && isset($row[$sku_index])) {
			$original_sku = trim($row[$sku_index]);
		}
		$had_empty_sku = empty($original_sku);
		
		// NEW: Get original GTIN from sheet
		$original_gtin = '';
		$gtin_columns = ['GTIN, UPC, EAN, or ISBN', 'GTIN', 'UPC', 'EAN', 'ISBN'];
		foreach ($gtin_columns as $column_name) {
			$gtin_index = array_search($column_name, $headers);
			if ($gtin_index !== false && isset($row[$gtin_index])) {
				$original_gtin = trim($row[$gtin_index]);
				if ($original_gtin) break;
			}
		}

		// NEW: Get original Quantity from sheet (used by the quantity write-back logic below)
		$original_quantity = '';
		$quantity_index = array_search('Quantity', $headers);
		if ($quantity_index !== false && isset($row[$quantity_index])) {
			$original_quantity = trim(strval($row[$quantity_index]));
		}

		// NEW: Force Update flag — when set, the sheet always wins over WooCommerce,
		// bypassing the "recently modified in WooCommerce wins" conflict resolution.
		$force_update = false;
		$force_index = array_search('Force Update', $headers);
		if ($force_index !== false && isset($row[$force_index])) {
			$force_update = in_array(strtolower(trim(strval($row[$force_index]))), ['yes', 'y', '1', 'true', 'force']);
		}

		wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Original SKU: ' . ($original_sku ?: 'empty') . ', Generated SKU: ' . $product_data['sku']);
		
		// NEW: Check if this is a delete request
		$should_delete = $this->should_delete_product($product_data);
    
		if ($should_delete) {
			return $this->handle_product_deletion($product_data, $row_number);
		}
        
        $validation = $data_builder->validate_product_data($product_data);

        if (!$validation['is_valid']) {
            throw new Exception('Validation failed: ' . implode(', ', $validation['errors']));
        }
        
        // Check if product exists (by ID, SKU, or Name)
        $existing_product = null;
        $had_empty_id = empty($product_data['id']); // Track if ID was originally empty
        $match_method = 'none';
        
        wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Original ID: ' . ($product_data['id'] ?? 'empty') . ', SKU: ' . ($product_data['sku'] ?? 'empty') . ', Name: ' . ($product_data['name'] ?? 'empty'));
        
        // First check by ID
        if (!empty($product_data['id'])) {
            $existing_product = wc_get_product($product_data['id']);
            $match_method = 'ID';
            wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Checking by ID: ' . $product_data['id'] . ' -> ' . ($existing_product ? 'Found product ' . $existing_product->get_id() : 'Not found'));
        } 
        // Then check by SKU
        elseif (!empty($product_data['sku'])) {
            $product_id = wc_get_product_id_by_sku($product_data['sku']);
            if ($product_id) {
                $existing_product = wc_get_product($product_id);
                $match_method = 'SKU';
                wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Checking by SKU: ' . $product_data['sku'] . ' -> Found product ' . $product_id);
            } else {
                wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Checking by SKU: ' . $product_data['sku'] . ' -> Not found');
            }
        }
        // Finally check by Name to prevent duplicates
        elseif (!empty($product_data['name'])) {
            $existing_product = $this->find_product_by_name($product_data['name']);
            $match_method = 'Name';
            wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Checking by Name: ' . $product_data['name'] . ' -> ' . ($existing_product ? 'Found product ' . $existing_product->get_id() : 'Not found'));
        }
        
        if ($existing_product && $existing_product->get_id()) {
			// Update existing product
			$found_product_id = $existing_product->get_id();
			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - UPDATING existing product ' . $found_product_id . ' (matched by ' . $match_method . ')');
			
			// CRITICAL FIX: Capture current WooCommerce values BEFORE updating the product
			$current_sku_before_update = $existing_product->get_sku();
			$current_gtin_before_update = $existing_product->get_meta('_global_unique_id');
			$current_quantity_before_update = $existing_product->get_stock_quantity(); // NEW: Add this line
			
			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - BEFORE UPDATE - WC SKU: "' . $current_sku_before_update . '", WC GTIN: "' . $current_gtin_before_update . '"');
			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - SHEET VALUES - SKU: "' . $original_sku . '", GTIN: "' . $original_gtin . '"');
			
			// SIMPLIFIED BIDIRECTIONAL LOGIC - Replace the complex logic in process_product_row()

			// SMART FIX: Only preserve WooCommerce values when they were recently changed
			$will_write_sku_back = false;
			$will_write_gtin_back = false;
			$will_write_quantity_back = false; // NEW: Add this line

			// Get product's last modified time
			$product_modified = get_post_modified_time('U', false, $existing_product->get_id());
			$sheet_last_synced = get_option('wc_gs_sync_last_sync_time', 0);

			// Check if product was modified AFTER last sync (indicates manual WooCommerce changes)
			$product_recently_modified = ($product_modified > $sheet_last_synced);

			// Force Update overrides conflict resolution: treat as if WooCommerce was NOT
			// recently modified so the sheet values always win.
			if ($force_update) {
				$product_recently_modified = false;
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Force Update enabled, sheet values will overwrite WooCommerce');
			}

			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Product modified: ' . date('Y-m-d H:i:s', $product_modified) . ', Last sync: ' . date('Y-m-d H:i:s', $sheet_last_synced) . ', Recently modified: ' . ($product_recently_modified ? 'YES' : 'NO'));

			// SKU: the sheet is the source of truth, so the sheet value always wins
			// — we no longer overwrite the sheet with a SKU changed in WooCommerce.
			// (The auto-generated-SKU case is just the sheet/generated value too.)
			if ($had_empty_sku && !empty($product_data['sku'])) {
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - SKU auto-generated: using "' . $product_data['sku'] . '"');
			}
			// product_data['sku'] keeps the sheet value; no Woo->sheet SKU write-back.

			// GTIN: sheet is the source of truth — the sheet value always wins, no
			// Woo->sheet GTIN write-back. product_data['meta_data'] keeps the sheet value.

			// NEW: Quantity Logic - SIMPLIFIED (same pattern)
			// Convert to string for comparison (handle null/empty cases)
			$current_quantity_str = ($current_quantity_before_update !== null) ? strval($current_quantity_before_update) : '';
			$original_quantity_str = ($original_quantity !== '') ? strval($original_quantity) : '';

			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Current WC Quantity: "' . $current_quantity_str . '", Sheet Quantity: "' . $original_quantity_str . '"');

			if ($current_quantity_str !== $original_quantity_str) {
				// Values differ - decide who wins
				if ($product_recently_modified) {
					// Product was recently modified in WooCommerce - preserve WooCommerce value
					if (isset($product_data['stock_quantity'])) {
						$product_data['stock_quantity'] = $current_quantity_before_update;
					}
					$will_write_quantity_back = true;
					wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Quantity: Product recently modified, preserving WooCommerce value "' . $current_quantity_str . '"');
				} else {
					// Product NOT recently modified - use sheet value
					wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Quantity: Product not recently modified, using sheet value "' . $original_quantity_str . '"');
					// Keep product_data['stock_quantity'] as-is (sheet value)
				}
			} else {
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Quantity: Values match, no action needed');
			}

			// Debug: Log final decision
			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - FINAL DECISION: SKU write-back=' . ($will_write_sku_back ? 'YES' : 'NO') . ', GTIN write-back=' . ($will_write_gtin_back ? 'YES' : 'NO') . ', Quantity write-back=' . ($will_write_quantity_back ? 'YES' : 'NO'));
			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - FINAL SKU to use: "' . ($product_data['sku'] ?? 'empty') . '"');

			// Find GTIN value that will be used
			$final_gtin = '';
			if (!empty($product_data['meta_data'])) {
				foreach ($product_data['meta_data'] as $meta) {
					if ($meta['key'] === '_global_unique_id') {
						$final_gtin = $meta['value'];
						break;
					}
				}
			}
			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - FINAL GTIN to use: "' . $final_gtin . '"');
			
			// Change detection: skip the update when the data we would apply matches
			// what was applied last time (unless Force Update is set, or the row
			// still needs its ID written back). Unchanged products are then reported
			// as skipped instead of updated, and are not needlessly re-saved.
			$new_hash = $this->compute_product_hash($product_data);
			$old_hash = $existing_product->get_meta('_wc_gs_data_hash');

			// Never skip when the matched product is a different type than the sheet
			// now says (e.g. it is variable but the row says simple) — the type
			// change isn't part of the data hash, so it must be detected here.
			$type_matches = ($existing_product->get_type() === 'simple');

			if (!$force_update && !$had_empty_id && $type_matches && $old_hash !== '' && $old_hash === $new_hash) {
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - no changes, skipping update');
				// Unchanged, so set_product_attributes won't run — keep the attribute
				// term order aligned to the sheet anyway (guarded; no product save
				// and a no-op once the order has been applied).
				$this->ensure_attribute_term_order($product_data['attributes'] ?? array());
				return array(
					'action'       => 'skipped',
					'product_id'   => $found_product_id,
					'missing_id'   => false,
					'row_number'   => $row_number,
					'match_method' => $match_method,
				);
			}

			// If the matched product is a different type (e.g. it was variable and
			// the sheet now says simple), convert it to a simple product first —
			// removing any variations — so the update applies as a simple product.
			if (!$type_matches) {
				$existing_product = $this->convert_product_to_simple($existing_product);
			}

			// Now update the product with sheet data
			$result = $this->update_product($existing_product, $product_data);

			// Persist the data hash so the next unchanged sync can skip this row
			update_post_meta($result['product_id'], '_wc_gs_data_hash', $new_hash);

			// Set basic result data
			$result['missing_id'] = $had_empty_id;
			$result['row_number'] = $row_number;
			$result['match_method'] = $match_method;
			
			// Track what needs to be written back to sheet
			if ($will_write_sku_back) {
				$result['generated_sku'] = $current_sku_before_update;
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Will write SKU back to sheet: "' . $current_sku_before_update . '"');
			}
			
			// NEW: Track quantity write-back
			if ($will_write_quantity_back) {
				$result['generated_quantity'] = $current_quantity_before_update;
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Will write Quantity back to sheet: "' . $current_quantity_before_update . '"');
			}

			if ($will_write_gtin_back) {
				$result['gtin_changed'] = true;
				$result['current_gtin'] = $current_gtin_before_update;
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Will write GTIN back to sheet: "' . $current_gtin_before_update . '"');
			}
			
			wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Update result: action=' . $result['action'] . ', product_id=' . $result['product_id'] . ', missing_id=' . ($result['missing_id'] ? 'true' : 'false'));
			
			return $result;
        } else {
            // Create new product
            wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - CREATING new product (no existing product found)');
            
            $result = $this->create_product($product_data);
            $result['row_number'] = $row_number; // TRACK THE ROW NUMBER
            $result['match_method'] = 'new';

            // Store the data hash so the next unchanged sync can skip this product
            if (!empty($result['product_id'])) {
                update_post_meta($result['product_id'], '_wc_gs_data_hash', $this->compute_product_hash($product_data));
            }
            
            wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - Create result: action=' . $result['action'] . ', product_id=' . $result['product_id']);
			
			// NEW: Track SKU generation for new products
			if ($had_empty_sku && !empty($product_data['sku'])) {
				$result['generated_sku'] = $product_data['sku'];
				wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - SKU was auto-generated for new product: ' . $product_data['sku']);
			}
            
            return $result;
        }
    }

    /**
     * Process a `Type = variable` row: create or update the parent variable
     * product (product-level fields + the variation attributes), and return a
     * result that includes the parent's final SKU so variation rows can link to
     * it via $state['parent_ids']. Variations themselves are handled separately
     * (Phase A4). See docs/VARIABLE_PRODUCTS_PLAN.md.
     */
    private function process_variable_parent_row($headers, $row, $row_number) {
        $data_builder = new WC_GS_Product_Data_Builder();
        $product_data = $data_builder->build_product_data($row, $headers);

        // Delete handling is shared with simple products.
        if ($this->should_delete_product($product_data)) {
            return $this->handle_product_deletion($product_data, $row_number);
        }

        $validation = $data_builder->validate_product_data($product_data);
        if (!$validation['is_valid']) {
            throw new Exception('Validation failed: ' . implode(', ', $validation['errors']));
        }

        // Track whether the SKU was originally blank (so a generated one is
        // written back to the sheet — variations reference the parent by SKU).
        $sku_index = array_search('SKU', $headers);
        $had_empty_sku = !($sku_index !== false && isset($row[$sku_index]) && trim((string) $row[$sku_index]) !== '');

        // Force Update bypasses change detection.
        $force_update = false;
        $force_index = array_search('Force Update', $headers);
        if ($force_index !== false && isset($row[$force_index])) {
            $force_update = in_array(strtolower(trim((string) $row[$force_index])), array('yes', 'y', '1', 'true', 'force'), true);
        }

        // Match an existing product by ID, then SKU, then Name.
        $existing_product = null;
        $match_method = 'none';
        $had_empty_id = empty($product_data['id']);

        if (!empty($product_data['id'])) {
            $existing_product = wc_get_product($product_data['id']);
            $match_method = 'ID';
        } elseif (!empty($product_data['sku'])) {
            $pid = wc_get_product_id_by_sku($product_data['sku']);
            if ($pid) {
                $existing_product = wc_get_product($pid);
                $match_method = 'SKU';
            }
        } elseif (!empty($product_data['name'])) {
            $existing_product = $this->find_product_by_name($product_data['name']);
            $match_method = 'Name';
        }

        if ($existing_product && $existing_product->get_id()) {
            $found_product_id = $existing_product->get_id();

            // Change detection: skip an unchanged, already-variable product.
            $new_hash = $this->compute_product_hash($product_data);
            $old_hash = $existing_product->get_meta('_wc_gs_data_hash');
            if (!$force_update && !$had_empty_id && $old_hash !== '' && $old_hash === $new_hash
                && $existing_product->get_type() === 'variable') {
                // Unchanged, so set_product_attributes won't run — but still keep
                // the attribute term order in sync with the sheet so the front-end
                // variation dropdown order can be corrected without forcing a full
                // update of every product. Guarded so it only acts when the order
                // actually changed.
                $this->ensure_attribute_term_order($product_data['attributes'] ?? array());
                return array(
                    'action'       => 'skipped',
                    'product_id'   => $found_product_id,
                    'sku'          => $existing_product->get_sku(),
                    'missing_id'   => false,
                    'row_number'   => $row_number,
                    'match_method' => $match_method,
                );
            }

            $result = $this->create_or_update_variable_parent($product_data, $existing_product);
            if (!empty($result['product_id'])) {
                update_post_meta($result['product_id'], '_wc_gs_data_hash', $new_hash);
            }
            $result['missing_id'] = $had_empty_id;
        } else {
            $result = $this->create_or_update_variable_parent($product_data, null);
            if (!empty($result['product_id'])) {
                update_post_meta($result['product_id'], '_wc_gs_data_hash', $this->compute_product_hash($product_data));
            }
            $match_method = 'new';
        }

        $result['row_number'] = $row_number;
        $result['match_method'] = $match_method;

        // Resolve the parent's final SKU (for the parent map + SKU write-back).
        $saved = $result['product_id'] ? wc_get_product($result['product_id']) : null;
        $result['sku'] = $saved ? $saved->get_sku() : (isset($product_data['sku']) ? $product_data['sku'] : '');
        if ($had_empty_sku && !empty($result['sku'])) {
            $result['generated_sku'] = $result['sku'];
        }

        return $result;
    }

    /**
     * Create or update a WC_Product_Variable parent from sheet data: product-level
     * fields (reusing the shared apply logic) plus the variation attributes (the
     * pipe-separated values on the parent row, marked "used for variations"). An
     * existing simple product matched here is converted to a variable product.
     * Does not create the variations — that is Phase A4.
     */
    private function create_or_update_variable_parent($product_data, $existing_product) {
        if ($existing_product && $existing_product->get_id()) {
            $product_id = $existing_product->get_id();
            // Convert a non-variable product (e.g. simple) to variable.
            if ($existing_product->get_type() !== 'variable') {
                wp_set_object_terms($product_id, 'variable', 'product_type');
            }
            $product = new WC_Product_Variable($product_id);
            $action = 'updated';
        } else {
            $product = new WC_Product_Variable();
            $action = 'created';
        }

        $product->set_name($product_data['name']);
        $this->apply_product_slug($product, $product_data);

        if (!empty($product_data['sku'])) {
            $product->set_sku($product_data['sku']);
        }
        if (!empty($product_data['description'])) {
            $product->set_description($product_data['description']);
        }
        if (!empty($product_data['short_description'])) {
            $product->set_short_description($product_data['short_description']);
        }
        if (!empty($product_data['meta_data'])) {
            foreach ($product_data['meta_data'] as $meta) {
                $product->update_meta_data($meta['key'], $meta['value']);
            }
        }
        // Parent-level stock status is allowed (variations usually manage their own).
        if (!empty($product_data['stock_status'])) {
            $product->set_stock_status($product_data['stock_status']);
        }

        // Shared product-level fields (featured, dimensions, tax status, sold
        // individually, upsells/cross-sells, purchase note, position, reviews…).
        $this->apply_additional_product_fields($product, $product_data);

        $status = !empty($product_data['status']) ? $product_data['status'] : 'publish';
        $product->set_status($status);

        $product_id = $product->save();

        // Taxonomies / media / meta (reuse the simple-product helpers).
        if (!empty($product_data['categories'])) {
            $this->set_product_categories($product_id, $product_data['categories']);
        }
        if (!empty($product_data['tags'])) {
            $this->set_product_tags($product_id, $product_data['tags']);
        }
        if (!empty($product_data['images'])) {
            $this->handle_product_images($product_id, $product_data);
        }
        if (!empty($product_data['meta'])) {
            $this->set_product_meta($product_id, $product_data['meta']);
        }

        // Variation attributes: the parent lists all values; mark them
        // "used for variations" so WooCommerce can build variations from them.
        if (!empty($product_data['attributes'])) {
            $this->set_product_attributes($product_id, $product_data['attributes'], true);
        }

        $this->apply_post_visibility($product_id, $product_data);

        return array('action' => $action, 'product_id' => $product_id);
    }

    /**
     * Convert a non-simple product (e.g. a variable product whose sheet row now
     * says Type = simple) into a simple product: remove its variations (a simple
     * product has none), switch the product_type taxonomy, and return a fresh
     * WC_Product_Simple for the same ID so the caller can apply the row's data.
     */
    private function convert_product_to_simple($product) {
        $product_id = $product->get_id();

        // Variable products carry child variations that must not linger.
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if ($child) {
                    $child->delete(true);
                }
            }
        }

        wp_set_object_terms($product_id, 'simple', 'product_type');
        wc_gs_log('WC_GS_Sync: Converted product ' . (int) $product_id . ' to simple');

        return new WC_Product_Simple($product_id);
    }

    /**
     * Process a `Type = variation` row: create or update one WC_Product_Variation
     * under its parent (resolved by the Parent SKU via $state['parent_ids'], or an
     * existing variable product with that SKU). Applies the variation's attribute
     * values plus its own price, stock, SKU, weight/dimensions, shipping class,
     * tax class, virtual/downloadable, image, GTIN, description (Short Description)
     * and enabled/disabled (Status). See docs/VARIABLE_PRODUCTS_PLAN.md.
     */
    private function process_variation_row($headers, $row, $row_number, $parent_sku, &$state) {
        $parent_sku = trim((string) $parent_sku);
        if ($parent_sku === '') {
            throw new Exception('Variation row has no Parent SKU.');
        }

        // Resolve the parent: prefer this run's map, then an existing variable
        // product with that SKU (e.g. the parent was synced on an earlier run).
        $parent_id = isset($state['parent_ids'][$parent_sku]) ? (int) $state['parent_ids'][$parent_sku] : 0;
        if (!$parent_id) {
            $maybe = wc_get_product_id_by_sku($parent_sku);
            if ($maybe) {
                $maybe_product = wc_get_product($maybe);
                if ($maybe_product && $maybe_product->get_type() === 'variable') {
                    $parent_id = (int) $maybe;
                    $state['parent_ids'][$parent_sku] = $parent_id;
                }
            }
        }
        if (!$parent_id) {
            throw new Exception(sprintf('Parent SKU "%s" not found for this variation.', $parent_sku));
        }

        $data_builder = new WC_GS_Product_Data_Builder();
        $product_data = $data_builder->build_product_data($row, $headers);

        // A variation row with Delete = yes removes just that variation.
        if ($this->should_delete_product($product_data)) {
            return $this->handle_product_deletion($product_data, $row_number);
        }

        // Attribute map (name => single value) from the parsed attribute columns.
        $attr_map = array();
        if (!empty($product_data['attributes'])) {
            foreach ($product_data['attributes'] as $attr) {
                $name = isset($attr['name']) ? $attr['name'] : '';
                $values = isset($attr['values']) ? (array) $attr['values'] : array();
                if ($name !== '' && !empty($values)) {
                    $attr_map[$name] = $values[0]; // a variation carries one value per attribute
                }
            }
        }

        // Match an existing variation (by ID, SKU, then attribute combination).
        $variation = $this->find_matching_variation($parent_id, $product_data, $attr_map);
        $had_empty_id = empty($product_data['id']);
        $action = $variation ? 'updated' : 'created';

        // Force Update bypasses change detection.
        $force_update = false;
        $force_index = array_search('Force Update', $headers);
        if ($force_index !== false && isset($row[$force_index])) {
            $force_update = in_array(strtolower(trim((string) $row[$force_index])), array('yes', 'y', '1', 'true', 'force'), true);
        }

        // Whether the row's SKU cell was blank (so a generated SKU is written back).
        $sku_index = array_search('SKU', $headers);
        $had_empty_sku = !($sku_index !== false && isset($row[$sku_index]) && trim((string) $row[$sku_index]) !== '');

        // New variation with a blank SKU: generate "<parent SKU>-NN" (first unused
        // number) rather than leaving it blank, so future syncs match it by SKU
        // instead of by attribute combination. Only for genuinely NEW variations —
        // an existing one (matched by ID/SKU/attributes above) keeps its own SKU.
        // The value is written back to the sheet (via $had_empty_sku below), so it
        // is pinned from then on.
        if (!$variation && $had_empty_sku && empty($product_data['sku'])) {
            $parent_product = wc_get_product($parent_id);
            $base = $parent_product ? trim((string) $parent_product->get_sku()) : trim((string) $parent_sku);
            if ($base !== '') {
                $n = 1;
                do {
                    $candidate = $base . '-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
                    $n++;
                } while (wc_get_product_id_by_sku($candidate));
                $product_data['sku'] = $candidate;
                wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - generated variation SKU "' . $candidate . '" from parent "' . $base . '"');
            }
        }

        // Bidirectional write-back for SKU / GTIN / Quantity, mirroring simple
        // products: if the variation was edited in WooCommerce after the last sync
        // (e.g. stock sold), WooCommerce wins for these three and the value is
        // written back to the variation's row instead of being overwritten.
        $will_write_sku_back = false;
        $will_write_gtin_back = false;
        $will_write_quantity_back = false;
        $current_sku_before_update = '';
        $current_gtin_before_update = '';
        $current_quantity_before_update = null;

        if ($variation) {
            // Original sheet values for this row.
            $original_sku = ($sku_index !== false && isset($row[$sku_index])) ? trim((string) $row[$sku_index]) : '';
            $original_gtin = '';
            foreach (array('GTIN, UPC, EAN, or ISBN', 'GTIN', 'UPC', 'EAN', 'ISBN') as $gtin_col) {
                $gi = array_search($gtin_col, $headers);
                if ($gi !== false && isset($row[$gi])) {
                    $original_gtin = trim((string) $row[$gi]);
                    if ($original_gtin !== '') {
                        break;
                    }
                }
            }
            $original_quantity = '';
            $qi = array_search('Quantity', $headers);
            if ($qi !== false && isset($row[$qi])) {
                $original_quantity = trim((string) $row[$qi]);
            }

            // Current WooCommerce values before we apply the sheet.
            $current_sku_before_update = $variation->get_sku();
            $current_gtin_before_update = $variation->get_meta('_global_unique_id');
            $current_quantity_before_update = $variation->get_stock_quantity();

            $variation_modified = get_post_modified_time('U', false, $variation->get_id());
            $sheet_last_synced = (int) get_option('wc_gs_sync_last_sync_time', 0);
            $recently_modified = ($variation_modified > $sheet_last_synced) && !$force_update;

            // SKU and GTIN: the sheet is the source of truth, so the sheet value
            // always wins — we no longer overwrite the sheet with a value changed
            // in WooCommerce. (Quantity stays two-way below, since stock genuinely
            // changes in WooCommerce as orders come in.)

            // Quantity
            $current_qty_str = ($current_quantity_before_update !== null) ? strval($current_quantity_before_update) : '';
            $original_qty_str = ($original_quantity !== '') ? strval($original_quantity) : '';
            if ($current_qty_str !== $original_qty_str && $recently_modified) {
                if (isset($product_data['stock_quantity'])) {
                    $product_data['stock_quantity'] = $current_quantity_before_update;
                }
                $will_write_quantity_back = true;
            }
        }

        // Change detection: if an existing variation is unchanged since the last
        // sync, skip re-saving it — but still record it as "seen" so it is not
        // treated as an orphan and deleted.
        $new_hash = $this->compute_product_hash($product_data);
        if ($variation && !$force_update) {
            $old_hash = $variation->get_meta('_wc_gs_data_hash');
            if ($old_hash !== '' && $old_hash === $new_hash) {
                $vid = $variation->get_id();
                if (!isset($state['parent_seen_variations'][$parent_id])) {
                    $state['parent_seen_variations'][$parent_id] = array();
                }
                $state['parent_seen_variations'][$parent_id][] = $vid;
                return array(
                    'action'       => 'skipped',
                    'product_id'   => $vid,
                    'sku'          => $variation->get_sku(),
                    'missing_id'   => false,
                    'row_number'   => $row_number,
                    'match_method' => 'variation',
                );
            }
        }

        if (!$variation) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
        }

        // Attributes: resolve to taxonomy => term-slug and ensure the parent
        // offers each option.
        $variation->set_attributes($this->resolve_variation_attributes($parent_id, $attr_map));

        if (!empty($product_data['sku'])) {
            $variation->set_sku($product_data['sku']);
        }
        if (!empty($product_data['meta_data'])) {
            foreach ($product_data['meta_data'] as $meta) {
                $variation->update_meta_data($meta['key'], $meta['value']);
            }
        }

        // Variation description = the Short Description column.
        if (isset($product_data['short_description'])) {
            $variation->set_description((string) $product_data['short_description']);
        }

        // Enabled/disabled from Status (private = disabled).
        $status = !empty($product_data['status']) ? strtolower($product_data['status']) : 'publish';
        $variation->set_status($status === 'private' ? 'private' : 'publish');

        // Prices + sale schedule.
        if (isset($product_data['regular_price']) && $product_data['regular_price'] !== '') {
            $variation->set_regular_price($product_data['regular_price']);
        }
        if (isset($product_data['sale_price'])) {
            $variation->set_sale_price($product_data['sale_price'] !== '' ? $product_data['sale_price'] : '');
        }
        if (isset($product_data['date_on_sale_from'])) {
            $variation->set_date_on_sale_from($product_data['date_on_sale_from'] !== '' ? $product_data['date_on_sale_from'] : null);
        }
        if (isset($product_data['date_on_sale_to'])) {
            $variation->set_date_on_sale_to($product_data['date_on_sale_to'] !== '' ? $product_data['date_on_sale_to'] : null);
        }

        // Stock.
        if (isset($product_data['manage_stock'])) {
            $variation->set_manage_stock((bool) $product_data['manage_stock']);
        }
        if (array_key_exists('stock_quantity', $product_data)) {
            $variation->set_stock_quantity(intval($product_data['stock_quantity']));
        }
        if (!empty($product_data['stock_status'])) {
            $variation->set_stock_status($product_data['stock_status']);
        }
        if (!empty($product_data['backorders'])) {
            $variation->set_backorders($product_data['backorders']);
        }
        if (!empty($product_data['low_stock_amount'])) {
            $variation->set_low_stock_amount(intval($product_data['low_stock_amount']));
        }

        // Weight / dimensions.
        if (!empty($product_data['weight'])) {
            $variation->set_weight($product_data['weight']);
        }
        if (!empty($product_data['dimensions']['length'])) {
            $variation->set_length($product_data['dimensions']['length']);
        }
        if (!empty($product_data['dimensions']['width'])) {
            $variation->set_width($product_data['dimensions']['width']);
        }
        if (!empty($product_data['dimensions']['height'])) {
            $variation->set_height($product_data['dimensions']['height']);
        }

        // Shipping class.
        if (!empty($product_data['shipping_class'])) {
            $term = get_term_by('slug', $product_data['shipping_class'], 'product_shipping_class');
            if ($term && !is_wp_error($term)) {
                $variation->set_shipping_class_id($term->term_id);
            }
        }

        // Tax class.
        if (!empty($product_data['tax_class'])) {
            $tax_class = trim((string) $product_data['tax_class']);
            $variation->set_tax_class(strtolower($tax_class) === 'standard' ? '' : sanitize_title($tax_class));
        }

        // Virtual / downloadable (variations support these too).
        if (isset($product_data['virtual'])) {
            $variation->set_virtual((bool) $product_data['virtual']);
        }
        if (isset($product_data['downloadable'])) {
            $variation->set_downloadable((bool) $product_data['downloadable']);
        }
        if (isset($product_data['downloads'])) {
            $variation->set_downloads($this->build_download_objects($product_data['downloads']));
        }
        if (isset($product_data['download_limit'])) {
            $variation->set_download_limit((int) $product_data['download_limit']);
        }
        if (isset($product_data['download_expiry'])) {
            $variation->set_download_expiry((int) $product_data['download_expiry']);
        }

        $variation_id = $variation->save();

        // Variation image (single image — the first one provided for the row).
        if (!empty($product_data['images'])) {
            $image_id = $this->resolve_variation_image_id($product_data['images'][0], $variation_id);
            if ($image_id) {
                $variation->set_image_id($image_id);
                $variation->save();
            }
        }

        // Persist the data hash so an unchanged variation is skipped next sync.
        if ($variation_id) {
            update_post_meta($variation_id, '_wc_gs_data_hash', $new_hash);
        }

        // Record for orphan reconciliation (variations no longer in the sheet).
        if (!isset($state['parent_seen_variations'][$parent_id])) {
            $state['parent_seen_variations'][$parent_id] = array();
        }
        $state['parent_seen_variations'][$parent_id][] = $variation_id;

        $result = array(
            'action'       => $action,
            'product_id'   => $variation_id,
            'sku'          => $variation->get_sku(),
            'missing_id'   => $had_empty_id,
            'row_number'   => $row_number,
            'match_method' => 'variation',
        );

        // Write a generated/used SKU back if the row's SKU cell was blank.
        if ($had_empty_sku && !empty($result['sku'])) {
            $result['generated_sku'] = $result['sku'];
        }

        // Bidirectional write-backs (WooCommerce value preserved above): surface
        // them so finalize_sync writes the WooCommerce value into the row.
        if ($will_write_sku_back) {
            $result['generated_sku'] = $current_sku_before_update;
        }
        if ($will_write_quantity_back) {
            $result['generated_quantity'] = $current_quantity_before_update;
        }
        if ($will_write_gtin_back) {
            $result['gtin_changed'] = true;
            $result['current_gtin'] = $current_gtin_before_update;
        }

        return $result;
    }

    /**
     * Find an existing variation of $parent_id that this row refers to: by the
     * row's ID, then its SKU, then by an exact attribute-combination match.
     * Returns a WC_Product_Variation or null.
     */
    private function find_matching_variation($parent_id, $product_data, $attr_map) {
        // By ID
        if (!empty($product_data['id'])) {
            $p = wc_get_product($product_data['id']);
            if ($p && $p->get_type() === 'variation' && (int) $p->get_parent_id() === (int) $parent_id) {
                return $p;
            }
        }
        // By SKU
        if (!empty($product_data['sku'])) {
            $vid = wc_get_product_id_by_sku($product_data['sku']);
            if ($vid) {
                $p = wc_get_product($vid);
                if ($p && $p->get_type() === 'variation' && (int) $p->get_parent_id() === (int) $parent_id) {
                    return $p;
                }
            }
        }
        // By attribute combination
        $want = $this->attr_map_to_taxonomy_slugs($attr_map);
        if (!empty($want)) {
            $parent = wc_get_product($parent_id);
            if ($parent && $parent->get_type() === 'variable') {
                foreach ($parent->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    if (!$child || $child->get_type() !== 'variation') {
                        continue;
                    }
                    $child_attrs = $child->get_attributes(); // taxonomy => slug
                    $matches = true;
                    foreach ($want as $tax => $slug) {
                        if (!isset($child_attrs[$tax]) || $child_attrs[$tax] !== $slug) {
                            $matches = false;
                            break;
                        }
                    }
                    if ($matches) {
                        return $child;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve an attribute map (name => value) to the taxonomy => term-slug map a
     * variation stores, creating terms as needed and ensuring the parent product
     * offers each option (so the variation's value is valid).
     */
    private function resolve_variation_attributes($parent_id, $attr_map) {
        $resolved = array();
        foreach ($attr_map as $name => $value) {
            $value = trim((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            $taxonomy = $this->get_or_create_global_attribute($name);
            if (!$taxonomy) {
                continue;
            }
            $term = get_term_by('name', $value, $taxonomy);
            if (!$term) {
                $inserted = wp_insert_term($value, $taxonomy);
                if (is_wp_error($inserted)) {
                    wc_gs_log('WC_GS_Sync: Failed to create variation term "' . $value . '" in ' . $taxonomy . ': ' . $inserted->get_error_message());
                    continue;
                }
                $term = get_term($inserted['term_id'], $taxonomy);
            }
            // Ensure the parent offers this term (append, don't replace).
            wp_set_object_terms($parent_id, (int) $term->term_id, $taxonomy, true);
            $resolved[$taxonomy] = $term->slug;
        }
        return $resolved;
    }

    /**
     * Read-only version of the above for matching: attribute name => value to
     * taxonomy => slug, without creating terms or touching the parent.
     */
    private function attr_map_to_taxonomy_slugs($attr_map) {
        $out = array();
        foreach ($attr_map as $name => $value) {
            $value = trim((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            $taxonomy = $this->get_or_create_global_attribute($name);
            if (!$taxonomy) {
                continue;
            }
            $term = get_term_by('name', $value, $taxonomy);
            $out[$taxonomy] = $term ? $term->slug : sanitize_title($value);
        }
        return $out;
    }

    /**
     * Resolve a single image definition (existing attachment id or a src URL) to
     * an attachment ID for a variation. Returns 0 if none/failed.
     */
    private function resolve_variation_image_id($image_data, $variation_id) {
        $image_id = 0;
        if (isset($image_data['id']) && !empty($image_data['id'])) {
            $image_id = (int) $image_data['id'];
        } elseif (isset($image_data['src']) && !empty($image_data['src'])) {
            $uploaded = $this->upload_image_from_url($image_data['src'], $variation_id);
            if (is_wp_error($uploaded)) {
                wc_gs_log('WC_GS_Sync: Failed to upload variation image: ' . $uploaded->get_error_message());
                return 0;
            }
            $image_id = (int) $uploaded;
        }

        // Apply alt text (non-empty only) to the resolved image.
        if ($image_id && isset($image_data['alt']) && trim((string) $image_data['alt']) !== '') {
            update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($image_data['alt']));
        }

        return $image_id;
    }

	/**
	 * Compute a stable hash of the meaningful product data, used to detect when a
	 * row is unchanged so the sync can skip re-saving it. Excludes volatile fields
	 * (ID, delete flag, type).
	 */
	private function compute_product_hash($product_data) {
		$keys = array(
			'name', 'slug', 'description', 'short_description', 'sku',
			'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to',
			'status', 'catalog_visibility', 'visibility', 'post_password', 'featured',
			'tax_status', 'tax_class',
			'stock_status', 'manage_stock', 'stock_quantity', 'backorders',
			'sold_individually', 'low_stock_amount',
			'weight', 'dimensions', 'shipping_class',
			'virtual', 'downloadable', 'downloads', 'download_limit', 'download_expiry',
			'purchase_note', 'menu_order', 'reviews_allowed',
			'upsells', 'cross_sells',
			'categories', 'tags', 'attributes', 'meta', 'meta_data', 'images',
		);

		$subset = array();
		foreach ($keys as $k) {
			$v = isset($product_data[$k]) ? $product_data[$k] : null;
			// Images flip representation once imported (a source URL on the first
			// sync becomes a matched attachment ID on later syncs). Normalize them
			// to their stable source URL so that flip alone doesn't look like a
			// change and spuriously re-"update" the product.
			if ($k === 'images') {
				$v = $this->normalize_images_for_hash($v);
			}
			// Canonicalize so a value missing on a new product (the data is cleaned
			// of empties when the ID is blank) hashes the same as the same value
			// present-but-empty on an update. Without this, a product created from
			// the sheet would spuriously re-"update" on the next sync.
			$subset[$k] = $this->canonicalize_for_hash($v);
		}

		return md5(wp_json_encode($subset));
	}

	/**
	 * Normalize the images array for change detection so an image hashes the same
	 * whether it is represented as a source URL (not yet in the media library) or
	 * as a matched attachment ID (after it has been imported). Each image reduces
	 * to its stable source URL plus position and alt text. Without this, the first
	 * re-sync after a product's images are imported always looks "updated" because
	 * the image flips from {src: url} to {id: 123}.
	 */
	private function normalize_images_for_hash($images) {
		if (!is_array($images)) {
			return $images;
		}
		$out = array();
		foreach ($images as $img) {
			if (!is_array($img)) {
				continue;
			}
			$url = '';
			if (isset($img['src']) && $img['src'] !== '') {
				$url = (string) $img['src'];
			} elseif (!empty($img['id'])) {
				// Prefer the original source URL stored at import time; fall back to
				// the local attachment URL (the filename match guarantees it equals
				// the sheet URL).
				$src_meta = get_post_meta((int) $img['id'], '_wc_gs_source_url', true);
				$url = ($src_meta !== '' && $src_meta !== false)
					? (string) $src_meta
					: (string) wp_get_attachment_url((int) $img['id']);
			}
			$out[] = array(
				'url'      => $url,
				'position' => isset($img['position']) ? (int) $img['position'] : 0,
				'alt'      => isset($img['alt']) ? (string) $img['alt'] : '',
			);
		}
		return $out;
	}

	/**
	 * Recursively strip empty strings, nulls and empty arrays (mirroring
	 * clean_object) so two representations of the same data — one cleaned for a
	 * new product, one raw for an update — produce an identical hash. `false` is
	 * preserved so booleans remain meaningful.
	 */
	private function canonicalize_for_hash($v) {
		if (is_array($v)) {
			$out = array();
			foreach ($v as $k => $item) {
				$c = $this->canonicalize_for_hash($item);
				if ($c !== null) {
					$out[$k] = $c;
				}
			}
			return empty($out) ? null : $out;
		}
		if ($v === '' || $v === null) {
			return null;
		}
		return $v;
	}

	/**
	 * NEW: Check if product should be deleted
	 */
	private function should_delete_product($product_data) {
		$delete_value = $product_data['delete'] ?? '';
    
		if (empty($delete_value)) {
			return false;
		}
    
		$delete_value = strtolower(trim($delete_value));
		return in_array($delete_value, ['yes', 'y', '1', 'true', 'delete']);
	}

	/**
	 * NEW: Handle product deletion
	 */
	private function handle_product_deletion($product_data, $row_number) {
		wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - DELETE REQUEST detected');
    
		// Find the product to delete (same logic as your existing product matching)
		$product_to_delete = null;
		$match_method = 'none';
    
		if (!empty($product_data['id'])) {
			$product_to_delete = wc_get_product($product_data['id']);
			$match_method = 'ID';
		} elseif (!empty($product_data['sku'])) {
			$product_id = wc_get_product_id_by_sku($product_data['sku']);
			if ($product_id) {
				$product_to_delete = wc_get_product($product_id);
				$match_method = 'SKU';
			}
		} elseif (!empty($product_data['name'])) {
			$product_to_delete = $this->find_product_by_name($product_data['name']);
			$match_method = 'Name';
		}
    
		if (!$product_to_delete || !$product_to_delete->get_id()) {
			throw new Exception('Cannot delete product: Product not found');
		}

		$product_id = $product_to_delete->get_id();

		// Safety: never delete anything that is not a WooCommerce product/variation,
		// even if a sheet row supplies an arbitrary post ID.
		$post_type = get_post_type($product_id);
		if (!in_array($post_type, array('product', 'product_variation'), true)) {
			throw new Exception('Refusing to delete non-product post ID ' . $product_id);
		}

		// Move products to Trash (recoverable). Note: wp_delete_post($id, false)
		// only trashes the built-in post/page types — for a custom type like
		// `product` it deletes permanently — so go through WC_Product::delete(),
		// which trashes when not forcing. Variations have no Trash, so force-delete
		// those.
		$force_delete = ($post_type === 'product_variation');
		$delete_result = $product_to_delete->delete($force_delete);

		if (!$delete_result) {
			throw new Exception('Failed to delete product ID ' . $product_id);
		}

		wc_gs_log('WC_GS_Sync: Row ' . $row_number . ' - DELETE SUCCESS: Product ID ' . $product_id . ($force_delete ? ' deleted' : ' moved to Trash'));
    
		return array(
			'action' => 'deleted',
			'product_id' => $product_id,
			'missing_id' => false,
			'row_number' => $row_number,
			'match_method' => $match_method
		);
	}
    
    /**
     * Find product by name to prevent duplicates
     */
    private function find_product_by_name($product_name) {
        $posts = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'title' => $product_name,
            'posts_per_page' => 1,
            'exact' => true
        ));
        
        if (!empty($posts)) {
            return wc_get_product($posts[0]->ID);
        }
        
        return null;
    }
    
    /**
     * Create new WooCommerce product
     */
    private function create_product($product_data) {
        $product = new WC_Product_Simple();
        
        // Set basic data
        $product->set_name($product_data['name']);
        $this->apply_product_slug($product, $product_data);
        
        if (!empty($product_data['sku'])) {
            $product->set_sku($product_data['sku']);
        }
		
		if (!empty($product_data['meta_data'])) {
			foreach ($product_data['meta_data'] as $meta) {
				$product->update_meta_data($meta['key'], $meta['value']);
			}
		}
        
        if (!empty($product_data['description'])) {
            $product->set_description($product_data['description']);
        }
        
        if (!empty($product_data['short_description'])) {
            $product->set_short_description($product_data['short_description']);
        }
        
        if (!empty($product_data['regular_price'])) {
            $product->set_regular_price($product_data['regular_price']);
        }
        
        if (!empty($product_data['sale_price'])) {
            $product->set_sale_price($product_data['sale_price']);
        }
		
		// Set stock status
		if (!empty($product_data['stock_status'])) {
			$product->set_stock_status($product_data['stock_status']);
		}
		
		// Set backorders
		if (!empty($product_data['backorders'])) {
			$product->set_backorders($product_data['backorders']);
		}
		
		// Set low stock threshold
		if (!empty($product_data['low_stock_amount'])) {
			$product->set_low_stock_amount(intval($product_data['low_stock_amount']));
		}
        
        if (!empty($product_data['weight'])) {
            $product->set_weight($product_data['weight']);
        }
        
        if (!empty($product_data['stock_quantity'])) {
            $product->set_stock_quantity(intval($product_data['stock_quantity']));
            $product->set_manage_stock(true);
        }

        // Apply additional attributes from the sheet
        $this->apply_additional_product_fields($product, $product_data);

        // Set status
        $status = !empty($product_data['status']) ? $product_data['status'] : 'publish';
        $product->set_status($status);
        
        // Save product
        $product_id = $product->save();
        
        // Handle categories
        if (!empty($product_data['categories'])) {
            $this->set_product_categories($product_id, $product_data['categories']);
        }
        
        // Handle tags
        if (!empty($product_data['tags'])) {
            $this->set_product_tags($product_id, $product_data['tags']);
        }
        
        // Handle images (featured + gallery)
        if (!empty($product_data['images'])) {
            $this->handle_product_images($product_id, $product_data);
        }

        // Handle global product attributes (for filtering)
        if (!empty($product_data['attributes'])) {
            $this->set_product_attributes($product_id, $product_data['attributes']);
        }

        // Handle custom post meta (the "Meta" marker columns)
        if (!empty($product_data['meta'])) {
            $this->set_product_meta($product_id, $product_data['meta']);
        }

        // Apply post visibility (public/private/password) from the Visibility column
        $this->apply_post_visibility($product_id, $product_data);

        return array(
            'action' => 'created',
            'product_id' => $product_id,
            'missing_id' => false // NEW: Always false for created products since they never had an ID
        );
    }
    
    /**
     * Update existing WooCommerce product
     */
    private function update_product($product, $product_data) {
        // Update basic data
        $product->set_name($product_data['name']);
        $this->apply_product_slug($product, $product_data);
        
        if (!empty($product_data['description'])) {
            $product->set_description($product_data['description']);
        }
        
        if (!empty($product_data['short_description'])) {
            $product->set_short_description($product_data['short_description']);
        }
		
		// Update SKU if provided
		if (!empty($product_data['sku'])) {
			$product->set_sku($product_data['sku']);
		}
		
		if (!empty($product_data['meta_data'])) {
			foreach ($product_data['meta_data'] as $meta) {
				$product->update_meta_data($meta['key'], $meta['value']);
			}
		}
        
        if (!empty($product_data['regular_price'])) {
            $product->set_regular_price($product_data['regular_price']);
        }
        
        if (!empty($product_data['sale_price'])) {
            $product->set_sale_price($product_data['sale_price']);
        }
		
		// Set stock status  
		if (!empty($product_data['stock_status'])) {
			$product->set_stock_status($product_data['stock_status']);
		}
		
		// Set backorders
		if (!empty($product_data['backorders'])) {
			$product->set_backorders($product_data['backorders']);
		}
		
		// Set low stock threshold  
		if (!empty($product_data['low_stock_amount'])) {
			$product->set_low_stock_amount(intval($product_data['low_stock_amount']));
		}
        
        if (!empty($product_data['weight'])) {
            $product->set_weight($product_data['weight']);
        }
        
        if (!empty($product_data['stock_quantity'])) {
            $product->set_stock_quantity(intval($product_data['stock_quantity']));
            $product->set_manage_stock(true);
        }

        // Apply additional attributes from the sheet
        $this->apply_additional_product_fields($product, $product_data);

        // Set status
        if (!empty($product_data['status'])) {
            $product->set_status($product_data['status']);
        }
        
        // Save product
        $product->save();
        
        $product_id = $product->get_id();
        
        // Handle categories
        if (!empty($product_data['categories'])) {
            $this->set_product_categories($product_id, $product_data['categories']);
        }
        
        // Handle tags
        if (!empty($product_data['tags'])) {
            $this->set_product_tags($product_id, $product_data['tags']);
        }
        
        // Handle images (featured + gallery)
        if (!empty($product_data['images'])) {
            $this->handle_product_images($product_id, $product_data);
        }

        // Handle global product attributes (for filtering)
        if (!empty($product_data['attributes'])) {
            $this->set_product_attributes($product_id, $product_data['attributes']);
        }

        // Handle custom post meta (the "Meta" marker columns)
        if (!empty($product_data['meta'])) {
            $this->set_product_meta($product_id, $product_data['meta']);
        }

        // Apply post visibility (public/private/password) from the Visibility column
        $this->apply_post_visibility($product_id, $product_data);

        return array(
            'action' => 'updated',
            'product_id' => $product_id,
            'missing_id' => false // NEW: Will be set to true in process_product_row if ID was missing
        );
    }

    /**
     * Set the product slug (post_name) from the sheet.
     *
     * If the "Slug" column is filled it is used; otherwise the slug is derived
     * from the product Name. WordPress's wp_unique_post_slug() then guarantees
     * uniqueness, appending -2, -3… when the slug is already taken by another
     * product (so identical names/slugs don't collide). Not applied to variations
     * (their slug is internal).
     */
    private function apply_product_slug($product, $product_data) {
        if ($product->is_type('variation')) {
            return;
        }

        $base = '';
        if (!empty($product_data['slug'])) {
            $base = sanitize_title($product_data['slug']);
        } elseif (!empty($product_data['name'])) {
            $base = sanitize_title($product_data['name']);
        }
        if ($base === '') {
            return;
        }

        $unique = wp_unique_post_slug($base, (int) $product->get_id(), 'publish', 'product', 0);
        $product->set_slug($unique);
    }

    /**
     * Apply additional product attributes parsed from the sheet that aren't
     * handled by the basic setters above (featured, visibility, sale schedule,
     * sold individually, dimensions, shipping class).
     *
     * Shared by create_product() and update_product().
     */
    private function apply_additional_product_fields($product, $product_data) {
        // Featured flag (sheet is the source of truth)
        if (isset($product_data['featured'])) {
            $product->set_featured((bool) $product_data['featured']);
        }

        // Sold individually
        if (isset($product_data['sold_individually'])) {
            $product->set_sold_individually((bool) $product_data['sold_individually']);
        }

        // Catalog visibility (data builder returns null to intentionally skip)
        if (!empty($product_data['catalog_visibility'])) {
            $product->set_catalog_visibility($product_data['catalog_visibility']);
        }

        // Sale schedule dates (empty string clears the date)
        if (isset($product_data['date_on_sale_from'])) {
            $product->set_date_on_sale_from($product_data['date_on_sale_from'] !== '' ? $product_data['date_on_sale_from'] : null);
        }
        if (isset($product_data['date_on_sale_to'])) {
            $product->set_date_on_sale_to($product_data['date_on_sale_to'] !== '' ? $product_data['date_on_sale_to'] : null);
        }

        // Dimensions
        if (!empty($product_data['dimensions']['length'])) {
            $product->set_length($product_data['dimensions']['length']);
        }
        if (!empty($product_data['dimensions']['width'])) {
            $product->set_width($product_data['dimensions']['width']);
        }
        if (!empty($product_data['dimensions']['height'])) {
            $product->set_height($product_data['dimensions']['height']);
        }

        // Shipping class (the data builder returns a slug; WC_Product only has
        // set_shipping_class_id(), so resolve the slug to its term ID)
        if (!empty($product_data['shipping_class'])) {
            $shipping_term = get_term_by('slug', $product_data['shipping_class'], 'product_shipping_class');
            if ($shipping_term && !is_wp_error($shipping_term)) {
                $product->set_shipping_class_id($shipping_term->term_id);
            }
        }

        // Upsells / Cross-sells (referenced by product ID or SKU; sheet is authoritative)
        if (isset($product_data['upsells'])) {
            $product->set_upsell_ids($this->resolve_product_ids($product_data['upsells']));
        }
        if (isset($product_data['cross_sells'])) {
            $product->set_cross_sell_ids($this->resolve_product_ids($product_data['cross_sells']));
        }

        // Tax status (taxable / shipping / none)
        if (!empty($product_data['tax_status'])) {
            $tax_status = strtolower(trim($product_data['tax_status']));
            if (in_array($tax_status, array('taxable', 'shipping', 'none'), true)) {
                $product->set_tax_status($tax_status);
            }
        }

        // Tax class ("Standard" or empty = the standard rate, stored as '')
        if (!empty($product_data['tax_class'])) {
            $tax_class = trim((string) $product_data['tax_class']);
            $product->set_tax_class(strtolower($tax_class) === 'standard' ? '' : sanitize_title($tax_class));
        }

        // Purchase note
        if (!empty($product_data['purchase_note'])) {
            $product->set_purchase_note($product_data['purchase_note']);
        }

        // Position (menu order)
        if (isset($product_data['menu_order']) && $product_data['menu_order'] !== '') {
            $product->set_menu_order(intval($product_data['menu_order']));
        }

        // Allow reviews (only when the column has an explicit value)
        if (!empty($product_data['reviews_allowed'])) {
            $reviews = strtolower(trim($product_data['reviews_allowed']));
            $product->set_reviews_allowed(in_array($reviews, array('yes', 'y', '1', 'true', 'enabled', 'allow'), true));
        }

        // Virtual (no shipping). The data builder stores null when the column is
        // absent, so isset() skips it and leaves the product untouched.
        if (isset($product_data['virtual'])) {
            $product->set_virtual((bool) $product_data['virtual']);
        }

        // Downloadable product + its files and limits.
        if (isset($product_data['downloadable'])) {
            $product->set_downloadable((bool) $product_data['downloadable']);
        }
        if (isset($product_data['downloads'])) {
            $product->set_downloads($this->build_download_objects($product_data['downloads']));
        }
        if (isset($product_data['download_limit'])) {
            $product->set_download_limit((int) $product_data['download_limit']);
        }
        if (isset($product_data['download_expiry'])) {
            $product->set_download_expiry((int) $product_data['download_expiry']);
        }
    }

    /**
     * Convert parsed download definitions (name/file pairs) into the
     * WC_Product_Download objects WooCommerce expects. An empty list clears the
     * product's downloads. The download ID is derived from the file URL so it
     * stays stable across syncs (avoids churn in change detection).
     */
    private function build_download_objects($downloads) {
        $objects = array();
        if (empty($downloads) || !is_array($downloads)) {
            return $objects;
        }

        foreach ($downloads as $d) {
            if (empty($d['file'])) {
                continue;
            }
            $download = new WC_Product_Download();
            $download->set_name(isset($d['name']) ? $d['name'] : '');
            $download->set_file($d['file']);
            $download->set_id(md5($d['file']));
            $objects[] = $download;
        }

        return $objects;
    }

    /**
     * Resolve a comma/semicolon/pipe-separated list of product references
     * (numeric IDs or SKUs) into an array of valid product IDs.
     */
    private function resolve_product_ids($value) {
        $tokens = is_array($value) ? $value : preg_split('/[,;|]/', (string) $value);

        $ids = array();
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            // Numeric token: treat as a product ID if it resolves to a product
            if (ctype_digit($token) && wc_get_product((int) $token)) {
                $ids[] = (int) $token;
                continue;
            }

            // Otherwise treat as a SKU
            $by_sku = wc_get_product_id_by_sku($token);
            if ($by_sku) {
                $ids[] = (int) $by_sku;
            } else {
                wc_gs_log('WC_GS_Sync: Upsell/Cross-sell reference not found: "' . $token . '"');
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Write the custom post meta from the "Meta" marker columns.
     *
     * $meta_data is meta_key => value (values already trimmed). For each key the
     * sheet manages: a non-empty value is written, an empty value DELETES the key
     * (so clearing a cell on re-sync removes the meta). Only keys derived from the
     * sheet's Meta columns are touched — never other product meta. If ACF is
     * active and a matching field is registered, the value is written through ACF
     * so it shows in the product editor; otherwise plain post meta is used.
     */
    private function set_product_meta($product_id, $meta_data) {
        if (empty($meta_data) || !is_array($meta_data)) {
            return;
        }

        $acf = function_exists('update_field') && function_exists('acf_get_field');

        foreach ($meta_data as $key => $value) {
            $key = (string) $key;
            // Safety: never create/delete protected (underscore-prefixed) meta.
            if ($key === '' || $key[0] === '_') {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                if ($acf && acf_get_field($key)) {
                    update_field($key, $value, $product_id);
                } else {
                    update_post_meta($product_id, $key, $value);
                }
            } else {
                // Empty cell -> remove the key (safe no-op if it was never set).
                if ($acf && acf_get_field($key)) {
                    delete_field($key, $product_id);
                } else {
                    delete_post_meta($product_id, $key);
                }
            }
        }
    }

    /**
     * Apply global product attributes (used for filtering / layered nav).
     *
     * Creates the global attribute taxonomy and terms if needed, assigns the
     * terms to the product, and stores them as the product's attributes.
     */
    private function set_product_attributes($product_id, $attributes_data, $for_variation = false) {
        if (empty($attributes_data) || !is_array($attributes_data)) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $product_attributes = array();
        $position = 0;

        foreach ($attributes_data as $attribute) {
            $name = isset($attribute['name']) ? trim($attribute['name']) : '';
            $values = isset($attribute['values']) ? (array) $attribute['values'] : array();
            if ($name === '' || empty($values)) {
                continue;
            }

            // Ensure the global attribute taxonomy exists and is registered
            $taxonomy = $this->get_or_create_global_attribute($name);
            if (!$taxonomy) {
                continue;
            }

            // Ensure each term exists and collect the term IDs
            $term_ids = array();
            foreach ($values as $value) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }

                $term = get_term_by('name', $value, $taxonomy);
                if (!$term) {
                    $inserted = wp_insert_term($value, $taxonomy);
                    if (is_wp_error($inserted)) {
                        wc_gs_log('WC_GS_Sync: Failed to create attribute term "' . $value . '" in ' . $taxonomy . ': ' . $inserted->get_error_message());
                        continue;
                    }
                    $term_ids[] = (int) $inserted['term_id'];
                } else {
                    $term_ids[] = (int) $term->term_id;
                }
            }

            if (empty($term_ids)) {
                continue;
            }

            // Assign the terms to the product — this is what powers attribute filtering
            wp_set_object_terms($product_id, $term_ids, $taxonomy, false);

            // Order the attribute's terms to match the sheet so the front-end
            // variation dropdown lists them in the same order as the row (the
            // attribute is registered with order_by = menu_order / "custom
            // ordering", which sorts the dropdown by this term order). $term_ids is
            // already in sheet order. NOTE: term order is global per attribute
            // taxonomy, so when products disagree the most recently synced one wins.
            if (function_exists('wc_set_term_order')) {
                $term_order = 0;
                foreach ($term_ids as $ordered_term_id) {
                    wc_set_term_order((int) $ordered_term_id, $term_order, $taxonomy);
                    $term_order++;
                }
            }

            // Build the product attribute object
            $wc_attribute = new WC_Product_Attribute();
            $wc_attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $wc_attribute->set_name($taxonomy);
            $wc_attribute->set_options($term_ids);
            $wc_attribute->set_position($position++);

            // Visibility: visible unless the header carried a [hidden] tag.
            $visible = !(isset($attribute['visible']) && $attribute['visible'] === false);
            $wc_attribute->set_visible($visible);

            // Used for variations: a [no-vary] tag forces it off; otherwise the
            // default applies — on a variable parent the attributes drive the
            // variations, on a simple product they are plain filtering attributes.
            $use_for_variation = (isset($attribute['variation']) && $attribute['variation'] !== null)
                ? (bool) $attribute['variation']
                : $for_variation;
            $wc_attribute->set_variation($use_for_variation);

            $product_attributes[] = $wc_attribute;
        }

        if (!empty($product_attributes)) {
            $product->set_attributes($product_attributes);
            $product->save();
        }
    }

    /**
     * Align a global attribute's term order to the sheet without touching the
     * product. Used on the skip path (an unchanged product never reaches
     * set_product_attributes, which is where terms are otherwise ordered), so the
     * front-end variation dropdown order can be corrected without forcing a full
     * re-update of every product. Resolves only attributes/terms that already
     * exist; anything missing is left for a real create/update to handle.
     */
    private function ensure_attribute_term_order($attributes) {
        if (empty($attributes) || !is_array($attributes) || !function_exists('wc_set_term_order')) {
            return;
        }
        // Re-apply every sync. Attribute term order is GLOBAL per taxonomy, so a
        // per-product "already done" guard is wrong — another product (or the order
        // the terms were first created in) can leave the global order stale, and the
        // guard would never correct it. wc_set_term_order() ultimately calls
        // update_term_meta(), which no-ops when the value is unchanged, so this is
        // cheap (reads only) when the order is already right and self-heals drift.
        foreach ($attributes as $attribute) {
            $name = isset($attribute['name']) ? trim($attribute['name']) : '';
            $values = isset($attribute['values']) ? (array) $attribute['values'] : array();
            if ($name === '' || empty($values)) {
                continue;
            }
            $taxonomy = $this->find_existing_attribute_taxonomy($name);
            if (!$taxonomy) {
                continue;
            }
            $order = 0;
            foreach ($values as $value) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                $term = get_term_by('name', $value, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    wc_set_term_order((int) $term->term_id, $order, $taxonomy);
                    $order++;
                }
            }
        }
    }

    /**
     * Find an existing global attribute taxonomy by its label (exact header text)
     * without creating one. Returns the taxonomy name or false.
     */
    private function find_existing_attribute_taxonomy($name) {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        foreach (wc_get_attribute_taxonomies() as $tax) {
            if (isset($tax->attribute_label) && $tax->attribute_label === $name) {
                $taxonomy = wc_attribute_taxonomy_name($tax->attribute_name);
                $this->register_attribute_taxonomy($taxonomy);
                return $taxonomy;
            }
        }
        return false;
    }

    /**
     * Get a global attribute taxonomy name, creating the attribute if needed.
     * Returns the taxonomy (e.g. "pa_color") or false on failure.
     */
    private function get_or_create_global_attribute($name) {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        // Match on the attribute LABEL (the exact header text) so that two
        // different headers that would sanitize to the same slug — e.g. "WS" and
        // "W&S" both -> "ws" — do not collide into one attribute.
        $existing_slugs = array();
        foreach (wc_get_attribute_taxonomies() as $tax) {
            if (isset($tax->attribute_label) && $tax->attribute_label === $name) {
                $taxonomy = wc_attribute_taxonomy_name($tax->attribute_name);
                $this->register_attribute_taxonomy($taxonomy);
                return $taxonomy;
            }
            $existing_slugs[] = $tax->attribute_name;
        }

        // No attribute with this label yet — create one with a unique slug
        $slug = $this->generate_unique_attribute_slug($name, $existing_slugs);
        if ($slug === '') {
            return false;
        }

        $attribute_id = wc_create_attribute(array(
            'name'         => $name,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => true,
        ));

        if (is_wp_error($attribute_id)) {
            wc_gs_log('WC_GS_Sync: Failed to create global attribute "' . $name . '": ' . $attribute_id->get_error_message());
            return false;
        }

        $taxonomy = wc_attribute_taxonomy_name($slug);
        $this->register_attribute_taxonomy($taxonomy);
        return $taxonomy;
    }

    /**
     * Generate a collision-free attribute slug from a header, appending -2, -3…
     * when the base slug is already used by a different attribute. Stays within
     * WooCommerce's 28-character attribute-slug limit.
     */
    private function generate_unique_attribute_slug($name, $existing_slugs) {
        $base = wc_sanitize_taxonomy_name($name);
        if ($base === '') {
            return '';
        }
        $base = substr($base, 0, 28);

        $slug = $base;
        $i = 2;
        while (in_array($slug, $existing_slugs, true)) {
            $suffix = '-' . $i;
            $slug = substr($base, 0, 28 - strlen($suffix)) . $suffix;
            $i++;
        }

        return $slug;
    }

    /**
     * Register an attribute taxonomy for the current request so terms can be
     * added immediately (WooCommerce normally registers them on init).
     */
    private function register_attribute_taxonomy($taxonomy) {
        if (taxonomy_exists($taxonomy)) {
            return;
        }
        register_taxonomy(
            $taxonomy,
            apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, array('product')),
            apply_filters('woocommerce_taxonomy_args_' . $taxonomy, array(
                'hierarchical' => true,
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
            ))
        );
    }

    /**
     * Apply WordPress post visibility (public / private / password) from the
     * Visibility column. This is separate from Catalog Visibility. Runs after
     * the product is saved so it can adjust post_status / post_password.
     */
    private function apply_post_visibility($product_id, $product_data) {
        if (empty($product_data['visibility'])) {
            return; // No Visibility value — leave the Status column in control
        }

        $visibility = strtolower(trim($product_data['visibility']));
        $update = array('ID' => $product_id);

        switch ($visibility) {
            case 'private':
                $update['post_status'] = 'private';
                $update['post_password'] = '';
                break;

            case 'password':
                $password = isset($product_data['post_password']) ? trim((string) $product_data['post_password']) : '';
                if ($password === '') {
                    wc_gs_log('WC_GS_Sync: Visibility "password" requested for product ' . $product_id . ' but no Password value was provided; leaving visibility unchanged');
                    return;
                }
                $update['post_password'] = $password;
                // Password-protected posts must be public, not private
                if (get_post_status($product_id) === 'private') {
                    $update['post_status'] = 'publish';
                }
                break;

            case 'public':
            default:
                $update['post_password'] = '';
                // If the product was previously private, restore it to public
                if (get_post_status($product_id) === 'private') {
                    $update['post_status'] = 'publish';
                }
                break;
        }

        // Only write if there is something to change beyond the ID
        if (count($update) > 1) {
            wp_update_post($update);
            wc_gs_log('WC_GS_Sync: Applied post visibility "' . $visibility . '" to product ' . $product_id);
        }
    }

    /**
     * Set product categories
     * FIXED: Handle both string and array inputs
     */
    private function set_product_categories($product_id, $categories_data) {
        if (empty($categories_data)) {
            return;
        }
        
        $category_ids = array();
        
        // FIXED: Handle array format from data builder
        if (is_array($categories_data)) {
            foreach ($categories_data as $category) {
                if (isset($category['id'])) {
                    $category_ids[] = $category['id'];
                }
            }
        } else {
            // Handle string format (legacy)
            $categories = array_map('trim', explode(',', $categories_data));
            foreach ($categories as $category_name) {
                if (empty($category_name)) continue;
                
                // Get or create category
                $term = get_term_by('name', $category_name, 'product_cat');
                if (!$term) {
                    $term_data = wp_insert_term($category_name, 'product_cat');
                    if (!is_wp_error($term_data)) {
                        $category_ids[] = $term_data['term_id'];
                    }
                } else {
                    $category_ids[] = $term->term_id;
                }
            }
        }
        
        if (!empty($category_ids)) {
            wp_set_object_terms($product_id, $category_ids, 'product_cat');
        }
    }
    
    /**
     * Set product tags
     * FIXED: Handle both string and array inputs
     */
    private function set_product_tags($product_id, $tags_data) {
        if (empty($tags_data)) {
            return;
        }
        
        $tag_names = array();
        
        // FIXED: Handle array format from data builder
        if (is_array($tags_data)) {
            foreach ($tags_data as $tag) {
                if (isset($tag['name'])) {
                    $tag_names[] = $tag['name'];
                }
            }
        } else {
            // Handle string format (legacy)
            $tag_names = array_map('trim', explode(',', $tags_data));
        }
        
        if (!empty($tag_names)) {
            wp_set_object_terms($product_id, $tag_names, 'product_tag');
        }
    }
    
    /**
     * Handle product images (featured + gallery) with proper updates/removals
     */
    private function handle_product_images($product_id, $product_data) {
        // DEBUG: Log what we're receiving
        wc_gs_log('WC_GS_Sync: Handling images for product ' . $product_id);
        wc_gs_log('WC_GS_Sync: Image data received: ' . print_r($product_data['images'], true));
        
        if (!isset($product_data['images']) || !is_array($product_data['images'])) {
            wc_gs_log('WC_GS_Sync: No images array found or not an array');
            return;
        }
        
        $new_images = $product_data['images'];
        
        // Get current product images
        $product = wc_get_product($product_id);
        $current_featured_id = get_post_thumbnail_id($product_id);
        $current_gallery_ids = $product->get_gallery_image_ids();
        
        // Prepare new image data
        $new_featured_id = null;
        $new_gallery_ids = array();
        
        // Process each new image
        foreach ($new_images as $image_data) {
            wc_gs_log('WC_GS_Sync: Processing image: ' . print_r($image_data, true));
            
            $position = isset($image_data['position']) ? intval($image_data['position']) : 0;
            
            // Check if we have an existing ID or need to upload from URL
            if (isset($image_data['id']) && !empty($image_data['id'])) {
                $image_id = $image_data['id'];
                wc_gs_log('WC_GS_Sync: Using existing image ID: ' . $image_id);
            } elseif (isset($image_data['src']) && !empty($image_data['src'])) {
                // Upload new image from URL
                wc_gs_log('WC_GS_Sync: Uploading new image from URL: ' . $image_data['src']);
                $image_id = $this->upload_image_from_url($image_data['src'], $product_id);
                if (is_wp_error($image_id)) {
                    wc_gs_log('WC_GS_Sync: Failed to upload image: ' . $image_id->get_error_message());
                    continue; // Skip failed uploads
                } else {
                    wc_gs_log('WC_GS_Sync: Successfully uploaded image, ID: ' . $image_id);
                }
            } else {
                wc_gs_log('WC_GS_Sync: No ID or src found for image');
                continue; // Skip if no ID or URL
            }

            // Apply alt text from the sheet (non-empty only — a blank cell leaves
            // any existing alt text unchanged).
            if (isset($image_data['alt']) && trim((string) $image_data['alt']) !== '') {
                update_post_meta($image_id, '_wp_attachment_image_alt', sanitize_text_field($image_data['alt']));
            }

            // Assign to featured or gallery based on position
            if ($position === 0) {
                $new_featured_id = $image_id;
                wc_gs_log('WC_GS_Sync: Set as featured image: ' . $image_id);
            } else {
                $new_gallery_ids[] = $image_id;
                wc_gs_log('WC_GS_Sync: Added to gallery: ' . $image_id);
            }
        }
        
        // Update featured image
        if ($new_featured_id) {
            set_post_thumbnail($product_id, $new_featured_id);
            wc_gs_log('WC_GS_Sync: Updated featured image to: ' . $new_featured_id);
        } else {
            // Remove featured image if none specified
            delete_post_thumbnail($product_id);
            wc_gs_log('WC_GS_Sync: Removed featured image');
        }
        
        // Update gallery images
        update_post_meta($product_id, '_product_image_gallery', implode(',', $new_gallery_ids));
        wc_gs_log('WC_GS_Sync: Updated gallery images: ' . implode(',', $new_gallery_ids));
        
        // Clean up unused images (optional - be careful with this!)
        $this->cleanup_unused_product_images($product_id, $current_featured_id, $current_gallery_ids, $new_featured_id, $new_gallery_ids);
    }
    
    /**
     * Clean up images that are no longer used by the product
     */
    private function cleanup_unused_product_images($product_id, $old_featured_id, $old_gallery_ids, $new_featured_id, $new_gallery_ids) {
        // Get all old image IDs
        $old_image_ids = array();
        if ($old_featured_id) {
            $old_image_ids[] = $old_featured_id;
        }
        $old_image_ids = array_merge($old_image_ids, $old_gallery_ids);
        
        // Get all new image IDs
        $new_image_ids = array();
        if ($new_featured_id) {
            $new_image_ids[] = $new_featured_id;
        }
        $new_image_ids = array_merge($new_image_ids, $new_gallery_ids);
        
        // Find images that are no longer used
        $unused_image_ids = array_diff($old_image_ids, $new_image_ids);
        
        foreach ($unused_image_ids as $unused_id) {
            // Check if this image is used by other products before deleting
            if (!$this->is_image_used_elsewhere($unused_id, $product_id)) {
                // Only delete if it's not used by other products
                wp_delete_attachment($unused_id, true);
            }
        }
    }
    
    /**
     * Check if an image is used by other products
     */
    private function is_image_used_elsewhere($image_id, $exclude_product_id) {
        global $wpdb;
        
        // Check if used as featured image by other products
        $featured_usage = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_thumbnail_id' 
             AND meta_value = %d 
             AND post_id != %d",
            $image_id,
            $exclude_product_id
        ));
        
        if ($featured_usage > 0) {
            return true;
        }
        
        // Check if used in gallery by other products
        $gallery_usage = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_product_image_gallery' 
             AND meta_value LIKE %s 
             AND post_id != %d",
            '%' . $image_id . '%',
            $exclude_product_id
        ));
        
        return $gallery_usage > 0;
    }

    /**
     * Upload image from URL
     */
    private function upload_image_from_url($image_url, $post_id) {
        wc_gs_log('WC_GS_Sync: Starting image upload from URL: ' . $image_url);

        // SSRF protection: only allow well-formed http(s) URLs that pass WP's
        // safe-URL validation (blocks localhost / internal IPs by default).
        if (!wp_http_validate_url($image_url)) {
            wc_gs_log('WC_GS_Sync: Rejected unsafe image URL: ' . $image_url);
            return new WP_Error('invalid_image_url', 'Image URL is not allowed');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Cap the per-image download time. download_url() defaults to a 300s
        // timeout, so a single slow/unreachable image can block a whole batch for
        // 300 seconds — exactly Action Scheduler's per-action limit, which then
        // marks the batch "failed after 300 seconds" and stops the sync. A short
        // timeout makes a bad image fail fast; the caller skips it and the rest of
        // the row still imports. Filterable for slow hosts / large images.
        $timeout = max(5, (int) apply_filters('wc_gs_image_download_timeout', 20));
        $temp_file = download_url($image_url, $timeout);

        if (is_wp_error($temp_file)) {
            wc_gs_log('WC_GS_Sync: Failed to download image (timeout ' . $timeout . 's): ' . $temp_file->get_error_message());
            return $temp_file;
        }
        
        wc_gs_log('WC_GS_Sync: Downloaded to temp file: ' . $temp_file);
        
        $file = array(
            'name' => basename($image_url),
            'tmp_name' => $temp_file,
        );
        
        wc_gs_log('WC_GS_Sync: Attempting to sideload file: ' . print_r($file, true));
        
        $attachment_id = media_handle_sideload($file, $post_id);
        
        if (is_wp_error($attachment_id)) {
            wc_gs_log('WC_GS_Sync: Failed to sideload image: ' . $attachment_id->get_error_message());
            @unlink($temp_file);
            return $attachment_id;
        }
        
        wc_gs_log('WC_GS_Sync: Successfully uploaded image, attachment ID: ' . $attachment_id);

        // Record the source URL so future syncs reuse this attachment instead of
        // re-downloading the same external image every time.
        update_post_meta($attachment_id, '_wc_gs_source_url', $image_url);

        return $attachment_id;
    }
}