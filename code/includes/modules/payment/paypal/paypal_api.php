<?php
/**
 * PayPal Create Order API Endpoint for SDK v6
 *
 * This endpoint creates a PayPal order for the JavaScript SDK v6 integration.
 * It replicates all the preparation work from Phase 1 (ec_step1) to ensure
 * compatibility with the existing payment completion flow.
 *
 * Call: POST /api/paypal/create-order
 * Response: JSON with orderId, amount, and currencyCode
 */

// Set JSON response header
header('Content-Type: application/json');

// Load Zen Cart application (using relative path to avoid path issues)
require_once('../../includes/application_top.php');

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Validate cart is not empty
if (!isset($_SESSION['cart']) || $_SESSION['cart']->count_contents() == 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Cart is empty', 'code' => 'CART_EMPTY']);
    exit;
}

// Stock validation
if (STOCK_CHECK == 'true' && STOCK_ALLOW_CHECKOUT != 'true') {
    $products = $_SESSION['cart']->get_products();
    foreach ($products as $product) {
        $qtyAvailable = zen_get_products_stock($product['id']);
        if ($qtyAvailable - $product['quantity'] < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Product out of stock', 'code' => 'OUT_OF_STOCK']);
            exit;
        }
    }
}

// Validate cart ID to prevent session manipulation
if (isset($_SESSION['cartID']) && $_SESSION['cartID'] != $_SESSION['cart']->cartID) {
    http_response_code(400);
    echo json_encode(['error' => 'Cart validation failed', 'code' => 'CART_VALIDATION_FAILED']);
    exit;
}

try {
    // Load order object
    require_once(DIR_WS_CLASSES . 'order.php');
    $order = new order();

    // Load shipping module
    require_once(DIR_WS_CLASSES . 'shipping.php');
    $shipping_modules = new shipping(isset($_SESSION['shipping']) ? $_SESSION['shipping'] : array());

    // Load order total modules
    require_once(DIR_WS_CLASSES . 'order_total.php');
    $order_total_modules = new order_total();
    $order_totals = $order_total_modules->pre_confirmation_check();
    $order_totals = $order_total_modules->process();

    // Initialize PayPal payment module
    require_once(DIR_WS_MODULES . 'payment/paypalwpp.php');
    $paypalwpp = new paypalwpp();
    $doPayPal = $paypalwpp->paypal_init();

    // Get currency code
    $currency_code = $paypalwpp->selectCurrency($order->info['currency']);

    // Build order line item details
    $options = $paypalwpp->getLineItemDetails($currency_code);

    // CRITICAL: Explicitly add currency code (getLineItemDetails does NOT include it)
    // This is a ZenCart-specific pitfall that must be addressed
    $options['PAYMENTREQUEST_0_CURRENCYCODE'] = $currency_code;

    // Set return and cancel URLs
    $returnUrl = zen_href_link('ipn_main_handler.php', 'type=ec', 'SSL');
    $cancelUrl = zen_href_link(FILENAME_SHOPPING_CART, '', 'SSL');

    // Call PayPal API to create order
    $response = $doPayPal->SetExpressCheckout($returnUrl, $cancelUrl, $options);

    // Check for API errors
    if ($response['ACK'] == 'Failure') {
        $errorMessage = isset($response['L_LONGMESSAGE0']) ? $response['L_LONGMESSAGE0'] : 'PayPal API error';
        $errorCode = isset($response['L_ERRORCODE0']) ? $response['L_ERRORCODE0'] : 'UNKNOWN_ERROR';

        http_response_code(500);
        echo json_encode([
            'error' => $errorMessage,
            'code' => $errorCode
        ]);
        exit;
    }

    // Extract PayPal Order ID (TOKEN)
    if (!isset($response['TOKEN'])) {
        http_response_code(500);
        echo json_encode([
            'error' => 'PayPal did not return order ID',
            'code' => 'NO_ORDER_ID'
        ]);
        exit;
    }

    // Sanitize and store token in session (for Phase 3 compatibility)
    $paypalOrderId = preg_replace('/[^0-9.A-Z\-]/', '', urldecode($response['TOKEN']));
    $_SESSION['paypal_ec_token'] = $paypalOrderId;
    $_SESSION['payment'] = 'paypalwpp';

    // Extract amount and currency from response
    $amount = isset($response['PAYMENTREQUEST_0_AMT']) ? $response['PAYMENTREQUEST_0_AMT'] : '0.00';
    $currency = isset($response['PAYMENTREQUEST_0_CURRENCYCODE']) ? $response['PAYMENTREQUEST_0_CURRENCYCODE'] : 'USD';

    // Return JSON response for SDK v6
    echo json_encode([
        'orderId' => $paypalOrderId,
        'amount' => $amount,
        'currencyCode' => $currency
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ]);
}
