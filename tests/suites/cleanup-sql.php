<?php
// Build the cleanup SQL through the real class methods, with a prepare() that
// mirrors WordPress's %%/%d/%s handling, then print it for execution against MySQL.
require_once __DIR__ . '/../helpers.php';

define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

class WPDB_Prep {
	public $posts      = 'wp_posts';
	public $postmeta   = 'wp_postmeta';
	public $options    = 'wp_options';
	public $prefix     = 'wp_';
	public $last_error = '';
	public $captured   = [];
	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0]; }
		// WordPress turns a literal %% into a single %.
		$query = str_replace( '%%', "\x00PCT\x00", $query );
		$i     = 0;
		$query = preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( $args, &$i ) {
				$v = $args[ $i++ ] ?? '';
				if ( $m[0] === '%d' ) {
					return (string) (int) $v; }
				if ( $m[0] === '%f' ) {
					return (string) (float) $v; }
				return "'" . addslashes( (string) $v ) . "'";
			},
			$query
		);
		return str_replace( "\x00PCT\x00", '%', $query );
	}
	public function get_var( $sql ) {
		$this->captured[] = $sql;
		return 0; }
	public function get_col( $sql ) {
		$this->captured[] = $sql;
		return []; }
	public function get_results( $sql ) {
		$this->captured[] = $sql;
		return []; }
	public function query( $sql ) {
		$this->captured[] = $sql;
		return 0; }
	public function get_charset_collate() {
		return ''; }
}
$GLOBALS['wpdb'] = new WPDB_Prep();

foreach ( [
	'add_action',
	'add_filter',
	'apply_filters',
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
	'add_query_arg',
	'current_time',
	'get_post_status',
	'wp_update_post',
	'wp_defer_term_counting',
	'wp_schedule_single_event',
	'wp_next_scheduled',
	'wp_clear_scheduled_hook',
	'admin_url',
	'wp_upload_dir',
	'wp_mkdir_p',
	'trailingslashit',
	'get_post',
	'get_post_type',
	'wp_insert_post',
	'get_post_meta',
	'update_post_meta',
	'delete_post_meta',
	'metadata_exists',
	'has_post_thumbnail',
	'set_post_thumbnail',
	'download_url',
	'dbDelta',
	'media_handle_sideload',
	'wp_count_terms',
	'rwbe_get_api_token',
	'rwbe_has_api_token',
	'wp_cache_delete',
	'get_attached_file',
	'wp_delete_attachment',
	'_prime_post_caches',
	'has_term',
] as $f ) {
	if ( ! function_exists( $f ) ) {
		eval( "function {$f}() { return false; }" ); }
}
class WP_Error {
	public function get_error_message() {
		return ''; }
}

$dir = RWBE_TEST_PLUGIN_DIR . '/includes/';
require_once $dir . 'class-rwbe-debug-logger.php';
require_once $dir . 'class-rwbe-generic-attribute-helper.php';
require_once $dir . 'class-rwbe-fitment.php';
require_once $dir . 'class-rwbe-product-importer.php';

$imp = new RWBE_Product_Importer();

// The windowed query, exactly as cleanup_placeholder_duplicates() builds it.
$sqlm  = new ReflectionMethod( 'RWBE_Product_Importer', 'cleanup_candidate_sql' );
$needm = new ReflectionMethod( 'RWBE_Product_Importer', 'placeholder_metadata_needle' );
$frag  = $sqlm->invoke( $imp );
$need  = $needm->invoke( $imp );

echo "--- NEEDLE ---\n{$need}\n";

$window = $GLOBALS['wpdb']->prepare(
	'SELECT DISTINCT p.ID ' . $frag . "\n ORDER BY p.ID ASC LIMIT %d",
	12636,
	$need,
	300
);

// The count query, through the real helper.
$cnt = new ReflectionMethod( 'RWBE_Product_Importer', 'count_cleanup_candidates' );
$cnt->invoke( $imp, 0 );
$count_sql = end( $GLOBALS['wpdb']->captured );

echo "--- COUNT SQL ---\n{$count_sql}\n";
$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$fail; }
	printf( "%-46s got=%-10s want=%-10s %s\n", $label, var_export( $got, true ), var_export( $want, true ), $ok ? 'OK' : 'FAIL' );
}

echo "\n--- checks ---\n";

// prepare() must have collapsed the literal %% escapes; a stray %% in the SQL would
// reach MySQL as a wildcard and the pre-filter would match far too much.
check( 'no leftover %% in the window SQL', strpos( $window, '%%' ), false );

// The needle has to arrive as a bound literal, not as a placeholder.
check(
	'needle bound as a literal',
	strpos( $window, 's:8:\\"filesize\\";i:11137;' ) !== false
		|| strpos( $window, 's:8:"filesize";i:11137;' ) !== false,
	true
);

check( 'cursor bound', strpos( $window, 'p.ID > 12636' ) !== false, true );
check( 'limit bound', strpos( $window, 'LIMIT 300' ) !== false, true );

// The count query must cover the same candidate set, anchored at its own cursor.
check( 'count SQL is a COUNT over the candidates', strpos( $count_sql, 'SELECT COUNT(DISTINCT p.ID)' ) === 0, true );
check( 'count SQL joins the metadata', strpos( $count_sql, '_wp_attachment_metadata' ) !== false, true );
check( 'count SQL has no LIMIT', strpos( $count_sql, 'LIMIT' ), false );

echo "\n" . ( $fail === 0 ? 'ALL CLEANUP SQL CHECKS PASSED' : "{$fail} CHECK(S) FAILED" ) . "\n";
exit( $fail === 0 ? 0 : 1 );
