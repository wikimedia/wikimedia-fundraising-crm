<?php

namespace queue2civicrm\contribution_tracking;

use Statistics\Collector\AbstractCollector;
use Statistics\Exporter\Prometheus as PrometheusStatsExporter;

/**
 * Class ContributionTrackingStatsCollector
 *
 * Handles contribution tracking stats recording & Prometheus file exporting.
 *
 * Note: We also track a count of the 'changed contribution id' bug via the
 * 'change_cid_errors' stat.
 *
 * @see AbstractCollector
 */
class ContributionTrackingStatsCollector extends AbstractCollector {

  /**
   * Default output filename for Prometheus .prom file
   *
   * @var string
   */
  public $outputFileName = "contribution_tracking";

  /**
   * Output file path.
   *
   * @var string
   */
  public $outputFilePath;

  /*
   * Stats timer namespace used for processing rate stats
   */
  public $timerNamespace = "contribution_tracking_queue_consumer";

  /**
   * @var string
   */
  protected $defaultNamespace = "contribution_tracking";

  public function recordContributionTrackingRecord() {
    $this->incrementStat("count");
  }

  public function recordChangeOfContributionIdError() {
    $this->incrementStat("change_cid_errors");
  }

  /**
   * Override parent startTimer() to inject default namespace
   *
   * @param $namespace
   * @param null $customTimestamp
   * @param bool $useTimerNamespacePrefix
   */
  public function startTimer(
    $namespace = NULL,
    $customTimestamp = NULL,
    $useTimerNamespacePrefix = TRUE
  ) {
    if ($namespace === NULL) {
      $namespace = $this->timerNamespace;
    }
    parent::startTimer($namespace, $customTimestamp, $useTimerNamespacePrefix);
  }

  /**
   * Override parent endTimer() to inject default namespace
   *
   * @param $namespace
   * @param null $customTimestamp
   * @param bool $useTimerNamespacePrefix
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function endTimer(
    $namespace = NULL,
    $customTimestamp = NULL,
    $useTimerNamespacePrefix = TRUE
  ) {
    if ($namespace === NULL) {
      $namespace = $this->timerNamespace;
    }
    parent::endTimer($namespace, $customTimestamp, $useTimerNamespacePrefix);
  }

  /**
   * Generate export stats data and export to backends.
   *
   * Currently we only export to Prometheus.
   */
  public function export() {
    $this->generateAverageProcessingTimeStats();
    $this->purgeTimerNamespace();
    $this->exportToPrometheus();
  }

  /**
   * Get the output file path.
   *
   * Default path is drupal global variable 'metrics_reporting_prometheus_path'
   * unless outputFilePath is set.
   *
   * @return null|string
   */
  public function getOutputFilePath() {
    if ($this->outputFilePath === NULL) {
      $this->outputFilePath = variable_get(
        'metrics_reporting_prometheus_path',
        '/var/spool/prometheus'
      );
    }
    return $this->outputFilePath;
  }

  protected function exportToPrometheus() {
    $prometheusStatsExporter = new PrometheusStatsExporter(
      $this->outputFileName, $this->getOutputFilePath()
    );
    $prometheusStatsExporter->export($this->all());
  }

  protected function generateAverageProcessingTimeStats() {
    $totalProcessed = $this->getStat("count");

    $fullyQualifiedTimerNamespace = self::TIMERS_NS . self::SEPARATOR . $this->timerNamespace;
    if ($this->exists($fullyQualifiedTimerNamespace)) {
      $batchProcessingTime = $this->getTimerDiff($this->timerNamespace);
    }
    else {
      return;
    }

    $donationsProcessedPerSecond = $totalProcessed / $batchProcessingTime;
    $donationProcessingAverages = [
      'period=batch' => $batchProcessingTime,
      'period=1s' => $donationsProcessedPerSecond,
      'period=5s' => ($donationsProcessedPerSecond * 5),
      'period=10s' => ($donationsProcessedPerSecond * 10),
      'period=30s' => ($donationsProcessedPerSecond * 30),
    ];

    $this->add("processing_rate", $donationProcessingAverages);
  }

  /**
   * Let's remove any library-generated stat namespaces before exporting to
   * Prometheus.
   */
  protected function purgeTimerNamespace() {
    if ($this->exists("timer")) {
      $this->removeStat("timer");
    }
  }

  /**
   * Return the default namespace to be used to record stats against.
   *
   * @return string
   */
  protected function getDefaultNamespace() {
    return $this->defaultNamespace;
  }

}
