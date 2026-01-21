/**
 * PayPal Pay Later Integration for Zen Cart
 *
 * This file implements the PayPal Web SDK v6 PayLater integration for Zen Cart.
 * It includes SDK initialization, eligibility checking, button rendering, and payment flow.
 */

// Helper: Get client token from backend endpoint
const getBrowserSafeClientToken = async () => {
  const response = await fetch('/paypal_client_token.php');
  if (!response.ok) {
    throw new Error('Failed to get client token');
  }

  // Get response text first to check if it's valid JSON
  const responseText = await response.text();

  if (!responseText || responseText.trim() === '') {
    console.error('[PayPal] Empty response from client token API');
    throw new Error('Empty response from client token API. Please check PHP error logs.');
  }

  try {
    const data = JSON.parse(responseText);
    return data.clientToken;
  } catch (parseError) {
    console.error('[PayPal] Failed to parse client token JSON:', responseText.substring(0, 200));
    throw new Error('Invalid JSON response from client token API. Please check PHP error logs.');
  }
};

// Helper: Create PayPal order via backend API
const createOrder = async () => {
  const response = await fetch('/api/paypal/create_order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.error || 'Failed to create PayPal order');
  }

  // Get response text first to check if it's valid JSON
  const responseText = await response.text();

  if (!responseText || responseText.trim() === '') {
    console.error('[PayPal] Empty response from create_order API');
    throw new Error('Empty response from server. Please check PHP error logs.');
  }

  try {
    const data = JSON.parse(responseText);
    // SDK v6 requires object format with orderId property
    return { orderId: data.orderId };
  } catch (parseError) {
    console.error('[PayPal] Failed to parse JSON response:', responseText.substring(0, 200));
    throw new Error('Invalid JSON response from server. Please check PHP error logs.');
  }
};

// Helper: Get current order amount from cart page
const getOrderAmount = () => {
  // Try to get amount from cart total display
  const cartTotalElement = document.querySelector('#cartSubTotal, .cartTotal, [data-cart-total]');
  if (cartTotalElement) {
    // Extract numeric amount (format: "$25.00" or "25.00")
    const amountText = cartTotalElement.textContent.trim().replace(/[^0-9.]/g, '');
    if (amountText) {
      return {
        amount: amountText,
        currencyCode: 'USD' // TODO: Make dynamic based on store currency
      };
    }
  }

  // Fallback to default value
  return {
    amount: '0.00',
    currencyCode: 'USD'
  };
};

// Payment session options with callbacks from .paypal-order-flow.md
const paymentSessionOptions = {
  // SDK v6 onApprove callback parameters: { orderId, payerId } (camelCase with lowercase 'd')
  async onApprove(data) {
    console.log('[PayPal] onApprove', data);
    // Redirect to existing Phase 3 entry point as per Implementation Details
    window.location.href = `/ipn_main_handler.php?type=ec&token=${data.orderId}&PayerID=${data.payerId}`;
  },
  onCancel(data) {
    console.log('[PayPal] onCancel', data);
    // Redirect to shopping cart with cancel flag
    window.location.href = '/index.php?main_page=shopping_cart&ec_cancel=1';
  },
  onError(error) {
    console.error('[PayPal] onError', error);
    alert('Payment error occurred. Please try again or contact support.');
  }
};

// Payment handler: Start PayLater payment session
const onClickPayLater = async (paylaterPaymentSession) => {
  console.log('[PayPal] PayLater payment initiated');

  try {
    // Get the promise reference by invoking createOrder(): Promise<{orderId: string}>
    // Do NOT await this async function - it can cause transient activation issues
    const createOrderPromise = createOrder();
    await paylaterPaymentSession.start(
      { presentationMode: 'auto' },
      createOrderPromise
    );
  } catch (error) {
    console.error('[PayPal] PayLater payment error', error);
    alert('Payment failed. Please try again.');
  }
};

// Helper: Setup PayPal Messages component
const setupPayPalMessages = (sdkInstance) => {
  console.log('[PayPal] Setting up PayPal Messages');

  // Call SDK to register PayPal Messages web components
  sdkInstance.createPayPalMessages();

  // Get the current order amount
  const { amount, currencyCode } = getOrderAmount();

  // Create PayPal Message element
  const paypalMessage = document.createElement('paypal-message');
  paypalMessage.id = 'paypal-message';
  paypalMessage.setAttribute('auto-bootstrap', '');
  paypalMessage.setAttribute('amount', amount);
  paypalMessage.setAttribute('currency-code', currencyCode);
  paypalMessage.setAttribute('data-pp-style-layout', 'text');
  paypalMessage.setAttribute('data-pp-style-logo-type', 'primary');
  paypalMessage.setAttribute('data-pp-style-text-color', 'black');

  // Create message container
  const messagesContainer = document.createElement('div');
  messagesContainer.className = 'paypal-messages-container';
  messagesContainer.appendChild(paypalMessage);

  // Find PayPal button container (existing PPEC button location)
  const paypalButtonContainer = document.querySelector('#PPECbutton');
  if (paypalButtonContainer) {
    // Insert messages after the button container
    paypalButtonContainer.parentNode.insertBefore(messagesContainer, paypalButtonContainer.nextSibling);
    console.log('[PayPal] PayPal Messages rendered');
  } else {
    console.warn('[PayPal] PayPal button container not found');
  }
};

// Helper: Setup PayLater button
const setupPayLaterButton = async (sdkInstance, paylaterPaymentMethodDetails) => {
  console.log('[PayPal] Setting up PayLater button');

  // Create PayLater one-time payment session
  const paylaterPaymentSession = sdkInstance.createPayLaterOneTimePaymentSession(paymentSessionOptions);

  // Create PayLater button component
  const { productCode, countryCode } = paylaterPaymentMethodDetails;
  const paylaterButton = document.createElement('paypal-pay-later-button');
  paylaterButton.id = 'paylater-button';
  paylaterButton.productCode = productCode;
  paylaterButton.countryCode = countryCode;
  paylaterButton.addEventListener('click', () => onClickPayLater(paylaterPaymentSession));

  // Find PayPal button container (existing PPEC button location)
  const paypalButtonContainer = document.querySelector('#PPECbutton');
  if (paypalButtonContainer) {
    // Insert PayLater button after existing PayPal button
    paypalButtonContainer.parentNode.insertBefore(paylaterButton, paypalButtonContainer.nextSibling);
    console.log('[PayPal] PayLater button rendered');
  } else {
    console.warn('[PayPal] PayPal button container not found');
  }
};

// Main: Initialize PayPal SDK
const initializePayPalSDK = async () => {
  try {
    console.log('[PayPal] Getting client token...');
    const clientToken = await getBrowserSafeClientToken();
    console.log('[PayPal] Client token received');

    // Create SDK instance
    const sdkInstance = await window.paypal.createInstance({
      clientToken,
      components: ['paypal-payments', 'paypal-messages'],
      pageType: 'cart', // Shopping cart page
      testBuyerCountry: 'US', // KEEP: During development for consistent testing
    });
    console.log('[PayPal] SDK instance created');

    // Find all eligible payment methods
    const paymentMethods = await sdkInstance.findEligibleMethods();
    console.log('[PayPal] Eligible payment methods:', paymentMethods);

    // Setup PayLater if eligible
    if (paymentMethods.isEligible('paylater')) {
      console.log('[PayPal] PayLater is eligible');
      const paylaterDetails = paymentMethods.getDetails('paylater');
      await setupPayLaterButton(sdkInstance, paylaterDetails);
      setupPayPalMessages(sdkInstance);
    } else {
      console.log('[PayPal] PayLater is not eligible for this merchant/region');
    }

    // Future: Setup other payment methods
    // if (paymentMethods.isEligible('card')) {
    //   await setupCard(sdkInstance, paymentMethods.getDetails('card'));
    // }

    return sdkInstance;
  } catch (error) {
    console.error('[PayPal] SDK initialization failed:', error);
    throw error;
  }
};

// SDK initialization callback (called when SDK script loads)
const onPayPalWebSdkLoaded = async () => {
  console.log('[PayPal] PayPal Web SDK loaded');

  try {
    await initializePayPalSDK();
    console.log('[PayPal] PayLater integration complete');
  } catch (error) {
    console.error('[PayPal] Failed to initialize PayLater:', error);
    // Optionally show error message to user
  }
};

// Dynamic SDK script loading
(function loadPayPalSDK() {
  console.log('[PayPal] Loading PayPal Web SDK v6...');

  // Create script element
  const script = document.createElement('script');
  script.src = 'https://www.sandbox.paypal.com/web-sdk/v6/core';
  script.async = true;
  script.onload = onPayPalWebSdkLoaded;
  script.onerror = () => {
    console.error('[PayPal] Failed to load PayPal SDK script');
  };

  // Add script to page
  document.head.appendChild(script);
})();
