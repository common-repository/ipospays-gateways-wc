<?php
/**
 * Plugin Name: iPOSpays Gateways WC
 * Plugin URI: https://docs.ipospays.com/iPOSpays-WooCommerce-Extension
 * Description: Accept credit, debit, and alternative payments on your store using iPOSpays.
 * Author: Dejavoo
 * Author URI: https://dejavoo.io
 * Version: 1.1.3
 * Requires Plugins: woocommerce
 * Requires at least: 6.4
 * Tested up to: 6.6
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 **/

if (!defined('ABSPATH')) {
    exit("You must not have access this file directly");
}


if (!defined("iPOSpays_PLUGIN_DIR_PATH"))
    define("iPOSpays_PLUGIN_DIR_PATH", plugin_dir_path(__FILE__));
if (!defined("iPOSpays_PLUGIN_URL"))
    define("iPOSpays_PLUGIN_URL", plugin_dir_url(__FILE__));


// Add custom meta links (Docs, Support)
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file == plugin_basename(__FILE__)) {
        $docs_link = '<a href="' . esc_url('https://docs.ipospays.com/iPOSpays-WooCommerce-Extension') . '" target="_blank">' . esc_html__('Docs', 'ipospays-gateways-wc') . '</a>';
        $support_link = '<a href="' . esc_url('https://dejavoo.io/support/') . '" target="_blank">' . esc_html__('Support', 'ipospays-gateways-wc') . '</a>';
        $links[] = $docs_link;
        $links[] = $support_link;
    }
    return $links;
}, 10, 2);


function ipospays_enqueue_custom_scripts()
{
    // Enqueue jQuery in the footer with a version number
    // wp_enqueue_script('cardJs', esc_url(iPOSpays_PLUGIN_URL . '/js/card-js.min.js'), array(), '1.1.3', true);
    flush_rewrite_rules(); // Consider moving this to activation/deactivation hooks
}
add_action('wp_enqueue_scripts', 'ipospays_enqueue_custom_scripts');


function ipospays_setting_enqueue_custom_css_admin($hook)
{
    // Check if we're on the WooCommerce settings page
    if ($hook === 'woocommerce_page_wc-settings') {
        // Further check for query parameters
        if (
            isset($_GET['tab']) && sanitize_text_field(wp_unslash($_GET['tab'])) === 'checkout'
            && isset($_GET['section']) && sanitize_text_field(wp_unslash($_GET['section'])) === 'ipospays'
        ) {
            // Verify nonce
            if (isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'my_plugin_nonce_action')) {
                // Enqueue the CSS file with a version number
                wp_enqueue_style('settingsCss', esc_url(iPOSpays_PLUGIN_URL . '/assets/css/setting.css'), array(), '1.1.3');
                // Enqueue the JavaScript file in the footer with a version number
                wp_enqueue_script('settingsJs', esc_url(iPOSpays_PLUGIN_URL . '/assets/js/setting.js'), array(), '1.1.3', true);
            }
        }
    }
}
add_action('admin_enqueue_scripts', 'ipospays_setting_enqueue_custom_css_admin');

// Function to add nonce to admin page URL
function ipospays_add_nonce_to_admin_url($url)
{
    if (strpos($url, 'wc-settings') !== false) {
        $url = add_query_arg('_wpnonce', wp_create_nonce('my_plugin_nonce_action'), $url);
    }
    return $url;
}
add_filter('admin_url', 'ipospays_add_nonce_to_admin_url');

function ipospays_test_mode_admin_notice()
{
    // Ensure we're on the WooCommerce settings page with correct query parameters
    if (
        isset($_GET['tab']) && sanitize_text_field(wp_unslash($_GET['tab'])) === 'checkout'
        && isset($_GET['section']) && sanitize_text_field(wp_unslash($_GET['section'])) === 'ipospays'
    ) {
        // Verify nonce
        if (isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'my_plugin_nonce_action')) {
            $options = get_option('woocommerce_ipospays_settings', []);
            if (isset($options['test_mode']) && $options['test_mode'] === 'yes') {
                echo '<div class="notice notice-warning">
                       <p><strong>Test mode active: </strong>All transactions are simulated for testing purposes only; no real purchases can be made in this mode.</p>
                      </div>';
            }
        }
    }
    ;
}
add_action('admin_notices', 'ipospays_test_mode_admin_notice');

// Ensure WooCommerce is active
add_action('admin_init', function () {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('This Payment Gateway requires WooCommerce to be installed and active.', 'ipospays-gateways-wc') . '</p></div>';
        });
    }
});


// Add settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=ipospays')) . '">' . esc_html__('Settings', 'ipospays-gateways-wc') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});


add_action('plugins_loaded', 'ipospays_init_ipospays_gateway', 0);
function ipospays_init_ipospays_gateway()
{
    if (!class_exists('WC_Payment_Gateway'))
        return; // if the WC payment gateway class 

    include(iPOSpays_PLUGIN_DIR_PATH . 'includes/class-gateway.php');
}


add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'iPOSpays_Gateway';
    return $gateways;
});


/**
 * Custom function to declare compatibility with cart_checkout_blocks feature 
 */
function ipospays_declare_cart_checkout_blocks_compatibility()
{
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'ipospays_declare_cart_checkout_blocks_compatibility');


// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action('woocommerce_blocks_loaded', 'ipospays_register_order_approval_payment_method_type');
/**
 * Custom function to register a payment method type
 */
function ipospays_register_order_approval_payment_method_type()
{
    // Check if the required class exists
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    // Include the custom Blocks Checkout class
    require_once iPOSpays_PLUGIN_DIR_PATH . 'class-blocks.php';
    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            // Register an instance of iPOSpays_Gateway_iPOSpays_Blocks
            $payment_method_registry->register(new iPOSpays_Gateway_iPOSpays_Blocks);
        }
    );
}

add_action('woocommerce_admin_order_totals_after_discount', 'ipospays_add_sub_total', 10);
function ipospays_add_sub_total($order_id)
{
    $order = wc_get_order($order_id);

    // Fetching meta data
    $tips_meta_value = $order->get_meta('_tips');
    $local_tax_meta_value = $order->get_meta('_local_tax');
    $state_tax_meta_value = $order->get_meta('_state_tax');
    $custom_fee_meta_value = $order->get_meta('_custom_fee');

    // Initialize values to avoid division by zero or invalid operations
    $tips_meta_value = is_numeric($tips_meta_value) ? $tips_meta_value / 100 : 0;
    $custom_fee_meta_value = is_numeric($custom_fee_meta_value) ? $custom_fee_meta_value / 100 : 0;
    $local_tax_meta_value = is_numeric($local_tax_meta_value) ? $local_tax_meta_value / 100 : 0;
    $state_tax_meta_value = is_numeric($state_tax_meta_value) ? $state_tax_meta_value / 100 : 0;

    ?>

    <?php if ($tips_meta_value > 0): ?>
        <tr>
            <td class="label"><?php esc_html_e('Tips', 'ipospays-gateways-wc'); ?>:</td>
            <td width="1%"></td>
            <td class="total"><?php echo wp_kses_post(wc_price($tips_meta_value)); ?></td>
        </tr>
    <?php endif; ?>

    <?php if ($local_tax_meta_value > 0): ?>
        <tr>
            <td class="label"><?php esc_html_e('Local Tax', 'ipospays-gateways-wc'); ?>:</td>
            <td width="1%"></td>
            <td class="total"><?php echo wp_kses_post(wc_price($local_tax_meta_value)); ?></td>
        </tr>
    <?php endif; ?>

    <?php if ($state_tax_meta_value > 0): ?>
        <tr>
            <td class="label"><?php esc_html_e('State Tax', 'ipospays-gateways-wc'); ?>:</td>
            <td width="1%"></td>
            <td class="total"><?php echo wp_kses_post(wc_price($state_tax_meta_value)); ?></td>
        </tr>
    <?php endif; ?>

    <?php if ($custom_fee_meta_value > 0): ?>
        <tr>
            <td class="label"><?php esc_html_e('Custom Fee', 'ipospays-gateways-wc'); ?>:</td>
            <td width="1%"></td>
            <td class="total"><?php echo wp_kses_post(wc_price($custom_fee_meta_value)); ?></td>
        </tr>
    <?php endif; ?>

    <?php
}

add_action('template_redirect', function () {
    if (isset($_GET['error_message']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'checkout_error_nonce')) {
        $error_message = urldecode(sanitize_text_field(wp_unslash($_GET['error_message'])));
        wc_add_notice($error_message, 'error');
    } else if (isset($_GET['error_message'])) {
       $error_message = urldecode(sanitize_text_field(wp_unslash($_GET['error_message'])));
        wc_add_notice($error_message, 'error');
    }
});

?>