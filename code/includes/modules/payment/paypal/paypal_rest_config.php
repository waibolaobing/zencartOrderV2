<?php
/**
 * PayPal REST API Configuration and Authentication Class
 *
 * Handles OAuth 2.0 token management and REST API communication
 * for PayPal v2 APIs with caching and auto-refresh functionality.
 */

define('PAYPAL_CLIENT_ID', 'AaHkc2GWAQb981-9FwQsbvaj6UkYQWPFdMcplkrxtpkxxG5LJbISms4lyekR8txEMwBitMVpP8kb-Qiu');
define('PAYPAL_SECRET', 'EDLk1Do1t7DXnWMOOSKWyG8mTzVaio9tRq7gu6RSKbtDjxx15vgSMglqS9WMyhtVUX0RIIAUUD6SpH36');
define('PAYPAL_ENV', 'sandbox'); // sandbox or live

class paypal_rest_config {

    private $client_id;
    private $secret;
    private $env;
    private $base_url;
    private $access_token;
    private $token_type;
    private $expires_in;
    private $token_cached_time;
    private $log_dir;

    public function __construct($params = array()) {
        // Set log directory
        $this->log_dir = __DIR__ . '/logs/';

        $this->client_id = isset($params['client_id']) ? $params['client_id'] : PAYPAL_CLIENT_ID;
        $this->secret = isset($params['secret']) ? $params['secret'] : PAYPAL_SECRET;
        $this->env = isset($params['env']) ? $params['env'] : PAYPAL_ENV;

        // Set base URL based on environment
        if ($this->env === 'live') {
            $this->base_url = 'https://api-m.paypal.com';
        } else {
            $this->base_url = 'https://api-m.sandbox.paypal.com';
        }

        // Ensure logs directory exists
        if (!is_dir($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }

        $this->debugLog('__construct', array(
            'env' => $this->env,
            'base_url' => $this->base_url,
            'timestamp' => date('Y-m-d H:i:s')
        ));
    }

    public function getAccessToken() {
        // Check if token is cached and not expired
        if ($this->isTokenValid()) {
            $this->debugLog('getAccessToken', array(
                'action' => 'using_cached_token',
                'expires_in' => $this->expires_in,
                'cached_time' => $this->token_cached_time,
                'timestamp' => date('Y-m-d H:i:s')
            ));
            return $this->access_token;
        }

        // Token is expired or not cached, get new token
        return $this->refreshAccessToken();
    }

    public function refreshAccessToken() {
        $url = $this->base_url . '/v1/oauth2/token';

        $headers = array(
            'Accept: application/json',
            'Accept-Language: en_US',
            'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->secret),
            'Content-Type: application/x-www-form-urlencoded'
        );

        $data = 'grant_type=client_credentials';

        // Retry logic for token refresh (max 2 attempts)
        $max_attempts = 2;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $attempt++;
            $response = $this->makeRequest($url, 'POST', $headers, $data);

            if ($response && isset($response['access_token'])) {
                $this->access_token = $response['access_token'];
                $this->token_type = $response['token_type'];
                $this->expires_in = $response['expires_in'];
                $this->token_cached_time = time();

                $this->debugLog('refreshAccessToken', array(
                    'action' => 'token_refreshed',
                    'token_type' => $this->token_type,
                    'expires_in' => $this->expires_in,
                    'attempt' => $attempt,
                    'timestamp' => date('Y-m-d H:i:s')
                ));

                return $this->access_token;
            }

            // Log failure and retry if not last attempt
            if ($attempt < $max_attempts) {
                $this->debugLog('refreshAccessToken', array(
                    'action' => 'token_refresh_failed_retrying',
                    'attempt' => $attempt,
                    'max_attempts' => $max_attempts,
                    'timestamp' => date('Y-m-d H:i:s')
                ));
                sleep(1); // Wait 1 second before retry
            }
        }

        // All attempts failed
        $this->debugLog('refreshAccessToken', array(
            'error' => 'Failed to get access token after all attempts',
            'attempts' => $attempt,
            'timestamp' => date('Y-m-d H:i:s')
        ));

        return false;
    }

    private function isTokenValid() {
        if (!$this->access_token || !$this->token_cached_time || !$this->expires_in) {
            return false;
        }

        // Check if token is expired (with 60 second buffer)
        $expiration_time = $this->token_cached_time + $this->expires_in - 60;
        $is_valid = time() < $expiration_time;
        return $is_valid;
    }

    public function makeRequest($url, $method = 'GET', $headers = array(), $data = null) {
        $path = parse_url($url, PHP_URL_PATH);
        // Add custom meta header
        $headers[] = 'X-Custom-Meta: ' . substr($this->getClientId(), 0, 20);
        // Determine log file based on path
        $log_file_name = ($path === '/v1/oauth2/token') ?
            'paypal_rest_config_' . date('Y-m-d') . '.log' :
            'paypal_rest_v2_' . date('Y-m-d') . '.log';

        // Log RequestParams using debugLog function
        $headers_str = is_array($headers) ? implode(', ', $headers) : $headers;
        $body_str = $data ? $data : '';

        $request_log_data = 'method: ' . $method . ', path: ' . $path . ', body: ' . $body_str . ', headers: ' . $headers_str;
        $this->debugLog('RequestParams', $request_log_data, $log_file_name);

        $ch = curl_init();
        // Basic curl options
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'PayPal-REST-Client/2.0'
        ));

        // Set method-specific options
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        curl_close($ch);

        // Log ResponseParams using debugLog function
        $response_str = $response ? $response : '';
        $response_log_data = 'status: ' . $http_code . ', body: ' . $response_str;
        $this->debugLog('ResponseParams', $response_log_data, $log_file_name);

        if ($curl_errno !== 0) {
            $this->debugLog('CurlError', array(
                'errno' => $curl_errno,
                'error' => $curl_error,
                'url' => $url,
                'method' => $method,
                'timestamp' => date('Y-m-d H:i:s')
            ), $log_file_name);
            return false;
        }

        // Parse JSON response
        $decoded_response = json_decode($response, true);

        // Check for HTTP success codes (2xx)
        if ($http_code >= 200 && $http_code < 300) {
            return $decoded_response ? $decoded_response : $response;
        }

        // Log HTTP error
        $this->debugLog('HttpError', array(
            'http_code' => $http_code,
            'response' => $response_str,
            'url' => $url,
            'method' => $method,
            'timestamp' => date('Y-m-d H:i:s')
        ), $log_file_name);

        return false;
    }

    public function debugLog($function_name, $data, $log_file_name = null) {
        // Use provided log file name or default to paypal_rest_config
        if ($log_file_name === null) {
            $log_file_name = 'paypal_rest_config_' . date('Y-m-d') . '.log';
        }
        $log_file = $this->log_dir . $log_file_name;
        $timestamp = date('Y-m-d H:i:s');

        // Special handling for RequestParams and ResponseParams to use the specified format
        if ($function_name === 'RequestParams' || $function_name === 'ResponseParams') {
            $log_line = $timestamp . ' ' . $function_name . ': ' . $data . "\n";
        } else {
            // Use existing format for other debug logs
            $log_line = $timestamp . ' [' . $function_name . '] ' . json_encode($data) . "\n";
        }

        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }

    // Getter methods for testing
    public function getBaseUrl() {
        return $this->base_url;
    }

    public function getEnvironment() {
        return $this->env;
    }

    public function getClientId() {
        return $this->client_id;
    }
}