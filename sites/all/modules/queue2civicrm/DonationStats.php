<?php

use Statistics\Collector\Collector;
use Statistics\Exporter\Prometheus as PrometheusStatsExporter;
use SmashPig\Core\UtcDate;

/**
 * Class DonationStats
 *
 * Handles donation stats recording & exporting using Stats Collector
 *
 * @see Collector
 */
class DonationStats {

  /**
   * Default output filename for Prometheus .prom file
   *
   * @var string
   */
  public $prometheusOutputFileName = "donations";

  /**
   * Custom Prometheus output file path. Default export behaviour is to use drupal global
   * variable 'metrics_reporting_prometheus_path' unless this value is set.
   *
   * @var string
   */
  public $prometheusOutputFilePath;

  /**
   * @var Statistics\Collector\Collector
   */
  public $statsCollector;

  public function __construct() {
    $this->statsCollector = Collector::getInstance();
    // set the root namespace for all donation related stats
    $this->statsCollector->ns("donations");
  }

  /**
   * Record donation stats:
   * 1) Number of donations by gateway
   * 2) Number of overall donations
   * 3) Time between gateway transaction time and civiCRM import time (now)
   * 4) Gateway specific moving average of (3)
   * 5) Overall moving average of (3)
   * 6) Time between donation message enqueued time and civiCRM import time (now)
   * 7) Gateway specific moving average of (6)
   * 8) Overall moving average of (6)
   *
   * This method is called within @see DonationQueueConsumer::processMessage()
   *
   * @param array $message
   * @param array $contribution
   */
  public function recordDonationStats($message, $contribution) {
    $paymentGateway = $message['gateway'];
    $gatewayTransactionTime = $contribution['receive_date'];

    // donation counter
    $this->recordGatewayDonation($paymentGateway);
    $this->recordOverallDonations();

    // difference between gateway transaction time to civiCRM save time
    $this->recordGatewayTransactionAge($paymentGateway, $gatewayTransactionTime);
    $this->recordAverageGatewayTransactionAge($paymentGateway);
    $this->recordOverallAverageGatewayTransactionAge();

    // difference between message enqueued time to civiCRM save time
    if (isset($message['source_enqueued_time'])) {
      $messageEnqueuedTime = $message['source_enqueued_time'];
      $this->recordMessageEnqueuedAge($paymentGateway, $messageEnqueuedTime);
      $this->recordAverageGatewayMessageEnqueuedAge($paymentGateway);
      $this->recordOverallAverageMessageEnqueuedAge();
    }
  }

  /**
   * Get overall average gateway transaction age at end of queue consumer batch run
   *
   * @return float|int
   */
  public function getOverallAverageGatewayTransactionAge() {
    return $this->statsCollector->get("overall.average.transaction_age");
  }

  /**
   * Get overall average message age at end of queue consumer batch run
   *
   * @return float|int
   */
  public function getOverallAverageMessageEnqueuedAge() {
    return $this->statsCollector->get("overall.average.enqueued_age");
  }

  /**
   * Export recorded stats to an output format to then be consumed upstream.
   * Record DonationQueueConsumer total batch processing time.
   *
   * Also trigger the call to generate and record average-donations-processed stats.
   *
   * @param $batchProcessingTime
   */
  public function recordBatchProcessingTime($batchProcessingTime) {
    $this->statsCollector->add("overall.processing_time", $batchProcessingTime);
    $this->generateAndRecordAverageDonationsProcessedStats($batchProcessingTime);
  }

  /**
   * Export donation stats data to Prometheus out files using the
   * Statistics\Exporter\Prometheus exporter.
   *
   * Currently we only export to Prometheus.
   */
  public function export() {
    $this->exportToPrometheus();
  }

  /**
   * Record a stat to count/increment the number of gateway specific donations
   *
   * @param string $paymentGateway
   */
  protected function recordGatewayDonation($paymentGateway) {
    $this->statsCollector->inc("gateway.{$paymentGateway}", 1);
  }

  /**
   * Set/update the current total count of all donations during this queue consumer run
   */
  protected function recordOverallDonations() {
    $this->statsCollector->clobber("overall.donations", $this->statsCollector->sum("gateway.*"));
  }

  /**
   * Record a stat for the difference between gateway transaction time to civiCRM save time
   *
   * @param string $paymentGateway
   * @param $gatewayTransactionTime
   */
  protected function recordGatewayTransactionAge($paymentGateway, $gatewayTransactionTime) {
    // work out time between gateway's official transaction time and now
    $gatewayReceivedAge = UtcDate::getUtcTimestamp() - UtcDate::getUtcTimestamp($gatewayTransactionTime);
    $this->statsCollector->add("transaction_age.{$paymentGateway}", $gatewayReceivedAge);
  }

  /**
   * Set/update the current moving average of gateway transaction age
   *
   * @param string $paymentGateway
   */
  protected function recordAverageGatewayTransactionAge($paymentGateway) {
    $this->statsCollector->clobber("average.transaction_age.{$paymentGateway}",
      $this->statsCollector->avg("transaction_age.{$paymentGateway}")
    );
  }

  /**
   * Set/update the overall current moving average for all payment gateway transaction ages
   */
  protected function recordOverallAverageGatewayTransactionAge() {
    $this->statsCollector->clobber("overall.average.transaction_age",
      $this->statsCollector->avg("transaction_age.*")
    );
  }

  /**
   * Record a stat for the difference between message enqueued time to civiCRM save time
   *
   * @param string $paymentGateway
   * @param $messageEnqueuedTime
   */
  protected function recordMessageEnqueuedAge($paymentGateway, $messageEnqueuedTime) {
    // work out time between the message enqueued time and now if 'source_enqueued_time' is set
    $enqueuedAge = UtcDate::getUtcTimestamp() - $messageEnqueuedTime;
    $this->statsCollector->add("enqueued_age.{$paymentGateway}", $enqueuedAge);
  }

  /**
   * Set/update the current moving average of gateway enqueued message ages
   *
   * @param string $paymentGateway
   */
  protected function recordAverageGatewayMessageEnqueuedAge($paymentGateway) {
    $this->statsCollector->clobber("average.enqueued_age.{$paymentGateway}",
      $this->statsCollector->avg("enqueued_age.{$paymentGateway}")
    );
  }

  /**
   * Set/update the current moving average of enqueued message ages
   */
  protected function recordOverallAverageMessageEnqueuedAge() {
    $this->statsCollector->clobber("overall.average.enqueued_age",
      $this->statsCollector->avg("enqueued_age.*")
    );
  }

  /*
   * Record the average number of donations processed within a per-second time period using
   * average-donations-processed-per-second as the base and extrapolating upwards to
   * estimate averages over longer periods.
   *
   * This method of blind average scaling will distort the *actual* messages-processed
   * per-time-window so will not be useful or accurate above short time periods.
   *
   * This stat is passed as an associative array so that it is mapped as a Prometheus metric
   * with labels for each seconds grouping.
   *
   * @param $batchProcessingTime
   */
  protected function generateAndRecordAverageDonationsProcessedStats($batchProcessingTime) {
    $totalDonationsProcessed = $this->statsCollector->get("overall.donations");

    $donationsProcessedPerSecond = $totalDonationsProcessed / $batchProcessingTime;
    $donationProcessingAverages = [
      'period=1s' => $donationsProcessedPerSecond,
      'period=5s' => ($donationsProcessedPerSecond * 5),
      'period=10s' => ($donationsProcessedPerSecond * 10),
      'period=30s' => ($donationsProcessedPerSecond * 30),
    ];

    $this->statsCollector->add("average.donations.processed", $donationProcessingAverages);
  }

  /**
   * Export stats data to a Prometheus .prom out file using the
   * PrometheusStatsExporter exporter.
   *
   * @see PrometheusStatsExporter
   */
  protected function exportToPrometheus() {
    // get the output file name and file path
    if (isset($this->prometheusOutputFilePath)) {
      $path = $this->prometheusOutputFilePath;
    } else {
      $path = variable_get(
        'metrics_reporting_prometheus_path', '/var/spool/prometheus'
      );
    }
    $filename = $this->prometheusOutputFileName;

    $prometheusStatsExporter = new PrometheusStatsExporter($filename, $path);
    $donationStats = $this->getDonationStatsFromStatsCollector();
    $prometheusStatsExporter->export($donationStats);
  }

  /**
   * We only want to pull the *unique* summary data when exporting to Prometheus
   *
   * @return array|mixed
   */
  protected function getDonationStatsFromStatsCollector() {
    return $this->statsCollector->getWithKey([
      'donations.average.*',
      'donations.overall.*',
      'donations.gateway.*',
    ]);
  }

}