<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Therius with the WooCommerce Cart/Checkout blocks (Store API).
 * Server-side counterpart to assets/js/therius-checkout-blocks.js. The
 * classic WC_Gateway_Therius (class-wc-gateway-therius.php) is untouched and
 * keeps serving the legacy/shortcode checkout — this is purely additive, per
 * WooCommerce's documented "keep both, WooCommerce dispatches by which
 * checkout is actually rendering" pattern.
 *
 * All the actual purchase/finalize logic lives on WC_Gateway_Therius
 * (ajax_blocks_attempt(), ajax_blocks_finalize(), get_blocks_payment_method_data())
 * — this class only wires that gateway instance into the Blocks registration
 * contract (AbstractPaymentMethodType).
 */
class WC_Therius_Blocks_Support extends AbstractPaymentMethodType {

    /** @var WC_Gateway_Therius */
    private $gateway;

    protected $name = 'therius';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_therius_settings', array() );
        $gateways       = WC()->payment_gateways()->payment_gateways();
        $this->gateway  = isset( $gateways['therius'] ) ? $gateways['therius'] : new WC_Gateway_Therius();
    }

    public function is_active() {
        return $this->gateway && 'yes' === $this->gateway->enabled;
    }

    public function get_payment_method_script_handles() {
        $api_url = $this->gateway->testmode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        // May already be registered by the classic gateway's payment_scripts()
        // (wp_enqueue_scripts fires on the same checkout/cart page load
        // regardless of shortcode vs. block checkout) — guard against a
        // duplicate-registration notice rather than assuming hook order.
        if ( ! wp_script_is( 'therius-sdk', 'registered' ) && ! wp_script_is( 'therius-sdk', 'enqueued' ) ) {
            wp_register_script( 'therius-sdk', $api_url . '/v1/sdk/js', array(), '1.0.0', true );
        }

        wp_register_script(
            'therius-blocks-integration',
            plugins_url( 'assets/js/therius-checkout-blocks.js', dirname( __FILE__ ) ),
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'therius-sdk' ),
            '1.0.0',
            true
        );

        return array( 'therius-blocks-integration' );
    }

    public function get_payment_method_data() {
        return $this->gateway->get_blocks_payment_method_data();
    }
}
