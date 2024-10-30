<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}


// Ensure that the iPOSpays_API class is loaded
include(plugin_dir_path(__FILE__) . 'class-api.php');

class iPOSpays_Gateway extends WC_Payment_Gateway
{

  private $ipospays_api;

  private $payment_token_id;

  /**
   * Constructor for the gateway.
   */
  public function __construct()
  {
    $this->id = 'ipospays';
    $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
    $this->has_fields = true; // in case you need a custom credit card form
    $this->method_title = 'iPOSpays';
    $this->method_description = 'Accept credit/debit cards, ACH, and Google Pay.'; // Will be displayed on the options page

    // Load the settings
    $this->init_form_fields();
    $this->init_settings();
    $this->supports = array('products', 'refunds');
    // Define user settings
    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->enabled = $this->get_option('enabled');

    $this->ipospays_api = new iPOSpays_API();

    // Register the REST API route for the loading page
    add_action('rest_api_init', [$this, 'register_rest_routes']);
  }

  /**
   * Registers REST API routes.
   */
  public function register_rest_routes()
  {
    register_rest_route('ipospays/v1', '/loading', array(
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'ipospay_loading_page'], // Ensure this is a public method
      'permission_callback' => '__return_true',
    ));

    // New payment_token_id route
    register_rest_route('ipospays/v1', '/payment_token_id', array(
      'methods' => WP_REST_Server::CREATABLE,
      'callback' => [$this, 'ipospays_payment_token_id_page'], // Ensure this is a public method
      'permission_callback' => '__return_true',
    ));

  }

  // Admin options for your payment gateway
  public function init_form_fields()
  {
    $options = get_option('woocommerce_ipospays_settings');
    $ipospays_payment_methods_array = json_decode($options['payment_methods'], true);
    $this->form_fields = array(
      'enabled' => array(
        'title' => 'Enable/Disable',
        'label' => 'Enable iPOSpays Gateway',
        'type' => 'checkbox',
        'description' => '',
        'default' => 'no',
      ),
      'title' => array(
        'title' => 'Title',
        'type' => 'text',
        'description' => 'This controls the title which the user sees during checkout.',
        'default' => $ipospays_payment_methods_array['credit_card']['name'] ?? 'Payment Options',
        'desc_tip' => true,
      ),
      'description' => array(
        'title' => 'Description',
        'type' => 'textarea',
        'description' => 'This controls the description which the user sees during checkout.',
        'default' => $ipospays_payment_methods_array['credit_card']['description'] ?? 'Allow customers to pay with their preferred payment method without leaving your store',
      )
    );
  }

  public function payment_fields()
  {
    // Retrieve the settings
    $options = get_option('woocommerce_ipospays_settings');
    $ipospays_payment_methods_array = json_decode($options['payment_methods'], true);
    echo esc_html($ipospays_payment_methods_array['credit_card']['description']);
    if($options['test_mode'] === "yes"){
      echo '<p style="font-size: x-small;">Test mode active: All transactions are simulated for testing purposes only; no real purchases can be made in this mode.</p>';
    }
    // if($options['embedded_payment'] === "yes"){
    //   require(iPOSpays_PLUGIN_DIR_PATH . 'card.php');
    // }
  }

  // Admin options for your payment gateway
  public function admin_options()
  {
    global $hide_save_button;
    $hide_save_button = true;
    require(iPOSpays_PLUGIN_DIR_PATH . 'includes/admin/setting.php');
  }

  // Validate payment fields
  public function validate_fields()
  {
    return true;
  }

  /**
   * The callback function for the loading page.
   * 
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public function ipospay_loading_page(WP_REST_Request $request)
  {
    $parameters = $request->get_params();

    // Extract and sanitize the parameters from the response
    $response_code = sanitize_text_field($parameters['responseCode'] ?? '');
    $response_message = sanitize_text_field($parameters['responseMessage'] ?? '');
    $transaction_reference_id = sanitize_text_field($parameters['transactionReferenceId'] ?? '');
    $transaction_type = sanitize_text_field($parameters['transactionType'] ?? '');
    $transaction_number = sanitize_text_field($parameters['transactionNumber'] ?? '');
    $batch_number = sanitize_text_field($parameters['batchNumber'] ?? '');
    $card_type = sanitize_text_field($parameters['cardType'] ?? '');
    $card_last4_digit = sanitize_text_field($parameters['cardLast4Digit'] ?? '');
    $amount = sanitize_text_field($parameters['amount'] ?? '');
    $tips = sanitize_text_field($parameters['tips'] ?? '');
    $custom_fee = sanitize_text_field($parameters['customFee'] ?? '');
    $local_tax = sanitize_text_field($parameters['localTax'] ?? '');
    $state_tax = sanitize_text_field($parameters['stateTax'] ?? '');
    $total_amount = sanitize_text_field($parameters['totalAmount'] ?? '');
    $response_approval_code = sanitize_text_field($parameters['responseApprovalCode'] ?? '');
    $rrn = sanitize_text_field($parameters['RRN'] ?? '');
    $transaction_id = sanitize_text_field($parameters['transactionId'] ?? '');

    // Log the received parameters for debugging purposes
    $logger = wc_get_logger();
    $logger->info('Received iPOSpays response: ' . wp_json_encode($parameters), ['source' => 'iPOSpays']);

    // Extract the order ID from the transaction reference ID
    // $reference_segments = explode('a', $transaction_reference_id);
    // $order_id = end($reference_segments);
    $regex = '/TPNID(\d+)ORDERID(\d+)UNIQUEID([a-z0-9]+)/'; // Updated regex pattern to match the structure of the ID

    preg_match($regex, $transaction_reference_id, $matches);

    if (isset($matches[2])) {
      $order_id = $matches[2]; // Capture the ORDERID
    }

    // Validate order ID
    if (!$order_id || !is_numeric($order_id)) {
      return new WP_REST_Response(['error' => 'Invalid order ID.'], 400);
    }

    if ($response_code === '200') {
      $order = wc_get_order($order_id);
      if ($order) {
        // Store response data as order meta
        $order->update_meta_data('_response_code', $response_code);
        $order->update_meta_data('_response_message', $response_message);
        $order->update_meta_data('_transaction_reference_id', $transaction_reference_id);
        $order->update_meta_data('_transaction_type', $transaction_type);
        $order->update_meta_data('_transaction_number', $transaction_number);
        $order->update_meta_data('_batch_number', $batch_number);
        $order->update_meta_data('_card_type', $card_type);
        $order->update_meta_data('_card_last4_digit', $card_last4_digit);
        $order->update_meta_data('_amount', $amount);
        $order->update_meta_data('_tips', $tips);
        $order->update_meta_data('_custom_fee', $custom_fee);
        $order->update_meta_data('_local_tax', $local_tax);
        $order->update_meta_data('_state_tax', $state_tax);
        $order->update_meta_data('_total_amount', $total_amount);
        $order->update_meta_data('_response_approval_code', $response_approval_code);
        $order->update_meta_data('_rrn', $rrn);
        $order->update_meta_data('_transaction_id', $transaction_id);

        // Ensure $total_amount is a number
        $total_amount = (float) $total_amount; // Convert to float

        // Now perform the division
        $order->set_total($total_amount / 100);

        // Save the order meta data
        $order->save();

        // Process the payment and complete the order
        $order->payment_complete();
        $order->add_order_note(__('iPOSpays Payment Completed Successfully.', 'ipospays-gateways-wc'));

        // Construct the URL for redirection
        $redirect_url = esc_url($this->get_return_url($order));

        // Redirect to the loading page
        wp_safe_redirect($redirect_url);
        exit;
      }
    } else {
      $logger->info('Error: ' . wp_json_encode($parameters), ['source' => 'iPOSpays']);

      // Create the error message
      $error_message = __('The payment was canceled. Please try again.', 'ipospays');

      // Generate the checkout URL with the error message and nonce
      $checkout_url = wc_get_checkout_url() . '?error_message=' . urlencode($error_message) . '&_wpnonce=' . wp_create_nonce('checkout_error_nonce');

      // Redirect to the checkout page
      wp_safe_redirect($checkout_url);
      exit; // Ensure script execution stops after redirection

    }


    // return new WP_REST_Response(['error' => 'Order not found.'], 404);
  }


  /**
   * Handles the payment token ID and stores it.
   *
   * @param WP_REST_Request $request The request object containing the parameters.
   * @return WP_REST_Response The response object with the result of the operation.
   */
  public function ipospays_payment_token_id_page(WP_REST_Request $request)
  {
    // Get parameters from the request
    $response_card_token = isset($request['responseCardTokenId']) ? sanitize_text_field($request['responseCardTokenId']) : '';

    // Validate the parameters
    if (empty($response_card_token)) {
      return new WP_REST_Response(['error' => 'Missing required parameters.'], 400);
    }

    // Store the token ID in an option
    update_option('ipospays_payment_token_id', $response_card_token);

    // Log the received data (for debugging)
    $logger = wc_get_logger();
    $logger->info('Received payment token ID: ' . $this->payment_token_id, ['source' => 'iPOSpays']);

    return new WP_REST_Response(['success' => 'Payment token ID received.'], 200);
  }

  /**
   * Refund notification message for order notes.
   *
   * Translators: %1$s is the amount (formatted), %2$s is the reason for the refund.
   */
  public function process_refund($order_id, $amount = null, $reason = '')
  {
    $order = wc_get_order($order_id);
    $logger = wc_get_logger();
    $options = get_option('woocommerce_ipospays_settings', []);

    $logger->info('Processing refund for order ID: ' . $order_id . '. Amount: ' . $amount, ['source' => 'iPOSpays']);

    $mode = $options['test_mode'] === 'yes' ? 'test' : 'live';
    $keys = ['secret_key', 'api_key', 'tpn'];
    foreach ($keys as $key) {
      ${$key} = sanitize_text_field($options["{$mode}_{$key}"]);
    }


    $transaction_reference_id = $tpn . 'r' . $order_id;

    // Call the refund API through your iPOSpays_API class
    $refund_response = $this->ipospays_api->refund($options['token'], $tpn, $transaction_reference_id, $order->get_meta('_rrn'), $amount);
    if ($refund_response['status'] === 'success') {

      $order->add_order_note(sprintf(
        /* Translators: %1$s is the amount (formatted), %2$s is the reason for the refund. */
        __('Refund of %1$s completed. Reason: %2$s', 'ipospays-gateways-wc'),
        wc_price($amount),
        $reason
      ));

      $logger->info('Refund successful for order ID: ' . $order_id . '. Amount: ' . $amount, ['source' => 'iPOSpays']);
      return true;
    } else {
      $logger->error('Refund failed for order ID: ' . $order_id . '. Message: ' . $refund_response['message'], ['source' => 'iPOSpays']);
      return new WP_Error('error', __('Refund failed: ', 'ipospays-gateways-wc') . esc_html($refund_response['message']));
    }
  }

  /**
   * Process the payment and return the result.
   *
   * @param int $order_id Order ID.
   * @return array
   */
  public function process_payment($order_id)
  {
    // Retrieve the payment token ID from the option
    $this->payment_token_id = get_option('ipospays_payment_token_id', '');
    $order = wc_get_order($order_id);
    $logger = wc_get_logger();
    $options = get_option('woocommerce_ipospays_settings', []);
    $logger->info('Processing payment for order ID: ' . $order_id, ['source' => 'iPOSpays']);

    if (!$this->validate_fields()) {
      $logger->error('Validation failed for order ID: ' . $order_id, ['source' => 'iPOSpays']);
      return ['result' => 'failure', 'redirect' => ''];
    }

    $mode = $options['test_mode'] === 'yes' ? 'test' : 'live';
    $keys = ['secret_key', 'api_key', 'tpn'];
    foreach ($keys as $key) {
      ${$key} = sanitize_text_field($options["{$mode}_{$key}"]);
    }

    if (isset($options['embedded_payment']) && $options['embedded_payment'] === 'yes') {
      $logger->info('Sending authentication request with token ID: ' . $this->payment_token_id, ['source' => 'iPOSpays']);

      $logger->info('Authentication successful for token ID: ' . $this->payment_token_id, ['source' => 'iPOSpays']);

      // Check if the response contains necessary data
      $payment_response = $this->ipospays_api->make_emebbed_payment_request(
        $order,
        $options['token'],
        $this->payment_token_id,
        $tpn
      );

      if (
        isset($payment_response['status']) &&
        $payment_response['status'] === 'success' &&
        isset($payment_response['response_code']) &&
        $payment_response['response_code'] === '200'
      ) {
        delete_option('ipospays_payment_token_id');
        $this->payment_token_id = null;
        // Extract and sanitize the parameters from the response
        $transaction_reference_id = sanitize_text_field($payment_response['transaction_reference'] ?? '');
        $transaction_type = sanitize_text_field($payment_response['transaction_type'] ?? '');
        $transaction_id = sanitize_text_field($payment_response['transaction_id'] ?? '');
        $chd_token = sanitize_text_field($payment_response['chd_token'] ?? '');
        $amount = sanitize_text_field($payment_response['amount'] ?? '');
        $response_approval_code = sanitize_text_field($payment_response['response_approval_code'] ?? '');
        $rrn = sanitize_text_field($payment_response['rrn'] ?? '');
        $transaction_number = sanitize_text_field($payment_response['transaction_number'] ?? '');
        $batch_number = sanitize_text_field($payment_response['batch_number'] ?? '');
        $total_amount = sanitize_text_field($payment_response['total_amount'] ?? '');

        $order->update_meta_data('_transaction_reference_id', $transaction_reference_id);
        $order->update_meta_data('_transaction_type', $transaction_type);
        $order->update_meta_data('_transaction_id', $transaction_id);
        $order->update_meta_data('_chd_token', $chd_token);
        $order->update_meta_data('_amount', $amount);
        $order->update_meta_data('_response_approval_code', $response_approval_code);
        $order->update_meta_data('_rrn', $rrn);
        $order->update_meta_data('_transaction_number', $transaction_number);
        $order->update_meta_data('_batch_number', $batch_number);
        $order->update_meta_data('_total_amount', $total_amount);

        // Save the order meta data
        $order->save();

        // Process the payment and complete the order
        $order->payment_complete();
        $order->add_order_note(__('iPOSpays payment completed.', 'ipospays-gateways-wc'));
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
      } else {
        delete_option('ipospays_payment_token_id');
        // Log the error from the payment response
        $logger->error('Payment error for order ID: ' . $order_id . '. Message: ' . (isset($payment_response['message']) ? $payment_response['message'] : 'Unknown error'), ['source' => 'iPOSpays']);
        wc_add_notice(__('Payment error: ', 'ipospays-gateways-wc') . (isset($payment_response['message']) ? esc_html($payment_response['message']) : 'Unknown error'), 'error');
      }

      return ['result' => 'failure', 'redirect' => ''];
    }

    if (isset($options['redirect_payment']) && $options['redirect_payment'] === 'yes') {
      $logger->info('Authentication successful for order ID: ' . $order_id, ['source' => 'iPOSpays']);
      $payment_response = $this->ipospays_api->make_redirect_payment_request($order, $options['token'], $options['webhookToken'], $tpn);
      if ($payment_response['status'] === 'success') {
        $logger->info('Payment successful for order ID: ' . $order_id . '. Checkout URL: ' . $payment_response['checkout_url'], ['source' => 'iPOSpays']);
        return ['result' => 'success', 'redirect' => esc_url($payment_response['checkout_url'])];
      }
      $logger->error('Payment error for order ID: ' . $order_id . '. Message: ' . $payment_response['message'], ['source' => 'iPOSpays']);
      wc_add_notice(__('Payment error: ', 'ipospays-gateways-wc') . esc_html($payment_response['message']), 'error');
      return ['result' => 'failure', 'redirect' => ''];
    }
  }

}
?>