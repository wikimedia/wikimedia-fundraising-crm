<?php

use queue2civicrm\contribution_tracking\ContributionTrackingQueueConsumer;
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

  public function setUp() {
    parent::setUp();
    $this->consumer = new ContributionTrackingQueueConsumer(
      'contribution-tracking'
    );
  }

  public function testCanProcessContributionTrackingMessage() {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);
    $this->compareMessageWithDb($message);
  }

  public function testCanProcessUpdateMessage() {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);

    $updateMessage = [
      'id' => $message['id'],
      'contribution_id' => '1234',
    ];
    $this->consumer->processMessage($updateMessage);

    $expectedData = $message + $updateMessage;
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

  /**
   * Update messages can only contain the id and contribution_id
   */
  public function testExceptionThrowOnInvalidUpdateMessage() {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);
    $this->expectException(WmfException::class);
    $message['utm_medium'] = 'UpdatedMedium';
    $this->consumer->processMessage($message);
  }

  public function testExceptionOnUpdateExistingContributionId() {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);

    $updateMessage = [
      'id' => $message['id'],
      'contribution_id' => '1234',
    ];
    $this->consumer->processMessage($updateMessage);

    $extraUpdateMessage = [
      'id' => $message['id'],
      'contribution_id' => '99999999',
    ];
    $this->expectException(WmfException::class);
    $this->consumer->processMessage($extraUpdateMessage);
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
}
