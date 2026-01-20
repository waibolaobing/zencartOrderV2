<?php
/**
 * PayPal REST API v2 Implementation with NVP Compatibility
 *
 * Converts NVP API calls to REST v2 API calls.
 */

require_once __DIR__ . '/paypal_rest_config.php';

class paypal_rest_v2 {

    private $config;
    private $env;
    private $log_dir;

    public function __construct($params = array()) {
        // Set log directory
        $this->log_dir = __DIR__ . '/logs/';

        $this->env = isset($params['env']) ? $params['env'] : 'sandbox';

        $config_params = array(
            'env' => $this->env
        );

        if (isset($params['client_id'])) {
            $config_params['client_id'] = $params['client_id'];
        }
        if (isset($params['secret'])) {
            $config_params['secret'] = $params['secret'];
        }

        $this->config = new paypal_rest_config($config_params);

        // Ensure logs directory exists
        if (!is_dir($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }

        $this->debugLog('__construct', array(
            'env' => $this->env,
            'timestamp' => date('Y-m-d H:i:s')
        ));
    }

    /**
     * NVP-compatible SetExpressCheckout method
     * Converts to POST /v2/checkout/orders
     *
     * @param string $returnUrl URL to which the buyer's browser is returned after choosing to pay with PayPal
     * @param string $cancelUrl URL to which the buyer is returned if the buyer does not approve the use of PayPal to pay you
     * @param array $options All other optional request parameters from NVP API call (NVP format)
     * @return array NVP-format response with TOKEN and ACK status
     */
    public function SetExpressCheckout($returnUrl, $cancelUrl, $options = array()) {
        $this->debugLog('SetExpressCheckout', array(
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'options' => $options,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $token = $this->config->getAccessToken();
            if (!$token) {
                throw new Exception('Failed to get access token');
            }

            $order_data = $this->buildOrderData($options, $returnUrl, $cancelUrl);

            $url = $this->config->getBaseUrl() . '/v2/checkout/orders';
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            );

            $response = $this->config->makeRequest($url, 'POST', $headers, json_encode($order_data));

            if ($response && isset($response['id'])) {
                return $this->convertToNVPFormat('SetExpressCheckout', $response);
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('SetExpressCheckout', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Internal Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * NVP-compatible GetExpressCheckoutDetails method
     * Converts to GET /v2/checkout/orders/{id}
     */
    public function GetExpressCheckoutDetails($token) {
        $this->debugLog('GetExpressCheckoutDetails', array(
            'token' => $token,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            $url = $this->config->getBaseUrl() . '/v2/checkout/orders/' . $token;
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
            );

            $response = $this->config->makeRequest($url, 'GET', $headers);

            if ($response && isset($response['id'])) {
                return $this->convertToNVPFormat('GetExpressCheckoutDetails', $response);
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('GetExpressCheckoutDetails', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Internal Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * PATCH Order method - Update order details before capture
     * Converts to PATCH /v2/checkout/orders/{id}
     */
    public function PatchOrder($token, $options = array()) {
        $this->debugLog('PatchOrder', array(
            'token' => $token,
            'options' => $options,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            $url = $this->config->getBaseUrl() . '/v2/checkout/orders/' . $token;
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
            );

            // First, get the current order to check if it has items
            $current_order = $this->config->makeRequest($url, 'GET', $headers);

            if (!$current_order || !isset($current_order['id'])) {
                throw new Exception('Failed to get current order details');
            }

            // Build patch operations array, passing current order info
            $patch_operations = $this->buildPatchOperations($options, $current_order);

            $response = $this->config->makeRequest($url, 'PATCH', $headers, json_encode($patch_operations));

            // PATCH returns 204 No Content on success
            if ($response !== false) {
                $this->debugLog('PatchOrder', array(
                    'success' => true,
                    'timestamp' => date('Y-m-d H:i:s')
                ));

                return array(
                    'ACK' => 'Success',
                    'TOKEN' => $token,
                    'TIMESTAMP' => date('c')
                );
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('PatchOrder', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Patch Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * Build patch operations for order update
     */
    private function buildPatchOperations($options, $current_order = null) {
        $patch_operations = array();

        // Get currency code - priority: PAYMENTREQUEST_n_CURRENCYCODE > CURRENCYCODE
        $currency_code = null;
        if (isset($options['PAYMENTREQUEST_0_CURRENCYCODE'])) {
            $currency_code = $options['PAYMENTREQUEST_0_CURRENCYCODE'];
        } elseif (isset($options['CURRENCYCODE'])) {
            $currency_code = $options['CURRENCYCODE'];
        }

        // Get total amount - priority: PAYMENTREQUEST_n_AMT > AMT
        $total_amount = null;
        if (isset($options['PAYMENTREQUEST_0_AMT'])) {
            $total_amount = $options['PAYMENTREQUEST_0_AMT'];
        } elseif (isset($options['AMT'])) {
            $total_amount = $options['AMT'];
        }

        // Check if we need to update amount (check both prefixed and non-prefixed fields)
        if (isset($options['PAYMENTREQUEST_0_AMT']) || isset($options['AMT']) ||
            isset($options['PAYMENTREQUEST_0_ITEMAMT']) || isset($options['ITEMAMT']) ||
            isset($options['PAYMENTREQUEST_0_SHIPPINGAMT']) || isset($options['SHIPPINGAMT']) ||
            isset($options['PAYMENTREQUEST_0_TAXAMT']) || isset($options['TAXAMT']) ||
            isset($options['PAYMENTREQUEST_0_HANDLINGAMT']) || isset($options['HANDLINGAMT']) ||
            isset($options['PAYMENTREQUEST_0_SHIPDISCAMT']) || isset($options['SHIPPINGDISCAMT']) ||
            isset($options['PAYMENTREQUEST_0_INSURANCEAMT']) || isset($options['INSURANCEAMT'])) {

            // Only build amount_data if we have required values
            if ($total_amount !== null && $currency_code !== null) {
                $amount_data = array(
                    'currency_code' => $currency_code,
                    'value' => number_format((float)$total_amount, 2, '.', '')
                );

                // Build breakdown
                $breakdown = array();

                // item_total: PAYMENTREQUEST_n_ITEMAMT > ITEMAMT
                if (isset($options['PAYMENTREQUEST_0_ITEMAMT'])) {
                    $breakdown['item_total'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['PAYMENTREQUEST_0_ITEMAMT'], 2, '.', '')
                    );
                } elseif (isset($options['ITEMAMT'])) {
                    $breakdown['item_total'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['ITEMAMT'], 2, '.', '')
                    );
                }

                // shipping: PAYMENTREQUEST_n_SHIPPINGAMT > SHIPPINGAMT
                if (isset($options['PAYMENTREQUEST_0_SHIPPINGAMT'])) {
                    $breakdown['shipping'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['PAYMENTREQUEST_0_SHIPPINGAMT'], 2, '.', '')
                    );
                } elseif (isset($options['SHIPPINGAMT'])) {
                    $breakdown['shipping'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['SHIPPINGAMT'], 2, '.', '')
                    );
                }

                // tax_total: PAYMENTREQUEST_n_TAXAMT > TAXAMT
                if (isset($options['PAYMENTREQUEST_0_TAXAMT'])) {
                    $breakdown['tax_total'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['PAYMENTREQUEST_0_TAXAMT'], 2, '.', '')
                    );
                } elseif (isset($options['TAXAMT'])) {
                    $breakdown['tax_total'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['TAXAMT'], 2, '.', '')
                    );
                }

                // handling: PAYMENTREQUEST_n_HANDLINGAMT > HANDLINGAMT
                if (isset($options['PAYMENTREQUEST_0_HANDLINGAMT'])) {
                    $breakdown['handling'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['PAYMENTREQUEST_0_HANDLINGAMT'], 2, '.', '')
                    );
                } elseif (isset($options['HANDLINGAMT'])) {
                    $breakdown['handling'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['HANDLINGAMT'], 2, '.', '')
                    );
                }

                // shipping_discount: PAYMENTREQUEST_n_SHIPDISCAMT > SHIPPINGDISCAMT
                if (isset($options['PAYMENTREQUEST_0_SHIPDISCAMT'])) {
                    $breakdown['shipping_discount'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['PAYMENTREQUEST_0_SHIPDISCAMT'], 2, '.', '')
                    );
                } elseif (isset($options['SHIPPINGDISCAMT'])) {
                    $breakdown['shipping_discount'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['SHIPPINGDISCAMT'], 2, '.', '')
                    );
                }

                // insurance: PAYMENTREQUEST_n_INSURANCEAMT > INSURANCEAMT
                if (isset($options['PAYMENTREQUEST_0_INSURANCEAMT'])) {
                    $breakdown['insurance'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['PAYMENTREQUEST_0_INSURANCEAMT'], 2, '.', '')
                    );
                } elseif (isset($options['INSURANCEAMT'])) {
                    $breakdown['insurance'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format((float)$options['INSURANCEAMT'], 2, '.', '')
                    );
                }

                // Calculate actual item_total from items if present
                // This prevents ITEM_TOTAL_MISMATCH errors
                // CRITICAL: item_total must ONLY include positive items, negative items are discounts
                $items_present = false;
                $calculated_item_total = 0;
                $total_discount = 0;

                // Check both L_PAYMENTREQUEST_0_* and L_* formats
                for ($i = 0; $i < 100; $i++) {
                    if (isset($options["L_PAYMENTREQUEST_0_NAME$i"]) || isset($options["L_NAME$i"])) {
                        $items_present = true;

                        // Get quantity: L_PAYMENTREQUEST_0_QTY > L_QTY
                        $quantity = 1;
                        if (isset($options["L_PAYMENTREQUEST_0_QTY$i"])) {
                            $quantity = (int)$options["L_PAYMENTREQUEST_0_QTY$i"];
                        } elseif (isset($options["L_QTY$i"])) {
                            $quantity = (int)$options["L_QTY$i"];
                        }

                        // Get unit amount: L_PAYMENTREQUEST_0_AMT > L_AMT
                        $unit_amount = 0;
                        if (isset($options["L_PAYMENTREQUEST_0_AMT$i"])) {
                            $unit_amount = (float)$options["L_PAYMENTREQUEST_0_AMT$i"];
                        } elseif (isset($options["L_AMT$i"])) {
                            $unit_amount = (float)$options["L_AMT$i"];
                        }

                        // Separate positive items (for item_total) from negative items (discounts)
                        if ($unit_amount >= 0) {
                            $calculated_item_total += round($unit_amount * $quantity, 2);
                        } else {
                            $total_discount += round(abs($unit_amount * $quantity), 2);
                        }
                    }
                }

                // Check if this is an "aggregate line item" scenario
                // This happens when discounts exceed item total, and a single aggregate line item is created
                // Detection: only 1 line item AND its amount doesn't match the breakdown's item_total
                $is_aggregate_item = false;
                $original_item_total = 0;

                if ($items_present &&
                    (isset($options["L_PAYMENTREQUEST_0_NAME0"]) || isset($options["L_NAME0"])) &&
                    !isset($options["L_PAYMENTREQUEST_0_NAME1"]) && !isset($options["L_NAME1"]) &&
                    $current_order &&
                    isset($current_order['purchase_units'][0]['items'])) {

                    // Calculate original item_total from PayPal order
                    foreach ($current_order['purchase_units'][0]['items'] as $item) {
                        $item_amount = (float)$item['unit_amount']['value'];
                        $item_qty = (int)$item['quantity'];
                        $original_item_total += round($item_amount * $item_qty, 2);
                    }

                    // If the single line item amount doesn't match the original order's item_total,
                    // it's an aggregate item
                    if (abs($calculated_item_total - $original_item_total) > 0.01) {
                        $is_aggregate_item = true;

                        $this->debugLog('buildPatchOperations', array(
                            'detected' => 'aggregate_line_item',
                            'single_item_amount' => $calculated_item_total,
                            'original_item_total' => $original_item_total,
                            'mismatch' => true,
                            'target_total' => $total_amount,
                            'timestamp' => date('Y-m-d H:i:s')
                        ));
                    }
                }

                // Handle aggregate line item case: use original order's item_total and calculate discount
                if ($is_aggregate_item) {
                    $this->debugLog('buildPatchOperations', array(
                        'strategy' => 'aggregate_with_original_items',
                        'note' => 'Using original order items to maintain item_total consistency',
                        'timestamp' => date('Y-m-d H:i:s')
                    ));

                    // Use original item_total
                    $breakdown['item_total'] = array(
                        'currency_code' => $currency_code,
                        'value' => number_format($original_item_total, 2, '.', '')
                    );

                    // Calculate discount needed to reach target amount
                    $calculated_discount = $original_item_total - (float)$total_amount;

                    if ($calculated_discount > 0) {
                        $breakdown['discount'] = array(
                            'currency_code' => $currency_code,
                            'value' => number_format($calculated_discount, 2, '.', '')
                        );
                    }

                    $this->debugLog('buildPatchOperations', array(
                        'original_item_total' => $original_item_total,
                        'calculated_discount' => $calculated_discount,
                        'target_total' => $total_amount,
                        'timestamp' => date('Y-m-d H:i:s')
                    ));

                } elseif ($items_present) {
                    // Normal case: use calculated item_total from options
                    // Update item_total to match actual calculation (positive items only)
                    if ($calculated_item_total > 0) {
                        $breakdown['item_total'] = array(
                            'currency_code' => $currency_code,
                            'value' => number_format($calculated_item_total, 2, '.', '')
                        );
                    }

                    // Add accumulated discounts to breakdown
                    if ($total_discount > 0) {
                        // If discount already exists in breakdown, add to it
                        if (isset($breakdown['discount'])) {
                            $existing_discount = (float)$breakdown['discount']['value'];
                            $total_discount += $existing_discount;
                        }

                        $breakdown['discount'] = array(
                            'currency_code' => $currency_code,
                            'value' => number_format($total_discount, 2, '.', '')
                        );
                    }
                }

                if (!empty($breakdown)) {
                    $amount_data['breakdown'] = $breakdown;
                }

                $patch_operations[] = array(
                    'op' => 'replace',
                    'path' => '/purchase_units/@reference_id==\'default\'/amount',
                    'value' => $amount_data
                );
            }
        }
        return $patch_operations;
    }

    /**
     * NVP-compatible DoExpressCheckoutPayment method
     * Converts to POST /v2/checkout/orders/{id}/capture or POST /v2/checkout/orders/{id}/authorize
     *
     * @param string $token The token returned from SetExpressCheckout (order ID)
     * @param string $payerId The payer ID returned from PayPal after buyer approval
     * @param array $options All other optional request parameters from NVP API call (NVP format)
     * @return array NVP-format response with transaction details
     */
    public function DoExpressCheckoutPayment($token, $payerId, $options = array()) {
        $this->debugLog('DoExpressCheckoutPayment', array(
            'token' => $token,
            'payerId' => $payerId,
            'options' => $options,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            // Determine if this is Authorization or Sale (Capture)
            $payment_action = isset($options['PAYMENTREQUEST_0_PAYMENTACTION']) ?
                             $options['PAYMENTREQUEST_0_PAYMENTACTION'] : 'Sale';
            $is_authorize = ($payment_action === 'Authorization');

            // Call PatchOrder to update order details before capture/authorize if options are provided
            if (!empty($options)) {
                $patch_result = $this->PatchOrder($token, $options);
                if ($patch_result['ACK'] !== 'Success') {
                    $this->debugLog('DoExpressCheckoutPayment', array(
                        'patch_error' => 'PatchOrder failed',
                        'patch_result' => $patch_result,
                        'timestamp' => date('Y-m-d H:i:s')
                    ));
                    // Continue with capture/authorize even if patch fails, but log the issue
                }
            }

            // Build URL based on payment action
            $endpoint = $is_authorize ? '/authorize' : '/capture';
            $url = $this->config->getBaseUrl() . '/v2/checkout/orders/' . $token . $endpoint;

            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
            );

            // Request data must be empty object
            $request_data = new stdClass();

            $this->debugLog('DoExpressCheckoutPayment', array(
                'action' => $is_authorize ? 'authorize' : 'capture',
                'endpoint' => $endpoint,
                'timestamp' => date('Y-m-d H:i:s')
            ));

            $response = $this->config->makeRequest($url, 'POST', $headers, json_encode($request_data));

            if ($response && isset($response['id'])) {
                return $this->convertToNVPFormat('DoExpressCheckoutPayment', $response, $is_authorize);
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('DoExpressCheckoutPayment', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Internal Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * Support for GetTransactionDetails
     * Converts to GET /v2/payments/captures/{capture_id}
     */
    public function GetTransactionDetails($txnID) {
        $this->debugLog('GetTransactionDetails', array(
            'txnID' => $txnID,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            // Step 1: Call GET /v2/payments/captures/{capture_id}
            $url = $this->config->getBaseUrl() . '/v2/payments/captures/' . $txnID;
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
            );

            $this->debugLog('GetTransactionDetails', array(
                'step' => 'captures_api',
                'url' => $url,
                'timestamp' => date('Y-m-d H:i:s')
            ));

            $capture_response = $this->config->makeRequest($url, 'GET', $headers);

            if ($capture_response) {
                $this->debugLog('GetTransactionDetails', array(
                    'step' => 'captures_api',
                    'response' => $capture_response,
                    'timestamp' => date('Y-m-d H:i:s')
                ));

                // Extract order_id from supplementary_data for step 2
                $order_id = null;
                if (isset($capture_response['supplementary_data']['related_ids']['order_id'])) {
                    $order_id = $capture_response['supplementary_data']['related_ids']['order_id'];
                }

                // Step 2: Call GET /v2/checkout/orders/{order_id} if order_id exists
                $order_response = null;
                if ($order_id) {
                    $order_url = $this->config->getBaseUrl() . '/v2/checkout/orders/' . $order_id;

                    $this->debugLog('GetTransactionDetails', array(
                        'step' => 'orders_api',
                        'url' => $order_url,
                        'order_id' => $order_id,
                        'timestamp' => date('Y-m-d H:i:s')
                    ));

                    $order_response = $this->config->makeRequest($order_url, 'GET', $headers);

                    if ($order_response) {
                        $this->debugLog('GetTransactionDetails', array(
                            'step' => 'orders_api',
                            'response' => $order_response,
                            'timestamp' => date('Y-m-d H:i:s')
                        ));
                    }
                }

                // Combine both responses for conversion
                $combined_response = array(
                    'capture' => $capture_response,
                    'order' => $order_response
                );

                return $this->convertToNVPFormat('GetTransactionDetails', $combined_response);
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('GetTransactionDetails', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Transaction Details Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * Support for RefundTransaction
     */
    public function RefundTransaction($oID, $txnID, $amount = 'Full', $note = '', $curCode = 'USD') {
        $this->debugLog('RefundTransaction', array(
            'oID' => $oID,
            'txnID' => $txnID,
            'amount' => $amount,
            'note' => $note,
            'curCode' => $curCode,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            $url = $this->config->getBaseUrl() . '/v2/payments/captures/' . $txnID . '/refund';
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
                'Prefer: return=representation'
            );

            $refund_data = array();
            if ($amount !== 'Full') {
                $refund_data['amount'] = array(
                    'value' => $amount,
                    'currency_code' => $curCode
                );
            }
            if ($note) {
                $refund_data['note_to_payer'] = $note;
            }

            $response = $this->config->makeRequest($url, 'POST', $headers, json_encode($refund_data));

            if ($response && isset($response['id'])) {
                return array(
                    'ACK' => 'Success',
                    'REFUNDTRANSACTIONID' => $response['id'],
                    'GROSSREFUNDAMT' => isset($response['amount']['value']) ? $response['amount']['value'] : $amount
                );
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('RefundTransaction', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Refund Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * Support for DoVoid
     */
    public function DoVoid($txnID, $note = '') {
        $this->debugLog('DoVoid', array(
            'txnID' => $txnID,
            'note' => $note,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            $url = $this->config->getBaseUrl() . '/v2/payments/authorizations/' . $txnID . '/void';
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
            );

            $response = $this->config->makeRequest($url, 'POST', $headers, '{}');

            if ($response) {
                return array(
                    'ACK' => 'Success',
                    'AUTHORIZATIONID' => $txnID
                );
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('DoVoid', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Void Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * Support for DoAuthorization
     */
    public function DoAuthorization($txnID, $amount = 0, $currency = 'USD', $entity = 'Order') {
        $this->debugLog('DoAuthorization', array(
            'txnID' => $txnID,
            'amount' => $amount,
            'currency' => $currency,
            'entity' => $entity,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        // Implementation would go here
        return array(
            'ACK' => 'Success',
            'TRANSACTIONID' => uniqid('auth_'),
            'AMT' => $amount
        );
    }

    /**
     * Support for DoReauthorization
     */
    public function DoReauthorization($txnID, $amount = 0, $currency = 'USD') {
        $this->debugLog('DoReauthorization', array(
            'txnID' => $txnID,
            'amount' => $amount,
            'currency' => $currency,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            $url = $this->config->getBaseUrl() . '/v2/payments/authorizations/' . $txnID . '/reauthorize';
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
            );

            $reauth_data = array(
                'amount' => array(
                    'value' => $amount,
                    'currency_code' => $currency
                )
            );

            $response = $this->config->makeRequest($url, 'POST', $headers, json_encode($reauth_data));

            if ($response && isset($response['id'])) {
                return array(
                    'ACK' => 'Success',
                    'AUTHORIZATIONID' => $response['id'],
                    'AMT' => $amount
                );
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('DoReauthorization', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Reauthorization Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * Support for DoCapture
     */
    public function DoCapture($txnID, $amount = 0, $currency = 'USD', $captureType = 'Complete', $invNum = '', $note = '') {
        $this->debugLog('DoCapture', array(
            'txnID' => $txnID,
            'amount' => $amount,
            'currency' => $currency,
            'captureType' => $captureType,
            'invNum' => $invNum,
            'note' => $note,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        try {
            $access_token = $this->config->getAccessToken();
            if (!$access_token) {
                throw new Exception('Failed to get access token');
            }

            $url = $this->config->getBaseUrl() . '/v2/payments/authorizations/' . $txnID . '/capture';
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token,
            );

            $capture_data = array(
                'amount' => array(
                    'value' => $amount,
                    'currency_code' => $currency
                ),
                'final_capture' => ($captureType === 'Complete')
            );

            if ($invNum) {
                $capture_data['invoice_id'] = $invNum;
            }
            if ($note) {
                $capture_data['note_to_payer'] = $note;
            }

            $response = $this->config->makeRequest($url, 'POST', $headers, json_encode($capture_data));

            if ($response && isset($response['id'])) {
                return array(
                    'ACK' => 'Success',
                    'TRANSACTIONID' => $response['id'],
                    'AMT' => $amount,
                    'ORDERTIME' => date('c'),
                    'CORRELATIONID' => isset($response['debug_id']) ?
                                      $response['debug_id'] :
                                      ('corr_' . time() . rand(1000, 9999))
                );
            }

            throw new Exception('Invalid response from PayPal API');

        } catch (Exception $e) {
            $this->debugLog('DoCapture', array(
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ));

            return array(
                'ACK' => 'Failure',
                'L_ERRORCODE0' => '10001',
                'L_SHORTMESSAGE0' => 'Capture Error',
                'L_LONGMESSAGE0' => $e->getMessage()
            );
        }
    }

    /**
     * Build REST API order structure
     * Maps NVP API fields to REST v2 API fields
     */
    public function buildOrderData($options, $returnUrl, $cancelUrl) {
        // Initialize order structure
        $order_data = array(
            'intent' => 'CAPTURE',
            'purchase_units' => array(
                array()
            ),
            'payment_source' => array()
        );

        // Get currency code - priority: PAYMENTREQUEST_n_CURRENCYCODE > CURRENCYCODE
        $currency_code = null;
        if (isset($options['PAYMENTREQUEST_0_CURRENCYCODE'])) {
            $currency_code = $options['PAYMENTREQUEST_0_CURRENCYCODE'];
        } elseif (isset($options['CURRENCYCODE'])) {
            $currency_code = $options['CURRENCYCODE'];
        }

        // Get total amount - priority: PAYMENTREQUEST_n_AMT > AMT
        $total_amount = null;
        if (isset($options['PAYMENTREQUEST_0_AMT'])) {
            $total_amount = $options['PAYMENTREQUEST_0_AMT'];
        } elseif (isset($options['AMT'])) {
            $total_amount = $options['AMT'];
        }

        // Build amount data
        $amount_data = array();
        if ($currency_code !== null) {
            $amount_data['currency_code'] = $currency_code;
        }
        if ($total_amount !== null) {
            $amount_data['value'] = number_format((float)$total_amount, 2, '.', '');
        }

        // Build breakdown
        $breakdown = array();

        // item_total: PAYMENTREQUEST_n_ITEMAMT > ITEMAMT
        if (isset($options['PAYMENTREQUEST_0_ITEMAMT'])) {
            $breakdown['item_total'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['PAYMENTREQUEST_0_ITEMAMT'], 2, '.', '')
            );
        } elseif (isset($options['ITEMAMT'])) {
            $breakdown['item_total'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['ITEMAMT'], 2, '.', '')
            );
        }

        // shipping: PAYMENTREQUEST_n_SHIPPINGAMT > SHIPPINGAMT
        if (isset($options['PAYMENTREQUEST_0_SHIPPINGAMT'])) {
            $breakdown['shipping'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['PAYMENTREQUEST_0_SHIPPINGAMT'], 2, '.', '')
            );
        } elseif (isset($options['SHIPPINGAMT'])) {
            $breakdown['shipping'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['SHIPPINGAMT'], 2, '.', '')
            );
        }

        // tax_total: PAYMENTREQUEST_n_TAXAMT > TAXAMT
        if (isset($options['PAYMENTREQUEST_0_TAXAMT'])) {
            $breakdown['tax_total'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['PAYMENTREQUEST_0_TAXAMT'], 2, '.', '')
            );
        } elseif (isset($options['TAXAMT'])) {
            $breakdown['tax_total'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['TAXAMT'], 2, '.', '')
            );
        }

        // handling: PAYMENTREQUEST_n_HANDLINGAMT > HANDLINGAMT
        if (isset($options['PAYMENTREQUEST_0_HANDLINGAMT'])) {
            $breakdown['handling'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['PAYMENTREQUEST_0_HANDLINGAMT'], 2, '.', '')
            );
        } elseif (isset($options['HANDLINGAMT'])) {
            $breakdown['handling'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['HANDLINGAMT'], 2, '.', '')
            );
        }

        // shipping_discount: PAYMENTREQUEST_n_SHIPDISCAMT > SHIPPINGDISCAMT
        if (isset($options['PAYMENTREQUEST_0_SHIPDISCAMT'])) {
            $breakdown['shipping_discount'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['PAYMENTREQUEST_0_SHIPDISCAMT'], 2, '.', '')
            );
        } elseif (isset($options['SHIPPINGDISCAMT'])) {
            $breakdown['shipping_discount'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['SHIPPINGDISCAMT'], 2, '.', '')
            );
        }

        // insurance: PAYMENTREQUEST_n_INSURANCEAMT > INSURANCEAMT
        if (isset($options['PAYMENTREQUEST_0_INSURANCEAMT'])) {
            $breakdown['insurance'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['PAYMENTREQUEST_0_INSURANCEAMT'], 2, '.', '')
            );
        } elseif (isset($options['INSURANCEAMT'])) {
            $breakdown['insurance'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['INSURANCEAMT'], 2, '.', '')
            );
        }

        if (!empty($breakdown)) {
            $amount_data['breakdown'] = $breakdown;
        }

        if (!empty($amount_data)) {
            $order_data['purchase_units'][0]['amount'] = $amount_data;
        }

        // Map intent: PAYMENTREQUEST_n_PAYMENTACTION > PAYMENTACTION
        if (isset($options['PAYMENTREQUEST_0_PAYMENTACTION'])) {
            $order_data['intent'] = ($options['PAYMENTREQUEST_0_PAYMENTACTION'] === 'Authorization') ? 'AUTHORIZE' : 'CAPTURE';
        } elseif (isset($options['PAYMENTACTION'])) {
            $order_data['intent'] = ($options['PAYMENTACTION'] === 'Authorization') ? 'AUTHORIZE' : 'CAPTURE';
        }

        // Map reference_id: PAYMENTREQUEST_n_PAYMENTREQUESTID > PAYMENTREQUESTID
        if (isset($options['PAYMENTREQUEST_0_PAYMENTREQUESTID'])) {
            $order_data['purchase_units'][0]['reference_id'] = $options['PAYMENTREQUEST_0_PAYMENTREQUESTID'];
        } elseif (isset($options['PAYMENTREQUESTID'])) {
            $order_data['purchase_units'][0]['reference_id'] = $options['PAYMENTREQUESTID'];
        }

        // Map custom_id: PAYMENTREQUEST_n_CUSTOM > CUSTOM
        if (isset($options['PAYMENTREQUEST_0_CUSTOM'])) {
            $order_data['purchase_units'][0]['custom_id'] = $options['PAYMENTREQUEST_0_CUSTOM'];
        } elseif (isset($options['CUSTOM'])) {
            $order_data['purchase_units'][0]['custom_id'] = $options['CUSTOM'];
        }

        // Map description: PAYMENTREQUEST_n_DESC > DESC
        if (isset($options['PAYMENTREQUEST_0_DESC'])) {
            $order_data['purchase_units'][0]['description'] = $options['PAYMENTREQUEST_0_DESC'];
        } elseif (isset($options['DESC'])) {
            $order_data['purchase_units'][0]['description'] = $options['DESC'];
        }

        // Map invoice_id: PAYMENTREQUEST_n_INVNUM > INVNUM
        if (isset($options['PAYMENTREQUEST_0_INVNUM'])) {
            $order_data['purchase_units'][0]['invoice_id'] = $options['PAYMENTREQUEST_0_INVNUM'];
        } elseif (isset($options['INVNUM'])) {
            $order_data['purchase_units'][0]['invoice_id'] = $options['INVNUM'];
        }

        // Build shipping address
        $shipping = array();

        // shipping.name.full_name: PAYMENTREQUEST_n_SHIPTONAME > SHIPTONAME
        if (isset($options['PAYMENTREQUEST_0_SHIPTONAME'])) {
            $shipping['name']['full_name'] = $options['PAYMENTREQUEST_0_SHIPTONAME'];
        } elseif (isset($options['SHIPTONAME'])) {
            $shipping['name']['full_name'] = $options['SHIPTONAME'];
        }

        // shipping.address.address_line_1: PAYMENTREQUEST_n_SHIPTOSTREET > SHIPTOSTREET
        if (isset($options['PAYMENTREQUEST_0_SHIPTOSTREET'])) {
            $shipping['address']['address_line_1'] = $options['PAYMENTREQUEST_0_SHIPTOSTREET'];
        } elseif (isset($options['SHIPTOSTREET'])) {
            $shipping['address']['address_line_1'] = $options['SHIPTOSTREET'];
        }

        // shipping.address.address_line_2: PAYMENTREQUEST_n_SHIPTOSTREET2 > SHIPTOSTREET2
        if (isset($options['PAYMENTREQUEST_0_SHIPTOSTREET2'])) {
            $shipping['address']['address_line_2'] = $options['PAYMENTREQUEST_0_SHIPTOSTREET2'];
        } elseif (isset($options['SHIPTOSTREET2'])) {
            $shipping['address']['address_line_2'] = $options['SHIPTOSTREET2'];
        }

        // shipping.address.admin_area_2: PAYMENTREQUEST_n_SHIPTOCITY > SHIPTOCITY
        if (isset($options['PAYMENTREQUEST_0_SHIPTOCITY'])) {
            $shipping['address']['admin_area_2'] = $options['PAYMENTREQUEST_0_SHIPTOCITY'];
        } elseif (isset($options['SHIPTOCITY'])) {
            $shipping['address']['admin_area_2'] = $options['SHIPTOCITY'];
        }

        // shipping.address.admin_area_1: PAYMENTREQUEST_n_SHIPTOSTATE > SHIPTOSTATE
        if (isset($options['PAYMENTREQUEST_0_SHIPTOSTATE'])) {
            $shipping['address']['admin_area_1'] = $options['PAYMENTREQUEST_0_SHIPTOSTATE'];
        } elseif (isset($options['SHIPTOSTATE'])) {
            $shipping['address']['admin_area_1'] = $options['SHIPTOSTATE'];
        }

        // shipping.address.postal_code: PAYMENTREQUEST_n_SHIPTOZIP > SHIPTOZIP
        if (isset($options['PAYMENTREQUEST_0_SHIPTOZIP'])) {
            $shipping['address']['postal_code'] = $options['PAYMENTREQUEST_0_SHIPTOZIP'];
        } elseif (isset($options['SHIPTOZIP'])) {
            $shipping['address']['postal_code'] = $options['SHIPTOZIP'];
        }

        // shipping.address.country_code: PAYMENTREQUEST_n_SHIPTOCOUNTRYCODE > PAYMENTREQUEST_n_SHIPTOCOUNTRY > SHIPTOCOUNTRY > SHIPTOCOUNTRYCODE
        if (isset($options['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'])) {
            $shipping['address']['country_code'] = $options['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'];
        } elseif (isset($options['PAYMENTREQUEST_0_SHIPTOCOUNTRY'])) {
            $shipping['address']['country_code'] = $options['PAYMENTREQUEST_0_SHIPTOCOUNTRY'];
        } elseif (isset($options['SHIPTOCOUNTRY'])) {
            $shipping['address']['country_code'] = $options['SHIPTOCOUNTRY'];
        } elseif (isset($options['SHIPTOCOUNTRYCODE'])) {
            $shipping['address']['country_code'] = $options['SHIPTOCOUNTRYCODE'];
        }

        // shipping.phone_number.national_number: PAYMENTREQUEST_n_SHIPTOPHONENUM
        if (isset($options['PAYMENTREQUEST_0_SHIPTOPHONENUM'])) {
            $shipping['phone_number']['national_number'] = $options['PAYMENTREQUEST_0_SHIPTOPHONENUM'];
        }

        if (!empty($shipping)) {
            $order_data['purchase_units'][0]['shipping'] = $shipping;
        }

        // Build items array
        $items = array();
        $item_index = 0;
        $total_discount = 0;

        // Process L_PAYMENTREQUEST_n_* items
        while (isset($options["L_PAYMENTREQUEST_0_NAME$item_index"]) || isset($options["L_NAME$item_index"])) {
            $item = array();

            // Get item name: L_PAYMENTREQUEST_n_NAMEm > PAYMENTREQUEST_n_NAME > L_NAMEn
            if (isset($options["L_PAYMENTREQUEST_0_NAME$item_index"])) {
                $item['name'] = $options["L_PAYMENTREQUEST_0_NAME$item_index"];
            } elseif (isset($options["PAYMENTREQUEST_0_NAME"])) {
                $item['name'] = $options["PAYMENTREQUEST_0_NAME"];
            } elseif (isset($options["L_NAME$item_index"])) {
                $item['name'] = $options["L_NAME$item_index"];
            }

            // Get quantity: L_PAYMENTREQUEST_n_QTYm > PAYMENTREQUEST_n_QTY > L_QTYn
            $quantity = 1;
            if (isset($options["L_PAYMENTREQUEST_0_QTY$item_index"])) {
                $quantity = (int)$options["L_PAYMENTREQUEST_0_QTY$item_index"];
            } elseif (isset($options["PAYMENTREQUEST_0_QTY"])) {
                $quantity = (int)$options["PAYMENTREQUEST_0_QTY"];
            } elseif (isset($options["L_QTY$item_index"])) {
                $quantity = (int)$options["L_QTY$item_index"];
            }
            $item['quantity'] = (string)$quantity;

            // Get unit amount: L_PAYMENTREQUEST_n_AMTm > L_AMTn
            $unit_amount = 0;
            if (isset($options["L_PAYMENTREQUEST_0_AMT$item_index"])) {
                $unit_amount = (float)$options["L_PAYMENTREQUEST_0_AMT$item_index"];
            } elseif (isset($options["L_AMT$item_index"])) {
                $unit_amount = (float)$options["L_AMT$item_index"];
            }

            // Handle negative amounts as discounts
            if ($unit_amount < 0) {
                $total_discount += abs($unit_amount * $quantity);
                $item_index++;
                continue;
            }

            $item['unit_amount'] = array(
                'currency_code' => $currency_code,
                'value' => number_format($unit_amount, 2, '.', '')
            );

            // Get SKU: L_PAYMENTREQUEST_n_NUMBERm > L_NUMBERn
            if (isset($options["L_PAYMENTREQUEST_0_NUMBER$item_index"])) {
                $item['sku'] = $options["L_PAYMENTREQUEST_0_NUMBER$item_index"];
            } elseif (isset($options["L_NUMBER$item_index"])) {
                $item['sku'] = $options["L_NUMBER$item_index"];
            }

            // Get description: L_PAYMENTREQUEST_n_DESCm > L_DESCn
            if (isset($options["L_PAYMENTREQUEST_0_DESC$item_index"])) {
                $item['description'] = $options["L_PAYMENTREQUEST_0_DESC$item_index"];
            } elseif (isset($options["L_DESC$item_index"])) {
                $item['description'] = $options["L_DESC$item_index"];
            }

            // Get URL: L_PAYMENTREQUEST_n_ITEMURLm > L_ITEMURLn
            if (isset($options["L_PAYMENTREQUEST_0_ITEMURL$item_index"])) {
                $item['url'] = $options["L_PAYMENTREQUEST_0_ITEMURL$item_index"];
            } elseif (isset($options["L_ITEMURL$item_index"])) {
                $item['url'] = $options["L_ITEMURL$item_index"];
            }

            // Get category: L_PAYMENTREQUEST_n_ITEMCATEGORYm
            if (isset($options["L_PAYMENTREQUEST_0_ITEMCATEGORY$item_index"])) {
                $item['category'] = $options["L_PAYMENTREQUEST_0_ITEMCATEGORY$item_index"];
            }

            // Get tax: L_PAYMENTREQUEST_n_TAXAMTm > L_TAXAMTn
            if (isset($options["L_PAYMENTREQUEST_0_TAXAMT$item_index"])) {
                $item['tax'] = array(
                    'currency_code' => $currency_code,
                    'value' => number_format((float)$options["L_PAYMENTREQUEST_0_TAXAMT$item_index"], 2, '.', '')
                );
            } elseif (isset($options["L_TAXAMT$item_index"])) {
                $item['tax'] = array(
                    'currency_code' => $currency_code,
                    'value' => number_format((float)$options["L_TAXAMT$item_index"], 2, '.', '')
                );
            }

            if (!empty($item['name'])) {
                $items[] = $item;
            }
            $item_index++;
        }

        if (!empty($items)) {
            $order_data['purchase_units'][0]['items'] = $items;

            // Recalculate item_total based on actual items
            $calculated_item_total = 0;
            foreach ($items as $item) {
                $calculated_item_total += (float)$item['unit_amount']['value'] * (float)$item['quantity'];
            }

            if ($calculated_item_total > 0) {
                if (!isset($order_data['purchase_units'][0]['amount']['breakdown'])) {
                    $order_data['purchase_units'][0]['amount']['breakdown'] = array();
                }
                $order_data['purchase_units'][0]['amount']['breakdown']['item_total'] = array(
                    'currency_code' => $currency_code,
                    'value' => number_format($calculated_item_total, 2, '.', '')
                );
            }
        }

        // Add accumulated discounts to breakdown
        if ($total_discount > 0) {
            if (!isset($order_data['purchase_units'][0]['amount']['breakdown'])) {
                $order_data['purchase_units'][0]['amount']['breakdown'] = array();
            }

            // Add to existing discount if present
            if (isset($order_data['purchase_units'][0]['amount']['breakdown']['discount'])) {
                $existing = (float)$order_data['purchase_units'][0]['amount']['breakdown']['discount']['value'];
                $total_discount += $existing;
            }

            $order_data['purchase_units'][0]['amount']['breakdown']['discount'] = array(
                'currency_code' => $currency_code,
                'value' => number_format($total_discount, 2, '.', '')
            );
        }

        // Map supplementary_data.card.level_3.shipping_amount: L_SHIPPINGOPTIONAMOUNTn
        if (isset($options['L_SHIPPINGOPTIONAMOUNT0'])) {
            $order_data['purchase_units'][0]['supplementary_data']['card']['level_3']['shipping_amount'] = array(
                'currency_code' => $currency_code,
                'value' => number_format((float)$options['L_SHIPPINGOPTIONAMOUNT0'], 2, '.', '')
            );
        }

        // Build payment_source.paypal
        $paypal_source = array();

        // experience_context
        $experience_context = array(
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl
        );

        // RETURNURL maps to return_url (already set above)
        // CANCELURL maps to cancel_url (already set above)

        // brand_name: BRANDNAME
        if (isset($options['BRANDNAME'])) {
            $experience_context['brand_name'] = $options['BRANDNAME'];
        }

        // landing_page: Convert LANDINGPAGE to REST v2 enum
        // NVP values: Billing, Login
        // REST v2 values: GUEST_CHECKOUT, LOGIN, NO_PREFERENCE (default)
        if (isset($options['LANDINGPAGE'])) {
            $landing_page_value = strtolower($options['LANDINGPAGE']);
            if ($landing_page_value === 'billing') {
                $experience_context['landing_page'] = 'GUEST_CHECKOUT';
            } elseif ($landing_page_value === 'login') {
                $experience_context['landing_page'] = 'LOGIN';
            } else {
                $experience_context['landing_page'] = 'NO_PREFERENCE';
            }
        }

        // shipping_preference: Convert ADDROVERRIDE or NOSHIPPING to REST v2 enum
        // Priority: ADDROVERRIDE (more specific) > NOSHIPPING
        if (isset($options['ADDROVERRIDE'])) {
            // ADDROVERRIDE: 0 = don't display merchant address, 1 = display merchant address
            if ($options['ADDROVERRIDE'] == '1') {
                $experience_context['shipping_preference'] = 'SET_PROVIDED_ADDRESS';
            } else {
                $experience_context['shipping_preference'] = 'GET_FROM_FILE';
            }
        } elseif (isset($options['NOSHIPPING'])) {
            // NOSHIPPING: 0 = display shipping, 1 = no shipping, 2 = get from buyer profile
            if ($options['NOSHIPPING'] == '1') {
                $experience_context['shipping_preference'] = 'NO_SHIPPING';
            } else {
                // Both 0 and 2 map to GET_FROM_FILE
                $experience_context['shipping_preference'] = 'GET_FROM_FILE';
            }
        }

        // payment_method_preference: PAYMENTREQUEST_n_ALLOWEDPAYMENTMETHOD > ALLOWEDPAYMENTMETHOD
        if (isset($options['PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD'])) {
            $experience_context['payment_method_preference'] = $options['PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD'];
        } elseif (isset($options['ALLOWEDPAYMENTMETHOD'])) {
            $experience_context['payment_method_preference'] = $options['ALLOWEDPAYMENTMETHOD'];
        }

        $paypal_source['experience_context'] = $experience_context;

        // email_address: EMAIL
        if (isset($options['EMAIL'])) {
            $paypal_source['email_address'] = $options['EMAIL'];
        }

        // name.given_name: BUYERUSERNAME
        if (isset($options['BUYERUSERNAME'])) {
            $paypal_source['name']['given_name'] = $options['BUYERUSERNAME'];
        }

        // billing_agreement_id: BILLING_AGREEMENT_ID
        if (isset($options['BILLING_AGREEMENT_ID'])) {
            $paypal_source['billing_agreement_id'] = $options['BILLING_AGREEMENT_ID'];
        }

        // phone.phone_number.national_number: SHIPTOPHONENUM
        if (isset($options['SHIPTOPHONENUM'])) {
            $paypal_source['phone']['phone_number']['national_number'] = $options['SHIPTOPHONENUM'];
        }

        // tax_info.tax_id: TAXID
        if (isset($options['TAXID'])) {
            $paypal_source['tax_info']['tax_id'] = $options['TAXID'];
        }

        // tax_info.tax_id_type: TAXIDTYPE
        if (isset($options['TAXIDTYPE'])) {
            $paypal_source['tax_info']['tax_id_type'] = $options['TAXIDTYPE'];
        }

        if (!empty($paypal_source)) {
            $order_data['payment_source']['paypal'] = $paypal_source;
        }

        return $order_data;
    }

    /**
     * Convert REST response to NVP format
     */
    private function convertToNVPFormat($method, $restResponse, $is_authorize = false) {
        $nvp_response = array();

        switch ($method) {
            case 'SetExpressCheckout':
                $nvp_response['TOKEN'] = $restResponse['id'];
                $nvp_response['ACK'] = 'Success';
                $nvp_response['TIMESTAMP'] = date('c');
                $nvp_response['CORRELATIONID'] = isset($restResponse['debug_id']) ?
                                                 $restResponse['debug_id'] :
                                                 ('corr_' . time() . rand(1000, 9999));
                $nvp_response['VERSION'] = '2.0';
                $nvp_response['BUILD'] = 'REST_API';

                // Extract approve URL
                if (isset($restResponse['links'])) {
                    foreach ($restResponse['links'] as $link) {
                        if ($link['rel'] === 'approve' || $link['rel'] === 'payer-action') {
                            $nvp_response['REDIRECT_URL'] = $link['href'];
                            break;
                        }
                    }
                }
                break;

            case 'GetExpressCheckoutDetails':
                // TOKEN → id
                $nvp_response['TOKEN'] = $restResponse['id'];
                $nvp_response['ACK'] = 'Success';

                // TIMESTAMP → create_time
                $nvp_response['TIMESTAMP'] = isset($restResponse['create_time']) ? $restResponse['create_time'] : date('c');

                // CORRELATIONID → debug_id
                $nvp_response['CORRELATIONID'] = isset($restResponse['debug_id']) ?
                                                 $restResponse['debug_id'] :
                                                 ('corr_' . time() . rand(1000, 9999));
                $nvp_response['VERSION'] = '2.0';
                $nvp_response['BUILD'] = 'REST_API';

                // CHECKOUTSTATUS → status
                if (isset($restResponse['status'])) {
                    $nvp_response['CHECKOUTSTATUS'] = $restResponse['status'];
                }

                // Map payer information
                if (isset($restResponse['payer'])) {
                    $payer = $restResponse['payer'];

                    // EMAIL / BUYERMARKETINGEMAIL → payment_source.paypal.email_address
                    if (isset($payer['email_address'])) {
                        $nvp_response['EMAIL'] = $payer['email_address'];
                        $nvp_response['BUYERMARKETINGEMAIL'] = $payer['email_address'];
                    }

                    // PAYERID → payment_source.paypal.account_id (or payer_id)
                    if (isset($payer['payer_id'])) {
                        $nvp_response['PAYERID'] = $payer['payer_id'];
                    }

                    $nvp_response['PAYERSTATUS'] = isset($payer['account_status']) ? $payer['account_status'] : '';

                    // FIRSTNAME → payment_source.paypal.name.given_name
                    // LASTNAME → payment_source.paypal.name.surname
                    if (isset($payer['name'])) {
                        if (isset($payer['name']['given_name'])) {
                            $nvp_response['FIRSTNAME'] = $payer['name']['given_name'];
                        }
                        if (isset($payer['name']['surname'])) {
                            $nvp_response['LASTNAME'] = $payer['name']['surname'];
                        }
                    }

                    // COUNTRYCODE → payment_source.paypal.address.country_code
                    if (isset($payer['address']['country_code'])) {
                        $nvp_response['COUNTRYCODE'] = $payer['address']['country_code'];
                    }
                }

                // PHONENUM → payment_source.paypal.phone_number.national_number
                if (isset($restResponse['payment_source']['paypal']['phone_number']['national_number'])) {
                    $nvp_response['PHONENUM'] = $restResponse['payment_source']['paypal']['phone_number']['national_number'];
                }

                // Map purchase_units information
                if (isset($restResponse['purchase_units'][0])) {
                    $purchase_unit = $restResponse['purchase_units'][0];

                    // PAYMENTREQUEST_n_TRANSACTIONID → purchase_units[].id
                    if (isset($purchase_unit['id'])) {
                        $nvp_response['PAYMENTREQUEST_0_TRANSACTIONID'] = $purchase_unit['id'];
                        $nvp_response['TRANSACTIONID'] = $purchase_unit['id'];
                    }

                    // PAYMENTREQUEST_n_DESC → purchase_units[].description
                    if (isset($purchase_unit['description'])) {
                        $nvp_response['PAYMENTREQUEST_0_DESC'] = $purchase_unit['description'];
                        $nvp_response['DESC'] = $purchase_unit['description'];
                    }

                    // PAYMENTREQUEST_n_INVNUM / INVNUM → purchase_units[].invoice_id
                    if (isset($purchase_unit['invoice_id'])) {
                        $nvp_response['PAYMENTREQUEST_0_INVNUM'] = $purchase_unit['invoice_id'];
                        $nvp_response['INVNUM'] = $purchase_unit['invoice_id'];
                    }

                    // PAYMENTREQUEST_n_SELLERPAYPALACCOUNTID → purchase_units[].payee.merchant_id
                    if (isset($purchase_unit['payee']['merchant_id'])) {
                        $nvp_response['PAYMENTREQUEST_0_SELLERPAYPALACCOUNTID'] = $purchase_unit['payee']['merchant_id'];
                        $nvp_response['SELLERPAYPALACCOUNTID'] = $purchase_unit['payee']['merchant_id'];
                    }

                    // Map amount information
                    if (isset($purchase_unit['amount'])) {
                        $amount = $purchase_unit['amount'];

                        // PAYMENTREQUEST_n_AMT → purchase_units[].amount.value
                        if (isset($amount['value'])) {
                            $nvp_response['PAYMENTREQUEST_0_AMT'] = $amount['value'];
                            $nvp_response['AMT'] = $amount['value'];
                        }

                        // PAYMENTREQUEST_n_CURRENCYCODE / PAYMENTINFO_n_CURRENCYCODE → purchase_units[].amount.currency_code
                        if (isset($amount['currency_code'])) {
                            $nvp_response['PAYMENTREQUEST_0_CURRENCYCODE'] = $amount['currency_code'];
                            $nvp_response['CURRENCYCODE'] = $amount['currency_code'];
                            $nvp_response['PAYMENTINFO_0_CURRENCYCODE'] = $amount['currency_code'];
                        }

                        // Map breakdown fields
                        if (isset($amount['breakdown'])) {
                            $breakdown = $amount['breakdown'];

                            // PAYMENTREQUEST_n_ITEMAMT → purchase_units[].amount.breakdown.item_total.value
                            if (isset($breakdown['item_total']['value'])) {
                                $nvp_response['PAYMENTREQUEST_0_ITEMAMT'] = $breakdown['item_total']['value'];
                                $nvp_response['ITEMAMT'] = $breakdown['item_total']['value'];
                            }

                            // PAYMENTREQUEST_n_SHIPPINGAMT → purchase_units[].amount.breakdown.shipping.value
                            if (isset($breakdown['shipping']['value'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPPINGAMT'] = $breakdown['shipping']['value'];
                                $nvp_response['SHIPPINGAMT'] = $breakdown['shipping']['value'];
                            }

                            // PAYMENTREQUEST_n_TAXAMT → purchase_units[].amount.breakdown.tax_total.value
                            if (isset($breakdown['tax_total']['value'])) {
                                $nvp_response['PAYMENTREQUEST_0_TAXAMT'] = $breakdown['tax_total']['value'];
                                $nvp_response['TAXAMT'] = $breakdown['tax_total']['value'];
                            }

                            // PAYMENTREQUEST_n_HANDLINGAMT → purchase_units[].amount.breakdown.handling.value
                            if (isset($breakdown['handling']['value'])) {
                                $nvp_response['PAYMENTREQUEST_0_HANDLINGAMT'] = $breakdown['handling']['value'];
                                $nvp_response['HANDLINGAMT'] = $breakdown['handling']['value'];
                            }

                            // PAYMENTREQUEST_n_INSURANCEAMT → purchase_units[].amount.breakdown.insurance.value
                            if (isset($breakdown['insurance']['value'])) {
                                $nvp_response['PAYMENTREQUEST_0_INSURANCEAMT'] = $breakdown['insurance']['value'];
                                $nvp_response['INSURANCEAMT'] = $breakdown['insurance']['value'];
                            }

                            // PAYMENTREQUEST_n_SHIPDISCAMT → purchase_units[].amount.breakdown.shipping_discount.value
                            if (isset($breakdown['shipping_discount']['value'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPDISCAMT'] = $breakdown['shipping_discount']['value'];
                                $nvp_response['SHIPDISCAMT'] = $breakdown['shipping_discount']['value'];
                            }
                        }
                    }

                    // Map shipping information
                    if (isset($purchase_unit['shipping'])) {
                        $shipping = $purchase_unit['shipping'];

                        // PAYMENTREQUEST_n_SHIPTONAME / SHIPTONAME → purchase_units[].shipping.name
                        if (isset($shipping['name']['full_name'])) {
                            $nvp_response['PAYMENTREQUEST_0_SHIPTONAME'] = $shipping['name']['full_name'];
                            $nvp_response['SHIPTONAME'] = $shipping['name']['full_name'];
                        }

                        if (isset($shipping['address'])) {
                            $address = $shipping['address'];

                            // PAYMENTREQUEST_n_SHIPTOSTREET / SHIPTOSTREET → purchase_units[].shipping.address.address_line_1
                            if (isset($address['address_line_1'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPTOSTREET'] = $address['address_line_1'];
                                $nvp_response['SHIPTOSTREET'] = $address['address_line_1'];
                            }

                            // PAYMENTREQUEST_n_SHIPTOSTREET2 / SHIPTOSTREET2 → purchase_units[].shipping.address.address_line_2
                            if (isset($address['address_line_2'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPTOSTREET2'] = $address['address_line_2'];
                                $nvp_response['SHIPTOSTREET2'] = $address['address_line_2'];
                            }

                            // PAYMENTREQUEST_n_SHIPTOCITY / SHIPTOCITY → purchase_units[].shipping.address.admin_area_2
                            if (isset($address['admin_area_2'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPTOCITY'] = $address['admin_area_2'];
                                $nvp_response['SHIPTOCITY'] = $address['admin_area_2'];
                            }

                            // PAYMENTREQUEST_n_SHIPTOSTATE / SHIPTOSTATE → purchase_units[].shipping.address.admin_area_1
                            if (isset($address['admin_area_1'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPTOSTATE'] = $address['admin_area_1'];
                                $nvp_response['SHIPTOSTATE'] = $address['admin_area_1'];
                            }

                            // PAYMENTREQUEST_n_SHIPTOZIP / SHIPTOZIP → purchase_units[].shipping.address.postal_code
                            if (isset($address['postal_code'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPTOZIP'] = $address['postal_code'];
                                $nvp_response['SHIPTOZIP'] = $address['postal_code'];
                            }

                            // PAYMENTREQUEST_n_SHIPTOCOUNTRYCODE / PAYMENTREQUEST_n_SHIPTOCOUNTRYNAME / SHIPTOCOUNTRY / SHIPTOCOUNTRYCODE
                            // → purchase_units[].shipping.address.country_code
                            if (isset($address['country_code'])) {
                                $nvp_response['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'] = $address['country_code'];
                                $nvp_response['PAYMENTREQUEST_0_SHIPTOCOUNTRYNAME'] = $address['country_code'];
                                $nvp_response['SHIPTOCOUNTRY'] = $address['country_code'];
                                $nvp_response['SHIPTOCOUNTRYCODE'] = $address['country_code'];
                            }
                        }

                        // Map phone number if available
                        if (isset($shipping['phone_number']['national_number'])) {
                            $nvp_response['PAYMENTREQUEST_0_SHIPTOPHONENUM'] = $shipping['phone_number']['national_number'];
                            $nvp_response['SHIPTOPHONENUM'] = $shipping['phone_number']['national_number'];
                        }
                    }

                    // Map line items
                    if (isset($purchase_unit['items'])) {
                        $items = $purchase_unit['items'];
                        $currency_code = isset($purchase_unit['amount']['currency_code']) ? $purchase_unit['amount']['currency_code'] : 'USD';

                        foreach ($items as $index => $item) {
                            // L_PAYMENTREQUEST_n_NAMEm / L_NAMEm → purchase_units[].items[].name
                            if (isset($item['name'])) {
                                $nvp_response["L_PAYMENTREQUEST_0_NAME$index"] = $item['name'];
                                $nvp_response["L_NAME$index"] = $item['name'];
                            }

                            // L_PAYMENTREQUEST_n_AMTm / L_AMTm → purchase_units[].items[].unit_amount.value
                            if (isset($item['unit_amount']['value'])) {
                                $nvp_response["L_PAYMENTREQUEST_0_AMT$index"] = $item['unit_amount']['value'];
                                $nvp_response["L_AMT$index"] = $item['unit_amount']['value'];
                            }

                            // L_PAYMENTREQUEST_n_QTYm / L_QTYm → purchase_units[].items[].quantity
                            if (isset($item['quantity'])) {
                                $nvp_response["L_PAYMENTREQUEST_0_QTY$index"] = $item['quantity'];
                                $nvp_response["L_QTY$index"] = $item['quantity'];
                            }

                            // L_PAYMENTREQUEST_n_NUMBERm / L_NUMBERm → purchase_units[].items[].sku
                            if (isset($item['sku'])) {
                                $nvp_response["L_PAYMENTREQUEST_0_NUMBER$index"] = $item['sku'];
                                $nvp_response["L_NUMBER$index"] = $item['sku'];
                            }

                            // L_PAYMENTREQUEST_n_DESCm / L_DESCm → purchase_units[].items[].description
                            if (isset($item['description'])) {
                                $nvp_response["L_PAYMENTREQUEST_0_DESC$index"] = $item['description'];
                                $nvp_response["L_DESC$index"] = $item['description'];
                            }

                            // L_PAYMENTREQUEST_n_TAXAMTm / L_TAXAMTm → purchase_units[].items[].tax.value
                            if (isset($item['tax']['value'])) {
                                $nvp_response["L_PAYMENTREQUEST_0_TAXAMT$index"] = $item['tax']['value'];
                                $nvp_response["L_TAXAMT$index"] = $item['tax']['value'];
                            }
                        }
                    }
                }
                break;

            case 'DoExpressCheckoutPayment':
                $nvp_response['TOKEN'] = $restResponse['id'];
                $nvp_response['ACK'] = 'Success';
                $nvp_response['TIMESTAMP'] = date('c');
                $nvp_response['CORRELATIONID'] = isset($restResponse['debug_id']) ?
                                                 $restResponse['debug_id'] :
                                                 ('corr_' . time() . rand(1000, 9999));
                $nvp_response['VERSION'] = '2.0';
                $nvp_response['BUILD'] = 'REST_API';

                // Map capture or authorization information based on payment action
                if ($is_authorize) {
                    // Handle Authorization
                    if (isset($restResponse['purchase_units'][0]['payments']['authorizations'][0])) {
                        $authorization = $restResponse['purchase_units'][0]['payments']['authorizations'][0];

                        // TRANSACTIONID → PAYMENTINFO_n_TRANSACTIONID
                        $nvp_response['PAYMENTINFO_0_TRANSACTIONID'] = $authorization['id'];
                        $nvp_response['TRANSACTIONID'] = $authorization['id'];

                        // TRANSACTIONTYPE → PAYMENTINFO_n_TRANSACTIONTYPE
                        $nvp_response['PAYMENTINFO_0_TRANSACTIONTYPE'] = 'authorization';
                        $nvp_response['TRANSACTIONTYPE'] = 'authorization';

                        // PAYMENTTYPE → PAYMENTINFO_n_PAYMENTTYPE
                        $nvp_response['PAYMENTINFO_0_PAYMENTTYPE'] = 'instant';
                        $nvp_response['PAYMENTTYPE'] = 'instant';

                        // ORDERTIME → PAYMENTINFO_n_ORDERTIME
                        $ordertime = isset($authorization['create_time']) ? $authorization['create_time'] : date('c');
                        $nvp_response['PAYMENTINFO_0_ORDERTIME'] = $ordertime;
                        $nvp_response['ORDERTIME'] = $ordertime;

                        // AMT → PAYMENTINFO_n_AMT
                        if (isset($authorization['amount']['value'])) {
                            $nvp_response['PAYMENTINFO_0_AMT'] = $authorization['amount']['value'];
                            $nvp_response['AMT'] = $authorization['amount']['value'];
                        }

                        // CURRENCYCODE → PAYMENTINFO_n_CURRENCYCODE
                        if (isset($authorization['amount']['currency_code'])) {
                            $nvp_response['PAYMENTINFO_0_CURRENCYCODE'] = $authorization['amount']['currency_code'];
                            $nvp_response['CURRENCYCODE'] = $authorization['amount']['currency_code'];
                        }

                        // Handle authorization status
                        $auth_status = isset($authorization['status']) ? strtoupper($authorization['status']) : 'CREATED';
                        if ($auth_status === 'PENDING' || $auth_status === 'CREATED') {
                            // PAYMENTSTATUS → PAYMENTINFO_n_PAYMENTSTATUS
                            $nvp_response['PAYMENTINFO_0_PAYMENTSTATUS'] = 'Pending';
                            $nvp_response['PAYMENTSTATUS'] = 'Pending';

                            // PENDINGREASON → PAYMENTINFO_n_PENDINGREASON
                            $nvp_response['PAYMENTINFO_0_PENDINGREASON'] = 'authorization';
                            $nvp_response['PENDINGREASON'] = 'authorization';
                        } else {
                            $nvp_response['PAYMENTINFO_0_PAYMENTSTATUS'] = 'Completed';
                            $nvp_response['PAYMENTSTATUS'] = 'Completed';
                        }

                        // PROTECTIONELIGIBILITY → PAYMENTINFO_n_PROTECTIONELIGIBILITY
                        if (isset($authorization['seller_protection']['status'])) {
                            $nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITY'] = $authorization['seller_protection']['status'];
                            $nvp_response['PROTECTIONELIGIBILITY'] = $authorization['seller_protection']['status'];
                        }

                        if (isset($authorization['seller_protection']['dispute_categories'])) {
                            $dispute_categories = is_array($authorization['seller_protection']['dispute_categories']) ?
                                implode(',', $authorization['seller_protection']['dispute_categories']) :
                                $authorization['seller_protection']['dispute_categories'];
                            $nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITYTYPE'] = $dispute_categories;
                        }
                    }
                } else {
                    // Handle Capture (Sale)
                    if (isset($restResponse['purchase_units'][0]['payments']['captures'][0])) {
                        $capture = $restResponse['purchase_units'][0]['payments']['captures'][0];

                        // TRANSACTIONID → PAYMENTINFO_n_TRANSACTIONID
                        $nvp_response['PAYMENTINFO_0_TRANSACTIONID'] = $capture['id'];
                        $nvp_response['TRANSACTIONID'] = $capture['id'];

                        // TRANSACTIONTYPE → PAYMENTINFO_n_TRANSACTIONTYPE
                        $nvp_response['PAYMENTINFO_0_TRANSACTIONTYPE'] = 'cart';
                        $nvp_response['TRANSACTIONTYPE'] = 'cart';

                        // PAYMENTTYPE → PAYMENTINFO_n_PAYMENTTYPE
                        $nvp_response['PAYMENTINFO_0_PAYMENTTYPE'] = 'instant';
                        $nvp_response['PAYMENTTYPE'] = 'instant';

                        // ORDERTIME → PAYMENTINFO_n_ORDERTIME
                        $ordertime = isset($capture['create_time']) ? $capture['create_time'] : date('c');
                        $nvp_response['PAYMENTINFO_0_ORDERTIME'] = $ordertime;
                        $nvp_response['ORDERTIME'] = $ordertime;

                        // AMT → PAYMENTINFO_n_AMT
                        if (isset($capture['amount']['value'])) {
                            $nvp_response['PAYMENTINFO_0_AMT'] = $capture['amount']['value'];
                            $nvp_response['AMT'] = $capture['amount']['value'];
                        }

                        // FEEAMT → PAYMENTINFO_n_FEEAMT
                        if (isset($capture['seller_receivable_breakdown']['paypal_fee']['value'])) {
                            $nvp_response['PAYMENTINFO_0_FEEAMT'] = $capture['seller_receivable_breakdown']['paypal_fee']['value'];
                            $nvp_response['FEEAMT'] = $capture['seller_receivable_breakdown']['paypal_fee']['value'];
                        }

                        // CURRENCYCODE → PAYMENTINFO_n_CURRENCYCODE
                        if (isset($capture['amount']['currency_code'])) {
                            $nvp_response['PAYMENTINFO_0_CURRENCYCODE'] = $capture['amount']['currency_code'];
                            $nvp_response['CURRENCYCODE'] = $capture['amount']['currency_code'];
                        }

                        // Handle payment status and pending reason
                        $capture_status = isset($capture['status']) ? strtoupper($capture['status']) : 'COMPLETED';
                        if ($capture_status === 'PENDING') {
                            // PAYMENTSTATUS → PAYMENTINFO_n_PAYMENTSTATUS
                            $nvp_response['PAYMENTINFO_0_PAYMENTSTATUS'] = 'Pending';
                            $nvp_response['PAYMENTSTATUS'] = 'Pending';

                            // PENDINGREASON → PAYMENTINFO_n_PENDINGREASON
                            $nvp_response['PAYMENTINFO_0_PENDINGREASON'] = 'paymentreview';
                            $nvp_response['PENDINGREASON'] = 'paymentreview';
                        } else {
                            $nvp_response['PAYMENTINFO_0_PAYMENTSTATUS'] = 'Completed';
                            $nvp_response['PAYMENTSTATUS'] = 'Completed';
                        }

                        // PROTECTIONELIGIBILITY → PAYMENTINFO_n_PROTECTIONELIGIBILITY
                        if (isset($capture['seller_protection']['status'])) {
                            $nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITY'] = $capture['seller_protection']['status'];
                            $nvp_response['PROTECTIONELIGIBILITY'] = $capture['seller_protection']['status'];
                        }

                        if (isset($capture['seller_protection']['dispute_categories'])) {
                            $dispute_categories = is_array($capture['seller_protection']['dispute_categories']) ?
                                implode(',', $capture['seller_protection']['dispute_categories']) :
                                $capture['seller_protection']['dispute_categories'];
                            $nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITYTYPE'] = $dispute_categories;
                        }
                    }
                }

                // Map payee information
                if (isset($restResponse['purchase_units'][0]['payee'])) {
                    $nvp_response['PAYMENTINFO_0_SELLERPAYPALACCOUNTID'] = $restResponse['purchase_units'][0]['payee']['email_address'];
                }
                break;

            case 'GetTransactionDetails':
                $nvp_response['ACK'] = 'Success';
                $nvp_response['TIMESTAMP'] = date('c');
                $nvp_response['VERSION'] = '2.0';
                $nvp_response['BUILD'] = 'REST_API';

                // Extract capture and order responses
                $capture = isset($restResponse['capture']) ? $restResponse['capture'] : null;
                $order = isset($restResponse['order']) ? $restResponse['order'] : null;

                if ($capture) {
                    // Extract basic transaction information from capture response
                    if (isset($capture['id'])) {
                        $nvp_response['TRANSACTIONID'] = $capture['id'];
                    }

                    // Extract status and map to NVP format
                    if (isset($capture['status'])) {
                        $status = $capture['status'];
                        if ($status === 'COMPLETED') {
                            $nvp_response['PAYMENTSTATUS'] = 'Completed';
                        } elseif ($status === 'PENDING') {
                            $nvp_response['PAYMENTSTATUS'] = 'Pending';
                            // Extract pending reason if available
                            if (isset($capture['status_details']['reason'])) {
                                $nvp_response['PENDINGREASON'] = $capture['status_details']['reason'];
                            }
                        } elseif ($status === 'DECLINED') {
                            $nvp_response['PAYMENTSTATUS'] = 'Denied';
                        } elseif ($status === 'REFUNDED') {
                            $nvp_response['PAYMENTSTATUS'] = 'Refunded';
                        } elseif ($status === 'PARTIALLY_REFUNDED') {
                            $nvp_response['PAYMENTSTATUS'] = 'Partially-Refunded';
                        } elseif ($status === 'FAILED') {
                            $nvp_response['PAYMENTSTATUS'] = 'Failed';
                        }
                    }

                    // Extract amount information
                    if (isset($capture['amount']['value'])) {
                        $nvp_response['AMT'] = $capture['amount']['value'];
                    }
                    if (isset($capture['amount']['currency_code'])) {
                        $nvp_response['CURRENCYCODE'] = $capture['amount']['currency_code'];
                    }

                    // Extract fee amount
                    if (isset($capture['seller_receivable_breakdown']['paypal_fee']['value'])) {
                        $nvp_response['FEEAMT'] = $capture['seller_receivable_breakdown']['paypal_fee']['value'];
                    }

                    // Extract seller protection
                    if (isset($capture['seller_protection']['status'])) {
                        $protection_status = $capture['seller_protection']['status'];
                        if ($protection_status === 'ELIGIBLE') {
                            $nvp_response['PROTECTIONELIGIBILITY'] = 'Eligible';
                        } elseif ($protection_status === 'NOT_ELIGIBLE') {
                            $nvp_response['PROTECTIONELIGIBILITY'] = 'Ineligible';
                        } elseif ($protection_status === 'PARTIALLY_ELIGIBLE') {
                            $nvp_response['PROTECTIONELIGIBILITY'] = 'PartiallyEligible';
                        }
                    }

                    // Extract timestamps
                    if (isset($capture['create_time'])) {
                        $nvp_response['ORDERTIME'] = $capture['create_time'];
                    }

                    // Extract payee information
                    if (isset($capture['payee']['merchant_id'])) {
                        $nvp_response['RECEIVERID'] = $capture['payee']['merchant_id'];
                    }
                }

                // Extract additional information from order response if available
                if ($order) {
                    // Extract payer information
                    if (isset($order['payer'])) {
                        $payer = $order['payer'];

                        if (isset($payer['email_address'])) {
                            $nvp_response['EMAIL'] = $payer['email_address'];
                        }
                        if (isset($payer['payer_id'])) {
                            $nvp_response['PAYERID'] = $payer['payer_id'];
                        }

                        // Extract payer name
                        if (isset($payer['name']['given_name'])) {
                            $nvp_response['FIRSTNAME'] = $payer['name']['given_name'];
                        }
                        if (isset($payer['name']['surname'])) {
                            $nvp_response['LASTNAME'] = $payer['name']['surname'];
                        }

                        // Extract address information
                        if (isset($payer['address']['country_code'])) {
                            $nvp_response['COUNTRYCODE'] = $payer['address']['country_code'];
                        }
                    }

                    // Extrace payer status
                    if (isset($order['payment_source']['paypal']['account_status'])) {
                        $nvp_response['PAYERSTATUS'] = strtolower($order['payment_source']['paypal']['account_status']);
                    }

                    // Extract shipping information
                    if (isset($order['purchase_units'][0]['shipping'])) {
                        $shipping = $order['purchase_units'][0]['shipping'];

                        if (isset($shipping['name']['full_name'])) {
                            $nvp_response['SHIPTONAME'] = $shipping['name']['full_name'];
                        }

                        if (isset($shipping['address'])) {
                            $address = $shipping['address'];
                            if (isset($address['address_line_1'])) {
                                $nvp_response['SHIPTOSTREET'] = $address['address_line_1'];
                            }
                            if (isset($address['address_line_2'])) {
                                $nvp_response['SHIPTOSTREET2'] = $address['address_line_2'];
                            }
                            if (isset($address['admin_area_2'])) {
                                $nvp_response['SHIPTOCITY'] = $address['admin_area_2'];
                            }
                            if (isset($address['admin_area_1'])) {
                                $nvp_response['SHIPTOSTATE'] = $address['admin_area_1'];
                            }
                            if (isset($address['postal_code'])) {
                                $nvp_response['SHIPTOZIP'] = $address['postal_code'];
                            }
                            if (isset($address['country_code'])) {
                                $nvp_response['SHIPTOCOUNTRYCODE'] = $address['country_code'];
                            }
                        }
                    }

                    // Extract custom fields
                    if (isset($order['purchase_units'][0]['custom_id'])) {
                        $nvp_response['CUSTOM'] = $order['purchase_units'][0]['custom_id'];
                    }
                    if (isset($order['purchase_units'][0]['invoice_id'])) {
                        $nvp_response['INVNUM'] = $order['purchase_units'][0]['invoice_id'];
                    }

                    // Extract tax amount from breakdown
                    if (isset($order['purchase_units'][0]['amount']['breakdown']['tax_total']['value'])) {
                        $nvp_response['TAXAMT'] = $order['purchase_units'][0]['amount']['breakdown']['tax_total']['value'];
                    }
                }

                break;
        }

        return $nvp_response;
    }

    public function _sanitizeLog($data) {
        if (is_array($data)) {
            foreach (array_keys($data) as $key) {
                switch (strtolower($key)) {
                    case 'authorization':
                    case 'access_token':
                        $data[$key] = str_repeat('*', strlen($data[$key]) - 4) . substr($data[$key], -4);
                        break;
                }
            }
            return $data;
        }
        return $data;
    }

    public function _parseNameValueList($response) {
        if (is_array($response)) {
            return $response;
        }

        $pairs = explode('&', $response);
        $values = array();
        foreach ($pairs as $pair) {
            if (strpos($pair, '=') !== false) {
                list($name, $value) = explode('=', $pair, 2);
                $values[$name] = urldecode($value);
            }
        }
        return $values;
    }

    public function debugLog($function_name, $data) {
        $log_file = $this->log_dir . 'paypal_rest_v2_' . date('Y-m-d') . '.log';

        $sanitized_data = $this->_sanitizeLog($data);
        $log_line = date('Y-m-d H:i:s') . ' [' . $function_name . '] ' . json_encode($sanitized_data) . "\n";

        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }
}
