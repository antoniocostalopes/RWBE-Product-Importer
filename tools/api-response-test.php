<?php
/**
 * API Response Test
 * 
 * This script tests the API response structure to help debug the import issue.
 */

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// API configuration
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
//   1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php tools/api-response-test.php
//   2. the token configured in the plugin settings (when WordPress is loaded)
$api_token = getenv('RWBE_API_TOKEN');
if (!$api_token && function_exists('rwbe_get_api_token')) {
    $api_token = rwbe_get_api_token();
}
if (!$api_token) {
    exit('Missing API token: set the RWBE_API_TOKEN environment variable or configure the token in the plugin settings.');
}
$limit = 5; // Limit to 5 products for testing

// Build the request URL
$url = $api_endpoint . '?' . http_build_query([
    'limit' => $limit,
    'skip' => 0
]);

echo "<h1>API Response Test</h1>";
echo "<p>Testing URL: " . htmlspecialchars($url) . "</p>";

// Initialize cURL session
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $url);
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

// Check for cURL errors
if ($curl_error) {
    echo "<h2>Error connecting to API</h2>";
    echo "<p>Error: " . htmlspecialchars($curl_error) . "</p>";
    exit;
}

// Check response code
if ($http_code !== 200) {
    echo "<h2>API Error</h2>";
    echo "<p>Response code: " . $http_code . "</p>";
    echo "<p>Response body:</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

// Parse JSON response
$data = json_decode($response, true);

// Check if we can parse the JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "<h2>JSON Parsing Error</h2>";
    echo "<p>Error: " . json_last_error_msg() . "</p>";
    echo "<p>Raw response:</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

// Display the raw response structure
echo "<h2>Raw API Response Structure</h2>";
echo "<pre>";
print_r($data);
echo "</pre>";

// Check if the data is in the expected format
echo "<h2>Data Analysis</h2>";

if (isset($data['data']) && is_array($data['data'])) {
    echo "<p>Data is in the expected format with a 'data' wrapper. Found " . count($data['data']) . " products.</p>";
    $products = $data['data'];
} elseif (is_array($data)) {
    echo "<p>Data is directly an array without a 'data' wrapper. Found " . count($data) . " products.</p>";
    $products = $data;
} else {
    echo "<p>Unexpected data format. Cannot find products array.</p>";
    exit;
}

// Display some product data if available
if (!empty($products)) {
    echo "<h2>Sample Product Data</h2>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Item Code</th><th>Title</th><th>Brand</th><th>Price</th><th>Stock</th></tr>";
    
    foreach (array_slice($products, 0, 5) as $product) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($product['itemCode'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($product['title'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($product['brand']['title'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars(($product['retailerPrice'] ?? 'N/A')) . "</td>";
        echo "<td>" . htmlspecialchars($product['stock'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>No products found in the response.</p>";
}
?>
