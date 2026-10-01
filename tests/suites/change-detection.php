<?php
require_once __DIR__ . '/../helpers.php';

define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

// Meta store that behaves like wp_postmeta: everything comes back as a string.
$GLOBALS['meta']        = [];
$GLOBALS['meta_writes'] = 0;

function metadata_exists( $type, $id, $key ) {
	return array_key_exists( "{$id}|{$key}", $GLOBALS['meta'] ); }
function get_post_meta( $id, $key, $single = false ) {
	$k = "{$id}|{$key}";
	return array_key_exists( $k, $GLOBALS['meta'] ) ? $GLOBALS['meta'][ $k ] : '';
}
function update_post_meta( $id, $key, $value ) {
	++$GLOBALS['meta_writes'];
	$GLOBALS['meta'][ "{$id}|{$key}" ] = (string) $value; // the DB stores strings.
	return true;
}
function delete_post_meta( $id, $key ) {
	$k = "{$id}|{$key}";
	if ( ! array_key_exists( $k, $GLOBALS['meta'] ) ) {
		return false; }
	unset( $GLOBALS['meta'][ $k ] );
	return true;
}

if ( ! isset( $GLOBALS['filters'] ) ) {
	$GLOBALS['filters'] = [];
}
/**
 * Stubbed filter dispatch. WordPress with nothing hooked returns the value it was
 * given, and a stub that returns false instead silently changes what the code under
 * test computes.
 */
function apply_filters( $hook, $value = null ) {
	return $GLOBALS['filters'][ $hook ] ?? $value;
}

foreach ( [
	'add_action',
	'add_filter',
	'get_option',
	'update_option',
	'delete_option',
	'get_transient',
	'set_transient',
	'delete_transient',
	'sanitize_title',
	'sanitize_text_field',
	'esc_html',
	'__',
	'_e',
	'taxonomy_exists',
	'get_terms',
	'wp_insert_term',
	'get_term_by',
	'wp_set_object_terms',
	'wp_remove_object_terms',
	'wc_get_product',
	'wc_get_product_id_by_sku',
	'wc_delete_product_transients',
	'clean_post_cache',
	'is_wp_error',
	'wp_remote_get',
	'wp_remote_retrieve_body',
	'wp_remote_retrieve_response_code',
	'add_query_arg',
	'current_time',
	'get_post_status',
	'wp_update_post',
	'wp_defer_term_counting',
	'wp_schedule_single_event',
	'wp_next_scheduled',
	'wp_schedule_event',
	'wp_clear_scheduled_hook',
	'admin_url',
	'wp_upload_dir',
	'wp_mkdir_p',
	'trailingslashit',
	'get_post',
	'get_post_type',
	'wp_insert_post',
	'has_post_thumbnail',
	'set_post_thumbnail',
	'download_url',
	'media_handle_sideload',
	'dbDelta',
	'wp_count_terms',
	'rwbe_get_api_token',
	'rwbe_has_api_token',
	'wp_cache_delete',
] as $f ) {
	if ( ! function_exists( $f ) ) {
		eval( "function {$f}() { return false; }" ); }
}

class WP_Error {
	public function __construct( ...$a ) {} public function get_error_message() {
		return ''; }
}

$dir = RWBE_TEST_PLUGIN_DIR . '/includes/';
require_once $dir . 'class-rwbe-debug-logger.php';
require_once $dir . 'class-rwbe-generic-attribute-helper.php';
require_once $dir . 'class-rwbe-fitment.php';
require_once $dir . 'class-rwbe-product-importer.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$fail; }
	printf( "%-62s got=%-12s want=%-12s %s\n", $label, var_export( $got, true ), var_export( $want, true ), $ok ? 'OK' : 'FAIL' );
}

$importer = new RWBE_Product_Importer();
$m        = new ReflectionMethod( 'RWBE_Product_Importer', 'update_meta_if_changed' );
$call     = function ( $id, $key, $value ) use ( $m, $importer ) {
	return $m->invoke( $importer, $id, $key, $value );
};

// Creating a value reports a change.
check( 'new int meta reports changed', $call( 1, '_stock', 5 ), true );
check( '  stored as string', $GLOBALS['meta']['1|_stock'], '5' );

// The crux: re-writing the same int over the stored string must report NO change.
// This is what update_post_meta() itself got wrong ("5" === 5 is false), and why
// every product used to look dirty on every run.
$GLOBALS['meta_writes'] = 0;
check( 'same int over stored string: no change', $call( 1, '_stock', 5 ), false );
check( '  and no DB write', $GLOBALS['meta_writes'], 0 );

// Same for floats, which is what the price fields are.
$call( 1, '_regular_price', 12.5 );
check( 'same float: no change', $call( 1, '_regular_price', 12.5 ), false );
check( 'different float: change', $call( 1, '_regular_price', 12.6 ), true );

// Zero and empty string must not be conflated.
$call( 2, '_stock', 0 );
check( 'stored "0" vs int 0: no change', $call( 2, '_stock', 0 ), false );
check( 'stored "0" vs "" : change', $call( 2, '_stock', '' ), true );

// A string value behaves the same way.
$call( 3, '_stock_status', 'instock' );
check( 'same string: no change', $call( 3, '_stock_status', 'instock' ), false );
check( 'different string: change', $call( 3, '_stock_status', 'outofstock' ), true );

// A missing meta with an empty value still has to be created.
check( 'missing meta, empty value: change', $call( 4, '_sale_price_dates_from', '' ), true );
check( '  then stable', $call( 4, '_sale_price_dates_from', '' ), false );

// apply_product_pricing must report "unchanged" on a second identical pass, which
// is what lets the cron skip the CRUD save.
$pricing = new ReflectionMethod( 'RWBE_Product_Importer', 'apply_product_pricing' );
$data    = [ 'retailerPrice' => 99.9 ];
check( 'pricing first pass: changed', $pricing->invoke( $importer, 10, $data ), true );
check( 'pricing second pass: unchanged', $pricing->invoke( $importer, 10, $data ), false );
check( 'pricing after price move: changed', $pricing->invoke( $importer, 10, [ 'retailerPrice' => 89.9 ] ), true );

// A promo that is valid and open must show up in _price, and still settle.
$promo = [
	'retailerPrice'     => 100.0,
	'grossPromoPricing' => 80.0,
	'promoSpecs'        => [],
];
$pricing->invoke( $importer, 11, $promo );
check( 'open promo wins _price', $GLOBALS['meta']['11|_price'], '80' );
check( 'promo second pass: unchanged', $pricing->invoke( $importer, 11, $promo ), false );

// Dropping the promo clears the sale meta and reports the change.
check( 'promo removed: changed', $pricing->invoke( $importer, 11, [ 'retailerPrice' => 100.0 ] ), true );
check( '  _sale_price gone', isset( $GLOBALS['meta']['11|_sale_price'] ), false );
check( '  _price back to regular', $GLOBALS['meta']['11|_price'], '100' );
check( '  and settles', $pricing->invoke( $importer, 11, [ 'retailerPrice' => 100.0 ] ), false );

// collect_api_ids must skip payloads with no id instead of warning.
$ids = new ReflectionMethod( 'RWBE_Product_Importer', 'collect_api_ids' );
check( 'collect_api_ids skips missing ids', $ids->invoke( $importer, [ [ 'id' => 'A' ], [ 'itemCode' => 'x' ], [ 'id' => 'B' ] ] ), [ 'A', 'B' ] );

// cache_buster must be stable within a request (that was the whole point).
$cb = new ReflectionMethod( 'RWBE_Product_Importer', 'cache_buster' );
check( 'cache_buster stable per request', $cb->invoke( null ) === $cb->invoke( null ), true );

echo "\n" . ( $fail === 0 ? 'ALL CHANGE-DETECTION CHECKS PASSED' : "{$fail} CHECK(S) FAILED" ) . "\n";
exit( $fail === 0 ? 0 : 1 );
