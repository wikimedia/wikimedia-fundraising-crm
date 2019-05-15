<?php

use queue2civicrm\contribution_tracking\ContributionTrackingQueueConsumer;
use SmashPig\Core\SequenceGenerators\Factory;

/**
 * @group Queue2Civicrm
 */
class ContributionTrackingQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var ContributionTrackingQueueConsumer
   */
  protected $consumer;

  public function setUp() {
    parent::setUp();
    $this->consumer = new ContributionTrackingQueueConsumer(
      'contribution-tracking'
    );
  }

  public function testCanProcessContributionTrackingMessage() {
    $message = $this->getMessage('contribution-tracking.json');
    $this->consumer->processMessage($message);
    $this->compareMessageWithDb($message);
  }

  public function testCanProcessMessageWithPreExistingContributionTrackingId() {
    $messageOne = $this->getMessage('contribution-tracking.json');
    $this->ctId = $this->consumer->processMessage($messageOne);

    $messageOneUpdated = [
        'note' => "this is an update to an existing entry",
      ] + $messageOne;

    $this->consumer->processMessage($messageOneUpdated);
    $this->compareMessageWithDb($messageOneUpdated);
  }

  /**
   * $messages should ALWAYS contain the field 'id'
   *
   * @expectedException ContributionTrackingDataValidationException
   */
  public function testExceptionThrowOnInvalidContributionTrackingMessage() {
    $message = $this->getMessage('contribution-tracking.json');
    unset($message['id']);
    $this->consumer->processMessage($message);
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
      $this->assertEquals($message[$field], $dbEntries[0][$field]);
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
}
