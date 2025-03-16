<?php
/*
Plugin Name: WATI Order Notifications
Description: Sends WhatsApp notifications for different order statuses using WATI API
Version: 1.5
Author: Hamdy Mohammed
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'wati_notifications_activation');
register_deactivation_hook(__FILE__, 'wati_notifications_deactivation');

// Add AJAX handlers
add_action('wp_ajax_test_wati_api', 'test_wati_api_ajax');
add_action('wp_ajax_search_users', 'wati_search_users_ajax');
add_action('wp_ajax_fetch_wati_templates', 'fetch_wati_templates_ajax');
add_action('wp_ajax_test_abandoned_check', 'test_abandoned_check');

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
            'cron_unit' => sanitize_text_field($_POST['wati_cron_unit'])
        );

        // Save conditions
        foreach (['abandoned', 'discount', 'processing', 'shipped'] as $status) {
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

    $settings = get_option('wati_notifications_settings', array());
    ?>
    <div class="wrap">
        <h1>WATI Notifications Settings</h1>
        
        <?php settings_errors('wati_messages'); ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('wati_notifications_settings'); ?>
            
            <!-- API Configuration -->
            <div class="card">
                <h2>API Configuration</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wati_bearer_token">WATI Bearer Token</label></th>
                        <td>
                            <input type="text" name="wati_bearer_token" id="wati_bearer_token" 
                                   value="<?php echo esc_attr($settings['bearer_token'] ?? ''); ?>" class="regular-text">
                            <p class="description">Enter your WATI Bearer Token</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wati_api_url">WATI API URL</label></th>
                        <td>
                            <input type="text" name="wati_api_url" id="wati_api_url" 
                                   value="<?php echo esc_attr($settings['api_url'] ?? ''); ?>" class="regular-text">
                            <p class="description">Enter your WATI API URL</p>
                            <button type="button" id="test-wati-api" class="button">Test Connection</button>
                            <span id="test-result"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- General Settings -->
            <div class="card">
                <h2>General Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Notifications</th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="wati_enable_feature" 
                                       value="1" 
                                       <?php checked(isset($settings['enable_feature']) && $settings['enable_feature']); ?>>
                                Enable WATI WhatsApp notifications
                            </label>
                            <p class="description">Turn this on to activate all notification features</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- After API Configuration card -->
            <div class="card">
                <h2>Cron Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="wati_cron_interval">Check Interval</label></th>
                        <td>
                            <input type="number" 
                                   name="wati_cron_interval" 
                                   id="wati_cron_interval" 
                                   value="<?php echo esc_attr($settings['cron_interval'] ?? '5'); ?>" 
                                   min="1" 
                                   style="width: 80px">
                            <select name="wati_cron_unit">
                                <option value="minutes" <?php selected(($settings['cron_unit'] ?? 'minutes'), 'minutes'); ?>>Minutes</option>
                                <option value="hours" <?php selected(($settings['cron_unit'] ?? 'minutes'), 'hours'); ?>>Hours</option>
                            </select>
                            <p class="description">How often should the plugin check for abandoned carts and order status changes. Default: 5 minutes</p>
                            <p class="description">Note: Changes to this setting require plugin deactivation and reactivation to take effect.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- User Selection -->
            <div class="card">
                <h2>Target Users</h2>
                <table class="form-table">
                    <tr>
                        <td>
                            <div class="wati-user-selector">
                                <div class="user-selection-controls">
                                    <select id="user-search" style="width: 300px;" multiple>
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
                                    <button type="button" class="button clear-users">Clear All Users</button>
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
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Notification Conditions -->
            <div class="card">
                <h2>Notification Conditions</h2>
                <?php
                $conditions = array(
                    'abandoned' => array(
                        'label' => 'Abandoned Cart',
                        'description' => 'Send notification when a cart is abandoned (uses CartFlows abandoned cart data)'
                    ),
                    'discount' => array(
                        'label' => 'Discount Reminder',
                        'description' => 'Send discount reminder for abandoned carts that remain unclaimed (typically sent after the initial abandoned cart notification)'
                    ),
                    'processing' => array(
                        'label' => 'Processing Order',
                        'description' => 'Send notification when order status changes to "Processing"'
                    ),
                    'shipped' => array(
                        'label' => 'Completed/Shipped Order',
                        'description' => 'Send notification when order status changes to "Completed" (typically used for shipped orders)'
                    )
                );

                foreach ($conditions as $status => $info) {
                    $condition = $settings['conditions'][$status] ?? array();
                    ?>
                    <div class="notification-condition">
                        <h3><?php echo esc_html($info['label']); ?></h3>
                        <p class="description"><?php echo esc_html($info['description']); ?></p>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="wati_<?php echo $status; ?>_enabled" value="1"
                                               <?php checked(isset($condition['enabled']) && $condition['enabled']); ?>>
                                        Enable <?php echo esc_html($info['label']); ?> notifications
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Template Name</th>
                                <td>
                                    <input type="text" name="wati_<?php echo $status; ?>_template"
                                           value="<?php echo esc_attr($condition['template_name'] ?? ''); ?>"
                                           class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Send After</th>
                                <td>
                                    <input type="number" name="wati_<?php echo $status; ?>_delay_time"
                                           value="<?php echo esc_attr($condition['delay_time'] ?? '2'); ?>"
                                           min="1" style="width: 80px">
                                    <select name="wati_<?php echo $status; ?>_delay_unit">
                                        <option value="minutes" <?php selected(($condition['delay_unit'] ?? 'hours'), 'minutes'); ?>>Minutes</option>
                                        <option value="hours" <?php selected(($condition['delay_unit'] ?? 'hours'), 'hours'); ?>>Hours</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Template Variables</th>
                                <td>
                                    <div class="template-variables" data-status="<?php echo esc_attr($status); ?>">
                                        <?php
                                        if (!empty($condition['variables'])) {
                                            foreach ($condition['variables'] as $var) {
                                                echo wati_render_variable_row($status, $var);
                                            }
                                        }
                                        ?>
                                        <button type="button" class="button add-variable">Add Variable</button>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php
                }
                ?>
            </div>

            <?php submit_button('Save Settings'); ?>
        </form>
    </div>

    <style>
    .card {
        background: #fff;
        border: 1px solid #ccd0d4;
        padding: 20px;
        margin-bottom: 20px;
    }
    .notification-condition {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }
    .template-variables {
        margin-bottom: 10px;
    }
    .variable-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }
    .variable-row select,
    .variable-row input {
        margin: 0;
    }
    .variable-type {
        min-width: 150px;
    }
    .variable-template-name {
        min-width: 200px;
    }
    .remove-variable {
        cursor: pointer;
        color: #cc0000;
    }
    .add-variable {
        margin-top: 5px !important;
    }
    .user-selection-controls {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }
    .selected-users-container {
        margin-top: 10px;
        border: 1px solid #ddd;
        padding: 10px;
        min-height: 50px;
    }
    .user-tag {
        display: inline-flex;
        align-items: center;
        background: #f0f0f1;
        border-radius: 3px;
        padding: 5px 10px;
        margin: 3px;
    }
    .user-tag .remove-user {
        border: none;
        background: none;
        color: #cc0000;
        cursor: pointer;
        padding: 0 5px;
        margin-left: 8px;
    }
    .select2-container--default .select2-selection--multiple {
        min-height: 35px;
    }
    .order-list {
        margin-top: 10px;
    }
    .order-item {
        border: 1px solid #eee;
        padding: 10px;
        margin-bottom: 5px;
        background: white;
    }
    .order-item p {
        margin: 5px 0;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Initialize Select2 with multiple selection
        $('#user-search').select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term,
                        action: 'search_users',
                        nonce: '<?php echo wp_create_nonce("wati_ajax_nonce"); ?>'
                    };
                },
                processResults: function(data) {
                    return {
                        results: data.users.map(function(user) {
                            return {
                                id: user.id,
                                text: user.text + ' (' + user.email + ')',
                                email: user.email,
                                phone: user.phone
                            };
                        })
                    };
                },
                cache: true
            },
            multiple: true,
            minimumInputLength: 2,
            placeholder: 'Search users...'
        }).on('select2:select', function(e) {
            var user = e.params.data;
            addUserTag(user);
        }).on('select2:unselect', function(e) {
            removeUserTag(e.params.data.id);
        });

        // Clear all users
        $('.clear-users').click(function() {
            if (confirm('Are you sure you want to clear all selected users?')) {
                $('#user-search').val(null).trigger('change');
                $('#selected-users').empty();
            }
        });

        // Remove individual user
        $(document).on('click', '.remove-user', function() {
            var userId = $(this).closest('.user-tag').data('id');
            removeUserTag(userId);
            // Remove from Select2
            var selection = $('#user-search').val().filter(function(value) {
                return value != userId;
            });
            $('#user-search').val(selection).trigger('change');
        });

        function addUserTag(user) {
            // Check if user already exists
            if ($('#selected-users').find('[data-id="' + user.id + '"]').length === 0) {
                var userTag = $('<div class="user-tag" data-id="' + user.id + '">' +
                    '<span class="user-info">' + user.text + '</span>' +
                    '<button type="button" class="remove-user">&times;</button>' +
                    '<input type="hidden" name="wati_specific_users[]" value="' + user.id + '">' +
                    '</div>');
                $('#selected-users').append(userTag);
            }
        }

        function removeUserTag(userId) {
            $('#selected-users').find('[data-id="' + userId + '"]').remove();
        }

        // Test API Connection
        $('#test-wati-api').click(function() {
            const button = $(this);
            const result = $('#test-result');
            
            button.prop('disabled', true);
            result.html('Testing connection...');
            
            $.post(ajaxurl, {
                action: 'test_wati_api',
                nonce: '<?php echo wp_create_nonce("wati_ajax_nonce"); ?>',
                bearer_token: $('#wati_bearer_token').val(),
                api_url: $('#wati_api_url').val()
            })
            .done(function(response) {
                if (response.success) {
                    result.html('<span style="color: green;">✓ Connection successful</span>');
                } else {
                    result.html('<span style="color: red;">✗ Connection failed</span>');
                }
            })
            .fail(function() {
                result.html('<span style="color: red;">✗ Connection failed</span>');
            })
            .always(function() {
                button.prop('disabled', false);
            });
        });

        // Handle adding new variables
        $('.add-variable').on('click', function() {
            const container = $(this).closest('.template-variables');
            const status = container.data('status');
            const newRow = `<?php echo str_replace(array("\n", "\r"), '', wati_render_variable_row('STATUS')); ?>`.replace(/STATUS/g, status);
            $(this).before(newRow);
        });

        // Handle removing variables
        $(document).on('click', '.remove-variable', function() {
            $(this).closest('.variable-row').remove();
        });

        // Prevent form submission on enter in variable inputs
        $(document).on('keypress', '.variable-row input', function(e) {
            return e.which !== 13;
        });
    });
    </script>
    <?php
}

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
        'order_number' => 'Order Number'
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
    error_log('WATI Debug: Plugin deactivated, clearing cron jobs');
    wp_clear_scheduled_hook('wati_check_notifications');
    wp_clear_scheduled_hook('wati_cleanup_old_logs');
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
        $phone = get_user_meta($user->ID, 'billing_phone', true);
        $results[] = array(
            'id' => $user->ID,
            'text' => $user->display_name,
            'email' => $user->user_email,
            'phone' => $phone
        );
    }

    wp_send_json(array('users' => $results));
}

// Add this function to verify template exists
function verify_template_exists($template_name, $api_url, $bearer_token) {
    if (!preg_match('/live-mt-server\.wati\.io\/(\d+)/', $api_url, $matches)) {
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

// Update the send_wati_template function
function send_wati_template($phone_number, $template_name, $variables = array()) {
    $settings = get_option('wati_notifications_settings', array());
    $api_url = $settings['api_url'] ?? '';
    $bearer_token = $settings['bearer_token'] ?? '';

    error_log('WATI Debug: Starting template send process');
    error_log('WATI Debug: Template: ' . $template_name);

    if (empty($api_url) || empty($bearer_token)) {
        error_log('WATI Debug: Missing API credentials');
        wati_log_notification('error', $phone_number, $template_name, 'error', array(
            'error' => 'Missing API credentials',
            'api_url_set' => !empty($api_url),
            'bearer_token_set' => !empty($bearer_token)
        ));
        return false;
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
    
    $request_body = array(
        'template_name' => $template_name,
        'broadcast_name' => $template_name
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
        )
    ));

    if ($success) {
        error_log('WATI Debug: Message sent successfully');
    } else {
        error_log('WATI Debug: Message send failed. Error: ' . ($response_body['error'] ?? 'Unknown error'));
    }

    return $success;
}

// Check notifications function (runs every 5 minutes)
function wati_check_notifications() {
    error_log('WATI Debug: Starting scheduled check at ' . current_time('mysql'));
    
    // Log cron execution
    wati_log_notification('cron', '', '', 'info', array(
        'message' => 'Cron job executed',
        'time' => current_time('mysql'),
        'next_run' => wp_next_scheduled('wati_check_notifications') ? 
            date('Y-m-d H:i:s', wp_next_scheduled('wati_check_notifications')) : 'Not scheduled'
    ));
    
    global $wpdb;
    $settings = get_option('wati_notifications_settings', array());
    
    if (empty($settings['enable_feature'])) {
        error_log('WATI Debug: Feature is disabled in settings');
        wati_log_notification('cron', '', '', 'warning', array(
            'message' => 'Feature is disabled in settings'
        ));
        return;
    }

    error_log('WATI Debug: Settings loaded: ' . print_r($settings, true));

    // Check abandoned carts
    if (!empty($settings['conditions']['abandoned']['enabled'])) {
        error_log('WATI Debug: Checking abandoned carts...');
        check_abandoned_carts($settings);
    } else {
        error_log('WATI Debug: Abandoned cart notifications are disabled');
    }

    // Check processing orders
    if (!empty($settings['conditions']['processing']['enabled'])) {
        error_log('WATI Debug: Checking processing orders...');
        check_processing_orders($settings);
    } else {
        error_log('WATI Debug: Processing order notifications are disabled');
    }

    // Check shipped orders
    if (!empty($settings['conditions']['shipped']['enabled'])) {
        error_log('WATI Debug: Checking completed/shipped orders...');
        check_shipped_orders($settings);
    } else {
        error_log('WATI Debug: Completed/shipped order notifications are disabled');
    }

    // Check discount reminders
    if (!empty($settings['conditions']['discount']['enabled'])) {
        error_log('WATI Debug: Checking discount reminders...');
        check_discount_notifications($settings);
    } else {
        error_log('WATI Debug: Discount reminders are disabled');
    }

    // Log completion
    wati_log_notification('cron', '', '', 'info', array(
        'message' => 'Cron job completed',
        'checks_performed' => array(
            'abandoned' => !empty($settings['conditions']['abandoned']['enabled']),
            'processing' => !empty($settings['conditions']['processing']['enabled']),
            'shipped' => !empty($settings['conditions']['shipped']['enabled']),
            'discount' => !empty($settings['conditions']['discount']['enabled'])
        )
    ));

    error_log('WATI Debug: Scheduled check completed at ' . current_time('mysql'));
}
add_action('wati_check_notifications', 'wati_check_notifications');

// Add this helper function at the top of the file
function wati_random_delay() {
    $delay = rand(5, 10);
    error_log("WATI Debug: Sleeping for {$delay} seconds before next message");
    sleep($delay);
}

// Check abandoned carts
function check_abandoned_carts($settings, $is_test = false) {
    global $wpdb;
    
    $details = array(
        'condition' => $settings['conditions']['abandoned'],
        'found_carts' => array(),
        'total_carts' => 0,
        'eligible_carts' => 0,
        'already_notified' => 0,
        'no_phone' => 0
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
        $cart_info = array(
            'id' => $cart->id,
            'email' => $cart->email,
            'time' => $cart->time,
            'cart_total' => $cart->cart_total
        );

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
                wati_random_delay();
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
                wati_random_delay();
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
        'no_phone' => 0
    );

    // Get the delay time
    $condition = $settings['conditions']['shipped'];
    $delay_minutes = $condition['delay_unit'] === 'hours' ? 
        $condition['delay_time'] * 60 : 
        $condition['delay_time'];

    // Get shipped and completed orders
    $args = array(
        'status' => array('completed', 'shipped'),  // Check both statuses
        'limit' => -1,
        'date_modified' => '>' . date('Y-m-d H:i:s', strtotime("-{$delay_minutes} minutes")),
        'return' => 'ids'
    );
    
    $order_ids = wc_get_orders($args);
    $details['total_orders'] = count($order_ids);

    error_log('WATI Debug: Found ' . count($order_ids) . ' recently shipped/completed orders');

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

        // Get the status change time
        $status_changed = $order->get_date_modified()->getTimestamp();
        $time_diff = time() - $status_changed;

        error_log("WATI Debug: Order {$order_id} status: " . $order->get_status() . ", changed: " . $order->get_date_modified()->format('Y-m-d H:i:s'));

        // Skip if not enough time has passed since status change
        if ($time_diff < ($delay_minutes * 60) && !$is_test) {
            error_log("WATI Debug: Order {$order_id} status changed too recently, waiting for delay time");
            continue;
        }

        $notification_key = 'wati_shipped_' . $order_id;
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

        // Add user filter check
        if (!empty($settings['specific_users']) && !in_array($order->get_customer_id(), $settings['specific_users'])) {
            continue;
        }

        $order_info['status'] = 'eligible';
        $details['eligible_orders']++;
        
        error_log("WATI Debug: Processing shipped notification for order {$order_id}");
        
        if (!$is_test) {
            // Add random delay before sending if not the first message
            if ($details['eligible_orders'] > 0) {
                wati_random_delay();
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
                }
            }

            error_log("WATI Debug: Sending shipped notification for order {$order_id} to " . $order->get_billing_phone());

            if (send_wati_template($order->get_billing_phone(), $settings['conditions']['shipped']['template_name'], $variables)) {
                update_option($notification_key, current_time('mysql'), false);
                $order_info['notification_sent'] = true;
                
                error_log("WATI Debug: Successfully sent shipped notification for order {$order_id}");
                
                // Log success
                wati_log_notification('shipped', $order->get_billing_phone(), $settings['conditions']['shipped']['template_name'], 'success', array(
                    'order_id' => $order_id,
                    'customer_id' => $order->get_customer_id(),
                    'variables' => $variables,
                    'status_changed' => $order->get_date_modified()->format('Y-m-d H:i:s')
                ));
            } else {
                error_log("WATI Debug: Failed to send shipped notification for order {$order_id}");
                
                // Log failure
                wati_log_notification('shipped', $order->get_billing_phone(), $settings['conditions']['shipped']['template_name'], 'error', array(
                    'order_id' => $order_id,
                    'customer_id' => $order->get_customer_id(),
                    'error' => 'Failed to send shipped notification',
                    'status_changed' => $order->get_date_modified()->format('Y-m-d H:i:s')
                ));
            }
        }

        $details['found_orders'][] = $order_info;
    }

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
    ?>
    <div class="wrap">
        <h1>WATI Notification Logs</h1>
        
        <div class="tablenav top">
            <form method="post" style="float: left; margin-right: 10px;">
                <?php wp_nonce_field('wati_clear_logs'); ?>
                <input type="submit" name="clear_logs" class="button" value="Clear Logs">
            </form>
            
            <div class="alignright">
                <select id="log-type-filter">
                    <option value="">All Types</option>
                    <option value="cron">Cron Jobs</option>
                    <option value="abandoned">Abandoned Cart</option>
                    <option value="processing">Processing Order</option>
                    <option value="shipped">Shipped Order</option>
                </select>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Phone</th>
                    <th>Template</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6">No logs found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach (array_reverse($logs) as $log): ?>
                        <tr class="log-entry <?php echo esc_attr($log['type']); ?>">
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
                                <button type="button" class="button show-details">Show Details</button>
                                <div class="log-details" style="display: none;">
                                    <pre><?php echo esc_html(json_encode($log['details'], JSON_PRETTY_PRINT)); ?></pre>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <style>
    .status-badge {
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 12px;
    }
    .status-success {
        background: #dff0d8;
        color: #3c763d;
    }
    .status-error {
        background: #f2dede;
        color: #a94442;
    }
    .status-info {
        background: #d9edf7;
        color: #31708f;
    }
    .status-warning {
        background: #fcf8e3;
        color: #8a6d3b;
    }
    .log-details {
        margin-top: 10px;
        padding: 10px;
        background: #f9f9f9;
        border: 1px solid #ddd;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Filter logs by type
        $('#log-type-filter').on('change', function() {
            var type = $(this).val();
            if (type) {
                $('.log-entry').hide();
                $('.log-entry.' + type).show();
            } else {
                $('.log-entry').show();
            }
        });

        // Show/hide details
        $('.show-details').on('click', function() {
            $(this).next('.log-details').toggle();
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
    
    error_log('WATI Debug: Manual test check initiated');
    
    $settings = get_option('wati_notifications_settings', array());
    
    if (empty($settings['enable_feature'])) {
        error_log('WATI Debug: Feature is disabled in settings');
        wp_send_json_error(array('message' => 'Feature is disabled in settings'));
        return;
    }

    $checks_performed = array();
    $check_details = array();

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

    // Log test completion
    wati_log_notification('test', '', '', 'info', array(
        'message' => 'Manual test completed',
        'checks_performed' => $checks_performed,
        'check_details' => $check_details,
        'time' => current_time('mysql')
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
            <div class="card">
                <h2>Debug Tools</h2>
                <button type="button" id="test-notifications" class="button">Test Enabled Notifications</button>
                <span id="test-result" style="margin-left: 10px;"></span>
                <div id="test-details" style="margin-top: 10px;">
                    <h4>Checks Performed:</h4>
                    <div id="checks-list"></div>
                </div>
            </div>
        `);

        // Hide test details initially
        $('#test-details').hide();

        $('#test-notifications').click(function() {
            const button = $(this);
            const result = $('#test-result');
            const details = $('#test-details');
            const checksList = $('#checks-list');
            
            button.prop('disabled', true);
            result.html('Running notification checks...');
            details.hide();
            checksList.empty();
            
            $.post(ajaxurl, {
                action: 'test_abandoned_check',
                nonce: '<?php echo wp_create_nonce("wati_ajax_nonce"); ?>'
            })
            .done(function(response) {
                if (response.success) {
                    result.html('<span style="color: green;">✓ Test completed</span>');
                    
                    let checksHtml = '';
                    const checks = response.data.checks_performed;
                    const checkDetails = response.data.check_details;
                    
                    Object.entries(checks).forEach(([type, performed]) => {
                        if (performed) {
                            const typeLabel = {
                                'abandoned': 'Abandoned Cart Notifications',
                                'discount': 'Discount Reminders',
                                'processing': 'Processing Order Notifications',
                                'shipped': 'Shipped Order Notifications'
                            }[type];

                            checksHtml += `
                                <div class="check-result">
                                    <div class="check-header">
                                        <span class="check-type">${typeLabel}</span>
                                        <button type="button" class="button show-check-details" 
                                                data-type="${type}">Show Details</button>
                                    </div>
                                    <div class="check-details-content" id="${type}-details" 
                                         style="display: none;">
                                        ${formatCheckDetails(type, checkDetails[type])}
                                    </div>
                                </div>
                            `;
                        }
                    });
                    
                    if (checksHtml) {
                        checksList.html(checksHtml);
                        $('#test-details').show();
                    } else {
                        result.html('<span style="color: orange;">⚠ No notifications enabled</span>');
                    }
                } else {
                    result.html('<span style="color: red;">✗ ' + 
                        (response.data.message || 'Test failed') + '</span>');
                }
            })
            .fail(function() {
                result.html('<span style="color: red;">✗ Test failed</span>');
            })
            .always(function() {
                button.prop('disabled', false);
            });
        });

        // Handle showing/hiding check details
        $(document).on('click', '.show-check-details', function() {
            const type = $(this).data('type');
            const detailsDiv = $(`#${type}-details`);
            detailsDiv.slideToggle();
            $(this).text(detailsDiv.is(':visible') ? 'Hide Details' : 'Show Details');
        });
    });
    </script>

    <style>
    .check-result {
        margin-bottom: 15px;
        border: 1px solid #ddd;
        padding: 10px;
    }
    .check-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    .check-type {
        font-weight: bold;
    }
    .check-details-content {
        background: #f9f9f9;
        padding: 10px;
        margin-top: 10px;
    }
    .cart-list {
        margin-top: 10px;
    }
    .cart-item {
        border: 1px solid #eee;
        padding: 10px;
        margin-bottom: 5px;
        background: white;
    }
    .cart-item p {
        margin: 5px 0;
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
            }
        }

        error_log('WATI Debug: Sending discount template ' . $condition['template_name'] . ' with variables: ' . print_r($variables, true));

        $details['eligible_carts']++;
        
        if (!$is_test) {
            // Add random delay before sending if not the first message
            if ($details['eligible_carts'] > 0) {
                wati_random_delay();
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