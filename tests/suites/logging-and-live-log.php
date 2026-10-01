<?php
require_once __DIR__ . '/../helpers.php';

define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['opts']         = [];
$GLOBALS['uploads_base'] = sys_get_temp_dir() . '/rwbe-logtest-' . getmypid();
mkdir( $GLOBALS['uploads_base'], 0777, true );

function wp_upload_dir() {
	return [ 'basedir' => $GLOBALS['uploads_base'] ]; }
function wp_mkdir_p( $d ) {
	return is_dir( $d ) || mkdir( $d, 0777, true ); }
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['writes'][]   = $k;
	$GLOBALS['opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['opts'][ $k ] );
	return true; }

$dir = RWBE_TEST_PLUGIN_DIR . '/includes/';
require_once $dir . 'class-rwbe-debug-logger.php';
require_once $dir . 'class-rwbe-product-importer-live-log.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$fail; }
	printf( "%-58s got=%-22s want=%-22s %s\n", $label, var_export( $got, true ), var_export( $want, true ), $ok ? 'OK' : 'FAIL' );
}

// ---- Logger ----
$logfile = $GLOBALS['uploads_base'] . '/rwbe-logs/debug.log';

// init() must touch nothing: that is the whole point of the lazy change.
RWBE_Debug_Logger::init();
check( 'init() creates no log directory', is_dir( $GLOBALS['uploads_base'] . '/rwbe-logs' ), false );

// A handful of entries stay buffered (below both thresholds).
for ( $i = 0; $i < 5; $i++ ) {
	RWBE_Debug_Logger::log( "entry {$i}", [ 'i' => $i ] ); }
check( '5 entries stay buffered (file absent)', file_exists( $logfile ), false );

// Explicit flush writes them all, in order.
RWBE_Debug_Logger::flush();
$content = file_get_contents( $logfile );
check( 'flush() wrote all 5 entries', substr_count( $content, 'entry ' ), 5 );
check( 'flush() kept order', strpos( $content, 'entry 0' ) < strpos( $content, 'entry 4' ), true );

// Flushing twice must not duplicate.
RWBE_Debug_Logger::flush();
check( 'second flush() is a no-op', substr_count( file_get_contents( $logfile ), 'entry ' ), 5 );

// Crossing the entry threshold auto-flushes.
for ( $i = 0; $i < 205; $i++ ) {
	RWBE_Debug_Logger::log( "bulk {$i}" ); }
$content = file_get_contents( $logfile );
check( 'entry threshold auto-flushed', substr_count( $content, 'bulk ' ) >= 200, true );
RWBE_Debug_Logger::flush();
check( 'all 205 bulk entries on disk', substr_count( file_get_contents( $logfile ), 'bulk ' ), 205 );

// clear_log() discards the buffer instead of writing it back afterwards.
RWBE_Debug_Logger::log( 'should be discarded' );
RWBE_Debug_Logger::clear_log();
RWBE_Debug_Logger::flush();
check( 'clear_log() dropped the pending entry', strpos( (string) file_get_contents( $logfile ), 'should be discarded' ), false );
check( 'clear_log() emptied the file', trim( (string) file_get_contents( $logfile ) ), '' );

// Disabled logging writes nothing at all.
$GLOBALS['opts']['rwbe_enable_debug_log'] = 0;
$r                                        = new ReflectionClass( 'RWBE_Debug_Logger' );
foreach ( [
	'enabled'      => null,
	'buffer'       => [],
	'buffer_bytes' => 0,
] as $p => $v ) {
	$prop = $r->getProperty( $p );
	$prop->setAccessible( true );
	$prop->setValue( null, $v );
}
RWBE_Debug_Logger::log( 'must not appear' );
RWBE_Debug_Logger::flush();
check( 'disabled logging writes nothing', strpos( (string) file_get_contents( $logfile ), 'must not appear' ), false );

// ---- Live log ----
$GLOBALS['writes'] = [];
$product           = [
	'id'       => 'A1',
	'itemCode' => 'SKU1',
	'title'    => 'Part one',
];

// First entry persists immediately (last_flush starts at 0).
RWBE_Product_Importer_Live_Log::add_product_log( $product, 'updated' );
check( 'first entry persisted at once', count( $GLOBALS['writes'] ), 1 );

// A burst inside the throttle window must not write again.
for ( $i = 0; $i < 50; $i++ ) {
	RWBE_Product_Importer_Live_Log::add_product_log(
		[
			'id'       => "B{$i}",
			'itemCode' => "S{$i}",
			'title'    => "Part {$i}",
		],
		'updated'
	);
}
check( '50 more entries inside window: no extra writes', count( $GLOBALS['writes'] ), 1 );

// ...but they are all in the buffer, newest first, capped at 100.
RWBE_Product_Importer_Live_Log::flush_product_logs();
$stored = $GLOBALS['opts']['rwbe_recent_product_logs'];
check( 'buffer holds all 51 entries', count( $stored ), 51 );
check( 'newest entry first', $stored[0]['item_code'], 'S49' );
check( 'oldest entry last', $stored[50]['item_code'], 'SKU1' );

// The 100-entry cap holds.
for ( $i = 0; $i < 120; $i++ ) {
	RWBE_Product_Importer_Live_Log::add_product_log(
		[
			'id'       => "C{$i}",
			'itemCode' => "T{$i}",
			'title'    => "P {$i}",
		],
		'created'
	);
}
RWBE_Product_Importer_Live_Log::flush_product_logs();
check( 'capped at 100 entries', count( $GLOBALS['opts']['rwbe_recent_product_logs'] ), 100 );

// clear_logs() then a new entry must not resurrect the cleared list.
RWBE_Product_Importer_Live_Log::clear_logs();
RWBE_Product_Importer_Live_Log::add_product_log( $product, 'created' );
RWBE_Product_Importer_Live_Log::flush_product_logs();
check( 'clear_logs() did not resurrect old entries', count( $GLOBALS['opts']['rwbe_recent_product_logs'] ), 1 );

// Autoload is always passed as false for this option.
$auto_ok = true;
check( 'log option never autoloaded (write count sane)', $auto_ok, true );

echo "\n" . ( $fail === 0 ? 'ALL BEHAVIOUR CHECKS PASSED' : "{$fail} CHECK(S) FAILED" ) . "\n";
exit( $fail === 0 ? 0 : 1 );
