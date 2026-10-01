<?php
require_once __DIR__ . '/../helpers.php';

define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );

// A $wpdb stub whose prepare() is close enough to the real one for shape checks.
class WPDB_Stub {
	public $prefix             = 'wp_';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $terms              = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $options            = 'wp_options';
	public $last_queries       = [];
	public function prepare( $sql, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0]; }
		$i = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( $args, &$i ) {
				$v = $args[ $i++ ] ?? '';
				return $m[0] === '%s' ? "'" . addslashes( (string) $v ) . "'" : (string) (int) $v;
			},
			$sql
		);
	}
	public function get_results( $sql ) {
		$this->last_queries[] = $sql;
		return []; }
	public function get_var( $sql ) {
		$this->last_queries[] = $sql;
		return 1; }
	public function get_col( $sql ) {
		$this->last_queries[] = $sql;
		return []; }
	public function get_charset_collate() {
		return ''; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();

function sanitize_title( $s ) {
	return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $s ) ) ); }
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
	'remove_filter',
	'add_shortcode',
	'get_option',
	'update_option',
	'delete_option',
	'get_transient',
	'set_transient',
	'delete_transient',
	'taxonomy_exists',
	'get_terms',
	'esc_html',
	'esc_attr',
	'esc_url',
	'esc_html__',
	'esc_attr__',
	'esc_html_e',
	'esc_attr_e',
	'__',
	'_e',
	'_x',
	'wp_unslash',
	'sanitize_text_field',
	'sanitize_hex_color',
	'wp_count_terms',
	'add_submenu_page',
	'register_setting',
	'selected',
	'disabled',
	'wc_get_page_permalink',
	'home_url',
	'admin_url',
	'wp_create_nonce',
	'wp_register_script',
	'wp_register_style',
	'wp_enqueue_script',
	'wp_enqueue_style',
	'wp_localize_script',
	'is_singular',
	'get_post',
	'has_shortcode',
	'shortcode_atts',
	'check_ajax_referer',
	'check_admin_referer',
	'wp_send_json_success',
	'wp_nonce_url',
	'add_query_arg',
	'is_wp_error',
	'wp_schedule_single_event',
	'wp_next_scheduled',
	'wp_clear_scheduled_hook',
	'dbDelta',
	'wp_cache_delete',
	'is_shop',
	'is_product_taxonomy',
] as $f ) {
	if ( ! function_exists( $f ) ) {
		eval( "function {$f}() { return false; }" ); }
}

class WP_Error {
	public function get_error_message() {
		return ''; }
}
class WP_Query_Stub {
	public $vars = [];
	public function get( $k ) {
		return $this->vars[ $k ] ?? '';
	} public function set( $k, $v ) {
		$this->vars[ $k ] = $v; }
}

$dir = RWBE_TEST_PLUGIN_DIR . '/includes/';
require_once $dir . 'class-rwbe-debug-logger.php';
require_once $dir . 'class-rwbe-fitment.php';
require_once $dir . 'class-rwbe-vehicle-map.php';
require_once $dir . 'class-rwbe-product-importer-search.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$fail; }
	printf( "%-60s %s\n", $label, $ok ? 'OK' : "FAIL\n   got:  " . var_export( $got, true ) . "\n   want: " . var_export( $want, true ) );
}

// ---- where_clause ----
check( 'make only', RWBE_Fitment::where_clause( 'Yamaha' ), "rwbe_fit.make_slug = 'yamaha'" );
check( 'make + model', RWBE_Fitment::where_clause( 'Yamaha', 'YZ250' ), "rwbe_fit.make_slug = 'yamaha' AND rwbe_fit.model_slug = 'yz250'" );
check(
	'make + model + year (range containment)',
	RWBE_Fitment::where_clause( 'Yamaha', 'YZ250', 2013 ),
	"rwbe_fit.make_slug = 'yamaha' AND rwbe_fit.model_slug = 'yz250' AND rwbe_fit.year_from <= 2013 AND rwbe_fit.year_to >= 2013"
);
check( 'year 0 is ignored', RWBE_Fitment::where_clause( 'Yamaha', 'YZ250', 0 ), "rwbe_fit.make_slug = 'yamaha' AND rwbe_fit.model_slug = 'yz250'" );
check( 'empty make yields null', RWBE_Fitment::where_clause( '' ), null );
check( 'model without make is dropped, not injected', strpos( RWBE_Fitment::where_clause( 'Yamaha', '' ), 'model_slug' ), false );
// SQL injection attempt must come out quoted/slugged, never as syntax.
$evil = RWBE_Fitment::where_clause( "yam' OR 1=1 -- ", "mod'" );
check( 'injection attempt is neutralised', strpos( $evil, 'OR 1=1' ) === false, true );

// ---- query_join ----
check(
	'join aliases the table and binds to wp_posts.ID',
	trim( RWBE_Fitment::query_join() ),
	'INNER JOIN wp_rwbe_fitment rwbe_fit ON rwbe_fit.product_id = wp_posts.ID'
);

// ---- filtered maps hit the DB with the filter in the SQL ----
$GLOBALS['wpdb']->last_queries = [];
RWBE_Fitment::get_model_map( 'yamaha' );
$sql = $GLOBALS['wpdb']->last_queries[0];
check( 'get_model_map(make) filters in SQL', strpos( $sql, "f.make_slug = 'yamaha'" ) !== false, true );

$GLOBALS['wpdb']->last_queries = [];
RWBE_Fitment::get_model_map();
$sql = $GLOBALS['wpdb']->last_queries[0];
check( 'get_model_map() unfiltered has no make clause', strpos( $sql, 'f.make_slug =' ) === false, true );

$GLOBALS['wpdb']->last_queries = [];
RWBE_Fitment::get_year_map( 'yamaha', 'yz250' );
$sql = $GLOBALS['wpdb']->last_queries[0];
check(
	'get_year_map(make, model) filters both in SQL',
	strpos( $sql, "f.make_slug = 'yamaha'" ) !== false && strpos( $sql, "f.model_slug = 'yz250'" ) !== false,
	true
);

$GLOBALS['wpdb']->last_queries = [];
RWBE_Fitment::get_year_map();
$sql = $GLOBALS['wpdb']->last_queries[0];
check( 'get_year_map() unfiltered still reads everything', strpos( $sql, 'f.make_slug =' ) === false, true );

// ---- inject_fitment_clauses ----
$search = new RWBE_Product_Importer_Search();
$target = new WP_Query_Stub();
$other  = new WP_Query_Stub();

$r  = new ReflectionClass( 'RWBE_Product_Importer_Search' );
$pq = $r->getProperty( 'fitment_query' );
$pq->setValue( $search, $target );
$pc = $r->getProperty( 'fitment_clause' );
$pc->setValue( $search, "rwbe_fit.make_slug = 'yamaha'" );

$base = [
	'distinct' => '',
	'join'     => ' INNER JOIN other o ON o.id = wp_posts.ID ',
	'where'    => " AND wp_posts.post_status = 'publish'",
];

// An unrelated query must pass through byte-identical.
check( 'unrelated query untouched', $search->inject_fitment_clauses( $base, $other ), $base );

// The marked query gets the join, DISTINCT, and the condition appended.
$out = $search->inject_fitment_clauses( $base, $target );
check( 'marked query gets DISTINCT', $out['distinct'], 'DISTINCT' );
check( 'marked query keeps the pre-existing join', strpos( $out['join'], 'INNER JOIN other o' ) !== false, true );
check( 'marked query gains the fitment join', strpos( $out['join'], 'rwbe_fit' ) !== false, true );
check( 'marked query keeps the pre-existing where', strpos( $out['where'], "post_status = 'publish'" ) !== false, true );
check( 'marked query gains the fitment condition', strpos( $out['where'], "rwbe_fit.make_slug = 'yamaha'" ) !== false, true );

// Running it again on an already-joined clause set must not double-append.
$twice = $search->inject_fitment_clauses( $out, $target );
check( 'idempotent: join not appended twice', substr_count( $twice['join'], 'rwbe_fit' ), substr_count( $out['join'], 'rwbe_fit' ) );
check( 'idempotent: where not appended twice', substr_count( $twice['where'], 'make_slug' ), 1 );

// With no marked query, nothing is ever touched.
$pq->setValue( $search, null );
check( 'no marked query: pass through', $search->inject_fitment_clauses( $base, $target ), $base );

echo "\n" . ( $fail === 0 ? 'ALL SQL / QUERY CHECKS PASSED' : "{$fail} CHECK(S) FAILED" ) . "\n";
exit( $fail === 0 ? 0 : 1 );
