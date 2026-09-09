<?php
/**
 * RWBE API Connection Test (Standalone Version)
 * 
 * This script tests the connection to the RWBE API using the provided token.
 * This is a standalone version that doesn't require WordPress functions.
 */

// API configuration
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
//   1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php tools/standalone-api-test.php
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

// Initialize cURL session
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $request_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $api_token
]);

// Execute the request
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

// Close cURL session
curl_close($ch);

// Output as HTML
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html>
<html>
<head>
    <title>RWBE API Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; max-width: 1200px; margin: 0 auto; }
        pre { background: #f4f4f4; padding: 15px; overflow: auto; }
        .success { color: green; }
        .error { color: red; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>';

echo '<h1>RWBE API Connection Test</h1>';

// Check for cURL errors
if ($curl_error) {
    echo '<h2 class="error">Error connecting to RWBE API</h2>';
    echo '<p class="error">Error: ' . htmlspecialchars($curl_error) . '</p>';
} 
// Check response code
else if ($http_code !== 200) {
    echo '<h2 class="error">API Error</h2>';
    echo '<p class="error">Response code: ' . $http_code . '</p>';
    echo '<p>Response body:</p>';
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
} 
else {
    // Parse JSON response
    $data = json_decode($response, true);
    
    // Check if we can parse the JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo '<h2 class="error">JSON Parsing Error</h2>';
        echo '<p class="error">Error: ' . json_last_error_msg() . '</p>';
        echo '<p>Raw response:</p>';
        echo '<pre>' . htmlspecialchars($response) . '</pre>';
    } 
    else {
        // Display the results
        echo '<h2 class="success">API Connection Test Successful</h2>';
        echo '<p>Successfully connected to the RWBE API and retrieved data.</p>';
        
        // Display product count
        $products = isset($data['data']) ? $data['data'] : (is_array($data) ? $data : []);
        $product_count = count($products);
        
        echo '<p>Retrieved ' . $product_count . ' products.</p>';
        
        // Display the products in a table
        if ($product_count > 0) {
            echo '<h3>Sample Product Data:</h3>';
            
            echo '<table>';
            echo '<tr>
                <th>Item Code</th>
                <th>Title</th>
                <th>Brand</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Category</th>
            </tr>';
            
            foreach ($products as $product) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($product['itemCode'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($product['title'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($product['brand']['title'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars(($product['currency']['signPrefix'] ?? '€') . ' ' . ($product['retailerPrice'] ?? 'N/A')) . '</td>';
                echo '<td>' . htmlspecialchars($product['stock'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($product['segment'] ?? 'N/A') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            
            // Show full details of first product
            echo '<h3>Detailed Data for First Product:</h3>';
            echo '<pre>';
            print_r($products[0]);
            echo '</pre>';
        }
    }
}

echo '</body></html>';
?>
