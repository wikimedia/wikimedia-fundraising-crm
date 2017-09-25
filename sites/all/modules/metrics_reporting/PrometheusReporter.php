<?php

/**
 * Write out metrics in a Prometheus-readable format.
 * Each component gets its own file, and each call to
 * reportMetrics overwrites the file with the new data.
 */
class PrometheusReporter implements MetricsReporter {

	/**
	 * Directory where we should write prometheus files
	 *
	 * @var string $prometheusPath
	 */
	protected $prometheusPath;

	/**
	 * @param string $prometheusPath
	 */
	function __construct( $prometheusPath ) {
		$this->prometheusPath = $prometheusPath;
	}

	/**
	 * Update the component's metrics. The entire component file will be
	 * overwritten each time this is called.
	 * TODO: might be nice to update just the affected rows so we can call
	 * this multiple times. When we want that, we'll need locking.
	 *
	 * @param string $component name of the component doing the reporting
	 * @param array $metrics associative array of metric names to values
	 */
	public function reportMetrics( $component, $metrics = array() ) {
		$contents = array();
		foreach ( $metrics as $name => $value ) {
			$contents[] = "$name $value\n";
		}
		$path = $this->prometheusPath . DIRECTORY_SEPARATOR . $component;
		file_put_contents( $path, implode( '', $contents ) );
	}
}
