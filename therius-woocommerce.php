<?php
/**
 * Plugin Name: Therius Payment Orchestration
 * Plugin URI: https://therius.io
 * Description: Therius Payment Orchestration for WooCommerce.
 * Version: 1.0.0
 * Author: Therius
 * Author URI: https://therius.io
 * Text Domain: therius-woocommerce
 * Domain Path: /i18n/languages/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package TheriusWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

/**
 * Initialize the gateway.
 */
function therius_woocommerce_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-therius.php';
}
add_action( 'plugins_loaded', 'therius_woocommerce_init', 11 );

/**
 * Add the gateway to WooCommerce.
 */
function add_therius_gateway_class( $methods ) {
    $methods[] = 'WC_Gateway_Therius';
    return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_therius_gateway_class' );

/**
 * Declare Checkout Blocks (Cart/Checkout React blocks) compatibility so
 * WooCommerce 8.3+ doesn't hide the gateway from the block checkout — the
 * classic WC_Gateway_Therius above is untouched and still serves the
 * legacy/shortcode checkout; both stay live, WooCommerce dispatches by
 * which checkout is actually rendering.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

/**
 * Register the Checkout Blocks integration (assets/js/therius-checkout-blocks.js
 * + includes/class-wc-therius-blocks-support.php). Guarded by class_exists
 * since this hook only fires when WooCommerce Blocks is actually loaded, but
 * the check is cheap insurance against an older WooCommerce version where
 * AbstractPaymentMethodType might not exist even if the action name did.
 */
add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
    if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-therius-blocks-support.php';
    $payment_method_registry->register( new WC_Therius_Blocks_Support() );
} );

/**
 * Add Settings link to the plugin page.
 */
function therius_gateway_plugin_links( $links ) {
    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=therius' ) . '">' . __( 'Settings', 'therius-woocommerce' ) . '</a>'
    );
    return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'therius_gateway_plugin_links' );
