<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooToWoo_Sync_Service {
    
    private static $instance = null;
    private $api_client;
    private $database;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api_client = WooToWoo_API_Client::get_instance();
        $this->database = WooToWoo_Database::get_instance();
    }
    
    public function get_initial_product_count() {
        // Create database table if needed
        $this->database->create_tables();
        
        $result = $this->api_client->get_product_count();
        
        if ($result['success']) {
            return array(
                'success' => true,
                'message' => "{$result['total_products']} products to read",
                'total_products' => $result['total_products']
            );
        }
        
        return $result;
    }
    
    public function sync_products_page($requested_page) {
        $per_page = 10;
        
        // Calculate resume point based on existing records
        if ($requested_page === 1) {
            $existing_count = $this->database->get_products_count();
            $calculated_page = max(1, floor($existing_count / $per_page) + 1);
            $page = $calculated_page;
            error_log("WooToWoo Resume: {$existing_count} products exist, starting from page {$page}");
        } else {
            $page = $requested_page;
        }
        
        // Fetch products from API
        $result = $this->api_client->get_products($page, $per_page);
        
        if (!$result['success']) {
            return $result;
        }
        
        $products = $result['products'];
        $total_products = $result['total_products'];
        $total_pages = $result['total_pages'];
        
        // Store products in database
        $inserted_count = $this->database->insert_products($products);
        $new_existing_count = $this->database->get_products_count();
        
        // Debug logging
        error_log("WooToWoo Sync - Page {$page}/{$total_pages}: Fetched " . count($products) . " products, Total in DB: {$new_existing_count}");
        
        // Log when we reach the end
        if (count($products) === 0) {
            error_log("WooToWoo: Page {$page} returned 0 products - reached end of available products");
            error_log("WooToWoo: API claims {$total_products} total products, but only {$new_existing_count} are accessible");
        }
        
        // More robust check: continue if we have more pages AND we got products on this page
        $has_more = ($page < $total_pages) && (count($products) > 0);
        
        return array(
            'success' => true,
            'page' => $page,
            'total_pages' => $total_pages,
            'total_products' => $total_products,
            'existing_count' => $new_existing_count,
            'inserted_count' => $inserted_count,
            'products_fetched' => count($products),
            'has_more' => $has_more,
            'failed_count' => max(0, $total_products - $new_existing_count)
        );
    }
    
    public function get_variation_sync_status() {
        $total_variable = $this->database->get_variable_products_count();
        $completed_variable = $this->database->get_completed_variable_products_count();
        
        return array(
            'success' => true,
            'total_variable_products' => $total_variable,
            'completed_variable_products' => $completed_variable,
            'remaining_variable_products' => $total_variable - $completed_variable,
            'has_variable_products' => $total_variable > 0,
            'variations_complete' => $total_variable === $completed_variable
        );
    }
    
    public function sync_variations_batch($batch_size = 5) {
        // Get variable products that need variations
        $variable_products = $this->database->get_variable_products_needing_variations($batch_size);
        
        if (empty($variable_products)) {
            return array(
                'success' => true, 
                'message' => 'No variable products need variation sync',
                'completed' => true,
                'processed_count' => 0
            );
        }
        
        $processed_count = 0;
        $error_count = 0;
        
        foreach ($variable_products as $product) {
            $product_data = json_decode($product['product_data'], true);
            $product_id = $product['source_product_id'];
            
            // Fetch variations for this product
            $result = $this->api_client->get_product_variations($product_id);
            
            if ($result['success']) {
                // Add variations to product data
                $product_data['variations'] = $result['variations'];
                
                // Update the database record with variations and mark as complete
                $updated = $this->database->update_product_with_variations($product_id, $product_data);
                
                if ($updated) {
                    $processed_count++;
                    error_log("WooToWoo: Updated product {$product_id} with " . count($result['variations']) . " variations");
                } else {
                    $error_count++;
                    error_log("WooToWoo: Failed to update product {$product_id} variations in database");
                }
            } else {
                $error_count++;
                error_log("WooToWoo: Failed to fetch variations for product {$product_id}: " . $result['message']);
            }
        }
        
        // Check if more work remains
        $remaining = $this->database->get_variable_products_needing_variations(1);
        $has_more = !empty($remaining);
        
        return array(
            'success' => true,
            'processed_count' => $processed_count,
            'error_count' => $error_count,
            'has_more' => $has_more,
            'completed' => !$has_more,
            'message' => "Processed {$processed_count} variable products. {$error_count} errors."
        );
    }
    
    public function clear_all_products() {
        $result = $this->database->clear_products();
        
        if ($result !== false) {
            return array('success' => true, 'message' => 'Products cleared. Starting fresh synchronization...');
        } else {
            return array('success' => false, 'message' => 'Failed to clear products database');
        }
    }
    
    public function terminate_sync() {
        // Clear any sync-related transients or flags
        delete_transient('wootowoo_sync_in_progress');
        
        return array('success' => true, 'message' => 'Synchronization terminated');
    }
    
    public function sync_categories() {
        // Get categories from source site
        $result = $this->api_client->get_categories(1, 100); // Fetch up to 100 categories per page
        
        if (!$result['success']) {
            return $result;
        }
        
        $categories = $result['categories'];
        $total_categories = $result['total_categories'];
        $categories_synced = 0;
        
        // Store source categories in database
        if (!empty($categories)) {
            $categories_synced = $this->database->insert_categories($categories);
        }
        
        // Create WordPress categories and map destination IDs
        $this->create_wordpress_categories();
        
        return array(
            'success' => true,
            'categories_synced' => $categories_synced,
            'total_categories' => $total_categories
        );
    }
    
    private function create_wordpress_categories() {
        // Get categories that need WordPress category creation
        $categories_to_create = $this->database->get_categories_needing_destination_id(50);
        
        foreach ($categories_to_create as $category_data) {
            $category_json = json_decode($category_data['category_data'], true);
            
            if (!$category_json) {
                continue;
            }
            
            // Prepare category data for WordPress
            $wp_category_data = array(
                'name' => $category_json['name'],
                'slug' => $category_json['slug'],
                'description' => isset($category_json['description']) ? $category_json['description'] : ''
            );
            
            // Handle parent category
            $parent_id = 0;
            if (!empty($category_data['parent_source_id'])) {
                // Find the destination ID for the parent category
                $parent_mapping = $this->database->get_category_mapping();
                if (isset($parent_mapping[$category_data['parent_source_id']])) {
                    $parent_id = $parent_mapping[$category_data['parent_source_id']]->destination_category_id;
                }
            }
            
            if ($parent_id > 0) {
                $wp_category_data['parent'] = $parent_id;
            }
            
            // Create WordPress product category term
            $term_result = wp_insert_term(
                $wp_category_data['name'],
                'product_cat', // WooCommerce category taxonomy
                $wp_category_data
            );
            
            if (!is_wp_error($term_result) && isset($term_result['term_id'])) {
                // Update our database with the destination ID
                $this->database->update_category_destination_id(
                    $category_data['source_category_id'], 
                    $term_result['term_id']
                );
                
                error_log("WooToWoo: Created category '{$wp_category_data['name']}' with ID {$term_result['term_id']}");
            } else {
                $error_message = is_wp_error($term_result) ? $term_result->get_error_message() : 'Unknown error';
                error_log("WooToWoo: Failed to create category '{$wp_category_data['name']}': {$error_message}");
            }
        }
    }
    
    public function get_category_mapping_for_products() {
        return $this->database->get_category_mapping();
    }
    
    public function sync_categories_from_products() {
        // Fetch ALL categories from the source site (not just those referenced in products)
        $categories_with_images = $this->fetch_all_categories();
        
        if (empty($categories_with_images)) {
            return array(
                'success' => true,
                'message' => 'No categories retrieved from API',
                'categories_processed' => 0
            );
        }
        
        // Store full category data in database
        $categories_stored = $this->database->insert_categories($categories_with_images);
        
        // Create/update WordPress categories and get destination IDs
        $categories_created = $this->create_or_update_wordpress_categories();
        
        // Update stored products with destination category IDs
        $products_updated = $this->update_products_with_destination_categories();
        
        return array(
            'success' => true,
            'message' => "Categories synced: {$categories_created} created/updated, {$products_updated} products updated",
            'categories_processed' => $categories_created,
            'products_updated' => $products_updated
        );
    }
    
    private function extract_category_ids_from_products() {
        global $wpdb;
        
        $products_table = $wpdb->prefix . 'wootowoo_products';
        $products = $wpdb->get_results("SELECT product_data FROM $products_table", ARRAY_A);
        
        $category_ids = array();
        
        foreach ($products as $product_row) {
            $product_data = json_decode($product_row['product_data'], true);
            
            if (isset($product_data['categories']) && is_array($product_data['categories'])) {
                foreach ($product_data['categories'] as $category) {
                    if (isset($category['id']) && !in_array($category['id'], $category_ids)) {
                        $category_ids[] = intval($category['id']);
                    }
                }
            }
        }
        
        error_log("WooToWoo: Extracted category IDs from products: " . json_encode($category_ids));
        return $category_ids;
    }
    
    private function fetch_all_categories() {
        $categories_with_images = array();
        $page = 1;
        $per_page = 100;
        
        error_log("WooToWoo: Starting to fetch ALL categories from source site");
        
        do {
            error_log("WooToWoo: Fetching categories page {$page}");
            
            // Fetch categories page by page (without specific IDs to get ALL categories)
            $result = $this->api_client->get_categories($page, $per_page);
            
            if ($result['success'] && !empty($result['categories'])) {
                error_log("WooToWoo: Page {$page} returned " . count($result['categories']) . " categories");
                $categories_with_images = array_merge($categories_with_images, $result['categories']);
                
                // Check if we have more pages
                $has_more = ($page < $result['total_pages']) && (count($result['categories']) === $per_page);
                $page++;
            } else {
                error_log("WooToWoo: Page {$page} failed or returned no categories");
                break;
            }
        } while (isset($has_more) && $has_more);
        
        error_log("WooToWoo: Total fetched " . count($categories_with_images) . " categories (including unused ones) from API");
        return $categories_with_images;
    }
    
    private function fetch_categories_by_ids($category_ids) {
        $categories_with_images = array();
        
        error_log("WooToWoo: Starting to fetch categories for IDs: " . json_encode($category_ids));
        
        // Fetch categories in batches to avoid URL length limits
        $batch_size = 50;
        $batches = array_chunk($category_ids, $batch_size);
        
        foreach ($batches as $batch_index => $batch) {
            error_log("WooToWoo: Fetching batch " . ($batch_index + 1) . " with IDs: " . json_encode($batch));
            
            $result = $this->api_client->get_categories(1, $batch_size, $batch);
            
            if ($result['success'] && !empty($result['categories'])) {
                error_log("WooToWoo: Batch " . ($batch_index + 1) . " returned " . count($result['categories']) . " categories");
                $categories_with_images = array_merge($categories_with_images, $result['categories']);
            } else {
                error_log("WooToWoo: Batch " . ($batch_index + 1) . " failed or returned no categories");
            }
        }
        
        error_log("WooToWoo: Total fetched " . count($categories_with_images) . " categories with full data from API");
        return $categories_with_images;
    }
    
    private function create_or_update_wordpress_categories() {
        $categories_to_process = $this->database->get_categories_needing_destination_id(100);
        $processed_count = 0;
        
        foreach ($categories_to_process as $category_data) {
            $category_json = json_decode($category_data['category_data'], true);
            
            if (!$category_json) {
                continue;
            }
            
            $slug = $category_json['slug'];
            
            // Check if category with this slug already exists
            $existing_term = get_term_by('slug', $slug, 'product_cat');
            
            if ($existing_term) {
                // Update existing category
                $wp_category_data = array(
                    'name' => $category_json['name'],
                    'description' => isset($category_json['description']) ? $category_json['description'] : ''
                );
                
                // Handle parent category
                if (!empty($category_data['parent_source_id'])) {
                    $parent_mapping = $this->database->get_category_mapping();
                    if (isset($parent_mapping[$category_data['parent_source_id']])) {
                        $wp_category_data['parent'] = $parent_mapping[$category_data['parent_source_id']]->destination_category_id;
                    }
                }
                
                $term_result = wp_update_term($existing_term->term_id, 'product_cat', $wp_category_data);
                
                if (!is_wp_error($term_result)) {
                    // Handle category image
                    $this->process_category_image($existing_term->term_id, $category_json);
                    
                    // Handle category display type
                    $this->process_category_display_type($existing_term->term_id, $category_json);
                    
                    // Update our database with the existing term ID
                    $this->database->update_category_destination_id(
                        $category_data['source_category_id'], 
                        $existing_term->term_id
                    );
                    $processed_count++;
                    error_log("WooToWoo: Updated existing category '{$category_json['name']}' with ID {$existing_term->term_id}");
                }
            } else {
                // Create new category
                $wp_category_data = array(
                    'slug' => $slug,
                    'description' => isset($category_json['description']) ? $category_json['description'] : ''
                );
                
                // Handle parent category
                if (!empty($category_data['parent_source_id'])) {
                    $parent_mapping = $this->database->get_category_mapping();
                    if (isset($parent_mapping[$category_data['parent_source_id']])) {
                        $wp_category_data['parent'] = $parent_mapping[$category_data['parent_source_id']]->destination_category_id;
                    }
                }
                
                $term_result = wp_insert_term($category_json['name'], 'product_cat', $wp_category_data);
                
                if (!is_wp_error($term_result) && isset($term_result['term_id'])) {
                    // Handle category image
                    $this->process_category_image($term_result['term_id'], $category_json);
                    
                    // Handle category display type
                    $this->process_category_display_type($term_result['term_id'], $category_json);
                    
                    // Update our database with the new term ID
                    $this->database->update_category_destination_id(
                        $category_data['source_category_id'], 
                        $term_result['term_id']
                    );
                    $processed_count++;
                    error_log("WooToWoo: Created new category '{$category_json['name']}' with ID {$term_result['term_id']}");
                }
            }
        }
        
        return $processed_count;
    }
    
    private function update_products_with_destination_categories() {
        global $wpdb;
        
        $products_table = $wpdb->prefix . 'wootowoo_products';
        $category_mapping = $this->database->get_category_mapping();
        $processor = WooToWoo_Product_Processor::get_instance();
        
        $products = $wpdb->get_results("SELECT id, source_product_id, product_data FROM $products_table", ARRAY_A);
        $updated_count = 0;
        $unmapped_count = 0;
        $error_count = 0;
        
        foreach ($products as $product_row) {
            $product_data = json_decode($product_row['product_data'], true);
            
            if (!$product_data) {
                error_log("WooToWoo: Invalid product data for product ID {$product_row['source_product_id']}");
                $error_count++;
                continue;
            }
            
            // Remap category IDs
            $remap_result = $processor->remap_product_categories_detailed($product_data, $category_mapping);
            $updated_product = $remap_result['product'];
            
            // Track unmapped categories
            if ($remap_result['unmapped_count'] > 0) {
                $unmapped_count += $remap_result['unmapped_count'];
                error_log("WooToWoo: Product {$product_row['source_product_id']} has {$remap_result['unmapped_count']} unmapped categories");
            }
            
            // Check if any categories were actually remapped
            if (json_encode($updated_product) !== json_encode($product_data)) {
                // Update the product in database
                $result = $wpdb->update(
                    $products_table,
                    array(
                        'product_data' => json_encode($updated_product),
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $product_row['id']),
                    array('%s', '%s'),
                    array('%d')
                );
                
                if ($result !== false) {
                    $updated_count++;
                    error_log("WooToWoo: Updated product {$product_row['source_product_id']} with remapped category IDs");
                } else {
                    error_log("WooToWoo: Failed to update product {$product_row['source_product_id']}: " . $wpdb->last_error);
                    $error_count++;
                }
            }
        }
        
        error_log("WooToWoo: Category remapping complete - {$updated_count} products updated, {$unmapped_count} unmapped categories found, {$error_count} errors");
        
        return array(
            'updated_count' => $updated_count,
            'unmapped_count' => $unmapped_count,
            'error_count' => $error_count,
            'total_processed' => count($products)
        );
    }
    
    public function validate_category_mapping_readiness() {
        // Check if all categories used by products have been synced and mapped
        $missing_categories = $this->database->ensure_all_product_categories_exist();
        $unmapped_categories = $this->database->get_unmapped_categories_count();
        $products_with_unmapped_categories = $this->database->get_products_with_unmapped_categories_count();
        
        return array(
            'is_ready' => empty($missing_categories) && $unmapped_categories === 0,
            'missing_categories' => $missing_categories,
            'unmapped_categories' => $unmapped_categories,
            'products_with_unmapped_categories' => $products_with_unmapped_categories,
            'all_categories_mapped' => $this->database->are_all_product_categories_mapped()
        );
    }
    
    public function force_update_product_categories() {
        // First ensure all categories from products are synced
        $this->sync_categories_from_products();
        
        // Then update all products with destination category IDs
        $result = $this->update_products_with_destination_categories();
        
        // Validate the update was successful
        $validation = $this->validate_category_mapping_readiness();
        
        return array(
            'success' => $validation['is_ready'],
            'update_results' => $result,
            'validation' => $validation,
            'message' => $validation['is_ready'] ? 
                "All product categories successfully updated. {$result['updated_count']} products updated." :
                "Category mapping incomplete. {$validation['unmapped_categories']} categories still unmapped."
        );
    }
    
    private function process_category_image($term_id, $category_data) {
        error_log("WooToWoo: Processing category image for term {$term_id}");
        error_log("WooToWoo: Category data: " . json_encode($category_data));
        
        // Check if category has an image
        if (!isset($category_data['image'])) {
            error_log("WooToWoo: No 'image' key found in category data");
            return false;
        }
        
        if (empty($category_data['image']['src'])) {
            error_log("WooToWoo: Empty image src in category data");
            return false;
        }
        
        $image_url = $category_data['image']['src'];
        $image_alt = isset($category_data['image']['alt']) ? $category_data['image']['alt'] : $category_data['name'];
        
        error_log("WooToWoo: Attempting to download image: {$image_url}");
        
        // Download and import the image
        $attachment_id = $this->download_and_import_image($image_url, $image_alt);
        
        if ($attachment_id) {
            // Set as category thumbnail (WooCommerce uses 'thumbnail_id' meta)
            $result = update_term_meta($term_id, 'thumbnail_id', $attachment_id);
            error_log("WooToWoo: Set category image for term {$term_id}, attachment {$attachment_id}, result: " . ($result ? 'success' : 'failed'));
            return true;
        } else {
            error_log("WooToWoo: Failed to get attachment ID for category image");
        }
        
        return false;
    }
    
    private function download_and_import_image($image_url, $alt_text = '') {
        error_log("WooToWoo: Starting image download for URL: {$image_url}");
        
        // Check if image already exists by URL
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_image_url' AND meta_value = %s LIMIT 1",
            $image_url
        ));
        
        if ($existing) {
            error_log("WooToWoo: Image already exists with ID {$existing}");
            return $existing;
        }
        
        // Include WordPress media functions
        if (!function_exists('media_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Download the image
        $temp_file = download_url($image_url);
        
        if (is_wp_error($temp_file)) {
            error_log("WooToWoo: Failed to download image {$image_url}: " . $temp_file->get_error_message());
            return false;
        }
        
        // Get file info
        $file_info = pathinfo($image_url);
        $filename = isset($file_info['basename']) ? sanitize_file_name($file_info['basename']) : 'category-image.jpg';
        
        // Prepare file array for wp_handle_sideload
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $temp_file,
            'error' => 0,
            'size' => filesize($temp_file)
        );
        
        // Import the image
        $attachment_id = media_handle_sideload($file_array, 0, null);
        
        // Clean up temp file
        if (!is_wp_error($temp_file)) {
            @unlink($temp_file);
        }
        
        if (is_wp_error($attachment_id)) {
            error_log("WooToWoo: Failed to import image: " . $attachment_id->get_error_message());
            return false;
        }
        
        // Set alt text
        if ($alt_text) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }
        
        // Store source URL for duplicate detection
        update_post_meta($attachment_id, '_source_image_url', $image_url);
        
        error_log("WooToWoo: Successfully imported category image {$filename} with ID {$attachment_id}");
        return $attachment_id;
    }
    
    private function process_category_display_type($term_id, $category_data) {
        error_log("WooToWoo: Processing category display type for term {$term_id}");
        
        // Check if category has display type data
        if (!isset($category_data['display'])) {
            error_log("WooToWoo: No 'display' field found in category data");
            return false;
        }
        
        $display_type = $category_data['display'];
        
        // Validate display type (WooCommerce accepts: default, products, subcategories, both)
        $valid_display_types = array('default', 'products', 'subcategories', 'both');
        if (!in_array($display_type, $valid_display_types)) {
            error_log("WooToWoo: Invalid display type '{$display_type}' for category term {$term_id}");
            return false;
        }
        
        // Set the display type using WooCommerce term meta
        $result = update_term_meta($term_id, 'display_type', $display_type);
        
        error_log("WooToWoo: Set category display type for term {$term_id} to '{$display_type}', result: " . ($result ? 'success' : 'failed'));
        return $result !== false;
    }
}