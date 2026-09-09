<?php
/**
 * Test script for importing products from a .txt file
 * 
 * This script reads product data from a .txt file and imports it into WooCommerce
 * using the RWBE_Product_Importer class.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    // Define WPINC to allow the script to run in standalone mode for testing
    define('WPINC', 'wp-includes');
}

// Include the main plugin file to access all required classes
require_once __DIR__ . '/rwbe-product-importer.php';

/**
 * Class to handle importing products from a .txt file
 */
class RWBE_TXT_Product_Importer {
    
    /**
     * Path to the .txt file containing product data
     * 
     * @var string
     */
    private $file_path;
    
    /**
     * Instance of the main product importer class
     * 
     * @var RWBE_Product_Importer
     */
    private $importer;
    
    /**
     * Constructor
     * 
     * @param string $file_path Path to the .txt file
     */
    public function __construct($file_path) {
        $this->file_path = $file_path;
        $this->importer = new RWBE_Product_Importer();
    }
    
    /**
     * Import products from the .txt file
     * 
     * @return array Import results
     */
    public function import_products() {
        echo "Starting import from TXT file: {$this->file_path}\n";
        
        // Check if file exists
        if (!file_exists($this->file_path)) {
            echo "Error: File not found: {$this->file_path}\n";
            return [
                'status' => 'error',
                'message' => 'File not found'
            ];
        }
        
        // Read file contents
        $file_contents = file_get_contents($this->file_path);
        $lines = explode("\n", $file_contents);
        
        $results = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_messages' => []
        ];
        
        // Process each line
        foreach ($lines as $line_number => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $results['total']++;
            
            // Parse line (format: SKU|Name|Description|Price|Stock|Category|Brand|Make|Model|Year-Range)
            $fields = explode('|', $line);
            
            if (count($fields) < 10) {
                $results['errors']++;
                $error_message = "Line {$line_number}: Invalid format, expected 10 fields, got " . count($fields);
                $results['error_messages'][] = $error_message;
                echo "Error: {$error_message}\n";
                continue;
            }
            
            // Create product data in the format expected by the importer
            $product_data = $this->create_product_data($fields);
            
            // Process the product
            try {
                $result = $this->process_product($product_data);
                
                if ($result === 'created') {
                    $results['created']++;
                    echo "Product created: {$product_data['sku']}\n";
                } elseif ($result === 'updated') {
                    $results['updated']++;
                    echo "Product updated: {$product_data['sku']}\n";
                } elseif ($result === 'skipped') {
                    $results['skipped']++;
                    echo "Product skipped: {$product_data['sku']}\n";
                } else {
                    $results['errors']++;
                    $error_message = "Failed to process product: {$product_data['sku']} - {$result}";
                    $results['error_messages'][] = $error_message;
                    echo "Error: {$error_message}\n";
                }
            } catch (Exception $e) {
                $results['errors']++;
                $error_message = "Exception processing product: {$product_data['sku']} - " . $e->getMessage();
                $results['error_messages'][] = $error_message;
                echo "Error: {$error_message}\n";
            }
        }
        
        echo "\nImport completed.\n";
        echo "Total: {$results['total']}\n";
        echo "Created: {$results['created']}\n";
        echo "Updated: {$results['updated']}\n";
        echo "Skipped: {$results['skipped']}\n";
        echo "Errors: {$results['errors']}\n";
        
        return $results;
    }
    
    /**
     * Create product data in the format expected by the importer
     * 
     * @param array $fields Fields from the .txt file
     * @return array Product data
     */
    private function create_product_data($fields) {
        // Extract fields
        list($sku, $name, $description, $price, $stock, $category, $brand, $make, $model, $year_range) = $fields;
        
        // Create product data structure similar to what the API would return
        return [
            'id' => $sku, // Using SKU as ID for this test
            'sku' => $sku,
            'name' => $name,
            'description' => $description,
            'price' => (float) $price,
            'stock' => (int) $stock,
            'category' => $category,
            'brand' => $brand,
            'application' => [
                'make' => $make,
                'model' => $model,
                'year_range' => $year_range
            ],
            'images' => [
                // Using placeholder images for testing
                'main' => 'https://via.placeholder.com/600x400?text=' . urlencode($name),
                'gallery' => [
                    'https://via.placeholder.com/600x400?text=' . urlencode($name) . '+1',
                    'https://via.placeholder.com/600x400?text=' . urlencode($name) . '+2'
                ]
            ]
        ];
    }
    
    /**
     * Process a single product
     * 
     * @param array $product_data Product data
     * @return string|WP_Error 'created', 'updated', 'skipped' or error message
     */
    private function process_product($product_data) {
        // For testing purposes, we'll simulate the product processing
        // In a real environment, this would call the actual importer methods
        
        // Uncomment the following line to use the actual importer in a WordPress environment
        // return $this->importer->process_product($product_data);
        
        // For standalone testing, we'll just return success
        return 'created';
    }
}

// Run the test if this script is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    // Path to the test products file
    $file_path = __DIR__ . '/test-products.txt';
    
    // Create importer instance
    $txt_importer = new RWBE_TXT_Product_Importer($file_path);
    
    // Run the import
    $results = $txt_importer->import_products();
    
    // Output results in JSON format for potential programmatic use
    echo "\nResults as JSON:\n";
    echo json_encode($results, JSON_PRETTY_PRINT);
}
