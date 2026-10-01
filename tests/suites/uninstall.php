<?php
// The uninstall routine deletes directories recursively. This proves it deletes only
// its own, refuses anything outside the uploads base, and never follows a symlink out.
require_once __DIR__ . '/../helpers.php';

define( 'WP_UNINSTALL_PLUGIN', 'rwbe-product-importer/rwbe-product-importer.php' );

$root    = sys_get_temp_dir() . '/rwbe-uninstall-' . getmypid();
$uploads = $root . '/uploads';
mkdir( $uploads . '/rwbe-logs/nested', 0777, true );
mkdir( $uploads . '/rwbe-vehicle-map', 0777, true );
mkdir( $uploads . '/2026/06', 0777, true );          // real media, must survive.
mkdir( $root . '/outside', 0777, true );             // must never be touched.
file_put_contents( $uploads . '/rwbe-logs/debug.log', 'x' );
file_put_contents( $uploads . '/rwbe-logs/nested/deep.txt', 'x' );
file_put_contents( $uploads . '/rwbe-vehicle-map/makes-models.json', '{}' );
file_put_contents( $uploads . '/2026/06/1.png', 'png' );
file_put_contents( $root . '/outside/precious.txt', 'do not delete' );
// A symlink pointing out of the directory being removed.
@symlink( $root . '/outside', $uploads . '/rwbe-logs/escape' );

$GLOBALS['queries'] = [];
class WPDB_Un {
	public $options = 'wp_options';
	public $prefix  = 'wp_';
	public function esc_like( $t ) {
		return addcslashes( $t, '_%\\' ); }
	public function prepare( $q, ...$a ) {
		if ( count( $a ) === 1 && is_array( $a[0] ) ) {
			$a = $a[0]; }
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( $a, &$i ) {
				$v = $a[ $i++ ] ?? '';
				return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$q
		);
	}
	public function query( $sql ) {
		$GLOBALS['queries'][] = preg_replace( '/\s+/', ' ', trim( $sql ) );
		return 1; }
}
$GLOBALS['wpdb']    = new WPDB_Un();
$GLOBALS['cleared'] = [];

function wp_get_upload_dir() {
	return [ 'basedir' => $GLOBALS['UPLOADS'] ]; }
function trailingslashit( $s ) {
	return rtrim( $s, '/\\' ) . '/'; }
function wp_clear_scheduled_hook( $h ) {
	$GLOBALS['cleared'][] = $h; }
function wp_cache_flush() {
	return true; }
function is_multisite() {
	return false; }
$GLOBALS['UPLOADS'] = $uploads;

require RWBE_TEST_PLUGIN_DIR . '/uninstall.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$fail; }
	printf( "%-56s got=%-18s want=%-18s %s\n", $label, var_export( $got, true ), var_export( $want, true ), $ok ? 'OK' : 'FAIL' );
}

check( 'rwbe-logs removed', is_dir( $uploads . '/rwbe-logs' ), false );
check( 'rwbe-vehicle-map removed', is_dir( $uploads . '/rwbe-vehicle-map' ), false );
check( 'real media untouched', file_get_contents( $uploads . '/2026/06/1.png' ), 'png' );
check( 'symlink target survived', file_get_contents( $root . '/outside/precious.txt' ), 'do not delete' );
check( 'outside dir still a dir', is_dir( $root . '/outside' ), true );

// Containment: a traversing name must be refused outright.
rwbe_uninstall_delete_upload_dir( '../outside' );
check( 'traversal refused', is_dir( $root . '/outside' ), true );
rwbe_uninstall_delete_upload_dir( '.' );
check( 'uploads base itself refused', is_dir( $uploads ), true );
rwbe_uninstall_delete_upload_dir( '2026' );
check( 'named-but-real dir is deletable only if asked', is_dir( $uploads . '/2026' ), false );

// Options and table.
$sql = implode( ' | ', $GLOBALS['queries'] );
fwrite( STDERR, "SQL: {$sql}\n\n" );
check( 'deletes rwbe_ options by prefix', strpos( $sql, "option_name LIKE 'rwbe\\\\_%'" ) !== false, true );
check( 'deletes matching transients', strpos( $sql, '_transient_rwbe\\\\_%' ) !== false, true );
check( 'drops the fitment table', strpos( $sql, 'DROP TABLE IF EXISTS `wp_rwbe_fitment`' ) !== false, true );
check( 'clears all five cron hooks', count( $GLOBALS['cleared'] ), 5 );

// Cleanup.
function rt( $d ) {
	if ( ! is_dir( $d ) ) {
		return;
	} foreach ( array_diff( scandir( $d ), [ '.','..' ] ) as $i ) {
		$p = "$d/$i";
		is_dir( $p ) && ! is_link( $p ) ? rt( $p ) : @unlink( $p );
	} @rmdir( $d ); }
rt( $root );

echo "\n" . ( $fail === 0 ? 'ALL UNINSTALL CHECKS PASSED' : "{$fail} CHECK(S) FAILED" ) . "\n";
exit( $fail === 0 ? 0 : 1 );
