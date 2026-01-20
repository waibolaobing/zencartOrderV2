<?php
/**
 * PayPal Create Order API Endpoint
 *
 * Public endpoint for creating PayPal orders via SDK v6.
 * This file delegates to the actual implementation in the payment module.
 *
 * URL: POST /api/paypal/create-order.php
 */

// Load the implementation
require_once('../../includes/modules/payment/paypal/paypal_api.php');
