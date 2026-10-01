<?php
/**
 * RWBE API Tester Class
 *
 * @since 1.0.0
 */

// Exit when accessed directly: these files only make sense inside WordPress.
if (!defined('WPINC')) {
    die;
}

class RWBE_API_Tester {

    /**
     * Test the connection to the RWBE API
     *
     * @return array Test results
     */
    public static function test_connection($token = null) {
        $results = array(
            'success' => false,
            'message' => '',
            'data' => null,
            'error' => ''
        );

        // API configuration
        $api_endpoint = RWBE_API_ENDPOINT;
        // Use the provided token (e.g. the value typed in the settings field) when
        // given; otherwise fall back to the saved token.
        if (is_string($token) && trim($token) !== '') {
            $api_token = trim($token);
        } else {
            $api_token = function_exists('rwbe_get_api_token') ? rwbe_get_api_token() : '';
        }

        // No credential configured: fail early instead of sending an empty Bearer header.
        if ($api_token === '') {
            $results['error'] = __('Nenhum API Token configurado. Introduza o token no campo "API Token" das Configurações (ou defina a constante RWBE_API_AUTH_TOKEN no wp-config.php).', 'rwbe-product-importer');
            $results['message'] = $results['error'];
            return $results;
        }
        $limit = 5; // Limit to 5 products for testing

        // Build the request URL
        $url = add_query_arg(
            array(
                'limit' => $limit,
                'skip' => 0
            ),
            $api_endpoint
        );

        // Make the API request
        $response = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_token
                ),
                'timeout' => 30
            )
        );

        // Check for errors
        if (is_wp_error($response)) {
            $results['error'] = $response->get_error_message();
            $results['message'] = 'Error connecting to RWBE API: ' . $results['error'];
            return $results;
        }

        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $results['error'] = 'HTTP Error: ' . $response_code;
            $results['message'] = 'API returned error code: ' . $response_code;
            return $results;
        }

        // Get the response body
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check if we can parse the JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            $results['error'] = 'JSON Error: ' . json_last_error_msg();
            $results['message'] = 'Failed to parse API response: ' . json_last_error_msg();
            return $results;
        }

        // Check if we have products
        $products = isset($data['data']) ? $data['data'] : $data;
        if (!is_array($products) || empty($products)) {
            $results['error'] = 'No products found';
            $results['message'] = 'API returned no products';
            return $results;
        }

        // Success!
        $results['success'] = true;
        $results['message'] = 'Successfully connected to RWBE API and retrieved ' . count($products) . ' products.';
        $results['data'] = $products;

        return $results;
    }

    /**
     * Render the test results as HTML
     *
     * @param array $results Test results
     * @return string HTML output
     */
    public static function render_results($results) {
        ob_start();
        ?>
        <div class="rwbe-api-test-results">
            <?php if ($results['success']): ?>
                <div class="rwbe-api-test-success">
                    <h3><?php _e('API Connection Successful!', 'rwbe-product-importer'); ?></h3>
                    <p><?php echo esc_html($results['message']); ?></p>
                </div>

                <?php if (!empty($results['data'])): ?>
                    <h4><?php _e('Sample Product Data:', 'rwbe-product-importer'); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php _e('Item Code', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Title', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Brand', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Price', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Stock', 'rwbe-product-importer'); ?></th>
                                <th><?php _e('Category', 'rwbe-product-importer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['data'] as $product): ?>
                                <tr>
                                    <td><?php echo esc_html($product['itemCode'] ?? 'N/A'); ?></td>
                                    <td><?php echo esc_html($product['title'] ?? 'N/A'); ?></td>
                                    <td><?php echo esc_html($product['brand']['title'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $currency = isset($product['currency']['signPrefix']) ? $product['currency']['signPrefix'] : '€';
                                        $price = isset($product['retailerPrice']) ? $product['retailerPrice'] : 'N/A';
                                        echo esc_html("$currency $price"); 
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($product['stock'] ?? 'N/A'); ?></td>
                                    <td><?php echo esc_html($product['segment'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h4><?php _e('First Product Details:', 'rwbe-product-importer'); ?></h4>
                    <pre><?php print_r($results['data'][0]); ?></pre>
                <?php endif; ?>
            <?php else: ?>
                <div class="rwbe-api-test-error">
                    <h3><?php _e('API Connection Failed', 'rwbe-product-importer'); ?></h3>
                    <p><?php echo esc_html($results['message']); ?></p>
                    <?php if (!empty($results['error'])): ?>
                        <p><strong><?php _e('Error:', 'rwbe-product-importer'); ?></strong> <?php echo esc_html($results['error']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
