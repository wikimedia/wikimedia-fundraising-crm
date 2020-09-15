<?php

namespace wmf_civicrm;

use Statistics\Collector\AbstractCollector;
use Statistics\Exporter\Prometheus as PrometheusStatsExporter;

/**
 * Class ImportStatsCollector
 *
 * Handles recording of timing data across civicrm import process, specifically
 * the steps taken within the method wmf_civicrm_contribution_message_import

 *
 * @see wmf_civicrm_contribution_message_import
 */
class ImportStatsCollector extends AbstractCollector {

  /**
   * Default output filename for Prometheus .prom file
   *
   * @var string
   */
  public $outputFileName = "civicrm_import_stats";

  /**
   * Output file path.
   *
   * @var string
   */
  public $outputFilePath;

  /**
   * @var string
   */
  protected $defaultNamespace = "civicrm_import";

  /**
   * We append a unique token to timer stat names to group per-donation stats
   * and also keep stat names unique (needed for timers calculations to work).
   *
   * Typically the order_id would be unique enough but in unittests we do a
   * lot of repeat donations using the same order_id so it was easier to use
   * a uniqid. If we ever need to see the order_id we could append that also
   * but for now the timer stat names are only used internally.
   *
   * @var string
   */
  protected $uniqueStatToken;

  public function init() {
    $this->setUniqueStatToken(uniqid('', true));
  }

  /**
   * @return string
   */
  public function getUniqueStatToken(): ?string {
    return $this->uniqueStatToken;
  }

  /**
   * @param string $uniqueStatToken
   */
  public function setUniqueStatToken(string $uniqueStatToken): void {
    $this->uniqueStatToken = $uniqueStatToken;
  }

  /**
   * reset the uniqueStatToken
   */
  public function deinit(): void {
    $this->uniqueStatToken = NULL;
  }

  /**
   * @param $namespace
   */
  public function startImportTimer($namespace): void {
    if ($this->uniqueStatToken !== NULL) {
      $namespace = $namespace . "_" . $this->uniqueStatToken;
    }
    parent::startTimer($namespace);
  }

  /**
   * @param $namespace
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function endImportTimer($namespace): void {
    if ($this->uniqueStatToken !== NULL) {
      $namespace = $namespace . "_" . $this->uniqueStatToken;
    }
    parent::endTimer($namespace);
  }


  /**
   * Work out what stats we wanna export from those recorded and then
   * export to Prometheus log format.
   */
  public function export(): void {
    $importStatsToExport = $this->getStatsToExport();
    // looks like we have scenarios where no stats are recorded
    // so let's check here first to save writing an empty
    // file.
    if(!empty($importStatsToExport)) {
      $prometheusStatsExporter = new PrometheusStatsExporter(
        $this->outputFileName, $this->getOutputFilePath()
      );
      $prometheusStatsExporter->export($importStatsToExport);
    }

  }

  /**
   * Get the output file path.
   *
   * Default path is drupal global variable 'metrics_reporting_prometheus_path'
   * unless outputFilePath is set.
   *
   * @return string
   */
  public function getOutputFilePath(): string {
    if ($this->outputFilePath === NULL) {
      $this->outputFilePath = variable_get(
        'metrics_reporting_prometheus_path',
        '/var/spool/prometheus'
      );
    }
    return $this->outputFilePath;
  }

  /**
   * Let's pull out that stats we're interested in and work out their averages.
   *
   * @return array
   */
  protected function getStatsToExport(): array {
    // the list of stats below corresponds to the steps taken within the
    // wmf_civicrm_contribution_message_import method. We are interested
    // in working out how long each step within that method takes and we also
    // record the overall time of the main method for convenience.
    $statsOfInterest = [
      'wmf_civicrm_contribution_message_import', // main method
      'verify_and_stage',
      'get_recurring_payment_token', // recurring only
      'get_gateway_subscription',
      'create_contact',
      'message_location_update',
      'message_email_update',
      'message_contribution_recur_insert', // recurring only
      'message_contribution_insert',
    ];

    // loop through import stats, pull out the stats we want and average them.
    // export the averages for prometheus
    foreach ($statsOfInterest as $stat) {
      if ($this->exists("*$stat*")) {
        $statResults = $this->get("*$stat*", TRUE);

        // Note: $absoluteStatNamespace means the full namespace of the stat.
        // StatsCollector uses absolute and relative namespaces to find stuff.
        foreach ($statResults as $absoluteStatNamespace => $values) {
          // create a new stat which stores all processing times of the import
          // step recorded (e.g. message_contribution_insert) that we will
          // then average later
          $this->add(
            $stat . "_process_times",
            // we prefix the namespace with a dot to tell StatsCollector it's
            // an absolute namespace.
            $this->getTimerDiff("." . $absoluteStatNamespace, FALSE)
          );
        }

        if ($this->exists($stat . "_process_times")) {
          // now add our average of the previously added stats. These tells us how long
          // on average the import step took across all donations in the batch.
          $this->add(
            $stat . "_average_process_time",
            $this->avg($stat . "_process_times")
          );
        }
      }
    }

    if ($this->exists("*_average_process_time")) {
      return $this->get("*_average_process_time", TRUE);
    } else {
      // return an empty array if there's nothing to return
      return [];
    }
  }


  /**
   * Return the default namespace to be used to record stats against.
   *
   * @return string
   */
  protected function getDefaultNamespace(): string {
    return $this->defaultNamespace;
  }
}
