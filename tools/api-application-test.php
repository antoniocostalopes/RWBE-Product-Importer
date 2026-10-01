<?php
/**
 * API Application Test
 *
 * Este script testa especificamente a presença e estrutura dos dados de aplicação (application)
 * na resposta da API RWBE.
 */

// Diagnostic script: command line only.
//
// These files live inside a directory the web server serves, so without this they
// answer HTTP requests from anyone — an unauthenticated endpoint that talks to the
// supplier API. Run them as: RWBE_API_TOKEN=xxx php tools/<script>.php.
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'This diagnostic script can only be run from the command line.' );
}


// Carregar WordPress.
require_once dirname( __DIR__, 4 ) . '/wp-load.php';

// Configuração da API.
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
// 1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php tools/api-application-test.php
// 2. the token configured in the plugin settings (when WordPress is loaded)
$api_token = getenv( 'RWBE_API_TOKEN' );
if ( ! $api_token && function_exists( 'rwbe_get_api_token' ) ) {
	$api_token = rwbe_get_api_token();
}
if ( ! $api_token ) {
	exit( 'Missing API token: set the RWBE_API_TOKEN environment variable or configure the token in the plugin settings.' );
}
$limit = 10; // Limitar a 10 produtos para teste.

// Construir a URL de requisição.
$url = $api_endpoint . '?' . http_build_query(
	[
		'limit' => $limit,
		'skip'  => 0,
	]
);

echo '<h1>Teste de Dados de Aplicação (Application)</h1>';
echo '<p>URL de teste: ' . htmlspecialchars( $url ) . '</p>';

// Inicializar sessão cURL.
$ch = curl_init();

// Configurar opções cURL.
curl_setopt( $ch, CURLOPT_URL, $url );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
curl_setopt(
	$ch,
	CURLOPT_HTTPHEADER,
	[
		'Authorization: Bearer ' . $api_token,
	]
);
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false ); // Desativar verificação SSL para teste.

// Executar a requisição.
$response   = curl_exec( $ch );
$http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$curl_error = curl_error( $ch );

// Fechar sessão cURL.
curl_close( $ch );

// Verificar erros cURL.
if ( $curl_error ) {
	echo '<h2>Erro ao conectar à API</h2>';
	echo '<p>Erro: ' . htmlspecialchars( $curl_error ) . '</p>';
	exit;
}

// Verificar código de resposta.
if ( $http_code !== 200 ) {
	echo '<h2>Erro da API</h2>';
	echo '<p>Código de resposta: ' . $http_code . '</p>';
	echo '<p>Corpo da resposta:</p>';
	echo '<pre>' . htmlspecialchars( $response ) . '</pre>';
	exit;
}

// Analisar resposta JSON.
$data = json_decode( $response, true );

// Verificar se podemos analisar o JSON.
if ( json_last_error() !== JSON_ERROR_NONE ) {
	echo '<h2>Erro ao analisar JSON</h2>';
	echo '<p>Erro: ' . json_last_error_msg() . '</p>';
	echo '<p>Resposta bruta:</p>';
	echo '<pre>' . htmlspecialchars( $response ) . '</pre>';
	exit;
}

// Determinar a estrutura dos produtos.
if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
	echo "<p>Dados estão no formato esperado com um wrapper 'data'. Encontrados " . count( $data['data'] ) . ' produtos.</p>';
	$products = $data['data'];
} elseif ( is_array( $data ) ) {
	echo "<p>Dados são diretamente um array sem um wrapper 'data'. Encontrados " . count( $data ) . ' produtos.</p>';
	$products = $data;
} else {
	echo '<p>Formato de dados inesperado. Não foi possível encontrar o array de produtos.</p>';
	exit;
}

// Verificar a estrutura de cada produto.
echo '<h2>Análise de Estrutura de Produtos</h2>';

if ( empty( $products ) ) {
	echo '<p>Nenhum produto encontrado na resposta.</p>';
	exit;
}

// Verificar a presença do campo 'application'.
$products_with_application = 0;
$application_structures    = [];

foreach ( $products as $index => $product ) {
	if ( ! empty( $product['application'] ) && is_array( $product['application'] ) ) {
		++$products_with_application;

		// Analisar a estrutura do campo 'application'.
		foreach ( $product['application'] as $app ) {
			$keys      = array_keys( $app );
			$structure = implode( ', ', $keys );
			if ( ! isset( $application_structures[ $structure ] ) ) {
				$application_structures[ $structure ] = 0;
			}
			++$application_structures[ $structure ];
		}
	}
}

echo "<p>Produtos com campo 'application': " . $products_with_application . ' de ' . count( $products ) . '</p>';

if ( ! empty( $application_structures ) ) {
	echo "<h3>Estruturas do campo 'application' encontradas:</h3>";
	echo '<ul>';
	foreach ( $application_structures as $structure => $count ) {
		echo '<li>Estrutura: [' . $structure . '] - Encontrada em ' . $count . ' aplicações</li>';
	}
	echo '</ul>';
} else {
	echo "<p>Nenhuma estrutura do campo 'application' encontrada.</p>";
}

// Mostrar detalhes dos produtos.
echo '<h2>Detalhes dos Produtos</h2>';

echo "<table border='1' cellpadding='5'>";
echo '<tr><th>Item Code</th><th>Título</th><th>Tem Application?</th><th>Detalhes de Application</th></tr>';

foreach ( $products as $index => $product ) {
	echo '<tr>';
	echo '<td>' . htmlspecialchars( $product['itemCode'] ?? 'N/A' ) . '</td>';
	echo '<td>' . htmlspecialchars( $product['title'] ?? 'N/A' ) . '</td>';

	if ( ! empty( $product['application'] ) && is_array( $product['application'] ) ) {
		echo "<td style='color:green;'>Sim</td>";

		echo '<td>';
		echo '<ul>';
		foreach ( $product['application'] as $app_index => $app ) {
			echo '<li>Aplicação #' . ( $app_index + 1 ) . ': ';
			$app_details = [];

			if ( ! empty( $app['make'] ) ) {
				$app_details[] = 'Make: ' . htmlspecialchars( $app['make'] );
			}

			if ( ! empty( $app['model'] ) ) {
				$app_details[] = 'Model: ' . htmlspecialchars( $app['model'] );
			}

			if ( ! empty( $app['beginYear'] ) ) {
				$app_details[] = 'Begin Year: ' . htmlspecialchars( $app['beginYear'] );
			}

			if ( ! empty( $app['endYear'] ) ) {
				$app_details[] = 'End Year: ' . htmlspecialchars( $app['endYear'] );
			}

			echo implode( ', ', $app_details );
			echo '</li>';
		}
		echo '</ul>';
		echo '</td>';
	} else {
		echo "<td style='color:red;'>Não</td>";
		echo '<td>N/A</td>';
	}

	echo '</tr>';
}

echo '</table>';

// Verificar se o endpoint de produto único retorna o campo 'application'.
if ( ! empty( $products[0]['id'] ) ) {
	$product_id = $products[0]['id'];

	echo '<h2>Teste de Endpoint de Produto Único</h2>';
	echo '<p>Testando produto com ID: ' . htmlspecialchars( $product_id ) . '</p>';

	$product_url = 'https://portal.racewinningbrandseurope.com/apiv2/products/product?' . http_build_query(
		[
			'product_id' => $product_id,
		]
	);

	// Inicializar sessão cURL.
	$ch = curl_init();

	// Configurar opções cURL.
	curl_setopt( $ch, CURLOPT_URL, $product_url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
	curl_setopt(
		$ch,
		CURLOPT_HTTPHEADER,
		[
			'Authorization: Bearer ' . $api_token,
		]
	);
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );

	// Executar a requisição.
	$product_response  = curl_exec( $ch );
	$product_http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

	// Fechar sessão cURL.
	curl_close( $ch );

	if ( $product_http_code === 200 ) {
		$product_data = json_decode( $product_response, true );

		if ( json_last_error() === JSON_ERROR_NONE ) {
			echo '<p>Resposta do endpoint de produto único:</p>';

			if ( ! empty( $product_data['application'] ) && is_array( $product_data['application'] ) ) {
				echo "<p style='color:green;'>Campo 'application' encontrado no endpoint de produto único.</p>";

				echo '<pre>';
				print_r( $product_data['application'] );
				echo '</pre>';
			} else {
				echo "<p style='color:red;'>Campo 'application' NÃO encontrado no endpoint de produto único.</p>";

				echo '<p>Campos disponíveis no produto:</p>';
				echo '<pre>';
				print_r( array_keys( $product_data ) );
				echo '</pre>';
			}
		} else {
			echo '<p>Erro ao analisar JSON da resposta do produto único: ' . json_last_error_msg() . '</p>';
		}
	} else {
		echo '<p>Erro ao obter dados do produto único. Código de resposta: ' . $product_http_code . '</p>';
	}
}

// Verificar se o endpoint /products/ aceita parâmetros de filtro make e model.
echo '<h2>Teste de Filtros make e model</h2>';

if ( ! empty( $products_with_application ) && ! empty( $products[0]['application'][0]['make'] ) && ! empty( $products[0]['application'][0]['model'] ) ) {
	$test_make  = $products[0]['application'][0]['make'];
	$test_model = $products[0]['application'][0]['model'];

	echo '<p>Testando filtro com make: ' . htmlspecialchars( $test_make ) . ' e model: ' . htmlspecialchars( $test_model ) . '</p>';

	$filter_url = $api_endpoint . '?' . http_build_query(
		[
			'limit' => 5,
			'make'  => $test_make,
			'model' => $test_model,
		]
	);

	// Inicializar sessão cURL.
	$ch = curl_init();

	// Configurar opções cURL.
	curl_setopt( $ch, CURLOPT_URL, $filter_url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
	curl_setopt(
		$ch,
		CURLOPT_HTTPHEADER,
		[
			'Authorization: Bearer ' . $api_token,
		]
	);
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );

	// Executar a requisição.
	$filter_response  = curl_exec( $ch );
	$filter_http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

	// Fechar sessão cURL.
	curl_close( $ch );

	if ( $filter_http_code === 200 ) {
		$filter_data = json_decode( $filter_response, true );

		if ( json_last_error() === JSON_ERROR_NONE ) {
			$filter_products = isset( $filter_data['data'] ) && is_array( $filter_data['data'] ) ? $filter_data['data'] : ( is_array( $filter_data ) ? $filter_data : [] );

			echo '<p>Produtos encontrados com os filtros: ' . count( $filter_products ) . '</p>';

			if ( ! empty( $filter_products ) ) {
				echo "<p style='color:green;'>Filtros de make e model funcionam corretamente.</p>";
			} else {
				echo "<p style='color:orange;'>Nenhum produto encontrado com os filtros especificados.</p>";
			}
		} else {
			echo '<p>Erro ao analisar JSON da resposta filtrada: ' . json_last_error_msg() . '</p>';
		}
	} else {
		echo '<p>Erro ao obter dados filtrados. Código de resposta: ' . $filter_http_code . '</p>';
	}
} else {
	echo '<p>Não foi possível testar os filtros porque nenhum produto tem dados de aplicação completos.</p>';
}
