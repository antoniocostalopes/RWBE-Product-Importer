<?php
/**
 * RWBE API Connection Test
 * 
 * This script tests the connection to the RWBE API using the provided token.
 */

// API configuration
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
//   1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php api-test.php
//   2. the token configured in the plugin settings (when WordPress is loaded)
$api_token = getenv('RWBE_API_TOKEN');
if (!$api_token && function_exists('rwbe_get_api_token')) {
    $api_token = rwbe_get_api_token();
}
if (!$api_token) {
    exit('Missing API token: set the RWBE_API_TOKEN environment variable or configure the token in the plugin settings.');
}
$limit = 5; // Limiting to 5 products for the test

// Build the request URL with parameters
$request_url = $api_endpoint . '?' . http_build_query([
    'limit' => $limit,
    'skip' => 0
]);

// Set up the request
$args = [
    'headers' => [
        'Authorization' => 'Bearer ' . $api_token
    ],
    'timeout' => 30
];

// Make the request
$response = wp_remote_get($request_url, $args);

// Check for errors
if (is_wp_error($response)) {
    echo '<h2>Error connecting to RWBE API</h2>';
    echo '<p>Error: ' . $response->get_error_message() . '</p>';
    exit;
}

// Check response code
$response_code = wp_remote_retrieve_response_code($response);
if ($response_code !== 200) {
    echo '<h2>API Error</h2>';
    echo '<p>Response code: ' . $response_code . '</p>';
    echo '<p>Response message: ' . wp_remote_retrieve_response_message($response) . '</p>';
    exit;
}

// Get the response body
$body = wp_remote_retrieve_body($response);
$data = json_decode($body, true);

// Check if we can parse the JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    echo '<h2>JSON Parsing Error</h2>';
    echo '<p>Error: ' . json_last_error_msg() . '</p>';
    exit;
}

// Display the results
echo '<h2>API Connection Test Successful</h2>';
echo '<p>Successfully connected to the RWBE API and retrieved data.</p>';

// Display product count
$product_count = isset($data['data']) ? count($data['data']) : 0;
echo '<p>Retrieved ' . $product_count . ' products.</p>';

// Display the first product details if available
if ($product_count > 0) {
    $first_product = $data['data'][0];
    echo '<h3>Sample Product Data:</h3>';
    echo '<pre>';
    print_r($first_product);
    echo '</pre>';
}
?>
