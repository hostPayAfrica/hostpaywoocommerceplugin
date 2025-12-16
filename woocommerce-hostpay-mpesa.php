<?php
/**
 * Plugin Name: WooCommerce  hostPay  M-Pesa Gateway
 * Plugin URI: https://hostpay.africa
 * Description: Accept M-Pesa payments via  hostPay  API with automatic STK Push and manual verification fallback
 * Version: 1.0.0
 * Author: hostPay Africa
 * Author URI: https://hostpay.africa
 * Text Domain: wc-hostpay-mpesa
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_HOSTPAY_MPESA_VERSION', '1.0.0');
define('WC_HOSTPAY_MPESA_PLUGIN_FILE', __FILE__);
define('WC_HOSTPAY_MPESA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_HOSTPAY_MPESA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_HOSTPAY_MPESA_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
function wc_hostpay_mpesa_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_hostpay_mpesa_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display admin notice if WooCommerce is not active
 */
function wc_hostpay_mpesa_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('WooCommerce HostPay M-Pesa Gateway requires WooCommerce to be installed and active.', 'wc-hostpay-mpesa'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the gateway
 */
function wc_hostpay_mpesa_init() {
    // Check if WooCommerce is active
    if (!wc_hostpay_mpesa_check_woocommerce()) {
        return;
    }

    // Include required files
    require_once WC_HOSTPAY_MPESA_PLUGIN_DIR . 'includes/class-hostpay-api-client.php';
    require_once WC_HOSTPAY_MPESA_PLUGIN_DIR . 'includes/class-payment-processor.php';
    require_once WC_HOSTPAY_MPESA_PLUGIN_DIR . 'includes/class-logger.php';
    require_once WC_HOSTPAY_MPESA_PLUGIN_DIR . 'includes/helpers.php';
    require_once WC_HOSTPAY_MPESA_PLUGIN_DIR . 'includes/class-wc-gateway-hostpay-mpesa.php';

    // Add the gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'wc_hostpay_mpesa_add_gateway');
    
    // Register AJAX handlers
    add_action('wp_ajax_wc_hostpay_fetch_accounts', 'wc_hostpay_ajax_fetch_accounts');
    add_action('wp_ajax_wc_hostpay_check_payment_status', 'wc_hostpay_ajax_check_payment_status');
    add_action('wp_ajax_nopriv_wc_hostpay_check_payment_status', 'wc_hostpay_ajax_check_payment_status');
    add_action('wp_ajax_wc_hostpay_verify_payment', 'wc_hostpay_ajax_verify_payment');
    add_action('wp_ajax_nopriv_wc_hostpay_verify_payment', 'wc_hostpay_ajax_verify_payment');
    add_action('wp_ajax_wc_hostpay_initiate_stk', 'wc_hostpay_ajax_initiate_stk');
    add_action('wp_ajax_nopriv_wc_hostpay_initiate_stk', 'wc_hostpay_ajax_initiate_stk');
}
add_action('plugins_loaded', 'wc_hostpay_mpesa_init');

/**
 * Add the gateway to WooCommerce
 */
function wc_hostpay_mpesa_add_gateway($gateways) {
    $gateways[] = 'WC_Gateway_HostPay_Mpesa';
    return $gateways;
}

/**
 * Plugin activation hook
 */
function wc_hostpay_mpesa_activate() {
    // Check WooCommerce dependency
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('WooCommerce HostPay M-Pesa Gateway requires WooCommerce to be installed and active.', 'wc-hostpay-mpesa'),
            esc_html__('Plugin Activation Error', 'wc-hostpay-mpesa'),
            array('back_link' => true)
        );
    }

    // Create necessary database tables if needed
    // Add default options
    add_option('wc_hostpay_mpesa_version', WC_HOSTPAY_MPESA_VERSION);
}
register_activation_hook(__FILE__, 'wc_hostpay_mpesa_activate');

/**
 * Plugin deactivation hook
 */
function wc_hostpay_mpesa_deactivate() {
    // Cleanup tasks if needed
}
register_deactivation_hook(__FILE__, 'wc_hostpay_mpesa_deactivate');

/**
 * Load plugin textdomain for translations
 */
function wc_hostpay_mpesa_load_textdomain() {
    load_plugin_textdomain('wc-hostpay-mpesa', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'wc_hostpay_mpesa_load_textdomain');

/**
 * Add plugin action links
 */
function wc_hostpay_mpesa_plugin_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=hostpay_mpesa') . '">' . esc_html__('Settings', 'wc-hostpay-mpesa') . '</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_hostpay_mpesa_plugin_action_links');

/**
 * AJAX handler to fetch M-Pesa accounts
 */
function wc_hostpay_ajax_fetch_accounts() {
    check_ajax_referer('wc_hostpay_mpesa_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'wc-hostpay-mpesa')));
    }

    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('API key is required', 'wc-hostpay-mpesa')));
    }

    $logger = new WC_HostPay_Logger();
    $logger->info('Fetching M-Pesa accounts via AJAX');

    $api_client = new HostPay_API_Client($api_key);
    $response = $api_client->get_mpesa_accounts();

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $logger->error('Failed to fetch accounts: ' . $error_message);
        wp_send_json_error(array('message' => $error_message));
    }

    $logger->debug('API response received', $response);

    // Store accounts in options
    if (isset($response['data']) && is_array($response['data'])) {
        update_option('wc_hostpay_mpesa_accounts', $response['data']);
        $logger->info('Accounts saved: ' . count($response['data']) . ' accounts');
        wp_send_json_success(array(
            'accounts' => $response['data'],
            'message' => __('Accounts loaded successfully', 'wc-hostpay-mpesa'),
        ));
    } elseif (isset($response['success']) && $response['success'] === true && isset($response['data'])) {
        update_option('wc_hostpay_mpesa_accounts', array());
        wp_send_json_error(array('message' => __('No M-Pesa accounts found in your HostPay account', 'wc-hostpay-mpesa')));
    } else {
        $logger->error('Unexpected API response structure', $response);
        wp_send_json_error(array('message' => __('Invalid response from API. Please check your API key.', 'wc-hostpay-mpesa')));
    }
}

/**
 * AJAX handler to check payment status
 */
function wc_hostpay_ajax_check_payment_status() {
    // Get gateway instance
    $gateways = WC()->payment_gateways->payment_gateways();
    if (isset($gateways['hostpay_mpesa'])) {
        $gateways['hostpay_mpesa']->ajax_check_payment_status();
    } else {
        wp_send_json_error(array('message' => __('Gateway not found', 'wc-hostpay-mpesa')));
    }
}

/**
 * AJAX handler to verify payment
 */
function wc_hostpay_ajax_verify_payment() {
    // Get gateway instance
    $gateways = WC()->payment_gateways->payment_gateways();
    if (isset($gateways['hostpay_mpesa'])) {
        $gateways['hostpay_mpesa']->ajax_verify_payment();
    } else {
        wp_send_json_error(array('message' => __('Gateway not found', 'wc-hostpay-mpesa')));
    }
}

/**
 * AJAX handler to initiate STK Push from modal
 */
function wc_hostpay_ajax_initiate_stk() {
    check_ajax_referer('wc_hostpay_initiate_stk', 'nonce');

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $phone_number = isset($_POST['phone_number']) ? sanitize_text_field($_POST['phone_number']) : '';

    if (!$order_id) {
        wp_send_json_error(array('message' => __('Invalid order ID', 'wc-hostpay-mpesa')));
    }

    if (empty($phone_number)) {
        wp_send_json_error(array('message' => __('Please enter your M-Pesa phone number', 'wc-hostpay-mpesa')));
    }

    // Validate phone number
    if (!wc_hostpay_validate_phone($phone_number)) {
        wp_send_json_error(array('message' => __('Please enter a valid Kenyan mobile number (e.g., 0712345678)', 'wc-hostpay-mpesa')));
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => __('Order not found', 'wc-hostpay-mpesa')));
    }

    // Get gateway instance
    $gateways = WC()->payment_gateways->payment_gateways();
    if (!isset($gateways['hostpay_mpesa'])) {
        wp_send_json_error(array('message' => __('Payment gateway not found', 'wc-hostpay-mpesa')));
    }

    $gateway = $gateways['hostpay_mpesa'];
    $payment_processor = $gateway->get_payment_processor();

    if (!$payment_processor) {
        wp_send_json_error(array('message' => __('Payment processor not configured', 'wc-hostpay-mpesa')));
    }

    // Initiate STK Push
    $result = $payment_processor->process_stk_push($order, $phone_number);

    if ($result['success']) {
        // Update order meta
        $order->update_meta_data('_hostpay_payment_method', 'stk_push');
        $order->update_meta_data('_hostpay_phone_number', $phone_number);
        $order->save();

        wp_send_json_success(array(
            'message' => __('Payment request sent! Please check your phone.', 'wc-hostpay-mpesa'),
            'order_id' => $order_id
        ));
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}

/**
 * Enqueue admin scripts and styles
 */
function wc_hostpay_mpesa_admin_scripts($hook) {
    // Only load on WooCommerce settings page
    if ('woocommerce_page_wc-settings' !== $hook) {
        return;
    }

    wp_enqueue_style(
        'wc-hostpay-mpesa-admin',
        WC_HOSTPAY_MPESA_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        WC_HOSTPAY_MPESA_VERSION
    );

    wp_enqueue_script(
        'wc-hostpay-mpesa-admin',
        WC_HOSTPAY_MPESA_PLUGIN_URL . 'assets/js/admin.js',
        array('jquery'),
        WC_HOSTPAY_MPESA_VERSION,
        true
    );

    wp_localize_script('wc-hostpay-mpesa-admin', 'wcHostPayMpesa', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wc_hostpay_mpesa_admin'),
    ));
}
add_action('admin_enqueue_scripts', 'wc_hostpay_mpesa_admin_scripts');

/**
 * Enqueue frontend scripts and styles
 */
function wc_hostpay_mpesa_frontend_scripts() {
    // Load on checkout and order received (thank you) pages
    if (!is_checkout() && !is_order_received_page()) {
        return;
    }

    wp_enqueue_style(
        'wc-hostpay-mpesa-frontend',
        WC_HOSTPAY_MPESA_PLUGIN_URL . 'assets/css/frontend.css',
        array(),
        WC_HOSTPAY_MPESA_VERSION
    );

    wp_enqueue_script(
        'wc-hostpay-mpesa-frontend',
        WC_HOSTPAY_MPESA_PLUGIN_URL . 'assets/js/frontend.js',
        array('jquery'),
        WC_HOSTPAY_MPESA_VERSION,
        true
    );

    wp_localize_script('wc-hostpay-mpesa-frontend', 'wcHostPayMpesa', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wc_hostpay_mpesa_frontend'),
        'messages' => array(
            'processing' => __('Processing payment...', 'wc-hostpay-mpesa'),
            'checkingStatus' => __('Checking payment status...', 'wc-hostpay-mpesa'),
            'enterPhone' => __('Please enter your M-Pesa phone number', 'wc-hostpay-mpesa'),
            'invalidPhone' => __('Please enter a valid phone number', 'wc-hostpay-mpesa'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'wc_hostpay_mpesa_frontend_scripts');
