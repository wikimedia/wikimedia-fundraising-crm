<?php

interface MetricsReporter {
	/**
	 * @param string $component name of the component doing the reporting
	 * @param array $metrics associative array of metric names to values
	 */
	public function reportMetrics( $component, $metrics = array() );
}
