<?php
if (!defined('ABSPATH')) {
    exit;
}


class WooToWoo_Admin {
    
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'WooToWoo',              
            'WooToWoo',              
            'manage_options',        
            'wootowoo',              
            array($this, 'admin_page') 
        );
    }
    
    public function admin_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
        $website_url = $this->config->get_website_url();
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();
        $has_config = $this->config->has_config();
        ?>
        <div class="wrap">
            <h1>WooToWoo</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wootowoo&tab=overview" class="nav-tab <?php echo $active_tab == 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
                <a href="?page=wootowoo&tab=configuration" class="nav-tab <?php echo $active_tab == 'configuration' ? 'nav-tab-active' : ''; ?>">Configuration</a>
            </nav>
            
            <div class="tab-content">
                <?php if ($active_tab == 'overview'): ?>
                    <?php $this->render_overview_tab($has_config); ?>
                <?php elseif ($active_tab == 'configuration'): ?>
                    <?php $this->render_configuration_tab($website_url, $consumer_key, $consumer_secret, $has_config); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($active_tab == 'configuration' && $has_config): ?>
            <?php $this->render_connection_test_script(); ?>
        <?php elseif ($active_tab == 'overview' && $has_config): ?>
            <?php $this->render_synchronize_script(); ?>
        <?php endif; ?>
        <?php
    }
    
    private function render_overview_tab($has_config) {
        ?>
        <div class="overview-tab">
            <h2>Overview</h2>
            <p>Welcome to WooToWoo - reliable WooCommerce product sync that recovers from interruptions automatically.</p>
            <?php if ($has_config): ?>
                <div style="margin-top: 20px;">
                    <button type="button" id="synchronize-btn" class="button button-primary button-hero">Synchronize</button>
                </div>
                <div id="sync-result" style="margin-top: 20px;"></div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p><strong>Status:</strong> Configuration required - <a href="?page=wootowoo&tab=configuration">Configure now</a></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_configuration_tab($website_url, $consumer_key, $consumer_secret, $has_config) {
        ?>
        <div class="configuration-tab">
            <h2>Configuration</h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('wootowoo_save_config', 'wootowoo_nonce'); ?>
                
                <h3>Source</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="website_url">Website address</label>
                        </th>
                        <td>
                            <input type="url" id="website_url" name="website_url" value="<?php echo esc_attr($website_url); ?>" class="regular-text" placeholder="https://www.my-woocommerce-site.com" />
                            <p class="description">Enter the URL of the WooCommerce site to sync from (e.g., https://www.my-woocommerce-site.com)</p>
                        </td>
                    </tr>
                </table>
                
                <h4>WooCommerce REST API</h4>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="consumer_key">Consumer key</label>
                        </th>
                        <td>
                            <input type="text" id="consumer_key" name="consumer_key" value="<?php echo esc_attr($consumer_key); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="consumer_secret">Consumer secret</label>
                        </th>
                        <td>
                            <input type="password" id="consumer_secret" name="consumer_secret" value="<?php echo esc_attr($consumer_secret); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="wootowoo_save_config" class="button-primary" value="Save" />
                    <?php if ($has_config): ?>
                        <button type="button" id="test-connection" class="button">Test connection</button>
                    <?php endif; ?>
                </p>
            </form>
            
            <div id="connection-result" style="margin-top: 20px;"></div>
        </div>
        <?php
    }
    
    private function render_connection_test_script() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#test-connection').on('click', function() {
                var button = $(this);
                var result = $('#connection-result');
                
                button.prop('disabled', true).text('Testing...');
                result.html('<div class="notice notice-info inline"><p>Testing connection...</p></div>');
                
                var websiteUrl = $('#website_url').val();
                var consumerKey = $('#consumer_key').val();
                var consumerSecret = $('#consumer_secret').val();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootowoo_test_connection',
                        nonce: '<?php echo wp_create_nonce('wootowoo_test_connection'); ?>',
                        website_url: websiteUrl,
                        consumer_key: consumerKey,
                        consumer_secret: consumerSecret
                    },
                    success: function(response) {
                        if (response.success) {
                            result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        } else {
                            result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        result.html('<div class="notice notice-error inline"><p>Connection test failed</p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Test connection');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    private function render_synchronize_script() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#synchronize-btn').on('click', function() {
                var button = $(this);
                var result = $('#sync-result');
                
                button.prop('disabled', true).text('Synchronizing...');
                result.html('<div class="notice notice-info inline"><p>Getting products</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootowoo_synchronize',
                        nonce: '<?php echo wp_create_nonce('wootowoo_synchronize'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        } else {
                            result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        result.html('<div class="notice notice-error inline"><p>Synchronization failed - please try again</p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Synchronize');
                    }
                });
            });
        });
        </script>
        <?php
    }
}