<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooToWoo_Database {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(WOOTOWOO_PLUGIN_FILE, array($this, 'create_tables'));
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create products table
        $products_table = $wpdb->prefix . 'wootowoo_products';
        $sql_products = "CREATE TABLE $products_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_product_id bigint(20) NOT NULL,
            sku varchar(100) DEFAULT NULL,
            product_data longtext NOT NULL,
            synchronised tinyint(1) DEFAULT 0,
            allVariationsObtained tinyint(1) DEFAULT 0,
            isVariableProduct tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_product_id (source_product_id),
            KEY sku (sku),
            KEY created_at (created_at),
            KEY synchronised (synchronised),
            KEY allVariationsObtained (allVariationsObtained),
            KEY isVariableProduct (isVariableProduct)
        ) $charset_collate;";
        
        // Create categories table
        $categories_table = $wpdb->prefix . 'wootowoo_categories';
        $sql_categories = "CREATE TABLE $categories_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            slug varchar(200) NOT NULL,
            source_category_id bigint(20) NOT NULL,
            destination_category_id bigint(20) DEFAULT NULL,
            category_data longtext NOT NULL,
            parent_source_id bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_category_id (source_category_id),
            KEY slug (slug),
            KEY parent_source_id (parent_source_id),
            KEY destination_category_id (destination_category_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_products);
        dbDelta($sql_categories);
    }
    
    public function get_products_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }
    
    public function get_last_synced_product_id() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return (int) $wpdb->get_var("SELECT MAX(source_product_id) FROM $table_name");
    }
    
    public function insert_products($products) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        $inserted_count = 0;
        $failed_count = 0;
        
        foreach ($products as $product) {
            // Store products with original source category IDs for now
            // Categories will be remapped later after category sync
            
            // Check if product is variable (has type 'variable' or has variations)
            $is_variable = false;
            if (isset($product['type']) && $product['type'] === 'variable') {
                $is_variable = true;
            } elseif (isset($product['variations']) && !empty($product['variations'])) {
                $is_variable = true;
            }
            
            $result = $wpdb->replace(
                $table_name,
                array(
                    'source_product_id' => $product['id'],
                    'sku' => isset($product['sku']) ? $product['sku'] : null,
                    'product_data' => json_encode($product),
                    'isVariableProduct' => $is_variable ? 1 : 0,
                    'updated_at' => current_time('mysql')
                ),
                array(
                    '%d',
                    '%s', 
                    '%s',
                    '%d',
                    '%s'
                )
            );
            
            if ($result !== false) {
                $inserted_count++;
            } else {
                $failed_count++;
                error_log("WooToWoo: Failed to insert product ID {$product['id']}: " . $wpdb->last_error);
            }
        }
        
        if ($failed_count > 0) {
            error_log("WooToWoo: {$failed_count} products failed to insert in this batch");
        }
        
        return $inserted_count;
    }
    
    public function get_product_id_gaps() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        // Get count of distinct product IDs vs total count
        $distinct_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT source_product_id) FROM $table_name");
        $total_count = $this->get_products_count();
        
        return array(
            'distinct_ids' => $distinct_count,
            'total_records' => $total_count,
            'duplicates' => $total_count - $distinct_count
        );
    }
    
    public function get_variable_products() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return $wpdb->get_results(
            "SELECT source_product_id, product_data FROM $table_name 
             WHERE isVariableProduct = 1",
            ARRAY_A
        );
    }
    
    public function get_variable_products_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE isVariableProduct = 1");
    }
    
    public function get_completed_variable_products_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE isVariableProduct = 1 AND allVariationsObtained = 1");
    }
    
    public function get_variable_products_needing_variations($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_product_id, product_data FROM $table_name 
                 WHERE isVariableProduct = 1 AND allVariationsObtained = 0 
                 ORDER BY id ASC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
    
    public function update_product_variations($product_id, $updated_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        $result = $wpdb->update(
            $table_name,
            array(
                'product_data' => json_encode($updated_data),
                'updated_at' => current_time('mysql')
            ),
            array('source_product_id' => $product_id),
            array('%s', '%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    public function update_product_with_variations($product_id, $updated_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        $result = $wpdb->update(
            $table_name,
            array(
                'product_data' => json_encode($updated_data),
                'allVariationsObtained' => 1,
                'updated_at' => current_time('mysql')
            ),
            array('source_product_id' => $product_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    public function clear_products() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return $wpdb->query("TRUNCATE TABLE $table_name");
    }
    
    // Category management methods
    public function insert_categories($categories) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_categories';
        $inserted_count = 0;
        $failed_count = 0;
        
        foreach ($categories as $category) {
            $result = $wpdb->replace(
                $table_name,
                array(
                    'source_category_id' => $category['id'],
                    'slug' => $category['slug'],
                    'category_data' => json_encode($category),
                    'parent_source_id' => isset($category['parent']) ? $category['parent'] : null,
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%d', '%s')
            );
            
            if ($result !== false) {
                $inserted_count++;
            } else {
                $failed_count++;
                error_log("WooToWoo: Failed to insert category ID {$category['id']}: " . $wpdb->last_error);
            }
        }
        
        return $inserted_count;
    }
    
    public function get_categories_count() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_categories';
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }
    
    public function get_categories_needing_destination_id($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_categories';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                 WHERE destination_category_id IS NULL 
                 ORDER BY parent_source_id ASC, id ASC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
    
    public function update_category_destination_id($source_id, $destination_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_categories';
        
        $result = $wpdb->update(
            $table_name,
            array(
                'destination_category_id' => $destination_id,
                'updated_at' => current_time('mysql')
            ),
            array('source_category_id' => $source_id),
            array('%d', '%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    public function get_category_mapping() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_categories';
        
        return $wpdb->get_results(
            "SELECT source_category_id, destination_category_id 
             FROM $table_name 
             WHERE destination_category_id IS NOT NULL",
            OBJECT_K
        );
    }
    
    public function clear_categories() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_categories';
        
        return $wpdb->query("TRUNCATE TABLE $table_name");
    }
    
    public function is_sync_complete() {
        // Check if sync is fully complete (products + variations + categories)
        
        // 1. Must have products
        $product_count = $this->get_products_count();
        if ($product_count === 0) {
            return false;
        }
        
        // 2. All variable products must have variations synced
        $variable_count = $this->get_variable_products_count();
        $completed_variations = $this->get_completed_variable_products_count();
        if ($variable_count > $completed_variations) {
            return false;
        }
        
        // 3. All categories used by products must have destination IDs
        $categories_needing_mapping = $this->get_categories_needing_destination_id(1);
        if (!empty($categories_needing_mapping)) {
            return false;
        }
        
        return true;
    }
    
    public function get_sync_status() {
        $product_count = $this->get_products_count();
        $variable_count = $this->get_variable_products_count();
        $completed_variations = $this->get_completed_variable_products_count();
        $category_count = $this->get_categories_count();
        $categories_mapped = count($this->get_category_mapping());
        
        return array(
            'products' => $product_count,
            'variable_products' => $variable_count,
            'completed_variations' => $completed_variations,
            'categories' => $category_count,
            'categories_mapped' => $categories_mapped,
            'is_complete' => $this->is_sync_complete()
        );
    }
}