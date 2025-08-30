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
    
    private function has_existing_products() {
        $database = WooToWoo_Database::get_instance();
        return $database->get_products_count() > 0;
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
        $has_products = $this->has_existing_products();
        ?>
        <div class="wrap">
            <h1>WooToWoo</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wootowoo&tab=overview" class="nav-tab <?php echo $active_tab == 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
                <a href="?page=wootowoo&tab=configuration" class="nav-tab <?php echo $active_tab == 'configuration' ? 'nav-tab-active' : ''; ?>">Configuration</a>
            </nav>
            
            <div class="tab-content">
                <?php if ($active_tab == 'overview'): ?>
                    <?php $this->render_overview_tab($has_config, $has_products); ?>
                <?php elseif ($active_tab == 'configuration'): ?>
                    <?php $this->render_configuration_tab($website_url, $consumer_key, $consumer_secret, $has_config); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($active_tab == 'configuration' && $has_config): ?>
            <?php $this->render_connection_test_script(); ?>
        <?php elseif ($active_tab == 'overview' && $has_config): ?>
            <?php $this->render_synchronize_script($has_products); ?>
        <?php endif; ?>
        <?php
    }
    
    private function render_overview_tab($has_config, $has_products) {
        ?>
        <div class="overview-tab">
            <h2>Overview</h2>
            <p>Welcome to WooToWoo - reliable WooCommerce product sync that recovers from interruptions automatically.</p>
            <?php if ($has_config): ?>
                <div style="margin-top: 20px;">
                    <?php if ($has_products): ?>
                        <?php 
                        $database = WooToWoo_Database::get_instance();
                        $product_count = $database->get_products_count();
                        ?>
                        <div class="notice notice-info inline" style="margin-bottom: 15px;">
                            <p><strong>Status:</strong> <?php echo $product_count; ?> products already imported. You can resume or restart synchronization.</p>
                        </div>
                        <button type="button" id="resume-sync-btn" class="button button-primary" style="margin-right: 10px;">Resume synchronization</button>
                        <button type="button" id="restart-sync-btn" class="button button-secondary">Clear and restart</button>
                    <?php else: ?>
                        <button type="button" id="synchronize-btn" class="button button-primary">Synchronize</button>
                    <?php endif; ?>
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
    
    private function render_synchronize_script($has_products) {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var syncInProgress = false;
            var terminateSync = false;
            var currentSyncRequest = null;
            
            // Use event delegation for terminate button (works even if button is recreated)
            $(document).on('click', '#terminate-sync-btn', function() {
                console.log('Terminate button clicked!');
                terminateSync = true;
                console.log('terminateSync set to:', terminateSync);
                $(this).prop('disabled', true).text('Terminating...');
                
                // Abort current AJAX request if any
                if (currentSyncRequest && currentSyncRequest.readyState !== 4) {
                    console.log('Aborting current AJAX request');
                    currentSyncRequest.abort();
                }
                
                // Show termination message and reset buttons
                $('#sync-result').html('<div class="notice notice-warning inline"><p>Synchronization terminated by user</p></div>');
                resetSyncButton($('#resume-sync-btn, #synchronize-btn'));
                
                // Optional: Call server-side cleanup
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootowoo_terminate_sync',
                        nonce: '<?php echo wp_create_nonce('wootowoo_terminate_sync'); ?>'
                    }
                });
            });
            
            // Handle all sync buttons (synchronize, resume, restart)
            $('#synchronize-btn, #resume-sync-btn').on('click', function() {
                var button = $(this);
                var result = $('#sync-result');
                
                if (syncInProgress) {
                    return;
                }
                
                syncInProgress = true;
                terminateSync = false;
                button.prop('disabled', true).text('Synchronizing...');
                
                // Hide restart button during sync
                $('#restart-sync-btn').hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootowoo_get_site_url',
                        nonce: '<?php echo wp_create_nonce('wootowoo_get_site_url'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            result.html('<div class="notice notice-info inline"><p>Getting products from ' + response.data + '...</p></div>');
                            performInitialSync();
                        } else {
                            result.html('<div class="notice notice-error inline"><p>Error: ' + response.data + '</p></div>');
                            resetSyncButton(button);
                        }
                    },
                    error: function() {
                        result.html('<div class="notice notice-error inline"><p>Failed to get site information</p></div>');
                        resetSyncButton(button);
                    }
                });
            });
            
            function resetSyncButton(button) {
                syncInProgress = false;
                var originalText = button.attr('id') === 'resume-sync-btn' ? 'Resume synchronization' : 'Synchronize';
                button.prop('disabled', false).text(originalText);
                $('#terminate-sync-btn').remove();
                
                // Show restart button again if it exists
                $('#restart-sync-btn').show();
            }
            
            // Handle restart button
            $('#restart-sync-btn').on('click', function() {
                var button = $(this);
                var result = $('#sync-result');
                
                if (syncInProgress) {
                    return;
                }
                
                if (!confirm('This will delete all existing products and start fresh. Are you sure?')) {
                    return;
                }
                
                button.prop('disabled', true).text('Clearing...');
                result.html('<div class="notice notice-info inline"><p>Clearing existing products...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootowoo_restart_sync',
                        nonce: '<?php echo wp_create_nonce('wootowoo_restart_sync'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                            // Reload page to show fresh sync button
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            result.html('<div class="notice notice-error inline"><p>Error: ' + response.data + '</p></div>');
                            button.prop('disabled', false).text('Clear and restart');
                        }
                    },
                    error: function() {
                        result.html('<div class="notice notice-error inline"><p>Failed to restart synchronization</p></div>');
                        button.prop('disabled', false).text('Clear and restart');
                    }
                });
            });
            
            function performInitialSync() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootowoo_synchronize',
                        nonce: '<?php echo wp_create_nonce('wootowoo_synchronize'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var message = response.data.message || response.data;
                            $('#sync-result').html('<div class="notice notice-info inline"><p>' + message + '</p></div>');
                            
                            // Start paginated sync
                            performPaginatedSync(1, response.data.total_products || 0);
                        } else {
                            $('#sync-result').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                            resetSyncButton($('#synchronize-btn'));
                        }
                    },
                    error: function() {
                        $('#sync-result').html('<div class="notice notice-error inline"><p>111 Synchronization failed - please try again</p></div>');
                        resetSyncButton($('#synchronize-btn'));
                    }
                });
            }
            
            function performPaginatedSync(page, totalProducts) {
                console.log('performPaginatedSync called for page', page, 'terminateSync:', terminateSync);
                if (terminateSync) {
                    console.log('Terminating sync at page', page);
                    return;
                }
                
                currentSyncRequest = $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootowoo_sync_products',
                        nonce: '<?php echo wp_create_nonce('wootowoo_sync_products'); ?>',
                        page: page
                    },
                    success: function(response) {
                        console.log('AJAX response received, terminateSync:', terminateSync);
                        if (terminateSync) {
                            console.log('Terminating in AJAX success callback');
                            return;
                        }
                        
                        if (response.success) {
                            var data = response.data;
                            var progressMessage = 'Getting page ' + data.page + ' of ' + data.total_pages + ' pages';
                            
                            // Update the message
                            $('#sync-result').html('<div class="notice notice-info inline"><p>' + progressMessage + '</p></div>');
                            
                            // Add terminate button on first page processed (regardless of resume point)
                            if ($('#terminate-sync-btn').length === 0) {
                                $('#sync-result').append('<div style="margin-top: 10px;"><button type="button" id="terminate-sync-btn" class="button">Terminate synchronization</button></div>');
                            } else {
                                // Preserve existing terminate button
                                var terminateButton = $('#terminate-sync-btn').parent();
                                $('#sync-result').append(terminateButton);
                            }
                            
                            if (data.has_more && !terminateSync) {
                                // Continue with next page
                                setTimeout(function() {
                                    console.log('setTimeout callback, terminateSync:', terminateSync);
                                    if (!terminateSync) { // Check again after timeout
                                        console.log('Continuing to next page:', data.page + 1);
                                        performPaginatedSync(data.page + 1, data.total_products);
                                    } else {
                                        console.log('Sync terminated in setTimeout callback');
                                    }
                                }, 500); // Small delay to prevent overwhelming the server
                            } else {
                                // Sync complete
                                $('#sync-result').html('<div class="notice notice-success inline"><p>Synchronization completed! ' + data.existing_count + ' products imported.</p></div>');
                                resetSyncButton($('#synchronize-btn'));
                            }
                        } else {
                            $('#sync-result').html('<div class="notice notice-error inline"><p>Error: ' + response.data + '</p></div>');
                            resetSyncButton($('#synchronize-btn'));
                        }
                    },
                    error: function() {
                        if (!terminateSync) {
                            $('#sync-result').html('<div class="notice notice-error inline"><p>Sync failed on page ' + page + ' - please try again</p></div>');
                            resetSyncButton($('#synchronize-btn'));
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }
}