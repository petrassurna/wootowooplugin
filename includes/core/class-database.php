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
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_product_id bigint(20) NOT NULL,
            sku varchar(100) DEFAULT NULL,
            product_data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_product_id (source_product_id),
            KEY sku (sku),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
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
            $result = $wpdb->replace(
                $table_name,
                array(
                    'source_product_id' => $product['id'],
                    'sku' => isset($product['sku']) ? $product['sku'] : null,
                    'product_data' => json_encode($product),
                    'updated_at' => current_time('mysql')
                ),
                array(
                    '%d',
                    '%s', 
                    '%s',
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
             WHERE JSON_EXTRACT(product_data, '$.type') = 'variable'",
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
    
    public function clear_products() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wootowoo_products';
        
        return $wpdb->query("TRUNCATE TABLE $table_name");
    }
}