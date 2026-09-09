<?php
/**
 * API Gallery Test
 * 
 * Este script testa especificamente a presença e estrutura do campo gallery
 * na resposta da API RWBE.
 */

// Carregar WordPress
require_once dirname(__DIR__, 4) . '/wp-load.php';

// Configuração da API
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
//   1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php tools/api-gallery-test.php
//   2. the token configured in the plugin settings (when WordPress is loaded)
$api_token = getenv('RWBE_API_TOKEN');
if (!$api_token && function_exists('rwbe_get_api_token')) {
    $api_token = rwbe_get_api_token();
}
if (!$api_token) {
    exit('Missing API token: set the RWBE_API_TOKEN environment variable or configure the token in the plugin settings.');
}
$limit = 10; // Limitar a 10 produtos para teste

// Construir a URL de requisição
$url = $api_endpoint . '?' . http_build_query([
    'limit' => $limit,
    'skip' => 0
]);

echo "<h1>Teste de Dados de Galeria (Gallery)</h1>";
echo "<p>URL de teste: " . htmlspecialchars($url) . "</p>";

// Inicializar sessão cURL
$ch = curl_init();

// Configurar opções cURL
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $api_token
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Desativar verificação SSL para teste

// Executar a requisição
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

// Fechar sessão cURL
curl_close($ch);

// Verificar erros cURL
if ($curl_error) {
    echo "<h2>Erro ao conectar à API</h2>";
    echo "<p>Erro: " . htmlspecialchars($curl_error) . "</p>";
    exit;
}

// Verificar código de resposta
if ($http_code !== 200) {
    echo "<h2>Erro da API</h2>";
    echo "<p>Código de resposta: " . $http_code . "</p>";
    echo "<p>Corpo da resposta:</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

// Analisar resposta JSON
$data = json_decode($response, true);

// Verificar se podemos analisar o JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "<h2>Erro ao analisar JSON</h2>";
    echo "<p>Erro: " . json_last_error_msg() . "</p>";
    echo "<p>Resposta bruta:</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}

// Determinar a estrutura dos produtos
if (isset($data['data']) && is_array($data['data'])) {
    echo "<p>Dados estão no formato esperado com um wrapper 'data'. Encontrados " . count($data['data']) . " produtos.</p>";
    $products = $data['data'];
} elseif (is_array($data)) {
    echo "<p>Dados são diretamente um array sem um wrapper 'data'. Encontrados " . count($data) . " produtos.</p>";
    $products = $data;
} else {
    echo "<p>Formato de dados inesperado. Não foi possível encontrar o array de produtos.</p>";
    exit;
}

// Verificar a estrutura de cada produto
echo "<h2>Análise de Estrutura de Produtos</h2>";

if (empty($products)) {
    echo "<p>Nenhum produto encontrado na resposta.</p>";
    exit;
}

// Verificar a presença do campo 'gallery'
$products_with_gallery = 0;
$gallery_structures = [];
$gallery_examples = [];

foreach ($products as $index => $product) {
    if (!empty($product['gallery'])) {
        $products_with_gallery++;
        
        // Analisar a estrutura do campo 'gallery'
        $structure_type = gettype($product['gallery']);
        
        if (!isset($gallery_structures[$structure_type])) {
            $gallery_structures[$structure_type] = 0;
        }
        $gallery_structures[$structure_type]++;
        
        // Guardar exemplos de cada tipo de estrutura
        if (count($gallery_examples) < 3 && !isset($gallery_examples[$structure_type])) {
            $gallery_examples[$structure_type] = $product['gallery'];
        }
    }
}

echo "<p>Produtos com campo 'gallery': " . $products_with_gallery . " de " . count($products) . "</p>";

if (!empty($gallery_structures)) {
    echo "<h3>Estruturas do campo 'gallery' encontradas:</h3>";
    echo "<ul>";
    foreach ($gallery_structures as $structure => $count) {
        echo "<li>Tipo: " . $structure . " - Encontrado em " . $count . " produtos</li>";
    }
    echo "</ul>";
    
    echo "<h3>Exemplos de estruturas:</h3>";
    foreach ($gallery_examples as $structure => $example) {
        echo "<h4>Exemplo de estrutura tipo: " . $structure . "</h4>";
        echo "<pre>";
        print_r($example);
        echo "</pre>";
    }
} else {
    echo "<p>Nenhuma estrutura do campo 'gallery' encontrada.</p>";
}

// Agora vamos testar o endpoint de produto único para verificar a estrutura do campo gallery
if (!empty($products[0]['id'])) {
    $product_id = $products[0]['id'];
    
    echo "<h2>Teste de Endpoint de Produto Único</h2>";
    echo "<p>Testando produto com ID: " . htmlspecialchars($product_id) . "</p>";
    
    $product_url = 'https://portal.racewinningbrandseurope.com/apiv2/products/product?' . http_build_query([
        'product_id' => $product_id
    ]);
    
    // Inicializar sessão cURL
    $ch = curl_init();
    
    // Configurar opções cURL
    curl_setopt($ch, CURLOPT_URL, $product_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Executar a requisição
    $product_response = curl_exec($ch);
    $product_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Fechar sessão cURL
    curl_close($ch);
    
    if ($product_http_code === 200) {
        $product_data = json_decode($product_response, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "<p>Resposta do endpoint de produto único:</p>";
            
            if (!empty($product_data['gallery'])) {
                echo "<p style='color:green;'>Campo 'gallery' encontrado no endpoint de produto único.</p>";
                
                echo "<h4>Estrutura do campo 'gallery':</h4>";
                echo "<p>Tipo: " . gettype($product_data['gallery']) . "</p>";
                
                echo "<pre>";
                print_r($product_data['gallery']);
                echo "</pre>";
                
                // Se for um array, vamos analisar os itens
                if (is_array($product_data['gallery'])) {
                    echo "<h4>Análise dos itens da galeria:</h4>";
                    
                    foreach ($product_data['gallery'] as $index => $gallery_item) {
                        echo "<p><strong>Item #" . ($index + 1) . ":</strong></p>";
                        echo "<p>Tipo: " . gettype($gallery_item) . "</p>";
                        
                        if (is_string($gallery_item)) {
                            echo "<p>URL: " . htmlspecialchars($gallery_item) . "</p>";
                            echo "<p>Começa com http/https: " . (preg_match('/^https?:\/\//i', $gallery_item) ? 'Sim' : 'Não') . "</p>";
                            
                            // Tentar exibir a imagem
                            $display_url = $gallery_item;
                            if (!preg_match('/^https?:\/\//i', $display_url)) {
                                $display_url = 'https://' . ltrim($display_url, '/');
                            }
                            
                            echo "<p><img src='" . htmlspecialchars($display_url) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                        } elseif (is_array($gallery_item)) {
                            echo "<pre>";
                            print_r($gallery_item);
                            echo "</pre>";
                            
                            // Verificar se há um campo url ou src
                            if (!empty($gallery_item['url'])) {
                                echo "<p>URL encontrado: " . htmlspecialchars($gallery_item['url']) . "</p>";
                                echo "<p><img src='" . htmlspecialchars($gallery_item['url']) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                            } elseif (!empty($gallery_item['src'])) {
                                echo "<p>SRC encontrado: " . htmlspecialchars($gallery_item['src']) . "</p>";
                                echo "<p><img src='" . htmlspecialchars($gallery_item['src']) . "' style='max-width: 200px; max-height: 200px;' /></p>";
                            }
                        }
                    }
                }
            } else {
                echo "<p style='color:red;'>Campo 'gallery' NÃO encontrado no endpoint de produto único.</p>";
                
                echo "<p>Campos disponíveis no produto:</p>";
                echo "<pre>";
                print_r(array_keys($product_data));
                echo "</pre>";
            }
        } else {
            echo "<p>Erro ao analisar JSON da resposta do produto único: " . json_last_error_msg() . "</p>";
        }
    } else {
        echo "<p>Erro ao obter dados do produto único. Código de resposta: " . $product_http_code . "</p>";
    }
}

// Verificar se o campo gallery é um array de strings ou um array de objetos
echo "<h2>Análise Detalhada da Estrutura da Galeria</h2>";

$gallery_item_types = [];
$gallery_item_examples = [];

foreach ($products as $product) {
    if (!empty($product['gallery']) && is_array($product['gallery'])) {
        foreach ($product['gallery'] as $gallery_item) {
            $item_type = gettype($gallery_item);
            
            if (!isset($gallery_item_types[$item_type])) {
                $gallery_item_types[$item_type] = 0;
                $gallery_item_examples[$item_type] = $gallery_item;
            }
            
            $gallery_item_types[$item_type]++;
        }
    }
}

if (!empty($gallery_item_types)) {
    echo "<h3>Tipos de itens encontrados na galeria:</h3>";
    echo "<ul>";
    foreach ($gallery_item_types as $type => $count) {
        echo "<li>Tipo: " . $type . " - Encontrado " . $count . " vezes</li>";
    }
    echo "</ul>";
    
    echo "<h3>Exemplos de cada tipo:</h3>";
    foreach ($gallery_item_examples as $type => $example) {
        echo "<h4>Exemplo de item tipo: " . $type . "</h4>";
        
        if ($type === 'string') {
            echo "<p>URL: " . htmlspecialchars($example) . "</p>";
        } else {
            echo "<pre>";
            print_r($example);
            echo "</pre>";
        }
    }
} else {
    echo "<p>Nenhum item de galeria encontrado para análise.</p>";
}
