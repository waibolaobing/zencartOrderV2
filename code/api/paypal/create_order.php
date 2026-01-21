<?php
/**
 * PayPal Create Order API Endpoint for JavaScript SDK v6
 *
 * This endpoint creates a PayPal order for the Web SDK v6 integration.
 * It replicates the preparation work from ec_step1() but returns JSON instead of redirecting.
 *
 * Endpoint: POST /api/paypal/create_order.php
 * Response: { orderId: "...", amount: "...", currencyCode: "..." }
 *
 * IMPORTANT: This endpoint serves ALL PayPal Web SDK v6 payment methods (PayPal, Pay Later, Venmo)
 */

// Set JSON response header
header('Content-Type: application/json');

// Disable error display (log only instead)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Global error handler to catch fatal errors only (not warnings/notices)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Only handle fatal errors and recoverable errors, not warnings/notices
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR ||
        $errno === E_COMPILE_ERROR || $errno === E_USER_ERROR ||
        $errno === E_RECOVERABLE_ERROR) {

        http_response_code(500);
        echo json_encode([
            'error' => 'PHP Error',
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'errno' => $errno
        ]);
        exit;
    }

    // For warnings/notices, return false to continue with standard PHP error handling
    return false;
});

// Global exception handler for uncaught exceptions
set_exception_handler(function($exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Uncaught Exception',
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    exit;
});

// Load Zen Cart application (use require_once to avoid class redeclaration)
require_once('../../includes/application_top.php');

// Initialize PayPal REST configuration
require_once(DIR_WS_MODULES . 'payment/paypal/paypal_rest_config.php');

try {
    // 1. Validate cart (replicate ec_step1 lines 1521-1525)
    if (!isset($_SESSION['cart']) || $_SESSION['cart']->count_contents() == 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Cart is empty']);
        exit;
    }

    // 2. Initialize payment module and get paypalwpp instance
    if (!class_exists('paypalwpp')) {
        // Load payment class if not already loaded
        require_once(DIR_WS_CLASSES . 'payment.php');
    }

    // Create paypalwpp instance
    $payment_modules = new payment('paypalwpp');
    $paypalwpp = $payment_modules->payment;

    // 3. Initialize order object (replicate ec_step1 lines 1528-1540)
    require_once(DIR_WS_CLASSES . 'order.php');
    $order = new order;

    require_once(DIR_WS_CLASSES . 'order_total.php');
    $order_total_modules = new order_total;
    $order_totals = $order_total_modules->process();

    // 4. Build PayPal order details (replicate ec_step1 lines 1546-1571)
    $currency_code = $paypalwpp->selectCurrency();
    $options = $paypalwpp->getLineItemDetails($currency_code);

    // CRITICAL: Explicitly add currency code (getLineItemDetails does NOT include it)
    // See common pitfalls documentation
    $options['PAYMENTREQUEST_0_CURRENCYCODE'] = $currency_code;

    // Calculate order amount
    $order_amount = $paypalwpp->calc_order_amount($order->info['total'], $currency_code);
    $options['PAYMENTREQUEST_0_AMT'] = round($order_amount, 2);

    // 5. Set return URLs (replicate ec_step1 lines 1590-1592)
    $return_url = zen_href_link('ipn_main_handler.php', 'type=ec', 'SSL', true, true, true);
    $cancel_url = zen_href_link(FILENAME_SHOPPING_CART, 'ec_cancel=1', 'SSL');

    // 6. Call PayPal API via SetExpressCheckout (replicate ec_step1 line 1666)
    $doPayPal = $paypalwpp->paypal_init();
    $response = $doPayPal->SetExpressCheckout($return_url, $cancel_url, $options);

    if (!$response || isset($response['ERRORCODE'])) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to create PayPal order',
            'details' => $response
        ]);
        exit;
    }

    // 7. Store token in session (CRITICAL for Phase 3 - replicate ec_step1 line 1726)
    $_SESSION['paypal_ec_token'] = preg_replace('/[^0-9.A-Z\-]/', '', urldecode($response['TOKEN']));

    // 8. Return JSON response (NOT redirect!)
    echo json_encode([
        'orderId' => $_SESSION['paypal_ec_token'],
        'amount' => (string)$order_amount,
        'currencyCode' => $currency_code
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Exception occurred',
        'message' => $e->getMessage()
    ]);
}
?>
