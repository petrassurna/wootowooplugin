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
        add_action('wp_ajax_wootowoo_get_site_url', array($this, 'get_site_url'));
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
        // First, get total count by making a request with per_page=1 to get headers
        $products_url = rtrim($website_url, '/') . '/wp-json/wc/v3/products?per_page=1';
        
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
        
        // Get total count from X-WP-Total header
        $headers = wp_remote_retrieve_headers($response);
        $total_products = isset($headers['x-wp-total']) ? intval($headers['x-wp-total']) : 0;
        
        if ($total_products === 0) {
            // Fallback: try to get from response body if header not available
            $products = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($products)) {
                // If we only got 1 product but there might be more, we need to estimate
                $total_products = count($products);
            } else {
                return array('success' => false, 'message' => 'Invalid response from source site');
            }
        }
        
        // For now, just return success with total count - actual sync logic would be implemented here
        return array(
            'success' => true, 
            'message' => "{$total_products} products to read"
        );
    }
    
    public function get_site_url() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_get_site_url')) {
            wp_die('Security check failed');
        }
        
        $website_url = $this->config->get_website_url();
        
        if (empty($website_url)) {
            wp_send_json_error('No website URL configured');
        }
        
        wp_send_json_success($website_url);
    }
}