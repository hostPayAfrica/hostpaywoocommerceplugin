<?php
/**
 * Payment Processor
 *
 * Handles payment processing logic for STK Push and manual verification
 *
 * @package WC_HostPay_Mpesa
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_HostPay_Payment_Processor {
    /**
     * API Client
     *
     * @var HostPay_API_Client
     */
    private $api_client;

    /**
     * Logger
     *
     * @var WC_HostPay_Logger
     */
    private $logger;

    /**
     * Gateway settings
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor
     *
     * @param HostPay_API_Client $api_client API client instance
     * @param array $settings Gateway settings
     */
    public function __construct($api_client, $settings) {
        $this->api_client = $api_client;
        $this->settings = $settings;
        $this->logger = new WC_HostPay_Logger();
    }

    /**
     * Process STK Push payment
     *
     * @param WC_Order $order Order object
     * @param string $phone_number Customer phone number
     * @return array Result array with success status and message
     */
    public function process_stk_push($order, $phone_number) {
        $this->logger->info('Processing STK Push for order #' . $order->get_id());

        // Validate phone number
        $formatted_phone = wc_hostpay_format_phone($phone_number);
        if (!$formatted_phone || !wc_hostpay_validate_phone($formatted_phone)) {
            return array(
                'success' => false,
                'message' => __('Invalid phone number. Please enter a valid Kenyan mobile number.', 'wc-hostpay-mpesa'),
            );
        }

        // Get account details
        $account_id = isset($this->settings['mpesa_account']) ? $this->settings['mpesa_account'] : '';
        if (empty($account_id)) {
            return array(
                'success' => false,
                'message' => __('M-Pesa account not configured. Please contact support.', 'wc-hostpay-mpesa'),
            );
        }

        // Get account info from settings
        $accounts = get_option('wc_hostpay_mpesa_accounts', array());
        $account = null;
        foreach ($accounts as $acc) {
            if (isset($acc['id']) && $acc['id'] == $account_id) {
                $account = $acc;
                break;
            }
        }

        if (!$account) {
            return array(
                'success' => false,
                'message' => __('M-Pesa account details not found. Please contact support.', 'wc-hostpay-mpesa'),
            );
        }

        // Get the appropriate shortcode based on account type
        $account_type = wc_hostpay_detect_account_type($account);
        $shortcode = '';
        if ($account_type === 'paybill' && isset($account['paybill_shortcode'])) {
            $shortcode = $account['paybill_shortcode'];
        } elseif ($account_type === 'till' && isset($account['till_shortcode'])) {
            $shortcode = $account['till_shortcode'];
        }

        if (empty($shortcode)) {
            return array(
                'success' => false,
                'message' => __('M-Pesa shortcode not found. Please contact support.', 'wc-hostpay-mpesa'),
            );
        }

        // Prepare STK Push parameters
        $params = array(
            'shortcode' => $shortcode,
            'amount' => wc_hostpay_format_amount($order->get_total()),
            'phone_number' => $formatted_phone,
            'reason' => sprintf(__('Payment for Order #%s', 'wc-hostpay-mpesa'), $order->get_order_number()),
            'account_reference' => wc_hostpay_get_order_reference($order),
        );

        $this->logger->debug('STK Push params', $params);

        // Initiate STK Push
        $response = $this->api_client->initiate_stk_push($params);

        if (is_wp_error($response)) {
            $this->logger->error('STK Push failed: ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => __('Failed to initiate payment. Please try again or use manual payment.', 'wc-hostpay-mpesa'),
            );
        }

        // Store checkout request ID in order meta
        // API returns 'checkout_request_id' (lowercase)
        $checkout_request_id = '';
        if (isset($response['checkout_request_id'])) {
            $checkout_request_id = $response['checkout_request_id'];
        } elseif (isset($response['CheckoutRequestID'])) {
            // Fallback for different API versions
            $checkout_request_id = $response['CheckoutRequestID'];
        }

        if (!empty($checkout_request_id)) {
            $order->update_meta_data('_hostpay_checkout_request_id', $checkout_request_id);
            $order->update_meta_data('_hostpay_phone_number', $formatted_phone);
            $order->save();

            $this->logger->info('STK Push initiated successfully', $response);

            return array(
                'success' => true,
                'message' => __('Payment request sent to your phone. Please enter your M-Pesa PIN to complete the payment.', 'wc-hostpay-mpesa'),
                'checkout_request_id' => $checkout_request_id,
            );
        }

        return array(
            'success' => false,
            'message' => __('Failed to initiate payment. Please try again.', 'wc-hostpay-mpesa'),
        );
    }

    /**
     * Query STK Push status
     *
     * @param WC_Order $order Order object
     * @return array Result array with status information
     */
    public function query_stk_push_status($order) {
        $checkout_request_id = $order->get_meta('_hostpay_checkout_request_id');

        if (empty($checkout_request_id)) {
            return array(
                'success' => false,
                'status' => 'error',
                'message' => __('No payment request found for this order.', 'wc-hostpay-mpesa'),
            );
        }

        $response = $this->api_client->query_stk_push($checkout_request_id);

        if (is_wp_error($response)) {
            $this->logger->error('STK Push query failed: ' . $response->get_error_message());
            return array(
                'success' => false,
                'status' => 'error',
                'message' => __('Failed to check payment status.', 'wc-hostpay-mpesa'),
            );
        }

        $this->logger->debug('STK Push query response', $response);

        // API returns data in nested structure
        $data = isset($response['data']) ? $response['data'] : $response;

        // Check result code - API uses result_code field
        if (isset($data['result_code'])) {
            $result_code = (string) $data['result_code'];
            
            if ($result_code === '0') {
                // Payment successful
                $this->complete_payment($order, $data);
                return array(
                    'success' => true,
                    'status' => 'completed',
                    'message' => __('Payment completed successfully!', 'wc-hostpay-mpesa'),
                );
            } elseif ($result_code === '1032') {
                // Cancelled by user
                $order->update_status('cancelled', __('Payment cancelled by user.', 'wc-hostpay-mpesa'));
                return array(
                    'success' => false,
                    'status' => 'cancelled',
                    'message' => __('Payment was cancelled.', 'wc-hostpay-mpesa'),
                );
            } elseif ($result_code === '1037') {
                // No response from user - still pending or timeout
                return array(
                    'success' => true,
                    'status' => 'pending',
                    'message' => __('Waiting for payment confirmation...', 'wc-hostpay-mpesa'),
                );
            } else {
                // Other error
                $message = isset($data['result_desc']) ? $data['result_desc'] : __('Payment failed.', 'wc-hostpay-mpesa');
                $order->update_status('failed', sprintf(__('Payment failed: %s', 'wc-hostpay-mpesa'), $message));
                return array(
                    'success' => false,
                    'status' => 'failed',
                    'message' => $message,
                );
            }
        }

        // Check status field as fallback
        if (isset($data['status'])) {
            if ($data['status'] === 'completed' || $data['status'] === 'success') {
                $this->complete_payment($order, $data);
                return array(
                    'success' => true,
                    'status' => 'completed',
                    'message' => __('Payment completed successfully!', 'wc-hostpay-mpesa'),
                );
            } elseif ($data['status'] === 'failed') {
                $message = isset($data['result_desc']) ? $data['result_desc'] : __('Payment failed.', 'wc-hostpay-mpesa');
                $order->update_status('failed', sprintf(__('Payment failed: %s', 'wc-hostpay-mpesa'), $message));
                return array(
                    'success' => false,
                    'status' => 'failed',
                    'message' => $message,
                );
            }
        }

        // Fallback to old ResultCode format for compatibility
        if (isset($response['ResultCode'])) {
            if ($response['ResultCode'] === '0' || $response['ResultCode'] === 0) {
                $this->complete_payment($order, $response);
                return array(
                    'success' => true,
                    'status' => 'completed',
                    'message' => __('Payment completed successfully!', 'wc-hostpay-mpesa'),
                );
            } elseif ($response['ResultCode'] === '1032' || $response['ResultCode'] === 1032) {
                return array(
                    'success' => false,
                    'status' => 'cancelled',
                    'message' => __('Payment was cancelled.', 'wc-hostpay-mpesa'),
                );
            } else {
                $message = isset($response['ResultDesc']) ? $response['ResultDesc'] : __('Payment failed.', 'wc-hostpay-mpesa');
                return array(
                    'success' => false,
                    'status' => 'failed',
                    'message' => $message,
                );
            }
        }

        // Still pending
        return array(
            'success' => true,
            'status' => 'pending',
            'message' => __('Waiting for payment confirmation...', 'wc-hostpay-mpesa'),
        );
    }

    /**
     * Verify manual payment
     *
     * @param WC_Order $order Order object
     * @param string $trans_id Transaction ID
     * @return array Result array with verification status
     */
    public function verify_manual_payment($order, $trans_id) {
        $this->logger->info('Verifying manual payment for order #' . $order->get_id());

        // Sanitize transaction ID
        $trans_id = wc_hostpay_sanitize_trans_id($trans_id);

        if (empty($trans_id)) {
            return array(
                'success' => false,
                'message' => __('Please enter a valid transaction ID.', 'wc-hostpay-mpesa'),
            );
        }

        // Get account details
        $account_id = isset($this->settings['mpesa_account']) ? $this->settings['mpesa_account'] : '';
        $accounts = get_option('wc_hostpay_mpesa_accounts', array());
        $account = null;
        
        foreach ($accounts as $acc) {
            if (isset($acc['id']) && $acc['id'] == $account_id) {
                $account = $acc;
                break;
            }
        }

        if (!$account) {
            return array(
                'success' => false,
                'message' => __('M-Pesa account not configured.', 'wc-hostpay-mpesa'),
            );
        }

        // Get the appropriate shortcode based on account type
        $account_type = wc_hostpay_detect_account_type($account);
        $shortcode = '';
        if ($account_type === 'paybill' && isset($account['paybill_shortcode'])) {
            $shortcode = $account['paybill_shortcode'];
        } elseif ($account_type === 'till' && isset($account['till_shortcode'])) {
            $shortcode = $account['till_shortcode'];
        }

        if (empty($shortcode)) {
            return array(
                'success' => false,
                'message' => __('M-Pesa shortcode not found.', 'wc-hostpay-mpesa'),
            );
        }

        // Prepare verification parameters
        $params = array(
            'trans_id' => $trans_id,
            'bill_ref_number' => wc_hostpay_get_order_reference($order),
            'amount' => wc_hostpay_format_amount($order->get_total()),
            'business_shortcode' => $shortcode,
        );

        $this->logger->debug('Verification params', $params);

        // Verify transaction
        $response = $this->api_client->verify_transaction($params);

        if (is_wp_error($response)) {
            $this->logger->error('Verification failed: ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => __('Failed to verify payment. Please check the transaction ID and try again.', 'wc-hostpay-mpesa'),
            );
        }

        $this->logger->debug('Verification response', $response);

        // Check if verification was successful
        if (isset($response['success']) && $response['success'] === true) {
            // Check if amount matches - API returns amount in data.TransAmount
            $paid_amount = 0;
            if (isset($response['data']['TransAmount'])) {
                $paid_amount = (float) $response['data']['TransAmount'];
            } elseif (isset($response['amount'])) {
                // Fallback for older API versions
                $paid_amount = (float) $response['amount'];
            }
            
            $order_amount = (float) $order->get_total();

            if (abs($paid_amount - $order_amount) < 0.01) {
                // Amount matches, complete payment
                $this->complete_payment($order, $response, $trans_id);
                return array(
                    'success' => true,
                    'message' => __('Payment verified successfully!', 'wc-hostpay-mpesa'),
                );
            } else {
                $this->logger->warning('Amount mismatch', array(
                    'paid' => $paid_amount,
                    'expected' => $order_amount,
                ));
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __('Payment amount mismatch. Expected: %s, Paid: %s', 'wc-hostpay-mpesa'),
                        wc_price($order_amount),
                        wc_price($paid_amount)
                    ),
                );
            }
        }

        return array(
            'success' => false,
            'message' => isset($response['message']) ? $response['message'] : __('Payment verification failed.', 'wc-hostpay-mpesa'),
        );
    }

    /**
     * Complete payment and update order
     *
     * @param WC_Order $order Order object
     * @param array $payment_data Payment data from API
     * @param string $trans_id Optional transaction ID
     */
    private function complete_payment($order, $payment_data, $trans_id = '') {
        // Extract transaction ID
        if (empty($trans_id)) {
            // Check for TransID in data object (from gateway verify)
            if (isset($payment_data['data']['TransID'])) {
                $trans_id = $payment_data['data']['TransID'];
            }
            // Check for mpesa_receipt_number (from STK Push query - new API)
            elseif (isset($payment_data['mpesa_receipt_number']) && !empty($payment_data['mpesa_receipt_number'])) {
                $trans_id = $payment_data['mpesa_receipt_number'];
            }
            // Check for MpesaReceiptNumber (from STK Push - old API)
            elseif (isset($payment_data['MpesaReceiptNumber'])) {
                $trans_id = $payment_data['MpesaReceiptNumber'];
            }
            // Check for TransID at root level
            elseif (isset($payment_data['TransID'])) {
                $trans_id = $payment_data['TransID'];
            }
            // Check for trans_id at root level
            elseif (isset($payment_data['trans_id'])) {
                $trans_id = $payment_data['trans_id'];
            }
        }

        // Update order
        $order->payment_complete($trans_id);
        
        // Add order note
        $note = sprintf(
            __('M-Pesa payment completed. Transaction ID: %s', 'wc-hostpay-mpesa'),
            $trans_id
        );
        $order->add_order_note($note);

        // Store payment data in order meta
        $order->update_meta_data('_hostpay_transaction_id', $trans_id);
        $order->update_meta_data('_hostpay_payment_data', $payment_data);
        $order->save();
        
        // Mark order as completed (must be after save to persist)
        $order->update_status('completed', __('Payment received and order completed.', 'wc-hostpay-mpesa'));

        $this->logger->info('Payment completed for order #' . $order->get_id(), array('trans_id' => $trans_id));
    }
}
