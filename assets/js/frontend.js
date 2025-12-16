/**
 * WooCommerce HostPay M-Pesa - Frontend JavaScript
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        console.log('HostPay M-Pesa frontend script loaded');

        // Auto-fill phone number from billing phone on checkout
        const billingPhone = $('#billing_phone').val();
        if (billingPhone && !$('#hostpay_phone_number').val()) {
            $('#hostpay_phone_number').val(billingPhone);
        }

        // Update M-Pesa phone when billing phone changes
        $('#billing_phone').on('change', function () {
            if (!$('#hostpay_phone_number').val()) {
                $('#hostpay_phone_number').val($(this).val());
            }
        });

        // ========================================
        // MODAL - Tab Switching
        // ========================================

        $(document).on('click', '.wc-hostpay-modal-tab', function (e) {
            e.preventDefault();
            console.log('Modal tab clicked');

            const tab = $(this).data('tab');
            console.log('Switching to modal tab:', tab);

            // Update button states
            $('.wc-hostpay-modal-tab').removeClass('active');
            $(this).addClass('active');

            // Update tab content
            $('.wc-hostpay-modal-pane').removeClass('active');
            $('#modal-tab-' + tab).addClass('active');
        });

        // ========================================
        // MODAL - STK Push Initiation
        // ========================================

        $(document).on('click', '#wc-hostpay-initiate-stk-btn', function (e) {
            e.preventDefault();
            initiateSTKPush($(this));
        });

        function initiateSTKPush($button) {
            const orderId = $button.data('order-id');
            const phoneNumber = $('#hostpay_modal_phone').val().trim();
            const nonce = $button.data('nonce');

            if (!phoneNumber) {
                alert('Please enter your M-Pesa phone number');
                $('#hostpay_modal_phone').focus();
                return;
            }

            // Disable button and show loading
            $button.prop('disabled', true).text('Sending request...');

            $.ajax({
                url: wcHostPayMpesa.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_hostpay_initiate_stk',
                    order_id: orderId,
                    phone_number: phoneNumber,
                    nonce: nonce
                },
                success: function (response) {
                    console.log('STK initiation response:', response);

                    if (response.success) {
                        // Hide the form, show the payment status
                        $('.wc-hostpay-stk-section').find('.stk-description, .stk-phone-input, #wc-hostpay-initiate-stk-btn').hide();
                        $('.wc-hostpay-payment-status').show().addClass('pending');

                        // Start polling for payment status
                        startPaymentPolling(orderId);
                    } else {
                        alert(response.data.message || 'Failed to initiate payment. Please try again.');
                        $button.prop('disabled', false).text('Send Payment Request');
                    }
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                    $button.prop('disabled', false).text('Send Payment Request');
                }
            });
        }

        // ========================================
        // MODAL - Payment Status Polling
        // ========================================

        function startPaymentPolling(orderId) {
            console.log('Starting payment polling for order:', orderId);

            let attempts = 0;
            const maxAttempts = 30; // 30 attempts = 2.5 minutes

            const pollInterval = setInterval(function () {
                attempts++;
                console.log('Polling attempt:', attempts);

                $.ajax({
                    url: wcHostPayMpesa.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wc_hostpay_check_payment_status',
                        order_id: orderId,
                        nonce: wcHostPayMpesa.nonce
                    },
                    success: function (response) {
                        console.log('Poll response:', response);

                        if (response.success) {
                            if (response.data.status === 'completed') {
                                // Payment successful!
                                clearInterval(pollInterval);
                                showPaymentSuccess(response.data.message);

                                // Update steps
                                $('.payment-steps .step').removeClass('active').addClass('completed');

                                // Reload page after 2 seconds to show thank you message
                                setTimeout(function () {
                                    location.reload();
                                }, 2000);
                            } else if (response.data.status === 'failed' || response.data.status === 'cancelled') {
                                // Payment failed
                                clearInterval(pollInterval);
                                showPaymentError(response.data.message);

                                // Switch to manual payment tab
                                setTimeout(function () {
                                    $('.wc-hostpay-modal-tab[data-tab="manual"]').click();
                                }, 3000);
                            }
                            // If pending, continue polling
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Poll error:', { xhr: xhr, status: status, error: error });
                        // Continue polling on error
                    }
                });

                // Stop after max attempts
                if (attempts >= maxAttempts) {
                    clearInterval(pollInterval);
                    showPaymentTimeout();

                    // Switch to manual payment tab
                    setTimeout(function () {
                        $('.wc-hostpay-modal-tab[data-tab="manual"]').click();
                    }, 2000);
                }
            }, 5000); // Poll every 5 seconds
        }

        function showPaymentSuccess(message) {
            const $status = $('.wc-hostpay-payment-status');
            $status.removeClass('pending error').addClass('success');
            $status.find('.status-title').text(message || 'Payment Successful!');
            $status.find('.status-message').text('Your order has been confirmed. Redirecting...');
        }

        function showPaymentError(message) {
            const $status = $('.wc-hostpay-payment-status');
            $status.removeClass('pending success').addClass('error');
            $status.find('.status-title').text(message || 'Payment Failed');
            $status.find('.status-message').text('Please try manual payment or contact support.');
        }

        function showPaymentTimeout() {
            const $status = $('.wc-hostpay-payment-status');
            $status.removeClass('pending success').addClass('error');
            $status.find('.status-title').text('Payment Confirmation Timeout');
            $status.find('.status-message').text('We couldn\'t confirm your payment automatically. Please use manual payment below.');
        }

        // ========================================
        // MODAL - Manual Payment Verification
        // ========================================

        $(document).on('click', '#wc-hostpay-verify-btn-modal', function (e) {
            e.preventDefault();
            verifyManualPayment($(this));
        });

        function verifyManualPayment($button) {
            const orderId = $button.data('order-id');
            const transId = $('#hostpay_trans_id_modal').val().trim();
            const nonce = $button.data('nonce');

            if (!transId) {
                alert('Please enter the M-Pesa transaction ID');
                return;
            }

            // Disable button and show loading
            $button.prop('disabled', true).text('Verifying...');

            $.ajax({
                url: wcHostPayMpesa.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_hostpay_verify_payment',
                    order_id: orderId,
                    trans_id: transId,
                    hostpay_verify_nonce: nonce
                },
                success: function (response) {
                    console.log('Verification response:', response);

                    if (response.success) {
                        // Switch to STK tab and show success
                        $('.wc-hostpay-modal-tab[data-tab="stk"]').click();

                        // Hide STK form and show success status
                        $('.wc-hostpay-stk-section').find('.stk-description, .stk-phone-input, #wc-hostpay-initiate-stk-btn').hide();
                        $('.wc-hostpay-payment-status').show().removeClass('pending error').addClass('success');
                        $('.wc-hostpay-payment-status .status-title').text(response.data.message);
                        $('.wc-hostpay-payment-status .status-message').text('Your order has been confirmed. Redirecting...');
                        $('.payment-steps .step').removeClass('active').addClass('completed');

                        // Reload page after 2 seconds
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        alert(response.data.message || 'Verification failed. Please check the transaction ID and try again.');
                        $button.prop('disabled', false).text('Verify Payment');
                    }
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                    $button.prop('disabled', false).text('Verify Payment');
                }
            });
        }

    });

})(jQuery);
