/* global jQuery, TheriusSDK, therius_params */
jQuery( function( $ ) {
    'use strict';

    var therius_checkout = {
        therius: null,
        widget: null,
        currentResolve: null,
        currentReject: null,
        suppressNextCheckoutError: false,

        init: function() {
            // Check if Therius SDK is loaded and we have our params
            if ( typeof TheriusSDK === 'undefined' || typeof therius_params === 'undefined' || !therius_params.client_token ) {
                return false;
            }

            // Initialize the Therius JS SDK with the client token
            this.therius = new TheriusSDK.TheriusSDK({
                clientToken: therius_params.client_token,
                baseUrl: therius_params.base_url
            });
            
            // Mount the full Checkout Widget to the DOM
            this.mountWidget();

            var self = this;

            // Listen to WooCommerce checkout events to hide the native "Place Order" button
            $( document.body ).on( 'payment_method_selected', this.toggleNativeButton.bind(this) );
            
            // WooCommerce replaces the checkout form HTML on AJAX updates, destroying the widget.
            // We must remount the widget whenever the checkout updates.
            $( document.body ).on( 'updated_checkout', function() {
                self.mountWidget();
            });
            
            // Listen to WooCommerce checkout errors to reject the widget's internal promise
            $( document.body ).on( 'checkout_error', this.onCheckoutError.bind(this) );
            
            // Listen to WooCommerce checkout success to intercept 3DS actions
            $( 'form.checkout' ).on( 'checkout_place_order_success', function( event, result ) {
                if ( result.therius_3ds_action ) {
                    if ( self.currentResolve ) {
                        self.currentResolve( result.therius_3ds_action );
                        self.currentResolve = null;
                        self.currentReject = null;
                    }
                    // WooCommerce core (assets/js/frontend/checkout.js) only skips its
                    // "Invalid response" error path when this handler's return value is
                    // NOT strictly false AND result.result === 'success'; there is no
                    // supported "success, don't redirect, don't error" outcome. Returning
                    // false here (required to stop the fake '#therius-3ds' redirect) always
                    // makes WooCommerce fall through to throw 'Invalid response', which
                    // shows a spurious generic error notice and fires checkout_error — even
                    // though the 3DS/DDC handoff above just succeeded. Flag it so
                    // onCheckoutError() can clean up that notice instead of treating it as
                    // a real failure (the same accepted trade-off official SCA-capable WC
                    // gateway plugins make).
                    self.suppressNextCheckoutError = true;
                    return false;
                }
                return true;
            });

            // Prevent native WooCommerce submission if Therius is selected and the widget hasn't injected its data yet
            $( 'form.checkout' ).on( 'checkout_place_order_therius', function() {
                var $form = $( 'form.checkout' );
                if ( $form.find('input[name="therius_method"]').length === 0 ) {
                    // The user clicked the native Place Order button (or pressed Enter) instead of the Widget's Pay button.
                    self.toggleNativeButton();
                    
                    // Show a helpful error using WooCommerce's native error system
                    $form.removeClass( 'processing' ).unblock();
                    $( '.woocommerce-NoticeGroup-checkout, .woocommerce-error' ).remove();
                    $form.prepend( 
                        '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
                        '<ul class="woocommerce-error" role="alert"><li>Please use the "Pay" button inside the secure payment widget to complete your transaction.</li></ul>' +
                        '</div>' 
                    );
                    $( 'html, body' ).animate( { scrollTop: ( $form.offset().top - 100 ) }, 1000 );
                    
                    return false;
                }
                return true;
            });
        },

        toggleNativeButton: function() {
            // Hide WooCommerce's native "Place Order" button when Therius is selected,
            // so the user only clicks the widget's "Pay" button.
            var selectedMethod = $( 'input[name="payment_method"]:checked' ).val();
            var $nativeButton = $( '#place_order, .place-order button' );
            if ( selectedMethod === 'therius' ) {
                $nativeButton.hide();
            } else {
                $nativeButton.show();
            }
        },

        mountWidget: function() {
            var container = $( '#therius-payment-element' );
            if ( !container.length ) return;

            // Clear any existing hosted fields content
            container.empty();

            var self = this;
            var checkoutOptions = {
                amount: parseFloat( therius_params.amount || 0 ),
                currency: therius_params.currency || 'USD',
                country: therius_params.country || 'US',
                
                // Inject the current WooCommerce billing details so the SDK can use them
                // (e.g. for Click to Pay lookup, 3DS data, or pre-filling the save-card form)
                shopperEmail: $( '#billing_email' ).val() || '',
                shopperName: ( $( '#billing_first_name' ).val() + ' ' + $( '#billing_last_name' ).val() ).trim() || '',
                
                // Intercept card submissions. The SDK calls this as
                // onNonce(nonce, ddcSessionId, vaultConsent, shopperInfo, intent) —
                // ddcSessionId is only set on the DDC-retry call (see the
                // pending_ddc comment in class-wc-gateway-therius.php); vaultConsent
                // reflects the widget's own "save my card" checkbox and must be
                // forwarded through to PHP, which is what actually sets
                // card.nonceData.tokenize on the purchase request.
                // `shopper` (4th arg) carries the widget's own optional
                // email/address fields, collected only when the "save my
                // card" checkbox is checked — forwarded as a billing-address
                // override so it isn't silently dropped in favor of the
                // order's stored address.
                onNonce: function( nonce, ddcSessionId, vaultConsent, shopper ) {
                    return self.triggerWooCommerceSubmit( 'card', { nonce: nonce, vaultConsent: vaultConsent, shopper: shopper }, ddcSessionId );
                },

                // Intercept APM submissions (e.g. Pix, iDEAL)
                onApm: function( apmData, ddcSessionId ) {
                    return self.triggerWooCommerceSubmit( 'apm', apmData, ddcSessionId );
                },

                // Intercept Wallet submissions (Apple Pay / Google Pay)
                onWalletToken: function( walletData, ddcSessionId ) {
                    return self.triggerWooCommerceSubmit( 'wallet', walletData, ddcSessionId );
                },

                // Intercept Visa Click to Pay submissions. Unlike onNonce/
                // onApm/onWalletToken, the SDK has NO internal fallback for
                // this one (see createClickToPayButton in
                // therius-sdk/src/click_to_pay.ts) — leaving it unset means
                // a shopper who completes Click to Pay gets total silence:
                // no purchase call, no error, no order. Forward the Visa
                // DCF payload as `clickToPayData` on the purchase request,
                // same as any other payment method here.
                onClickToPay: function( data ) {
                    return self.triggerWooCommerceSubmit( 'click_to_pay', data );
                },

                // Intercept a shopper picking one of their previously-saved
                // cards from the widget's own picker. Without this, the
                // widget falls back to calling sdk.authorizeToken() itself
                // directly from the browser (see _submitSavedMethod in
                // checkout.ts) — same "self-sufficient" client-only mode
                // therius-sdk-example relies on, but it builds its own
                // orderCode (DEMO-<timestamp>-<random>, see sdk.ts's
                // _orderCode()) since it has no way to know WooCommerce's
                // real order number, and the charge never goes through
                // process_payment() at all.
                // `shopper` (3rd arg) is the same widget-collected
                // address-override echo as onNonce's — forward it so it
                // isn't silently dropped in favor of the order's stored
                // address.
                onSavedMethodSelected: function( token, ddcSessionId, shopper ) {
                    return self.triggerWooCommerceSubmit( 'saved_method', { token: token, shopper: shopper }, ddcSessionId );
                },

                // Fires when the widget itself finished resolving a payment
                // outcome client-side — this is the ONLY notification for a
                // real 3DS *challenge* (as opposed to the DDC/fingerprint
                // step, which loops back through onNonce/triggerWooCommerceSubmit
                // above). The widget calls the Therius API directly from the
                // browser to resume/advance the challenge, so WooCommerce's
                // server never sees that call or its outcome — without this
                // handler, both an approval AND a decline after a challenge
                // are silently dropped and the "Pay" button is left showing
                // "Pending confirmation…" forever. See onCheckoutError() for
                // why we don't reuse currentResolve/currentReject here: this
                // callback isn't part of that promise chain.
                onActionComplete: function( result ) {
                    self.finalizeFromActionComplete( result.paymentCode );
                }
            };

            if ( therius_params.checkout_config_id ) {
                checkoutOptions.checkoutConfigId = therius_params.checkout_config_id;
            } else {
                checkoutOptions.config = {
                    paymentMethods: [
                        { type: 'card', label: 'Credit Card', enabled: true },
                        { type: 'pix', label: 'Pix', enabled: true },
                        { type: 'applepay', label: 'Apple Pay', enabled: true },
                        { type: 'googlepay', label: 'Google Pay', enabled: true }
                    ]
                };
            }

            this.widget = this.therius.checkout(checkoutOptions);

            this.widget.mount( container[0] );
            this.toggleNativeButton();
        },

        // `ddcSessionId` is only set when the SDK is re-invoking the interceptor
        // after a pending_ddc device-data-collection round completed. It rides
        // in the `therius_payment_code` hidden field (name kept as-is — PHP's
        // process_payment() reads it into threeDsSetup.sessionId) purely
        // because that field already existed; it does NOT carry a paymentCode.
        triggerWooCommerceSubmit: function( methodType, payload, ddcSessionId ) {
            var self = this;
            // We return a Promise to the Widget. The widget will show a loading spinner
            // until this Promise resolves or rejects.
            return new Promise( function( resolve, reject ) {
                self.currentResolve = resolve;
                self.currentReject = reject;

                var $form = $( 'form.checkout, form#order_review' );

                // Remove any old injected data
                $form.find( '.therius_injected_data' ).remove();

                // Inject method type
                $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_method' }).val(methodType).appendTo($form);

                if ( ddcSessionId ) {
                    $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_payment_code' }).val(ddcSessionId).appendTo($form);
                }

                if ( methodType === 'card' ) {
                    $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_nonce' }).val(payload.nonce).appendTo($form);
                    if ( payload.vaultConsent ) {
                        $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_vault_consent' }).val('1').appendTo($form);
                    }
                    if ( payload.shopper ) {
                        $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_shopper_override' }).val(JSON.stringify(payload.shopper)).appendTo($form);
                    }
                } else if ( methodType === 'apm' ) {
                    $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_apm_data' }).val(JSON.stringify(payload)).appendTo($form);
                } else if ( methodType === 'wallet' ) {
                    $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_wallet_data' }).val(JSON.stringify(payload)).appendTo($form);
                } else if ( methodType === 'click_to_pay' ) {
                    $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_click_to_pay_data' }).val(JSON.stringify(payload)).appendTo($form);
                } else if ( methodType === 'saved_method' ) {
                    $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_saved_token' }).val(payload.token).appendTo($form);
                    if ( payload.shopper ) {
                        $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_shopper_override' }).val(JSON.stringify(payload.shopper)).appendTo($form);
                    }
                }

                // Programmatically trigger standard WooCommerce validation and submission
                $form.submit();
            });
        },

        // Submits a fresh (non-Promise-gated) WC checkout request carrying only
        // the paymentCode the widget finished resolving client-side (challenge
        // completion, DDC-only decline, Pix/APM polling, etc.). PHP re-derives
        // the authoritative outcome via GET /payment/inquiry rather than
        // trusting this client-supplied value directly (see process_payment()'s
        // therius_finalize_payment_code branch).
        finalizeFromActionComplete: function( paymentCode ) {
            if ( !paymentCode ) return;

            var $form = $( 'form.checkout, form#order_review' );
            $form.find( '.therius_injected_data' ).remove();

            $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_method' }).val('finalize').appendTo($form);
            $('<input>').attr({ type: 'hidden', class: 'therius_injected_data', name: 'therius_finalize_payment_code' }).val(paymentCode).appendTo($form);

            $form.submit();
        },

        onCheckoutError: function() {
            if ( this.suppressNextCheckoutError ) {
                // Expected fallout from returning false in checkout_place_order_success
                // above (see the comment there) — not a real error. The 3DS/DDC handoff
                // already succeeded; just remove WooCommerce's generic notice so the
                // customer isn't shown a scary banner while the widget's challenge/DDC
                // iframe is up.
                this.suppressNextCheckoutError = false;
                $( '.woocommerce-NoticeGroup-checkout, .woocommerce-error' ).remove();
                return;
            }
            // A genuine WooCommerce validation (e.g. missing address) or server error.
            // Reject the widget's promise so it stops loading and the user can fix the error.
            if ( this.currentReject ) {
                this.currentReject( new Error( 'Please check the billing details above.' ) );
                this.currentReject = null;
                this.currentResolve = null;
            }
        }
    };

    therius_checkout.init();
} );
