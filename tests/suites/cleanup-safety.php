<?php
// Proves the safety property that matters: when the repoint cannot be completed,
// NOTHING is deleted and the cursor does not move, so no product is left pointing at
// an attachment that no longer exists.
require_once __DIR__ . '/../helpers.php';

define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$PLACEHOLDER_FILE = rwbe_test_placeholder_file();

$GLOBALS['opts']         = [
	'rwbe_ph_cleanup_cursor'         => 1000,
	'rwbe_placeholder_attachment_id' => 43,
];
$GLOBALS['deleted']      = [];
$GLOBALS['fail_gallery'] = false;

class WPDB_Safety {
	public $posts      = 'wp_posts';
	public $postmeta   = 'wp_postmeta';
	public $options    = 'wp_options';
	public $prefix     = 'wp_';
	public $last_error = '';
	public function prepare( $q, ...$a ) {
		if ( count( $a ) === 1 && is_array( $a[0] ) ) {
			$a = $a[0]; }
		$q = str_replace( '%%', "\x00P\x00", $q );
		$i = 0;
		$q = preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( $a, &$i ) {
				$v = $a[ $i++ ] ?? '';
				return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$q
		);
		return str_replace( "\x00P\x00", '%', $q );
	}
	public function get_col( $sql ) {
		$this->last_error = '';
		if ( strpos( $sql, "'_thumbnail_id'" ) !== false ) {
			return [ '9001' ]; }
		// The candidate window.
		return [ '2001', '2002', '2003' ];
	}
	public function get_results( $sql ) {
		if ( strpos( $sql, '_product_image_gallery' ) !== false && $GLOBALS['fail_gallery'] ) {
			$this->last_error = 'Regular expression is too complex';
			return [];
		}
		$this->last_error = '';
		return [];
	}
	public function get_var( $sql ) {
		$this->last_error = '';
		return 0; }
	public function query( $sql ) {
		$this->last_error = '';
		return 1; }
	public function get_charset_collate() {
		return ''; }
}
$GLOBALS['wpdb'] = new WPDB_Safety();

function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) {
	$GLOBALS['opts'][ $k ] = $v;
	return true; }
function delete_option( $k ) {
	unset( $GLOBALS['opts'][ $k ] );
	return true; }
function get_post_type( $id ) {
	return 'attachment'; }
function get_attached_file( $id ) {
	return $GLOBALS['PH_FILE']; }
function wp_delete_attachment( $id, $force = false ) {
	$GLOBALS['deleted'][] = (int) $id;
	return true; }
function _prime_post_caches( $ids, $a = true, $b = true ) {
	return null; }
function __( $s, $d = null ) {
	return $s; }

foreach ( [
	'add_action',
	'add_filter',
	'apply_filters',
	'get_transient',
	'set_transient',
	'delete_transient',
	'sanitize_title',
	'sanitize_text_field',
	'esc_html',
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
	'has_term',
] as $f ) {
	if ( ! function_exists( $f ) ) {
		eval( "function {$f}() { return false; }" ); }
}
class WP_Error {
	public function get_error_message() {
		return ''; }
}

$GLOBALS['PH_FILE'] = $PLACEHOLDER_FILE;

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
	printf( "%-56s got=%-20s want=%-20s %s\n", $label, var_export( $got, true ), var_export( $want, true ), $ok ? 'OK' : 'FAIL' );
}

$imp = new RWBE_Product_Importer();

// The end-to-end group needs is_placeholder_attachment() to genuinely return true,
// which means a file with the exact size and md5 the plugin hard-codes. Those bytes
// cannot be fabricated, so when the fixture is absent the group is skipped loudly
// instead of silently asserting nothing. See tests/helpers.php.
if ( $PLACEHOLDER_FILE === '' ) {
	$skipped = true;
	fwrite(
		STDERR,
		"SKIPPED (no placeholder fixture): the end-to-end delete-path group.\n"
		. "  Copy the placeholder PNG to tests/fixtures/placeholder.png to enable it.\n\n"
	);
} else {
	$skipped = false;
	// Sanity: the real placeholder file is recognised, so the run below reaches the
	// delete branch for real rather than skipping everything.
	$isph = new ReflectionMethod( 'RWBE_Product_Importer', 'is_placeholder_attachment' );
	check( 'real placeholder file is recognised', $isph->invoke( $imp, 2001 ), true );

	// --- Failure case: the gallery scan errors out ---
	$GLOBALS['fail_gallery']                   = true;
	$GLOBALS['deleted']                        = [];
	$GLOBALS['opts']['rwbe_ph_cleanup_cursor'] = 1000;

	$res = $imp->cleanup_placeholder_duplicates( 3, false, false );
	check( 'repoint failure: nothing deleted', count( $GLOBALS['deleted'] ), 0 );
	check( 'repoint failure: error reported', ! empty( $res['error'] ), true );
	check( 'repoint failure: deleted count 0', $res['deleted'], 0 );
	check( 'repoint failure: cursor NOT advanced', (int) $GLOBALS['opts']['rwbe_ph_cleanup_cursor'], 1000 );

	// --- Happy case: repoint succeeds ---
	$GLOBALS['fail_gallery']                   = false;
	$GLOBALS['deleted']                        = [];
	$GLOBALS['opts']['rwbe_ph_cleanup_cursor'] = 1000;

	$res = $imp->cleanup_placeholder_duplicates( 3, false, false );
	check( 'repoint ok: duplicates deleted', $GLOBALS['deleted'], [ 2001, 2002, 2003 ] );
	check( 'repoint ok: no error', empty( $res['error'] ), true );
	check( 'repoint ok: deleted count', $res['deleted'], 3 );
	check( 'repoint ok: cursor advanced', (int) $GLOBALS['opts']['rwbe_ph_cleanup_cursor'], 2003 );
	check( 'repoint ok: remaining not counted', $res['remaining'], null );
}

// --- reassign_attachment_references gate, directly ---
$re                      = new ReflectionMethod( 'RWBE_Product_Importer', 'reassign_attachment_references' );
$GLOBALS['fail_gallery'] = true;
check( 'reassign returns false on SQL error', $re->invoke( $imp, [ 2001 ], 43 ), false );
$GLOBALS['fail_gallery'] = false;
check( 'reassign returns true on success', $re->invoke( $imp, [ 2001 ], 43 ), true );
check( 'reassign returns false with no ids', $re->invoke( $imp, [], 43 ), false );
check( 'reassign returns false with no target', $re->invoke( $imp, [ 2001 ], 0 ), false );

echo "\n" . ( $fail === 0
	? ( 'ALL CLEANUP SAFETY CHECKS PASSED' . ( $skipped ? ' (end-to-end group skipped: no fixture)' : '' ) )
	: "{$fail} CHECK(S) FAILED" ) . "\n";
exit( $fail === 0 ? 0 : 1 );
