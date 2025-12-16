<?php
/**
 * HostPay API Client
 *
 * Handles all communication with the HostPay API
 *
 * @package WC_HostPay_Mpesa
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class HostPay_API_Client {
    /**
     * API Base URL
     */
    private const BASE_URL = 'https://bridge.hostpay.africa/api/';

    /**
     * API Key
     *
     * @var string
     */
    private $api_key;

    /**
     * Logger instance
     *
     * @var WC_HostPay_Logger
     */
    private $logger;

    /**
     * Constructor
     *
     * @param string $api_key API key for authentication
     */
    public function __construct($api_key) {
        $this->api_key = $api_key;
        $this->logger = new WC_HostPay_Logger();
    }

    /**
     * Make API request
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST)
     * @param array $data Request data
     * @return array|WP_Error Response data or error
     */
    private function request($endpoint, $method = 'GET', $data = array()) {
        $url = self::BASE_URL . ltrim($endpoint, '/');

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
        );

        // Add body for POST requests
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = $data;
        }

        // Add query parameters for GET requests
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $this->logger->log('API Request: ' . $method . ' ' . $url, $data);

        $response = wp_remote_request($url, $args);

        // Check for errors
        if (is_wp_error($response)) {
            $this->logger->log('API Error: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $this->logger->log('API Response (' . $status_code . '): ', $decoded);

        // Check for HTTP errors
        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'api_error',
                isset($decoded['message']) ? $decoded['message'] : 'API request failed',
                array('status' => $status_code, 'response' => $decoded)
            );
        }

        return $decoded;
    }

    /**
     * Get M-Pesa accounts
     *
     * @return array|WP_Error Array of M-Pesa accounts or error
     */
    public function get_mpesa_accounts() {
        return $this->request('mpesa-accounts', 'GET');
    }

    /**
     * Get specific M-Pesa account details
     *
     * @param int $account_id Account ID
     * @return array|WP_Error Account details or error
     */
    public function get_mpesa_account($account_id) {
        return $this->request('mpesa-accounts/show', 'GET', array('id' => $account_id));
    }

    /**
     * Initiate STK Push
     *
     * @param array $params STK Push parameters
     * @return array|WP_Error Response or error
     */
    public function initiate_stk_push($params) {
        $required = array('shortcode', 'amount', 'phone_number', 'reason', 'account_reference');
        
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_parameter', sprintf('Missing required parameter: %s', $field));
            }
        }

        return $this->request('stk-push/initiate', 'POST', $params);
    }

    /**
     * Query STK Push status
     *
     * @param string $checkout_request_id Checkout request ID from initiate response
     * @return array|WP_Error Status response or error
     */
    public function query_stk_push($checkout_request_id) {
        if (empty($checkout_request_id)) {
            return new WP_Error('missing_parameter', 'Checkout request ID is required');
        }

        return $this->request('stk-push/query', 'GET', array(
            'checkout_request_id' => $checkout_request_id
        ));
    }

    /**
     * Verify transaction
     *
     * @param array $params Verification parameters
     * @return array|WP_Error Verification response or error
     */
    public function verify_transaction($params) {
        $required = array('trans_id', 'bill_ref_number', 'amount', 'business_shortcode');
        
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return new WP_Error('missing_parameter', sprintf('Missing required parameter: %s', $field));
            }
        }

        // Add gateway type - identifies this as WooCommerce integration
        $params['gateway_type'] = 'WOO';

        return $this->request('gateways/verify', 'POST', $params);
    }

    /**
     * Get user details
     *
     * @return array|WP_Error User details or error
     */
    public function get_user_details() {
        return $this->request('user', 'GET');
    }

    /**
     * Test API connection
     *
     * @return bool True if connection successful
     */
    public function test_connection() {
        $result = $this->get_user_details();
        return !is_wp_error($result);
    }
}
