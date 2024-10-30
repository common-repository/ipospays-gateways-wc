<?php

defined('ABSPATH') || exit('Direct access is not allowed.');

// Ensure that the iPOSpays_API class is loaded
include(plugin_dir_path(__FILE__) . 'class.countryphonecode.php');

class iPOSpays_API
{
    private $api_url;

    private $country_phone_code ;

    private $generate_token_api_url;

    public function __construct()
    {
        $this->country_phone_code = new CountryPhoneCode();

        $options = get_option('woocommerce_ipospays_settings', []);
        // Correct the conditional to get the actual value
        $this->api_url = (isset($options['test_mode']) && $options['test_mode'] === 'yes') 
            ? (isset($options['test_URL']) ? $options['test_URL'] : '') 
            : (isset($options['live_URL']) ? $options['live_URL'] : '');
    
        $this->generate_token_api_url = (isset($options['test_mode']) && $options['test_mode'] === 'yes') 
            ? 'https://payment.ipospays.tech/api/v1/' 
            : 'https://payment.ipospays.com/api/v1/';

    }
    


    public function authenticate($secret_key, $api_key, $tpn)
    {
        $logger = wc_get_logger();
        $request_body = ['tpn' => sanitize_text_field($tpn)];
        $headers = [
            'Content-Type' => 'application/json',
            // 'api-key' => str_repeat('*', strlen($api_key) - 4) . substr($api_key, -4),
            // 'secret-key' => str_repeat('*', strlen($secret_key) - 4) . substr($secret_key, -4),
            'api-key' => $api_key,
            'secret-key' => $secret_key,
        ];

        $logger->info('Sending authentication request.', [
            'source' => 'iPOSpays',
            'request_url' => esc_url_raw($this->generate_token_api_url . 'woocomm/generate-token'),
            'headers' => $headers,
            'request_body' => $request_body,
        ]);

        $response = wp_remote_post(esc_url($this->generate_token_api_url . 'woocomm/generate-token'), [
            'method' => 'POST',
            'body' => wp_json_encode($request_body),
            'headers' => ['Content-Type' => 'application/json', 'api-key' => $api_key, 'secret-key' => $secret_key],
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            $logger->error('Authentication request error: ' . $response->get_error_message(), ['source' => 'iPOSpays']);
            return ['status' => 'failure', 'message' => __('Error during authentication.', 'ipospays-gateways-wc')];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = wp_remote_retrieve_response_code($response);
        $logger->info('HTTP status code: ' . $status, ['source' => 'iPOSpays']);
        $logger->info('Authentication response received.', ['source' => 'iPOSpays', 'response_body' => $body]);

        if (
            isset($body['responseCode']) && $body['responseCode'] === '00' &&
            isset($body['responseMessage']) && $body['responseMessage'] === 'Success' &&
            isset($body['data'])
        ) {
            return [
                'status' => 'success',
                'token' => sanitize_text_field($body['data']),
                'webhookToken' => sanitize_text_field($body['webhookAPIKey']),
                'tpn' => sanitize_text_field($body['tpn']),
                'testURL' => sanitize_text_field($body['testURL']),
                'liveURL' => sanitize_text_field($body['liveURL']),
            ];
        }


        $message = $body['responseMessage'] ?? __('Unknown authentication error.', 'ipospays-gateways-wc');
        $logger->error('Authentication failed: ' . $message, ['source' => 'iPOSpays']);
        return ['status' => 'failure', 'message' => $message];
    }

    public function make_redirect_payment_request($order, $token, $webhookToken, $tpn)
    {
        
        $logger = wc_get_logger();
        $amount_in_cents = (int) ($order->get_total() * 100);

        $store_url = home_url();
        $site_url = site_url();

        $site_logo_id = get_theme_mod('custom_logo'); // Get the custom logo ID
        $logo_url = wp_get_attachment_image_url($site_logo_id, 'full'); // Get the URL of the logo

        $primary_color = get_theme_mod('primary_color');

        // Fallback if no primary color is set
        if (!$primary_color) {
            $primary_color = '#000000'; // Default to black if no color is set
        }

        $secondary_color = get_theme_mod('secondary_color');

        // Fallback if the secondary color is not set
        if (!$secondary_color) {
            $secondary_color = '#CCCCCC'; // Default to a light grey color if not set
        }

        $billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        // Get the billing country code
        $billing_country_code = $order->get_billing_country();

        // Get the phone number entered by the customer
        $entered_phone_number = $order->get_billing_phone();

        // Get the correct country dialing code
        $country_dialing_code = $this->country_phone_code->get_phone_country_code($billing_country_code);

        // Check if the phone number already starts with the country dialing code
        if (strpos($entered_phone_number, $country_dialing_code) === 0) {
            // If the phone number already includes the country code, use it as is
            $phone_number = $entered_phone_number;
        } else {
            // If the country code is not already included, prepend it to the phone number
            $phone_number = $country_dialing_code . $entered_phone_number;
        }

        $uuid = $this->generate_uuid_with_random_number(); // Generate UUID

        // Create the error message
        $error_message = __('The payment was canceled. Please try again.', 'ipospays');

        // Generate the checkout URL with the error message and nonce
        $checkout_url = wc_get_checkout_url() . '?error_message=' . urlencode($error_message) . '&_wpnonce=' . wp_create_nonce('checkout_error_nonce');

        $referenceId = 'TPNID' . $tpn . 'ORDERID' . $order->get_id() . 'UNIQUEID' . substr($uuid, 0, 8);

        $request_body = wp_json_encode([
            'merchantAuthentication' => [
                'merchantId' => $tpn,
                'transactionReferenceId' => (string) $referenceId
            ],
            'transactionRequest' => [
                'transactionType' => 1,
                'amount' => (string) $amount_in_cents,
                'calculateFee' => true,
                'calculateTax' => true,
                'tipsInputPrompt' => true,
                'expiry' => 7,
                'feeRemoved' => false,
                'invoiceTxn' => false,
                'tipAmount' => '200',
                'poNumber' => ['poTagLabel' => 'PO Number', 'poTagValue' => ''],
                'sourceType' => 'shoppingcart-woocommernce'
            ],
            'personalization' => ['logoUrl' => (string)$logo_url, 'themeColor' => (string) $secondary_color, "buttonColor" => (string) $primary_color],
            'notificationOption' => ['postAPI' => $site_url . '/wp-json/ipospays/v1/loading', "failureUrl" => (string) $checkout_url, "returnUrl" => $site_url . '/wp-json/ipospays/v1/loading', "notifyBySMS" => "", "cancelUrl" => (string) $checkout_url, "notifyByRedirect" => true, "mobileNumber" => (string) $phone_number, 'notifyByPOST' => true, 'authHeader' => (string) $webhookToken],
            'preferences' => [
                'eReceipt' => true,
                'avsVerification' => true,
                'integrationType' => 1,
                "customerName" => (string) $billing_name,
                'customerMobile' => (string) $phone_number,
                'customerEmail' => (string) $order->get_billing_email(),
                'invoiceId' => (string) $referenceId,
            ],
            'PerformedBy' => ['Email' => (string) $order->get_billing_email()]
        ]);

        $logger->info('Payment request.', [
            'source' => 'iPOSpays',
            'request_url' => esc_url_raw($this->api_url . '/woocomm/payment-link'),
            'headers' => ['Content-Type' => 'application/json', 'token' => sanitize_text_field($token)],
            'request_body' => $request_body,
        ]);

        $response = wp_remote_post($this->api_url . '/woocomm/payment-link', [
            'method' => 'POST',
            'body' => $request_body,
            'headers' => ['Content-Type' => 'application/json', 'token' => sanitize_text_field($token)],
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            return ['status' => 'failure', 'message' => __('Error during payment request.', 'ipospays-gateways-wc')];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $logger->info('Payment request response.', ['source' => 'iPOSpays', 'response' => $body]);

        // Handle the "Invalid token" error by refreshing the token and retrying
        if (isset($body['errors'][0]['message']) && $body['errors'][0]['message'] === 'Payment Processing Token is Expired. Please try with new Token') {
            // Optionally, retrieve the new token from your refresh function if needed and retry the request
            $new_token = $this->get_new_token();
            return $this->make_redirect_payment_request($order, $new_token, $webhookToken, $tpn);
        }

        return isset($body['information']) ? ['status' => 'success', 'checkout_url' => esc_url_raw($body['information'])] : ['status' => 'failure', 'message' => __('Unknown payment error.', 'ipospays-gateways-wc')];
    }

    public function make_emebbed_payment_request($order, $token, $paymentTokenId, $tpn)
    {
        $logger = wc_get_logger();
        $amount_in_cents = (int) ($order->get_total() * 100);

        $billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        // Get the billing country code
        $billing_country_code = $order->get_billing_country();

        // Get the phone number entered by the customer
        $entered_phone_number = $order->get_billing_phone();

        // Get the correct country dialing code
        $country_dialing_code = $this->country_phone_code->get_phone_country_code($billing_country_code);

        // Check if the phone number already starts with the country dialing code
        if (strpos($entered_phone_number, $country_dialing_code) === 0) {
            // If the phone number already includes the country code, use it as is
            $phone_number = $entered_phone_number;
        } else {
            // If the country code is not already included, prepend it to the phone number
            $phone_number = $country_dialing_code . $entered_phone_number;
        }

        $uuid = $this->generate_uuid_with_random_number(); // Generate UUID

        $referenceId = 'TPNID' . $tpn . 'ORDERID' . $order->get_id() . 'UNIQUEID' . substr($uuid, 0, 8);

        $request_body = wp_json_encode([
            'merchantAuthentication' => [
                'merchantId' => $tpn,
                'transactionReferenceId' => (string) $referenceId
            ],
            'transactionRequest' => [
                'transactionType' => 1,
                'amount' => (string) $amount_in_cents,
                'paymentTokenId' => $paymentTokenId,
                'applySteamSettingTipFeeTax' => false,
                'sourceType' => 'shoppingcart-woocommernce'
            ],
            'preferences' => [
                'integrationType' => 1,
                'avsVerification' => true,
                'eReceipt' => true,
                "eReceiptInputPrompt" => true,
                "customerName" => (string) $billing_name,
                'customerEmail' => (string) $order->get_billing_email(),
                'customerMobile' => (string) $phone_number,
                "requestCardToken" => true
            ]
        ]);

        $logger->info('Payment request.', [
            'source' => 'iPOSpays',
            'request_url' => esc_url_raw($this->api_url . '/iposTransact'),
            'headers' => [
                'Content-Type' => 'application/json',
                'token' => sanitize_text_field($token),
            ],
            'request_body' => $request_body,
        ]);

        $response = wp_remote_post($this->api_url . '/iposTransact', [
            'method' => 'POST',
            'body' => $request_body,
            'headers' => [
                'Content-Type' => 'application/json',
                'token' => sanitize_text_field($token),
            ],
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            // Log the error message
            $logger->error('Error during payment request: ' . $response->get_error_message(), array('source' => 'iPOSpays'));
            
            return [
                'status' => 'failure',
                'message' => __('Error during payment request.', 'ipospays-gateways-wc')
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Log the response for debugging
        $logger->info('Payment request response.', [
            'source' => 'iPOSpays',
            'request_url' => esc_url_raw($this->api_url . '/iposTransact'),
            'response' => $body
        ]);

        // Handle token expiration error
        if (isset($body['errors'][0]['message']) && $body['errors'][0]['message'] === 'Payment Processing Token is Expired. Please try with new Token') {
            $new_token = $this->get_new_token(); // Fetch the new token
            return $this->make_emebbed_payment_request($order, $new_token, $paymentTokenId, $tpn); // Retry with new token
        }

        // Extract values from the response
        $payment_info = isset($body['iposhpresponse']) ? $body['iposhpresponse'] : [];

        if (!empty($payment_info)) {
            return [
                'status' => 'success',
                'response_code' => isset($payment_info['responseCode']) ? esc_attr($payment_info['responseCode']) : '',
                'transaction_reference' => isset($payment_info['transactionReferenceId']) ? esc_attr($payment_info['transactionReferenceId']) : '',
                'transaction_type' => isset($payment_info['transactionType']) ? esc_attr($payment_info['transactionType']) : '',
                'transaction_id' => isset($payment_info['transactionId']) ? esc_attr($payment_info['transactionId']) : '',
                'chd_token' => isset($payment_info['chdToken']) ? esc_attr($payment_info['chdToken']) : '',
                'amount' => isset($payment_info['amount']) ? esc_attr($payment_info['amount']) : '',
                'response_approval_code' => isset($payment_info['responseApprovalCode']) ? esc_attr($payment_info['responseApprovalCode']) : '',
                'rrn' => isset($payment_info['rrn']) ? esc_attr($payment_info['rrn']) : '',
                'transaction_number' => isset($payment_info['transactionNumber']) ? esc_attr($payment_info['transactionNumber']) : '',
                'batch_number' => isset($payment_info['batchNumber']) ? esc_attr($payment_info['batchNumber']) : '',
                'total_amount' => isset($payment_info['totalAmount']) ? esc_attr($payment_info['totalAmount']) : '',
            ];
        } else {
            $error_message = $body['errors'][0]['message'];
            return [
                'status' => 'failure',
                'message' => sprintf(__('An error occurred: ', 'ipospays-gateways-wc'), esc_html($error_message))
            ];
        }

    }

    public function refund($token, $tpn, $transaction_reference_id, $rrn, $amount)
    {
        $logger = wc_get_logger();

        $amount_in_cents = (float) $amount * 100;

        // Prepare the request body
        $request_body = [
            'merchantAuthentication' => [
                'merchantId' => sanitize_text_field($tpn),
                'transactionReferenceId' => sanitize_text_field($transaction_reference_id . $this->generate_uuid_with_random_number())
            ],
            'transactionRequest' => [
                'transactionType' => '3', // Assuming 3 is the code for refund
                'rrn' => sanitize_text_field($rrn),
                'amount' => sanitize_text_field($amount_in_cents),
                'sourceType' => 'shoppingcart-woocommernce'
            ]
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'token' => sanitize_text_field($token)
        ];

        $logger->info('Sending refund request.', [
            'source' => 'iPOSpays',
            'request_url' => esc_url_raw($this->api_url . '/iposTransact'), // Ensure URL is correct
            'headers' => $headers,
            'request_body' => $request_body
        ]);

        $response = wp_remote_post(esc_url($this->api_url . '/iposTransact'), [
            'method' => 'POST',
            'body' => wp_json_encode($request_body),
            'headers' => $headers,
            'timeout' => 45
        ]);

        if (is_wp_error($response)) {
            $logger->error('Refund request error: ' . $response->get_error_message(), ['source' => 'iPOSpays']);
            return ['status' => 'failure', 'message' => __('Error during refund.', 'ipospays-gateways-wc')];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = wp_remote_retrieve_response_code($response);
        $logger->info('HTTP status code: ' . $status, ['source' => 'iPOSpays']);
        $logger->info('Refund response received.', ['source' => 'iPOSpays', 'response_body' => $body]);

        // Handle token expiration error
        if (isset($body['errors'][0]['message']) && $body['errors'][0]['message'] === 'Payment Processing Token is Expired. Please try with new Token') {
            $new_token = $this->get_new_token(); // Fetch the new token
            return $this->refund($new_token, $tpn, $transaction_reference_id, $rrn, $amount); // Retry with new token
        }

        // Extract values from the response
        $payment_info = isset($body['iposhpresponse']) ? $body['iposhpresponse'] : [];

        if (!empty($payment_info)) {
            return [
                'status' => 'success',
                'message' => __('Refund processed successfully.', 'ipospays-gateways-wc')
            ];
        }

        $message = $body['responseMessage'] ?? __('Unknown refund error.', 'ipospays-gateways-wc');
        $logger->error('Refund failed: ' . $message, ['source' => 'iPOSpays']);
        return ['status' => 'failure', 'message' => $message];
    }

    public function get_new_token($secret_key, $api_key, $tpn)
    {
        $logger = wc_get_logger();
        $request_body = ['tpn' => sanitize_text_field($tpn)];
        $headers = [
            'Content-Type' => 'application/json',
            'api-key' => str_repeat('*', strlen($api_key) - 4) . substr($api_key, -4),
            'secret-key' => str_repeat('*', strlen($secret_key) - 4) . substr($secret_key, -4),
        ];

        $logger->info('Sending authentication request.', [
            'source' => 'iPOSpays',
            'request_url' => esc_url_raw($this->api_url . 'woocomm/refresh-token'),
            'headers' => $headers,
            'request_body' => $request_body,
        ]);

        $response = wp_remote_post(esc_url($this->api_url . 'woocomm/refresh-token'), [
            'method' => 'POST',
            'body' => wp_json_encode($request_body),
            'headers' => ['Content-Type' => 'application/json', 'api-key' => $api_key, 'secret-key' => $secret_key],
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            $logger->error('Authentication request error: ' . $response->get_error_message(), ['source' => 'iPOSpays']);
            return ['status' => 'failure', 'message' => __('Error during authentication.', 'ipospays-gateways-wc')];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = wp_remote_retrieve_response_code($response);
        $logger->info('HTTP status code: ' . $status, ['source' => 'iPOSpays']);
        $logger->info('Authentication response received.', ['source' => 'iPOSpays', 'response_body' => $body]);

        if (
            isset($body['responseCode']) && $body['responseCode'] === '00' &&
            isset($body['responseMessage']) && $body['responseMessage'] === 'Success' &&
            isset($body['data'])
        ) {
            return [
                'status' => 'success',
                'token' => sanitize_text_field($body['data']),
                'webhookToken' => sanitize_text_field($body['webhookAPIKey']),
                'tpn' => sanitize_text_field($body['tpn'])
            ];
        }


        $message = $body['responseMessage'] ?? __('Unknown authentication error.', 'ipospays-gateways-wc');
        $logger->error('Authentication failed: ' . $message, ['source' => 'iPOSpays']);
        return ['status' => 'failure', 'message' => $message];
    }

    /**
     * Generate a version 4 UUID with an additional random number between 2 and 5 (no hyphens).
     *
     * @return string
     */
    private function generate_uuid_with_random_number()
    {
        $uuid = sprintf(
            '%04x%04x%04x%04x%04x%04x%04x',
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff),
            wp_rand(0, 0x0fff) | 0x4000,
            wp_rand(0, 0x3fff) | 0x8000,
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff)
        );

        // Generate a random number between 2 and 5 using wp_rand
        $random_number = wp_rand(2, 5);

        // Append the random number to the UUID (as a string)
        return $uuid . $random_number;
    }

}