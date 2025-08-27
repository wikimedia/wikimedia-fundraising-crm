<?php

namespace Civi\WMFQueue;

use Civi\Api4\ContributionTracking;
use Civi\Api4\Generic\Result;
use Civi\WMFException\ContributionTrackingDataValidationException;
use Civi\WMFStatistic\ContributionTrackingStatsCollector;

/**
 * @group queues
 */
class ContributionTrackingQueueTest extends BaseQueueTestCase {

  protected string $queueConsumer = 'ContributionTracking';

  protected string $queueName = 'contribution-tracking';

  /**
   * @throws \CRM_Core_Exception
   */
  public function testCanProcessContributionTrackingMessage(): void {
    $message = $this->getContributionTrackingMessage();
    $message['utm_source'] = 'B2223_1115_en6C_m_p1_lg_amt_cnt.no-LP.paypal';
    $message['payment_method'] = 'paypal';
    $this->processMessage($message);
    $this->compareMessageWithDb($message);
    $this->validateContributionTracking($message['id'], [
      'payment_method_id' => \CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'payment_method_id', 'paypal'),
      'banner_size_id' => \CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'banner_size_id', 'banner_size_large'),
      'device_type_id' => \CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'device_type_id', 'device_type_mobile'),
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

  /**
   * @throws \CRM_Core_Exception
   */
  public function testTruncatesOverlongFields(): void {
    $message = $this->getContributionTrackingMessage();
    $message['utm_source'] = 'Blah_source-this-donor-came-in-from-a-search-' .
      'engine-and-they-were-looking-for-how-to-donate-to-wikipedia.' .
      'default~default~jimm...';
    $this->processMessage($message);
    $truncatedMessage = $message;
    $truncatedMessage['utm_source'] = substr($message['utm_source'], 0, 128);
    $this->compareMessageWithDb($truncatedMessage);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testCanProcessUpdateMessage(): void {
    $message = $this->getContributionTrackingMessage();
    $this->processMessage($message);
    $contributionID = $this->createContribution()['id'];
    $updateMessage = [
      'id' => $message['id'],
      'contribution_id' => $contributionID,
      // updated from 35
      'form_amount' => 'GBP 10.00',
      'amount' => '10.00',
    ];
    $this->processMessage($updateMessage);
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
   *
   * @throws \CRM_Core_Exception
   */
  public function testExceptionThrowOnInvalidContributionTrackingMessage(): void {
    $message = $this->getContributionTrackingMessage();
    unset($message['id']);
    $this->expectException(ContributionTrackingDataValidationException::class);
    $this->processMessage($message);
  }

  /**
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function testExceptionNotThrownOnChangeOfContributionID(): void {
    $contributionID1 = $this->createContribution()['id'];
    $contributionID2 = $this->createContribution()['id'];
    $this->ids['ContributionTracking'][] = 12345;
    $firstMessage = [
      'id' => '12345',
      'contribution_id' => $contributionID1,
    ] + $this->getContributionTrackingMessage();

    $this->processMessage($firstMessage);

    $secondMessage = [
      'id' => '12345',
      'contribution_id' => $contributionID2,
    ] + $firstMessage;

    $this->processMessage($secondMessage);

    // the second message when processed previously resulted in an exception
    // being thrown. Now these types of errors fail silently and instead we
    // track them via statsCollector/prometheus and logging.
    $expectedData = $firstMessage;
    $this->compareMessageWithDb($expectedData);
  }

  /**
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function testChangeOfContributionIdErrorsAreCountedInStatsCollector(): void {
    $ContributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    // should be a clean slate.
    $this->assertEquals(0, $ContributionTrackingStatsCollector->get('change_cid_errors'));

    $firstMessage = [
      'id' => '12345',
      'contribution_id' => $this->createContribution()['id'],
    ] + $this->getContributionTrackingMessage();

    $this->processMessage($firstMessage);

    $secondMessage = [
      'id' => '12345',
      'contribution_id' => $this->createContribution()['id'],
    ] + $firstMessage;

    $this->processMessage($secondMessage);

    // we updated the contribution_id from '11111' to '22222' which shouldn't happen.
    // let's see if the error stat was incremented as expected
    $this->assertEquals(1, $ContributionTrackingStatsCollector->get('change_cid_errors'));
  }

  /**
   *
   */
  public function testChangeOfContributionIdErrorsAreWrittenToPrometheusOutputFile(): void {
    $firstMessage = [
      'id' => '12345',
      'contribution_id' => $this->createContribution()['id'],
    ] + $this->getContributionTrackingMessage();

    $this->processMessage($firstMessage);

    $secondMessage = [
      'id' => '12345',
      'contribution_id' => $this->createContribution()['id'],
    ] + $firstMessage;

    $this->processMessage($secondMessage);

    // set the prometheus file output location that
    // ContributionTrackingStatsCollector will write to
    $tmpPrometheusFilePath = '/tmp/';
    \Civi::settings()->set('metrics_reporting_prometheus_path', $tmpPrometheusFilePath);

    // ask the stats collector to export stats captured so far
    /* @var ContributionTrackingStatsCollector $contributionTrackingStatsCollector */
    $contributionTrackingStatsCollector = ContributionTrackingStatsCollector::getInstance();
    $contributionTrackingStatsCollector->export();

    $expectedStatsOutput = [
      'contribution_tracking_change_cid_errors' => 1,
      // count of records processed
      'contribution_tracking_count' => 2,
    ];

    $statsWrittenAssocArray = $this->buildArrayFromPrometheusOutputFile(
      $tmpPrometheusFilePath . 'contribution_tracking.prom'
    );

    //compare written stats data with expected
    $this->assertEquals($expectedStatsOutput, $statsWrittenAssocArray);
  }

  /**
   * Check that the fields in the db line up with the original message fields
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   */
  protected function compareMessageWithDb(array $message): void {
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
    $contributionTrackingRecord = $this->getContributionTrackingRecords($message['id'])
      ->single();
    foreach ($fields as $field) {
      if (array_key_exists($field, $message)) {
        $this->assertEquals($message[$field], $contributionTrackingRecord[$field]);
      }
      $this->assertEquals(strtotime($message['ts']), strtotime($contributionTrackingRecord['tracking_date']));
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
  protected function getContributionTrackingRecords(int $contributionTrackingID): Result {
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
  private function validateContributionTracking(int $id, array $overrides = []): void {
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
      'referrer' => 'payments.wiki.local.test.net/wiki/Main_Page',
      'utm_medium' => 'civicrm',
      'utm_campaign' => 'test_campaign',
      'utm_key' => 'vw_1280~vh_577~otherAmt_1~ptf_1~time_37',
      'gateway' => 'ingenico',
      'appeal' => 'JimmyQuote',
      'payments_form_variant' => NULL,
      'payment_method_id' => \CRM_Core_PseudoConstant::getKey('CRM_Wmf_BAO_ContributionTracking', 'payment_method_id', 'cc'),
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

}
