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
            'has_more' => $has_more
        );
    }
    
    public function sync_variations() {
        // Get all variable products from database
        $variable_products = $this->database->get_variable_products();
        
        if (empty($variable_products)) {
            return array('success' => true, 'message' => 'No variable products found');
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
                
                // Update the database record
                $updated = $this->database->update_product_variations($product_id, $product_data);
                
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
        
        return array(
            'success' => true,
            'message' => "Updated {$processed_count} variable products with variations. {$error_count} errors."
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
}