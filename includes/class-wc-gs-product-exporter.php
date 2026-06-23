<?php
/**
 * Product Exporter — builds a Google Sheets row from a WooCommerce product.
 *
 * This is the reverse of WC_GS_Product_Data_Builder: given the sheet's header
 * row, it produces a row of values aligned to those headers so existing
 * WooCommerce products can be written back into the sheet.
 *
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_GS_Product_Exporter {

    /**
     * Build all sheet rows for a product. A simple product yields one row; a
     * variable product yields a parent row (Type = variable) followed by one row
     * per variation (Type = variation, Parent = parent SKU).
     */
    public function build_product_rows($product, $headers) {
        if ($product->get_type() === 'variable') {
            $rows = array($this->build_row($product, $headers, 'parent'));
            foreach ($product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if ($variation && $variation->get_type() === 'variation') {
                    $rows[] = $this->build_row($variation, $headers, 'variation');
                }
            }
            return $rows;
        }

        return array($this->build_row($product, $headers, 'simple'));
    }

    /**
     * Build a single row for a product, aligned to the sheet headers.
     *
     * $mode is 'simple', 'parent' (a variable product) or 'variation' (one of its
     * variations); it controls which columns apply (variations blank the
     * parent-only fields and map Short Description to the variation description).
     */
    public function build_row($product, $headers, $mode = 'simple') {
        $row = array();

        // Section markers: columns between "Attributes" and "Meta" are global
        // attributes; columns after "Meta" are custom post meta.
        $attr_start = array_search('Attributes', $headers);
        $meta_start = array_search('Meta', $headers);

        foreach ($headers as $index => $header) {
            $header = trim($header);

            // Marker cells themselves stay blank
            if ($header === 'Attributes' || $header === 'Meta') {
                $row[] = '';
                continue;
            }

            // Meta columns (after the Meta marker)
            if ($meta_start !== false && $index > $meta_start) {
                $row[] = $this->get_meta_value($product, $header);
                continue;
            }

            // Attribute columns (after Attributes, and before Meta if present)
            if ($attr_start !== false && $index > $attr_start && ($meta_start === false || $index < $meta_start)) {
                $row[] = $this->get_attribute_value($product, $header);
                continue;
            }

            $row[] = $this->get_field_value($product, $header, $mode);
        }

        return $row;
    }

    /**
     * Read a product's custom meta value for a "Meta" column, using the same key
     * derivation as the importer (and ACF when available) so values round-trip.
     */
    private function get_meta_value($product, $header) {
        if ($header === '') {
            return '';
        }

        $key = WC_GS_Product_Data_Builder::meta_key_from_header($header);
        if ($key === '') {
            return '';
        }

        if (function_exists('get_field') && function_exists('acf_get_field') && acf_get_field($key)) {
            $value = get_field($key, $product->get_id());
        } else {
            $value = get_post_meta($product->get_id(), $key, true);
        }

        // Only export simple scalar values to a cell.
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Map a known field header to the product's value.
     *
     * $mode is 'simple', 'parent' or 'variation'. On variation rows the
     * parent-only columns are blank and "Short Description" carries the variation
     * description; "Parent" carries the parent SKU.
     */
    private function get_field_value($product, $header, $mode = 'simple') {
        // Parent reference: only variation rows carry it.
        if ($header === 'Parent') {
            if ($mode === 'variation') {
                $parent = wc_get_product($product->get_parent_id());
                return $parent ? $parent->get_sku() : '';
            }
            return '';
        }

        // On a variation row, columns that only exist at the product level are
        // left blank (the parent row owns them).
        if ($mode === 'variation') {
            $parent_only = array(
                'Name', 'Description', 'Visibility', 'Catalog Visibility', 'Password',
                'Featured', 'Category Path', 'Tags', 'Upsells', 'Cross-sells',
                'Sold Individually', 'Tax Status', 'Purchase Note', 'Position',
                'Allow Reviews',
            );
            if (in_array($header, $parent_only, true)) {
                return '';
            }
            // A variation's "Short Description" cell holds its variation description.
            if ($header === 'Short Description') {
                return $product->get_description();
            }
        }

        switch ($header) {
            case 'ID': return $product->get_id();
            case 'SKU': return $product->get_sku();
            case 'GTIN, UPC, EAN, or ISBN': return $product->get_meta('_global_unique_id');
            case 'Stock Management': return $product->get_manage_stock() ? 'yes' : 'no';
            case 'Quantity':
                $qty = $product->get_stock_quantity();
                return ($qty === null) ? '' : $qty;
            case 'Stock Status': return $product->get_stock_status();
            case 'Backorder': return $product->get_backorders();
            case 'Low Stock Threshold':
                $low = $product->get_low_stock_amount();
                return ($low === '' || $low === null) ? '' : $low;
            case 'Sold Individually': return $product->get_sold_individually() ? 'yes' : 'no';
            case 'Name': return $product->get_name();
            case 'Description': return $product->get_description();
            case 'Short Description': return $product->get_short_description();
            case 'Type': return $product->get_type();
            case 'Virtual': return $product->is_virtual() ? 'yes' : 'no';
            case 'Downloadable': return $product->is_downloadable() ? 'yes' : 'no';
            case 'Download Files': return $this->get_downloads_value($product);
            case 'Download Limit':
                $limit = $product->get_download_limit();
                return ($limit === -1 || $limit === '' || $limit === null) ? '' : $limit;
            case 'Download Expiry':
                $expiry = $product->get_download_expiry();
                return ($expiry === -1 || $expiry === '' || $expiry === null) ? '' : $expiry;
            case 'Status': return $product->get_status();
            case 'Visibility': return $this->get_post_visibility($product);
            case 'Catalog Visibility': return $product->get_catalog_visibility();
            case 'Password': return get_post_field('post_password', $product->get_id());
            case 'Featured': return $product->get_featured() ? 'yes' : 'no';
            case 'Regular Price': return $product->get_regular_price();
            case 'Sale Price': return $product->get_sale_price();
            case 'Sale Start Date': return $this->format_date($product->get_date_on_sale_from());
            case 'Sale End Date': return $this->format_date($product->get_date_on_sale_to());
            case 'Tax Status': return $product->get_tax_status();
            case 'Tax Class':
                $tax_class = $product->get_tax_class();
                return ($tax_class === '') ? 'Standard' : $tax_class;
            case 'Purchase Note': return $product->get_purchase_note();
            case 'Position': return $product->get_menu_order();
            case 'Allow Reviews': return $product->get_reviews_allowed() ? 'yes' : 'no';
            case 'Weight': return $product->get_weight();
            case 'Dimension (L)': return $product->get_length();
            case 'Dimension (W)': return $product->get_width();
            case 'Dimension (H)': return $product->get_height();
            case 'Shipping Class': return $this->get_shipping_class_name($product);
            case 'Upsells': return implode(', ', $product->get_upsell_ids());
            case 'Cross-sells': return implode(', ', $product->get_cross_sell_ids());
            case 'Category Path': return $this->get_category_path($product);
            case 'Tags': return $this->get_terms_list($product->get_tag_ids(), 'product_tag');
            case 'Image': return $this->get_image_url($product->get_image_id());
            case 'Image Alt Text': return $this->get_image_alt($product->get_image_id());

            // Sync / control columns are intentionally left blank on export
            case 'Sync Status':
            case 'Sync Error':
            case 'Last Synced':
            case 'Force Update':
            case 'Delete':
                return '';
        }

        // Gallery image alt text (check before the plain gallery pattern)
        if (preg_match('/^Gallery Image (\d+) Alt Text$/', $header, $m)) {
            $gallery = $product->get_gallery_image_ids();
            $pos = intval($m[1]) - 1;
            return isset($gallery[$pos]) ? $this->get_image_alt($gallery[$pos]) : '';
        }

        // Gallery image URL
        if (preg_match('/^Gallery Image (\d+)$/', $header, $m)) {
            $gallery = $product->get_gallery_image_ids();
            $pos = intval($m[1]) - 1;
            return isset($gallery[$pos]) ? $this->get_image_url($gallery[$pos]) : '';
        }

        return ''; // Unknown header
    }

    /**
     * Render the product's downloadable files as "Name | URL" lines, matching the
     * format the importer parses from the "Download Files" cell.
     */
    private function get_downloads_value($product) {
        if (!is_callable(array($product, 'get_downloads'))) {
            return '';
        }
        $downloads = $product->get_downloads();
        if (empty($downloads)) {
            return '';
        }
        $parts = array();
        foreach ($downloads as $download) {
            $parts[] = $download->get_name() . ' | ' . $download->get_file();
        }
        return implode("\n", $parts);
    }

    /**
     * Get a product's terms for a global attribute identified by its header.
     */
    private function get_attribute_value($product, $header) {
        if ($header === '') {
            return '';
        }

        // Strip any [hidden] / [no-vary] flag tags so the column's values are read
        // from the real attribute, letting tagged headers round-trip.
        $header = WC_GS_Product_Data_Builder::parse_attribute_header($header)['name'];
        if ($header === '') {
            return '';
        }

        // Resolve by attribute label (exact header text) to match how the
        // importer creates attributes, so collision-suffixed slugs (e.g. ws-2
        // for "W&S" alongside ws for "WS") are read from the correct taxonomy.
        $taxonomy = $this->find_attribute_taxonomy_by_label($header);
        if (!$taxonomy) {
            return '';
        }

        // A variation stores its single attribute value as meta (taxonomy => slug),
        // not as assigned terms — resolve that slug back to the term name.
        if ($product->get_type() === 'variation') {
            $attrs = $product->get_attributes();
            if (empty($attrs[$taxonomy])) {
                return '';
            }
            $term = get_term_by('slug', $attrs[$taxonomy], $taxonomy);
            return ($term && !is_wp_error($term)) ? $term->name : $attrs[$taxonomy];
        }

        // Simple product or variable parent: list the assigned terms (for a parent
        // this is the full set of variation options).
        $terms = wp_get_post_terms($product->get_id(), $taxonomy, array('fields' => 'names'));
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }

        return implode(', ', $terms);
    }

    /**
     * Find the global attribute taxonomy whose label exactly matches $label.
     * Returns the taxonomy name (e.g. "pa_ws-2") or null.
     */
    private function find_attribute_taxonomy_by_label($label) {
        foreach (wc_get_attribute_taxonomies() as $tax) {
            if (isset($tax->attribute_label) && $tax->attribute_label === $label) {
                return wc_attribute_taxonomy_name($tax->attribute_name);
            }
        }
        return null;
    }

    /**
     * Derive WordPress post visibility (public / private / password).
     */
    private function get_post_visibility($product) {
        if ($product->get_status() === 'private') {
            return 'private';
        }
        $password = get_post_field('post_password', $product->get_id());
        if ($password !== '') {
            return 'password';
        }
        return 'public';
    }

    /**
     * Format a WC_DateTime (or null) as Y-m-d.
     */
    private function format_date($date) {
        return $date ? $date->date('Y-m-d') : '';
    }

    /**
     * Resolve the product's shipping class term name.
     */
    private function get_shipping_class_name($product) {
        $class_id = $product->get_shipping_class_id();
        if (!$class_id) {
            return '';
        }
        $term = get_term($class_id, 'product_shipping_class');
        return ($term && !is_wp_error($term)) ? $term->name : '';
    }

    /**
     * Build a hierarchical "Parent > Child" path for the product's first category.
     */
    private function get_category_path($product) {
        $category_ids = $product->get_category_ids();
        if (empty($category_ids)) {
            return '';
        }

        $term_id = $category_ids[0];
        $names = array();

        $ancestors = array_reverse(get_ancestors($term_id, 'product_cat'));
        foreach ($ancestors as $ancestor_id) {
            $term = get_term($ancestor_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $names[] = $term->name;
            }
        }

        $term = get_term($term_id, 'product_cat');
        if ($term && !is_wp_error($term)) {
            $names[] = $term->name;
        }

        return implode(' > ', $names);
    }

    /**
     * Comma-separated term names for a list of term IDs.
     */
    private function get_terms_list($term_ids, $taxonomy) {
        if (empty($term_ids)) {
            return '';
        }
        $names = array();
        foreach ($term_ids as $term_id) {
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $names[] = $term->name;
            }
        }
        return implode(', ', $names);
    }

    private function get_image_url($attachment_id) {
        if (!$attachment_id) {
            return '';
        }
        $url = wp_get_attachment_url($attachment_id);
        return $url ? $url : '';
    }

    private function get_image_alt($attachment_id) {
        if (!$attachment_id) {
            return '';
        }
        return (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    }
}
