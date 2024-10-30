<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <?php

    // Check if the 'ipospays_settings' option exists
	if ( get_option( 'ipospays_settings' ) !== false ) {
		// Delete the 'ipospays_settings' option
		delete_option( 'ipospays_settings' );
		delete_option( 'woocommerce_ipospays_settings' );
	}
    
    $ipospays_api = new iPOSpays_API();

    // Fetch current options or set default values if not available
    $options = get_option('woocommerce_ipospays_settings', array());

    if (!$options) {
        // Define your payment methods array
        $payment_methods = array(
            'credit_card' => array(
                'name' => 'Payment Options',
                'enabled' => true,
                'description' => 'Allow customers to pay with their preferred payment method without leaving your store',
            ),
            'ach' => array(
                'name' => 'ACH',
                'enabled' => false,
                'description' => 'Enable ACH payments for your customers.',
            ),
        );

        // Options are not set, initialize with default values
        $options = array(
            'payment_methods' => wp_json_encode($payment_methods),
            'express_checkout' => 'no',
            'enabled' => 'yes',
            'test_mode' => 'no',
            'embedded_payment' => 'no',  // Corrected typo here
            'redirect_payment' => 'no',
            'test_api_key' => '',
            'test_secret_key' => '',
            'test_tpn' => '',
            'live_api_key' => '',
            'live_secret_key' => '',
            'live_tpn' => '',
            'token' => '',
            'test_URL' => '',
            'live_URL' => '',
            'webhookToken' => '',
        );
        update_option('woocommerce_ipospays_settings', $options);
        echo '<div class="notice notice-warning"><p>Default settings have been applied.</p></div>';
    }

    // Decode the payment methods into a global variable
    global $ipospays_payment_methods_array;
    $ipospays_payment_methods_array = isset($options['payment_methods']) ? json_decode($options['payment_methods'], true) : array();

    // Check if form is submitted and process the input
    if (isset($_POST['woocommerce_ipospays_settings'])) {
        // Verify nonce directly from $_POST
        if (isset($_POST['woocommerce_ipospay_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce_ipospay_nonce'])), 'save_ipospays_settings')) {

            // Ensure the necessary fields are set
            $api_key = sanitize_text_field(wp_unslash($_POST['test_api_key'] ?? ''));
            $secret_key = sanitize_text_field(wp_unslash($_POST['test_secret_key'] ?? ''));
            $tpn = sanitize_text_field(wp_unslash($_POST['test_tpn'] ?? ''));

            // Check if 'test_mode' is present in $_POST and set test_mode accordingly
            $test_mode = isset($_POST['test_mode']) ? sanitize_text_field(wp_unslash($_POST['test_mode'])) : 'no';

            // Use test or live API credentials based on the value of test_mode
            $api_key = ($test_mode === 'yes')
                ? (isset($_POST['test_api_key']) ? sanitize_text_field(wp_unslash($_POST['test_api_key'])) : '')
                : (isset($_POST['live_api_key']) ? sanitize_text_field(wp_unslash($_POST['live_api_key'])) : '');

            $secret_key = ($test_mode === 'yes')
                ? (isset($_POST['test_secret_key']) ? sanitize_text_field(wp_unslash($_POST['test_secret_key'])) : '')
                : (isset($_POST['live_secret_key']) ? sanitize_text_field(wp_unslash($_POST['live_secret_key'])) : '');

            $tpn = ($test_mode === 'yes')
                ? (isset($_POST['test_tpn']) ? sanitize_text_field(wp_unslash($_POST['test_tpn'])) : '')
                : (isset($_POST['live_tpn']) ? sanitize_text_field(wp_unslash($_POST['live_tpn'])) : '');

            // Authenticate via API
            $auth_response = $this->ipospays_api->authenticate($secret_key, $api_key, $tpn);

            // Log the received parameters for debugging purposes
            $logger = wc_get_logger();
            $logger->info('Received iPOSpays Auth response: ' . wp_json_encode($auth_response), ['source' => 'iPOSpays']);

            // Sanitize and process user input
            $payment_methods = array(
                'credit_card' => array(
                    'name' => isset($_POST['payment_methods']['credit_card']['name']) ? sanitize_text_field(wp_unslash($_POST['payment_methods']['credit_card']['name'])) : '',
                    'description' => isset($_POST['payment_methods']['credit_card']['description']) ? sanitize_text_field(wp_unslash($_POST['payment_methods']['credit_card']['description'])) : '',
                    'enabled' => isset($_POST['payment_methods']['credit_card']['enabled']) ? 'yes' : 'no',
                ),
                'ach' => array(
                    'name' => isset($_POST['payment_methods']['ach']['name']) ? sanitize_text_field(wp_unslash($_POST['payment_methods']['ach']['name'])) : '',
                    'description' => isset($_POST['payment_methods']['ach']['description']) ? sanitize_text_field(wp_unslash($_POST['payment_methods']['ach']['description'])) : '',
                    'enabled' => isset($_POST['payment_methods']['ach']['enabled']) ? 'yes' : 'no',
                ),
            );

            // Sanitize other settings
            $options = array(
                'payment_methods' => wp_json_encode($payment_methods),
                'express_checkout' => isset($_POST['express_checkout']) ? 'yes' : 'no',
                'enabled' => isset($_POST['enabled']) ? 'yes' : 'no',
                'test_mode' => isset($_POST['test_mode']) ? 'yes' : 'no',
                'embedded_payment' => isset($_POST['embedded_payment']) ? 'yes' : 'no',
                'redirect_payment' => isset($_POST['redirect_payment']) ? 'yes' : 'no',
                'test_api_key' => isset($auth_response['token']) ? sanitize_text_field(wp_unslash($_POST['test_api_key'])) : '',
                'test_secret_key' => isset($auth_response['token']) ? sanitize_text_field(wp_unslash($_POST['test_secret_key'])) : '',
                'test_tpn' => isset($auth_response['token']) ? sanitize_text_field(wp_unslash($_POST['test_tpn'])) : '',
                'live_api_key' => isset($auth_response['token']) ? sanitize_text_field(wp_unslash($_POST['live_api_key'])) : '',
                'live_secret_key' => isset($auth_response['token']) ? sanitize_text_field(wp_unslash($_POST['live_secret_key'])) : '',
                'live_tpn' => isset($auth_response['token']) ? sanitize_text_field(wp_unslash($_POST['live_tpn'])) : '',
                'token' => isset($auth_response['token']) ? $auth_response['token'] : '',
                'test_URL' => isset($auth_response['testURL']) ? $auth_response['testURL'] : '',
                'live_URL' => isset($auth_response['liveURL']) ? $auth_response['liveURL'] : '',
                'webhookToken' => isset($auth_response['webhookToken']) ? $auth_response['webhookToken'] : '',
            );

            // Update the options
            update_option('woocommerce_ipospays_settings', $options);
            echo '<div class="updated"><p>Settings saved.</p></div>';

            // Update the global variable after form submission
            $ipospays_payment_methods_array = json_decode($options['payment_methods'], true);
        } else {
            // Handle nonce verification failure
            wp_die('Nonce verification failed.');
        }
    }

    ?>

</head>

<body>
    <?php if ($options['enabled'] !== 'yes'): ?>
        <div class="notice notice-error">
            <p><strong>Please Enable iPOSpays Method</strong></p>
        </div>
    <?php elseif ($ipospays_payment_methods_array['credit_card']['enabled'] !== 'yes'): ?>
        <div class="notice notice-error">
            <p><strong>Please Enable Payment Method</strong></p>
        </div>
    <?php elseif ($options['token'] === ''): ?>
        <div class="notice notice-error">
            <p><strong>401 Unauthorized! Please Test The Connection And Save It</strong></p>
        </div>
    <?php elseif ($options['embedded_payment'] !== 'yes' && $options['redirect_payment'] !== 'yes'): ?>
        <div class="notice notice-error">
            <p><strong>Enable Embedded Payment OR Enable Redirect Page Payment</strong></p>
        </div>
    <?php endif; ?>

    <?php if ($options['test_mode'] !== 'yes' && $options['test_api_key'] && $options['test_secret_key'] && $options['test_tpn']): ?>
        <div class="notice notice-warning">
            <p><strong>IF using test account please enable test mode</strong></p>
        </div>
    <?php endif; ?>





    <form method="post" action="">
        <?php wp_nonce_field('save_ipospays_settings', 'woocommerce_ipospay_nonce'); ?>
        <div style="width: 200px;height: 50px;margin-bottom: 20px;display: flex;align-items: center;gap: 5px;">
            <img class="w-100 h-100" src="<?php echo esc_url(plugins_url('../../assets/images/iPOSpays.png', __FILE__)); ?>"
                alt="" srcset="">
            <small class="dvpay-color" style="font-size: 18px; font-weight: bolder;">
                <a class="dvpay-color"
                    href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')); ?>">
                    <?php esc_html_e( '⤴︎', 'ipospays-gateways-wc' ); ?>
                </a>
            </small>

        </div>

        <div class="container" id="bodyContent">

            <div class="tabs">
                <button type="button" data-tab-value="#tab_1" role="tab"
                    class="components-button components-tab-panel__tabs-item is-active" tabindex="0">Payment
                    Methods</button>
                <button type="button" data-tab-value="#tab_2" role="tab"
                    class="components-button components-tab-panel__tabs-item">Settings</button>
            </div>

            <div class="tab-content">

                <div class="tabs__tab active" id="tab_1" data-tab-info>
                    <div class="card-container">
                        <div class="card-left">
                            <h2>Payments Accepted at Checkout</h2>
                            <p class="grey">Select the payment methods to make available to customers.</p>
                        </div>
                        <div class="box-container">
                            <div class="box border-bottom">
                                <h4>Payment methods</h4>
                                <!-- <div class="flex-center">
                                    <div class="dvpay-tertiary-btn dvpay-color" style="cursor: pointer; padding: 10px;"
                                        id="toggle-handles">Change display
                                        order</div>

                                    <div class="dropdown">
                                        <button type="button"
                                            class="components-button components-dropdown-menu__toggle has-icon">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                height="24" aria-hidden="true" focusable="false">
                                                <path d="M13 19h-2v-2h2v2zm0-6h-2v-2h2v2zm0-6h-2V5h2v2z"></path>
                                            </svg>
                                        </button>
                                        <div class="dropdown-content">
                                            <a href="#">Refrest Payment Methods</a>
                                        </div>
                                    </div>


                                </div> -->
                            </div>

                            <div class="box">

                                <ul id="sortable-list">

                                    <li>
                                        <span class="handle">☰</span>
                                        <div class="components-checkbox-control">
                                            <span class="components-checkbox-control__input-container">
                                                <input class="components-checkbox-control__input"
                                                    name="payment_methods[credit_card][enabled]" type="checkbox"
                                                    value="yes" <?php checked($ipospays_payment_methods_array['credit_card']['enabled'], 'yes'); ?>>
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                    height="24" role="presentation"
                                                    class="components-checkbox-control__checked" aria-hidden="true"
                                                    focusable="false">
                                                    <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                                                </svg>
                                            </span>
                                        </div>

                                        <div class="flex-between w-100">
                                            <div class="payment-method-container">
                                                <div class="payment-method-image">
                                                    <div style="display: flex;align-items: center; gap: 4px;">
                                                        <img style="width: 30px;"
                                                            src="<?php echo esc_url(plugins_url('../../assets/images/visa.svg', __FILE__)); ?>"
                                                            alt="Visa">
                                                        <img style="width: 30px;"
                                                            src="<?php echo esc_url(plugins_url('../../assets/images/amex.svg', __FILE__)); ?>"
                                                            alt="Amex">
                                                    </div>
                                                    <div style="display: flex;align-items: center; gap: 4px;">
                                                        <img style="width: 30px;"
                                                            src="<?php echo esc_url(plugins_url('../../assets/images/mastercard.svg', __FILE__)); ?>"
                                                            alt="Mastercard">
                                                        <img style="width: 30px;"
                                                            src="<?php echo esc_url(plugins_url('../../assets/images/discover.svg', __FILE__)); ?>"
                                                            alt="Discover">
                                                    </div>
                                                </div>
                                                <div class="payment-method-title">
                                                    <h2 style="margin-bottom: 6px;">
                                                        <?php echo esc_html($ipospays_payment_methods_array['credit_card']['name']); ?>
                                                    </h2>
                                                    <p class="grey">
                                                        <?php echo esc_html($ipospays_payment_methods_array['credit_card']['description']); ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <div>
                                                <button type="button"
                                                    class="components-button dvpay-secondary-btn customize-button"
                                                    data-target="customize-section-1">Customize</button>
                                            </div>
                                        </div>
                                    </li>

                                    <div id="customize-section-1" class="customize-section" style="display: none;">
                                        <div class="components-base-control">
                                            <div class="components-base-control__field">
                                                <label class="components-base-control__label"
                                                    for="inspector-text-control-18"><b>Name</b></label>
                                                <input class="components-text-control__input"
                                                    name="payment_methods[credit_card][name]" type="text"
                                                    value="<?php echo esc_attr($ipospays_payment_methods_array['credit_card']['name']); ?>" />
                                            </div>
                                            <p id="inspector-text-control-18__help"
                                                class="components-base-control__help grey">Enter a name which customers
                                                will
                                                see during checkout.</p>
                                        </div>
                                        <div class="components-base-control">
                                            <div class="components-base-control__field">
                                                <label class="components-base-control__label"
                                                    for="inspector-text-control-19">Description</label>
                                                <input class="components-text-control__input"
                                                    name="payment_methods[credit_card][description]" type="text"
                                                    value="<?php echo esc_attr($ipospays_payment_methods_array['credit_card']['description']); ?>" />
                                            </div>
                                            <p id="inspector-text-control-19__help"
                                                class="components-base-control__help grey">Describe how customers should
                                                use
                                                this payment method during checkout.</p>
                                        </div>
                                        <div style="display: flex;justify-content: flex-end;gap: 6px;">
                                            <button type="button"
                                                class="components-button dvpay-tertiary-btn dvpay-color cancel-button"
                                                data-target="customize-section-1">Cancel</button>
                                            <?php
                                            submit_button(
                                                'Save Settings',
                                                'components-button dvpay-secondary-btn', // Remove default class
                                                'woocommerce_ipospays_settings',
                                                true
                                            );
                                            ?>
                                        </div>
                                        <br>
                                    </div>

                                    <!-- <li>
                                        <span class="handle">☰</span>
                                        <div class="components-checkbox-control">
                                            <span class="components-checkbox-control__input-container">
                                                <input class="components-checkbox-control__input"
                                                    name="payment_methods[ach][enabled]" type="checkbox" value="yes"
                                                    <?php checked($ipospays_payment_methods_array['ach']['enabled'], 'yes'); ?>>
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                    height="24" role="presentation"
                                                    class="components-checkbox-control__checked" aria-hidden="true"
                                                    focusable="false">
                                                    <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                                                </svg>
                                            </span>
                                        </div>

                                        <div class="flex-between w-100">
                                            <div class="payment-method-container">
                                                <div class="payment-method-image">
                                                    <div>
                                                        <img class="w-100 h-100"
                                                            src="<?php echo esc_url(plugins_url('../../assets/images/ach.png', __FILE__)); ?>"
                                                            alt="ACH">
                                                    </div>
                                                </div>

                                                <div class="payment-method-title">
                                                    <h2 style="margin-bottom: 6px;">
                                                        <?php echo esc_html($ipospays_payment_methods_array['ach']['name']); ?>
                                                    </h2>
                                                    <p class="grey">
                                                        <?php echo esc_html($ipospays_payment_methods_array['ach']['description']); ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <div>
                                                <button type="button"
                                                    class="components-button dvpay-secondary-btn customize-button"
                                                    data-target="customize-section-2">Customize</button>
                                            </div>
                                        </div>
                                    </li> -->

                                    <!-- <div id="customize-section-2" class="customize-section" style="display: none;">
                                        <div class="components-base-control">
                                            <div class="components-base-control__field">
                                                <label class="components-base-control__label"
                                                    for="inspector-text-control-20">Name</label>
                                                <input class="components-text-control__input"
                                                    name="payment_methods[ach][name]" type="text"
                                                    value="<?php echo esc_attr($ipospays_payment_methods_array['ach']['name']); ?>" />
                                            </div>
                                            <p id="inspector-text-control-20__help"
                                                class="components-base-control__help grey">Enter a name which customers
                                                will
                                                see during checkout.</p>
                                        </div>
                                        <div class="components-base-control">
                                            <div class="components-base-control__field">
                                                <label class="components-base-control__label"
                                                    for="inspector-text-control-21">Description</label>
                                                <input class="components-text-control__input"
                                                    name="payment_methods[ach][description]" type="text"
                                                    value="<?php echo esc_attr($ipospays_payment_methods_array['ach']['description']); ?>" />

                                            </div>
                                            <p id="inspector-text-control-21__help"
                                                class="components-base-control__help grey">Describe how customers should
                                                use
                                                this payment method during checkout.</p>
                                        </div>
                                        <div style="display: flex;justify-content: flex-end;gap: 6px;">
                                            <button type="button"
                                                class="components-button dvpay-tertiary-btn dvpay-color cancel-button"
                                                data-target="customize-section-2">Cancel</button>
                                            <?php
                                            submit_button(
                                                'Save Settings',
                                                'components-button dvpay-secondary-btn', // Remove default class
                                                'woocommerce_ipospays_settings',
                                                true
                                            );
                                            ?>
                                        </div>
                                        <br>
                                    </div> -->

                                </ul>

                            </div>
                        </div>
                    </div>

                    <!-- <div class="card-container">
                        <div class="card-left">
                            <h2>Express checkouts</h2>
                            <p class="grey">Allow your customers to use their favorite express payment methods and
                                digital
                                wallets for faster, more secure checkout.</p>
                            <a class="components-external-link"
                                href="https://woocommerce.com/document/stripe/customer-experience/express-checkouts/"
                                target="_blank" rel="external noreferrer noopener"><span
                                    class="components-external-link__contents">Learn more</span><span
                                    class="components-external-link__icon"
                                    aria-label="(opens in a new tab)">↗</span></a>
                        </div>
                        <div class="box-container">
                            <div class="box">
                                <ul>
                                    <li>
                                        <div class="components-checkbox-control">
                                            <span class="components-checkbox-control__input-container">
                                                <input class="components-checkbox-control__input" type="checkbox"
                                                    name="express_checkout" value="yes" <?php checked($options['express_checkout'], 'yes'); ?> />
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                    height="24" role="presentation"
                                                    class="components-checkbox-control__checked" aria-hidden="true"
                                                    focusable="false">
                                                    <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                                                </svg>
                                            </span>
                                        </div>

                                        <div class="payment-method-container">
                                            <div class="payment-method-image">
                                                <div style="box-shadow: 0 0 0 1px #ddd;border-radius: 4px;">
                                                    <img src="<?php echo esc_url(plugins_url('../../assets/images/apple_gpay.svg', __FILE__)); ?>"
                                                        alt="Visa">
                                                </div>
                                            </div>

                                            <div class="payment-method-title">
                                                <h2 style="margin-bottom: 6px;">Apple Pay / Google Pay</h2>

                                                <p style="margin: 0;" class="grey">Boost sales by offering a fast,
                                                    simple,
                                                    and secure checkout experience.By enabling this feature, you agree
                                                    to <a target="_blank" rel="noreferrer"
                                                        href="https://dejavoo.io/terms-conditions/">iPOSpays</a>, <a
                                                        target="_blank" rel="noreferrer"
                                                        href="https://developer.apple.com/apple-pay/acceptable-use-guidelines-for-websites/">Apple</a>,
                                                    and <a target="_blank" rel="noreferrer"
                                                        href="https://androidpay.developers.google.com/terms/sellertos">Google</a>'s
                                                    terms of use.</p>
                                            </div>
                                        </div>

                                        <div>
                                            <a
                                                href="">Customize</a>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div> -->
                </div>

                <div class="tabs__tab" id="tab_2" data-tab-info>
                    <div class="card-container">
                        <div class="card-left">
                            <h2>General</h2>
                            <p>Enable or disable iPOSpays on your store, enter activation keys, and turn on test mode to
                                simulate transactions.</p>
                            <a class="components-external-link"
                                href="https://docs.ipospays.com/iPOSpays-WooCommerce-Extension" target="_blank"
                                rel="external noreferrer noopener"><span class="components-external-link__contents">View
                                    iPOSpays plugin docs</span><span class="components-external-link__icon"
                                    aria-label="(opens in a new tab)">↗</span></a><br>
                            <br><a class="components-external-link" href="https://dejavoo.io/support" target="_blank"
                                rel="external noreferrer noopener"><span class="components-external-link__contents">Get
                                    support</span><span class="components-external-link__icon"
                                    aria-label="(opens in a new tab)">↗</span></a>

                        </div>
                        <div class="box-container">
                            <div class="box">
                                <ul>
                                    <li style="display: block;">
                                        <div class="components-checkbox-control">
                                            <span class="components-checkbox-control__input-container">

                                                <input name="enabled" value="yes" id="enabled"
                                                    class="components-checkbox-control__input" type="checkbox" <?php checked($options['enabled'], 'yes'); ?> />
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                    height="24" role="presentation"
                                                    class="components-checkbox-control__checked" aria-hidden="true"
                                                    focusable="false">
                                                    <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                                                </svg>
                                            </span>
                                            <label class="components-checkbox-control__label"
                                                for="inspector-checkbox-control-233">Enable iPOSpays
                                                <br>
                                                <p style="margin-top: 8px;" class="grey"><span
                                                        class="components-checkbox-control__help">When enabled, payment
                                                        methods powered by iPOSpays will appear on checkout.</span></p>

                                            </label>
                                        </div>
                                    </li>
                                    <li style="display: block;">
                                        <h4>Test mode</h4>
                                        <div class="components-checkbox-control">
                                            <span class="components-checkbox-control__input-container">
                                                <input class="components-checkbox-control__input" type="checkbox"
                                                    name="test_mode" value="yes" <?php checked($options['test_mode'], 'yes'); ?> />
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                    height="24" role="presentation"
                                                    class="components-checkbox-control__checked" aria-hidden="true"
                                                    focusable="false">
                                                    <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                                                </svg>
                                            </span>
                                            <label class="components-checkbox-control__label"
                                                for="inspector-checkbox-control-233">Enable Test Mode
                                                <br>
                                                <p style="margin-top: 8px;" class="grey"><span
                                                        class="components-checkbox-control__help">All transactions are
                                                        simulated for testing purposes only; no real purchases can be
                                                        made in this mode.</span></p>

                                            </label>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="card-container">
                        <div class="card-left">
                            <h2>Account details</h2>
                            <p>View account overview and edit business details.</p>
                        </div>
                        <div class="box-container">
                            <div class="box border-bottom">
                                <div style="display: flex;align-items: center;gap:10px;">
                                    <h4>Account status</h4>
                                    <?php
                                    // Determine the background color based on the test_mode option
                                    $background_color = $options['test_mode'] === 'yes' ? 'orange' : 'limegreen';

                                    // Check if all test credentials are available and test mode is enabled
                                    if ($options['test_mode'] === 'yes' && $options['test_api_key'] && $options['test_secret_key'] && $options['test_tpn']) {
                                        ?>
                                        <div
                                            style="border: 1px solid grey; padding: 5px 10px; border-radius: 50px; display: flex; align-items: center; gap: 4px;">
                                            <div
                                                style="height: 10px; width: 10px; border-radius: 50%; background-color: <?php echo esc_attr($background_color); ?>;">
                                            </div>
                                            Test Mode
                                        </div>
                                        <?php
                                        // Check if all live credentials are available and live mode is enabled
                                    } elseif ($options['test_mode'] !== 'yes' && $options['live_api_key'] && $options['live_secret_key'] && $options['live_tpn']) {
                                        ?>
                                        <div
                                            style="border: 1px solid grey; padding: 5px 10px; border-radius: 50px; display: flex; align-items: center; gap: 4px;">
                                            <div
                                                style="height: 10px; width: 10px; border-radius: 50%; background-color: <?php echo esc_attr($background_color); ?>;">
                                            </div>
                                            Live Mode
                                        </div>
                                        <?php
                                    }
                                    ?>

                                </div>

                                <div style="display: flex;align-items: center;">
                                    <?php if ($options['test_mode'] === 'yes' && $options['test_api_key'] && $options['test_secret_key'] && $options['test_tpn']) { ?>
                                        <span>
                                            <p class="grey">
                                                TPN:
                                                <?php echo esc_attr($options['test_mode'] === 'yes' ? $options['test_tpn'] : $options['live_tpn']); ?>
                                            </p>
                                        </span>
                                    <?php } elseif ($options['test_mode'] !== 'yes' && $options['live_api_key'] && $options['live_secret_key'] && $options['live_tpn']) { ?>
                                        <span>
                                            <p class="grey">
                                                TPN:
                                                <?php echo esc_attr($options['test_mode'] === 'yes' ? $options['test_tpn'] : $options['live_tpn']); ?>
                                            </p>
                                        </span>
                                        <?php
                                    }
                                    ?>
                                    <!-- <div class="dropdown">
                                        <button type="button"
                                            class="components-button components-dropdown-menu__toggle has-icon">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                height="24" aria-hidden="true" focusable="false">
                                                <path d="M13 19h-2v-2h2v2zm0-6h-2v-2h2v2zm0-6h-2V5h2v2z"></path>
                                            </svg>
                                        </button>
                                        <div class="dropdown-content">
                                            <a href="#" id="edit-keys-button">Edit account keys</a>
                                            <a href="#">Disconnect</a>
                                        </div>
                                    </div> -->

                                </div>
                            </div>
                            <div class="box border-bottom">

                                <ul>
                                    <li style="justify-content: flex-start; gap: 100px;">
                                        <div>
                                            <h4>Payment

                                                <?php if ($options['test_mode'] === 'yes' && $options['test_api_key'] && $options['test_secret_key'] && $options['test_tpn']) { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(232, 252, 232);">Enabled</span>
                                                <?php } elseif ($options['test_mode'] !== 'yes' && $options['live_api_key'] && $options['live_secret_key'] && $options['live_tpn']) { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(232, 252, 232);">Enabled</span>
                                                <?php } else { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(252, 249, 232);">Disabled</span>
                                                <?php } ?>

                                            </h4>
                                        </div>
                                        <div>
                                            <h4>Payout
                                                <?php if ($options['test_mode'] === 'yes' && $options['test_api_key'] && $options['test_secret_key'] && $options['test_tpn']) { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(232, 252, 232);">Enabled</span>
                                                <?php } elseif ($options['test_mode'] !== 'yes' && $options['live_api_key'] && $options['live_secret_key'] && $options['live_tpn']) { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(232, 252, 232);">Enabled</span>
                                                <?php } else { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(252, 249, 232);">Disabled</span>
                                                <?php } ?>
                                            </h4>
                                        </div>
                                        <!-- <div>
                                            <h4>Webhook 
                                                <?php if ($options['redirect_payment'] === 'yes') { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(232, 252, 232);">Enabled</span>
                                                <?php } else { ?>
                                                    <span class="grey"
                                                        style="padding: 5px; background-color: rgb(252, 249, 232);">Disabled</span>
                                                <?php } ?>
                                            </h4>
                                        </div> -->
                                    </li>
                                    <!-- <li class="grey" style="display: block;">
                                        <p data-testid="webhook-information">Add the following webhook endpoint
                                            <?php
                                            $store_url = home_url();
                                            $site_url = site_url();
                                            ?>
                                            <strong
                                                style="background: rgba(156,26,255,.1);"><?php echo esc_attr($site_url) ?>/wp-json/ipospays/v1/receive-callback</strong>
                                            to your <a class="components-external-link"
                                                href="https://dashboard.IPOSPay.com/account/webhooks" target="_blank"
                                                rel="external noreferrer noopener"><span
                                                    class="components-external-link__contents">iPOSpays account
                                                    settings</span><span class="components-external-link__icon"
                                                    aria-label="(opens in a new tab)">↗</span></a> (if there isn't one
                                            already). This will enable you to receive notifications on the charge
                                            statuses.
                                        </p>
                                    </li> -->
                                </ul>
                            </div>
                            <div class="box">
                                <ul>
                                    <li style="display: block;">
                                        <button type="button" class="components-button dvpay-secondary-btn"
                                            id="edit-keys-button">Edit account
                                            keys</button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="card-container">
                        <div class="card-left">
                            <h2>Payments & transactions</h2>
                            <p>Configure optional payment settings and transaction details.</p>
                        </div>
                        <div class="box-container">
                            <div class="box">
                                <ul>
                                    <li style="display: block;">
                                        <div class="components-checkbox-control">
                                            <span class="components-checkbox-control__input-container">
                                                <input class="components-checkbox-control__input" type="checkbox" style="border-radius: 50%;"
                                                    name="embedded_payment" value="yes" <?php checked($options['embedded_payment'], 'yes'); ?> />
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                    height="24" role="presentation"
                                                    class="components-checkbox-control__checked" aria-hidden="true"
                                                    focusable="false">
                                                    <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                                                </svg>
                                            </span>
                                            <label class="components-checkbox-control__label"
                                                for="inspector-checkbox-control-233">Enable Emebbed Payment
                                                <br>
                                                <p style="margin-top: 8px;" class="grey"><span
                                                        class="components-checkbox-control__help">If selected, the
                                                        checkout
                                                        page will be embedded within your WooCommerce page.</span></p>

                                            </label>
                                        </div>
                                    </li>
                                    <li style="display: block;">
                                        <div class="components-checkbox-control">
                                            <span class="components-checkbox-control__input-container">
                                                <input class="components-checkbox-control__input" type="checkbox" style="border-radius: 50%;"
                                                    name="redirect_payment" value="yes" <?php checked($options['redirect_payment'], 'yes'); ?> />
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24"
                                                    height="24" role="presentation"
                                                    class="components-checkbox-control__checked" aria-hidden="true"
                                                    focusable="false">
                                                    <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                                                </svg>
                                            </span>
                                            <label class="components-checkbox-control__label"
                                                for="inspector-checkbox-control-233">Enable Redirect Page Payment
                                                <br>
                                                <p style="margin-top: 8px;" class="grey"><span
                                                        class="components-checkbox-control__help">If selected, the
                                                        checkout page will be redirected to a new page.</span></p>

                                            </label>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="float:right">
                <?php
                submit_button(
                    'Save Settings',
                    'components-button dvpay-primary-btn', // Remove default class
                    'woocommerce_ipospays_settings',
                    true
                );
                ?>
            </div>
        </div>

        <div id="lightbox" class="lightbox">

            <div class="lightbox-content">
                <div class="flex-between p-20">
                    <div>
                        <h1>Edit account keys</h1>
                    </div>
                    <div class="close-lightbox" id="close-lightbox" style="cursor: pointer;"><svg
                            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"
                            aria-hidden="true" focusable="false">
                            <path d="M13 11.8l6.1-6.3-1-1-6.1 6.2-6.1-6.2-1 1 6.1 6.3-6.5 6.7 1 1 6.5-6.6 6.5 6.6 1-1z">
                            </path>
                        </svg></div>
                </div>

                <div class="components-notice is-info"
                    style="background: rgba(156,26,255,.04); border-left: 4px solid #9a17ff">
                    <div>
                        To enable the test mode, get the test account keys from your
                        <a href="https://dashboard.ipospays.com/account/webhooks">iPOSpays Account</a>.
                    </div>
                </div>
                <div>

                    <div class="tabs" style="background: rgb(241, 241, 241); border: none;">
                        <button type="button" data-tab-value="#live_tab" role="tab"
                            class="components-button components-tab-panel__tabs-item is-active"
                            tabindex="0">Live</button>
                        <button type="button" data-tab-value="#test_tab" role="tab"
                            class="components-button components-tab-panel__tabs-item">Test</button>
                    </div>

                    <div class="tabs__tab active" id="live_tab" data-tab-info>
                        <div id="customize-section">
                            <div class="p-20">
                                <div class="components-base-control">
                                    <div class="components-base-control__field">
                                        <label class="components-base-control__label" for="live-api-key"><strong>LIVE
                                                API
                                                KEY</strong></label>
                                        <input style="margin-top: 6px;" class="components-text-control__input"
                                            type="text" id="live-api-key" name="live_api_key"
                                            value="<?php echo esc_attr($options['live_api_key']); ?>" />
                                    </div>
                                    <p class="grey">Enter Your Live Api Key</p>
                                </div>
                                <div class="components-base-control">
                                    <div class="components-base-control__field">
                                        <label class="components-base-control__label" for="live-secret-key"><strong>LIVE
                                                SECRET KEY</strong></label>
                                        <input style="margin-top: 6px;" class="components-text-control__input"
                                            type="text" id="live-secret-key" name="live_secret_key"
                                            value="<?php echo esc_attr($options['live_secret_key']); ?>" />
                                    </div>
                                    <p class="grey">Enter Your Live Secret Key</p>
                                </div>
                                <div class="components-base-control">
                                    <div class="components-base-control__field">
                                        <label class="components-base-control__label" for="live-tpn"><strong>LIVE
                                                TPN</strong></label>
                                        <input style="margin-top: 6px;" class="components-text-control__input"
                                            type="text" id="live-tpn" name="live_tpn"
                                            value="<?php echo esc_attr($options['live_tpn']); ?>" />
                                    </div>
                                    <p class="grey">Enter Your Live TPN Number</p>
                                </div>
                            </div>
                            <div class="border-bottom"></div>
                            <div class="flex-between p-20">
                                <div class="dvpay-color test-connection" id="live-test-connection"
                                    style="cursor: pointer;">Test connection</div>
                                <div class="flex-center">
                                    <button type="button" style="margin-right: 10px;"
                                        class="components-button dvpay-secondary-btn close-lightbox">Cancel</button>
                                    <?php
                                    submit_button(
                                        'Save Settings',
                                        'components-button dvpay-primary-btn', // Remove default class
                                        'woocommerce_ipospays_settings',
                                        true
                                    );
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tabs__tab" id="test_tab" data-tab-info>
                        <div id="customize-section">
                            <div class="p-20">
                                <div class="components-base-control">
                                    <div class="components-base-control__field">
                                        <label class="components-base-control__label" for="test-api-key"><strong>TEST
                                                API
                                                KEY</strong></label>
                                        <input class="components-text-control__input" type="text"
                                            style="margin-top: 6px;" id="test-api-key" name="test_api_key"
                                            value="<?php echo esc_attr($options['test_api_key']); ?>" />
                                    </div>
                                    <p class="grey">Enter Your Test Api Key</p>
                                </div>
                                <div class="components-base-control">
                                    <div class="components-base-control__field">
                                        <label class="components-base-control__label" for="test-secret-key"><strong>TEST
                                                SECRET KEY</strong></label>
                                        <input style="margin-top: 6px;" class="components-text-control__input"
                                            type="text" id="test-secret-key" name="test_secret_key"
                                            value="<?php echo esc_attr($options['test_secret_key']); ?>" />
                                    </div>
                                    <p class="grey">Enter Your Test Secret Key</p>
                                </div>
                                <div class="components-base-control">
                                    <div class="components-base-control__field">
                                        <label class="components-base-control__label" for="test-tpn"><strong>TEST
                                                TPN</strong></label>
                                        <input style="margin-top: 6px;" class="components-text-control__input"
                                            type="text" id="test-tpn" name="test_tpn"
                                            value="<?php echo esc_attr($options['test_tpn']); ?>" />
                                    </div>
                                    <p class="grey">Enter Your Test TPN Number</p>
                                </div>
                            </div>
                            <div class="border-bottom"></div>
                            <div class="flex-between p-20">
                                <div class="dvpay-color test-connection" id="test-test-connection"
                                    style="cursor: pointer;">Test connection</div>
                                <div class="flex-center">
                                    <button type="button" style="margin-right: 10px;"
                                        class="components-button dvpay-secondary-btn close-lightbox">Cancel</button>
                                    <?php
                                    submit_button(
                                        'Save Settings',
                                        'components-button dvpay-primary-btn', // Remove default class
                                        'woocommerce_ipospays_settings',
                                        true
                                    );
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </form>
</body>

</html>