<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use queue2civicrm\contribution_tracking\ContributionTrackingQueueConsumer;
use queue2civicrm\contribution_tracking\ContributionTrackingStatsCollector;
use SmashPig\Core\SequenceGenerators\Factory;
use Civi\WMFException\ContributionTrackingDataValidationException;

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
    $message['utm_source'] = 'B2223_1115_en6C_m_p1_lg_amt_cnt.no-LP.paypal';
    $message['payment_method'] = 'paypal';
    $this->consumer->processMessage($message);
    $this->compareMessageWithDb($message);
    $this->validateContributionTracking($message['id'], [
      'payment_method_id' => CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'payment_method_id', 'paypal'),
      'banner_size_id' => CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'banner_size_id', 'banner_size_large'),
      'device_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'device_type_id', 'device_type_mobile'),
      'utm_source' => 'B2223_1115_en6C_m_p1_lg_amt_cnt.no-LP.paypal',
      'banner_variant' => 1115,
      'mailing_identifier' => NULL,
      'banner' => 'B2223_1115_en6C_m_p1_lg_amt_cnt',
      'landing_page' => 'no-LP',
      'is_test_variant' => FALSE,
      'os' => 'Solaris',
      'os_version' => '11',
      'browser' => 'Mosaic',
      'browser_version' => '4',
    ]);
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

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \Civi\WMFException\ContributionTrackingDataValidationException
   */
  public function testCanProcessUpdateMessage(): void {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);
    $contributionID = $this->createContribution();
    $updateMessage = [
      'id' => $message['id'],
      'contribution_id' => $contributionID,
      'form_amount' => 'GBP 10.00', // updated from 35
      'amount' => '10.00',
    ];
    $this->consumer->processMessage($updateMessage);
    $this->validateContributionTracking((int) $message['id'], [
      'contribution_id' => $contributionID,
      'amount' => 10,
      'currency' => 'GBP',
    ]);
    $expectedData = $updateMessage + $message;
    $this->compareMessageWithDb($expectedData);
  }

  /**
   * $messages should ALWAYS contain the field 'id'
   */
  public function testExceptionThrowOnInvalidContributionTrackingMessage(): void {
    $message = $this->getMessage();
    unset($message['id']);
    $this->expectException(ContributionTrackingDataValidationException::class);
    $this->consumer->processMessage($message);
  }

  public function testExceptionNotThrownOnChangeOfContributionID(): void {
    $contributionID1 = $this->createContribution();
    $contributionID2 = $this->createContribution();

    $firstMessage = [
        'id' => '12345',
        'contribution_id' => $contributionID1,
      ] + $this->getMessage();

    $this->consumer->processMessage($firstMessage);

    $secondMessage = [
        'id' => '12345',
        'contribution_id' => $contributionID2,
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
        'contribution_id' => $this->createContribution(),
      ] + $this->getMessage();

    $this->consumer->processMessage($firstMessage);

    $secondMessage = [
        'id' => '12345',
        'contribution_id' => $this->createContribution(),
      ] + $firstMessage;

    $this->consumer->processMessage($secondMessage);

    // we updated the contribution_id from '11111' to '22222' which shouldn't happen.
    // let's see if the error stat was incremented as expected
    $this->assertEquals(1, $ContributionTrackingStatsCollector->get('change_cid_errors'));
  }

  public function testChangeOfContributionIdErrorsAreWrittenToPrometheusOutputFile() {
    $firstMessage = [
        'id' => '12345',
        'contribution_id' => $this->createContribution(),
      ] + $this->getMessage();

    $this->consumer->processMessage($firstMessage);

    $secondMessage = [
        'id' => '12345',
        'contribution_id' => $this->createContribution(),
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
      'contribution_tracking_count' => 2, // count of records processed
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
      'referrer',
      'amount',
      'gateway',
      'appeal',
      'utm_source',
      'utm_medium',
      'utm_campaign',
      'utm_key',
      'language',
      'country',
    ];
    foreach ($fields as $field) {
      if (array_key_exists($field, $message)) {
        $this->assertEquals($message[$field], $dbEntries[0][$field]);
      }
      $this->assertEquals(strtotime($message['ts']), strtotime($dbEntries[0]['tracking_date']));
    }
  }

  /**
   * Fetch the contribution tracking row from the db
   *
   * @param int $contributionTrackingID
   *
   * @return \Civi\Api4\Generic\Result
   * @throws \CRM_Core_Exception
   */
  protected function getDbEntries(int $contributionTrackingID): \Civi\Api4\Generic\Result {
    return ContributionTracking::get(FALSE)->addWhere('id', '=', $contributionTrackingID)->execute();
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

  /**
   * Validate the tracking record created in civicrm_contribution_tracking.
   *
   * @param int $id
   * @param array $overrides
   *
   * @noinspection PhpDocMissingThrowsInspection
   * @noinspection PhpUnhandledExceptionInspection
   */
  private function validateContributionTracking(int $id, $overrides = []): void {
    $expected = $this->getExpectedTracking($id, $overrides);
    $tracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();
    foreach ($expected as $key => $value) {
      $this->assertEquals($value, $tracking[$key], 'mismatch on ' . $key);
    }
  }

  /**
   * Get the expected tracking result.
   *
   * @param int $id
   * @param array $overrides
   *
   * @return array
   */
  private function getExpectedTracking(int $id, array $overrides = []): array {
    return array_merge([
      'id' => $id,
      'contribution_id' => NULL,
      'amount' => 35,
      'currency' => 'GBP',
      'is_recurring' => FALSE,
      'referrer' => 'payments.wiki.local.wmftest.net/wiki/Main_Page',
      'utm_medium' => 'civicrm',
      'utm_campaign' => 'test_campaign',
      'utm_key' => 'vw_1280~vh_577~otherAmt_1~ptf_1~time_37',
      'gateway' => 'ingenico',
      'appeal' => 'JimmyQuote',
      'payments_form_variant' => NULL,
      'payment_method_id' => CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'payment_method_id', 'cc'),
      'payment_submethod_id' => NULL,
      'language' => 'en',
      'country' => 'UK',
      'tracking_date' => '2019-03-22 11:45:58',
      'device_type_id' => NULL,
      'is_pay_fee' => TRUE,
      'banner_variant' => '',
      'mailing_identifier' => 'sp72294511',
      'utm_source' => 'sp72294511.default~default~JimmyQuote~default~control.cc',
    ], $overrides);
  }

  /**
   * @return mixed
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function createContribution() {
    $contributionID2 = Contribution::create(FALSE)->setValues([
      'contact_id' => $this->createIndividual(),
      'total_amount' => 5,
      'financial_type_id:name' => 'Engage',
    ])->execute()->first()['id'];
    return $contributionID2;
  }

}
