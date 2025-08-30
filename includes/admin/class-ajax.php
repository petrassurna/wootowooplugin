<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooToWoo_Ajax {
    
    private static $instance = null;
    private $config;
    private $sync_service;
    private $api_client;
    private $uploader;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->config = WooToWoo_Config::get_instance();
        $this->sync_service = WooToWoo_Sync_Service::get_instance();
        $this->api_client = WooToWoo_API_Client::get_instance();
        $this->uploader = WooToWoo_Product_Uploader::get_instance();
        
        add_action('wp_ajax_wootowoo_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_wootowoo_synchronize', array($this, 'synchronize'));
        add_action('wp_ajax_wootowoo_get_site_url', array($this, 'get_site_url'));
        add_action('wp_ajax_wootowoo_sync_products', array($this, 'sync_products'));
        add_action('wp_ajax_wootowoo_terminate_sync', array($this, 'terminate_sync'));
        add_action('wp_ajax_wootowoo_restart_sync', array($this, 'restart_sync'));
        add_action('wp_ajax_wootowoo_get_variation_status', array($this, 'get_variation_status'));
        add_action('wp_ajax_wootowoo_sync_variations_batch', array($this, 'sync_variations_batch'));
        add_action('wp_ajax_wootowoo_sync_categories', array($this, 'sync_categories'));
        add_action('wp_ajax_wootowoo_validate_category_mapping', array($this, 'validate_category_mapping'));
        add_action('wp_ajax_wootowoo_force_update_categories', array($this, 'force_update_categories'));
        add_action('wp_ajax_wootowoo_get_upload_status', array($this, 'get_upload_status'));
        add_action('wp_ajax_wootowoo_upload_products_batch', array($this, 'upload_products_batch'));
    }

    public function test_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_test_connection')) {
            wp_die('Security check failed');
        }
        
        $website_url = isset($_POST['website_url']) ? sanitize_url($_POST['website_url']) : '';
        $consumer_key = isset($_POST['consumer_key']) ? sanitize_text_field($_POST['consumer_key']) : '';
        $consumer_secret = isset($_POST['consumer_secret']) ? sanitize_text_field($_POST['consumer_secret']) : '';
        
        $result = $this->api_client->test_connection($website_url, $consumer_key, $consumer_secret);
        
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
        
        if (!$this->config->has_config()) {
            wp_send_json_error('Configuration incomplete - please check settings');
        }
        
        $result = $this->sync_service->get_initial_product_count();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
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

    public function sync_products() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_sync_products')) {
            wp_die('Security check failed');
        }
        
        $requested_page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        
        $result = $this->sync_service->sync_products_page($requested_page);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function terminate_sync() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_terminate_sync')) {
            wp_die('Security check failed');
        }
        
        $result = $this->sync_service->terminate_sync();
        
        wp_send_json_success($result['message']);
    }

    public function restart_sync() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_restart_sync')) {
            wp_die('Security check failed');
        }
        
        $result = $this->sync_service->clear_all_products();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function get_variation_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_get_variation_status')) {
            wp_die('Security check failed');
        }
        
        $result = $this->sync_service->get_variation_sync_status();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function sync_variations_batch() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_sync_variations_batch')) {
            wp_die('Security check failed');
        }
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
        
        $result = $this->sync_service->sync_variations_batch($batch_size);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function sync_categories() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_sync_categories')) {
            wp_die('Security check failed');
        }
        
        $result = $this->sync_service->sync_categories_from_products();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function validate_category_mapping() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_validate_category_mapping')) {
            wp_die('Security check failed');
        }
        
        $result = $this->sync_service->validate_category_mapping_readiness();
        
        wp_send_json_success($result);
    }
    
    public function force_update_categories() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_force_update_categories')) {
            wp_die('Security check failed');
        }
        
        $result = $this->sync_service->force_update_product_categories();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function get_upload_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_get_upload_status')) {
            wp_die('Security check failed');
        }
        
        $result = $this->uploader->get_upload_status();
        
        wp_send_json_success($result);
    }
    
    public function upload_products_batch() {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_upload_products_batch')) {
            wp_die('Security check failed');
        }
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
        $force_upload = isset($_POST['force_upload']) && $_POST['force_upload'] === 'true';
        
        $result = $this->uploader->upload_products_batch($batch_size, $force_upload);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
}