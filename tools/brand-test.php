<?php
/**
 * Brand Test Script
 *
 * Este script testa especificamente a criação e atribuição de marcas no WooCommerce
 * a partir dos dados da API RWBE.
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

// Verificar se o WooCommerce está ativo.
if ( ! class_exists( 'WooCommerce' ) ) {
	die( 'WooCommerce não está ativo.' );
}

// Configuração da API.
$api_endpoint = 'https://portal.racewinningbrandseurope.com/apiv2/products/';
// The API token is never hard-coded in this repository. It is read from, in order:
// 1. the RWBE_API_TOKEN environment variable, e.g. RWBE_API_TOKEN=xxx php tools/brand-test.php
// 2. the token configured in the plugin settings (when WordPress is loaded)
$api_token = getenv( 'RWBE_API_TOKEN' );
if ( ! $api_token && function_exists( 'rwbe_get_api_token' ) ) {
	$api_token = rwbe_get_api_token();
}
if ( ! $api_token ) {
	exit( 'Missing API token: set the RWBE_API_TOKEN environment variable or configure the token in the plugin settings.' );
}
$limit = 5; // Limitar a 5 produtos para teste.

// Construir a URL de requisição.
$url = $api_endpoint . '?' . http_build_query(
	[
		'limit' => $limit,
		'skip'  => 0,
	]
);

echo '<h1>Teste de Marcas (Brands)</h1>';
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

// Analisar a estrutura das marcas.
echo '<h2>Análise de Estrutura de Marcas</h2>';

if ( empty( $products ) ) {
	echo '<p>Nenhum produto encontrado na resposta.</p>';
	exit;
}

echo "<table border='1' cellpadding='5'>";
echo '<tr><th>Item Code</th><th>Marca (Estrutura)</th><th>Valor da Marca</th></tr>';

foreach ( $products as $product ) {
	echo '<tr>';
	echo '<td>' . htmlspecialchars( $product['itemCode'] ?? 'N/A' ) . '</td>';

	// Analisar a estrutura da marca.
	$brand_structure = 'Não encontrada';
	$brand_value     = 'N/A';

	if ( isset( $product['brand'] ) ) {
		if ( is_array( $product['brand'] ) && isset( $product['brand']['title'] ) ) {
			$brand_structure = "Array com chave 'title'";
			$brand_value     = htmlspecialchars( $product['brand']['title'] );
		} elseif ( is_string( $product['brand'] ) ) {
			$brand_structure = 'String direta';
			$brand_value     = htmlspecialchars( $product['brand'] );
		} else {
			$brand_structure = 'Outro formato: ' . gettype( $product['brand'] );
			$brand_value     = 'Não extraível';
		}
	} else {
		// Verificar outras possíveis chaves.
		$possible_keys = [ 'brand_name', 'brandName', 'brandTitle', 'brand_title', 'manufacturer' ];
		foreach ( $possible_keys as $key ) {
			if ( isset( $product[ $key ] ) ) {
				$brand_structure = 'Chave alternativa: ' . $key;
				$brand_value     = htmlspecialchars( $product[ $key ] );
				break;
			}
		}
	}

	echo '<td>' . $brand_structure . '</td>';
	echo '<td>' . $brand_value . '</td>';
	echo '</tr>';
}

echo '</table>';

// Testar a criação de atributo e termo.
echo '<h2>Teste de Criação de Atributo e Termo</h2>';

// Nome do atributo.
$attribute_name  = 'pa_marca';
$attribute_label = 'Marca';
$attribute_slug  = 'marca';

// Verificar se o atributo existe.
echo '<h3>Verificando Atributo Existente</h3>';
$attribute_taxonomies = wc_get_attribute_taxonomies();
$attribute_exists     = false;
$attribute_id         = 0;

echo '<p>Taxonomias de atributos existentes:</p>';
echo '<ul>';
foreach ( $attribute_taxonomies as $tax ) {
	echo '<li>' . $tax->attribute_name . ' (ID: ' . $tax->attribute_id . ')</li>';
	if ( $tax->attribute_name === $attribute_slug ) {
		$attribute_exists = true;
		$attribute_id     = $tax->attribute_id;
	}
}
echo '</ul>';

if ( $attribute_exists ) {
	echo "<p>Atributo 'marca' encontrado com ID: " . $attribute_id . '</p>';
} else {
	echo "<p>Atributo 'marca' não encontrado. Tentando criar...</p>";

	// Criar o atributo.
	$args = array(
		'name'         => $attribute_label,
		'slug'         => $attribute_slug,
		'type'         => 'select',
		'order_by'     => 'menu_order',
		'has_archives' => false,
	);

	$result = wc_create_attribute( $args );

	if ( is_wp_error( $result ) ) {
		echo '<p>Erro ao criar atributo: ' . $result->get_error_message() . '</p>';
	} else {
		echo '<p>Atributo criado com sucesso com ID: ' . $result . '</p>';
		$attribute_id = $result;

		// Forçar a atualização do cache de atributos do WooCommerce.
		delete_transient( 'wc_attribute_taxonomies' );

		// Registrar a taxonomia.
		$taxonomy_args = array(
			'labels'       => array(
				'name' => $attribute_label,
			),
			'hierarchical' => false,
			'show_ui'      => true,
			'query_var'    => true,
			'rewrite'      => false,
		);

		register_taxonomy( $attribute_name, array( 'product' ), $taxonomy_args );

		if ( taxonomy_exists( $attribute_name ) ) {
			echo '<p>Taxonomia registrada com sucesso: ' . $attribute_name . '</p>';
		} else {
			echo '<p>Falha ao registrar taxonomia: ' . $attribute_name . '</p>';
		}

		// Limpar o cache de taxonomias.
		delete_option( 'woocommerce_attribute_taxonomies' );
		wp_cache_flush();
	}
}

// Verificar se a taxonomia existe.
echo '<h3>Verificando Taxonomia</h3>';
if ( taxonomy_exists( $attribute_name ) ) {
	echo '<p>Taxonomia existe: ' . $attribute_name . '</p>';

	// Testar a criação de um termo de marca.
	if ( ! empty( $products ) ) {
		$test_product = $products[0];
		$brand_name   = '';

		// Extrair o nome da marca.
		if ( isset( $test_product['brand'] ) ) {
			if ( is_array( $test_product['brand'] ) && isset( $test_product['brand']['title'] ) ) {
				$brand_name = sanitize_text_field( $test_product['brand']['title'] );
			} elseif ( is_string( $test_product['brand'] ) ) {
				$brand_name = sanitize_text_field( $test_product['brand'] );
			}
		}

		if ( ! empty( $brand_name ) ) {
			echo '<h3>Testando Criação de Termo para Marca: ' . htmlspecialchars( $brand_name ) . '</h3>';

			// Verificar se o termo já existe.
			$term = term_exists( $brand_name, $attribute_name );

			if ( $term ) {
				echo '<p>Termo já existe com ID: ' . $term['term_id'] . '</p>';
			} else {
				echo '<p>Termo não existe, tentando criar...</p>';

				$term = wp_insert_term( $brand_name, $attribute_name );

				if ( is_wp_error( $term ) ) {
					echo '<p>Erro ao criar termo: ' . $term->get_error_message() . '</p>';
				} else {
					echo '<p>Termo criado com sucesso com ID: ' . $term['term_id'] . '</p>';
				}
			}
		} else {
			echo '<p>Não foi possível extrair um nome de marca válido do produto de teste.</p>';
		}
	} else {
		echo '<p>Nenhum produto disponível para testar a criação de termo.</p>';
	}
} else {
	echo '<p>Taxonomia não existe: ' . $attribute_name . '</p>';
}

// Listar todos os termos de marca existentes.
echo '<h3>Termos de Marca Existentes</h3>';
$terms = get_terms(
	[
		'taxonomy'   => $attribute_name,
		'hide_empty' => false,
	]
);

if ( is_wp_error( $terms ) ) {
	echo '<p>Erro ao obter termos: ' . $terms->get_error_message() . '</p>';
} elseif ( empty( $terms ) ) {
	echo '<p>Nenhum termo de marca encontrado.</p>';
} else {
	echo '<p>Encontrados ' . count( $terms ) . ' termos de marca:</p>';
	echo '<ul>';
	foreach ( $terms as $term ) {
		echo '<li>' . $term->name . ' (ID: ' . $term->term_id . ')</li>';
	}
	echo '</ul>';
}

// Verificar produtos com o atributo de marca.
echo '<h3>Produtos com Atributo de Marca</h3>';
$products_with_brand = new WP_Query(
	[
		'post_type'      => 'product',
		'posts_per_page' => 10,
		'tax_query'      => [
			[
				'taxonomy' => $attribute_name,
				'operator' => 'EXISTS',
			],
		],
	]
);

if ( $products_with_brand->have_posts() ) {
	echo '<p>Encontrados ' . $products_with_brand->found_posts . ' produtos com atributo de marca:</p>';
	echo '<ul>';
	while ( $products_with_brand->have_posts() ) {
		$products_with_brand->the_post();
		$product_id         = get_the_ID();
		$product            = wc_get_product( $product_id );
		$product_attributes = $product->get_attributes();

		echo '<li>' . get_the_title() . ' (ID: ' . $product_id . ')';

		if ( isset( $product_attributes[ $attribute_name ] ) ) {
			$terms = wc_get_product_terms( $product_id, $attribute_name, [ 'fields' => 'names' ] );
			echo ' - Marca(s): ' . implode( ', ', $terms );
		} else {
			echo ' - Atributo existe mas sem termos';
		}

		echo '</li>';
	}
	echo '</ul>';
	wp_reset_postdata();
} else {
	echo '<p>Nenhum produto encontrado com atributo de marca.</p>';
}
