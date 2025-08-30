<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooToWoo_Product_Processor {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize processor
    }
    
    public function validate_product($product) {
        // Validate product data structure
        if (!is_array($product)) {
            return false;
        }
        
        if (!isset($product['id']) || !is_numeric($product['id'])) {
            return false;
        }
        
        return true;
    }
    
    public function sanitize_product($product) {
        // Sanitize product data
        $sanitized = array();
        
        // Required fields
        $sanitized['id'] = intval($product['id']);
        $sanitized['name'] = isset($product['name']) ? sanitize_text_field($product['name']) : '';
        $sanitized['type'] = isset($product['type']) ? sanitize_text_field($product['type']) : 'simple';
        $sanitized['sku'] = isset($product['sku']) ? sanitize_text_field($product['sku']) : '';
        
        // Optional fields - preserve original structure for most data
        $preserve_fields = array(
            'slug', 'status', 'featured', 'catalog_visibility', 'description',
            'short_description', 'price', 'regular_price', 'sale_price',
            'date_created', 'date_modified', 'weight', 'dimensions',
            'categories', 'tags', 'images', 'attributes', 'variations',
            'grouped_products', 'menu_order', 'meta_data'
        );
        
        foreach ($preserve_fields as $field) {
            if (isset($product[$field])) {
                $sanitized[$field] = $product[$field];
            }
        }
        
        return $sanitized;
    }
    
    public function process_products($products) {
        $processed = array();
        
        foreach ($products as $product) {
            if ($this->validate_product($product)) {
                $processed[] = $this->sanitize_product($product);
            } else {
                error_log("WooToWoo: Invalid product data, skipping product: " . print_r($product, true));
            }
        }
        
        return $processed;
    }
    
    public function merge_variations($product, $variations) {
        // Merge variations into product data
        if (!is_array($variations)) {
            return $product;
        }
        
        // Sanitize variations
        $sanitized_variations = array();
        foreach ($variations as $variation) {
            if ($this->validate_product($variation)) {
                $sanitized_variations[] = $this->sanitize_product($variation);
            }
        }
        
        $product['variations'] = $sanitized_variations;
        
        return $product;
    }
    
    public function extract_categories($product) {
        // Extract category information for future category sync
        $categories = array();
        
        if (isset($product['categories']) && is_array($product['categories'])) {
            foreach ($product['categories'] as $category) {
                if (isset($category['id']) && isset($category['slug'])) {
                    $categories[] = array(
                        'id' => intval($category['id']),
                        'slug' => sanitize_title($category['slug']),
                        'name' => isset($category['name']) ? sanitize_text_field($category['name']) : ''
                    );
                }
            }
        }
        
        return $categories;
    }
    
    public function remap_product_categories($product, $category_mapping = null) {
        // Remap product category IDs from source to destination
        if (!isset($product['categories']) || !is_array($product['categories'])) {
            return $product;
        }
        
        // Get category mapping if not provided
        if ($category_mapping === null) {
            $sync_service = WooToWoo_Sync_Service::get_instance();
            $category_mapping = $sync_service->get_category_mapping_for_products();
        }
        
        // Remap category IDs
        $remapped_categories = array();
        foreach ($product['categories'] as $category) {
            if (isset($category['id']) && isset($category_mapping[$category['id']])) {
                // Update with destination ID
                $category['id'] = $category_mapping[$category['id']]->destination_category_id;
                $remapped_categories[] = $category;
            } else {
                // Keep original if no mapping found (will be handled by import process)
                $remapped_categories[] = $category;
                error_log("WooToWoo: No destination mapping found for source category ID {$category['id']}");
            }
        }
        
        $product['categories'] = $remapped_categories;
        return $product;
    }
    
    public function prepare_for_import($product, $category_mapping = null) {
        // Remap categories before preparing for import
        $product = $this->remap_product_categories($product, $category_mapping);
        
        // Prepare product data for local import (future use)
        // This would transform remote product data to local format
        
        $import_data = array(
            'post_title' => $product['name'],
            'post_content' => isset($product['description']) ? $product['description'] : '',
            'post_excerpt' => isset($product['short_description']) ? $product['short_description'] : '',
            'post_status' => 'publish',
            'post_type' => 'product',
            'meta_input' => array(
                '_sku' => $product['sku'],
                '_regular_price' => isset($product['regular_price']) ? $product['regular_price'] : '',
                '_sale_price' => isset($product['sale_price']) ? $product['sale_price'] : '',
                '_weight' => isset($product['weight']) ? $product['weight'] : '',
                '_wootowoo_source_id' => $product['id']
            )
        );
        
        // Add category terms if available
        if (isset($product['categories']) && is_array($product['categories'])) {
            $category_ids = array();
            foreach ($product['categories'] as $category) {
                if (isset($category['id']) && is_numeric($category['id'])) {
                    $category_ids[] = intval($category['id']);
                }
            }
            if (!empty($category_ids)) {
                $import_data['tax_input'] = array(
                    'product_cat' => $category_ids
                );
            }
        }
        
        return $import_data;
    }
}