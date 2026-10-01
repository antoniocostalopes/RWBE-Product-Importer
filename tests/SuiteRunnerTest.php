<?php
/**
 * Runs each suite in tests/suites/ as its own PHP process and fails the build when
 * one of them does.
 *
 * Why a runner instead of plain PHPUnit test methods: the plugin calls WordPress
 * functions in the global namespace and there is no WordPress to call. Each suite
 * therefore declares the stubs it needs, and suites that stub get_option() or
 * $wpdb differently cannot share a process. A subprocess per suite is what keeps
 * them independent, and it also keeps every suite runnable on its own:
 *
 *     php tests/suites/change-detection.php
 *
 * Each suite prints one line per assertion and exits non-zero on failure, so when
 * PHPUnit reports a failure the whole output is attached and names the assertion.
 *
 * @package RWBE_Product_Importer
 */

namespace RWBE\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SuiteRunnerTest extends TestCase {

	/**
	 * Every suite file, as [name => [path]] for the data provider.
	 *
	 * @return array<string, array{string}>
	 */
	public static function suites(): array {
		$files = glob( __DIR__ . '/suites/*.php' );
		self::assertNotEmpty( $files, 'No suites found in tests/suites/.' );

		$out = array();
		foreach ( $files as $file ) {
			$out[ basename( $file, '.php' ) ] = array( $file );
		}

		return $out;
	}

	#[DataProvider( 'suites' )]
	public function test_suite_passes( string $file ): void {
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open(
			array( PHP_BINARY, $file ),
			$descriptors,
			$pipes,
			dirname( __DIR__ )
		);

		$this->assertIsResource( $process, 'Could not start ' . basename( $file ) );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );

		// PHP's own deprecation notices about the test harness are not the suite's
		// verdict; the exit code is.
		$this->assertSame(
			0,
			$exit,
			sprintf(
				"Suite %s failed (exit %d).\n\n--- stdout ---\n%s\n--- stderr ---\n%s",
				basename( $file ),
				$exit,
				$stdout,
				$stderr
			)
		);

		// A suite that exits 0 without reporting a verdict would pass silently.
		$this->assertMatchesRegularExpression(
			'/PASSED|LOADED CLEANLY/',
			$stdout,
			sprintf( "Suite %s exited 0 but printed no verdict.\n\n%s", basename( $file ), $stdout )
		);

		// Surface a skipped group so a green run never hides missing coverage. This runs
		// after the assertions above, so the suite's verdict has already been checked;
		// marking it incomplete reports the gap without failing the build.
		if ( strpos( $stdout . $stderr, 'SKIPPED' ) !== false ) {
			$this->markTestIncomplete(
				'Suite ' . basename( $file ) . ' skipped a group: ' . trim( $stderr )
			);
		}
	}
}
