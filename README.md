# WooCommerce HostPay M-Pesa Gateway

A comprehensive WooCommerce payment gateway plugin that integrates with HostPay's M-Pesa API to enable seamless mobile money payments for Kenyan businesses.

## Features

✅ **Automatic STK Push Payments** - Customers receive M-Pesa prompts directly on their phones  
✅ **Manual Payment Fallback** - Display paybill/till instructions with transaction verification  
✅ **Dynamic Account Selection** - Fetch and select from your M-Pesa accounts via API  
✅ **Account Type Detection** - Automatically detect paybill vs till accounts  
✅ **Order Number as Reference** - Uses WooCommerce order number as account reference for paybill  
✅ **Amount Verification** - Ensures paid amount matches order total  
✅ **Real-time Status Checking** - Automatic payment status polling  
✅ **Debug Logging** - Comprehensive logging for troubleshooting  

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- HostPay account with API access
- Active M-Pesa paybill or till number configured in HostPay

## Installation

### Method 1: Upload via WordPress Admin

1. Download the plugin as a ZIP file
2. Log in to your WordPress admin panel
3. Navigate to **Plugins > Add New**
4. Click **Upload Plugin** button
5. Choose the ZIP file and click **Install Now**
6. Click **Activate Plugin**

### Method 2: Manual Installation

1. Download and extract the plugin files
2. Upload the `woocommerce-hostpay-mpesa` folder to `/wp-content/plugins/`
3. Log in to your WordPress admin panel
4. Navigate to **Plugins**
5. Find "WooCommerce HostPay M-Pesa Gateway" and click **Activate**

## Configuration

### Step 1: Get Your HostPay API Key

1. Log in to your HostPay dashboard at [https://bridge.hostpay.africa](https://bridge.hostpay.africa)
2. Navigate to **My Account** then scroll down to **API Key Management**
3. Generate or copy your API key (format: `hpay-xxxxxxxxxxxxx`)

### Step 2: Configure the Plugin

1. In WordPress admin, go to **WooCommerce > Settings > Payments**
2. Find **hostPay M-Pesa** and click **Manage** or toggle it to enable
3. Configure the following settings:

#### Basic Settings

- **Enable/Disable**: Check to enable the payment gateway
- **Title**: Payment method name shown to customers (default: "M-Pesa")
- **Description**: Payment description shown at checkout

#### API Configuration

- **API Key**: Paste your HostPay API key
- Click **Save changes** to save the API key

#### M-Pesa Account Selection

1. After saving your API key, click the **Fetch M-Pesa Accounts** button
2. Your configured M-Pesa accounts will be loaded from HostPay
3. Select the account you want to use for receiving payments
4. The plugin will automatically detect if it's a paybill or till number

#### Payment Mode

Choose how customers can pay:
- **STK Push Only**: Customers must use automatic payment (phone prompt)
- **Manual Payment Only**: Customers see payment instructions and verify manually
- **Both STK Push and Manual**: Customers can choose their preferred method (recommended)

#### Debug Mode

- Enable debug logging to troubleshoot issues
- Logs are saved to `wp-content/uploads/wc-logs/`
- View logs in **WooCommerce > Status > Logs**

### Step 3: Test the Integration

1. Create a test product in your store
2. Add it to cart and proceed to checkout
3. Select "M-Pesa" as payment method
4. Complete a test transaction

## Usage

### For Customers - STK Push Payment

1. At checkout, select "M-Pesa" as payment method
2. Enter your M-Pesa phone number (e.g., 0712345678)
3. Click "Place Order"
4. Check your phone for the M-Pesa payment prompt
5. Enter your M-Pesa PIN to complete payment
6. Payment status will update automatically

### For Customers - Manual Payment

1. At checkout, select "M-Pesa" as payment method
2. If both modes are enabled, check "I want to pay manually"
3. Click "Place Order"
4. Follow the payment instructions displayed:
   - **For Paybill**: Use the paybill number and order number as account
   - **For Till**: Use the till number
5. After paying, enter the M-Pesa transaction ID (e.g., QGH7XYZ123)
6. Click "Verify Payment"
7. Order status will update if payment is verified

### For Store Owners

- View payment details in **WooCommerce > Orders**
- Transaction IDs are saved in order meta
- Payment logs available in **WooCommerce > Status > Logs** (if debug mode enabled)

## Payment Flow

### STK Push Flow

```
Customer enters phone → Plugin initiates STK Push → Customer receives prompt
→ Customer enters PIN → Payment confirmed → Order status updated to Processing
```

### Manual Payment Flow

```
Customer views instructions → Customer pays via M-Pesa → Customer enters transaction ID
→ Plugin verifies with HostPay API → Amount validated → Order status updated
```

## Troubleshooting

### Plugin Not Showing at Checkout

- Ensure WooCommerce is installed and active
- Check that the gateway is enabled in settings
- Verify your API key is correct

### M-Pesa Accounts Not Loading

- Verify your API key is correct
- Check that you have M-Pesa accounts configured in HostPay
- Enable debug mode and check logs for API errors

### STK Push Not Working

- Verify the phone number format (should be 254XXXXXXXXX)
- Ensure the M-Pesa account is active
- Check HostPay dashboard for any account issues
- Enable debug logging to see API responses

### Manual Verification Failing

- Ensure the transaction ID is correct
- Verify the amount paid matches the order total
- Check that the payment was made to the correct paybill/till
- For paybills, ensure the account number matches the order number

### Payment Status Not Updating

- Check your internet connection
- Verify HostPay API is accessible
- Enable debug mode and check logs
- Try manually refreshing the thank you page

## API Endpoints Used

The plugin uses the following HostPay API endpoints:

- `GET /mpesa-accounts` - Fetch M-Pesa accounts
- `POST /stk-push/initiate` - Initiate STK Push payment
- `GET /stk-push/query` - Query STK Push status
- `POST /gateways/verify` - Verify manual payment

## Security

- API keys are stored securely in WordPress options
- All API communications use HTTPS
- Transaction verification validates amounts to prevent fraud
- Nonce verification on all AJAX requests
- Input sanitization and validation

## Support

For support and bug reports:
- Email: support@hostpay.africa
- Website: https://hostpay.africa

## Changelog

### Version 1.0.0
- Initial release
- STK Push payment support
- Manual payment with verification
- Dynamic M-Pesa account selection
- Automatic account type detection
- Real-time payment status checking

## License

GPL v2 or later

## Credits

Developed by HostPay for WooCommerce integration.
