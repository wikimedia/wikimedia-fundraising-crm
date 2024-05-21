<?php

namespace Civi\WMFStatistic;

/**
 * Write out metrics in a Prometheus-readable format.
 * Each component gets its own file, and each call to
 * reportMetrics overwrites the file with the new data.
 */
class PrometheusReporter implements MetricsReporter {

  public static string $extension = '.prom';

  /**
   * Directory where we should write prometheus files
   *
   * @var string
   */
  protected string $prometheusPath;

  /**
   * @param string $prometheusPath
   */
  public function __construct(string $prometheusPath) {
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
  public function reportMetrics(string $component, array $metrics = []) {
    $contents = [];
    foreach ($metrics as $name => $value) {
      // From Dallas failures because it doesn't like `TYPE job_fundraise-up_audit gauge`
      //if we were to change those to be _s or just fundraiseup it would be ok.
      //so metric names can contain ASCII letters, digits, underscores, and colons. but metric labels can't contain colons, so it's probably best to just use ASCII letters, digits, underscores
      //this is a restriction from the prometheus side, so not something you would usually be aware of.
      $name = str_replace('-', '_', $name);
      $contents[] = "$name $value\n";
    }
    $path = $this->prometheusPath .
      DIRECTORY_SEPARATOR .
      $component .
      self::$extension;
    file_put_contents($path, implode('', $contents));
  }

}
