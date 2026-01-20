<?php
/**
 * PayPal Client Token Endpoint for JavaScript SDK v6
 *
 * This endpoint provides a browser-safe client token for initializing
 * the PayPal JavaScript SDK v6 on the frontend.
 *
 * The client token is domain-bound and expires after 15 minutes.
 */

// Initialize Zen Cart application
require('includes/application_top.php');

// Set JSON response header
header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Initialize PayPal REST config
require_once(DIR_FS_CATALOG . DIR_WS_MODULES . 'payment/paypal/paypal_rest_config.php');

try {
    $paypalConfig = new paypal_rest_config();

    // Generate client token
    $clientToken = $paypalConfig->getClientToken();

    if ($clientToken) {
        // Return the client token to the frontend
        echo json_encode([
            'accessToken' => $clientToken,
            'environment' => $paypalConfig->getEnvironment()
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to generate client token']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
