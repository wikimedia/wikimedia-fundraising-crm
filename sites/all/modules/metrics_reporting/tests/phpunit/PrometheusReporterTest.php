<?php

/**
 * @group Metrics
 */
class PrometheusReporterTest extends BaseWmfDrupalPhpUnitTestCase {
	public function testReportMetrics() {
		$dir = CRM_Utils_File::tempdir();
		$reporter = new PrometheusReporter( $dir );
		$metrics = array(
			'daisies_picked' => 157,
			'orcs_befriended' => 3,
			'prisoners_freed' => 5,
		);
		$reporter->reportMetrics( 'foo', $metrics );
		$filename = $dir . DIRECTORY_SEPARATOR . 'foo';
		$this->assertFileExists( $filename );
		// don't want the trailing newline
		$written = rtrim( file_get_contents( $filename ) );
		$split = explode( "\n", $written );
		foreach ( $split as $metric ) {
			list( $name, $value ) = explode( ' ', $metric );
			$this->assertEquals( $metrics[$name], $value );
			unset( $metrics[$name] );
		}
		$this->assertEmpty( $metrics, 'A metric was left unwritten' );
		unlink( $filename );
		rmdir( $dir );
	}
}
