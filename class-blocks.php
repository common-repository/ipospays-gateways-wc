<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class iPOSpays_Gateway_iPOSpays_Blocks extends AbstractPaymentMethodType
{
    private $gateway;
    protected $name = 'ipospays'; // your payment gateway name

    private $ipospays_api;

    public function initialize()
    {
        $this->settings = get_option('woocommerce_ipospays_settings', []);
        $this->gateway = new iPOSpays_Gateway();
        $this->ipospays_api = new iPOSpays_API();
    }

    public function is_active()
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        // Define version number as a constant or hardcoded value
        $version = '1.1.3'; // You can change this version number as needed

        wp_register_script(
            'ipospays-blocks-integration',
            plugin_dir_url(__FILE__) . 'checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
                'wp-components',
            ],
            $version, // Set version number
            true
        );

        wp_register_script(
            'google-pay-script',
            'https://pay.google.com/gp/p/js/pay.js',
            [],
            $version, // Set version number
            true
        );

        wp_register_style(
            'ipospays-blocks-style',
            plugin_dir_url(__FILE__) . 'style.css',
            [],
            $version // Set version number
        );

        wp_enqueue_style('ipospays-blocks-style');
        wp_enqueue_script('google-pay-script'); // Enqueue Google Pay script

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('ipospays-blocks-integration');
        }

        // Localize the script
        wp_localize_script('ipospays-blocks-integration', 'iPOSpays_VARS', [
            'pluginUrl' => iPOSpays_PLUGIN_URL,
            'siteUrl' => site_url()
        ]);

        return ['ipospays-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        $options = get_option('woocommerce_ipospays_settings', []);
        $mode = isset($options['test_mode']) && $options['test_mode'] === 'yes' ? 'test' : 'live';
        $keys = ['secret_key', 'api_key', 'tpn'];

        // Initialize credentials and check for missing keys
        $credentials = [];
        foreach ($keys as $key) {
            $credentials[$key] = isset($options["{$mode}_{$key}"]) ? sanitize_text_field($options["{$mode}_{$key}"]) : ''; // Default to empty string if not set
        }

        // Check if any of the credentials are empty
        if (empty($credentials['secret_key']) || empty($credentials['api_key']) || empty($credentials['tpn'])) {
            $logger = wc_get_logger();
            $logger->error('Missing credentials for authentication', ['source' => 'iPOSpays']);
            return [
                'error' => 'Missing credentials for payment processing. Please check your configuration.'
            ];
        }
        
        // Parse payment methods array
        $ipospays_payment_methods_array = json_decode($options['payment_methods'], true);

        // Ensure we check if the array keys exist to avoid undefined index notices
        return [
            'title' => isset($ipospays_payment_methods_array['credit_card']['name']) ? $ipospays_payment_methods_array['credit_card']['name'] : '',
            'description' => isset($ipospays_payment_methods_array['credit_card']['description']) ? $ipospays_payment_methods_array['credit_card']['description'] : '',
            'content' => 'Card',
            'embedded' => isset($options['embedded_payment']) ? $options['embedded_payment'] : 'no',
            'redirect' => isset($options['redirect_payment']) ? $options['redirect_payment'] : 'no',
            'test_mode' => isset($options['test_mode']) ? $options['test_mode'] : 'no',
            'token' => isset($options['token']) ? $options['token'] : '',
            'enabled' => isset($ipospays_payment_methods_array['credit_card']['enabled']) ? $ipospays_payment_methods_array['credit_card']['enabled'] : 'no',
        ];
    }
}