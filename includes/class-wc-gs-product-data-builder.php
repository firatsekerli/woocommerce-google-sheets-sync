<?php
/**
 * Build Product Data - PHP Version
 * Converted from Apps Script buildProductData function
 * 
 * @package WC_Google_Sheets_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_GS_Product_Data_Builder {
    
    /**
     * Build product data from Google Sheets row
     */
    public function build_product_data($row, $headers) {
        // Helper function to get value from row
        $get = function($name, $default_value = "") use ($row, $headers) {
            $index = array_search($name, $headers);
            if ($index === false) return $default_value;
            
            $value = isset($row[$index]) ? $row[$index] : null;
            return ($value !== null && $value !== '') ? trim(strval($value)) : $default_value;
        };

        // Helper function to check if field is empty
        $is_empty = function($name) use ($row, $headers) {
            $index = array_search($name, $headers);
            if ($index === false) return true;
            
            $value = isset($row[$index]) ? $row[$index] : null;
            return ($value === null || $value === '' || $value === 0);
        };

        // === CATALOG VISIBILITY VALIDATION (visible / catalog / search / hidden) ===
        $validate_visibility = function($visibility) {
            if (!$visibility || trim($visibility) === '') {
                return null; // Return null to omit from update
            }
            $valid_values = ['visible', 'catalog', 'search', 'hidden'];
            $normalized_value = strtolower($visibility);
            return in_array($normalized_value, $valid_values) ? $normalized_value : 'visible';
        };

        // === POST VISIBILITY VALIDATION (public / private / password) ===
        $validate_post_visibility = function($visibility) {
            if (!$visibility || trim($visibility) === '') {
                return null; // Return null to leave the Status column in control
            }
            $valid_values = ['public', 'private', 'password'];
            $normalized_value = strtolower(trim($visibility));
            return in_array($normalized_value, $valid_values) ? $normalized_value : null;
        };

        // === CATEGORIES HANDLING ===
        $categories = array();
        $category_path = $get("Category Path");
        
        if ($category_path) {
            $category_names = array_filter(array_map('trim', explode('>', $category_path)));
            $parent_id = 0;
            
            foreach ($category_names as $category_name) {
                $category_id = $this->get_or_create_category($category_name, $parent_id);
                if ($category_id) {
                    $categories[] = array('id' => $category_id);
                    $parent_id = $category_id;
                }
            }
        }

        // === TAGS HANDLING ===
        $tags_string = $get("Tags");
        $tags = array();
        if ($tags_string) {
            $tag_names = preg_split('/[,;|]/', $tags_string);
            foreach ($tag_names as $tag_name) {
                $tag_name = trim($tag_name);
                if ($tag_name) {
                    $tags[] = array('name' => $tag_name);
                }
            }
        }

        // === IMAGES HANDLING ===
        $images = $this->process_product_images($row, $headers);

        // === SHIPPING CLASS LOOKUP ===
        $shipping_class_slug = $this->get_or_create_shipping_class($get("Shipping Class"));

        // === BUILD PRODUCT DATA ===
        $product_data = array(
            'id' => $get("ID"),
            'images' => $images,
            'sku' => $this->generate_sku_if_missing($get("SKU"), $get("Name")),
            'meta_data' => $this->build_meta_data($row, $headers, $is_empty),
            'name' => $get("Name"),
            'description' => $get("Description"),
            'short_description' => $get("Short Description"),
            'type' => strtolower($get("Type", "simple")),
            'status' => strtolower($get("Status", "publish")),
            'catalog_visibility' => $validate_visibility($get("Catalog Visibility")),
            'visibility' => $validate_post_visibility($get("Visibility")),
            'post_password' => $get("Password"),
            'tax_status' => strtolower($get("Tax Status")),
            'tax_class' => $get("Tax Class"),
            'purchase_note' => $get("Purchase Note"),
            'menu_order' => $get("Position"),
            'reviews_allowed' => $get("Allow Reviews"),
            'featured' => in_array(strtolower($get("Featured")), ['true', 'yes', '1']),
            'regular_price' => $get("Regular Price"),
            'sale_price' => $is_empty("Sale Price") ? "" : $get("Sale Price"),
            'date_on_sale_from' => $is_empty("Sale Start Date") ? "" : $this->format_date($get("Sale Start Date")),
            'date_on_sale_to' => $is_empty("Sale End Date") ? "" : $this->format_date($get("Sale End Date")),
            'stock_status' => strtolower($get("Stock Status", "instock")),
            'manage_stock' => in_array(strtolower($get("Stock Management")), ['yes', 'true', '1']),
            'backorders' => in_array(strtolower($get("Backorder")), ['yes', 'true', '1']) ? "yes" : "no",
            'sold_individually' => in_array(strtolower($get("Sold Individually")), ['yes', 'true', '1']),
            'weight' => $is_empty("Weight") ? "" : $get("Weight"),
            'dimensions' => array(
                'length' => $is_empty("Dimension (L)") ? "" : $get("Dimension (L)"),
                'width' => $is_empty("Dimension (W)") ? "" : $get("Dimension (W)"),
                'height' => $is_empty("Dimension (H)") ? "" : $get("Dimension (H)")
            ),
            'categories' => $categories,
            'tags' => $tags,
            'upsells' => $get("Upsells"),
            'cross_sells' => $get("Cross-sells"),
            'attributes' => $this->build_attributes($row, $headers),
            'meta' => $this->build_custom_meta($row, $headers),
            'delete' => $get("Delete")
        );

        // Add stock quantity only if stock management is enabled
        if ($product_data['manage_stock']) {
            $product_data['stock_quantity'] = intval($get("Quantity")) ?: 0;
        }

        // Add low stock amount if specified
        $low_stock = $get("Low Stock Threshold");
        if ($low_stock) {
            $product_data['low_stock_amount'] = intval($low_stock);
        }

        // Add shipping class if specified
        if ($shipping_class_slug) {
            $product_data['shipping_class'] = $shipping_class_slug;
        }

        // Keep empty values for updates, only clean for new products
        $id = $get("ID");
        return $id ? $product_data : $this->clean_object($product_data);
    }

    /**
     * Process product images
     * UPDATED: Always return images array, even if empty
     */
    private function process_product_images($row, $headers) {
        $get = function($name) use ($row, $headers) {
            $index = array_search($name, $headers);
            return ($index !== false && isset($row[$index])) ? trim($row[$index]) : "";
        };

        $images = array();
        
        // Featured image (position 0)
        $featured_image = $get("Image");
        if ($featured_image && filter_var($featured_image, FILTER_VALIDATE_URL)) {
            $existing_id = $this->get_media_id_if_exists($featured_image);
            $images[] = $existing_id ? 
                array('id' => $existing_id, 'position' => 0) : 
                array('src' => $featured_image, 'position' => 0, 'alt' => $get("Image Alt Text"));
        }

        // Gallery images (positions 1-8)
        $gallery_columns = [
            "Gallery Image 01", "Gallery Image 02", "Gallery Image 03", "Gallery Image 04",
            "Gallery Image 05", "Gallery Image 06", "Gallery Image 07", "Gallery Image 08"
        ];
        
        foreach ($gallery_columns as $index => $col) {
            $url = $get($col);
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                $existing_id = $this->get_media_id_if_exists($url);
                $images[] = $existing_id ? 
                    array('id' => $existing_id, 'position' => $index + 1) : 
                    array('src' => $url, 'position' => $index + 1, 'alt' => $get($col . " Alt Text"));
            }
        }

        // IMPORTANT: Always return the images array (even if empty) 
        // so the sync handler knows to remove existing images when sheet is empty
        return $images;
    }

    /**
     * Build product attributes from the sheet.
     *
     * The "Attributes" column acts as a section marker: every column to its
     * right is treated as an individual product attribute, where the header is
     * the attribute name and the cell holds its value(s) (comma/semicolon/pipe
     * separated). These are applied as global attributes for filtering.
     */
    private function build_attributes($row, $headers) {
        $attributes = array();

        $start = array_search('Attributes', $headers);
        if ($start === false) {
            return $attributes;
        }

        $count = count($headers);
        // Attributes run until the next marker ("Meta") or the end of the row.
        $meta_start = array_search('Meta', $headers);
        if ($meta_start !== false && $meta_start > $start) {
            $count = $meta_start;
        }
        for ($i = $start + 1; $i < $count; $i++) {
            $name = isset($headers[$i]) ? trim($headers[$i]) : '';
            if ($name === '') {
                continue;
            }

            $raw = isset($row[$i]) ? trim(strval($row[$i])) : '';
            if ($raw === '') {
                continue;
            }

            $values = array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $raw)), function ($v) {
                return $v !== '';
            }));

            if (empty($values)) {
                continue;
            }

            $attributes[] = array(
                'name'   => $name,
                'values' => $values,
            );
        }

        return $attributes;
    }

    /**
     * Build custom post meta from the sheet.
     *
     * The "Meta" column is a section marker (mirroring "Attributes"): every column
     * to its right becomes a custom field (post meta) on the product, where the
     * header is slugified into the meta key and the cell holds the value. Empty
     * cells are kept (as empty strings) so the save step can DELETE a previously
     * set key when its cell is cleared on a re-sync. The "Meta" cell itself is
     * ignored. Returns an associative array of meta_key => value.
     */
    private function build_custom_meta($row, $headers) {
        $meta = array();

        $start = array_search('Meta', $headers);
        if ($start === false) {
            return $meta;
        }

        $count = count($headers);
        for ($i = $start + 1; $i < $count; $i++) {
            $header = isset($headers[$i]) ? trim($headers[$i]) : '';
            if ($header === '') {
                continue;
            }
            $key = self::meta_key_from_header($header);
            if ($key === '') {
                continue;
            }
            $meta[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }

        return $meta;
    }

    /**
     * Derive a safe post-meta key from a column header: lowercase, runs of
     * non-alphanumeric characters collapsed to a single underscore, leading/
     * trailing underscores trimmed (so the key never starts with "_", which would
     * be a protected/hidden meta key). Filterable via `wcgs_meta_key` for optional
     * namespacing (e.g. a "df_" prefix). Shared with the exporter so import and
     * export use identical keys.
     */
    public static function meta_key_from_header($header) {
        $key = strtolower((string) $header);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');

        $key = (string) apply_filters('wcgs_meta_key', $key, $header);

        // Never allow a leading underscore (protected meta).
        return ltrim($key, '_');
    }

    /**
     * Build meta data (GTIN field)
     */
    private function build_meta_data($row, $headers, $is_empty) {
        $get = function($name) use ($row, $headers) {
            $index = array_search($name, $headers);
            return ($index !== false && isset($row[$index])) ? trim($row[$index]) : "";
        };

        $meta_data = array();
        
        // GTIN field
        $gtin_field = "GTIN, UPC, EAN, or ISBN";
        if ($is_empty($gtin_field)) {
            // Explicitly clear this field
            $meta_data[] = array('key' => '_global_unique_id', 'value' => '');
        } else {
            $gtin = $get($gtin_field);
            if ($gtin) {
                $meta_data[] = array('key' => '_global_unique_id', 'value' => $gtin);
            }
        }

        return $meta_data;
    }

    /**
     * Get or create category with hierarchy support
     */
    private function get_or_create_category($name, $parent_id = 0) {
        if (!$name || trim($name) === '') return null;

        $name = trim($name);
        
        // Check if category exists
        $existing_terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'name' => $name,
            'parent' => $parent_id,
            'hide_empty' => false
        ));

        if (!empty($existing_terms) && !is_wp_error($existing_terms)) {
            return $existing_terms[0]->term_id;
        }

        // Create new category
        $term_data = wp_insert_term($name, 'product_cat', array(
            'parent' => $parent_id
        ));

        if (is_wp_error($term_data)) {
            error_log('Failed to create category: ' . $term_data->get_error_message());
            return null;
        }

        return $term_data['term_id'];
    }

    /**
     * Get or create shipping class
     */
    private function get_or_create_shipping_class($name) {
        if (!$name || trim($name) === '') return null;

        $name = trim($name);
        $slug = sanitize_title($name);

        // Check if shipping class exists
        $existing_terms = get_terms(array(
            'taxonomy' => 'product_shipping_class',
            'slug' => $slug,
            'hide_empty' => false
        ));

        if (!empty($existing_terms) && !is_wp_error($existing_terms)) {
            return $existing_terms[0]->slug;
        }

        // Create new shipping class
        $term_data = wp_insert_term($name, 'product_shipping_class', array(
            'slug' => $slug
        ));

        if (is_wp_error($term_data)) {
            error_log('Failed to create shipping class: ' . $term_data->get_error_message());
            return null;
        }

        return $slug;
    }

    /**
     * Generate SKU if missing
     */
    private function generate_sku_if_missing($sku, $name) {
        if ($sku && trim($sku)) return trim($sku);
        
        $prefix = $name ? substr(sanitize_title($name), 0, 8) : "auto";
        $timestamp = substr(strval(time()), -6);
        return $prefix . '-' . $timestamp;
    }

    /**
     * Check if media exists in WordPress
     */
    private function get_media_id_if_exists($image_url) {
        if (!$image_url) return null;

        // Fast path: match by the source URL we stored when the image was first
        // imported, so re-syncs reuse the existing attachment instead of
        // re-downloading externally hosted images on every sync.
        $by_source = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'meta_key'       => '_wc_gs_source_url',
            'meta_value'     => $image_url,
            'posts_per_page' => 1,
        ));
        if (!empty($by_source)) {
            return $by_source[0];
        }

        // Fallback (legacy): match by filename, then confirm the local URL.
        $filename = basename(parse_url($image_url, PHP_URL_PATH));

        // Search for existing media
        $existing_media = get_posts(array(
            'post_type' => 'attachment',
            'meta_key' => '_wp_attached_file',
            'meta_value' => $filename,
            'meta_compare' => 'LIKE',
            'posts_per_page' => 1
        ));

        if (!empty($existing_media)) {
            $attachment_url = wp_get_attachment_url($existing_media[0]->ID);
            if ($attachment_url === $image_url) {
                return $existing_media[0]->ID;
            }
        }

        return null;
    }

    /**
     * Format date for WooCommerce
     */
    private function format_date($date_string) {
        if (!$date_string) return null;
        
        try {
            $date = new DateTime($date_string);
            return $date->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Clean object by removing empty values
     */
    private function clean_object($obj) {
        $cleaned = array();
        
        foreach ($obj as $key => $value) {
            if ($value !== null && $value !== '') {
                if (is_array($value)) {
                    if (!empty($value)) {
                        // For associative arrays, clean recursively
                        if ($this->is_assoc($value)) {
                            $cleaned_nested = $this->clean_object($value);
                            if (!empty($cleaned_nested)) {
                                $cleaned[$key] = $cleaned_nested;
                            }
                        } else {
                            // For indexed arrays, keep if not empty
                            $cleaned[$key] = $value;
                        }
                    }
                } else {
                    $cleaned[$key] = $value;
                }
            }
        }
        
        return $cleaned;
    }

    /**
     * Check if array is associative
     */
    private function is_assoc($array) {
        if (!is_array($array)) return false;
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Validate product data
     */
    public function validate_product_data($product_data) {
        $errors = array();
        
        if (empty($product_data['name']) || strlen($product_data['name']) < 1) {
            $errors[] = "Product name is required";
        }
        
        if (!empty($product_data['name']) && strlen($product_data['name']) > 255) {
            $errors[] = "Product name too long (max 255 characters)";
        }
        
        if (!empty($product_data['regular_price']) && !is_numeric($product_data['regular_price'])) {
            $errors[] = "Regular price must be a valid number";
        }
        
        if (!empty($product_data['sale_price']) && $product_data['sale_price'] !== '' && !is_numeric($product_data['sale_price'])) {
            $errors[] = "Sale price must be a valid number";
        }
        
        if (isset($product_data['stock_quantity']) && !is_numeric($product_data['stock_quantity'])) {
            $errors[] = "Stock quantity must be a valid number";
        }
        
        if (!empty($product_data['weight']) && $product_data['weight'] !== '' && !is_numeric($product_data['weight'])) {
            $errors[] = "Weight must be a valid number";
        }
		
		// NEW: Add SKU validation
		if (!empty($product_data['sku'])) {
			$sku = $product_data['sku'];
			
			// Check SKU format
			if (strlen($sku) < 1) {
				$errors[] = "SKU cannot be empty";
			}
			
			// Check SKU uniqueness
			$existing_product_id = wc_get_product_id_by_sku($sku);
			if ($existing_product_id && $existing_product_id != ($product_data['id'] ?? null)) {
				$errors[] = 'SKU "' . $sku . '" already exists in product ID ' . $existing_product_id;
			}
		}
		
		// NEW: Add GTIN validation
		if (!empty($product_data['meta_data'])) {
			foreach ($product_data['meta_data'] as $meta) {
				if ($meta['key'] === '_global_unique_id' && !empty($meta['value'])) {
					$gtin = $meta['value'];
					
					// Check GTIN format
					$clean_gtin = preg_replace('/[\s\-]/', '', $gtin);
					if (!is_numeric($clean_gtin)) {
						$errors[] = 'GTIN "' . $gtin . '" must contain only numbers';
					} elseif (!in_array(strlen($clean_gtin), [8, 12, 13, 14])) {
						$errors[] = 'GTIN "' . $gtin . '" has invalid length (must be 8, 12, 13, or 14 digits)';
					}
					
					// Check GTIN uniqueness
					global $wpdb;
					$existing_product_id = $wpdb->get_var($wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} 
						 WHERE meta_key = '_global_unique_id' 
						 AND meta_value = %s 
						 AND post_id != %d
						 LIMIT 1",
						$gtin,
						$product_data['id'] ?? 0
					));
					
					if ($existing_product_id) {
						$errors[] = 'GTIN "' . $gtin . '" already exists in product ID ' . $existing_product_id;
					}
					break;
				}
			}
		}
		
		// Validate Low Stock Threshold
		if (!empty($product_data['low_stock_amount'])) {
			// Check if stock management is enabled (manage_stock is a boolean)
			if (empty($product_data['manage_stock'])) {
				$errors[] = "You cannot set Low Stock Threshold if Stock Management is not enabled";
			}
		}
        
        return array(
            'is_valid' => empty($errors),
            'errors' => $errors
        );
    }
}