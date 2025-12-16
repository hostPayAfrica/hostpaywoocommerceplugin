<?php
/**
 * WooCommerce HostPay M-Pesa Gateway
 *
 * Main payment gateway class
 *
 * @package WC_HostPay_Mpesa
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_HostPay_Mpesa extends WC_Payment_Gateway {
    /**
     * API Client
     *
     * @var HostPay_API_Client
     */
    private $api_client;

    /**
     * Payment Processor
     *
     * @var WC_HostPay_Payment_Processor
     */
    private $payment_processor;

    /**
     * Logger
     *
     * @var WC_HostPay_Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'hostpay_mpesa';
        $this->icon = ''; // You can add M-Pesa icon URL here
        $this->has_fields = true;
        $this->method_title = __('HostPay M-Pesa', 'wc-hostpay-mpesa');
        $this->method_description = __('Accept M-Pesa payments via HostPay API with automatic STK Push and manual verification.', 'wc-hostpay-mpesa');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_key = $this->get_option('api_key');
        $this->mpesa_account = $this->get_option('mpesa_account');
        $this->payment_mode = $this->get_option('payment_mode', 'both');
        $this->debug = $this->get_option('debug', 'no');

        // Initialize logger
        $this->logger = new WC_HostPay_Logger();

        // Initialize API client if API key is set
        if (!empty($this->api_key)) {
            $this->api_client = new HostPay_API_Client($this->api_key);
            $this->payment_processor = new WC_HostPay_Payment_Processor($this->api_client, $this->settings);
        }

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_account_data'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_admin_order_meta'));
        
        // Handle payment confirmation page via template_redirect
        add_action('template_redirect', array($this, 'handle_payment_confirmation_page'));
    }

    /**
     * Get payment processor instance
     *
     * @return WC_HostPay_Payment_Processor|null
     */
    public function get_payment_processor() {
        return $this->payment_processor;
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'wc-hostpay-mpesa'),
                'type' => 'checkbox',
                'label' => __('Enable HostPay M-Pesa Gateway', 'wc-hostpay-mpesa'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'wc-hostpay-mpesa'),
                'type' => 'text',
                'description' => __('Payment method title that customers see during checkout.', 'wc-hostpay-mpesa'),
                'default' => __('M-Pesa', 'wc-hostpay-mpesa'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'wc-hostpay-mpesa'),
                'type' => 'textarea',
                'description' => __('Payment method description that customers see during checkout.', 'wc-hostpay-mpesa'),
                'default' => __('Pay securely using M-Pesa mobile money.', 'wc-hostpay-mpesa'),
                'desc_tip' => true,
            ),
            'api_key' => array(
                'title' => __('API Key', 'wc-hostpay-mpesa'),
                'type' => 'text',
                'description' => __('Enter your HostPay API key. You can get this from your HostPay dashboard.', 'wc-hostpay-mpesa'),
                'desc_tip' => true,
                'custom_attributes' => array(
                    'autocomplete' => 'off',
                ),
            ),
            'mpesa_account' => array(
                'title' => __('M-Pesa Account', 'wc-hostpay-mpesa'),
                'type' => 'select',
                'description' => __('Select the M-Pesa account to receive payments. Save your API key first to load accounts.', 'wc-hostpay-mpesa'),
                'desc_tip' => true,
                'options' => $this->get_mpesa_account_options(),
                'class' => 'wc-hostpay-mpesa-account-select',
            ),
            'payment_mode' => array(
                'title' => __('Payment Mode', 'wc-hostpay-mpesa'),
                'type' => 'select',
                'description' => __('Choose how customers can pay.', 'wc-hostpay-mpesa'),
                'desc_tip' => true,
                'default' => 'both',
                'options' => array(
                    'stk_only' => __('STK Push Only', 'wc-hostpay-mpesa'),
                    'manual_only' => __('Manual Payment Only', 'wc-hostpay-mpesa'),
                    'both' => __('Both STK Push and Manual', 'wc-hostpay-mpesa'),
                ),
            ),
            'debug' => array(
                'title' => __('Debug Mode', 'wc-hostpay-mpesa'),
                'type' => 'checkbox',
                'label' => __('Enable debug logging', 'wc-hostpay-mpesa'),
                'default' => 'no',
                'description' => sprintf(
                    __('Log events to %s', 'wc-hostpay-mpesa'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path('wc-hostpay-mpesa') . '</code>'
                ),
            ),
        );
    }

    /**
     * Get M-Pesa account options for select field
     *
     * @return array Account options
     */
    private function get_mpesa_account_options() {
        $options = array('' => __('-- Select Account --', 'wc-hostpay-mpesa'));
        
        $accounts = get_option('wc_hostpay_mpesa_accounts', array());
        
        if (!empty($accounts) && is_array($accounts)) {
            foreach ($accounts as $account) {
                if (isset($account['id'])) {
                    $options[$account['id']] = wc_hostpay_get_account_display_name($account);
                }
            }
        }
        
        return $options;
    }

    /**
     * Save account data when settings are saved
     */
    public function save_account_data() {
        // This will be called after process_admin_options
        // We'll fetch accounts via AJAX instead
    }

    /**
     * AJAX handler to fetch M-Pesa accounts
     */
    public function ajax_fetch_accounts() {
        check_ajax_referer('wc_hostpay_mpesa_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'wc-hostpay-mpesa')));
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('API key is required', 'wc-hostpay-mpesa')));
        }

        $this->logger->info('Fetching M-Pesa accounts via AJAX');

        $api_client = new HostPay_API_Client($api_key);
        $response = $api_client->get_mpesa_accounts();

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error('Failed to fetch accounts: ' . $error_message);
            wp_send_json_error(array('message' => $error_message));
        }

        $this->logger->debug('API response received', $response);

        // Store accounts in options
        if (isset($response['data']) && is_array($response['data'])) {
            update_option('wc_hostpay_mpesa_accounts', $response['data']);
            $this->logger->info('Accounts saved: ' . count($response['data']) . ' accounts');
            wp_send_json_success(array(
                'accounts' => $response['data'],
                'message' => __('Accounts loaded successfully', 'wc-hostpay-mpesa'),
            ));
        } elseif (isset($response['success']) && $response['success'] === true && isset($response['data'])) {
            // Handle case where data might be empty array
            update_option('wc_hostpay_mpesa_accounts', array());
            wp_send_json_error(array('message' => __('No M-Pesa accounts found in your HostPay account', 'wc-hostpay-mpesa')));
        } else {
            $this->logger->error('Unexpected API response structure', $response);
            wp_send_json_error(array('message' => __('Invalid response from API. Please check your API key.', 'wc-hostpay-mpesa')));
        }
    }

    /**
     * Payment fields on checkout page
     */
    public function payment_fields() {
        // Display description
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        // Get billing phone if available
        $billing_phone = WC()->customer ? WC()->customer->get_billing_phone() : '';
        
        ?>
        <div class="wc-hostpay-checkout-fields">
            <p class="wc-hostpay-info" style="background: #e7f3ff; padding: 15px; border-left: 4px solid #1e8449; margin-bottom: 20px;">
                <strong><?php esc_html_e('📱 How it works:', 'wc-hostpay-mpesa'); ?></strong><br>
                <?php esc_html_e('After placing your order, you will choose between STK Push (automatic) or Manual Payment in a popup window.', 'wc-hostpay-mpesa'); ?>
            </p>
            
            <p class="form-row form-row-wide">
                <label for="hostpay_phone_number">
                    <?php esc_html_e('M-Pesa Phone Number (Optional)', 'wc-hostpay-mpesa'); ?>
                </label>
                <input 
                    type="tel" 
                    class="input-text" 
                    id="hostpay_phone_number" 
                    name="hostpay_phone_number" 
                    value="<?php echo esc_attr($billing_phone); ?>"
                    placeholder="0712345678 or 254712345678"
                    autocomplete="tel"
                />
                <small><?php esc_html_e('Pre-fill your phone number for faster STK Push payment', 'wc-hostpay-mpesa'); ?></small>
            </p>
        </div>
        <?php
    }

    /**
     * Render STK Push content
     */
    private function render_stk_push_content() {
        // Get billing phone if available
        $billing_phone = WC()->customer ? WC()->customer->get_billing_phone() : '';
        ?>
        <div class="wc-hostpay-stk-content">
            <p class="wc-hostpay-description">
                <?php esc_html_e('Enter your M-Pesa phone number below. You will receive a payment prompt on your phone.', 'wc-hostpay-mpesa'); ?>
            </p>
            
            <p class="form-row form-row-wide">
                <label for="hostpay_phone_number">
                    <?php esc_html_e('M-Pesa Phone Number', 'wc-hostpay-mpesa'); ?> 
                    <span class="required">*</span>
                </label>
                <input 
                    type="tel" 
                    class="input-text" 
                    id="hostpay_phone_number" 
                    name="hostpay_phone_number" 
                    value="<?php echo esc_attr($billing_phone); ?>"
                    placeholder="0712345678 or 254712345678"
                    autocomplete="tel"
                />
                <small><?php esc_html_e('Enter the phone number to receive the payment prompt', 'wc-hostpay-mpesa'); ?></small>
            </p>

            <div class="wc-hostpay-stk-steps">
                <h4><?php esc_html_e('What happens next?', 'wc-hostpay-mpesa'); ?></h4>
                <ol>
                    <li><?php esc_html_e('Click "Place Order" below', 'wc-hostpay-mpesa'); ?></li>
                    <li><?php esc_html_e('Check your phone for M-Pesa payment prompt', 'wc-hostpay-mpesa'); ?></li>
                    <li><?php esc_html_e('Enter your M-Pesa PIN to complete payment', 'wc-hostpay-mpesa'); ?></li>
                    <li><?php esc_html_e('Wait for confirmation', 'wc-hostpay-mpesa'); ?></li>
                </ol>
            </div>
        </div>
        <?php
    }

    /**
     * Render manual payment content
     *
     * @param string $shortcode M-Pesa shortcode
     * @param string $account_type Account type (paybill/till)
     * @param WC_Order|null $order Order object (optional)
     */
    private function render_manual_payment_content($shortcode, $account_type, $order = null) {
        ?>
        <div class="wc-hostpay-manual-content">
            <div class="wc-hostpay-instructions-box">
                <h4><?php esc_html_e('INSTRUCTIONS - HOW TO PAY', 'wc-hostpay-mpesa'); ?></h4>
                <ol class="wc-hostpay-payment-steps">
                    <li><?php esc_html_e('Go to your Sim Toolkit', 'wc-hostpay-mpesa'); ?></li>
                    <li><?php esc_html_e('Select Safaricom then M-Pesa', 'wc-hostpay-mpesa'); ?></li>
                    <li><?php esc_html_e('Select Lipa na M-Pesa', 'wc-hostpay-mpesa'); ?></li>
                    <?php if ($account_type === 'paybill') : ?>
                        <li><?php esc_html_e('Select Pay Bill', 'wc-hostpay-mpesa'); ?></li>
                        <li><?php echo sprintf(esc_html__('Enter Business No as: %s', 'wc-hostpay-mpesa'), '<strong>' . esc_html($shortcode) . '</strong>'); ?></li>
                        <li>
                            <?php 
                            if ($order) {
                                echo sprintf(esc_html__('Enter Account No as: %s', 'wc-hostpay-mpesa'), '<strong>' . esc_html($order->get_order_number()) . '</strong>');
                            } else {
                                esc_html_e('Enter Account No as: [Order Number will be shown after placing order]', 'wc-hostpay-mpesa');
                            }
                            ?>
                        </li>
                    <?php else : ?>
                        <li><?php esc_html_e('Select Buy Goods and Services', 'wc-hostpay-mpesa'); ?></li>
                        <li><?php echo sprintf(esc_html__('Enter Till Number as: %s', 'wc-hostpay-mpesa'), '<strong>' . esc_html($shortcode) . '</strong>'); ?></li>
                    <?php endif; ?>
                    <li>
                        <?php 
                        if ($order) {
                            echo sprintf(esc_html__('Enter Amount as: %s', 'wc-hostpay-mpesa'), '<strong>' . wc_price($order->get_total()) . '</strong>');
                        } else {
                            esc_html_e('Enter Amount as: [Total amount will be shown after placing order]', 'wc-hostpay-mpesa');
                        }
                        ?>
                    </li>
                    <li><?php esc_html_e('Enter PIN and Wait for M-Pesa Message', 'wc-hostpay-mpesa'); ?></li>
                </ol>
            </div>

            <div class="wc-hostpay-manual-note">
                <p>
                    <strong><?php esc_html_e('Note:', 'wc-hostpay-mpesa'); ?></strong>
                    <?php esc_html_e('After placing your order, you will see the exact payment details and be able to verify your payment using the M-Pesa transaction code.', 'wc-hostpay-mpesa'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render STK Push form (deprecated - kept for compatibility)
     */
    private function render_stk_push_form() {
        // This method is now deprecated but kept for compatibility
        $this->render_stk_push_content();
    }

    /**
     * Render manual payment option (deprecated - kept for compatibility)
     *
     * @param bool $show_as_option Whether to show as an alternative option
     */
    private function render_manual_payment_option($show_as_option = false) {
        // This method is now deprecated but kept for compatibility
        if ($show_as_option) {
            echo '<input type="checkbox" id="hostpay_use_manual" name="hostpay_use_manual" value="1" style="display:none;" />';
        }
    }

    /**
     * Validate payment fields
     *
     * @return bool
     */
    public function validate_fields() {
        // Phone number is optional - user can choose payment method in modal
        // Validation will happen in the modal if STK Push is selected
        return true;
    }

    /**
     * Process payment
     *
     * @param int $order_id Order ID
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Mark order as pending - user will choose payment method in modal
        $order->update_status('pending', __('Awaiting M-Pesa payment.', 'wc-hostpay-mpesa'));
        
        // Store that this is a HostPay payment (method will be chosen in modal)
        $order->update_meta_data('_hostpay_payment_method', 'pending_choice');
        
        // Store phone number if provided
        $phone_number = isset($_POST['hostpay_phone_number']) ? sanitize_text_field($_POST['hostpay_phone_number']) : '';
        if (!empty($phone_number)) {
            $order->update_meta_data('_hostpay_phone_number', $phone_number);
        }
        
        $order->save();

        // Redirect to thank you page where modal will appear
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    /**
     * Process STK Push payment
     *
     * @param WC_Order $order Order object
     * @return array
     */
    private function process_stk_payment($order) {
        $phone_number = isset($_POST['hostpay_phone_number']) ? sanitize_text_field($_POST['hostpay_phone_number']) : '';

        if (!$this->payment_processor) {
            wc_add_notice(__('Payment gateway not configured properly.', 'wc-hostpay-mpesa'), 'error');
            return array('result' => 'failure');
        }

        $result = $this->payment_processor->process_stk_push($order, $phone_number);

        if ($result['success']) {
            // Mark order as pending payment
            $order->update_status('pending', __('Awaiting M-Pesa payment confirmation.', 'wc-hostpay-mpesa'));
            
            // Store payment method
            $order->update_meta_data('_hostpay_payment_method', 'stk_push');
            $order->save();

            // Return success and redirect to thank you page
            // Modal will handle payment confirmation on the thank you page
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        } else {
            wc_add_notice($result['message'], 'error');
            return array('result' => 'failure');
        }
    }

    /**
     * Process manual payment
     *
     * @param WC_Order $order Order object
     * @return array
     */
    private function process_manual_payment($order) {
        // Mark order as on-hold
        $order->update_status('on-hold', __('Awaiting manual M-Pesa payment.', 'wc-hostpay-mpesa'));
        
        // Store payment method
        $order->update_meta_data('_hostpay_payment_method', 'manual');
        $order->save();

        // Reduce stock
        wc_reduce_stock_levels($order->get_id());

        // Empty cart
        WC()->cart->empty_cart();

        // Return success and redirect to thank you page
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    /**
     * Handle payment confirmation page via template_redirect
     */
    public function handle_payment_confirmation_page() {
        // Check if this is a payment confirmation request
        if (!isset($_GET['wc-hostpay-payment']) || $_GET['wc-hostpay-payment'] !== '1') {
            return;
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        if (!$order_id || !$order_key) {
            wp_die(__('Invalid order', 'wc-hostpay-mpesa'));
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_die(__('Invalid order', 'wc-hostpay-mpesa'));
        }

        // Render the payment confirmation page
        $this->render_payment_confirmation_page($order);
        exit;
    }

    /**
     * Render payment confirmation page
     *
     * @param WC_Order $order Order object
     */
    private function render_payment_confirmation_page($order) {
        // Get account details for manual payment instructions
        $account_id = $this->get_option('mpesa_account');
        $accounts = get_option('wc_hostpay_mpesa_accounts', array());
        $account = null;
        
        foreach ($accounts as $acc) {
            if (isset($acc['id']) && $acc['id'] == $account_id) {
                $account = $acc;
                break;
            }
        }

        $account_type = $account ? wc_hostpay_detect_account_type($account) : 'paybill';
        $shortcode = '';
        if ($account) {
            if ($account_type === 'paybill' && isset($account['paybill_shortcode'])) {
                $shortcode = $account['paybill_shortcode'];
            } elseif ($account_type === 'till' && isset($account['till_shortcode'])) {
                $shortcode = $account['till_shortcode'];
            }
        }

        get_header();
        ?>
        <div class="woocommerce">
            <div class="wc-hostpay-payment-page">
                <div class="wc-hostpay-payment-container">
                    <h1><?php esc_html_e('Complete Your Payment', 'wc-hostpay-mpesa'); ?></h1>
                    
                    <div class="wc-hostpay-order-details">
                        <p><strong><?php esc_html_e('Order Number:', 'wc-hostpay-mpesa'); ?></strong> <?php echo esc_html($order->get_order_number()); ?></p>
                        <p><strong><?php esc_html_e('Amount:', 'wc-hostpay-mpesa'); ?></strong> <?php echo wc_price($order->get_total()); ?></p>
                    </div>

                    <div class="wc-hostpay-payment-status pending" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <div class="wc-hostpay-loading"></div>
                        <p><strong><?php esc_html_e('Waiting for payment confirmation...', 'wc-hostpay-mpesa'); ?></strong></p>
                        <p><?php esc_html_e('Please check your phone for the M-Pesa payment prompt and enter your PIN.', 'wc-hostpay-mpesa'); ?></p>
                    </div>

                    <div class="wc-hostpay-manual-fallback" style="display: none;">
                        <h3><?php esc_html_e('Having trouble?', 'wc-hostpay-mpesa'); ?></h3>
                        <p><?php esc_html_e('If you did not receive the payment prompt, you can pay manually:', 'wc-hostpay-mpesa'); ?></p>
                        
                        <?php $this->render_manual_payment_content($shortcode, $account_type); ?>
                        
                        <div class="wc-hostpay-verify-form">
                            <h4><?php esc_html_e('Verify Your Payment', 'wc-hostpay-mpesa'); ?></h4>
                            <p>
                                <input 
                                    type="text" 
                                    id="hostpay_trans_id" 
                                    placeholder="<?php esc_attr_e('Enter M-Pesa Code (e.g., QGH7XYZ123)', 'wc-hostpay-mpesa'); ?>"
                                />
                                <button 
                                    type="button" 
                                    id="wc-hostpay-verify-btn" 
                                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('wc_hostpay_verify_payment')); ?>"
                                >
                                    <?php esc_html_e('Verify Payment', 'wc-hostpay-mpesa'); ?>
                                </button>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        get_footer();
    }

    /**
     * Thank you page content
     *
     * @param int $order_id Order ID
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        $payment_method = $order->get_meta('_hostpay_payment_method');

        // Show modal for pending HostPay payments
        if (($payment_method === 'pending_choice' || $payment_method === 'stk_push') && $order->has_status('pending')) {
            $this->render_payment_modal($order);
        }
        // Show manual payment instructions for completed manual payments
        elseif ($payment_method === 'manual') {
            $this->render_manual_payment_instructions($order);
        }
        
        // Display transaction ID if available (for all completed payments)
        $trans_id = $order->get_meta('_hostpay_transaction_id');
        if (!empty($trans_id) && $order->is_paid()) {
            ?>
            <section class="woocommerce-order-mpesa-details">
                <h2 class="woocommerce-order-mpesa-details__title"><?php esc_html_e('M-Pesa Payment Details', 'wc-hostpay-mpesa'); ?></h2>
                <table class="woocommerce-table woocommerce-table--mpesa-details shop_table mpesa_details">
                    <tbody>
                        <tr>
                            <th><?php esc_html_e('Transaction ID:', 'wc-hostpay-mpesa'); ?></th>
                            <td><strong><?php echo esc_html($trans_id); ?></strong></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Payment Method:', 'wc-hostpay-mpesa'); ?></th>
                            <td><?php esc_html_e('M-Pesa', 'wc-hostpay-mpesa'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <?php
        }
    }

    /**
     * Render payment confirmation modal
     *
     * @param WC_Order $order Order object
     */
    private function render_payment_modal($order) {
        // Get account details for manual payment instructions
        $account_id = $this->get_option('mpesa_account');
        $accounts = get_option('wc_hostpay_mpesa_accounts', array());
        $account = null;
        
        foreach ($accounts as $acc) {
            if (isset($acc['id']) && $acc['id'] == $account_id) {
                $account = $acc;
                break;
            }
        }

        $account_type = $account ? wc_hostpay_detect_account_type($account) : 'paybill';
        $shortcode = '';
        if ($account) {
            if ($account_type === 'paybill' && isset($account['paybill_shortcode'])) {
                $shortcode = $account['paybill_shortcode'];
            } elseif ($account_type === 'till' && isset($account['till_shortcode'])) {
                $shortcode = $account['till_shortcode'];
            }
        }

        ?>
        <div id="wc-hostpay-payment-modal" class="wc-hostpay-modal" style="display: block;">
            <div class="wc-hostpay-modal-overlay"></div>
            <div class="wc-hostpay-modal-content">
                <div class="wc-hostpay-modal-header">
                    <h2><?php esc_html_e('Complete Your Payment', 'wc-hostpay-mpesa'); ?></h2>
                    <div class="wc-hostpay-order-summary">
                        <span class="order-number"><?php echo esc_html__('Order #', 'wc-hostpay-mpesa') . $order->get_order_number(); ?></span>
                        <span class="order-amount"><?php echo wc_price($order->get_total()); ?></span>
                    </div>
                </div>

                <div class="wc-hostpay-modal-body">
                    <!-- Tab Navigation -->
                    <div class="wc-hostpay-modal-tabs">
                        <button type="button" class="wc-hostpay-modal-tab active" data-tab="stk">
                            <span class="tab-icon">📱</span>
                            <span class="tab-label"><?php esc_html_e('STK Push', 'wc-hostpay-mpesa'); ?></span>
                        </button>
                        <button type="button" class="wc-hostpay-modal-tab" data-tab="manual">
                            <span class="tab-icon">💳</span>
                            <span class="tab-label"><?php esc_html_e('Manual Payment', 'wc-hostpay-mpesa'); ?></span>
                        </button>
                    </div>

                    <!-- Tab Content -->
                    <div class="wc-hostpay-modal-tab-content">
                        <!-- STK Push Tab -->
                        <div class="wc-hostpay-modal-pane active" id="modal-tab-stk">
                            <div class="wc-hostpay-stk-section">
                                <p class="stk-description">
                                    <?php esc_html_e('Enter your M-Pesa phone number below. You will receive a payment prompt on your phone.', 'wc-hostpay-mpesa'); ?>
                                </p>
                                
                                <div class="stk-phone-input">
                                    <label for="hostpay_modal_phone">
                                        <?php esc_html_e('M-Pesa Phone Number', 'wc-hostpay-mpesa'); ?> <span class="required">*</span>
                                    </label>
                                    <input 
                                        type="tel" 
                                        id="hostpay_modal_phone" 
                                        class="input-text"
                                        value="<?php echo esc_attr($order->get_meta('_hostpay_phone_number')); ?>"
                                        placeholder="0712345678 or 254712345678"
                                    />
                                </div>

                                <button 
                                    type="button" 
                                    id="wc-hostpay-initiate-stk-btn" 
                                    class="button button-primary button-large"
                                    data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('wc_hostpay_initiate_stk')); ?>"
                                >
                                    <?php esc_html_e('Send Payment Request', 'wc-hostpay-mpesa'); ?>
                                </button>

                                <!-- Payment status (hidden initially) -->
                                <div class="wc-hostpay-payment-status" style="display: none;" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                                    <div class="status-icon">
                                        <div class="wc-hostpay-spinner"></div>
                                    </div>
                                    <h3 class="status-title"><?php esc_html_e('Checking for payment...', 'wc-hostpay-mpesa'); ?></h3>
                                    <p class="status-message"><?php esc_html_e('Please check your phone for the M-Pesa payment prompt and enter your PIN.', 'wc-hostpay-mpesa'); ?></p>
                                    
                                    <div class="payment-steps">
                                        <div class="step completed">
                                            <span class="step-number">✓</span>
                                            <span class="step-text"><?php esc_html_e('Payment request sent', 'wc-hostpay-mpesa'); ?></span>
                                        </div>
                                        <div class="step active">
                                            <span class="step-number">2</span>
                                            <span class="step-text"><?php esc_html_e('Waiting for confirmation', 'wc-hostpay-mpesa'); ?></span>
                                        </div>
                                        <div class="step">
                                            <span class="step-number">3</span>
                                            <span class="step-text"><?php esc_html_e('Payment complete', 'wc-hostpay-mpesa'); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Manual Payment Tab -->
                        <div class="wc-hostpay-modal-pane" id="modal-tab-manual">
                            <?php $this->render_manual_payment_content($shortcode, $account_type, $order); ?>
                            
                            <div class="wc-hostpay-verify-section">
                                <h4><?php esc_html_e('Verify Your Payment', 'wc-hostpay-mpesa'); ?></h4>
                                <div class="verify-input-group">
                                    <input 
                                        type="text" 
                                        id="hostpay_trans_id_modal" 
                                        placeholder="<?php esc_attr_e('Enter M-Pesa Code (e.g., QGH7XYZ123)', 'wc-hostpay-mpesa'); ?>"
                                    />
                                    <button 
                                        type="button" 
                                        id="wc-hostpay-verify-btn-modal" 
                                        class="button"
                                        data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('wc_hostpay_verify_payment')); ?>"
                                    >
                                        <?php esc_html_e('Verify Payment', 'wc-hostpay-mpesa'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render STK Push payment status
     *
     * @param WC_Order $order Order object
     */
    private function render_stk_push_status($order) {
        ?>
        <div class="wc-hostpay-payment-status" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
            <h2><?php esc_html_e('Payment Status', 'wc-hostpay-mpesa'); ?></h2>
            <div class="hostpay-status-message">
                <p class="checking"><?php esc_html_e('Checking payment status...', 'wc-hostpay-mpesa'); ?></p>
            </div>
            <div class="hostpay-manual-fallback" style="display: none;">
                <p><?php esc_html_e('Payment taking too long? You can verify your payment manually below.', 'wc-hostpay-mpesa'); ?></p>
                <?php $this->render_manual_payment_instructions($order, true); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render manual payment instructions
     *
     * @param WC_Order $order Order object
     * @param bool $show_verification_form Whether to show verification form
     */
    private function render_manual_payment_instructions($order, $show_verification_form = true) {
        // Get account details
        $account_id = $this->get_option('mpesa_account');
        $accounts = get_option('wc_hostpay_mpesa_accounts', array());
        $account = null;
        
        foreach ($accounts as $acc) {
            if (isset($acc['id']) && $acc['id'] == $account_id) {
                $account = $acc;
                break;
            }
        }

        if (!$account) {
            echo '<p>' . esc_html__('Payment instructions not available.', 'wc-hostpay-mpesa') . '</p>';
            return;
        }

        $instructions = wc_hostpay_get_payment_instructions($account, $order);
        ?>
        <div class="wc-hostpay-manual-instructions">
            <h2><?php esc_html_e('Payment Instructions', 'wc-hostpay-mpesa'); ?></h2>
            
            <div class="hostpay-payment-details">
                <?php if ($instructions['type'] === 'paybill') : ?>
                    <p><strong><?php esc_html_e('Paybill Number:', 'wc-hostpay-mpesa'); ?></strong> <?php echo esc_html($instructions['shortcode']); ?></p>
                    <p><strong><?php esc_html_e('Account Number:', 'wc-hostpay-mpesa'); ?></strong> <?php echo esc_html($instructions['reference']); ?></p>
                <?php else : ?>
                    <p><strong><?php esc_html_e('Till Number:', 'wc-hostpay-mpesa'); ?></strong> <?php echo esc_html($instructions['shortcode']); ?></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e('Amount:', 'wc-hostpay-mpesa'); ?></strong> KES <?php echo esc_html($instructions['amount']); ?></p>
            </div>

            <div class="hostpay-payment-steps">
                <h3><?php esc_html_e('How to Pay:', 'wc-hostpay-mpesa'); ?></h3>
                <ol>
                    <?php foreach ($instructions['steps'] as $step) : ?>
                        <li><?php echo esc_html($step); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <?php if ($show_verification_form && $order->get_status() !== 'processing' && $order->get_status() !== 'completed') : ?>
                <div class="hostpay-verification-form">
                    <h3><?php esc_html_e('Verify Your Payment', 'wc-hostpay-mpesa'); ?></h3>
                    <p><?php esc_html_e('After completing the payment, enter the M-Pesa transaction ID (e.g., QGH7XYZ123) to verify:', 'wc-hostpay-mpesa'); ?></p>
                    
                    <form id="hostpay-verify-form" method="post">
                        <p class="form-row">
                            <label for="hostpay_trans_id"><?php esc_html_e('Transaction ID:', 'wc-hostpay-mpesa'); ?></label>
                            <input type="text" id="hostpay_trans_id" name="hostpay_trans_id" class="input-text" required />
                        </p>
                        <p class="form-row">
                            <button type="submit" class="button alt"><?php esc_html_e('Verify Payment', 'wc-hostpay-mpesa'); ?></button>
                        </p>
                        <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
                        <input type="hidden" name="action" value="wc_hostpay_verify_payment" />
                        <?php wp_nonce_field('wc_hostpay_verify_payment', 'hostpay_verify_nonce'); ?>
                    </form>
                    
                    <div class="hostpay-verify-message"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX handler to check payment status
     */
    public function ajax_check_payment_status() {
        check_ajax_referer('wc_hostpay_mpesa_frontend', 'nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order', 'wc-hostpay-mpesa')));
        }

        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found', 'wc-hostpay-mpesa')));
        }

        // Check if already paid
        if ($order->is_paid()) {
            wp_send_json_success(array(
                'status' => 'completed',
                'message' => __('Payment completed!', 'wc-hostpay-mpesa'),
            ));
        }

        if (!$this->payment_processor) {
            wp_send_json_error(array('message' => __('Payment processor not available', 'wc-hostpay-mpesa')));
        }

        $result = $this->payment_processor->query_stk_push_status($order);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler to verify manual payment
     */
    public function ajax_verify_payment() {
        check_ajax_referer('wc_hostpay_verify_payment', 'hostpay_verify_nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $trans_id = isset($_POST['trans_id']) ? sanitize_text_field($_POST['trans_id']) : '';

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order', 'wc-hostpay-mpesa')));
        }

        if (empty($trans_id)) {
            wp_send_json_error(array('message' => __('Transaction ID is required', 'wc-hostpay-mpesa')));
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found', 'wc-hostpay-mpesa')));
        }

        // Check if already paid
        if ($order->is_paid()) {
            wp_send_json_success(array('message' => __('This order has already been paid', 'wc-hostpay-mpesa')));
        }

        if (!$this->payment_processor) {
            wp_send_json_error(array('message' => __('Payment processor not available', 'wc-hostpay-mpesa')));
        }

        $result = $this->payment_processor->verify_manual_payment($order, $trans_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Display M-Pesa transaction details in admin order page
     *
     * @param WC_Order $order Order object
     */
    public function display_admin_order_meta($order) {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $trans_id = $order->get_meta('_hostpay_transaction_id');
        $payment_method = $order->get_meta('_hostpay_payment_method');
        
        if (!empty($trans_id)) {
            ?>
            <div class="order_data_column" style="clear:both; float:none; width:100%;">
                <h3><?php esc_html_e('M-Pesa Payment Details', 'wc-hostpay-mpesa'); ?></h3>
                <p>
                    <strong><?php esc_html_e('Transaction ID:', 'wc-hostpay-mpesa'); ?></strong><br>
                    <code style="background: #f0f0f0; padding: 5px 10px; border-radius: 3px; font-size: 14px;"><?php echo esc_html($trans_id); ?></code>
                </p>
                <?php if (!empty($payment_method)) : ?>
                <p>
                    <strong><?php esc_html_e('Payment Type:', 'wc-hostpay-mpesa'); ?></strong><br>
                    <?php echo esc_html($payment_method === 'stk_push' ? 'STK Push' : 'Manual Payment'); ?>
                </p>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}
