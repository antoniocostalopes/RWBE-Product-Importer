<?php
/**
 * RWBE API Connection Test
 * 
 * This script tests the connection to the RWBE API using the provided token.
 * Run this file in a browser to see the results.
 */

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// API configuration
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
//   1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php tools/browser-api-test.php
//   2. the token configured in the plugin settings (when WordPress is loaded)
$api_token = getenv('RWBE_API_TOKEN');
if (!$api_token && function_exists('rwbe_get_api_token')) {
    $api_token = rwbe_get_api_token();
}
if (!$api_token) {
    exit('Missing API token: set the RWBE_API_TOKEN environment variable or configure the token in the plugin settings.');
}
$limit = 5; // Limiting to 5 products for the test

// Function to make API request
function make_api_request($url, $token) {
    $options = [
        'http' => [
            'header' => "Authorization: Bearer $token\r\n",
            'method' => 'GET',
            'timeout' => 30,
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    if ($response === false) {
        return [
            'success' => false,
            'error' => 'Failed to connect to API',
            'http_response_header' => $http_response_header ?? []
        ];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'JSON parsing error: ' . json_last_error_msg(),
            'raw_response' => $response
        ];
    }
    
    return [
        'success' => true,
        'data' => $data,
        'http_response_header' => $http_response_header ?? []
    ];
}

// Build the request URL
$request_url = $api_endpoint . '?' . http_build_query([
    'limit' => $limit,
    'skip' => 0
]);

// Make the API request
$result = make_api_request($request_url, $api_token);

// Output as HTML
?>
<!DOCTYPE html>
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
<body>
    <h1>RWBE API Connection Test</h1>
    
    <?php if (!$result['success']): ?>
        <h2 class="error">API Connection Error</h2>
        <p class="error"><?php echo htmlspecialchars($result['error']); ?></p>
        
        <?php if (isset($result['http_response_header']) && !empty($result['http_response_header'])): ?>
            <h3>HTTP Response Headers:</h3>
            <pre><?php print_r($result['http_response_header']); ?></pre>
        <?php endif; ?>
        
        <?php if (isset($result['raw_response'])): ?>
            <h3>Raw Response:</h3>
            <pre><?php echo htmlspecialchars($result['raw_response']); ?></pre>
        <?php endif; ?>
    <?php else: ?>
        <h2 class="success">API Connection Test Successful</h2>
        <p>Successfully connected to the RWBE API and retrieved data.</p>
        
        <?php 
        // Determine the structure of the response
        $products = [];
        if (isset($result['data']['data']) && is_array($result['data']['data'])) {
            // Response has a 'data' wrapper
            $products = $result['data']['data'];
        } elseif (is_array($result['data'])) {
            // Response is directly an array of products
            $products = $result['data'];
        }
        
        $product_count = count($products);
        ?>
        
        <p>Retrieved <?php echo $product_count; ?> products.</p>
        
        <?php if ($product_count > 0): ?>
            <h3>Sample Product Data:</h3>
            
            <table>
                <tr>
                    <th>Item Code</th>
                    <th>Title</th>
                    <th>Brand</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Category</th>
                </tr>
                
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['itemCode'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($product['title'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($product['brand']['title'] ?? 'N/A'); ?></td>
                        <td>
                            <?php 
                            $currency = isset($product['currency']['signPrefix']) ? $product['currency']['signPrefix'] : '€';
                            $price = isset($product['retailerPrice']) ? $product['retailerPrice'] : 'N/A';
                            echo htmlspecialchars("$currency $price"); 
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($product['stock'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($product['segment'] ?? 'N/A'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            
            <h3>Detailed Data for First Product:</h3>
            <pre><?php print_r($products[0]); ?></pre>
            
            <h3>HTTP Response Headers:</h3>
            <pre><?php print_r($result['http_response_header']); ?></pre>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
