<?php
// An import with no API token must stop before it writes anything, and must clear the
// stale progress rather than leave it for the watchdog to resurrect. Found on a live
// site: the resilient path (the one cron and the watchdog use) had no token check, so
// every two minutes it restarted a doomed import, refreshed the progress timestamp,
// and in doing so kept the placeholder cleanup permanently standing down.
require_once __DIR__ . '/../helpers.php';

define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['opts']       = array();
$GLOBALS['has_token']  = false;
$GLOBALS['lock_taken'] = 0;
$GLOBALS['filters']    = array();

function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) {
	$GLOBALS['opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['opts'][ $k ] );
	return true;
}
function rwbe_has_api_token() {
	return (bool) $GLOBALS['has_token'];
}
function rwbe_get_api_token() {
	return $GLOBALS['has_token'] ? 'test-token' : '';
}
function __( $s, $d = null ) {
	return $s;
}
function apply_filters( $hook, $value = null ) {
	return $GLOBALS['filters'][ $hook ] ?? $value;
}

class WPDB_Token {
	public $posts      = 'wp_posts';
	public $postmeta   = 'wp_postmeta';
	public $options    = 'wp_options';
	public $prefix     = 'wp_';
	public $last_error = '';
	public function prepare( $q, ...$a ) {
		return $q;
	}
	public function get_var( $sql ) {
		// Any GET_LOCK attempt means the guard failed to stop the run.
		if ( stripos( $sql, 'GET_LOCK' ) !== false ) {
			++$GLOBALS['lock_taken'];
			return '1';
		}
		return 0;
	}
	public function get_col( $sql ) {
		return array();
	}
	public function get_results( $sql ) {
		return array();
	}
	public function query( $sql ) {
		return 0;
	}
	public function get_charset_collate() {
		return '';
	}
}
$GLOBALS['wpdb'] = new WPDB_Token();

foreach ( array(
	'add_action',
	'add_filter',
	'get_transient',
	'set_transient',
	'delete_transient',
	'sanitize_title',
	'sanitize_text_field',
	'esc_html',
	'esc_html__',
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
	'wp_cache_delete',
	'has_term',
	'get_attached_file',
	'wp_delete_attachment',
	'_prime_post_caches',
	'spawn_cron',
) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return false; }" );
	}
}

class WP_Error {
	public function get_error_message() {
		return '';
	}
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
		++$fail;
	}
	printf( "%-56s got=%-14s want=%-14s %s\n", $label, var_export( $got, true ), var_export( $want, true ), $ok ? 'OK' : 'FAIL' );
}

$imp = new RWBE_Product_Importer();

// A site with no token, carrying the stale progress a dead run left behind.
$GLOBALS['opts']['rwbe_import_progress'] = array(
	'skip'      => 44800,
	'status'    => 'in_progress',
	'timestamp' => time(),
	'results'   => array( 'total' => 54200 ),
);
$GLOBALS['lock_taken']                   = 0;

$res = $imp->import_products_with_resilience( true, true );

check( 'reports the missing token', ! empty( $res['missing_token'] ), true );
check( 'imports nothing', $res['total'], 0 );
check( 'never takes the import lock', $GLOBALS['lock_taken'], 0 );
check( 'clears the stale progress', isset( $GLOBALS['opts']['rwbe_import_progress'] ), false );

// The watchdog must not resurrect it either.
$GLOBALS['opts']['rwbe_import_progress'] = array(
	'skip'      => 44800,
	'status'    => 'in_progress',
	'timestamp' => time() - 600,
	'results'   => array( 'total' => 54200 ),
);
$GLOBALS['lock_taken']                   = 0;

$imp->check_and_resume_interrupted_imports();

check( 'watchdog does not start an import', $GLOBALS['lock_taken'], 0 );
check( 'watchdog clears the stale progress', isset( $GLOBALS['opts']['rwbe_import_progress'] ), false );

// With a token the guard must get out of the way. Taking the import lock happens
// immediately after it, so that is the signal; what the run then does needs a real
// WordPress, and the stubs deliberately do not go that far, so whatever it throws
// beyond this point is not this suite's business.
$GLOBALS['has_token']  = true;
$GLOBALS['lock_taken'] = 0;
try {
	$imp->import_products_with_resilience( true, false );
} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- See above.
	$unused = $e;
}
check( 'with a token the run proceeds past the guard', $GLOBALS['lock_taken'] > 0, true );

echo "\n" . ( 0 === $fail ? 'ALL TOKEN GUARD CHECKS PASSED' : "{$fail} CHECK(S) FAILED" ) . "\n";
exit( 0 === $fail ? 0 : 1 );
