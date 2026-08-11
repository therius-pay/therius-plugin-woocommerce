<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Therius extends WC_Payment_Gateway {

    // Currencies with no minor unit — the major amount IS the minor-unit
    // value (no ×100), exponent 0. Matches therius-sdk-example/src/lib/amount.ts.
    private static $zero_decimal_currencies = array(
        'JPY', 'KRW', 'VND', 'CLP', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'XAF', 'XOF', 'XPF',
    );

    public $testmode;
    public $private_key;
    public $publishable_key;
    public $webhook_secret;
    public $test_private_key;
    public $test_publishable_key;
    public $test_webhook_secret;
    public $checkout_config_id;
    public $test_checkout_config_id;
    public $live_checkout_config_id;
    public $merchant_code;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'therius';
        $this->icon               = apply_filters( 'woocommerce_therius_icon', '' );
        $this->has_fields         = true;
        $this->method_title       = __( 'Therius Payments', 'therius-woocommerce' );
        $this->method_description = __( 'Accept payments through Therius Payment Orchestration Platform.', 'therius-woocommerce' );
        $this->supports            = array( 'products', 'refunds' );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->testmode     = 'yes' === $this->get_option( 'testmode' );
        $this->private_key  = $this->testmode ? $this->get_option( 'test_private_key' ) : $this->get_option( 'private_key' );
        $this->publishable_key = $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'publishable_key' );
        $this->webhook_secret   = $this->testmode ? $this->get_option( 'test_webhook_secret' ) : $this->get_option( 'webhook_secret' );
        $this->checkout_config_id = $this->testmode ? $this->get_option( 'test_checkout_config_id' ) : $this->get_option( 'live_checkout_config_id' );
        $this->merchant_code    = $this->get_option( 'merchant_code' );

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

        // Webhook receiver: Therius POSTs asynchronous status updates here
        // (https://<site>/?wc-api=WC_Gateway_Therius) so order state is driven
        // by a signed, retried notification rather than only the synchronous
        // /payment/purchase response.
        add_action( 'woocommerce_api_wc_gateway_therius', array( $this, 'handle_webhook' ) );

        // Modify checkout fields based on Therius config
        add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_checkout_fields' ) );
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __( 'Enable/Disable', 'therius-woocommerce' ),
                'label'       => __( 'Enable Therius Payments', 'therius-woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __( 'Title', 'therius-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'therius-woocommerce' ),
                'default'     => __( 'Payment Details', 'therius-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'therius-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'therius-woocommerce' ),
                'default'     => '',
            ),
            'merchant_code' => array(
                'title'       => __( 'Merchant Code', 'therius-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Optional. The Merchant Code identifier for this account.', 'therius-woocommerce' ),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __( 'Test mode', 'therius-woocommerce' ),
                'label'       => __( 'Enable Test Mode', 'therius-woocommerce' ),
                'type'        => 'checkbox',
                'description' => __( 'Place the payment gateway in test mode using test API keys.', 'therius-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'test_publishable_key' => array(
                'title'       => __( 'Test Publishable Key', 'therius-woocommerce' ),
                'type'        => 'text'
            ),
            'test_private_key' => array(
                'title'       => __( 'Test Private Key', 'therius-woocommerce' ),
                'type'        => 'password',
            ),
            'test_checkout_config_id' => array(
                'title'       => __( 'Test Checkout Config ID', 'therius-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Optional. Controls which payment methods display dynamically from your Dashboard.', 'therius-woocommerce' ),
                'desc_tip'    => true,
            ),
            'publishable_key' => array(
                'title'       => __( 'Live Publishable Key', 'therius-woocommerce' ),
                'type'        => 'text'
            ),
            'private_key' => array(
                'title'       => __( 'Live Private Key', 'therius-woocommerce' ),
                'type'        => 'password'
            ),
            'live_checkout_config_id' => array(
                'title'       => __( 'Live Checkout Config ID', 'therius-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Optional. Controls which payment methods display dynamically from your Dashboard.', 'therius-woocommerce' ),
                'desc_tip'    => true,
            ),
            'webhook_settings' => array(
                'title'       => __( 'Webhooks', 'therius-woocommerce' ),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: webhook endpoint URL */
                    __( 'Add this URL as a webhook endpoint in the Therius Dashboard (Developers → Webhooks) so order status is confirmed by a signed notification rather than only the checkout response: %s', 'therius-woocommerce' ),
                    '<code>' . esc_url( WC()->api_request_url( 'WC_Gateway_Therius' ) ) . '</code>'
                ),
            ),
            'test_webhook_secret' => array(
                'title'       => __( 'Test Webhook Signing Secret', 'therius-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'The signing secret shown when you configure the webhook in Sandbox mode. Used to verify the X-Therius-Signature header.', 'therius-woocommerce' ),
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __( 'Live Webhook Signing Secret', 'therius-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'The signing secret shown when you configure the webhook in Production mode. Used to verify the X-Therius-Signature header.', 'therius-woocommerce' ),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Output for the order received page.
     */
    public function payment_fields() {
        // We will output the payment fields here. Can be a custom form or Drop-in UI.
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }

        echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

        // Add this action hook if you want your custom payment gateway to support it
        do_action( 'woocommerce_credit_card_form_start', $this->id );

        // Recommend implementing a hosted field or Drop-in UI here for PCI compliance
        echo '<div id="therius-payment-element"><!-- Therius Drop-in UI will render here --></div>';

        do_action( 'woocommerce_credit_card_form_end', $this->id );

        echo '<div class="clear"></div></fieldset>';
    }

    /**
     * Fetch the checkout configuration from Therius API
     */
    public function get_therius_checkout_config() {
        if ( 'yes' !== $this->enabled ) {
            return null;
        }

        $transient_key = 'therius_config_' . md5( $this->private_key . '_' . $this->checkout_config_id );
        $cached_config = get_transient( $transient_key );
        if ( false !== $cached_config ) {
            return $cached_config;
        }

        $api_url = $this->testmode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';
        $config_id = $this->checkout_config_id;

        if ( empty( $config_id ) ) {
            // Need to create a session to get the default config id
            $session_body = array( 
                'country' => WC()->customer && WC()->customer->get_billing_country() ? WC()->customer->get_billing_country() : WC()->countries->get_base_country(), 
                'currency' => get_woocommerce_currency() 
            );
            $response = wp_remote_post( $api_url . '/v1/sdk/session', array(
                'method'    => 'POST',
                'headers'   => array(
                    'Authorization' => 'Bearer ' . $this->private_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'      => wp_json_encode( $session_body ),
            ) );
            if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['defaultCheckoutConfigId'] ) ) {
                    $config_id = $body['defaultCheckoutConfigId'];
                }
            }
        }

        if ( empty( $config_id ) ) {
            return null;
        }

        $response = wp_remote_get( $api_url . '/v1/checkout-config/' . $config_id );
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $config = json_decode( wp_remote_retrieve_body( $response ), true );
            set_transient( $transient_key, $config, 15 * MINUTE_IN_SECONDS );
            return $config;
        }

        return null;
    }

    /**
     * Filter WooCommerce checkout fields based on Therius config
     */
    public function filter_checkout_fields( $fields ) {
        // Only apply if this gateway is actually being used or is enabled?
        // Usually checkout fields are global. We'll apply it if enabled.
        if ( 'yes' !== $this->enabled ) {
            return $fields;
        }

        $config = $this->get_therius_checkout_config();
        if ( $config && isset( $config['cardBillingAddress'] ) ) {
            $billing_req = $config['cardBillingAddress'];
            
            // Core address fields (excluding name, email, phone which are usually essential)
            $address_fields = array('billing_company', 'billing_country', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode');
            
            if ( 'optional' === $billing_req ) {
                foreach ( $address_fields as $field ) {
                    if ( isset( $fields['billing'][ $field ] ) ) {
                        $fields['billing'][ $field ]['required'] = false;
                    }
                }
            } elseif ( 'none' === $billing_req ) {
                foreach ( $address_fields as $field ) {
                    if ( isset( $fields['billing'][ $field ] ) ) {
                        unset( $fields['billing'][ $field ] );
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * Convert a major-unit amount (e.g. WC order total) to the minor-unit
     * integer value the Therius API expects, honouring zero-decimal currencies.
     */
    private function to_minor_units( $major, $currency ) {
        $currency = strtoupper( $currency );
        if ( in_array( $currency, self::$zero_decimal_currencies, true ) ) {
            return (int) round( $major );
        }
        return (int) round( $major * 100 );
    }

    /**
     * The `exponent` value to send alongside a minor-unit amount.
     */
    private function currency_exponent( $currency ) {
        return in_array( strtoupper( $currency ), self::$zero_decimal_currencies, true ) ? 0 : 2;
    }

    /**
     * Extract a human-readable message from a Therius API response.
     * HTTP-level errors return a flat string (`{"error": "..."}`); a genuine
     * decline is a 200 OK with `status: "refused"` and the reason under
     * `refusalCode`, not `error` at all — there is no `error.message` shape.
     */
    private function extract_error_message( $data, $fallback = null ) {
        if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
            return $data['error'];
        }
        if ( isset( $data['refusalCode']['reason'] ) ) {
            return $data['refusalCode']['reason'];
        }
        return null !== $fallback ? $fallback : __( 'Payment declined.', 'therius-woocommerce' );
    }

    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        $therius_method = isset( $_POST['therius_method'] ) ? wc_clean( $_POST['therius_method'] ) : 'card';

        // The widget resolved this payment's final outcome itself, client-side
        // (a real 3DS *challenge*, as opposed to the DDC/fingerprint step —
        // see the onActionComplete comment in therius-checkout.js) and never
        // routed it back through our onNonce/onApm interceptors, so this is
        // not a new charge attempt. Look the outcome up ourselves rather than
        // trusting the posted paymentCode directly.
        if ( 'finalize' === $therius_method ) {
            return $this->finalize_from_action_complete( $order );
        }

        $body = array(
            'amount'    => array(
                'value'    => $this->to_minor_units( $order->get_total(), $order->get_currency() ),
                'currency' => $order->get_currency(),
                'exponent' => $this->currency_exponent( $order->get_currency() ),
            ),
            'shopper'   => array(
                'email' => $order->get_billing_email(),
                'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            ),
            // This PHP request IS the customer's own checkout-page form
            // submission (see therius-checkout.js's triggerWooCommerceSubmit,
            // which injects the SDK's collected data into the WooCommerce
            // checkout form and submits it normally) — so $_SERVER here still
            // carries the real shopper browser's headers/IP, not WordPress's
            // own. Required for 3DS (therius-3ds's browser_info fields) and,
            // more strictly, for Stripe ACH's mandate_data.customer_acceptance:
            // Stripe hard-rejects an empty user_agent rather than silently
            // accepting it (handlers_payment.go only sets gwReq.UserAgent when
            // browserInfo is present at all — omitting this block entirely
            // left every WooCommerce-originated ACH purchase failing with
            // "You passed an empty string for
            // 'mandate_data[customer_acceptance][online][user_agent]'").
            'browserInfo' => array(
                'userAgent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? wc_clean( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
                'ipAddress' => $order->get_customer_ip_address(),
            ),
        );

        $customer_id = $order->get_customer_id();
        if ( ! empty( $customer_id ) ) {
            $body['shopper']['id'] = strval( $customer_id );
        }

        if ( 'card' === $therius_method ) {
            $nonce = isset( $_POST['therius_nonce'] ) ? wc_clean( $_POST['therius_nonce'] ) : '';
            if ( empty( $nonce ) ) {
                throw new Exception( __( 'Payment error: Missing Therius payment nonce.', 'therius-woocommerce' ) );
            }
            $body['card'] = array(
                'nonceData' => array(
                    'nonce' => $nonce,
                    'cardholderName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'cardAddress' => array(
                        'address1'    => $order->get_billing_address_1(),
                        'address2'    => $order->get_billing_address_2(),
                        'city'        => $order->get_billing_city(),
                        'state'       => $order->get_billing_state(),
                        'countryCode' => $order->get_billing_country(),
                        'postalCode'  => $order->get_billing_postcode(),
                    )
                )
            );

            // The widget's own "save my card" checkbox state — see the onNonce
            // comment in therius-checkout.js for why this arrives as a separate
            // hidden field rather than something read off the order. The API
            // requires shopper.id whenever tokenize is true (400s otherwise);
            // the widget itself only renders the checkbox for an identified
            // shopper (session-bound customerId, i.e. a logged-in user — see
            // $customer_id above), so in practice this should never fire for a
            // guest checkout, but skip tokenizing rather than let the whole
            // payment fail if it somehow does.
            $vault_consent = isset( $_POST['therius_vault_consent'] ) && '1' === wc_clean( $_POST['therius_vault_consent'] );
            if ( $vault_consent && ! empty( $body['shopper']['id'] ) ) {
                $body['card']['nonceData']['tokenize'] = true;
            } elseif ( $vault_consent ) {
                $order->add_order_note( __( 'Therius: shopper requested to save this card, but no shopper id was available (guest checkout) — card was not saved.', 'therius-woocommerce' ) );
            }
        } elseif ( 'saved_method' === $therius_method ) {
            $token = isset( $_POST['therius_saved_token'] ) ? wc_clean( $_POST['therius_saved_token'] ) : '';
            if ( empty( $token ) ) {
                throw new Exception( __( 'Payment error: Missing saved card token.', 'therius-woocommerce' ) );
            }
            // Same endpoint (/payment/purchase) as a fresh card — card.tokenData.token
            // is the "reuse a vaulted card" path (resolveCard in handlers_payment.go),
            // parallel to card.nonceData for a new card.
            $body['card'] = array(
                'tokenData' => array(
                    'token'       => $token,
                    'cardAddress' => array(
                        'address1'    => $order->get_billing_address_1(),
                        'address2'    => $order->get_billing_address_2(),
                        'city'        => $order->get_billing_city(),
                        'state'       => $order->get_billing_state(),
                        'countryCode' => $order->get_billing_country(),
                        'postalCode'  => $order->get_billing_postcode(),
                    ),
                ),
            );
        } elseif ( 'apm' === $therius_method ) {
            $apm_data = isset( $_POST['therius_apm_data'] ) ? json_decode( wp_unslash( $_POST['therius_apm_data'] ), true ) : array();
            if ( empty( $apm_data ) ) {
                throw new Exception( __( 'Payment error: Missing APM data.', 'therius-woocommerce' ) );
            }
            if ( empty( $apm_data['payerName'] ) ) {
                $apm_data['payerName'] = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            }
            if ( empty( $apm_data['payerEmail'] ) ) {
                $apm_data['payerEmail'] = $order->get_billing_email();
            }
            $apm_data['billingAddress'] = array(
                'address1'    => $order->get_billing_address_1(),
                'address2'    => $order->get_billing_address_2(),
                'city'        => $order->get_billing_city(),
                'state'       => $order->get_billing_state(),
                'countryCode' => $order->get_billing_country(),
                'postalCode'  => $order->get_billing_postcode(),
            );
            $body['apm'] = $apm_data;
        } elseif ( 'wallet' === $therius_method ) {
            $wallet_data = isset( $_POST['therius_wallet_data'] ) ? json_decode( wp_unslash( $_POST['therius_wallet_data'] ), true ) : array();
            if ( empty( $wallet_data ) ) {
                throw new Exception( __( 'Payment error: Missing Wallet data.', 'therius-woocommerce' ) );
            }
            $body['wallet'] = $wallet_data;
        } else {
            throw new Exception( __( 'Payment error: Unknown payment method.', 'therius-woocommerce' ) );
        }

        // Determine API URL based on environment
        $api_url = $this->testmode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        // The Therius API expects the private key in the JSON body for these endpoints
        $body['key'] = $this->private_key;

        if ( ! empty( $this->merchant_code ) ) {
            $body['merchantCode'] = $this->merchant_code;
        }

        $payment_code = isset( $_POST['therius_payment_code'] ) ? wc_clean( $_POST['therius_payment_code'] ) : '';
        if ( ! empty( $payment_code ) ) {
            $body['threeDsSetup'] = array(
                'sessionId' => $payment_code
            );
        }

        // orderCode stays the stable WooCommerce order number across every
        // attempt on this order — needed so GET /payment/inquiry (used by
        // finalize_from_action_complete() below) can be verified against a
        // single, predictable value. Uniqueness for retries/double-clicks is
        // handled by paymentCode instead: therius-public-api's payment lookup
        // (theriuscore.Lookup) only blocks with "Payment already exists" when
        // BOTH order_code AND payment_code match an existing row — so a
        // distinct payment_code per attempt is sufficient on its own, and
        // orderCode never needs to move. Same short-hash approach as before,
        // just applied to paymentCode instead of orderCode: an exact
        // double-click (identical payload) reproduces the same hash → same
        // paymentCode → correctly caught as "already exists"; any real retry
        // (new nonce, DDC/3DS continuation, etc.) has a different payload →
        // different paymentCode → no collision.
        $payload_hash = md5( wp_json_encode( $body ) );
        $body['orderCode']   = $order->get_order_number();
        $body['paymentCode'] = $order->get_order_number() . '_' . substr( $payload_hash, 0, 8 );

        // Stored unconditionally (before the API call, so it never depends on
        // what the response contains) as the check finalize_from_action_complete()
        // uses to confirm a widget-supplied paymentCode really belongs to THIS
        // order's purchase attempts.
        $order->update_meta_data( '_therius_order_code', $body['orderCode'] );
        $order->save();

        // Idempotency key tied to this order + hash of the payload
        $idempotency_key = 'wc_purchase_' . $order_id . '_' . $payload_hash;

        // Call the Therius API to authorize/charge the payment
        $response = wp_remote_post( $api_url . '/v1/payment/purchase', array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization'   => 'Bearer ' . $this->private_key,
                'Content-Type'    => 'application/json',
                'Idempotency-Key' => $idempotency_key,
            ),
            'body'      => wp_json_encode( $body ),
            'timeout'   => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( __( 'Connection error with Therius API.', 'therius-woocommerce' ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // Store the Therius payment code as soon as we have one — BEFORE the
        // challenge-status early-return below, not just at the end of this
        // method. A payment that goes through DDC/3DS never reaches the end
        // of this method on this call (it returns early with the challenge
        // action instead); the widget's later onActionComplete finalize call
        // (finalize_from_action_complete()) verifies the payment code it was
        // given against this exact meta, so it must exist before that round
        // trip, not only for payments that skip the challenge entirely.
        // Also doubles as the match key for the webhook handler (capture
        // confirmation, refund, chargeback, etc.) — order_code alone isn't a
        // stable enough key once sequential-order-number plugins are
        // involved. Must match what webhook deliveries carry as
        // data.payment_code (services_webhook.go) — the internal payment_id
        // UUID is never exposed to the merchant.
        if ( isset( $data['paymentCode'] ) ) {
            $order->update_meta_data( '_therius_payment_code', $data['paymentCode'] );
            $order->save();
        }

        // Real status strings + action field, per therius-public-api/handlers_payment.go:
        // status is "pending_ddc" (DDC/fingerprint) or "pending_3ds" (challenge) — NOT
        // "pending_challenge" — and the action payload key is "actionRequired", not "nextAction".
        $challenge_statuses = array( 'pending_ddc', 'pending_3ds' );
        if ( isset( $data['status'] ) && in_array( $data['status'], $challenge_statuses, true ) && ! empty( $data['actionRequired'] ) ) {
            return array(
                'result'             => 'success',
                'redirect'           => '#therius-3ds', // Intercepted by JS
                'therius_3ds_action' => $data['actionRequired']
            );
        }

        // 'pending' is a real, expected outcome (e.g. async fraud/issuer
        // review) — its final result arrives later via webhook, so it must
        // not be treated as a decline.
        // The payment identifier field is "paymentCode" — the API response has
        // no "id" field at all (paymentResponse struct in handlers_payment.go).
        $accepted_statuses = array( 'captured', 'authorized', 'pending', 'approved', 'succeeded' );
        if ( wp_remote_retrieve_response_code( $response ) >= 400 || ! isset( $data['paymentCode'] ) || ! in_array( $data['status'], $accepted_statuses, true ) ) {
            throw new Exception( $this->extract_error_message( $data ) );
        }

        // The synchronous response gives us the outcome at charge time, but it
        // is not the last word: capture confirmation, refunds and chargebacks
        // arrive later via the signed webhook (see handle_webhook()), which is
        // the authoritative source for anything that happens after this point.
        if ( 'captured' === $data['status'] ) {
            $order->payment_complete( $data['paymentCode'] );
            $order->add_order_note( sprintf( __( 'Therius payment captured (Transaction: %s).', 'therius-woocommerce' ), $data['paymentCode'] ) );
        } elseif ( 'pending' === $data['status'] ) {
            $order->update_status( 'on-hold', __( 'Therius: payment pending review, awaiting outcome via webhook.', 'therius-woocommerce' ) );
        } else {
            $order->update_status( 'on-hold', __( 'Therius: payment authorized, awaiting capture confirmation.', 'therius-woocommerce' ) );
        }
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order )
        );
    }

    /**
     * Finalizes an order whose outcome the Checkout Widget resolved entirely
     * client-side — a real 3DS *challenge* round (sdk._resumePayment /
     * _3dsAdvance) calls the Therius API directly from the browser, bypassing
     * our server, so this is the first this order's process_payment() sees of
     * that outcome. Re-derives it via GET /payment/inquiry rather than
     * trusting the widget-supplied paymentCode/status directly.
     *
     * Verified against orderCode, NOT a previously-stored paymentCode: the
     * pending_ddc/pending_3ds purchase responses (see process_payment() above)
     * carry no top-level paymentCode at all — only actionRequired.paymentCode,
     * which is a 3DS/DDC *session* id, not the payment's own code — so there
     * is never a real paymentCode to have stored for exactly the orders that
     * reach this method. orderCode is generated by US, locally, before every
     * purchase call (and saved unconditionally there), so it's always known
     * and isn't attacker-influenced — confirming the inquiry result's
     * orderCode matches what this order itself last submitted is what proves
     * the submitted paymentCode actually belongs to this order's payment.
     */
    private function finalize_from_action_complete( $order ) {
        $submitted_code = isset( $_POST['therius_finalize_payment_code'] ) ? wc_clean( $_POST['therius_finalize_payment_code'] ) : '';
        $expected_order_code = $order->get_meta( '_therius_order_code' );

        if ( empty( $submitted_code ) || empty( $expected_order_code ) ) {
            throw new Exception( __( 'Payment error: could not verify payment outcome.', 'therius-woocommerce' ) );
        }

        $api_url = $this->testmode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        // GET /payment/inquiry takes no API key, so — unlike purchase/refund,
        // which resolve sandbox vs. production from the sk_sandbox_/sk_live_
        // key prefix — it has nothing to derive environment from except this
        // header, and defaults to production when it's absent. Without it, a
        // sandbox payment (as here, when testmode is on) simply isn't found:
        // "Payment does not exist", even though the paymentCode is correct.
        $headers = array( 'Authorization' => 'Bearer ' . $this->private_key );
        if ( $this->testmode ) {
            $headers['X-Environment'] = 'sandbox';
        }

        // The widget's onActionComplete fires the instant its own /resume (or
        // /3ds_advance) call returns the final status — but that response and
        // this inquiry call are two independent requests, and we've observed
        // GET /payment/inquiry briefly 404 ("Payment does not exist") for a
        // paymentCode that /resume had just reported as captured seconds
        // earlier (confirmed by re-querying the same code moments later and
        // getting a normal 200). A short retry absorbs that read-after-write
        // gap instead of failing an otherwise-successful payment.
        $max_attempts = 3;
        $data         = null;
        $response     = null;
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $response = wp_remote_get( $api_url . '/v1/payment/inquiry/' . rawurlencode( $submitted_code ), array(
                'headers' => $headers,
                'timeout' => 45,
            ) );

            if ( is_wp_error( $response ) ) {
                throw new Exception( __( 'Connection error with Therius API.', 'therius-woocommerce' ) );
            }

            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( wp_remote_retrieve_response_code( $response ) < 400 && ! empty( $data['status'] ) ) {
                break;
            }

            if ( $attempt < $max_attempts ) {
                usleep( 700000 ); // 0.7s
            }
        }

        if ( wp_remote_retrieve_response_code( $response ) >= 400 || empty( $data['status'] ) ) {
            throw new Exception( $this->extract_error_message( $data ) );
        }

        if ( empty( $data['orderCode'] ) || $data['orderCode'] !== $expected_order_code ) {
            throw new Exception( __( 'Payment error: could not verify payment outcome.', 'therius-woocommerce' ) );
        }

        // Now that the inquiry is confirmed to be about THIS order's own
        // payment, the paymentCode it reports is the real one — store it,
        // same purpose as the synchronous-path save in process_payment()
        // (webhook matching).
        $order->update_meta_data( '_therius_payment_code', $data['paymentCode'] );
        $order->save();

        // Same acceptance rule as the synchronous purchase response in
        // process_payment() above — 'pending' is a real, expected outcome
        // whose final result still arrives later via webhook.
        $accepted_statuses = array( 'captured', 'authorized', 'pending', 'approved', 'succeeded' );
        if ( ! in_array( $data['status'], $accepted_statuses, true ) ) {
            throw new Exception( $this->extract_error_message( $data ) );
        }

        if ( 'captured' === $data['status'] ) {
            $order->payment_complete( $data['paymentCode'] );
            $order->add_order_note( sprintf( __( 'Therius payment captured (Transaction: %s).', 'therius-woocommerce' ), $data['paymentCode'] ) );
        } elseif ( 'pending' === $data['status'] ) {
            $order->update_status( 'on-hold', __( 'Therius: payment pending review, awaiting outcome via webhook.', 'therius-woocommerce' ) );
        } else {
            $order->update_status( 'on-hold', __( 'Therius: payment authorized, awaiting capture confirmation.', 'therius-woocommerce' ) );
        }
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order )
        );
    }

    /**
     * Process a refund initiated from the WooCommerce admin (Orders → Refund).
     *
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'therius_refund_error', __( 'Order not found.', 'therius-woocommerce' ) );
        }

        if ( null === $amount ) {
            return new WP_Error( 'therius_refund_error', __( 'Refund amount is required.', 'therius-woocommerce' ) );
        }

        $api_url = $this->testmode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        // Reuse the same idempotency key for a short window if this exact
        // (order, amount, reason) refund is retried (e.g. a network timeout
        // on the first attempt followed by the admin clicking Refund again) —
        // avoids double-refunding while still allowing a genuinely new refund
        // request after the window or with different parameters.
        $dedupe_key       = md5( $order_id . '|' . $amount . '|' . $reason );
        $transient_key    = 'therius_refund_idem_' . $dedupe_key;
        $idempotency_key  = get_transient( $transient_key );
        if ( ! $idempotency_key ) {
            $idempotency_key = wp_generate_uuid4();
            set_transient( $transient_key, $idempotency_key, 5 * MINUTE_IN_SECONDS );
        }

        $body = array(
            'key'       => $this->private_key,
            'orderCode' => $order->get_order_number(),
            'amount'    => array(
                'value'    => $this->to_minor_units( $amount, $order->get_currency() ),
                'currency' => $order->get_currency(),
                'exponent' => $this->currency_exponent( $order->get_currency() ),
            ),
            'reference' => $reason,
        );

        // Without an explicit paymentCode, therius-public-api's refund lookup
        // (theriuscore.Lookup, same WHERE order_code=$1 AND payment_code=$2
        // used by /payment/inquiry) defaults paymentCode to orderCode and
        // requires an EXACT match on both — but the real payment's
        // payment_code is the per-attempt hash-suffixed value stored in
        // _therius_payment_code (set in process_payment() on a synchronous
        // capture, or finalize_from_action_complete() after a 3DS challenge),
        // never the bare order number. Omitting this always 400s with "The
        // payment to be refunded does not exist", regardless of anything else
        // about the order.
        $payment_code = $order->get_meta( '_therius_payment_code' );
        if ( ! empty( $payment_code ) ) {
            $body['paymentCode'] = $payment_code;
        }

        if ( ! empty( $this->merchant_code ) ) {
            $body['merchantCode'] = $this->merchant_code;
        }

        $response = wp_remote_post( $api_url . '/v1/payment/refund', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization'   => 'Bearer ' . $this->private_key,
                'Content-Type'    => 'application/json',
                'Idempotency-Key' => $idempotency_key,
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'therius_refund_error', $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return new WP_Error( 'therius_refund_error', $this->extract_error_message( $data, __( 'Refund failed.', 'therius-woocommerce' ) ) );
        }

        // Refund was accepted by Therius; the order moves to "Refunded" when
        // the payment.refunded webhook confirms it (see process_webhook_event()).
        $order->add_order_note( sprintf( __( 'Therius refund of %1$s initiated. Order will move to Refunded once confirmed by webhook.', 'therius-woocommerce' ), wc_price( $amount ) ) );

        return true;
    }

    /**
     * Webhook receiver — Therius POSTs signed status-change notifications here.
     * Bound to https://<site>/?wc-api=WC_Gateway_Therius via the
     * woocommerce_api_wc_gateway_therius action registered in __construct().
     */
    public function handle_webhook() {
        $raw_body          = file_get_contents( 'php://input' );
        $signature_header  = isset( $_SERVER['HTTP_X_THERIUS_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_THERIUS_SIGNATURE'] ) ) : '';

        $data = json_decode( $raw_body, true );
        if ( ! is_array( $data ) || empty( $data['event'] ) ) {
            status_header( 400 );
            exit;
        }

        // Deliveries carry their originating environment so the same endpoint
        // can be used for both a Sandbox and a Production webhook config.
        $environment = isset( $data['environment'] ) ? $data['environment'] : '';
        $secret      = ( 'sandbox' === $environment ) ? $this->get_option( 'test_webhook_secret' ) : $this->get_option( 'webhook_secret' );

        if ( empty( $secret ) || ! $this->verify_webhook_signature( $raw_body, $signature_header, $secret ) ) {
            status_header( 401 );
            exit;
        }

        $this->process_webhook_event( $data );

        status_header( 200 );
        exit;
    }

    /**
     * Verify X-Therius-Signature: sha256=HMAC-SHA256(body, signing_secret).
     */
    private function verify_webhook_signature( $raw_body, $signature_header, $secret ) {
        if ( empty( $signature_header ) || 0 !== strpos( $signature_header, 'sha256=' ) ) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
        return hash_equals( $expected, $signature_header );
    }

    /**
     * Apply a verified webhook event to the matching WooCommerce order.
     */
    private function process_webhook_event( $data ) {
        $event   = $data['event'];
        $payload = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();

        $order = $this->find_order_for_webhook( $payload );
        if ( ! $order ) {
            return; // Nothing to reconcile against; still ack 200 so Therius doesn't keep retrying.
        }

        // Idempotency: webhook deliveries retry on failure and can arrive more
        // than once for the same event — skip re-applying one we've already seen.
        $event_key = $event . ':' . ( isset( $data['created_at'] ) ? $data['created_at'] : '' );
        if ( $order->get_meta( '_therius_last_event' ) === $event_key ) {
            return;
        }

        switch ( $event ) {
            case 'payment.captured':
                if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
                    $order->payment_complete( isset( $payload['payment_code'] ) ? $payload['payment_code'] : '' );
                    $order->add_order_note( __( 'Therius: payment captured (confirmed via webhook).', 'therius-woocommerce' ) );
                }
                break;

            case 'payment.refused':
                $order->update_status( 'failed', __( 'Therius: payment refused (confirmed via webhook).', 'therius-woocommerce' ) );
                break;

            case 'payment.refunded':
                if ( ! $order->has_status( 'refunded' ) ) {
                    $order->update_status( 'refunded', __( 'Therius: payment refunded (confirmed via webhook).', 'therius-woocommerce' ) );
                }
                break;

            case 'payment.cancelled':
                $order->update_status( 'cancelled', __( 'Therius: payment cancelled (confirmed via webhook).', 'therius-woocommerce' ) );
                break;

            case 'payment.chargeback':
                $order->update_status( 'on-hold', __( 'Therius: chargeback/dispute opened on this payment — needs manual review.', 'therius-woocommerce' ) );
                break;

            case 'payment.capture_failed':
                $order->update_status( 'on-hold', __( 'Therius: capture attempt failed — needs manual review.', 'therius-woocommerce' ) );
                break;

            case 'payment.refund_failed':
                // Don't move the order off its current status (the customer's
                // payment is unaffected — only the refund attempt failed);
                // "on-hold" would wrongly read as "unpaid" and could block
                // fulfillment. Just flag it loudly so the merchant retries.
                $order->add_order_note( __( 'Therius: refund attempt failed — needs manual review and retry.', 'therius-woocommerce' ) );
                break;

            case 'payment.cancel_failed':
                $order->update_status( 'on-hold', __( 'Therius: cancellation attempt failed — needs manual review.', 'therius-woocommerce' ) );
                break;

            case 'payment.authorized':
                // Already handled synchronously in process_payment(); no-op here.
                break;

            default:
                $order->add_order_note( sprintf( __( 'Therius: received unhandled webhook event "%s".', 'therius-woocommerce' ), esc_html( $event ) ) );
                return; // Don't record as last-applied for events we didn't act on.
        }

        $order->update_meta_data( '_therius_last_event', $event_key );
        $order->save();
    }

    /**
     * Look up the WooCommerce order a webhook event refers to. Prefers the
     * stored Therius payment code (set in process_payment()); falls back to
     * order_code for orders paid before that meta existed.
     *
     * Matches on payload.payment_code, NOT payload.payment_id — the webhook
     * payload's payment_id is Therius's internal payment UUID
     * (services_webhook.go), which is never exposed in the /payment/purchase
     * response the merchant sees (paymentResponse has no "id" field). The
     * merchant-visible identifier — and what process_payment() stores — is
     * paymentCode, which the webhook payload separately carries as payment_code.
     */
    private function find_order_for_webhook( $payload ) {
        if ( ! empty( $payload['payment_code'] ) ) {
            $orders = wc_get_orders( array(
                'meta_key'   => '_therius_payment_code',
                'meta_value' => $payload['payment_code'],
                'limit'      => 1,
                'return'     => 'objects',
            ) );
            if ( ! empty( $orders ) ) {
                return $orders[0];
            }
        }

        // order_code was set to $order->get_order_number(), which is the
        // order ID unless a sequential-order-number plugin is active.
        if ( ! empty( $payload['order_code'] ) && is_numeric( $payload['order_code'] ) ) {
            $order = wc_get_order( (int) $payload['order_code'] );
            if ( $order ) {
                return $order;
            }
        }

        return false;
    }

    /**
     * Load custom scripts
     */
    public function payment_scripts() {
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
            return;
        }
        
        if ( 'no' === $this->enabled ) {
            return;
        }

        $api_url = $this->testmode ? 'https://api-sandbox.therius.io' : 'https://api.therius.io';

        $session_body = array(
            'country'  => WC()->customer && WC()->customer->get_billing_country() ? WC()->customer->get_billing_country() : WC()->countries->get_base_country(),
            'currency' => get_woocommerce_currency()
        );

        if ( is_user_logged_in() ) {
            $session_body['customerId'] = strval( get_current_user_id() );
        }
        
        if ( ! empty( $this->checkout_config_id ) ) {
            $session_body['checkoutConfigId'] = $this->checkout_config_id;
        }

        // Get a clientToken from Therius API for the frontend SDK
        $response = wp_remote_post( $api_url . '/v1/sdk/session', array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->private_key,
                'Content-Type'  => 'application/json',
            ),
            'body'      => wp_json_encode( $session_body ),
        ) );

        $client_token = '';
        if ( ! is_wp_error( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            if ( isset( $data['clientToken'] ) ) {
                $client_token = $data['clientToken'];
            }
        }

        // Enqueue Therius frontend SDK (served by the API itself, no separate js.* host)
        wp_enqueue_script( 'therius-sdk', $api_url . '/v1/sdk/js', array(), '1.0.0', true );
        
        // Enqueue our custom integration script
        wp_enqueue_script( 'woocommerce_therius', plugins_url( 'assets/js/therius-checkout.js', dirname( __FILE__ ) ), array( 'therius-sdk', 'jquery' ), '1.0.0', true );

        // Get the configured checkout config ID based on environment
        $checkout_config_id = $this->checkout_config_id;

        // Pass PHP variables to JS
        $therius_params = array(
            'client_token'       => $client_token,
            'base_url'           => $api_url,
            'currency'           => get_woocommerce_currency(),
            'amount'             => WC()->cart ? $this->to_minor_units( WC()->cart->total, get_woocommerce_currency() ) : 0,
            'country'            => WC()->customer && WC()->customer->get_billing_country() ? WC()->customer->get_billing_country() : WC()->countries->get_base_country(),
            'checkout_config_id' => $checkout_config_id
        );

        if ( ! empty( $this->merchant_code ) ) {
            $therius_params['merchantCode'] = $this->merchant_code;
        }

        wp_localize_script( 'woocommerce_therius', 'therius_params', $therius_params );
    }
}
