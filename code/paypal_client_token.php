<?php
/**
 * PayPal Client Token API Endpoint for JavaScript SDK v6
 *
 * This endpoint returns a browser-safe client token for initializing the PayPal JavaScript SDK v6.
 * The client token is domain-bound and expires after 15 minutes.
 *
 * Usage: Fetch this endpoint from the frontend to get the client token:
 * fetch('/paypal_client_token.php')
 *   .then(res => res.json())
 *   .then(data => paypal.createInstance({ clientToken: data.clientToken }))
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

// Load Zen Cart application
require('includes/application_top.php');

// Initialize PayPal REST configuration
require_once(DIR_WS_MODULES . 'payment/paypal/paypal_rest_config.php');

$config_params = array(
    'client_id' => PAYPAL_CLIENT_ID,
    'secret' => PAYPAL_SECRET,
    'env' => PAYPAL_ENV
);

$paypal_config = new paypal_rest_config($config_params);

// Get client token
$clientToken = $paypal_config->getClientToken();

if ($clientToken) {
    // Return client token to frontend
    echo json_encode([
        'clientToken' => $clientToken
    ]);
} else {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to generate client token'
    ]);
}
?>
