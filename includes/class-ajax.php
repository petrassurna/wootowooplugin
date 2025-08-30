<?php
if (!defined('ABSPATH')) {
    exit;
}


class WooToWoo_Ajax
{

    private static $instance = null;
    private $config;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->config = WooToWoo_Config::get_instance();
        add_action('wp_ajax_wootowoo_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_wootowoo_synchronize', array($this, 'synchronize'));
        add_action('wp_ajax_wootowoo_get_site_url', array($this, 'get_site_url'));
        add_action('wp_ajax_wootowoo_sync_products', array($this, 'sync_products'));
        add_action('wp_ajax_wootowoo_terminate_sync', array($this, 'terminate_sync'));
        add_action('wp_ajax_wootowoo_restart_sync', array($this, 'restart_sync'));
    }

    public function test_connection()
    {
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

    public function synchronize()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_synchronize')) {
            wp_die('Security check failed');
        }

        error_log("synchronize 1 ???");

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

    private function perform_synchronization($website_url, $consumer_key, $consumer_secret)
    {
        // First, get total count by making a request with per_page=1 to get headers
        $products_url = rtrim($website_url, '/') . '/wp-json/wc/v3/products?per_page=1&status=any';

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

        // Create database table
        $database = WooToWoo_Database::get_instance();
        $database->create_tables();

        return array(
            'success' => true,
            'message' => "{$total_products} products to read",
            'total_products' => $total_products
        );
    }

    public function get_site_url()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_get_site_url')) {
            wp_die('Security check failed');
        }

        $website_url = $this->config->get_website_url();

        if (empty($website_url)) {
            wp_send_json_error('No website URL configured');
        }

        wp_send_json_success($website_url);
    }

    public function sync_products()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_sync_products')) {
            wp_die('Security check failed');
        }

        $requested_page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = 10;

        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();

        if (empty($website_url) || empty($consumer_key) || empty($consumer_secret)) {
            wp_send_json_error('Configuration incomplete');
        }

        $database = WooToWoo_Database::get_instance();

        // Calculate resume point based on existing records
        if ($requested_page === 1) {
            // If starting fresh or resuming, calculate where to start
            $existing_count = $database->get_products_count();
            $calculated_page = max(1, floor($existing_count / $per_page) + 1);
            $page = $calculated_page;
            error_log("WooToWoo Resume: {$existing_count} products exist, starting from page {$page}");
        } else {
            // Continue with specific page requested
            $page = $requested_page;
        }

        $products_url = rtrim($website_url, '/') . '/wp-json/wc/v3/products';
        $products_url .= '?per_page=' . $per_page . '&page=' . $page . '&orderby=date&order=asc&status=any';


        error_log($products_url);



        $response = wp_remote_get($products_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)
            ),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch products: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wp_send_json_error('API request failed (HTTP ' . $response_code . ')');
        }

        $products = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($products)) {
            wp_send_json_error('Invalid response format');
        }

        // Get total count and calculate pages
        $headers = wp_remote_retrieve_headers($response);
        $total_products = isset($headers['x-wp-total']) ? intval($headers['x-wp-total']) : 0;
        $total_pages = isset($headers['x-wp-totalpages']) ? intval($headers['x-wp-totalpages']) : 1;

        // Store products in database
        $inserted_count = $database->insert_products($products);

        $new_existing_count = $database->get_products_count();

        // Debug logging
        error_log("WooToWoo Sync - Page {$page}: Fetched " . count($products) . " products, Total in DB: {$new_existing_count}");

        // More robust check: continue if we have more pages OR if we got products on this page
        $has_more = ($page < $total_pages) || (count($products) >= $per_page);

        wp_send_json_success(array(
            'page' => $page,
            'total_pages' => $total_pages,
            'total_products' => $total_products,
            'existing_count' => $new_existing_count,
            'inserted_count' => $inserted_count,
            'products_fetched' => count($products),
            'has_more' => $has_more
        ));
    }

    public function terminate_sync()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_terminate_sync')) {
            wp_die('Security check failed');
        }

        // Clear sync status or set termination flag
        delete_transient('wootowoo_sync_in_progress');

        wp_send_json_success('Synchronization terminated');
    }

    public function restart_sync()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'wootowoo_restart_sync')) {
            wp_die('Security check failed');
        }

        // Clear all products from database
        $database = WooToWoo_Database::get_instance();
        $result = $database->clear_products();

        if ($result !== false) {
            wp_send_json_success('Products cleared. Starting fresh synchronization...');
        } else {
            wp_send_json_error('Failed to clear products database');
        }
    }
}
