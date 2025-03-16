# WATI Order Notifications Plugin

A WordPress plugin that integrates WooCommerce with WATI (WhatsApp API) to send automated WhatsApp notifications for abandoned carts and order status changes.

## Dependencies

- WordPress
- WooCommerce
- WooCommerce Cart Abandonment Recovery Pro (for abandoned cart functionality)
- WATI Business Account

## Features

### Abandoned Cart Recovery (Requires WooCommerce Cart Abandonment Recovery Pro)
- Send WhatsApp notifications for abandoned carts
- Configurable delay time (e.g., 48 hours after abandonment)
- Follow-up discount reminders for unclaimed carts
- Target specific users or all customers

### Order Status Notifications
- Processing order notifications
- Shipped/Completed order notifications
- Custom delay times for each status
- Support for both "Completed" and "Shipped" statuses

### Smart Message Handling
- Random delays between messages (5-10 seconds) to prevent blocking
- Template variable support (customer name, order number, etc.)
- User targeting options
- Automatic logging with 7-day retention

### Debug & Monitoring
- Test button for each notification type
- Detailed logs with filtering options
- Real-time status checking
- Debug tools for troubleshooting

## Installation

1. Install and activate WooCommerce
2. Install and activate WooCommerce Cart Abandonment Recovery Pro (required for abandoned cart features)
3. Upload the plugin to WordPress
4. Activate the plugin
5. Configure WATI API settings

## Configuration

### 1. API Setup
- Go to WordPress Admin → WATI Notifications
- Enter your WATI Bearer Token
- Enter WATI API URL (format: https://live-mt-server.wati.io/{your_business_id})
- Test the connection

### 2. General Settings
- Enable/disable the feature
- Set check interval
- Select specific users (optional)

### 3. Notification Types

#### Abandoned Cart (Requires WooCommerce Cart Abandonment Recovery Pro)
- Enable notifications
- Set template name
- Configure delay time
- Add template variables

#### Discount Reminder (Requires WooCommerce Cart Abandonment Recovery Pro)
- Secondary notification for abandoned carts
- Longer delay time (e.g., 72 hours)
- Different template for discount offers
- Sends only after initial abandoned cart notification

#### Processing Orders
- Notification when order status changes to "Processing"
- Configurable delay
- Custom template support

#### Shipped/Completed Orders
- Notification for shipped or completed orders
- Independent of processing notification
- Separate tracking for each status

## Usage

### Template Variables
Available variables for all notifications:
- `customer_name`: Customer's first name
- `order_number`: Cart/Order ID

### Testing
1. Use the "Test Enabled Notifications" button
2. View detailed results for each notification type
3. Check logs for message status

### Logs
- Access via WATI Notifications → Logs
- Filter by notification type
- View success/failure status
- Automatic cleanup after 7 days

## Best Practices

1. **Abandoned Cart Setup**
   - Set initial notification for 48 hours
   - Set discount reminder for 72 hours
   - Test with sample abandoned cart

2. **Order Notifications**
   - Set processing notification for immediate feedback
   - Set shipped notification after actual shipping
   - Use different templates for each status

3. **Message Timing**
   - Messages are automatically delayed 5-10 seconds apart
   - Consider timezone when setting delays
   - Monitor delivery success rates

## Troubleshooting

### Common Issues

1. **Abandoned Cart Notifications Not Working**
   - Verify WooCommerce Cart Abandonment Recovery Pro is active
   - Check abandoned cart data exists
   - Verify phone numbers are collected

2. **Order Status Notifications Not Sending**
   - Check status change triggers
   - Verify template names match WATI
   - Check phone number format

3. **API Issues**
   - Verify API credentials
   - Check WATI template names
   - Monitor API response logs

## Support

For issues and feature requests, please contact the plugin developer.

## License

This plugin is licensed under the GPL v2 or later.
