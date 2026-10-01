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
 * Path to the real RWB placeholder image, when this machine happens to have it.
 *
 * No suite depends on it any more: the detection is driven by two filterable values
 * (rwbe_placeholder_md5 / rwbe_placeholder_size), so the delete-path tests point them
 * at a fixture they generate and run everywhere, CI included. The supplier's
 * placeholder is their logo, not a generic "no image" box, so it is deliberately not
 * committed here.
 *
 * What it is still used for: when the file is present, the suite cross-checks that the
 * constants the plugin ships still describe it — the regression that would silently
 * stop every future placeholder from being de-duplicated.
 *
 * @return string Absolute path, or '' when not available.
 */
function rwbe_test_placeholder_file() {
	// Lets the "image not on this machine" branch be exercised where it is.
	if ( getenv( 'RWBE_TEST_NO_PLACEHOLDER' ) ) {
		return '';
	}

	$path = dirname( RWBE_TEST_PLUGIN_DIR, 2 ) . '/uploads/2026/06/1.png';

	return is_readable( $path ) ? $path : '';
}
