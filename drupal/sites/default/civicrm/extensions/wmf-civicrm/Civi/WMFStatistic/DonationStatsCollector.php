<?php

namespace Civi\WMFStatistic;

use SmashPig\Core\UtcDate;
use Statistics\Collector\AbstractCollector;
use Statistics\Exporter\Prometheus as PrometheusStatsExporter;

/**
 * Class DonationStatsCollector
 *
 * Handles donation stats recording & Prometheus file format exporting.
 *
 * @see AbstractCollector
 */
class DonationStatsCollector extends AbstractCollector {

  /**
   * Default output filename for Prometheus .prom file
   *
   * @var string
   */
  public string $outputFileName = 'donations';

  /**
   * Output file path.
   *
   * @var string
   */
  public string $outputFilePath;

  /**
   * Stats timer namespace used for processing rate stats.
   *
   * @var string
   */
  public string $timerNamespace = 'queue2civicrm';

  /**
   * @var string
   */
  protected string $defaultNamespace = 'donations';

  /**
   * Populated during generateOverallStats() and used within convenience method
   * getOverallAverageGatewayTransactionAge()
   *
   * @var float
   */
  protected $overallAverageGatewayTransactionAge;

  /**
   * Record donation stats:
   * 1) Number of donations by gateway
   * 2) Time between gateway transaction time and civiCRM import time (now)
   * 3) Time between donation message enqueued time and civiCRM import time
   * (now)
   *
   * This method is called within DonationQueueConsumer::processMessage()
   *
   * @param array $message
   * @param array $contribution
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   * @see DonationQueueConsumer::processMessage()
   *
   */
  public function recordDonationStats(array $message, array $contribution) {
    $gateway = $message['gateway'];
    $gatewayTransactionTime = $contribution['receive_date'];

    // increment the gateway donations counter & record gateway
    $this->incrementCompoundStat("count", [$gateway => 1]);

    // record the time between gateway's official transaction time and now
    $gatewayTransactionAge = UtcDate::getUtcTimestamp() - UtcDate::getUtcTimestamp($gatewayTransactionTime);
    $this->addStat("transaction_age", [$gateway => $gatewayTransactionAge]);

    if (isset($message['source_enqueued_time'])) {
      // record the time between the original message enqueued time and now
      $enqueuedAge = UtcDate::getUtcTimestamp() - $message['source_enqueued_time'];
      $this->addStat("enqueued_age", [$gateway => $enqueuedAge]);
    }
  }

  /**
   * Call parent startTimer() with default namespace
   * TODO: upstream to parent class
   */
  public function startDefaultTimer() {
    parent::startTimer($this->timerNamespace);
  }

  /**
   * Call parent endTimer() with default namespace
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function endDefaultTimer() {
    parent::endTimer($this->timerNamespace);
  }

  /**
   * Generate export stats data and export to backends.
   *
   * Currently, we only export to Prometheus.
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function export() {
    $this->generateAggregateStats();
    $this->purgeSuperfluousStats();
    $this->exportToPrometheus();
  }

  /**
   * Get the output file path.
   *
   * Default path is CiviCRM setting 'metrics_reporting_prometheus_path' unless
   * outputFilePath is set.
   *
   * @return null|string
   */
  public function getOutputFilePath(): string {
    if (!isset($this->outputFilePath)) {
      $this->outputFilePath = \Civi::settings()
        ->get('metrics_reporting_prometheus_path');
    }
    return $this->outputFilePath;
  }

  /**
   * Convenience method for logging average transaction ages
   */
  public function getOverallAverageGatewayTransactionAge(): float {
    return $this->overallAverageGatewayTransactionAge;
  }

  /**
   * Some prometheus specific mapping and then export using
   * PrometheusStatsExporter
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  protected function exportToPrometheus() {
    $this->mapStatsKeysToPrometheusLabels();
    $prometheusStatsExporter = new PrometheusStatsExporter($this->outputFileName, $this->getOutputFilePath());
    $prometheusStatsExporter->export($this->all());
  }

  /**
   * Generate the averages and overall stats from the individually recorded
   * stats
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  protected function generateAggregateStats() {
    $this->generateAverageStats();
    $this->generateOverallStats();
    $this->generateAverageDonationsProcessingTimeStats();
  }

  /**
   * The below averaging would be less verbose if the Stats Collector could
   * also average literal values.
   */
  protected function generateAverageStats() {
    if ($this->exists("transaction_age")) {
      foreach ($this->get("transaction_age") as $gateway => $transactionAges) {
        $gatewayTransactionAgeAverage = (is_array($transactionAges)) ? array_sum($transactionAges) / count($transactionAges) : $transactionAges;
        $this->add("average_transaction_age", [$gateway => $gatewayTransactionAgeAverage]);
      }
    }

    if ($this->exists("enqueued_age")) {
      foreach ($this->get("enqueued_age") as $gateway => $enqueuedAges) {
        $gatewayEnqueuedAgeAverage = (is_array($enqueuedAges)) ? array_sum($enqueuedAges) / count($enqueuedAges) : $enqueuedAges;
        $this->add("average_enqueued_age", [$gateway => $gatewayEnqueuedAgeAverage]);
      }
    }
  }

  /**
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  protected function generateOverallStats() {
    if ($this->exists("transaction_age")) {
      $this->overallAverageGatewayTransactionAge = $overallAverageGatewayTransactionAge = $this->avg("transaction_age");
      $this->add("average_transaction_age", [
        "all" => $overallAverageGatewayTransactionAge,
      ]);
    }

    if ($this->exists("enqueued_age")) {
      $this->add("average_enqueued_age", [
        "all" => $this->avg("enqueued_age"),
      ]);
    }

    if ($this->exists("count")) {
      $this->add("count", [
        "all" => $this->sum("count"),
      ]);
    }
  }

  /**
   * Record the average number of donations processed within a per-second time
   * period using average-donations-processed-per-second as the base and
   * extrapolating upwards to estimate averages over longer periods.
   *
   * This method of blind average scaling will distort the *actual*
   * messages-processed per-time-window so will not be useful or accurate above
   * short time periods.
   *
   * This stat is passed as an associative array so that it is mapped as a
   * Prometheus metric with labels for each seconds grouping.
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  protected function generateAverageDonationsProcessingTimeStats(): void {
    // get total donations count
    $countStats = $this->get("count", FALSE, []);
    if (isset($countStats['all'])) {
      $totalDonations = $countStats['all'];
    }
    else {
      return;
    }

    $fullyQualifiedTimerNamespace = self::TIMERS_NS . self::SEPARATOR . $this->timerNamespace;
    if ($this->exists($fullyQualifiedTimerNamespace)) {
      $batchProcessingTime = $this->getTimerDiff($this->timerNamespace);
    }
    else {
      return;
    }

    $donationsProcessedPerSecond = $totalDonations / $batchProcessingTime;
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
   * We only want to pull the *unique* summary data when exporting to
   * Prometheus. Superfluous are purged prior to export.
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  protected function purgeSuperfluousStats() {
    if ($this->exists("enqueued_age")) {
      $this->removeStat("enqueued_age");
    }
    if ($this->exists("transaction_age")) {
      $this->removeStat("transaction_age");
    }
    if ($this->exists("timer")) {
      $this->removeStat("timer");
    }
  }

  /**
   * We map some stats data to a more Prometheus-friendly format so that
   * these stats are processed with gateway-specific labels. We do this
   * separately to allow exporting of these stats to other backends in the
   * future.
   *
   * (to export to another backend, we would probably want to change the
   * *global* namespaces written to below to something more backend specific
   * e.g. backend.average_transaction_age, at point of mapping)
   *
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  protected function mapStatsKeysToPrometheusLabels() {
    $statsToBeMappedToLabelFormat = [
      'average_transaction_age',
      'average_enqueued_age',
      'count',
    ];
    // due to array_map not allowing you to modify array keys, we go the long way around
    $mapPaymentGatewayKeysToPrometheusLabelsFunc = function(&$value) {
      $value = "gateway=$value";
    };

    foreach ($statsToBeMappedToLabelFormat as $stat) {
      if ($this->exists($stat)) {
        $values = $this->get($stat);
        $keys = array_keys($values);
        array_walk($keys, $mapPaymentGatewayKeysToPrometheusLabelsFunc);
        $this->removeStat($stat);
        $this->add($stat, array_combine($keys, $values));
      }
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
