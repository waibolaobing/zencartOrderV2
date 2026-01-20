/**
 * PayPal JavaScript SDK v6 Integration with Pay Later Support
 *
 * This script dynamically loads and initializes the PayPal SDK v6
 * with Pay Later button and promotional messages.
 */

(function() {
    'use strict';

    // Configuration
    var PAYPAL_SDK_URL = 'https://www.sandbox.paypal.com/web-sdk/v6/core';
    var CLIENT_TOKEN_ENDPOINT = '/paypal_client_token.php';
    var CREATE_ORDER_ENDPOINT = '/api/paypal/create-order.php';

    /**
     * Get client token from backend endpoint
     */
    async function getBrowserSafeClientToken() {
        try {
            var response = await fetch(CLIENT_TOKEN_ENDPOINT, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to fetch client token: ' + response.status);
            }

            var data = await response.json();
            return data.accessToken;
        } catch (error) {
            console.error('[PayPal] Error fetching client token:', error);
            throw error;
        }
    }

    /**
     * Create PayPal order via backend API
     * Based on .paypal-order-flow.md Implementation Details
     */
    async function createOrder() {
        try {
            var response = await fetch(CREATE_ORDER_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to create order: ' + response.status);
            }

            var data = await response.json();
            console.log('[PayPal] Order created:', data.orderId);
            return { orderId: data.orderId };
        } catch (error) {
            console.error('[PayPal] Error creating order:', error);
            throw error;
        }
    }

    /**
     * Setup PayPal Messages component
     */
    function setupPayPalMessages(sdkInstance) {
        console.log('[PayPal] Setting up PayPal Messages');

        // Call SDK to register PayPal Messages web components
        sdkInstance.createPayPalMessages();

        // Create PayPal Message element with current cart total
        var paypalMessage = document.createElement('paypal-message');
        paypalMessage.id = 'paypal-message';
        paypalMessage.setAttribute('auto-bootstrap', '');
        paypalMessage.setAttribute('amount', '0.00'); // Will be updated dynamically
        paypalMessage.setAttribute('currency-code', 'USD');

        // Create message container
        var messagesContainer = document.createElement('div');
        messagesContainer.className = 'paypal-messages-container';
        messagesContainer.appendChild(paypalMessage);

        // Find PayPal Express Checkout button container
        var paypalButtonContainer = document.getElementById('PPECbutton');
        if (paypalButtonContainer && paypalButtonContainer.parentNode) {
            paypalButtonContainer.parentNode.appendChild(messagesContainer);
            console.log('[PayPal] PayPal Messages added to page');
        } else {
            console.warn('[PayPal] Could not find PayPal button container for messages');
        }

        return paypalMessage;
    }

    /**
     * Payment handler for Pay Later button
     */
    async function onClickPayLater(paylaterPaymentSession) {
        console.log('[PayPal] Pay Later payment initiated');

        try {
            // Get the promise reference by invoking createOrder()
            // Do NOT await this async function to avoid transient activation issues
            var createOrderPromise = createOrder();

            await paylaterPaymentSession.start(
                { presentationMode: 'auto' },
                createOrderPromise
            );
        } catch (error) {
            console.error('[PayPal] Pay Later payment error:', error);
            alert('Payment processing failed. Please try again.');
        }
    }

    /**
     * Setup Pay Later button with payment session
     */
    async function setupPayLaterButton(sdkInstance, paylaterPaymentMethodDetails) {
        console.log('[PayPal] Setting up Pay Later button');

        // Payment session callbacks based on .paypal-order-flow.md
        var paymentSessionOptions = {
            // SDK v6 onApprove callback parameters: { orderId, payerId } (camelCase with lowercase 'd')
            async onApprove(data) {
                console.log('[PayPal] Payment approved:', data);
                // Redirect to existing Phase 3 entry point
                // Using exact implementation from .paypal-order-flow.md
                window.location.href = '/ipn_main_handler.php?type=ec&token=' + data.orderId + '&PayerID=' + data.payerId;
            },
            onCancel(data) {
                console.log('[PayPal] Payment cancelled:', data);
                alert('Payment was cancelled. Please try again.');
            },
            onError(error) {
                console.error('[PayPal] Payment error:', error);
                alert('An error occurred during payment processing. Please try again.');
            }
        };

        // Create pay later one-time payment session
        var paylaterPaymentSession = sdkInstance.createPayLaterOneTimePaymentSession(paymentSessionOptions);

        // Create Pay Later button component
        var productCode = paylaterPaymentMethodDetails.productCode;
        var countryCode = paylaterPaymentMethodDetails.countryCode;

        var paylaterButton = document.createElement('paypal-pay-later-button');
        paylaterButton.id = 'paylater-button';
        paylaterButton.productCode = productCode;
        paylaterButton.countryCode = countryCode;
        paylaterButton.addEventListener('click', function() {
            onClickPayLater(paylaterPaymentSession);
        });

        // Find PayPal Express Checkout button container
        var paypalButtonContainer = document.getElementById('PPECbutton');
        if (paypalButtonContainer && paypalButtonContainer.parentNode) {
            // Insert Pay Later button before the existing Express Checkout button
            paypalButtonContainer.parentNode.insertBefore(paylaterButton, paypalButtonContainer);
            console.log('[PayPal] Pay Later button added to page');
        } else {
            console.warn('[PayPal] Could not find PayPal button container for Pay Later button');
        }
    }

    /**
     * Initialize PayPal SDK v6 with Pay Later
     */
    async function initializePayPalSDK() {
        try {
            console.log('[PayPal] Initializing SDK');

            // Get client token
            var clientToken = await getBrowserSafeClientToken();
            console.log('[PayPal] Client token received');

            // Create SDK instance with paypal-messages component
            var sdkInstance = await window.paypal.createInstance({
                clientToken: clientToken,
                components: ['paypal-payments', 'paypal-messages'],
                pageType: 'cart',
                locale: 'en-US',
                testBuyerCountry: 'US' // For consistent testing during development
            });

            console.log('[PayPal] SDK instance created');

            // Find all eligible payment methods
            var paymentMethods = await sdkInstance.findEligibleMethods();
            console.log('[PayPal] Payment methods checked:', paymentMethods);

            // Setup Pay Later if eligible
            if (paymentMethods.isEligible('paylater')) {
                console.log('[PayPal] Pay Later is eligible');
                var paylaterDetails = paymentMethods.getDetails('paylater');
                await setupPayLaterButton(sdkInstance, paylaterDetails);
                setupPayPalMessages(sdkInstance);
            } else {
                console.log('[PayPal] Pay Later is not eligible');
            }

            // Store SDK instance globally
            window.paypalSdk = sdkInstance;
            window.paypalSdkReady = true;

            // Dispatch custom event
            var event = new CustomEvent('paypalSdkReady');
            window.dispatchEvent(event);

            console.log('[PayPal] SDK initialization complete');

            return sdkInstance;
        } catch (error) {
            console.error('[PayPal] SDK initialization failed:', error);
            window.paypalSdkReady = false;
            throw error;
        }
    }

    /**
     * Load PayPal SDK script dynamically
     */
    function loadPayPalSDK(callback, errorCallback) {
        var script = document.createElement('script');
        script.src = PAYPAL_SDK_URL;
        script.async = true;

        script.onload = function() {
            console.log('[PayPal] SDK script loaded successfully');
            if (typeof callback === 'function') {
                callback();
            }
        };

        script.onerror = function() {
            console.error('[PayPal] Failed to load SDK script');
            if (typeof errorCallback === 'function') {
                errorCallback();
            }
        };

        document.head.appendChild(script);
    }

    /**
     * Main initialization flow
     */
    async function init() {
        try {
            // Load SDK script
            loadPayPalSDK(
                // Success callback
                async function() {
                    try {
                        await initializePayPalSDK();
                    } catch (error) {
                        console.error('[PayPal] Initialization failed:', error);
                    }
                },
                // Error callback
                function() {
                    console.error('[PayPal] SDK script loading failed');
                    window.paypalSdkReady = false;
                }
            );
        } catch (error) {
            console.error('[PayPal] Initialization error:', error);
            window.paypalSdkReady = false;
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
