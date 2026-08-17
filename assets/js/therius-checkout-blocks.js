/* global wp, wc, TheriusSDK */
( function( wp, wc ) {
    'use strict';

    if ( ! wp || ! wp.element || ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
        return;
    }

    var el         = wp.element.createElement;
    var useEffect  = wp.element.useEffect;
    var useRef     = wp.element.useRef;

    var settings = wc.wcSettings.getSetting( 'therius_data', {} );
    var label    = settings.title || 'Therius';

    /**
     * POSTs to admin-ajax.php. Mirrors the field names
     * therius-checkout.js's triggerWooCommerceSubmit() already uses for the
     * classic flow (therius_nonce, therius_apm_data, ...) so
     * WC_Gateway_Therius::build_purchase_body() reads identically either way.
     */
    function ajaxPost( action, fields ) {
        var body = new URLSearchParams();
        body.set( 'action', action );
        body.set( 'nonce', settings.nonce || '' );
        Object.keys( fields || {} ).forEach( function( key ) {
            var value = fields[ key ];
            if ( value === undefined || value === null ) return;
            body.set( key, typeof value === 'object' ? JSON.stringify( value ) : String( value ) );
        } );
        return fetch( settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        } )
            .then( function( res ) { return res.json(); } )
            .then( function( json ) {
                if ( ! json || ! json.success ) {
                    var data          = ( json && json.data ) || {};
                    var message       = data.message || 'Payment failed. Please try again.';
                    var recoveryAction = data.recoveryAction;
                    // recoveryAction present → construct the SDK's DeclineError so
                    // CheckoutWidget's built-in smart recovery (retry/switch_method/
                    // terminal) activates, same as therius-plugin-shopware's
                    // therius-payment.plugin.js. Falls back to a plain Error
                    // (generic message, no smart recovery) otherwise.
                    if ( recoveryAction && window.TheriusSDK && window.TheriusSDK.DeclineError ) {
                        throw new window.TheriusSDK.DeclineError( message, recoveryAction );
                    }
                    throw new Error( message );
                }
                return json.data || {};
            } );
    }

    function generateAttemptId() {
        if ( window.crypto && window.crypto.randomUUID ) {
            return window.crypto.randomUUID();
        }
        return 'attempt-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2 );
    }

    /**
     * Mounts the full Therius Checkout Widget (hideSubmitButton: true) and
     * drives it from the block's own "Place order" button via
     * eventRegistration.onPaymentSetup, instead of therius-checkout.js's
     * classic-only $form.submit() approach.
     *
     * Every interceptor (onNonce/onApm/onWalletToken/onSavedMethodSelected/
     * onClickToPay) calls the SAME pre-order AJAX action
     * (therius_blocks_attempt) — see WC_Gateway_Therius::ajax_blocks_attempt().
     * This runs BEFORE any WC_Order exists (Blocks only creates the order as
     * part of its single /checkout Store API call), so the entire purchase
     * attempt — including any DDC/3DS round-trip, handled entirely
     * client-side by the widget exactly as it is for the classic checkout —
     * has to reach a final outcome before onPaymentSetup can resolve.
     */
    function Content( props ) {
        var eventRegistration = props.eventRegistration;
        var emitResponse      = props.emitResponse;
        var containerRef      = useRef( null );
        var stateRef          = useRef( { attemptId: null, resolveOutcome: null, rejectOutcome: null } );

        useEffect( function() {
            if ( ! containerRef.current || typeof TheriusSDK === 'undefined' ) {
                return undefined;
            }

            var therius = new TheriusSDK.TheriusSDK( {
                clientToken: settings.client_token,
                baseUrl: settings.base_url,
            } );

            // Shared by every interceptor below — POSTs the attempt to
            // ajax_blocks_attempt(), returns the actionRequired object back
            // to the widget (still not final — DDC/3DS keeps going) or
            // signals the outer onPaymentSetup promise once a final outcome
            // is stashed server-side.
            function callAttempt( methodType, payload, ddcSessionId ) {
                var state = stateRef.current;
                var fields = {
                    attempt_id: state.attemptId,
                    therius_method: methodType,
                };
                if ( ddcSessionId ) {
                    fields.therius_payment_code = ddcSessionId;
                }
                if ( methodType === 'card' ) {
                    fields.therius_nonce = payload.nonce;
                    if ( payload.vaultConsent ) fields.therius_vault_consent = '1';
                    if ( payload.shopper ) fields.therius_shopper_override = payload.shopper;
                } else if ( methodType === 'apm' ) {
                    fields.therius_apm_data = payload;
                } else if ( methodType === 'wallet' ) {
                    fields.therius_wallet_data = payload;
                } else if ( methodType === 'click_to_pay' ) {
                    fields.therius_click_to_pay_data = payload;
                } else if ( methodType === 'saved_method' ) {
                    fields.therius_saved_token = payload.token;
                    if ( payload.shopper ) fields.therius_shopper_override = payload.shopper;
                }

                return ajaxPost( 'therius_blocks_attempt', fields ).then( function( data ) {
                    if ( data.actionRequired ) {
                        return data.actionRequired;
                    }
                    // Final outcome stashed server-side (ajax_blocks_attempt())
                    // — tell onPaymentSetup this attempt is done.
                    if ( state.resolveOutcome ) state.resolveOutcome();
                    return undefined;
                } ).catch( function( err ) {
                    if ( state.rejectOutcome ) state.rejectOutcome( err );
                    throw err;
                } );
            }

            var widget = therius.checkout( {
                amount: settings.amount,
                currency: settings.currency,
                country: settings.country,
                checkoutConfigId: settings.checkout_config_id || undefined,
                hideSubmitButton: true,
                onNonce: function( nonce, ddcSessionId, vaultConsent, shopper ) {
                    return callAttempt( 'card', { nonce: nonce, vaultConsent: vaultConsent, shopper: shopper }, ddcSessionId );
                },
                onApm: function( apmData, ddcSessionId ) {
                    return callAttempt( 'apm', apmData, ddcSessionId );
                },
                onWalletToken: function( walletData, ddcSessionId ) {
                    return callAttempt( 'wallet', walletData, ddcSessionId );
                },
                onClickToPay: function( data ) {
                    return callAttempt( 'click_to_pay', data );
                },
                onSavedMethodSelected: function( token, ddcSessionId, shopper ) {
                    return callAttempt( 'saved_method', { token: token, shopper: shopper }, ddcSessionId );
                },
                // Fires after the widget resolves a real 3DS *challenge*
                // entirely client-side (direct browser-to-Therius call,
                // bypassing our server — same as the classic flow). Verify
                // via ajax_blocks_finalize() (GET /payment/inquiry
                // server-side), same distrust-the-client principle as
                // finalize_from_action_complete() in the classic plugin.
                onActionComplete: function( result ) {
                    var state = stateRef.current;
                    ajaxPost( 'therius_blocks_finalize', {
                        attempt_id: state.attemptId,
                        therius_finalize_payment_code: result.paymentCode,
                    } ).then( function() {
                        if ( state.resolveOutcome ) state.resolveOutcome();
                    } ).catch( function( err ) {
                        if ( state.rejectOutcome ) state.rejectOutcome( err );
                    } );
                },
            } );

            widget.mount( containerRef.current );

            var unsubscribe = eventRegistration.onPaymentSetup( function() {
                return new Promise( function( resolve ) {
                    var state = stateRef.current;
                    state.attemptId = generateAttemptId();
                    state.resolveOutcome = function() {
                        state.resolveOutcome = null;
                        state.rejectOutcome = null;
                        resolve( {
                            type: emitResponse.responseTypes.SUCCESS,
                            meta: {
                                paymentMethodData: {
                                    therius_blocks_attempt_id: state.attemptId,
                                },
                            },
                        } );
                    };
                    state.rejectOutcome = function( err ) {
                        state.resolveOutcome = null;
                        state.rejectOutcome = null;
                        resolve( {
                            type: emitResponse.responseTypes.ERROR,
                            message: ( err && err.message ) || 'Payment failed. Please try again.',
                        } );
                    };
                    widget.submit();
                } );
            } );

            return function() {
                unsubscribe();
                widget.destroy();
            };
        }, [] );

        return el( 'div', { id: 'therius-blocks-payment-element', ref: containerRef } );
    }

    function Label() {
        return el( 'span', null, label );
    }

    wc.wcBlocksRegistry.registerPaymentMethod( {
        name: 'therius',
        label: el( Label ),
        content: el( Content ),
        edit: el( Content ),
        canMakePayment: function() { return true; },
        ariaLabel: label,
        supports: {
            features: [ 'products' ],
        },
    } );
} )( window.wp, window.wc );
