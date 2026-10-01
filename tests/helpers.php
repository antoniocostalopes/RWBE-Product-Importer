<?php
/**
 * Shared setup for the suites in tests/suites/.
 *
 * Every suite runs in its own PHP process. That is not a stylistic choice: the plugin
 * calls WordPress functions in the global namespace, so each suite declares the stubs
 * it needs, and two suites that stub get_option() differently cannot coexist in one
 * process. Separate processes keep each suite's stubs honest and independent.
 *
 * @package RWBE_Product_Importer
 */

if ( ! defined( 'RWBE_TEST_PLUGIN_DIR' ) ) {
	define( 'RWBE_TEST_PLUGIN_DIR', dirname( __DIR__ ) );
}

/**
 * Path to a file whose bytes are the RWB placeholder image, or '' when unavailable.
 *
 * One assertion group needs is_placeholder_attachment() to genuinely return true, and
 * that means a file with the exact size and md5 the plugin hard-codes — bytes that
 * cannot be fabricated. It is looked for as a committed fixture first, then in this
 * site's uploads. When neither is there the suite skips that group and says so,
 * rather than quietly asserting nothing; the assertions that do not need the file
 * still run.
 *
 * To enable the full group in CI, copy the placeholder PNG to
 * tests/fixtures/placeholder.png.
 *
 * @return string Absolute path, or '' when not available.
 */
function rwbe_test_placeholder_file() {
	$candidates = array(
		__DIR__ . '/fixtures/placeholder.png',
		// This development site, where the plugin has already imported it.
		dirname( RWBE_TEST_PLUGIN_DIR, 2 ) . '/uploads/2026/06/1.png',
	);

	foreach ( $candidates as $path ) {
		if ( is_readable( $path )
			&& filesize( $path ) === 11137
			&& md5_file( $path ) === '05aefa99c9870be09a77d9dd68b4c55a'
		) {
			return $path;
		}
	}

	return '';
}
