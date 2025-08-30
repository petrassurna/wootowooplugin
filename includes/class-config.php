<?php
if (!defined('ABSPATH')) {
    exit;
}


class WooToWoo_Config {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'handle_config_save'));
    }
    
    public function handle_config_save() {
        if (isset($_POST['wootowoo_save_config']) && wp_verify_nonce($_POST['wootowoo_nonce'], 'wootowoo_save_config')) {
            $website_url = sanitize_url($_POST['website_url']);
            $consumer_key = sanitize_text_field($_POST['consumer_key']);
            $consumer_secret = sanitize_text_field($_POST['consumer_secret']);
            
            $errors = array();
            
            if (empty($website_url)) {
                $errors[] = 'Website address is required.';
            } elseif (!filter_var($website_url, FILTER_VALIDATE_URL)) {
                $errors[] = 'Please enter a valid website address.';
            }
            
            if (empty($consumer_key)) {
                $errors[] = 'Consumer key is required.';
            }
            
            if (empty($consumer_secret)) {
                $errors[] = 'Consumer secret is required.';
            }
            
            if (empty($errors)) {
                update_option('wootowoo_website_url', $website_url);
                update_option('wootowoo_consumer_key', $consumer_key);
                update_option('wootowoo_consumer_secret', $consumer_secret);
                add_action('admin_notices', array($this, 'show_success_notice'));
            } else {
                add_action('admin_notices', function() use ($errors) {
                    foreach ($errors as $error) {
                        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
                    }
                });
            }
        }
    }
    
    public function show_success_notice() {
        echo '<div class="notice notice-success is-dismissible"><p>Configuration saved successfully!</p></div>';
    }
    
    public function get_website_url() {
        return get_option('wootowoo_website_url', '');
    }
    
    public function get_consumer_key() {
        return get_option('wootowoo_consumer_key', '');
    }
    
    public function get_consumer_secret() {
        return get_option('wootowoo_consumer_secret', '');
    }
    
    public function has_config() {
        return !empty($this->get_website_url()) && 
               !empty($this->get_consumer_key()) && 
               !empty($this->get_consumer_secret());
    }
    
    public function test_connection($website_url, $consumer_key, $consumer_secret) {
        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            return array('success' => false, 'message' => 'Please enter all configuration values');
        }
        
        if (!filter_var($website_url, FILTER_VALIDATE_URL)) {
            return array('success' => false, 'message' => 'Please enter a valid website address');
        }
        
        $test_url = rtrim($website_url, '/') . '/wp-json/wc/v3/system_status';
        
        $response = wp_remote_get($test_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
            ),
            'timeout' => 15
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
}