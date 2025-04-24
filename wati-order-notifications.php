<?php
/*
Plugin Name: WATI Order Notifications
Description: Sends WhatsApp notifications for different order statuses using WATI API
Version: 1.2.0
Author: Hamdy Mohammed
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add this function to check if the plugin is active
function wati_is_plugin_active() {
    // Check if the plugin file exists and is activated
    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    $plugin_file = 'wati-order-notifications/wati-order-notifications.php';
    // This gets the relative path if this file is in a subdirectory
    $plugin_path = plugin_basename(__FILE__);
    
    $is_active = is_plugin_active($plugin_path);
    
    if (!$is_active) {
        error_log('WATI Debug: Plugin is not active, terminating processing');
    }
    
    return $is_active;
}

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'wati_notifications_activation');
register_deactivation_hook(__FILE__, 'wati_notifications_deactivation');

// Add AJAX handlers
add_action('wp_ajax_test_wati_api', 'test_wati_api_ajax');
add_action('wp_ajax_search_users', 'wati_search_users_ajax');
add_action('wp_ajax_fetch_wati_templates', 'fetch_wati_templates_ajax');
add_action('wp_ajax_test_abandoned_check', 'test_abandoned_check');
add_action('wp_ajax_verify_wati_template', 'verify_wati_template_ajax');

// Track order status changes
add_action('woocommerce_order_status_changed', 'wati_track_order_status_change', 10, 4);

// Add settings page
function wati_notifications_settings_page() {
    add_menu_page(
        'WATI Notifications',
        'WATI Notifications',
        'manage_options',
        'wati-notifications',
        'wati_notifications_settings_page_html',
        'dashicons-whatsapp',
        100
    );

    // Add logs submenu page
    add_submenu_page(
        'wati-notifications',    // Parent slug
        'Notification Logs',     // Page title
        'Logs',                 // Menu title
        'manage_options',       // Capability
        'wati-notification-logs', // Menu slug
        'wati_notification_logs_page_html' // Callback function
    );

    // Add test button to the settings page
    add_action('admin_footer', 'wati_add_test_button');
}
add_action('admin_menu', 'wati_notifications_settings_page');

// Settings page HTML
function wati_notifications_settings_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Save settings if form is submitted
    if (isset($_POST['submit'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wati_notifications_settings')) {
            wp_die('Invalid nonce');
        }

        $settings = array(
            'bearer_token' => sanitize_text_field($_POST['wati_bearer_token']),
            'api_url' => sanitize_text_field($_POST['wati_api_url']),
            'enable_feature' => isset($_POST['wati_enable_feature']) ? 1 : 0,
            'specific_users' => isset($_POST['wati_specific_users']) ? array_map('intval', $_POST['wati_specific_users']) : array(),
            'conditions' => array(),
            'cron_interval' => absint($_POST['wati_cron_interval']),
            'cron_unit' => sanitize_text_field($_POST['wati_cron_unit']),
            'cutoff_date' => sanitize_text_field($_POST['wati_cutoff_date'])
        );

        // Save conditions
        foreach (['abandoned', 'discount', 'processing', 'shipped', 'tracking'] as $status) {
            if (isset($_POST["wati_{$status}_enabled"])) {
                $settings['conditions'][$status] = array(
                    'enabled' => 1,
                    'template_name' => sanitize_text_field($_POST["wati_{$status}_template"]),
                    'delay_time' => absint($_POST["wati_{$status}_delay_time"]),
                    'delay_unit' => sanitize_text_field($_POST["wati_{$status}_delay_unit"]),
                    'variables' => array()
                );

                // Save template variables - fixed version
                if (isset($_POST["wati_{$status}_variables"]) && 
                    isset($_POST["wati_{$status}_variables"]['type']) && 
                    isset($_POST["wati_{$status}_variables"]['template_name'])) {
                    
                    $types = $_POST["wati_{$status}_variables"]['type'];
                    $template_names = $_POST["wati_{$status}_variables"]['template_name'];
                    
                    foreach ($types as $index => $type) {
                        if (!empty($type) && !empty($template_names[$index])) {
                            $settings['conditions'][$status]['variables'][] = array(
                                'type' => sanitize_text_field($type),
                                'template_name' => sanitize_text_field($template_names[$index])
                            );
                        }
                    }
                }
            }
        }

        update_option('wati_notifications_settings', $settings);
        add_settings_error('wati_messages', 'wati_message', 'Settings Saved', 'updated');
    }

    // Handle emergency stop actions
    if (isset($_POST['activate_emergency_stop']) && check_admin_referer('wati_emergency_stop')) {
        wati_emergency_stop();
        add_settings_error('wati_messages', 'wati_message', 'Emergency stop activated. All notifications are stopped.', 'error');
    }
    
    if (isset($_POST['clear_emergency_stop']) && check_admin_referer('wati_clear_emergency_stop')) {
        delete_option('wati_emergency_stop');
        add_settings_error('wati_messages', 'wati_message', 'Emergency stop cleared. Notifications will resume.', 'updated');
    }

    // Handle order reset
    if (isset($_POST['reset_orders']) && check_admin_referer('wati_reset_orders')) {
        $order_ids = array_map('intval', explode(',', $_POST['order_ids']));
        $reset_count = 0;
        
        foreach ($order_ids as $order_id) {
            // Delete all notification flags for this order
            delete_option('wati_shipped_' . $order_id);
            delete_option('wati_processing_' . $order_id);
            delete_option('wati_tracking_' . $order_id);
            $reset_count++;
        }
        
        add_settings_error('wati_messages', 'wati_message', "Reset {$reset_count} orders. Notifications will be sent again.", 'updated');
    }

    $settings = get_option('wati_notifications_settings', array());
    ?>
    <div class="wrap wati-settings">
        <h1 class="wp-heading-inline">WATI Notifications Settings</h1>
        
        <?php settings_errors('wati_messages'); ?>
        <!-- Order Reset Card -->
        <div class="card order-reset">
            <div class="card-header">
                <h2><span class="dashicons dashicons-update"></span> Reset Order Notifications</h2>
                <p class="description">Enter order IDs (comma-separated) to reset their notification status. This will allow notifications to be sent again for these orders.</p>
            </div>
            
            <form method="post" action="" class="reset-form">
                <?php wp_nonce_field('wati_reset_orders'); ?>
                <div class="form-field">
                    <label for="order_ids">Order IDs</label>
                    <input type="text" name="order_ids" id="order_ids" class="regular-text" 
                           placeholder="e.g., 123, 456, 789">
                    <p class="description">Enter order IDs separated by commas</p>
                </div>
                <button type="submit" name="reset_orders" class="button button-secondary" 
                       onclick="return confirm('Are you sure you want to reset these orders? Notifications will be sent again.');">
                    <span class="dashicons dashicons-update"></span> Reset Orders
                </button>
            </form>
        </div>

        <!-- Main Settings Form -->
        <form method="post" action="" class="wati-settings-form">
            <?php wp_nonce_field('wati_notifications_settings'); ?>
            
            <!-- API Configuration -->
            <div class="card api-config">
                <div class="card-header">
                    <h2><span class="dashicons dashicons-admin-network"></span> API Configuration</h2>
                </div>
                <div class="form-table">
                    <div class="form-field">
                        <label for="wati_bearer_token">WATI Bearer Token</label>
                        <input type="password" name="wati_bearer_token" id="wati_bearer_token" 
                                   value="<?php echo esc_attr($settings['bearer_token'] ?? ''); ?>" class="regular-text">
                            <p class="description">Enter your WATI Bearer Token</p>
                    </div>
                    <div class="form-field">
                        <label for="wati_api_url">WATI API URL</label>
                        <input type="url" name="wati_api_url" id="wati_api_url" 
                                   value="<?php echo esc_attr($settings['api_url'] ?? ''); ?>" class="regular-text">
                            <p class="description">Enter your WATI API URL</p>
                        <button type="button" id="test-wati-api" class="button">
                            <span class="dashicons dashicons-admin-tools"></span> Test Connection
                        </button>
                            <span id="test-result"></span>
                    </div>
                </div>
            </div>

            <!-- General Settings -->
            <div class="card general-settings">
                <div class="card-header">
                    <h2><span class="dashicons dashicons-admin-generic"></span> General Settings</h2>
                </div>
                <div class="form-table">
                    <div class="form-field">
                        <label class="checkbox-label">
                                <input type="checkbox" 
                                       name="wati_enable_feature" 
                                       value="1" 
                                       <?php checked(isset($settings['enable_feature']) && $settings['enable_feature']); ?>>
                            <span>Enable WATI WhatsApp notifications</span>
                            </label>
                            <p class="description">Turn this on to activate all notification features</p>
                    </div>
                    <div class="form-field">
                        <label for="wati_cutoff_date">Cutoff Date</label>
                        <input type="date" 
                               name="wati_cutoff_date" 
                               id="wati_cutoff_date" 
                               value="<?php echo esc_attr($settings['cutoff_date'] ?? '2025-04-22'); ?>">
                        <p class="description">Orders before this date will be ignored. Format: YYYY-MM-DD</p>
                    </div>
                </div>
            </div>

            <!-- Cron Settings -->
            <div class="card cron-settings">
                <div class="card-header">
                    <h2><span class="dashicons dashicons-clock"></span> Cron Settings</h2>
                </div>
                <div class="form-table">
                    <div class="form-field">
                        <label for="wati_cron_interval">Check Interval</label>
                        <div class="interval-controls">
                            <input type="number" 
                                   name="wati_cron_interval" 
                                   id="wati_cron_interval" 
                                   value="<?php echo esc_attr($settings['cron_interval'] ?? '5'); ?>" 
                                   min="1" 
                                   class="small-text">
                            <select name="wati_cron_unit">
                                <option value="minutes" <?php selected(($settings['cron_unit'] ?? 'minutes'), 'minutes'); ?>>Minutes</option>
                                <option value="hours" <?php selected(($settings['cron_unit'] ?? 'minutes'), 'hours'); ?>>Hours</option>
                            </select>
                        </div>
                            <p class="description">How often should the plugin check for abandoned carts and order status changes. Default: 5 minutes</p>
                        <p class="description warning">Note: Changes to this setting require plugin deactivation and reactivation to take effect.</p>
                    </div>
                </div>
            </div>

            <!-- User Selection -->
            <div class="card user-selection">
                <div class="card-header">
                    <h2><span class="dashicons dashicons-groups"></span> Target Users</h2>
                </div>
                <div class="form-table">
                    <div class="form-field">
                            <div class="wati-user-selector">
                                <div class="user-selection-controls">
                                <select id="user-search" class="user-search-select" multiple>
                                        <option value="">Search users...</option>
                                        <?php
                                        // Load existing selected users
                                        if (!empty($settings['specific_users'])) {
                                            foreach ($settings['specific_users'] as $user_id) {
                                                $user = get_userdata($user_id);
                                                if ($user) {
                                                    echo sprintf(
                                                        '<option value="%d" selected>%s (%s)</option>',
                                                        $user->ID,
                                                        esc_html($user->display_name),
                                                        esc_html($user->user_email)
                                                    );
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                <button type="button" class="button clear-users">
                                    <span class="dashicons dashicons-no"></span> Clear All Users
                                </button>
                                </div>
                                <div id="selected-users" class="selected-users-container">
                                    <?php
                                    if (!empty($settings['specific_users'])) {
                                        foreach ($settings['specific_users'] as $user_id) {
                                            $user = get_userdata($user_id);
                                            if ($user) {
                                                echo wati_render_user_tag($user);
                                            }
                                        }
                                    }
                                    ?>
                                </div>
                                <p class="description">Leave empty to send notifications to all users. Select multiple users by holding Ctrl/Cmd.</p>
                            </div>
                    </div>
                </div>
            </div>

            <!-- Notification Conditions -->
            <div class="card notification-conditions">
                <div class="card-header">
                    <h2><span class="dashicons dashicons-email"></span> Notification Conditions</h2>
                </div>
                <?php
                $conditions = array(
                    'abandoned' => array(
                        'label' => 'Abandoned Cart',
                        'description' => 'Send notifications for abandoned carts'
                    ),
                    'discount' => array(
                        'label' => 'Discount Reminder',
                        'description' => 'Send discount reminders'
                    ),
                    'processing' => array(
                        'label' => 'Processing Order',
                        'description' => 'Send notifications when orders are being processed'
                    ),
                    'shipped' => array(
                        'label' => 'Shipped Order',
                        'description' => 'Send notifications when orders are shipped'
                    ),
                    'tracking' => array(
                        'label' => 'Tracking Update',
                        'description' => 'Send tracking number updates'
                    )
                );

                foreach ($conditions as $status => $info) {
                    $condition = $settings['conditions'][$status] ?? array();
                    ?>
                    <div class="notification-condition">
                        <h3><?php echo esc_html($info['label']); ?></h3>
                        <p class="description"><?php echo esc_html($info['description']); ?></p>
                        <div class="form-table">
                            <div class="form-field">
                                <label class="checkbox-label">
                                        <input type="checkbox" name="wati_<?php echo $status; ?>_enabled" value="1"
                                               <?php checked(isset($condition['enabled']) && $condition['enabled']); ?>>
                                    <span>Enable <?php echo esc_html($info['label']); ?> notifications</span>
                                    </label>
                            </div>
                            <div class="form-field">
                                <label for="wati_<?php echo $status; ?>_template">Template Name</label>
                                    <input type="text" name="wati_<?php echo $status; ?>_template"
                                           value="<?php echo esc_attr($condition['template_name'] ?? ''); ?>"
                                           class="regular-text">
                                <p class="description">Enter the WATI template name for this notification</p>
                            </div>
                            <div class="form-field">
                                <label for="wati_<?php echo $status; ?>_delay_time">Send After</label>
                                <div class="delay-controls">
                                    <input type="number" name="wati_<?php echo $status; ?>_delay_time"
                                           value="<?php echo esc_attr($condition['delay_time'] ?? '2'); ?>"
                                           min="1" class="small-text">
                                    <select name="wati_<?php echo $status; ?>_delay_unit">
                                        <option value="minutes" <?php selected(($condition['delay_unit'] ?? 'hours'), 'minutes'); ?>>Minutes</option>
                                        <option value="hours" <?php selected(($condition['delay_unit'] ?? 'hours'), 'hours'); ?>>Hours</option>
                                    </select>
                                </div>
                                <p class="description">Delay before sending this notification</p>
                            </div>
                            <div class="form-field">
                                <label>Template Variables</label>
                                    <div class="template-variables" data-status="<?php echo esc_attr($status); ?>">
                                        <?php
                                        if (!empty($condition['variables'])) {
                                            foreach ($condition['variables'] as $var) {
                                                echo wati_render_variable_row($status, $var);
                                            }
                                        }
                                        ?>
                                    <button type="button" class="button add-variable">
                                        <span class="dashicons dashicons-plus"></span> Add Variable
                                    </button>
                                    </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>

            <?php submit_button('Save Settings', 'primary', 'submit', true, array('id' => 'submit-settings')); ?>
        </form>
    </div>

    <style>
    .wati-settings {
        max-width: 1200px;
        margin: 20px auto;
    }

    .card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        margin-bottom: 20px;
        padding: 20px;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }

    .card-header {
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .card-header h2 {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header .dashicons {
        color: #2271b1;
    }

    .emergency-controls {
        border-left: 4px solid #d63638;
    }

    .emergency-controls .card-header h2 .dashicons {
        color: #d63638;
    }

    .emergency-form {
        margin-top: 20px;
    }

    .button-danger {
        background: #d63638;
        border-color: #d63638;
        color: #fff;
    }

    .button-danger:hover {
        background: #b32d2e;
        border-color: #b32d2e;
        color: #fff;
    }

    .form-table {
        display: grid;
        gap: 20px;
    }

    .form-field {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .form-field label {
        font-weight: 600;
    }

    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .description {
        color: #666;
        font-style: italic;
        margin-top: 5px;
    }

    .description.warning {
        color: #d63638;
    }

    .interval-controls,
    .delay-controls {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .small-text {
        width: 80px;
    }

    .wati-user-selector {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .user-selection-controls {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .user-search-select {
        width: 300px;
        min-height: 100px;
    }

    .selected-users-container {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        min-height: 50px;
    }

    .user-tag {
        display: flex;
        align-items: center;
        gap: 5px;
        background: #f0f0f1;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 13px;
    }

    .user-tag .remove-user {
        background: none;
        border: none;
        color: #666;
        cursor: pointer;
        padding: 0;
    }

    .user-tag .remove-user:hover {
        color: #d63638;
    }

    .notification-condition {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 20px;
    }

    .notification-condition h3 {
        margin: 0 0 10px 0;
        color: #1d2327;
    }

    .template-variables {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .variable-row {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .variable-row select,
    .variable-row input {
        flex: 1;
    }

    .variable-row .remove-variable {
        color: #666;
        cursor: pointer;
    }

    .variable-row .remove-variable:hover {
        color: #d63638;
    }

    #submit-settings {
        margin-top: 20px;
    }

    /* Responsive adjustments */
    @media screen and (max-width: 782px) {
        .form-table {
            grid-template-columns: 1fr;
        }

        .interval-controls,
        .delay-controls {
            flex-direction: column;
            align-items: flex-start;
        }

        .user-selection-controls {
            flex-direction: column;
            align-items: flex-start;
        }

        .user-search-select {
            width: 100%;
        }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Initialize Select2 for user search
        $('#user-search').select2({
            width: '100%',
            placeholder: 'Search users...',
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'search_users',
                        q: params.term,
                        nonce: '<?php echo wp_create_nonce("wati_ajax_nonce"); ?>'
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            }
        });

        // Handle user selection
        $('#user-search').on('select2:select', function(e) {
            const user = e.params.data;
            const userTag = `
                <div class="user-tag" data-id="${user.id}">
                    <span class="user-info">${user.text}</span>
                    <button type="button" class="remove-user">&times;</button>
                    <input type="hidden" name="wati_specific_users[]" value="${user.id}">
                </div>
            `;
            $('#selected-users').append(userTag);
        });

        // Handle user removal
        $(document).on('click', '.remove-user', function() {
            const userId = $(this).closest('.user-tag').data('id');
            $(this).closest('.user-tag').remove();
            $('#user-search option[value="' + userId + '"]').prop('selected', false);
            $('#user-search').trigger('change');
        });

        // Clear all users
        $('.clear-users').click(function() {
                $('#selected-users').empty();
            $('#user-search').val(null).trigger('change');
        });

        // Add variable row
        $('.add-variable').click(function() {
            const status = $(this).closest('.template-variables').data('status');
            const newRow = `
                <div class="variable-row">
                    <select name="wati_${status}_variables[type][]" class="variable-type">
                        <option value="customer_name">Customer Name</option>
                        <option value="order_number">Order Number</option>
                        <option value="tracking_number">SMSA Tracking Number</option>
                        <option value="tracking_url">SMSA Tracking URL</option>
                    </select>
                    <input type="text" name="wati_${status}_variables[template_name][]" 
                           class="variable-template-name" placeholder="Template Variable Name">
                    <span class="remove-variable dashicons dashicons-no-alt"></span>
                </div>
            `;
            $(this).before(newRow);
        });

        // Remove variable row
        $(document).on('click', '.remove-variable', function() {
            $(this).closest('.variable-row').remove();
        });

        // Test WATI API connection
        $('#test-wati-api').click(function() {
            const button = $(this);
            const result = $('#test-result');
            
            button.prop('disabled', true);
            result.html('<span class="spinner is-active"></span> Testing connection...');
            
            $.post(ajaxurl, {
                action: 'test_wati_api',
                nonce: '<?php echo wp_create_nonce("wati_ajax_nonce"); ?>',
                bearer_token: $('#wati_bearer_token').val(),
                api_url: $('#wati_api_url').val()
            }, function(response) {
                button.prop('disabled', false);
                if (response.success) {
                    result.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span> Connection successful!');
                } else {
                    result.html('<span class="dashicons dashicons-no" style="color: #dc3232;"></span> Connection failed: ' + response.data.message);
                }
            }).fail(function() {
                button.prop('disabled', false);
                result.html('<span class="dashicons dashicons-no" style="color: #dc3232;"></span> Connection failed: Server error');
            });
        });

        // Debounce function to limit API calls
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Template validation function
        function validateTemplate(templateInput, status) {
            const templateName = templateInput.val();
            const bearerToken = $('#wati_bearer_token').val();
            const apiUrl = $('#wati_api_url').val();
            const isEnabled = templateInput.closest('.notification-condition').find('input[type="checkbox"]').is(':checked');
            
            // Remove any existing status div
            templateInput.next('.template-status').remove();
            
            if (!isEnabled) {
                return; // Don't validate if notification type is not enabled
            }
            
            if (!templateName || !bearerToken || !apiUrl) {
                return;
            }
            
            const statusDiv = $('<div class="template-status"></div>');
            templateInput.after(statusDiv);
            
            statusDiv.html('<span class="spinner is-active"></span> Validating template...');
            
            $.post(ajaxurl, {
                action: 'verify_wati_template',
                nonce: '<?php echo wp_create_nonce("wati_ajax_nonce"); ?>',
                template_name: templateName,
                bearer_token: bearerToken,
                api_url: apiUrl
            }, function(response) {
                if (response.success && response.data.exists) {
                    statusDiv.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span> Template exists');
                    templateInput.removeClass('warning');
                } else {
                    statusDiv.html(`
                        <div class="template-warning">
                            <span class="dashicons dashicons-warning" style="color: #ffb900;"></span> 
                            Template not found
                            <button type="button" class="button button-small continue-anyway" style="margin-left: 10px;">
                                Continue Anyway
                            </button>
                        </div>
                    `);
                    templateInput.addClass('warning');
                    
                    // Handle continue anyway button
                    statusDiv.find('.continue-anyway').on('click', function() {
                        statusDiv.html('<span class="dashicons dashicons-info" style="color: #2271b1;"></span> Proceeding with unverified template');
                        templateInput.removeClass('warning');
                    });
                }
            }).fail(function() {
                statusDiv.html(`
                    <div class="template-warning">
                        <span class="dashicons dashicons-warning" style="color: #ffb900;"></span> 
                        Validation failed
                        <button type="button" class="button button-small continue-anyway" style="margin-left: 10px;">
                            Continue Anyway
                        </button>
                    </div>
                `);
                templateInput.addClass('warning');
                
                // Handle continue anyway button
                statusDiv.find('.continue-anyway').on('click', function() {
                    statusDiv.html('<span class="dashicons dashicons-info" style="color: #2271b1;"></span> Proceeding with unverified template');
                    templateInput.removeClass('warning');
                });
            });
        }
        
        // Debounced validation function
        const debouncedValidate = debounce(function(templateInput, status) {
            validateTemplate(templateInput, status);
        }, 500); // 500ms delay
        
        // Validate templates on input change
        $('input[name^="wati_"][name$="_template"]').on('input', function() {
            const status = $(this).closest('.notification-condition').find('h3').text();
            debouncedValidate($(this), status);
        });
        
        // Validate when enabling/disabling notification type
        $('input[type="checkbox"][name^="wati_"][name$="_enabled"]').on('change', function() {
            const templateInput = $(this).closest('.notification-condition').find('input[name$="_template"]');
            const status = $(this).closest('.notification-condition').find('h3').text();
            if ($(this).is(':checked')) {
                debouncedValidate(templateInput, status);
            } else {
                templateInput.next('.template-status').remove();
                templateInput.removeClass('warning');
            }
        });
        
        // Validate all templates on form submit
        $('.wati-settings-form').on('submit', function(e) {
            let hasWarnings = false;
            let warningMessages = [];
            
            $('input[name^="wati_"][name$="_template"]').each(function() {
                const isEnabled = $(this).closest('.notification-condition').find('input[type="checkbox"]').is(':checked');
                if (!isEnabled) {
                    return; // Skip validation for disabled notification types
                }
                
                const templateName = $(this).val();
                if (!templateName) {
                    $(this).addClass('error');
                    hasWarnings = true;
                    warningMessages.push('Please fill in all template names for enabled notifications');
                } else if ($(this).hasClass('warning')) {
                    hasWarnings = true;
                    warningMessages.push('Some templates are unverified');
                }
            });
            
            if (hasWarnings) {
                e.preventDefault();
                const message = warningMessages.join('\n') + '\n\nDo you want to proceed anyway?';
                if (!confirm(message)) {
                    return;
                }
            }
        });
    });
    </script>
    <style>
    .template-status {
        margin-top: 5px;
        font-size: 13px;
    }
    
    input.error {
        border-color: #dc3232;
    }
    
    input.warning {
        border-color: #ffb900;
    }
    
    .template-warning {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .continue-anyway {
        padding: 2px 8px;
        font-size: 12px;
    }
    </style>
    <?php
}

// Add handler for emergency stop actions
add_action('admin_init', 'wati_handle_emergency_actions');
function wati_handle_emergency_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'wati_emergency_stop':
                if (check_admin_referer('wati_emergency_stop')) {
                    wati_emergency_stop();
                    wp_redirect(add_query_arg('emergency_stop', 'enabled'));
                    exit;
                }
                break;
                
            case 'wati_emergency_stop_disable':
                if (check_admin_referer('wati_emergency_stop_disable')) {
                    wati_emergency_stop_disable();
                    wp_redirect(add_query_arg('emergency_stop', 'disabled'));
                    exit;
                }
                break;
        }
    }
}

// Add admin notices for emergency stop status
function wati_admin_notices() {
    if (isset($_GET['emergency_stop'])) {
        if ($_GET['emergency_stop'] === 'enabled') {
            ?>
            <div class="notice notice-warning">
                <p>Emergency Stop has been enabled. All WATI notifications are now stopped.</p>
            </div>
            <?php
        } elseif ($_GET['emergency_stop'] === 'disabled') {
            ?>
            <div class="notice notice-success">
                <p>Emergency Stop has been disabled. WATI notifications can now resume.</p>
            </div>
            <?php
        }
    }
}
add_action('admin_notices', 'wati_admin_notices');

// Helper function to render user tag
function wati_render_user_tag($user) {
    return sprintf(
        '<div class="user-tag" data-id="%d">
            <span class="user-info">%s (%s)</span>
            <button type="button" class="remove-user">&times;</button>
            <input type="hidden" name="wati_specific_users[]" value="%d">
        </div>',
        $user->ID,
        esc_html($user->display_name),
        esc_html($user->user_email),
        $user->ID
    );
}

// Helper function to render variable row
function wati_render_variable_row($status, $var = array()) {
    $types = array(
        'customer_name' => 'Customer Name',
        'order_number' => 'Order Number',
        'tracking_number' => 'SMSA Tracking Number',
        'tracking_url' => 'SMSA Tracking URL'
    );

    $html = '<div class="variable-row">';
    $html .= '<select name="wati_' . esc_attr($status) . '_variables[type][]" class="variable-type">';
    foreach ($types as $value => $label) {
        $selected = isset($var['type']) && $var['type'] === $value ? 'selected' : '';
        $html .= sprintf(
            '<option value="%s" %s>%s</option>',
            esc_attr($value),
            $selected,
            esc_html($label)
        );
    }
    $html .= '</select>';
    
    $html .= sprintf(
        '<input type="text" name="wati_%s_variables[template_name][]" class="variable-template-name" placeholder="Template Variable Name" value="%s">',
        esc_attr($status),
        esc_attr($var['template_name'] ?? '')
    );
    
    $html .= '<span class="remove-variable dashicons dashicons-no-alt"></span>';
    $html .= '</div>';
    
    return $html;
}

// Activation hook
function wati_notifications_activation() {
    error_log('WATI Debug: Plugin activated, registering cron jobs');
    
    // Clear any existing schedules
    wp_clear_scheduled_hook('wati_check_notifications');
    wp_clear_scheduled_hook('wati_cleanup_old_logs');
    
    // Schedule notification check
    if (!wp_next_scheduled('wati_check_notifications')) {
        wp_schedule_event(time(), 'wati_custom_interval', 'wati_check_notifications');
        error_log('WATI Debug: Notification check cron job scheduled successfully');
    }
    
    // Schedule log cleanup
    if (!wp_next_scheduled('wati_cleanup_old_logs')) {
        wp_schedule_event(time(), 'daily', 'wati_cleanup_old_logs');
        error_log('WATI Debug: Log cleanup cron job scheduled successfully');
    }
}

// Deactivation hook
function wati_notifications_deactivation() {
    error_log('WATI Debug: Plugin deactivated, performing cleanup');
    
    // Set emergency stop signal to stop any running processes
    wati_emergency_stop();
    
    // Clear all cron jobs
    wp_clear_scheduled_hook('wati_check_notifications');
    wp_clear_scheduled_hook('wati_cleanup_old_logs');
    
    // Clear emergency stop
    delete_option('wati_emergency_stop');
    
    // Clear any pending notifications
    delete_option('wati_pending_notifications');
    
    // Clear processing state
    wati_clear_processing_state();
}

// Add custom cron interval
function wati_add_cron_interval($schedules) {
    $settings = get_option('wati_notifications_settings', array());
    $interval = absint($settings['cron_interval'] ?? 5);
    $unit = $settings['cron_unit'] ?? 'minutes';
    
    $seconds = $unit === 'hours' ? $interval * 3600 : $interval * 60;
    
    $schedules['wati_custom_interval'] = array(
        'interval' => $seconds,
        'display' => sprintf(
            $unit === 'hours' ? 'Every %d hours' : 'Every %d minutes',
            $interval
        )
    );
    
    return $schedules;
}
add_filter('cron_schedules', 'wati_add_cron_interval');

// WATI API Integration Functions
function test_wati_api_connection($api_url, $bearer_token) {
    error_log('WATI Debug: Testing API connection');
    error_log('WATI Debug: API URL: ' . $api_url);
    error_log('WATI Debug: Bearer token length: ' . strlen($bearer_token));

    // Remove any trailing slash from API URL
    $api_url = rtrim($api_url, '/');
    
    if (!preg_match('/live-mt-server\.wati\.io\/(\d+)/', $api_url, $matches)) {
        error_log('WATI Debug: Invalid API URL format');
        return array(
            'success' => false,
            'message' => 'Invalid API URL format. Expected: live-mt-server.wati.io/{business_id}',
            'debug' => array(
                'provided_url' => $api_url,
                'expected_format' => 'https://live-mt-server.wati.io/{business_id}'
            )
        );
    }

    $business_id = $matches[1];
    $test_url = "https://live-mt-server.wati.io/{$business_id}/api/v1/getMessageTemplates";
    
    error_log('WATI Debug: Testing endpoint: ' . $test_url);

    $response = wp_remote_get($test_url, array(
        'headers' => array(
            'Authorization' => $bearer_token
        )
    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log('WATI Debug: WordPress error: ' . $error_message);
        return array(
            'success' => false,
            'message' => 'Connection error: ' . $error_message,
            'debug' => array(
                'error_type' => 'wp_error',
                'error_message' => $error_message
            )
        );
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $response_data = json_decode($body, true);

    error_log('WATI Debug: Response status code: ' . $status_code);
    error_log('WATI Debug: Response body: ' . $body);

    return array(
        'success' => $status_code === 200,
        'status_code' => $status_code,
        'response' => $response_data,
        'debug' => array(
            'endpoint' => $test_url,
            'business_id' => $business_id,
            'raw_response' => $body
        )
    );
}

// AJAX handler for API testing
function test_wati_api_ajax() {
    check_ajax_referer('wati_ajax_nonce', 'nonce');

    $bearer_token = sanitize_text_field($_POST['bearer_token']);
    $api_url = sanitize_text_field($_POST['api_url']);

    $result = test_wati_api_connection($api_url, $bearer_token);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

// User search AJAX handler
function wati_search_users_ajax() {
    check_ajax_referer('wati_ajax_nonce', 'nonce');
    
    $search = sanitize_text_field($_GET['q']);
    
    $users = get_users(array(
        'search' => "*{$search}*",
        'search_columns' => array('user_login', 'user_email', 'display_name'),
        'number' => 10
    ));

    $results = array();
    foreach ($users as $user) {
        $results[] = array(
            'id' => $user->ID,
            'text' => sprintf('%s (%s)', $user->display_name, $user->user_email)
        );
    }

    wp_send_json($results);
}

// Add this function to verify template exists
function verify_template_exists($template_name, $api_url, $bearer_token) {
    if (empty($template_name)) {
        error_log('WATI Debug: Empty template name provided');
        return false;
    }

    if (!preg_match('/live-mt-server\.wati\.io\/(\d+)/', $api_url, $matches)) {
        error_log('WATI Debug: Invalid API URL format');
        return false;
    }
    
    $business_id = $matches[1];
    $templates_url = "https://live-mt-server.wati.io/{$business_id}/api/v1/getMessageTemplates";

    $response = wp_remote_get($templates_url, array(
        'headers' => array(
            'Authorization' => $bearer_token
        )
    ));

    if (is_wp_error($response)) {
        error_log('WATI Debug: Template verification error - ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['messageTemplates'])) {
        foreach ($body['messageTemplates'] as $template) {
            if ($template['elementName'] === $template_name) {
                error_log('WATI Debug: Template found: ' . $template_name);
                return true;
            }
        }
    }

    error_log('WATI Debug: Template not found: ' . $template_name);
    return false;
}

// Add AJAX handler for template validation
add_action('wp_ajax_verify_wati_template', 'verify_wati_template_ajax');
function verify_wati_template_ajax() {
    check_ajax_referer('wati_ajax_nonce', 'nonce');
    
    $template_name = sanitize_text_field($_POST['template_name']);
    $api_url = sanitize_text_field($_POST['api_url']);
    $bearer_token = sanitize_text_field($_POST['bearer_token']);
    
    if (empty($template_name) || empty($api_url) || empty($bearer_token)) {
        wp_send_json_error(array('message' => 'Missing required parameters'));
    }
    
    $exists = verify_template_exists($template_name, $api_url, $bearer_token);
    wp_send_json_success(array('exists' => $exists));
}

// Update the send_wati_template function
function send_wati_template($phone_number, $template_name, $variables = array()) {
    // First check if plugin is still active
    if (!wati_is_plugin_active()) {
        error_log('WATI Debug: Plugin is not active. Stopping message send to: ' . $phone_number);
        return false;
    }
    
    // Check both emergency stop and stop signal - immediate check
    if (wati_check_emergency_stop() || wati_check_stop_signal()) {
        error_log('WATI Debug: Message sending stopped due to emergency stop/signal. Phone: ' . $phone_number);
        return false;
    }
    
    $settings = get_option('wati_notifications_settings', array());
    $api_url = $settings['api_url'] ?? '';
    $bearer_token = $settings['bearer_token'] ?? '';

    error_log('WATI Debug: Starting template send process');
    error_log('WATI Debug: Template: ' . $template_name);

    // Check retry count
    $retry_key = 'wati_retry_' . md5($phone_number . $template_name);
    $retry_count = get_option($retry_key, 0);
    
    if ($retry_count >= 3) {
        error_log('WATI Debug: Message skipped - exceeded retry limit');
        wati_log_notification('error', $phone_number, $template_name, 'skipped', array(
            'error' => 'Exceeded retry limit',
            'retry_count' => $retry_count,
            'message' => 'Message skipped after 3 failed attempts'
        ));
        return false;
    }

    // Double-check emergency stop again - aggressive checking
    if (wati_check_emergency_stop(true) || wati_check_stop_signal()) {
        error_log('WATI Debug: Emergency stop detected during message preparation. Aborting send to: ' . $phone_number);
        return false;
    }

    if (empty($api_url) || empty($bearer_token)) {
        error_log('WATI Debug: Missing API credentials');
        wati_log_notification('error', $phone_number, $template_name, 'error', array(
            'error' => 'Missing API credentials',
            'api_url_set' => !empty($api_url),
            'bearer_token_set' => !empty($bearer_token)
        ));
        return false;
    }

    // Validate template exists before sending
    if (!verify_template_exists($template_name, $api_url, $bearer_token)) {
        error_log('WATI Debug: Template validation failed: ' . $template_name);
        wati_log_notification('warning', $phone_number, $template_name, 'warning', array(
            'error' => 'Template validation failed',
            'template_name' => $template_name,
            'message' => 'Proceeding with unverified template'
        ));
        // Continue anyway, but log the warning
    }

    // Basic phone number cleaning - just remove non-numeric characters
    $whatsapp_number = preg_replace('/[^0-9]/', '', $phone_number);
    
    if (empty($whatsapp_number)) {
        error_log('WATI Debug: Invalid phone number format: ' . $phone_number);
        wati_log_notification('error', $phone_number, $template_name, 'error', array(
            'error' => 'Invalid phone number format',
            'original_number' => $phone_number,
            'cleaned_number' => $whatsapp_number
        ));
        return false;
    }

    // One final emergency stop check before API call - critical point
    if (wati_check_emergency_stop(true) || wati_check_stop_signal()) {
        error_log('WATI Debug: Emergency stop detected right before API call. Aborting send to: ' . $phone_number);
        return false;
    }

    error_log('WATI Debug: Sending to number: ' . $whatsapp_number);
    error_log('WATI Debug: Variables: ' . print_r($variables, true));

    // Extract business ID from API URL
    if (!preg_match('/live-mt-server\.wati\.io\/(\d+)/', $api_url, $matches)) {
        error_log('WATI Debug: Invalid API URL format: ' . $api_url);
        wati_log_notification('error', $whatsapp_number, $template_name, 'error', array(
            'error' => 'Invalid API URL format',
            'api_url' => $api_url
        ));
        return false;
    }
    
    $business_id = $matches[1];
    
    // Generate a unique broadcast name using template name and timestamp
    $broadcast_name = $template_name . '_' . time();
    
    $request_body = array(
        'template_name' => $template_name,
        'broadcast_name' => $broadcast_name
    );

    // Add variables if present
    if (!empty($variables)) {
        $request_body['parameters'] = $variables;
    }

    $api_endpoint = "https://live-mt-server.wati.io/{$business_id}/api/v2/sendTemplateMessage";
    $api_endpoint .= "?whatsappNumber=" . $whatsapp_number;

    error_log('WATI Debug: Making API request to: ' . $api_endpoint);
    error_log('WATI Debug: Request body: ' . json_encode($request_body));

    $response = wp_remote_post($api_endpoint, array(
        'headers' => array(
            'Authorization' => $bearer_token,
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($request_body)
    ));

    // Check emergency stop again after API call - prevent further processing
    if (wati_check_emergency_stop(true) || wati_check_stop_signal()) {
        error_log('WATI Debug: Emergency stop detected after API call. Aborting next steps for: ' . $phone_number);
        return false;
    }

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log('WATI Debug: WordPress error: ' . $error_message);
        wati_log_notification('error', $whatsapp_number, $template_name, 'error', array(
            'error' => 'WordPress error',
            'message' => $error_message,
            'request' => array(
                'endpoint' => $api_endpoint,
                'body' => $request_body
            )
        ));
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    error_log('WATI Debug: Response code: ' . $response_code);
    error_log('WATI Debug: Response body: ' . print_r($response_body, true));

    $success = $response_code === 200 && isset($response_body['result']) && $response_body['result'] === true;
    $status = $success ? 'success' : 'error';

    if ($success) {
        // Clear retry count on success
        delete_option($retry_key);
        error_log('WATI Debug: Message sent successfully');
    } else {
        // Increment retry count on failure
        $retry_count++;
        update_option($retry_key, $retry_count);
        error_log('WATI Debug: Message send failed. Error: ' . ($response_body['error'] ?? 'Unknown error'));
        error_log('WATI Debug: Retry count: ' . $retry_count);
    }

    wati_log_notification('notification', $whatsapp_number, $template_name, $status, array(
        'response_code' => $response_code,
        'response_body' => $response_body,
        'request' => array(
            'endpoint' => $api_endpoint,
            'body' => $request_body
        ),
        'variables' => $variables,
        'original_number' => $phone_number,
        'formatted_number' => $whatsapp_number,
        'timestamp' => current_time('mysql'),
        'settings_used' => array(
            'api_url_set' => !empty($api_url),
            'bearer_token_set' => !empty($bearer_token),
            'business_id' => $business_id
        ),
        'retry_count' => $retry_count
    ));

    return $success;
}

// Check notifications function (runs every 5 minutes)
function wati_check_notifications() {
    // Force terminate if emergency stop is active
    wati_force_terminate_if_emergency();
    
    // First check if plugin is still active
    if (!wati_is_plugin_active()) {
        error_log('WATI Debug: Plugin is not active. Terminating notification check.');
        // Clear processing state
        delete_option('wati_notification_processing');
        return;
    }
    
    // Check for emergency stop before processing any notifications
    if (wati_check_emergency_stop() || wati_check_stop_signal()) {
        error_log('WATI Debug: Skipping notification check due to active emergency stop');
        return;
    }

    if (!wati_rate_limit_check()) {
        error_log('WATI Debug: Rate limit hit - skipping this run');
        return;
    }
    
    error_log('WATI Debug: Starting scheduled check at ' . current_time('mysql'));
    
    // Log cron execution
    wati_log_notification('cron', '', '', 'info', array(
        'message' => 'Cron job executed',
        'time' => current_time('mysql'),
        'next_run' => wp_next_scheduled('wati_check_notifications') ? 
            date('Y-m-d H:i:s', wp_next_scheduled('wati_check_notifications')) : 'Not scheduled'
    ));
    
    // Get settings
    $settings = get_option('wati_notifications_settings', array());
    
    if (empty($settings['enable_feature'])) {
        error_log('WATI Debug: Feature is disabled in settings');
        wati_log_notification('cron', '', '', 'warning', array(
            'message' => 'Feature is disabled in settings'
        ));
        return;
    }

    // Track the start of notification processing
    update_option('wati_notification_processing', array(
        'start_time' => current_time('mysql'),
        'process_id' => getmypid()
    ));

    try {
        // Critical termination check before starting any work
        wati_force_terminate_if_emergency();
        
        // Check tracking notifications first since it's independent of order status
        if (!empty($settings['conditions']['tracking']['enabled'])) {
            if (wati_check_emergency_stop(true) || wati_check_stop_signal()) {
                error_log('WATI Debug: Emergency stop active - stopping tracking notifications');
                return;
            }
            
            error_log('WATI Debug: Starting tracking notifications check from cron...');
            try {
                $tracking_results = check_tracking_notifications($settings);
                error_log('WATI Debug: Tracking check completed. Results: ' . print_r($tracking_results, true));
                
                wati_log_notification('cron', '', '', 'info', array(
                    'message' => 'Tracking notifications check completed',
                    'tracking_results' => $tracking_results
                ));
            } catch (Exception $e) {
                error_log('WATI Debug: Error in tracking notifications: ' . $e->getMessage());
                wati_log_notification('error', '', '', 'error', array(
                    'message' => 'Error in tracking notifications',
                    'error' => $e->getMessage()
                ));
            }
        }

        // Check other notifications in batches
        $notification_types = array(
            'abandoned' => 'check_abandoned_carts',
            'processing' => 'check_processing_orders',
            'shipped' => 'check_shipped_orders',
            'discount' => 'check_discount_notifications'
        );

        foreach ($notification_types as $type => $callback) {
            // Force terminate if emergency stop is active - critical check
            wati_force_terminate_if_emergency();
            
            if (wati_check_emergency_stop(true) || wati_check_stop_signal()) {
                error_log("WATI Debug: Emergency stop active - stopping {$type} notifications");
                return;
            }
            
            if (!empty($settings['conditions'][$type]['enabled'])) {
                error_log("WATI Debug: Checking {$type} notifications...");
                try {
                    $results = call_user_func($callback, $settings);
                    wati_log_notification('cron', '', '', 'info', array(
                        'message' => ucfirst($type) . ' notifications check completed',
                        'results' => $results
                    ));
                } catch (Exception $e) {
                    error_log("WATI Debug: Error in {$type} notifications: " . $e->getMessage());
                    wati_log_notification('error', '', '', 'error', array(
                        'message' => "Error in {$type} notifications",
                        'error' => $e->getMessage()
                    ));
                }
            }
            
            // Check for emergency stop after each notification type
            if (wati_check_emergency_stop(true) || wati_check_stop_signal()) {
                error_log("WATI Debug: Emergency stop detected after {$type} check. Stopping remaining checks.");
                break;
            }
        }

        error_log('WATI Debug: Scheduled check completed at ' . current_time('mysql'));

    } catch (Exception $e) {
        error_log('WATI Debug: Error in notification check: ' . $e->getMessage());
    } finally {
        // Clear the processing flag
        delete_option('wati_notification_processing');
    }
}
add_action('wati_check_notifications', 'wati_check_notifications');

// Add this helper function at the top of the file
function wati_random_delay() {
    // Check if plugin is still active before sleeping
    if (!wati_is_plugin_active()) {
        error_log('WATI Debug: Plugin is not active. Skipping delay and terminating process.');
        return false;
    }
    
    $delay = rand(5, 10);
    error_log("WATI Debug: Sleeping for {$delay} seconds before next message");
    sleep($delay);
    
    // Check again after the delay
    return wati_is_plugin_active();
}

// Check abandoned carts
function check_abandoned_carts($settings, $is_test = false) {
    // Check if plugin is still active
    if (!wati_is_plugin_active() && !$is_test) {
        error_log('WATI Debug: Plugin is not active. Terminating abandoned cart check.');
        return array(
            'condition' => isset($settings['conditions']['abandoned']) ? $settings['conditions']['abandoned'] : array(),
            'error' => 'Plugin not active',
            'total_carts' => 0
        );
    }
    
    global $wpdb;
    
    $details = array(
        'condition' => $settings['conditions']['abandoned'],
        'found_carts' => array(),
        'total_carts' => 0,
        'eligible_carts' => 0,
        'already_notified' => 0,
        'no_phone' => 0,
        'old_carts' => 0
    );
    
    $condition = $settings['conditions']['abandoned'];
    $delay_minutes = $condition['delay_unit'] === 'hours' ? 
        $condition['delay_time'] * 60 : 
        $condition['delay_time'];

    $sql = "SELECT * FROM {$wpdb->prefix}cartflows_ca_cart_abandonment 
            WHERE order_status = 'abandoned' 
            AND unsubscribed = 0
            AND time <= DATE_SUB(NOW(), INTERVAL %d MINUTE)";

    // Add user filter if specific users are selected
    if (!empty($settings['specific_users'])) {
        $user_emails = array();
        foreach ($settings['specific_users'] as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $user_emails[] = $user->user_email;
            }
        }
        if (!empty($user_emails)) {
            $emails_list = "'" . implode("','", array_map('esc_sql', $user_emails)) . "'";
            $sql .= " AND email IN ($emails_list)";
        }
    }

    error_log('WATI Debug: Executing SQL: ' . $wpdb->prepare($sql, $delay_minutes));

    $abandoned_carts = $wpdb->get_results($wpdb->prepare($sql, $delay_minutes));
    $details['total_carts'] = count($abandoned_carts);

    foreach ($abandoned_carts as $cart) {
        // Check if plugin is still active before processing each cart
        if (!wati_is_plugin_active() && !$is_test) {
            error_log('WATI Debug: Plugin is no longer active during abandoned cart check. Stopping processing.');
            break;
        }
        
        $cart_info = array(
            'id' => $cart->id,
            'email' => $cart->email,
            'time' => $cart->time,
            'cart_total' => $cart->cart_total
        );

        // Check cutoff date
        if (!wati_check_order_date($cart->time)) {
            error_log('WATI Debug: Cart ' . $cart->id . ' is before cutoff date');
            $cart_info['status'] = 'old_cart';
            $details['old_carts']++;
            continue;
        }

        $notification_key = 'wati_abandoned_' . $cart->id;
        if (get_option($notification_key)) {
            $cart_info['status'] = 'already_notified';
            $details['already_notified']++;
            continue;
        }

        $other_fields = maybe_unserialize($cart->other_fields);
        $phone = isset($other_fields['wcf_phone_number']) ? $other_fields['wcf_phone_number'] : '';
        
        if (empty($phone)) {
            $cart_info['status'] = 'no_phone';
            $details['no_phone']++;
            continue;
        }

        $cart_info['status'] = 'eligible';
        $cart_info['phone'] = $phone;
        $cart_info['customer_name'] = $other_fields['wcf_first_name'] ?? '';
        $details['eligible_carts']++;
        
        if (!$is_test) {
            // Add random delay before sending if not the first message
            if ($details['eligible_carts'] > 0) {
                // If wati_random_delay returns false, plugin is not active anymore
                if (!wati_random_delay()) {
                    break;
                }
            }

            // Actual sending logic
            if (send_wati_template($phone, $condition['template_name'], $variables)) {
                update_option($notification_key, current_time('mysql'), false);
                $cart_info['notification_sent'] = true;
            }
        }

        $details['found_carts'][] = $cart_info;
    }

    return $details;
}

// Check processing orders
function check_processing_orders($settings, $is_test = false) {
    global $wpdb;
    
    $details = array(
        'condition' => $settings['conditions']['processing'],
        'found_orders' => array(),
        'total_orders' => 0,
        'eligible_orders' => 0,
        'already_notified' => 0,
        'no_phone' => 0
    );

    // Get processing orders
    $args = array(
        'status' => 'processing',
        'limit' => -1,
        'return' => 'ids'
    );
    
    $order_ids = wc_get_orders($args);
    $details['total_orders'] = count($order_ids);

    // Add user filter
    if (!empty($settings['specific_users'])) {
        $filtered_order_ids = array();
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && in_array($order->get_customer_id(), $settings['specific_users'])) {
                $filtered_order_ids[] = $order_id;
            }
        }
        $order_ids = $filtered_order_ids;
    }

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        // Check only processing notification key, not shipped
        $notification_key = 'wati_processing_' . $order_id;
        $order_info = array(
            'id' => $order_id,
            'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'total' => $order->get_total(),
            'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'phone' => $order->get_billing_phone(),
            'customer_id' => $order->get_customer_id()
        );

        if (get_option($notification_key)) {
            $order_info['status'] = 'already_notified';
            $details['already_notified']++;
            continue;
        }

        if (empty($order->get_billing_phone())) {
            $order_info['status'] = 'no_phone';
            $details['no_phone']++;
            continue;
        }

        $order_info['status'] = 'eligible';
        $details['eligible_orders']++;
        
        if (!$is_test) {
            // Add random delay before sending if not the first message
            if ($details['eligible_orders'] > 0) {
                // If wati_random_delay returns false, plugin is not active anymore
                if (!wati_random_delay()) {
                    break;
                }
            }

            $variables = array();
            foreach ($settings['conditions']['processing']['variables'] as $var) {
                switch ($var['type']) {
                    case 'customer_name':
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $order->get_billing_first_name()
                        );
                        break;
                    case 'order_number':
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $order_id
                        );
                        break;
                    case 'tracking_number':
                        $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);
                        if (!empty($tracking_number)) {
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $tracking_number
                            );
                        }
                        break;
                    case 'tracking_url':
                        $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);
                        if (!empty($tracking_number)) {
                            $tracking_url = "https://www.smsaexpress.com/sa/ar/trackingdetails?tracknumbers=" . $tracking_number;
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $tracking_url
                            );
                        }
                        break;
                }
            }

            if (send_wati_template($order->get_billing_phone(), $settings['conditions']['processing']['template_name'], $variables)) {
                update_option($notification_key, current_time('mysql'), false);
                $order_info['notification_sent'] = true;
            }
        }

        $details['found_orders'][] = $order_info;
    }

    return $details;
}

// Check shipped orders
function check_shipped_orders($settings, $is_test = false) {
    global $wpdb;
    
    $details = array(
        'condition' => $settings['conditions']['shipped'],
        'found_orders' => array(),
        'total_orders' => 0,
        'eligible_orders' => 0,
        'already_notified' => 0,
        'no_phone' => 0,
        'old_orders' => 0
    );

    // Get the delay time
    $condition = $settings['conditions']['shipped'];
    $delay_minutes = $condition['delay_unit'] === 'hours' ? 
        $condition['delay_time'] * 60 : 
        $condition['delay_time'];

    // Get shipped and completed orders
    $args = array(
        'status' => array('completed', 'shipped'),
        'limit' => -1,
        'return' => 'ids',
        'type' => 'shop_order'
    );
    
    $order_ids = wc_get_orders($args);
    $details['total_orders'] = count($order_ids);

    // Process orders in batches
    wati_process_orders_in_batches($order_ids, function($batch) use (&$details, $settings, $is_test, $delay_minutes) {
        foreach ($batch as $order_id) {
            try {
                $order = wc_get_order($order_id);
                if (!$order || $order->get_type() === 'shop_order_refund') {
                continue;
                }

                // Check order date
                $order_date = $order->get_date_created()->format('Y-m-d H:i:s');
                if (!wati_check_order_date($order_date)) {
                    $details['old_orders']++;
                continue;
            }

            // Get the status change time
            $status_changed = $order->get_date_modified()->getTimestamp();
            $time_diff = time() - $status_changed;
            $notification_key = 'wati_shipped_' . $order_id;

            error_log("WATI Debug: Processing order {$order_id}");
            error_log("WATI Debug: Status: " . $order->get_status());
            error_log("WATI Debug: Modified: " . $order->get_date_modified()->format('Y-m-d H:i:s'));
            error_log("WATI Debug: Time diff: {$time_diff} seconds");
            error_log("WATI Debug: Required delay: " . ($delay_minutes * 60) . " seconds");
            error_log("WATI Debug: Notification sent before: " . (get_option($notification_key) ? 'Yes' : 'No'));

            $order_info = array(
                'id' => $order_id,
                'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                'modified_date' => $order->get_date_modified()->format('Y-m-d H:i:s'),
                'total' => $order->get_total(),
                'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'phone' => $order->get_billing_phone(),
                'customer_id' => $order->get_customer_id(),
                'status' => $order->get_status()
            );

            // Skip if notification already sent
            if (get_option($notification_key)) {
                error_log("WATI Debug: Order {$order_id} already notified");
                $order_info['status'] = 'already_notified';
                $details['already_notified']++;
                continue;
            }

            // Skip if not enough time has passed (unless testing)
            if ($time_diff < ($delay_minutes * 60) && !$is_test) {
                error_log("WATI Debug: Order {$order_id} not ready for notification yet");
                continue;
            }

            if (empty($order->get_billing_phone())) {
                error_log("WATI Debug: Order {$order_id} has no phone number");
                $order_info['status'] = 'no_phone';
                $details['no_phone']++;
                continue;
            }

            $order_info['status'] = 'eligible';
            $details['eligible_orders']++;
            
            if (!$is_test) {
                // Add random delay before sending if not the first message
                if ($details['eligible_orders'] > 1) {
                    // If wati_random_delay returns false, plugin is not active anymore
                    if (!wati_random_delay()) {
                        break;
                    }
                }

                $variables = array();
                foreach ($settings['conditions']['shipped']['variables'] as $var) {
                    switch ($var['type']) {
                        case 'customer_name':
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $order->get_billing_first_name()
                            );
                            break;
                        case 'order_number':
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $order_id
                            );
                            break;
                        case 'tracking_number':
                            $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);
                            if (!empty($tracking_number)) {
                                $variables[] = array(
                                    'name' => $var['template_name'],
                                    'value' => $tracking_number
                                );
                            }
                            break;
                        case 'tracking_url':
                            $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);
                            if (!empty($tracking_number)) {
                                $tracking_url = "https://www.smsaexpress.com/sa/ar/trackingdetails?tracknumbers=" . $tracking_number;
                                $variables[] = array(
                                    'name' => $var['template_name'],
                                    'value' => $tracking_url
                                );
                            }
                            break;
                    }
                }

                error_log("WATI Debug: Attempting to send notification for order {$order_id}");
                error_log("WATI Debug: Template: " . $settings['conditions']['shipped']['template_name']);
                error_log("WATI Debug: Variables: " . print_r($variables, true));

                if (send_wati_template($order->get_billing_phone(), $settings['conditions']['shipped']['template_name'], $variables)) {
                    update_option($notification_key, current_time('mysql'), false);
                    $order_info['notification_sent'] = true;
                    error_log("WATI Debug: Successfully sent notification for order {$order_id}");
                } else {
                    error_log("WATI Debug: Failed to send notification for order {$order_id}");
                }
            }

            $details['found_orders'][] = $order_info;
        } catch (Exception $e) {
            error_log('WATI Debug: Error processing order ' . $order_id . ': ' . $e->getMessage());
            continue;
        }
    }
    });

    return $details;
}

// Error logging function
function wati_log_error($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('WATI Notifications: ' . $message);
    }
}

// Add this function to display the logs
function wati_notification_logs_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Handle log clearing
    if (isset($_POST['clear_logs']) && check_admin_referer('wati_clear_logs')) {
        delete_option('wati_notification_logs');
        echo '<div class="notice notice-success"><p>Logs cleared successfully.</p></div>';
    }

    $logs = get_option('wati_notification_logs', array());
    
    // Get emergency stop status
    $emergency_stop = wati_check_emergency_stop();
    $emergency_stop_details = get_option('wati_emergency_stop', array());
    ?>
    <div class="wrap">
        <h1>WATI Notification Logs</h1>
        
        <!-- Emergency Stop Status Card -->
        <div class="card emergency-status-card" style="margin-bottom: 20px; padding: 20px; background: <?php echo $emergency_stop ? '#fff3f3' : '#f3fff3'; ?>; border-left: 4px solid <?php echo $emergency_stop ? '#dc3232' : '#46b450'; ?>;">
            <h2 style="margin-top: 0;">Emergency Stop Status</h2>
            <div class="emergency-status">
                <p style="font-size: 16px; margin-bottom: 15px;">
                    <strong>Current Status:</strong> 
                    <span style="color: <?php echo $emergency_stop ? '#dc3232' : '#46b450'; ?>;">
                        <?php echo $emergency_stop ? 'ACTIVE' : 'INACTIVE'; ?>
                    </span>
                </p>
                <?php if ($emergency_stop && !empty($emergency_stop_details)): ?>
                    <div class="emergency-details">
                        <p><strong>Activated by Process ID:</strong> <?php echo esc_html($emergency_stop_details['process_id'] ?? 'Unknown'); ?></p>
                        <p><strong>Activated at:</strong> <?php echo esc_html($emergency_stop_details['timestamp'] ?? 'Unknown'); ?></p>
                    </div>
                <?php endif; ?>
                <form method="post" action="" style="margin-top: 15px;">
                    <?php if ($emergency_stop): ?>
                        <?php wp_nonce_field('wati_emergency_stop_disable'); ?>
                        <input type="hidden" name="action" value="wati_emergency_stop_disable">
                        <button type="submit" class="button button-primary">Disable Emergency Stop</button>
                    <?php else: ?>
                        <?php wp_nonce_field('wati_emergency_stop'); ?>
                        <input type="hidden" name="action" value="wati_emergency_stop">
                        <button type="submit" class="button button-danger">Enable Emergency Stop</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <div class="tablenav top">
            <form method="post" style="float: left; margin-right: 10px;">
                <?php wp_nonce_field('wati_clear_logs'); ?>
                <input type="submit" name="clear_logs" class="button" value="Clear Logs">
            </form>
            
            <div class="alignright">
                <select id="log-type-filter">
                    <option value="">All Types</option>
                    <option value="cron">Cron Jobs</option>
                    <option value="notification">Notifications</option>
                    <option value="error">Errors</option>
                    <option value="test">Test Results</option>
                    <option value="abandoned">Abandoned Cart</option>
                    <option value="processing">Processing Order</option>
                    <option value="shipped">Shipped Order</option>
                    <option value="tracking">Tracking</option>
                    <option value="discount">Discount</option>
                    <option value="custom">Custom</option>
                    <option value="emergency">Emergency Stop</option>
                </select>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Details</th>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Phone</th>
                    <th>Template</th>
                    <th>Status</th>
                    <th>Order/Cart ID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7">No logs found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach (array_reverse($logs) as $log): ?>
                        <tr class="log-entry" data-type="<?php echo esc_attr($log['type']); ?>">
                            <td>
                                <button type="button" class="button show-details">Show Details</button>
                                <div class="log-details" style="display: none;">
                                    <div class="log-details-content">
                                        <?php
                                        if (isset($log['details'])) {
                                            foreach ($log['details'] as $key => $value) {
                                                if (is_array($value)) {
                                                    echo '<div class="detail-row">';
                                                    echo '<strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong><br>';
                                                    if ($key === 'parameters' || $key === 'variables') {
                                                        echo '<div class="parameters-list">';
                                                        foreach ($value as $param) {
                                                            echo '<div class="parameter-item">';
                                                            echo '<span class="param-name">' . esc_html($param['name']) . ':</span> ';
                                                            echo '<span class="param-value">' . esc_html($param['value']) . '</span>';
                                                            echo '</div>';
                                                        }
                                                        echo '</div>';
                                                    } else {
                                                        echo '<pre>' . esc_html(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<div class="detail-row">';
                                                    echo '<strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong> ';
                                                    echo esc_html($value);
                                                    echo '</div>';
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo esc_html($log['time']); ?></td>
                            <td><?php echo esc_html(ucfirst($log['type'])); ?></td>
                            <td><?php echo esc_html($log['phone']); ?></td>
                            <td><?php echo esc_html($log['template']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr($log['status']); ?>">
                                    <?php echo esc_html(ucfirst($log['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $order_id = '';
                                if (isset($log['details']['order_id'])) {
                                    $order_id = $log['details']['order_id'];
                                } elseif (isset($log['details']['cart_id'])) {
                                    $order_id = $log['details']['cart_id'];
                                } elseif (isset($log['details']['parameters'])) {
                                    foreach ($log['details']['parameters'] as $param) {
                                        if ($param['name'] === 'order_number') {
                                            $order_id = $param['value'];
                                            break;
                                        }
                                    }
                                }
                                echo esc_html($order_id);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <style>
        .emergency-status-card {
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .emergency-details {
            background: #fff;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .emergency-details p {
            margin: 5px 0;
        }
    .status-badge {
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 12px;
            font-weight: 600;
        }
        .status-success { background: #46b450; color: white; }
        .status-error { background: #dc3232; color: white; }
        .status-warning { background: #ffb900; color: white; }
        .status-info { background: #00a0d2; color: white; }
        .log-details {
            margin-top: 10px;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .detail-row {
            margin-bottom: 10px;
        }
        .parameters-list {
            margin-left: 20px;
        }
        .parameter-item {
            margin: 5px 0;
        }
        .param-name {
            font-weight: 600;
            color: #23282d;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Show/hide details
        $('.show-details').click(function() {
            $(this).siblings('.log-details').toggle();
            $(this).text($(this).text() === 'Show Details' ? 'Hide Details' : 'Show Details');
        });

        // Filter logs
        $('#log-type-filter').change(function() {
            var type = $(this).val();
            if (type) {
                $('.log-entry').hide();
                $('.log-entry[data-type="' + type + '"]').show();
            } else {
                $('.log-entry').show();
            }
        });
    });
    </script>
    <?php
}

// Update the logging function to include automatic cleanup
function wati_log_notification($type, $phone, $template, $status, $details = array()) {
    $logs = get_option('wati_notification_logs', array());
    $one_week_ago = strtotime('-1 week');
    
    // Add new log
    $logs[] = array(
        'time' => current_time('mysql'),
        'type' => $type,
        'phone' => $phone,
        'template' => $template,
        'status' => $status,
        'details' => $details
    );

    // Filter out logs older than one week
    $logs = array_filter($logs, function($log) use ($one_week_ago) {
        return strtotime($log['time']) > $one_week_ago;
    });

    // Keep only last 1000 logs even if they're within a week
    if (count($logs) > 1000) {
        $logs = array_slice($logs, -1000);
    }

    update_option('wati_notification_logs', array_values($logs));
}

// Add a scheduled event for weekly cleanup
function wati_schedule_log_cleanup() {
    if (!wp_next_scheduled('wati_cleanup_old_logs')) {
        wp_schedule_event(time(), 'daily', 'wati_cleanup_old_logs');
    }
}
add_action('init', 'wati_schedule_log_cleanup');

// Add the cleanup function
function wati_cleanup_old_logs() {
    error_log('WATI Debug: Starting scheduled log cleanup');
    
    $logs = get_option('wati_notification_logs', array());
    $one_week_ago = strtotime('-1 week');
    $original_count = count($logs);
    
    // Filter out old logs
    $logs = array_filter($logs, function($log) use ($one_week_ago) {
        return strtotime($log['time']) > $one_week_ago;
    });
    
    $removed_count = $original_count - count($logs);
    
    if ($removed_count > 0) {
        update_option('wati_notification_logs', array_values($logs));
        error_log("WATI Debug: Removed {$removed_count} logs older than one week");
    }
    
    error_log('WATI Debug: Log cleanup completed');
}
add_action('wati_cleanup_old_logs', 'wati_cleanup_old_logs');

// Update the test_abandoned_check function
function test_abandoned_check() {
    check_ajax_referer('wati_ajax_nonce', 'nonce');
    
    error_log('WATI Debug: Starting manual test check');
    
    $settings = get_option('wati_notifications_settings', array());
    error_log('WATI Debug: Retrieved settings: ' . print_r($settings, true));
    
    if (empty($settings['enable_feature'])) {
        error_log('WATI Debug: Feature is disabled in settings');
        wp_send_json_error(array('message' => 'Feature is disabled in settings'));
        return;
    }

    $checks_performed = array();
    $check_details = array();

    // Check tracking notifications first
    if (!empty($settings['conditions']['tracking']['enabled'])) {
        error_log('WATI Debug: Testing tracking notifications...');
        $tracking_details = check_tracking_notifications($settings, true);
        $checks_performed['tracking'] = true;
        $check_details['tracking'] = $tracking_details;
    } else {
        error_log('WATI Debug: Tracking notifications are disabled');
    }

    // Check abandoned carts
    if (!empty($settings['conditions']['abandoned']['enabled'])) {
        error_log('WATI Debug: Testing abandoned cart notifications...');
        $abandoned_details = check_abandoned_carts($settings, true);
        $checks_performed['abandoned'] = true;
        $check_details['abandoned'] = $abandoned_details;
    }

    // Check discount reminders
    if (!empty($settings['conditions']['discount']['enabled'])) {
        error_log('WATI Debug: Testing discount reminders...');
        $discount_details = check_discount_notifications($settings, true);
        $checks_performed['discount'] = true;
        $check_details['discount'] = $discount_details;
    }

    // Check processing orders
    if (!empty($settings['conditions']['processing']['enabled'])) {
        error_log('WATI Debug: Testing processing order notifications...');
        $processing_details = check_processing_orders($settings, true);
        $checks_performed['processing'] = true;
        $check_details['processing'] = $processing_details;
    }

    // Check shipped orders
    if (!empty($settings['conditions']['shipped']['enabled'])) {
        error_log('WATI Debug: Testing shipped order notifications...');
        $shipped_details = check_shipped_orders($settings, true);
        $checks_performed['shipped'] = true;
        $check_details['shipped'] = $shipped_details;
    }

    // Log test completion with more details
    wati_log_notification('test', '', '', 'info', array(
        'message' => 'Manual test completed',
        'checks_performed' => $checks_performed,
        'check_details' => $check_details,
        'time' => current_time('mysql'),
        'settings_state' => array(
            'feature_enabled' => !empty($settings['enable_feature']),
            'tracking_enabled' => !empty($settings['conditions']['tracking']['enabled']),
            'tracking_template' => $settings['conditions']['tracking']['template_name'] ?? 'not set'
        )
    ));

    wp_send_json_success(array(
        'message' => 'Test completed',
        'checks_performed' => $checks_performed,
        'check_details' => $check_details
    ));
}

// Update the JavaScript for the test button
function wati_add_test_button() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Add the debug tools card
        $('.card:contains("Notification Conditions")').after(`
            <div class="card debug-tools">
                <div class="card-header">
                    <h2><span class="dashicons dashicons-admin-tools"></span> Debug Tools</h2>
                    <p class="description">Test and monitor your notification system</p>
                </div>
                <div class="debug-content">
                    <div class="test-section">
                        <button type="button" id="test-notifications" class="button button-primary">
                            <span class="dashicons dashicons-admin-tools"></span> Test Enabled Notifications
                        </button>
                        <div id="test-status" class="test-status">
                            <span class="spinner"></span>
                            <span class="status-text">Ready to test</span>
                        </div>
                    </div>
                    <div id="test-details" class="test-details" style="display: none;">
                        <h4>Test Results</h4>
                        <div class="test-summary">
                            <div class="summary-item">
                                <span class="label">Total Checks:</span>
                                <span class="value" id="total-checks">0</span>
                            </div>
                            <div class="summary-item">
                                <span class="label">Successful:</span>
                                <span class="value success" id="successful-checks">0</span>
                            </div>
                            <div class="summary-item">
                                <span class="label">Failed:</span>
                                <span class="value error" id="failed-checks">0</span>
                            </div>
                        </div>
                        <div id="checks-list" class="checks-list"></div>
                    </div>
                </div>
            </div>
        `);

        // Hide test details initially
        $('#test-details').hide();

        $('#test-notifications').click(function() {
            const button = $(this);
            const status = $('#test-status');
            const details = $('#test-details');
            const checksList = $('#checks-list');
            
            button.prop('disabled', true);
            status.find('.spinner').addClass('is-active');
            status.find('.status-text').text('Running tests...');
            details.hide();
            checksList.empty();
            
            $.post(ajaxurl, {
                action: 'test_abandoned_check',
                nonce: '<?php echo wp_create_nonce("wati_ajax_nonce"); ?>'
            })
            .done(function(response) {
                if (response.success) {
                    status.find('.status-text').html('<span style="color: #46b450;">✓ Test completed successfully</span>');
                    details.show();
                    
                    let checksHtml = '';
                    let totalChecks = 0;
                    let successfulChecks = 0;
                    let failedChecks = 0;
                    
                    const checks = response.data.checks_performed;
                    const checkDetails = response.data.check_details;
                    
                    Object.entries(checks).forEach(([type, performed]) => {
                        if (performed) {
                            totalChecks++;
                            const typeLabel = {
                                'abandoned': 'Abandoned Cart Notifications',
                                'discount': 'Discount Reminders',
                                'processing': 'Processing Order Notifications',
                                'shipped': 'Shipped Order Notifications',
                                'tracking': 'Tracking Notifications'
                            }[type];

                            const details = checkDetails[type];
                            // Consider a check successful if it completed without errors, even if no eligible orders
                            const isSuccess = details.total_orders > 0 || details.total_carts > 0;
                            
                            if (isSuccess) successfulChecks++;
                            else failedChecks++;

                            checksHtml += `
                                <div class="check-result ${isSuccess ? 'success' : 'error'}">
                                    <div class="check-header">
                                        <span class="check-type">${typeLabel}</span>
                                        <span class="check-status">${isSuccess ? '✓ Success' : '✗ Failed'}</span>
                                        <button type="button" class="button show-check-details" 
                                                data-type="${type}">Show Details</button>
                                    </div>
                                    <div class="check-details-content" id="${type}-details" 
                                         style="display: none;">
                                        ${formatCheckDetails(type, details)}
                                    </div>
                                </div>
                            `;
                        }
                    });
                    
                    $('#total-checks').text(totalChecks);
                    $('#successful-checks').text(successfulChecks);
                    $('#failed-checks').text(failedChecks);
                    
                    if (checksHtml) {
                        checksList.html(checksHtml);
                        details.show();
                    } else {
                        status.find('.status-text').html('<span style="color: #ffb900;">⚠ No notifications enabled</span>');
                    }
                } else {
                    status.find('.status-text').html('<span style="color: #dc3232;">✗ ' + 
                        (response.data.message || 'Test failed') + '</span>');
                }
            })
            .fail(function() {
                status.find('.status-text').html('<span style="color: #dc3232;">✗ Test failed - Server error</span>');
            })
            .always(function() {
                button.prop('disabled', false);
                status.find('.spinner').removeClass('is-active');
            });
        });

        // Handle showing/hiding check details
        $(document).on('click', '.show-check-details', function() {
            const type = $(this).data('type');
            const detailsDiv = $(`#${type}-details`);
            detailsDiv.slideToggle();
            $(this).text(detailsDiv.is(':visible') ? 'Hide Details' : 'Show Details');
        });

        function formatCheckDetails(type, details) {
            let html = '<div class="details-content">';
            
            if (type === 'abandoned' || type === 'discount') {
                html += `
                    <div class="detail-row">
                        <span class="label">Total Carts:</span>
                        <span class="value">${details.total_carts}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Eligible Carts:</span>
                        <span class="value">${details.eligible_carts}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Already Notified:</span>
                        <span class="value">${details.already_notified}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">No Phone Number:</span>
                        <span class="value">${details.no_phone}</span>
                    </div>
                `;
            } else {
                html += `
                    <div class="detail-row">
                        <span class="label">Total Orders:</span>
                        <span class="value">${details.total_orders}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Eligible Orders:</span>
                        <span class="value">${details.eligible_orders}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Already Notified:</span>
                        <span class="value">${details.already_notified}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">No Phone Number:</span>
                        <span class="value">${details.no_phone}</span>
                    </div>
                `;
            }

            if (details.found_orders && details.found_orders.length > 0) {
                html += '<div class="orders-list">';
                details.found_orders.forEach(order => {
                    html += `
                        <div class="order-item">
                            <div class="order-header">
                                <span class="order-id">Order #${order.id}</span>
                                <span class="order-status ${order.status}">${order.status}</span>
                            </div>
                            <div class="order-details">
                                <div class="detail">Customer: ${order.customer}</div>
                                <div class="detail">Phone: ${order.phone}</div>
                                <div class="detail">Date: ${order.date}</div>
                                <div class="detail">Total: ${order.total}</div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            }

            html += '</div>';
            return html;
        }
    });
    </script>

    <style>
    .debug-tools {
        margin-top: 20px;
    }
    .debug-tools .card-header {
        border-bottom: 1px solid #ddd;
        padding-bottom: 15px;
        margin-bottom: 15px;
    }
    .debug-tools .card-header h2 {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .debug-tools .card-header .dashicons {
        color: #2271b1;
    }
    .test-section {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }
    .test-status {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .test-status .spinner {
        float: none;
        margin: 0;
    }
    .test-details {
        background: #f9f9f9;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        margin-top: 15px;
    }
    .test-summary {
        display: flex;
        gap: 20px;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid #ddd;
    }
    .summary-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .summary-item .label {
        font-size: 12px;
        color: #666;
    }
    .summary-item .value {
        font-size: 18px;
        font-weight: bold;
    }
    .summary-item .value.success {
        color: #46b450;
    }
    .summary-item .value.error {
        color: #dc3232;
    }
    .check-result {
        border: 1px solid #ddd;
        border-radius: 4px;
        margin-bottom: 10px;
        overflow: hidden;
    }
    .check-result.success {
        border-left: 4px solid #46b450;
    }
    .check-result.error {
        border-left: 4px solid #dc3232;
    }
    .check-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        background: #f9f9f9;
    }
    .check-type {
        flex: 1;
        font-weight: bold;
    }
    .check-status {
        font-weight: bold;
    }
    .check-status:before {
        margin-right: 5px;
    }
    .success .check-status {
        color: #46b450;
    }
    .error .check-status {
        color: #dc3232;
    }
    .check-details-content {
        padding: 15px;
        background: #fff;
    }
    .detail-row {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
    }
    .detail-row .label {
        font-weight: bold;
        min-width: 120px;
    }
    .orders-list {
        margin-top: 15px;
    }
    .order-item {
        border: 1px solid #eee;
        border-radius: 4px;
        padding: 10px;
        margin-bottom: 10px;
    }
    .order-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }
    .order-status {
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 12px;
    }
    .order-status.eligible {
        background: #dff0d8;
        color: #3c763d;
    }
    .order-status.already_notified {
        background: #fcf8e3;
        color: #8a6d3b;
    }
    .order-status.no_phone {
        background: #f2dede;
        color: #a94442;
    }
    .order-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
    }
    .order-details .detail {
        font-size: 13px;
        color: #666;
    }
    </style>
    <?php
}

// Add this new function
function check_discount_notifications($settings, $is_test = false) {
    global $wpdb;
    
    error_log('WATI Debug: Starting discount reminder check at ' . current_time('mysql'));
    
    $condition = $settings['conditions']['discount'];
    $delay_minutes = $condition['delay_unit'] === 'hours' ? 
        $condition['delay_time'] * 60 : 
        $condition['delay_time'];

    error_log('WATI Debug: Checking for abandoned carts eligible for discount reminder after ' . $delay_minutes . ' minutes');

    $sql = "SELECT ca.* FROM {$wpdb->prefix}cartflows_ca_cart_abandonment ca
            WHERE ca.order_status = 'abandoned' 
            AND ca.unsubscribed = 0
            AND ca.time <= DATE_SUB(NOW(), INTERVAL %d MINUTE)
            AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}options 
                WHERE option_name = CONCAT('wati_abandoned_', ca.id)
            )
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}options 
                WHERE option_name = CONCAT('wati_discount_', ca.id)
            )";

    // Add user filter if specific users are selected
    if (!empty($settings['specific_users'])) {
        $user_emails = array();
        foreach ($settings['specific_users'] as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $user_emails[] = $user->user_email;
            }
        }
        if (!empty($user_emails)) {
            $emails_list = "'" . implode("','", array_map('esc_sql', $user_emails)) . "'";
            $sql .= " AND ca.email IN ($emails_list)";
        }
    }

    error_log('WATI Debug: Executing discount SQL: ' . $wpdb->prepare($sql, $delay_minutes));

    $eligible_carts = $wpdb->get_results($wpdb->prepare($sql, $delay_minutes));
    
    error_log('WATI Debug: Found ' . count($eligible_carts) . ' carts eligible for discount reminder');

    $details = array(
        'condition' => $condition,
        'found_carts' => array(),
        'total_carts' => count($eligible_carts),
        'eligible_carts' => 0,
        'already_notified' => 0,
        'no_phone' => 0
    );

    foreach ($eligible_carts as $cart) {
        error_log('WATI Debug: Processing discount reminder for cart ID ' . $cart->id);

        $other_fields = maybe_unserialize($cart->other_fields);
        $phone = isset($other_fields['wcf_phone_number']) ? $other_fields['wcf_phone_number'] : '';
        
        if (empty($phone)) {
            error_log('WATI Debug: No phone number for cart ' . $cart->id);
            $details['no_phone']++;
            continue;
        }

        error_log('WATI Debug: Attempting to send discount reminder for cart ' . $cart->id . ' to ' . $phone);

        // Prepare variables
        $variables = array();
        foreach ($condition['variables'] as $var) {
            switch ($var['type']) {
                case 'customer_name':
                    $variables[] = array(
                        'name' => $var['template_name'],
                        'value' => $other_fields['wcf_first_name'] ?? ''
                    );
                    break;
                case 'order_number':
                    $variables[] = array(
                        'name' => $var['template_name'],
                        'value' => $cart->id
                    );
                    break;
                case 'tracking_number':
                    $tracking_number = get_post_meta($cart->id, 'smsa_awb_no', true);
                    if (!empty($tracking_number)) {
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $tracking_number
                        );
                    }
                    break;
                case 'tracking_url':
                    $tracking_number = get_post_meta($cart->id, 'smsa_awb_no', true);
                    if (!empty($tracking_number)) {
                        $tracking_url = "https://www.smsaexpress.com/sa/ar/trackingdetails?tracknumbers=" . $tracking_number;
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $tracking_url
                        );
                    }
                    break;
                    
            }
        }

        error_log('WATI Debug: Sending discount template ' . $condition['template_name'] . ' with variables: ' . print_r($variables, true));

        $details['eligible_carts']++;
        
        if (!$is_test) {
            // Add random delay before sending if not the first message
            if ($details['eligible_carts'] > 0) {
                // If wati_random_delay returns false, plugin is not active anymore
                if (!wati_random_delay()) {
                    break;
                }
            }

            if (send_wati_template($phone, $condition['template_name'], $variables)) {
                error_log('WATI Debug: Successfully sent discount reminder for cart ' . $cart->id);
                update_option('wati_discount_' . $cart->id, current_time('mysql'), false);
                
                // Log the discount notification
                wati_log_notification('discount', $phone, $condition['template_name'], 'success', array(
                    'cart_id' => $cart->id,
                    'delay_minutes' => $delay_minutes,
                    'variables' => $variables
                ));
            } else {
                error_log('WATI Debug: Failed to send discount reminder for cart ' . $cart->id);
                
                // Log the failure
                wati_log_notification('discount', $phone, $condition['template_name'], 'error', array(
                    'cart_id' => $cart->id,
                    'error' => 'Failed to send discount reminder'
                ));
            }
        }

        $details['found_carts'][] = array(
            'id' => $cart->id,
            'email' => $cart->email,
            'time' => $cart->time,
            'cart_total' => $cart->cart_total,
            'status' => $details['status']
        );
    }

    return $details;
}

// Also register the shipped status if it doesn't exist
function wati_register_shipped_order_status() {
    register_post_status('wc-shipped', array(
        'label' => 'Shipped',
        'public' => true,
        'show_in_admin_status_list' => true,
        'show_in_admin_all_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop('Shipped <span class="count">(%s)</span>',
            'Shipped <span class="count">(%s)</span>')
    ));
}
add_action('init', 'wati_register_shipped_order_status');

// Add shipped status to WooCommerce order statuses
function wati_add_shipped_to_order_statuses($order_statuses) {
    $new_order_statuses = array();
    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;
        if ($key === 'wc-completed') {
            $new_order_statuses['wc-shipped'] = 'Shipped';
        }
    }
    return $new_order_statuses;
}
add_filter('wc_order_statuses', 'wati_add_shipped_to_order_statuses');

// Track order status changes
function wati_track_order_status_change($order_id, $old_status, $new_status, $order) {
    if (wati_check_emergency_stop()) {
        error_log('WATI Debug: Emergency stop active - skipping order status change check');
        return;
    }
    
    error_log("WATI Debug: Order status changed - Order: {$order_id}");
    error_log("WATI Debug: Old status: {$old_status}");
    error_log("WATI Debug: New status: {$new_status}");
    
    // If the new status is completed or shipped, force a check
    if (in_array($new_status, array('completed', 'shipped'))) {
        error_log("WATI Debug: Triggering immediate check for order {$order_id}");
        
        $settings = get_option('wati_notifications_settings', array());
        if (!empty($settings['conditions']['shipped']['enabled'])) {
            check_shipped_orders($settings);
        }
    }

    // Check for tracking number when status changes
    $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);
    if (!empty($tracking_number)) {
        error_log("WATI Debug: Tracking number found for order {$order_id}");
        $settings = get_option('wati_notifications_settings', array());
        if (!empty($settings['conditions']['tracking']['enabled'])) {
            check_tracking_notifications($settings);
        }
    }
}

// Check custom template notifications
function check_custom_notifications($settings, $is_test = false) {
    global $wpdb;
    
    $details = array(
        'condition' => $settings['conditions']['custom'],
        'found_orders' => array(),
        'total_orders' => 0,
        'eligible_orders' => 0,
        'already_notified' => 0,
        'no_phone' => 0
    );

    // Get the delay time
    $condition = $settings['conditions']['custom'];
    $delay_minutes = $condition['delay_unit'] === 'hours' ? 
        $condition['delay_time'] * 60 : 
        $condition['delay_time'];

    // Get all orders
    $args = array(
        'status' => array('any'),
        'limit' => -1,
        'return' => 'ids'
    );
    
    $order_ids = wc_get_orders($args);
    $details['total_orders'] = count($order_ids);

    error_log('WATI Debug: Found ' . count($order_ids) . ' orders for custom template');

    // Add user filter
    if (!empty($settings['specific_users'])) {
        $filtered_order_ids = array();
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && in_array($order->get_customer_id(), $settings['specific_users'])) {
                $filtered_order_ids[] = $order_id;
            }
        }
        $order_ids = $filtered_order_ids;
    }

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        $notification_key = 'wati_custom_' . $order_id;

        error_log("WATI Debug: Processing order {$order_id} for custom template");

        $order_info = array(
            'id' => $order_id,
            'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'total' => $order->get_total(),
            'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'phone' => $order->get_billing_phone(),
            'customer_id' => $order->get_customer_id(),
            'status' => $order->get_status()
        );

        // Skip if notification already sent
        if (get_option($notification_key)) {
            error_log("WATI Debug: Order {$order_id} already notified for custom template");
            $order_info['status'] = 'already_notified';
            $details['already_notified']++;
            continue;
        }

        if (empty($order->get_billing_phone())) {
            error_log("WATI Debug: Order {$order_id} has no phone number");
            $order_info['status'] = 'no_phone';
            $details['no_phone']++;
            continue;
        }

        $order_info['status'] = 'eligible';
        $details['eligible_orders']++;
        
        if (!$is_test) {
            // Add random delay before sending if not the first message
            if ($details['eligible_orders'] > 1) {
                // If wati_random_delay returns false, plugin is not active anymore
                if (!wati_random_delay()) {
                    break;
                }
            }

            $variables = array();
            foreach ($settings['conditions']['custom']['variables'] as $var) {
                switch ($var['type']) {
                    case 'customer_name':
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $order->get_billing_first_name()
                        );
                        break;
                    case 'order_number':
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $order_id
                        );
                        break;
                    case 'tracking_number':
                        $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);
                        if (!empty($tracking_number)) {
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $tracking_number
                            );
                        }
                        break;
                    case 'tracking_url':
                        $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);
                        if (!empty($tracking_number)) {
                            $tracking_url = "https://www.smsaexpress.com/sa/ar/trackingdetails?tracknumbers=" . $tracking_number;
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $tracking_url
                            );
                        }
                        break;
                }
            }

            error_log("WATI Debug: Attempting to send custom notification for order {$order_id}");
            error_log("WATI Debug: Template: " . $settings['conditions']['custom']['template_name']);
            error_log("WATI Debug: Variables: " . print_r($variables, true));

            if (send_wati_template($order->get_billing_phone(), $settings['conditions']['custom']['template_name'], $variables)) {
                update_option($notification_key, current_time('mysql'), false);
                $order_info['notification_sent'] = true;
                error_log("WATI Debug: Successfully sent custom notification for order {$order_id}");
            } else {
                error_log("WATI Debug: Failed to send custom notification for order {$order_id}");
            }
        }

        $details['found_orders'][] = $order_info;
    }

    return $details;
} 

// Check tracking notifications
function check_tracking_notifications($settings, $is_test = false) {
    global $wpdb;
    
    error_log('WATI Debug: Starting tracking notifications check with detailed logging');
    error_log('WATI Debug: Settings for tracking: ' . print_r($settings['conditions']['tracking'], true));
    
    $details = array(
        'condition' => $settings['conditions']['tracking'],
        'found_orders' => array(),
        'total_orders' => 0,
        'eligible_orders' => 0,
        'already_notified' => 0,
        'no_phone' => 0,
        'old_orders' => 0
    );

    // Verify tracking notifications are enabled
    if (empty($settings['conditions']['tracking']['enabled'])) {
        error_log('WATI Debug: Tracking notifications are disabled in settings');
        return $details;
    }

    // Direct database query to verify tracking numbers exist
    $tracking_numbers = $wpdb->get_results(
        "SELECT post_id, meta_value 
         FROM {$wpdb->postmeta} 
         WHERE meta_key = 'smsa_awb_no' 
         AND meta_value IS NOT NULL 
         AND meta_value != ''",
        ARRAY_A
    );
    
    error_log('WATI Debug: Found tracking numbers in database: ' . print_r($tracking_numbers, true));

    if (empty($tracking_numbers)) {
        error_log('WATI Debug: No orders found with tracking numbers');
        return $details;
    }

    // Extract order IDs with valid tracking numbers
    $order_ids = array_map(function($row) {
        return $row['post_id'];
    }, $tracking_numbers);

    error_log('WATI Debug: Order IDs with tracking numbers: ' . print_r($order_ids, true));

    $details['total_orders'] = count($order_ids);

    // Add user filter
    if (!empty($settings['specific_users'])) {
        error_log('WATI Debug: Filtering for specific users: ' . print_r($settings['specific_users'], true));
        $filtered_order_ids = array();
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && in_array($order->get_customer_id(), $settings['specific_users'])) {
                $filtered_order_ids[] = $order_id;
            }
        }
        $order_ids = $filtered_order_ids;
        error_log('WATI Debug: After user filtering, remaining orders: ' . count($order_ids));
    }

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("WATI Debug: Could not load order {$order_id}");
            continue;
        }

        // Check order date
        $order_date = $order->get_date_created()->format('Y-m-d H:i:s');
        if (!wati_check_order_date($order_date)) {
            error_log("WATI Debug: Order {$order_id} is before cutoff date");
            $details['old_orders']++;
            continue;
        }

        $notification_key = 'wati_tracking_' . $order_id;
        $tracking_number = get_post_meta($order_id, 'smsa_awb_no', true);

        error_log("WATI Debug: Processing order {$order_id}");
        error_log("WATI Debug: Tracking number: {$tracking_number}");
        error_log("WATI Debug: Previous notification check: " . (get_option($notification_key) ? 'Yes' : 'No'));

        $order_info = array(
            'id' => $order_id,
            'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'total' => $order->get_total(),
            'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'phone' => $order->get_billing_phone(),
            'customer_id' => $order->get_customer_id(),
            'status' => $order->get_status(),
            'tracking_number' => $tracking_number
        );

        error_log("WATI Debug: Order info: " . print_r($order_info, true));

        // Skip if notification already sent
        if (get_option($notification_key)) {
            error_log("WATI Debug: Order {$order_id} already notified for tracking");
            $order_info['status'] = 'already_notified';
            $details['already_notified']++;
            continue;
        }

        if (empty($order->get_billing_phone())) {
            error_log("WATI Debug: Order {$order_id} has no phone number");
            $order_info['status'] = 'no_phone';
            $details['no_phone']++;
            continue;
        }

        $order_info['status'] = 'eligible';
        $details['eligible_orders']++;
        
        if (!$is_test) {
            // Add random delay before sending if not the first message
            if ($details['eligible_orders'] > 1) {
                // If wati_random_delay returns false, plugin is not active anymore
                if (!wati_random_delay()) {
                    break;
                }
            }

            $variables = array();
            foreach ($settings['conditions']['tracking']['variables'] as $var) {
                switch ($var['type']) {
                    case 'customer_name':
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $order->get_billing_first_name()
                        );
                        break;
                    case 'order_number':
                        $variables[] = array(
                            'name' => $var['template_name'],
                            'value' => $order_id
                        );
                        break;
                    case 'tracking_number':
                        if (!empty($tracking_number)) {
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $tracking_number
                            );
                        }
                        break;
                    case 'tracking_url':
                        if (!empty($tracking_number)) {
                            $tracking_url = "https://www.smsaexpress.com/sa/ar/trackingdetails?tracknumbers=" . $tracking_number;
                            $variables[] = array(
                                'name' => $var['template_name'],
                                'value' => $tracking_url
                            );
                        }
                        break;
                }
            }

            error_log("WATI Debug: Template variables prepared: " . print_r($variables, true));
            error_log("WATI Debug: Attempting to send tracking notification for order {$order_id}");
            error_log("WATI Debug: Template: " . $settings['conditions']['tracking']['template_name']);

            if (send_wati_template($order->get_billing_phone(), $settings['conditions']['tracking']['template_name'], $variables)) {
                update_option($notification_key, current_time('mysql'), false);
                $order_info['notification_sent'] = true;
                error_log("WATI Debug: Successfully sent tracking notification for order {$order_id}");
            } else {
                error_log("WATI Debug: Failed to send tracking notification for order {$order_id}");
            }
        }

        $details['found_orders'][] = $order_info;
    }

    error_log('WATI Debug: Tracking check completed. Details: ' . print_r($details, true));
    return $details;
}

// Add hook for tracking number updates
function wati_track_tracking_number_update($meta_id, $post_id, $meta_key, $meta_value) {
    if (wati_check_emergency_stop()) {
        error_log('WATI Debug: Emergency stop active - skipping tracking number update check');
        return;
    }
    
    // Only proceed if this is a tracking number update
    if ($meta_key !== 'smsa_awb_no' || empty($meta_value)) {
        return;
    }

    error_log("WATI Debug: Tracking number updated for order {$post_id}: {$meta_value}");
    
    // Check if this is a WooCommerce order
    if (get_post_type($post_id) !== 'shop_order') {
        return;
    }

    $settings = get_option('wati_notifications_settings', array());
    if (!empty($settings['conditions']['tracking']['enabled'])) {
        error_log("WATI Debug: Triggering tracking notification check for order {$post_id}");
        check_tracking_notifications($settings);
    }
}
add_action('updated_post_meta', 'wati_track_tracking_number_update', 10, 4);
add_action('added_post_meta', 'wati_track_tracking_number_update', 10, 4);

// Add these new functions after the plugin header but before any other functions
function wati_rate_limit_check() {
    $last_run = get_option('wati_last_run', 0);
    $current_time = time();
    
    // Only allow one run per minute
    if ($current_time - $last_run < 60) {
        error_log('WATI Debug: Rate limit hit - skipping this run');
        return false;
    }
    
    update_option('wati_last_run', $current_time);
    return true;
}

function wati_emergency_stop() {
    global $wpdb;
    
    error_log('WATI Debug: Emergency stop activated for WATI notifications by process ' . getmypid());
    
    // Store process ID and timestamp
    $emergency_data = array(
        'active' => true,
        'timestamp' => current_time('mysql'),
        'process_id' => getmypid()
    );
    
    // Set both transient and option for redundancy with serialized value for consistency
    $serialized_data = serialize($emergency_data);
    
    // Direct DB writes to ensure they happen immediately
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) 
         VALUES (%s, %s, %s) 
         ON DUPLICATE KEY UPDATE option_value = %s",
        'wati_emergency_stop', $serialized_data, 'yes', $serialized_data
    ));
    
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) 
         VALUES (%s, %s, %s) 
         ON DUPLICATE KEY UPDATE option_value = %s",
        '_transient_wati_emergency_stop', $serialized_data, 'no', $serialized_data
    ));
    
    // Also write to options API for redundancy
    update_option('wati_emergency_stop', $emergency_data, 'yes');
    set_transient('wati_emergency_stop', $emergency_data, 12 * HOUR_IN_SECONDS);
    
    // Set stop signal with current timestamp
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) 
         VALUES (%s, %s, %s) 
         ON DUPLICATE KEY UPDATE option_value = %s",
        'wati_notification_stop_signal', current_time('mysql'), 'yes', current_time('mysql')
    ));
    
    // Try to stop only WATI notification processes
    wati_terminate_notification_processes();
    
    // Force kill any remaining WATI processes
    wati_kill_notification_processes();

    // Clear any pending WATI notifications
    delete_option('wati_pending_notifications');
    
    // Clear WATI processing state
    wati_clear_processing_state();
    
    // Reset retry counters
    wati_reset_retry_counts();
    
    // Log the emergency stop with detailed information
    wati_log_notification('emergency', '', '', 'warning', array(
        'message' => 'Emergency stop activated for WATI notifications',
        'time' => current_time('mysql'),
        'process_id' => getmypid()
    ));
    
    return true;
}

function wati_check_emergency_stop($bypass_cache = false) {
    global $wpdb;
    
    // If bypass_cache is true, directly check the database
    if ($bypass_cache) {
        $option_value = $wpdb->get_var("
            SELECT option_value FROM {$wpdb->options} 
            WHERE option_name = 'wati_emergency_stop' 
            LIMIT 1
        ");
        
        if ($option_value) {
            $emergency_stop = maybe_unserialize($option_value);
            if (is_array($emergency_stop) && isset($emergency_stop['active']) && $emergency_stop['active']) {
                error_log('WATI Debug: Emergency stop check (DB direct) - Stop is active');
                return true;
            }
        }
        
        // Also check the transient directly for thoroughness
        $transient_value = $wpdb->get_var("
            SELECT option_value FROM {$wpdb->options} 
            WHERE option_name = '_transient_wati_emergency_stop' 
            LIMIT 1
        ");
        
        if ($transient_value) {
            $transient_stop = maybe_unserialize($transient_value);
            if (is_array($transient_stop) && isset($transient_stop['active']) && $transient_stop['active']) {
                error_log('WATI Debug: Emergency stop check (transient DB direct) - Stop is active');
                return true;
            }
        }
        
        return false;
    }
    
    // Standard check for normal operation (cached)
    // Check transient first (faster)
    $emergency_stop = get_transient('wati_emergency_stop');
    
    // If no transient, check option
    if (false === $emergency_stop) {
        $emergency_stop = get_option('wati_emergency_stop', false);
    }
    
    // If emergency stop is active, log it
    if (is_array($emergency_stop) && isset($emergency_stop['active']) && $emergency_stop['active']) {
        error_log('WATI Debug: Emergency stop check - Stop is active (set by process ' . 
            (isset($emergency_stop['process_id']) ? $emergency_stop['process_id'] : 'unknown') . 
            ' at ' . $emergency_stop['timestamp'] . ')');
        return true;
    }
    
    return false;
}

function wati_terminate_notification_processes() {
    global $wpdb;
    
    // Get current process ID
    $current_pid = getmypid();
    
    // Log termination attempt
    error_log('WATI Debug: Attempting to terminate WATI notification processes from process ' . $current_pid);
    
    // Set a flag specifically for WATI processes
    $wpdb->query("
        INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
        VALUES ('wati_notification_stop_signal', NOW(), 'yes')
        ON DUPLICATE KEY UPDATE option_value = NOW()
    ");

    // Clear specific WATI-related options
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wati_processing_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wati_batch_%'");
    
    return true;
}

function wati_check_stop_signal() {
    global $wpdb;
    
    // Direct DB check for maximum reliability - bypass cache
    $stop_signal = $wpdb->get_var("
        SELECT option_value FROM {$wpdb->options} 
        WHERE option_name = 'wati_notification_stop_signal' 
        LIMIT 1
    ");
    
    if ($stop_signal) {
        // Check if signal is recent (within last 5 minutes)
        $signal_time = strtotime($stop_signal);
        if (time() - $signal_time < 300) {
            error_log('WATI Debug: Stop signal detected for WATI notifications, terminating process ' . getmypid());
            return true;
        }
    }
    
    return false;
}

function wati_check_order_date($order_date) {
    $settings = get_option('wati_notifications_settings', array());
    $cutoff_date = isset($settings['cutoff_date']) ? strtotime($settings['cutoff_date']) : strtotime('2025-04-22');
    $order_timestamp = strtotime($order_date);
    
    if ($order_timestamp < $cutoff_date) {
        error_log('WATI Debug: Order date ' . $order_date . ' is before cutoff date ' . date('Y-m-d', $cutoff_date));
        return false;
    }
    return true;
}

// Update the batch processing function to include both rate limit and emergency stop checks
function wati_process_orders_in_batches($order_ids, $callback, $batch_size = 50) {
    // First check if plugin is still active
    if (!wati_is_plugin_active()) {
        error_log('WATI Debug: Plugin is not active. Terminating batch processing.');
        return;
    }
    
    // Check both emergency stop and stop signal at the start
    if (wati_check_emergency_stop() || wati_check_stop_signal()) {
        error_log('WATI Debug: Emergency stop/signal active - skipping batch processing');
        wati_log_notification('emergency', '', '', 'warning', array(
            'message' => 'Batch processing stopped due to emergency stop/signal',
            'time' => current_time('mysql'),
            'batch_size' => count($order_ids),
            'process_id' => getmypid(),
            'processing_state' => get_option('wati_processing_state', array())
        ));
        return;
    }
    
    $total_orders = count($order_ids);
    $batches = array_chunk($order_ids, $batch_size);
    $total_batches = count($batches);
    $start_time = time();
    $max_batch_time = 300; // 5 minutes per batch
    
    error_log("WATI Debug: Starting batch processing of {$total_orders} orders in {$total_batches} batches (Process ID: " . getmypid() . ")");
    
    foreach ($batches as $batch_index => $batch) {
        // Check if plugin is still active before each batch
        if (!wati_is_plugin_active()) {
            error_log("WATI Debug: Plugin is no longer active during batch processing. Stopped at batch {$batch_index} of {$total_batches}");
            return;
        }
        
        // Check emergency stop and stop signal before each batch
        if (wati_check_emergency_stop() || wati_check_stop_signal()) {
            error_log("WATI Debug: Emergency stop/signal activated during batch processing. Stopped at batch {$batch_index} of {$total_batches}");
            wati_log_notification('emergency', '', '', 'warning', array(
                'message' => 'Batch processing interrupted by emergency stop/signal',
                'time' => current_time('mysql'),
                'batch_index' => $batch_index,
                'total_batches' => $total_batches,
                'remaining_orders' => $total_orders - ($batch_index * $batch_size),
                'process_id' => getmypid(),
                'processing_state' => get_option('wati_processing_state', array())
            ));
            return;
        }
        
        // Process each order individually
        foreach ($batch as $order_id) {
            // Check if plugin is still active before each order
            if (!wati_is_plugin_active()) {
                error_log("WATI Debug: Plugin is no longer active during order processing. Stopped at order {$order_id}");
                return;
            }
            
            // Check emergency stop and stop signal before each order
            if (wati_check_emergency_stop() || wati_check_stop_signal()) {
                error_log("WATI Debug: Emergency stop/signal activated during order processing. Stopped at order {$order_id}");
                wati_log_notification('emergency', '', '', 'warning', array(
                    'message' => 'Order processing interrupted by emergency stop/signal',
                    'time' => current_time('mysql'),
                    'order_id' => $order_id,
                    'batch_index' => $batch_index,
                    'total_batches' => $total_batches,
                    'process_id' => getmypid(),
                    'processing_state' => get_option('wati_processing_state', array())
                ));
                return;
            }
            
            try {
                // Process single order
                call_user_func($callback, array($order_id));
                
                // Small delay between orders to prevent overwhelming the system
                usleep(100000); // 100ms delay
                
            } catch (Exception $e) {
                error_log("WATI Debug: Error processing order {$order_id}: " . $e->getMessage());
                continue;
            }
        }
        
        // Log progress after each batch
        $processed = ($batch_index + 1) * $batch_size;
        $progress = min($processed, $total_orders);
        error_log("WATI Debug: Progress: {$progress}/{$total_orders} orders processed (Process ID: " . getmypid() . ")");
    }
}

// Enhanced processing state tracking
function wati_track_processing_state($order_id, $data) {
    $processing_state = get_option('wati_processing_state', array());
    
    if ($order_id === 'current_batch') {
        $processing_state['current_batch'] = $data;
    } else {
        $processing_state[$order_id] = array_merge(
            $processing_state[$order_id] ?? array(),
            $data
        );
    }
    
    update_option('wati_processing_state', $processing_state);
}

// Add function to get processing summary
function wati_get_processing_summary() {
    $processing_state = get_option('wati_processing_state', array());
    $current_batch = $processing_state['current_batch'] ?? null;
    
    if (!$current_batch) {
        return array(
            'status' => 'idle',
            'message' => 'No processing in progress'
        );
    }
    
    $summary = array(
        'status' => $current_batch['status'] ?? 'processing',
        'batch_index' => $current_batch['batch_index'] ?? 0,
        'start_time' => $current_batch['start_time'] ?? null,
        'end_time' => $current_batch['end_time'] ?? null,
        'total_orders' => $current_batch['total_orders'] ?? 0
    );
    
    if (isset($current_batch['error'])) {
        $summary['error'] = $current_batch['error'];
    }
    
    return $summary;
}

// Add function to clear processing state
function wati_clear_processing_state() {
    delete_option('wati_processing_state');
    error_log('WATI Debug: Processing state cleared');
}

// Add function to reset retry counts
function wati_reset_retry_counts() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wati_retry_%'");
}
add_action('wati_cleanup_old_logs', 'wati_reset_retry_counts');

// ... existing code ...
function wati_kill_notification_processes() {
    global $wpdb;
    
    // Get all active WATI notification processes
    $processing_state = get_option('wati_notification_processing', array());
    $current_pid = getmypid();
    
    error_log('WATI Debug: Attempting to kill WATI notification processes. Current PID: ' . $current_pid);
    
    // Get all PHP-FPM processes
    $processes = array();
    exec('ps aux | grep php-fpm', $processes);
    
    foreach ($processes as $process) {
        // Skip the grep process itself
        if (strpos($process, 'grep') !== false) {
            continue;
        }
        
        // Extract PID from process line
        preg_match('/^\S+\s+(\d+)/', $process, $matches);
        if (isset($matches[1])) {
            $pid = $matches[1];
            
            // Skip current process
            if ($pid == $current_pid) {
                continue;
            }
            
            // Check if this is a WATI notification process
            if (strpos($process, 'wati') !== false || strpos($process, 'notification') !== false) {
                error_log('WATI Debug: Killing WATI notification process with PID: ' . $pid);
                posix_kill($pid, SIGTERM);
            }
        }
    }
    
    // Clear all WATI processing states
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wati_processing_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wati_batch_%'");
    delete_option('wati_notification_processing');
    
    return true;
}

function wati_emergency_stop_disable() {
    global $wpdb;
    
    error_log('WATI Debug: Emergency stop deactivated for WATI notifications by process ' . getmypid());
    
    // Get the emergency stop details before clearing
    $emergency_stop = get_option('wati_emergency_stop', array());
    $start_time = isset($emergency_stop['timestamp']) ? $emergency_stop['timestamp'] : '';
    $process_id = isset($emergency_stop['process_id']) ? $emergency_stop['process_id'] : '';
    
    // Log the emergency stop period before clearing
    if ($start_time) {
        wati_log_notification('emergency', '', '', 'info', array(
            'message' => 'Emergency stop period ended',
            'start_time' => $start_time,
            'end_time' => current_time('mysql'),
            'duration' => strtotime(current_time('mysql')) - strtotime($start_time),
            'start_process_id' => $process_id,
            'end_process_id' => getmypid(),
            'status' => 'deactivated'
        ));
    }
    
    // Clear emergency stop flags
    delete_option('wati_emergency_stop');
    delete_transient('wati_emergency_stop');
    
    // Clear stop signal
    delete_option('wati_notification_stop_signal');
    
    // Clear processing state
    wati_clear_processing_state();
    
    // Reset retry counters
    wati_reset_retry_counts();
    
    // Log the deactivation
    wati_log_notification('emergency', '', '', 'info', array(
        'message' => 'Emergency stop deactivated',
        'time' => current_time('mysql'),
        'process_id' => getmypid(),
        'status' => 'deactivated'
    ));
    
    return true;
}

// Add handling of plugin file deletion
register_shutdown_function('wati_handle_plugin_shutdown');

function wati_handle_plugin_shutdown() {
    // Check if this file still exists
    if (!file_exists(__FILE__)) {
        error_log('WATI Debug: Plugin file deleted or moved. Setting emergency stop signal.');
        // Use direct database query as plugin functions might not be available
        global $wpdb;
        $wpdb->query("
            INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
            VALUES ('wati_emergency_stop', 'a:3:{s:6:\"active\";b:1;s:9:\"timestamp\";s:19:\"" . current_time('mysql') . "\";s:10:\"process_id\";i:" . getmypid() . ";}', 'yes')
            ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)
        ");
        
        $wpdb->query("
            INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
            VALUES ('wati_notification_stop_signal', NOW(), 'yes')
            ON DUPLICATE KEY UPDATE option_value = NOW()
        ");
    }
}

// Add a new function to check emergency stop status in cron
add_action('init', 'wati_check_emergency_on_init', 1);
function wati_check_emergency_on_init() {
    // Only run this check if we're in a WordPress cron process
    if (defined('DOING_CRON') && DOING_CRON) {
        if (wati_check_emergency_stop(true)) {
            error_log('WATI Debug: Emergency stop detected during cron execution. Terminating process ' . getmypid());
            
            // Log the killed process
            wati_log_notification('emergency', '', '', 'warning', array(
                'message' => 'Cron process terminated due to emergency stop',
                'time' => current_time('mysql'),
                'process_id' => getmypid()
            ));
            
            // Exit the process immediately
            exit;
        }
    }
}

// Add a function to force-terminate in the event of an emergency stop - fail-safe mechanism
function wati_force_terminate_if_emergency() {
    if (wati_check_emergency_stop(true) || wati_check_stop_signal()) {
        error_log('WATI Debug: Critical force-termination due to emergency stop/signal. Process ID: ' . getmypid());
        exit;
    }
}

