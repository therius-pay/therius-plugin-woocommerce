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
 * Add Settings link to the plugin page.
 */
function therius_gateway_plugin_links( $links ) {
    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=therius' ) . '">' . __( 'Settings', 'therius-woocommerce' ) . '</a>'
    );
    return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'therius_gateway_plugin_links' );
