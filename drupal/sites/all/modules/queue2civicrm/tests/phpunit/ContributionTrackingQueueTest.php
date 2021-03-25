<?php

use queue2civicrm\contribution_tracking\ContributionTrackingQueueConsumer;
use queue2civicrm\contribution_tracking\ContributionTrackingStatsCollector;
use SmashPig\Core\SequenceGenerators\Factory;

/**
 * @group Queue2Civicrm
 * @group ContributionTracking
 */
class ContributionTrackingQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var ContributionTrackingQueueConsumer
   */
  protected $consumer;

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new ContributionTrackingQueueConsumer(
      'contribution-tracking'
    );
  }

  public function testCanProcessContributionTrackingMessage(): void {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);
    $this->compareMessageWithDb($message);
  }

  public function testTruncatesOverlongFields(): void {
    $message = $this->getMessage();
    $message['utm_source'] = 'Blah_source-this-donor-came-in-from-a-search-' .
      'engine-and-they-were-looking-for-how-to-donate-to-wikipedia.' .
      'default~default~jimmywantscash.paypal';
    $this->consumer->processMessage($message);
    $truncatedMessage = $message;
    $truncatedMessage['utm_source'] = substr($message['utm_source'], 0, 128);
    $this->compareMessageWithDb($truncatedMessage);
  }

  public function testCanProcessUpdateMessage() {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);

    $updateMessage = [
      'id' => $message['id'],
      'contribution_id' => '1234',
      'form_amount' => 'GBP 10.00', // updated from 35
    ];
    $this->consumer->processMessage($updateMessage);

    $expectedData = $updateMessage + $message;
    $this->compareMessageWithDb($expectedData);
  }

  /**
   * $messages should ALWAYS contain the field 'id'
   */
  public function testExceptionThrowOnInvalidContributionTrackingMessage() {
    $message = $this->getMessage();
    unset($message['id']);
    $this->expectException(ContributionTrackingDataValidationException::class);
    $this->consumer->processMessage($message);
  }

  public function testExceptionNotThrownOnChangeOfContributionId() {
    $firstMessage = [
      'id' => '12345',
      'contribution_id' => '11111',
    ] + $this->getMessage();

    $this->consumer->processMessage($firstMessage);

    $secondMessage = [
      'id' => '12345',
      'contribution_id' => '22222',
    ] + $firstMessage;

    $this->consumer->processMessage($secondMessage);

    // the second message when processed previously resulted in an exception
    // being thrown. Now these types of errors fail silently and instead we
    // track them via statscollector/prometheus and logging.
    $expectedData = $firstMessage;
    $this->compareMessageWithDb($expectedData);
  }

  public function testChangeOfContributionIdErrorsAreCountedInStatsCollector() {
    $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    // should be a clean slate.
    $this->assertEquals(0, $ContributionTrackingStatsCollector->get('change_cid_errors'));

    $firstMessage = [
        'id' => '12345',
        'contribution_id' => '11111',
      ] + $this->getMessage();

    $this->consumer->processMessage($firstMessage);

    $secondMessage = [
        'id' => '12345',
        'contribution_id' => '22222',
      ] + $firstMessage;

    $this->consumer->processMessage($secondMessage);

    // we updated the contribution_id from '11111' to '22222' which shouldn't happen.
    // let's see if the error stat was incremented as expected
    $this->assertEquals(1, $ContributionTrackingStatsCollector->get('change_cid_errors'));
  }

  public function testChangeOfContributionIdErrorsAreWrittenToPrometheusOutputFile() {
    $firstMessage = [
        'id' => '12345',
        'contribution_id' => '11111',
      ] + $this->getMessage();

    $this->consumer->processMessage($firstMessage);

    $secondMessage = [
        'id' => '12345',
        'contribution_id' => '22222',
      ] + $firstMessage;

    $this->consumer->processMessage($secondMessage);

    // set the prometheus file output location that
    // ContributionTrackingStatsCollector will write to
    $tmpPrometheusFilePath = '/tmp/';
    variable_set(
      'metrics_reporting_prometheus_path',
      $tmpPrometheusFilePath
    );

    // ask the stats collector to export stats captured so far
    $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    $ContributionTrackingStatsCollector->export();

    $expectedStatsOutput = [
      'contribution_tracking_change_cid_errors' => 1,
      'contribution_tracking_count' => 2 // count of records processed
    ];

    $statsWrittenAssocArray = $this->buildArrayFromPrometheusOutputFile(
      $tmpPrometheusFilePath . 'contribution_tracking.prom'
    );

    //compare written stats data with expected
    $this->assertEquals($expectedStatsOutput, $statsWrittenAssocArray);

  }
  /**
   * build a queue message from our fixture file and drop in a random
   * contribution tracking id
   *
   * @return array
   */
  protected function getMessage() {
    $message = json_decode(
      file_get_contents(__DIR__ . '/../data/contribution-tracking.json'),
      TRUE
    );
    $generator = Factory::getSequenceGenerator('contribution-tracking');
    $ctId = $generator->getNext();
    $message['id'] = $ctId; // overwrite to make unique
    $this->ids['ContributionTracking'][] = $ctId;
    return $message;
  }

  /**
   * Check that the fields in the db line up with the original message fields
   *
   * @param $message
   */
  protected function compareMessageWithDb($message) {
    $dbEntries = $this->getDbEntries($message['id']);
    $this->assertEquals(1, count($dbEntries));
    $fields = [
      'id',
      'contribution_id',
      'note',
      'referrer',
      'form_amount',
      'payments_form',
      'anonymous',
      'utm_source',
      'utm_medium',
      'utm_campaign',
      'utm_key',
      'language',
      'country',
      'ts',
    ];
    foreach ($fields as $field) {
      if (array_key_exists($field, $message)) {
        $this->assertEquals($message[$field], $dbEntries[0][$field]);
      }
    }
  }

  /**
   * Fetch the contribution tracking row from the db
   *
   * @param $cId
   *
   * @return mixed
   */
  protected function getDbEntries($cId) {
    return Database::getConnection('default', 'default')
      ->select('contribution_tracking', 'ct')
      ->fields('ct', [
        'id',
        'contribution_id',
        'note',
        'referrer',
        'form_amount',
        'payments_form',
        'anonymous',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_key',
        'language',
        'country',
        'ts',
      ])
      ->condition('id', $cId)
      ->execute()
      ->fetchAll(PDO::FETCH_ASSOC);
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
      $statsWritten = rtrim(file_get_contents($statsFileFullPath)); // remove trailing \n
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

  public function tearDown(): void {
    parent::tearDown();
    // reset the ContributionTrackingStatsCollector state after each test
    ContributionTrackingStatsCollector::tearDown(TRUE);
  }


}
