<?php
/**
 * Helper Functions
 *
 * Utility functions for the HostPay M-Pesa gateway
 *
 * @package WC_HostPay_Mpesa
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Format phone number to Kenyan format (254XXXXXXXXX)
 *
 * @param string $phone Phone number
 * @return string|false Formatted phone number or false if invalid
 */
function wc_hostpay_format_phone($phone) {
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);

    // Handle different formats
    if (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
        // 0712345678 -> 254712345678
        return '254' . substr($phone, 1);
    } elseif (strlen($phone) === 9) {
        // 712345678 -> 254712345678
        return '254' . $phone;
    } elseif (strlen($phone) === 12 && substr($phone, 0, 3) === '254') {
        // Already in correct format
        return $phone;
    } elseif (strlen($phone) === 13 && substr($phone, 0, 4) === '+254') {
        // +254712345678 -> 254712345678
        return substr($phone, 1);
    }

    return false;
}

/**
 * Validate Kenyan phone number
 *
 * @param string $phone Phone number
 * @return bool True if valid
 */
function wc_hostpay_validate_phone($phone) {
    $formatted = wc_hostpay_format_phone($phone);
    
    if (!$formatted) {
        return false;
    }

    // Check if it's a valid Kenyan mobile number (starts with 2547)
    return preg_match('/^2547[0-9]{8}$/', $formatted) === 1;
}

/**
 * Format amount for M-Pesa (must be integer, no decimals)
 *
 * @param float $amount Amount
 * @return int Formatted amount
 */
function wc_hostpay_format_amount($amount) {
    return (int) round($amount);
}

/**
 * Detect account type from M-Pesa account data
 *
 * @param array $account M-Pesa account data
 * @return string 'paybill' or 'till'
 */
function wc_hostpay_detect_account_type($account) {
    // Check if account has account_type field (from API)
    if (isset($account['account_type'])) {
        return strtolower($account['account_type']);
    }

    // Fallback: check which shortcode field is populated
    if (!empty($account['till_shortcode'])) {
        return 'till';
    }
    
    if (!empty($account['paybill_shortcode'])) {
        return 'paybill';
    }

    // Default to paybill
    return 'paybill';
}

/**
 * Get account display name
 *
 * @param array $account M-Pesa account data
 * @return string Display name
 */
function wc_hostpay_get_account_display_name($account) {
    $name = isset($account['company_business_name']) ? $account['company_business_name'] : '';
    $type = wc_hostpay_detect_account_type($account);
    
    // Get the appropriate shortcode based on account type
    $shortcode = '';
    if ($type === 'paybill' && isset($account['paybill_shortcode'])) {
        $shortcode = $account['paybill_shortcode'];
    } elseif ($type === 'till' && isset($account['till_shortcode'])) {
        $shortcode = $account['till_shortcode'];
    }
    
    if ($name && $shortcode) {
        return sprintf('%s (%s - %s)', $name, $shortcode, ucfirst($type));
    } elseif ($shortcode) {
        return sprintf('%s (%s)', $shortcode, ucfirst($type));
    } elseif ($name) {
        return $name;
    }
    
    return __('Unknown Account', 'wc-hostpay-mpesa');
}

/**
 * Sanitize transaction ID
 *
 * @param string $trans_id Transaction ID
 * @return string Sanitized transaction ID
 */
function wc_hostpay_sanitize_trans_id($trans_id) {
    // Remove whitespace and convert to uppercase
    return strtoupper(trim($trans_id));
}

/**
 * Generate unique order reference
 *
 * @param WC_Order $order Order object
 * @return string Order reference
 */
function wc_hostpay_get_order_reference($order) {
    return $order->get_order_number();
}

/**
 * Check if order is paid via HostPay
 *
 * @param WC_Order $order Order object
 * @return bool True if paid via HostPay
 */
function wc_hostpay_is_hostpay_order($order) {
    return $order->get_payment_method() === 'hostpay_mpesa';
}

/**
 * Get payment instructions for manual payment
 *
 * @param array $account M-Pesa account data
 * @param WC_Order $order Order object
 * @return array Payment instructions
 */
function wc_hostpay_get_payment_instructions($account, $order) {
    $type = wc_hostpay_detect_account_type($account);
    
    // Get the appropriate shortcode based on account type
    $shortcode = '';
    if ($type === 'paybill' && isset($account['paybill_shortcode'])) {
        $shortcode = $account['paybill_shortcode'];
    } elseif ($type === 'till' && isset($account['till_shortcode'])) {
        $shortcode = $account['till_shortcode'];
    }
    
    $amount = wc_hostpay_format_amount($order->get_total());
    $reference = wc_hostpay_get_order_reference($order);

    $instructions = array(
        'type' => $type,
        'shortcode' => $shortcode,
        'amount' => $amount,
        'reference' => $reference,
    );

    if ($type === 'paybill') {
        $instructions['steps'] = array(
            __('Go to M-Pesa menu on your phone', 'wc-hostpay-mpesa'),
            __('Select Lipa na M-Pesa', 'wc-hostpay-mpesa'),
            __('Select Pay Bill', 'wc-hostpay-mpesa'),
            sprintf(__('Enter Business Number: %s', 'wc-hostpay-mpesa'), $shortcode),
            sprintf(__('Enter Account Number: %s', 'wc-hostpay-mpesa'), $reference),
            sprintf(__('Enter Amount: %s', 'wc-hostpay-mpesa'), $amount),
            __('Enter your M-Pesa PIN', 'wc-hostpay-mpesa'),
            __('Confirm the transaction', 'wc-hostpay-mpesa'),
        );
    } else {
        $instructions['steps'] = array(
            __('Go to M-Pesa menu on your phone', 'wc-hostpay-mpesa'),
            __('Select Lipa na M-Pesa', 'wc-hostpay-mpesa'),
            __('Select Buy Goods and Services', 'wc-hostpay-mpesa'),
            sprintf(__('Enter Till Number: %s', 'wc-hostpay-mpesa'), $shortcode),
            sprintf(__('Enter Amount: %s', 'wc-hostpay-mpesa'), $amount),
            __('Enter your M-Pesa PIN', 'wc-hostpay-mpesa'),
            __('Confirm the transaction', 'wc-hostpay-mpesa'),
        );
    }

    return $instructions;
}
