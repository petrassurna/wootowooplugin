<?php
if (!defined('ABSPATH')) {
    exit;
}


class WooToWoo_Ajax {
    
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
        add_action('wp_ajax_wootowoo_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_wootowoo_synchronize', array($this, 'synchronize'));
    }
    
    public function test_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_test_connection')) {
            wp_die('Security check failed');
        }
        
        $website_url = isset($_POST['website_url']) ? sanitize_url($_POST['website_url']) : '';
        $consumer_key = isset($_POST['consumer_key']) ? sanitize_text_field($_POST['consumer_key']) : '';
        $consumer_secret = isset($_POST['consumer_secret']) ? sanitize_text_field($_POST['consumer_secret']) : '';
        
        $result = $this->config->test_connection($website_url, $consumer_key, $consumer_secret);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function synchronize() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_synchronize')) {
            wp_die('Security check failed');
        }
        
        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        
        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            wp_send_json_error('Configuration incomplete - please check settings');
        }
        
        $result = $this->perform_synchronization($website_url, $consumer_key, $consumer_secret);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    private function perform_synchronization($website_url, $consumer_key, $consumer_secret) {
        $products_url = rtrim($website_url, '/') . '/wp-json/wc/v3/products';
        
        $response = wp_remote_get($products_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
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
        
        $products = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($products)) {
            return array('success' => false, 'message' => 'Invalid response from source site');
        }
        
        $product_count = count($products);
        
        // For now, just return success with count - actual sync logic would be implemented here
        return array(
            'success' => true, 
            'message' => "{$product_count} products to read"
        );
    }
}