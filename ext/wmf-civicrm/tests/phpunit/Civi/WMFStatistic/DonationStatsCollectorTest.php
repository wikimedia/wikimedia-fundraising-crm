<?php

namespace Civi\WMFStatistic;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\WMFEnvironmentTrait;
use CRM_Utils_File;
use PHPUnit\Framework\TestCase;
use Statistics\Collector\AbstractCollector;

/**
 * Tests for DonationStatsCollector.
 *
 * Where visibility is an obstacle, we use reflection to trigger behaviour to ensure internal results are correct
 *
 * @group Queue2Civicrm
 * @group DonationStats
 */
class DonationStatsCollectorTest extends TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use WMFEnvironmentTrait;

  protected string $statsFilename;

  protected string $statsFilePath;

  protected string $statsFileExtension;

  /**
   * @var \Civi\WMFStatistic\DonationStatsCollector
   */
  protected $donationStatsCollector;

  public function setUp(): void {
    parent::setUp();
    $this->donationStatsCollector = $this->getTestDonationStatsCollectorInstance();
  }

  /**
   * @dataProvider singleDummyDonationDataProvider
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function testCanRecordSingleDonationStat($message, $contribution) {
    $this->donationStatsCollector->recordDonationStats($message, $contribution);

    $recordedStats = $this->donationStatsCollector->getAllStats();

    $expected = [
      'test_namespace.count' => ['ACME_PAYMENTS' => 1],
      // 1 hour
      'test_namespace.transaction_age' => ['ACME_PAYMENTS' => 3600],
    ];

    $this->assertEquals($expected, $recordedStats);
  }

  /**
   * @dataProvider singleDummyDonationDataProvider
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function testCanRecordMultipleDonationStats($message, $contribution) {
    // count stat doesn't exist yet
    $this->assertFalse($this->donationStatsCollector->exists("count"));

    //record stats
    for ($i = 0; $i < 3; $i++) {
      $this->donationStatsCollector->recordDonationStats($message, $contribution);
    }

    $recordedStats = $this->donationStatsCollector->getAllStats();

    $expected = [
      'test_namespace.count' => ['ACME_PAYMENTS' => 3],
      'test_namespace.transaction_age' => [
        'ACME_PAYMENTS' => [3600, 3600, 3600],
      ],
    ];

    $this->assertEquals($expected, $recordedStats);
  }

  /**
   * @dataProvider singleDummyDonationDataProvider
   * @throws \Statistics\Exception\StatisticsCollectorException
   * @throws \ReflectionException
   */
  public function testCanGenerateDonationProcessingRateStats($message, $contribution) {
    // open up protected methods
    $reflectionMethodAggregates = new \ReflectionMethod(DonationStatsCollector::class, 'generateAggregateStats');
    $reflectionMethodPurgeSuperfluousStats = new \ReflectionMethod(DonationStatsCollector::class, 'purgeSuperfluousStats');
    $reflectionMethodAggregates->setAccessible(TRUE);
    $reflectionMethodPurgeSuperfluousStats->setAccessible(TRUE);

    $start = microtime(TRUE);
    $this->donationStatsCollector->recordDonationStats($message, $contribution);
    $end = microtime(TRUE);

    //simulate the tracking with supplied timestamps
    $this->donationStatsCollector->timerNamespace = "test";
    $this->donationStatsCollector->startTimer("test", $start);
    $this->donationStatsCollector->endTimer("test", $end);

    // call generateAggregateStats() && purgeSuperfluousStats()
    $reflectionMethodAggregates->invoke($this->donationStatsCollector);
    $reflectionMethodPurgeSuperfluousStats->invoke($this->donationStatsCollector);

    $recordedStats = $this->donationStatsCollector->getAllStats();

    // we expect the internal times to be generated exactly as worked out below
    $batchProcessingTime = $end - $start;
    // we only recorded one (line 80)
    $totalDonations = 1;
    $donationsProcessedPerSecond = $totalDonations / $batchProcessingTime;

    $expected = [
      'test_namespace.count' => ['ACME_PAYMENTS' => 1, 'all' => 1],
      'test_namespace.average_transaction_age' => ['ACME_PAYMENTS' => 3600, 'all' => 3600],
      'test_namespace.processing_rate' => [
        'period=batch' => $batchProcessingTime,
        'period=1s' => $donationsProcessedPerSecond,
        'period=5s' => $donationsProcessedPerSecond * 5,
        'period=10s' => $donationsProcessedPerSecond * 10,
        'period=30s' => $donationsProcessedPerSecond * 30,
      ],
    ];

    $this->assertEquals($expected, $recordedStats);
  }

  /**
   * @dataProvider doubleDummyDonationDataProvider
   * @throws \Statistics\Exception\StatisticsCollectorException|\ReflectionException
   */
  public function testCanGenerateAverageDataFromRecordedStats($message, $contribution) {
    // open up protected method
    $reflectionMethod = new \ReflectionMethod(DonationStatsCollector::class, 'generateAverageStats');
    $reflectionMethod->setAccessible(TRUE);

    //record stats
    for ($i = 0; $i < 2; $i++) {
      $message[$i]['source_enqueued_time'] = \SmashPig\Core\UtcDate::getUtcTimestamp($message[$i]['source_enqueued_time']);
      $this->donationStatsCollector->recordDonationStats($message[$i], $contribution[$i]);
    }

    // call $DonationStatsCollector->generateAverageStats()
    $reflectionMethod->invoke($this->donationStatsCollector);

    $recordedStats = $this->donationStatsCollector->getAllStats();

    $expected = [
      'test_namespace.count' => [
        'ACME_PAYMENTS' => 1,
        'NICE_GATEWAY' => 1,
      ],
      'test_namespace.transaction_age' => ['ACME_PAYMENTS' => 3600, 'NICE_GATEWAY' => 3600],
      'test_namespace.enqueued_age' => ['ACME_PAYMENTS' => 3600, 'NICE_GATEWAY' => 3600],
      'test_namespace.average_enqueued_age' => [
        //average data
        'ACME_PAYMENTS' => 3600,
        //average data
        'NICE_GATEWAY' => 3600,
      ],
      'test_namespace.average_transaction_age' => [
        //average data
        'ACME_PAYMENTS' => 3600,
        //average data
        'NICE_GATEWAY' => 3600,
      ],
    ];

    $this->assertEquals($expected, $recordedStats);
  }

  /**
   * @dataProvider doubleDummyDonationDataProvider
   * @throws \ReflectionException
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function testCanGenerateOverallDataFromRecordedStats($message, $contribution) {
    // open up protected methods
    $reflectionMethodAverages = new \ReflectionMethod(DonationStatsCollector::class, 'generateAverageStats');
    $reflectionMethodOverall = new \ReflectionMethod(DonationStatsCollector::class, 'generateOverallStats');
    $reflectionMethodAverages->setAccessible(TRUE);
    $reflectionMethodOverall->setAccessible(TRUE);

    //record stats
    for ($i = 0; $i < 2; $i++) {
      $message[$i]['source_enqueued_time'] = \SmashPig\Core\UtcDate::getUtcTimestamp($message[$i]['source_enqueued_time']);
      $this->donationStatsCollector->recordDonationStats($message[$i], $contribution[$i]);
    }

    // call generateAverageStats() && generateOverallStats()
    $reflectionMethodAverages->invoke($this->donationStatsCollector);
    $reflectionMethodOverall->invoke($this->donationStatsCollector);

    $recordedStats = $this->donationStatsCollector->getAllStats();

    $expected = [
      'test_namespace.count' => [
        'ACME_PAYMENTS' => 1,
        'NICE_GATEWAY' => 1,
        // overall data
        'all' => 2,
      ],
      'test_namespace.transaction_age' => ['ACME_PAYMENTS' => 3600, 'NICE_GATEWAY' => 3600],
      'test_namespace.enqueued_age' => ['ACME_PAYMENTS' => 3600, 'NICE_GATEWAY' => 3600],
      'test_namespace.average_enqueued_age' => [
        'ACME_PAYMENTS' => 3600,
        'NICE_GATEWAY' => 3600,
        // overall data
        'all' => 3600,
      ],
      'test_namespace.average_transaction_age' => [
        'ACME_PAYMENTS' => 3600,
        'NICE_GATEWAY' => 3600,
        // overall data
        'all' => 3600,
      ],
    ];

    $this->assertEquals($expected, $recordedStats);
  }

  /**
   * @dataProvider singleDummyDonationDataProvider
   *
   * @param $message
   * @param $contribution
   *
   * @throws \ReflectionException
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function testGetOverallAverageGatewayTransactionAge($message, $contribution) {
    // open up protected methods
    $reflectionMethodAverages = new \ReflectionMethod(DonationStatsCollector::class, 'generateAverageStats');
    $reflectionMethodOverall = new \ReflectionMethod(DonationStatsCollector::class, 'generateOverallStats');
    $reflectionMethodAverages->setAccessible(TRUE);
    $reflectionMethodOverall->setAccessible(TRUE);

    $this->donationStatsCollector->recordDonationStats($message, $contribution);

    // call generateAverageStats() && generateOverallStats()
    $reflectionMethodAverages->invoke($this->donationStatsCollector);
    $reflectionMethodOverall->invoke($this->donationStatsCollector);

    $OverallAverageGatewayTransactionAge = $this->donationStatsCollector->getOverallAverageGatewayTransactionAge();

    $this->assertEquals(3600, $OverallAverageGatewayTransactionAge);
  }

  /**
   * @dataProvider singleDummyDonationDataProvider
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function testExportingStatsCreatesOutputFile($message, $contribution) {
    $this->setUpStatsOutFileProperties();

    // test output file does not currently exist
    $this->assertFileDoesNotExist($this->statsFilePath . $this->statsFilename . $this->statsFileExtension);

    $this->donationStatsCollector->recordDonationStats($message, $contribution);
    $this->donationStatsCollector->outputFileName = $this->statsFilename;
    $this->donationStatsCollector->outputFilePath = rtrim($this->statsFilePath, '/');
    $this->donationStatsCollector->export();

    //test output file has been created
    $this->assertFileExists($this->statsFilePath . $this->statsFilename . $this->statsFileExtension);

    // clean up
    $this->removeStatsOutFile();
  }

  /**
   * We also check for the mapping and presence of Prometheus labels {key=value} here.
   *
   * @dataProvider singleDummyDonationDataProvider
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function testExportedPrometheusOutputIsCorrect($message, $contribution) {
    $this->setUpStatsOutFileProperties();

    $this->donationStatsCollector->recordDonationStats($message, $contribution);
    $this->donationStatsCollector->outputFileName = $this->statsFilename;
    $this->donationStatsCollector->outputFilePath = rtrim($this->statsFilePath, '/');
    $this->donationStatsCollector->export();

    $expectedStatsOutput = [
      'test_namespace_average_transaction_age{gateway="ACME_PAYMENTS"}' => '3600',
      'test_namespace_average_transaction_age{gateway="all"}' => '3600',
      'test_namespace_count{gateway="ACME_PAYMENTS"}' => '1',
      'test_namespace_count{gateway="all"}' => '1',
    ];

    $statsWrittenAssocArray = $this->buildArrayFromPrometheusOutputFile(
      $this->statsFilePath . $this->statsFilename . $this->statsFileExtension
    );
    //compare written stats data with expected
    $this->assertEquals($expectedStatsOutput, $statsWrittenAssocArray);

    // clean up
    $this->removeStatsOutFile();
  }

  /**
   * 'receive_date' is stored as the relative difference to our actual-usage overwriting timestamp
   *
   * Data providers are called before all setUp methods which causes a lag between timestamps generated within
   * Data providers and actual tests. This causes problems when you are asserting time values based on now()
   * between test and provider (they don't match). Numerous workarounds exist, including this approach.
   *
   * @return array
   */
  public function singleDummyDonationDataProvider(): array {
    return [
      [
        ['gateway' => "ACME_PAYMENTS"],
        ['receive_date' => '-1 hour'],
      ],
    ];
  }

  /**
   * 'receive_date' is stored as the relative difference to our actual-usage overwriting timestamp
   * 'source_enqueued_time' is stored as the relative difference to our actual-usage overwriting timestamp
   *
   * Data providers are called before all setUp methods which causes a lag between timestamps generated within
   * Data providers and actual tests. This causes problems when you are asserting time values based on now()
   * between test and provider (they don't match). Numerous workarounds exist, including this approach.
   *
   * @return array
   */
  public function doubleDummyDonationDataProvider(): array {
    return [
      [
        [
          ['gateway' => "ACME_PAYMENTS", 'source_enqueued_time' => '-1 hour'],
          ['gateway' => "NICE_GATEWAY", 'source_enqueued_time' => '-1 hour'],
        ],
        [
          ['receive_date' => '-1 hour'],
          ['receive_date' => '-1 hour'],
        ],
      ],
    ];
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

  private function getTestDonationStatsCollectorInstance(): AbstractCollector {
    $this->resetDonationStatsCollector();
    $DonationStatsCollector = DonationStatsCollector::getInstance();
    $DonationStatsCollector->setNamespace(("test_namespace"));
    return $DonationStatsCollector;
  }

  /**
   * Test helper method to convert Prometheus outfile files to associative arrays
   *
   * @param $prometheusFileLocation
   *
   * @return array|string
   */
  private function buildArrayFromPrometheusOutputFile($prometheusFileLocation) {
    $statsWrittenAssocArray = [];
    if (file_exists($prometheusFileLocation)) {
      $statsFileFullPath = $prometheusFileLocation;
      // remove trailing \n
      $statsWritten = rtrim(file_get_contents($statsFileFullPath));
      $statsWrittenLinesArray = explode("\n", $statsWritten);
      foreach ($statsWrittenLinesArray as $statsLine) {
        [$name, $value] = explode(" ", $statsLine);
        if (array_key_exists($name, $statsWrittenAssocArray)) {
          if (is_array($statsWrittenAssocArray[$name])) {
            $statsWrittenAssocArray[$name][] = $value;
          }
          else {
            $statsWrittenAssocArray[$name] = [$statsWrittenAssocArray[$name], $value];
          }
        }
        else {
          $statsWrittenAssocArray[$name] = $value;
        }
      }
    }
    else {
      return "Prometheus file does not exist";
    }

    return $statsWrittenAssocArray;
  }

  /**
   * DonationStatsCollector singleton needs clearing before each test (and on first run due to DonationQueueTest).
   *
   * DonationQueueTest indirectly records stats when calling DonationQueueConsumer::processMessage() so we clear all
   * DonationStatsCollector before running DonationStatsCollectorTest to work form a known starting point.
   *
   * @see DonationQueueTest
   */
  private function resetDonationStatsCollector() {
    DonationStatsCollector::tearDown(TRUE);
  }

}
