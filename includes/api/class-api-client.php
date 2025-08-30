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
    
    public function test_connection() {
        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        
        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            return array('success' => false, 'message' => 'Missing API credentials');
        }
        
        $url = trailingslashit($website_url) . 'wp-json/wc/v3/products';
        
        $response = wp_remote_get($url . '?per_page=1', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return array('success' => false, 'message' => 'HTTP ' . $status_code);
        }
        
        return array('success' => true, 'message' => 'Connection successful');
    }
    
    public function get_product_count() {
        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        
        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            return array('success' => false, 'message' => 'Missing API credentials');
        }
        
        $url = trailingslashit($website_url) . 'wp-json/wc/v3/products';
        
        $response = wp_remote_get($url . '?per_page=1', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $headers = wp_remote_retrieve_headers($response);
        $total_products = isset($headers['x-wp-total']) ? (int) $headers['x-wp-total'] : 0;
        
        return array('success' => true, 'total_products' => $total_products);
    }
    
    public function get_products($page = 1, $per_page = 10) {
        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        
        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            return array('success' => false, 'message' => 'Missing API credentials');
        }
        
        $url = trailingslashit($website_url) . 'wp-json/wc/v3/products';
        
        $response = wp_remote_get($url . '?per_page=' . $per_page . '&page=' . $page, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return array('success' => false, 'message' => 'HTTP ' . $status_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        $products = json_decode($body, true);
        
        if (!is_array($products)) {
            return array('success' => false, 'message' => 'Invalid response format');
        }
        
        $headers = wp_remote_retrieve_headers($response);
        $total_products = isset($headers['x-wp-total']) ? (int) $headers['x-wp-total'] : 0;
        $total_pages = isset($headers['x-wp-totalpages']) ? (int) $headers['x-wp-totalpages'] : 1;
        
        return array(
            'success' => true,
            'products' => $products,
            'total_products' => $total_products,
            'total_pages' => $total_pages,
            'current_page' => $page
        );
    }
    
    public function get_product_variations($product_id) {
        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        
        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            return array('success' => false, 'message' => 'Missing API credentials');
        }
        
        $url = trailingslashit($website_url) . 'wp-json/wc/v3/products/' . $product_id . '/variations';
        
        $response = wp_remote_get($url . '?per_page=100', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return array('success' => false, 'message' => 'HTTP ' . $status_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        $variations = json_decode($body, true);
        
        if (!is_array($variations)) {
            return array('success' => false, 'message' => 'Invalid variations response');
        }
        
        return array('success' => true, 'variations' => $variations);
    }
    
    public function get_categories($page = 1, $per_page = 100) {
        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        
        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            return array('success' => false, 'message' => 'Missing API credentials');
        }
        
        $url = trailingslashit($website_url) . 'wp-json/wc/v3/products/categories';
        
        $response = wp_remote_get($url . '?per_page=' . $per_page . '&page=' . $page, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $categories = json_decode($body, true);
        
        return array('success' => true, 'categories' => $categories);
    }
}