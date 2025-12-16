<?php
/**
 * Logger Class
 *
 * Handles logging for the HostPay M-Pesa gateway
 *
 * @package WC_HostPay_Mpesa
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_HostPay_Logger {
    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    private $logger;

    /**
     * Log source
     *
     * @var string
     */
    private $source = 'wc-hostpay-mpesa';

    /**
     * Debug mode enabled
     *
     * @var bool
     */
    private $debug_enabled;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = wc_get_logger();
        
        // Check if debug mode is enabled in gateway settings
        $gateway_settings = get_option('woocommerce_hostpay_mpesa_settings', array());
        $this->debug_enabled = isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes';
    }

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param mixed $data Additional data to log
     * @param string $level Log level (debug, info, notice, warning, error, critical, alert, emergency)
     */
    public function log($message, $data = null, $level = 'info') {
        if (!$this->debug_enabled && $level === 'debug') {
            return;
        }

        $log_message = $message;
        
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $log_message .= ' | Data: ' . print_r($data, true);
            } else {
                $log_message .= ' | Data: ' . $data;
            }
        }

        $this->logger->log($level, $log_message, array('source' => $this->source));
    }

    /**
     * Log debug message
     *
     * @param string $message Log message
     * @param mixed $data Additional data
     */
    public function debug($message, $data = null) {
        $this->log($message, $data, 'debug');
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @param mixed $data Additional data
     */
    public function info($message, $data = null) {
        $this->log($message, $data, 'info');
    }

    /**
     * Log error message
     *
     * @param string $message Log message
     * @param mixed $data Additional data
     */
    public function error($message, $data = null) {
        $this->log($message, $data, 'error');
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @param mixed $data Additional data
     */
    public function warning($message, $data = null) {
        $this->log($message, $data, 'warning');
    }
}
