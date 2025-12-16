/**
 * WooCommerce HostPay M-Pesa - Admin JavaScript
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        // Add fetch accounts button after API key field
        const apiKeyField = $('#woocommerce_hostpay_mpesa_api_key');

        if (apiKeyField.length) {
            const fetchButton = $('<button type="button" class="button wc-hostpay-fetch-accounts-btn">Fetch M-Pesa Accounts</button>');
            const messageDiv = $('<div class="wc-hostpay-message"></div>');

            apiKeyField.closest('tr').after(
                $('<tr><td colspan="2"></td></tr>').find('td').append(fetchButton).append(messageDiv).end()
            );

            // Fetch accounts on button click
            fetchButton.on('click', function (e) {
                e.preventDefault();
                fetchMpesaAccounts();
            });
        }

        /**
         * Fetch M-Pesa accounts from API
         */
        function fetchMpesaAccounts() {
            const apiKey = $('#woocommerce_hostpay_mpesa_api_key').val();
            const button = $('.wc-hostpay-fetch-accounts-btn');
            const messageDiv = $('.wc-hostpay-message');

            if (!apiKey) {
                showMessage('Please enter your API key first', 'error');
                return;
            }

            // Show loading state
            button.prop('disabled', true).text('Fetching...');
            messageDiv.html('<span class="wc-hostpay-loading">Loading accounts...</span>');

            $.ajax({
                url: wcHostPayMpesa.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_hostpay_fetch_accounts',
                    api_key: apiKey,
                    nonce: wcHostPayMpesa.nonce
                },
                success: function (response) {
                    console.log('Fetch accounts response:', response);
                    if (response.success) {
                        updateAccountDropdown(response.data.accounts);
                        showMessage(response.data.message, 'success');
                    } else {
                        const errorMsg = response.data && response.data.message
                            ? response.data.message
                            : 'Failed to fetch accounts';
                        console.error('Fetch accounts error:', response);
                        showMessage(errorMsg, 'error');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX error:', { xhr: xhr, status: status, error: error });
                    let errorMsg = 'An error occurred. Please try again.';

                    // Try to get more specific error message
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorMsg = response.data.message;
                            }
                        } catch (e) {
                            // Keep default error message
                        }
                    }

                    showMessage(errorMsg, 'error');
                },
                complete: function () {
                    button.prop('disabled', false).text('Fetch M-Pesa Accounts');
                }
            });
        }

        /**
         * Update account dropdown with fetched accounts
         */
        function updateAccountDropdown(accounts) {
            const select = $('#woocommerce_hostpay_mpesa_mpesa_account');
            const currentValue = select.val();

            // Clear existing options except the first one
            select.find('option:not(:first)').remove();

            // Add new options
            if (accounts && accounts.length > 0) {
                accounts.forEach(function (account) {
                    const displayName = getAccountDisplayName(account);
                    const option = $('<option></option>')
                        .val(account.id)
                        .text(displayName);

                    select.append(option);
                });

                // Restore previous selection if it exists
                if (currentValue) {
                    select.val(currentValue);
                }
            }
        }

        /**
         * Get account display name
         */
        function getAccountDisplayName(account) {
            const name = account.company_business_name || '';
            const type = detectAccountType(account);

            // Get the appropriate shortcode based on account type
            let shortcode = '';
            if (type === 'paybill' && account.paybill_shortcode) {
                shortcode = account.paybill_shortcode;
            } else if (type === 'till' && account.till_shortcode) {
                shortcode = account.till_shortcode;
            }

            if (name && shortcode) {
                return name + ' (' + shortcode + ' - ' + capitalizeFirst(type) + ')';
            } else if (shortcode) {
                return shortcode + ' (' + capitalizeFirst(type) + ')';
            } else if (name) {
                return name;
            }

            return 'Unknown Account';
        }

        /**
         * Detect account type
         */
        function detectAccountType(account) {
            if (account.account_type) {
                return account.account_type.toLowerCase();
            }

            // Fallback: check which shortcode field is populated
            if (account.till_shortcode) {
                return 'till';
            }

            if (account.paybill_shortcode) {
                return 'paybill';
            }

            return 'paybill';
        }

        /**
         * Capitalize first letter
         */
        function capitalizeFirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        /**
         * Show message
         */
        function showMessage(message, type) {
            const messageDiv = $('.wc-hostpay-message');
            const className = type === 'success' ? 'wc-hostpay-success' : 'wc-hostpay-error';

            messageDiv.html('<p class="' + className + '">' + message + '</p>');

            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(function () {
                    messageDiv.fadeOut(function () {
                        $(this).html('').show();
                    });
                }, 5000);
            }
        }
    });

})(jQuery);
