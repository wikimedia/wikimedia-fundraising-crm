<?php

/**
 * The tests for the testOverallAverage*() methods are admittedly a little confusing due to the
 * DonationStats API not allowing access to individual stat values once recorded.
 *
 * However! fear not because even though testing averages to confirm individual records appears
 * counter-intuitive, we are only adding a single donation per test meaning the averages will be
 * identical be the individual values used per test because `avg(1) == 1`
 *
 * @group Queue2Civicrm
 * @group DonationStats
 */
class DonationStatsTest extends BaseWmfDrupalPhpUnitTestCase {

  protected $statsFilename;

  protected $statsFilePath;

  protected $statsFileExtension;

  public function setUp() {
    parent::setUp();
    $this->setupStatsCollector();
  }

  public function testOverallAverageGatewayTransactionAgeRecorded() {
    $message = [
      'gateway' => "ACME_PAYMENTS",
    ];
    $contribution = [
      //simulate a transaction date 1 hour earlier from now()
      'receive_date' => \SmashPig\Core\UtcDate::getUtcDatabaseString('-1 hour'),
    ];

    $DonationStats = new DonationStats();
    $DonationStats->recordDonationStats($message, $contribution);
    $OverallAverageGatewayTransactionAge = $DonationStats->getOverallAverageGatewayTransactionAge();

    //compare recorded stats data with expected age in seconds (3600 seconds = 1 hour)
    $this->assertEquals(3600, $OverallAverageGatewayTransactionAge);
  }

  public function testOverallAverageMessageEnqueuedAgeRecordedWhenPresent() {
    $message = [
      'gateway' => "ACME_PAYMENTS",
      // populating 'source_enqueued_time' with a timestamp one hour earlier from now()
      'source_enqueued_time' => \SmashPig\Core\UtcDate::getUtcTimestamp('-1 hour'),
    ];
    $contribution = [
      'receive_date' => \SmashPig\Core\UtcDate::getUtcDatabaseString('-1 hour'),
    ];

    $DonationStats = new DonationStats();
    $DonationStats->recordDonationStats($message, $contribution);
    $OverallAverageMessageEnqueuedAge = $DonationStats->getOverallAverageMessageEnqueuedAge();

    //compare written stats data with expected age in seconds (3600 seconds = 1 hour)
    $this->assertEquals(3600, $OverallAverageMessageEnqueuedAge);
  }

  public function testOverallAverageMessageEnqueuedAgeNotRecordedWhenNotPresent() {
    $message = [
      'gateway' => "ACME_PAYMENTS",
      // omitting 'source_enqueued_time'
    ];
    $contribution = [
      'receive_date' => \SmashPig\Core\UtcDate::getUtcDatabaseString(),
    ];

    $DonationStats = new DonationStats();
    $DonationStats->recordDonationStats($message, $contribution);
    $OverallAverageMessageEnqueuedAge = $DonationStats->getOverallAverageMessageEnqueuedAge();

    $this->assertNull($OverallAverageMessageEnqueuedAge);
  }

  public function testExportingStatsToFile() {
    $this->setUpStatsOutFileProperties();
    $message = [
      'gateway' => "ACME_PAYMENTS",
    ];
    $contribution = [
      'receive_date' => \SmashPig\Core\UtcDate::getUtcDatabaseString(),
    ];

    // test output file does not currently exist
    $this->assertFileNotExists($this->statsFilePath . $this->statsFilename . $this->statsFileExtension);

    $DonationStats = new DonationStats();
    $DonationStats->recordDonationStats($message, $contribution);
    $DonationStats->prometheusOutputFileName = $this->statsFilename;
    $DonationStats->prometheusOutputFilePath = rtrim($this->statsFilePath, '/');
    $DonationStats->export();

    //test output file has been created
    $this->assertFileExists($this->statsFilePath . $this->statsFilename . $this->statsFileExtension);

    // clean up
    $this->removeStatsOutFile();
  }

  public function testExportedStatsValues() {
    $this->setUpStatsOutFileProperties();
    $message = [
      'gateway' => "ACME_PAYMENTS",
    ];
    $contribution = [
      //simulate a transaction date 1 hour earlier from now()
      'receive_date' => \SmashPig\Core\UtcDate::getUtcDatabaseString('-1 hour'),
    ];

    $DonationStats = new DonationStats();
    $DonationStats->recordDonationStats($message, $contribution);
    $DonationStats->prometheusOutputFileName = $this->statsFilename;
    $DonationStats->prometheusOutputFilePath = rtrim($this->statsFilePath, '/');
    $DonationStats->export();

    $expectedStats = [
      'donations_gateway_ACME_PAYMENTS' => 1,
      'donations_overall_donations' => 1,
      'donations_overall_average_transaction_age' => 3600, // should be -1 hour from now (3600 secs)
      'donations_average_transaction_age_ACME_PAYMENTS' => 3600,
    ];

    $statsFileFullPath = $this->statsFilePath . $this->statsFilename . $this->statsFileExtension;
    $statsWrittenAssocArray = [];
    $statsWritten = rtrim(file_get_contents($statsFileFullPath)); // remove trailing \n
    $statsWrittenLinesArray = explode("\n", $statsWritten);
    foreach ($statsWrittenLinesArray as $statsLine) {
      list($name, $value) = explode(' ', $statsLine);
      $statsWrittenAssocArray[$name] = $value;
    }

    //compare written stats data with expected
    $this->assertEquals($expectedStats, $statsWrittenAssocArray);

    // clean up
    $this->removeStatsOutFile();
  }

  public function tearDown() {
    parent::tearDown();
  }

  private function setUpStatsOutFileProperties() {
    $this->statsFilename = "test_stats";
    $this->statsFilePath = CRM_Utils_File::tempdir();
    $this->statsFileExtension = '.prom';
  }

  private function removeStatsOutFile() {
    unlink($this->statsFilePath . $this->statsFilename . $this->statsFileExtension);
    rmdir($this->statsFilePath);
  }

  /**
   * Stats Collector singleton (used internally by DonationStats) needs resetting before each test.
   *
   * DonationQueueTest indirectly records stats when calling
   * DonationQueueConsumer::processMessage() so we clear all instances before each run to test
   * from a known starting point.
   *
   * @see DonationQueueTest
   */
  private function setupStatsCollector() {
    \Statistics\Collector\Collector::tearDown(TRUE);
  }
}
