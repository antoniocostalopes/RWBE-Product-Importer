<?php
/**
 * API Product Images Test
 * 
 * Este script testa especificamente a presença de imagens de produto
 * em endpoints específicos da API RWBE.
 */

// Carregar WordPress
require_once dirname(__DIR__, 4) . '/wp-load.php';

// Configuração da API
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
//   1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php tools/api-product-images-test.php
//   2. the token configured in the plugin settings (when WordPress is loaded)
$api_token = getenv('RWBE_API_TOKEN');
if (!$api_token && function_exists('rwbe_get_api_token')) {
    $api_token = rwbe_get_api_token();
}
if (!$api_token) {
    exit('Missing API token: set the RWBE_API_TOKEN environment variable or configure the token in the plugin settings.');
}

// ID do produto para teste (usando o ID do exemplo fornecido)
$product_id = 'D1316C32-717A-0054-1CF8-B5643B00742D';

echo "<h1>Teste de Imagens de Produto</h1>";
echo "<p>Testando produto com ID: " . htmlspecialchars($product_id) . "</p>";

// Função para fazer requisições à API
function make_api_request($url, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'response' => $response,
        'http_code' => $http_code,
        'error' => $curl_error
    ];
}

// 1. Testar o endpoint de produto único
$product_url = 'https://portal.racewinningbrandseurope.com/apiv2/products/product?' . http_build_query([
    'product_id' => $product_id
]);

echo "<h2>1. Teste de Endpoint de Produto Único</h2>";
echo "<p>URL: " . htmlspecialchars($product_url) . "</p>";

$product_result = make_api_request($product_url, $api_token);

if ($product_result['error']) {
    echo "<p style='color:red;'>Erro: " . htmlspecialchars($product_result['error']) . "</p>";
} elseif ($product_result['http_code'] !== 200) {
    echo "<p style='color:red;'>Código de resposta: " . $product_result['http_code'] . "</p>";
} else {
    $product_data = json_decode($product_result['response'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<p style='color:red;'>Erro ao analisar JSON: " . json_last_error_msg() . "</p>";
    } else {
        echo "<h3>Dados do Produto:</h3>";
        echo "<pre>";
        print_r($product_data);
        echo "</pre>";
        
        echo "<h3>Campos relacionados a imagens:</h3>";
        echo "<ul>";
        
        if (!empty($product_data['photo'])) {
            echo "<li>photo: " . htmlspecialchars($product_data['photo']) . "</li>";
            echo "<p><img src='" . htmlspecialchars($product_data['photo']) . "' style='max-width: 200px; max-height: 200px;' /></p>";
        } else {
            echo "<li style='color:red;'>photo: Não encontrado</li>";
        }
        
        if (!empty($product_data['gallery'])) {
            echo "<li>gallery: Encontrado (array com " . count($product_data['gallery']) . " itens)</li>";
            
            echo "<ul>";
            foreach ($product_data['gallery'] as $index => $gallery_item) {
                echo "<li>Item #" . ($index + 1) . ": ";
                
                if (is_string($gallery_item)) {
                    echo htmlspecialchars($gallery_item);
                    echo "<p><img src='" . htmlspecialchars($gallery_item) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                } else {
                    echo "<pre>";
                    print_r($gallery_item);
                    echo "</pre>";
                }
                
                echo "</li>";
            }
            echo "</ul>";
        } else {
            echo "<li style='color:red;'>gallery: Não encontrado</li>";
        }
        
        echo "</ul>";
    }
}

// 2. Testar o endpoint de imagens do produto
$images_url = 'https://portal.racewinningbrandseurope.com/apiv2/products/images?' . http_build_query([
    'product_id' => $product_id
]);

echo "<h2>2. Teste de Endpoint de Imagens do Produto</h2>";
echo "<p>URL: " . htmlspecialchars($images_url) . "</p>";

$images_result = make_api_request($images_url, $api_token);

if ($images_result['error']) {
    echo "<p style='color:red;'>Erro: " . htmlspecialchars($images_result['error']) . "</p>";
} elseif ($images_result['http_code'] !== 200) {
    echo "<p style='color:red;'>Código de resposta: " . $images_result['http_code'] . "</p>";
    echo "<p>Resposta:</p>";
    echo "<pre>" . htmlspecialchars($images_result['response']) . "</pre>";
} else {
    $images_data = json_decode($images_result['response'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<p style='color:red;'>Erro ao analisar JSON: " . json_last_error_msg() . "</p>";
        echo "<p>Resposta bruta:</p>";
        echo "<pre>" . htmlspecialchars($images_result['response']) . "</pre>";
    } else {
        echo "<h3>Dados de Imagens:</h3>";
        echo "<pre>";
        print_r($images_data);
        echo "</pre>";
        
        if (is_array($images_data)) {
            echo "<h3>Visualização das Imagens:</h3>";
            
            foreach ($images_data as $index => $image) {
                echo "<h4>Imagem #" . ($index + 1) . ":</h4>";
                
                if (is_string($image)) {
                    echo "<p>URL: " . htmlspecialchars($image) . "</p>";
                    echo "<p><img src='" . htmlspecialchars($image) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                } elseif (is_array($image)) {
                    echo "<pre>";
                    print_r($image);
                    echo "</pre>";
                    
                    // Tentar encontrar a URL da imagem
                    $image_url = null;
                    
                    if (!empty($image['url'])) {
                        $image_url = $image['url'];
                    } elseif (!empty($image['src'])) {
                        $image_url = $image['src'];
                    } elseif (!empty($image['path'])) {
                        $image_url = $image['path'];
                    }
                    
                    if ($image_url) {
                        echo "<p><img src='" . htmlspecialchars($image_url) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                    }
                }
            }
        }
    }
}

// 3. Testar o endpoint de galeria do produto
$gallery_url = 'https://portal.racewinningbrandseurope.com/apiv2/products/gallery?' . http_build_query([
    'product_id' => $product_id
]);

echo "<h2>3. Teste de Endpoint de Galeria do Produto</h2>";
echo "<p>URL: " . htmlspecialchars($gallery_url) . "</p>";

$gallery_result = make_api_request($gallery_url, $api_token);

if ($gallery_result['error']) {
    echo "<p style='color:red;'>Erro: " . htmlspecialchars($gallery_result['error']) . "</p>";
} elseif ($gallery_result['http_code'] !== 200) {
    echo "<p style='color:red;'>Código de resposta: " . $gallery_result['http_code'] . "</p>";
    echo "<p>Resposta:</p>";
    echo "<pre>" . htmlspecialchars($gallery_result['response']) . "</pre>";
} else {
    $gallery_data = json_decode($gallery_result['response'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<p style='color:red;'>Erro ao analisar JSON: " . json_last_error_msg() . "</p>";
        echo "<p>Resposta bruta:</p>";
        echo "<pre>" . htmlspecialchars($gallery_result['response']) . "</pre>";
    } else {
        echo "<h3>Dados da Galeria:</h3>";
        echo "<pre>";
        print_r($gallery_data);
        echo "</pre>";
        
        if (is_array($gallery_data)) {
            echo "<h3>Visualização das Imagens da Galeria:</h3>";
            
            foreach ($gallery_data as $index => $image) {
                echo "<h4>Imagem #" . ($index + 1) . ":</h4>";
                
                if (is_string($image)) {
                    echo "<p>URL: " . htmlspecialchars($image) . "</p>";
                    echo "<p><img src='" . htmlspecialchars($image) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                } elseif (is_array($image)) {
                    echo "<pre>";
                    print_r($image);
                    echo "</pre>";
                    
                    // Tentar encontrar a URL da imagem
                    $image_url = null;
                    
                    if (!empty($image['url'])) {
                        $image_url = $image['url'];
                    } elseif (!empty($image['src'])) {
                        $image_url = $image['src'];
                    } elseif (!empty($image['path'])) {
                        $image_url = $image['path'];
                    }
                    
                    if ($image_url) {
                        echo "<p><img src='" . htmlspecialchars($image_url) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                    }
                }
            }
        }
    }
}

// 4. Testar a construção de URLs de imagem com base no ID do produto
echo "<h2>4. Teste de Construção de URLs de Imagem</h2>";

$possible_image_patterns = [
    'Imagem Principal' => 'https://portal.racewinningbrandseurope.com/apiv2/image/product/{product_id}',
    'Galeria 1' => 'https://portal.racewinningbrandseurope.com/apiv2/image/product/{product_id}/1',
    'Galeria 2' => 'https://portal.racewinningbrandseurope.com/apiv2/image/product/{product_id}/2',
    'Galeria 3' => 'https://portal.racewinningbrandseurope.com/apiv2/image/product/{product_id}/3',
    'Galeria 4' => 'https://portal.racewinningbrandseurope.com/apiv2/image/product/{product_id}/4',
    'Galeria 5' => 'https://portal.racewinningbrandseurope.com/apiv2/image/product/{product_id}/5',
];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Descrição</th><th>URL</th><th>Imagem</th></tr>";

foreach ($possible_image_patterns as $description => $pattern) {
    $url = str_replace('{product_id}', $product_id, $pattern);
    
    echo "<tr>";
    echo "<td>" . htmlspecialchars($description) . "</td>";
    echo "<td>" . htmlspecialchars($url) . "</td>";
    echo "<td><img src='" . htmlspecialchars($url) . "' style='max-width: 200px; max-height: 200px;' /></td>";
    echo "</tr>";
}

echo "</table>";
