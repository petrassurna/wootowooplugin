<?php

/**
 * Plugin Name: WooToWoo
 * Description: Reliable WooCommerce product sync that recovers from interruptions automatically
 * Version: 1.0.0
 * Author: Petras Surna
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WOOTOWOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOOTOWOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOOTOWOO_PLUGIN_FILE', __FILE__);
define('WOOTOWOO_VERSION', '1.0.0');

// Include required files
require_once WOOTOWOO_PLUGIN_DIR . 'includes/core/class-config.php';
require_once WOOTOWOO_PLUGIN_DIR . 'includes/core/class-database.php';
require_once WOOTOWOO_PLUGIN_DIR . 'includes/api/class-api-client.php';
require_once WOOTOWOO_PLUGIN_DIR . 'includes/services/class-sync-service.php';
require_once WOOTOWOO_PLUGIN_DIR . 'includes/services/class-product-processor.php';
require_once WOOTOWOO_PLUGIN_DIR . 'includes/admin/class-admin.php';
require_once WOOTOWOO_PLUGIN_DIR . 'includes/admin/class-ajax.php';

/**
 * Main plugin class
 */
class WooToWoo
{

    private static $instance = null;


    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('wp_head', array($this, 'add_meta_comment'));
    }

    public function init()
    {
        // Initialize plugin components
        WooToWoo_Config::get_instance();
        WooToWoo_Database::get_instance();
        WooToWoo_Admin::get_instance();
        WooToWoo_Ajax::get_instance();
    }

    public function add_meta_comment()
    {
        echo '<!-- WooToWoo Plugin Active -->';
    }
}

// Initialize the plugin
WooToWoo::get_instance();
