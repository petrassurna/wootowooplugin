<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooToWoo_API_Client {
    
    private static $instance = null;
    private $config;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->config = WooToWoo_Config::get_instance();
    }
    
    private function get_auth_header() {
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        
        return 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret);
    }
    
    private function get_base_url() {
        return rtrim($this->config->get_website_url(), '/') . '/wp-json/wc/v3';
    }
    
    public function test_connection($website_url = null, $consumer_key = null, $consumer_secret = null) {
        // Allow override for connection testing with unsaved credentials
        if ($website_url && $consumer_key && $consumer_secret) {
            $auth_header = 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret);
            $base_url = rtrim($website_url, '/') . '/wp-json/wc/v3';
        } else {
            $auth_header = $this->get_auth_header();
            $base_url = $this->get_base_url();
        }
        
        $test_url = $base_url . '/products?per_page=1';
        
        $response = wp_remote_get($test_url, array(
            'headers' => array(
                'Authorization' => $auth_header
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'Connection failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            return array('success' => true, 'message' => 'Success - connection valid');
        } else {
            return array('success' => false, 'message' => 'Connection invalid (HTTP ' . $response_code . ')');
        }
    }
    
    public function get_product_count() {
        $url = $this->get_base_url() . '/products?per_page=1&status=any';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header()
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'Failed to connect: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return array('success' => false, 'message' => 'API request failed (HTTP ' . $response_code . ')');
        }
        
        $headers = wp_remote_retrieve_headers($response);
        $total_products = isset($headers['x-wp-total']) ? intval($headers['x-wp-total']) : 0;
        
        if ($total_products === 0) {
            // Fallback: try to get from response body if header not available
            $products = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($products)) {
                $total_products = count($products);
            } else {
                return array('success' => false, 'message' => 'Invalid response from source site');
            }
        }
        
        return array(
            'success' => true,
            'total_products' => $total_products
        );
    }
    
    public function get_products($page, $per_page = 10) {
        $url = $this->get_base_url() . '/products';
        $url .= '?per_page=' . $per_page . '&page=' . $page . '&orderby=date&order=asc&status=any';
        
        error_log($url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header()
            ),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'Failed to fetch products: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return array('success' => false, 'message' => 'API request failed (HTTP ' . $response_code . ')');
        }
        
        $products = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($products)) {
            return array('success' => false, 'message' => 'Invalid response format');
        }
        
        // Get pagination info from headers
        $headers = wp_remote_retrieve_headers($response);
        $total_products = isset($headers['x-wp-total']) ? intval($headers['x-wp-total']) : 0;
        $total_pages = isset($headers['x-wp-totalpages']) ? intval($headers['x-wp-totalpages']) : 1;
        
        return array(
            'success' => true,
            'products' => $products,
            'total_products' => $total_products,
            'total_pages' => $total_pages
        );
    }
    
    public function get_product_variations($product_id) {
        $url = $this->get_base_url() . "/products/{$product_id}/variations";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header()
            ),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'Failed to fetch variations: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return array('success' => false, 'message' => 'API request failed (HTTP ' . $response_code . ')');
        }
        
        $variations = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($variations)) {
            return array('success' => false, 'message' => 'Invalid response format');
        }
        
        return array(
            'success' => true,
            'variations' => $variations
        );
    }
}