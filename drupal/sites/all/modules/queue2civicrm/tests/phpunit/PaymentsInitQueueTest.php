<?php

use Civi\Api4\PaymentsInitial;
use queue2civicrm\fredge\PaymentsInitQueueConsumer;
use Civi\WMFException\FredgeDataValidationException;

/**
 * @group Queue2Civicrm
 */
class PaymentsInitQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var PaymentsInitQueueConsumer
   */
  protected $consumer;

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new PaymentsInitQueueConsumer(
      'payments-init'
    );
  }

  public function tearDown(): void {
    PaymentsInitial::delete(FALSE)->addWhere('server', '=', 'testpayments1002')->execute();
    parent::tearDown();
  }

  public function testValidMessage() {
    $message = $this->getMessage();
    $this->consumer->processMessage($message);

    $this->compareMessageWithDb($message);
  }

  /**
   * The first message for a ct_id / order_id pair needs to be complete
   */
  public function testIncompleteMessage(): void {
    $this->expectException(FredgeDataValidationException::class);
    $message = $this->getMessage();
    unset($message['server']);
    $this->consumer->processMessage($message);
  }

  /**
   * After one complete message has been inserted, a second message
   * with the same ct_id / order_id can update only selected fields
   */
  public function testUpdatedMessage(): void {
    $message1 = $this->getMessage();
    $message2 = $this->getMessage();
    $message2['contribution_tracking_id'] = $message1['contribution_tracking_id'];
    $message2['order_id'] = $message1['order_id'];

    $message1['payments_final_status'] = 'pending';
    $message2['payments_final_status'] = 'pending';
    unset($message2['payment_method']);

    $this->consumer->processMessage($message1);
    $this->compareMessageWithDb($message1);

    $this->consumer->processMessage($message2);
    $updated = array_merge(
      $message1, $message2
    );
    $this->compareMessageWithDb($updated);
  }

  protected function compareMessageWithDb($message) {
    $dbEntries = $this->getDbEntries(
      $message['contribution_tracking_id'], $message['order_id']
    );
    $this->assertCount(1, $dbEntries);
    $fields = [
      'gateway',
      'gateway_txn_id',
      'validation_action',
      'payments_final_status',
      'payment_method',
      'payment_submethod',
      'country',
      'amount',
      'server',
    ];
    foreach ($fields as $field) {
      $this->assertEquals($message[$field], $dbEntries[0][$field], $field);
    }
    $this->assertEquals($message['currency'], $dbEntries[0]['currency_code']);
    $this->assertEquals(
      $message['date'], $this->wmf_common_date_civicrm_to_unix($dbEntries[0]['date'])
    );
  }

  protected function getDbEntries($ctId, $orderId) {
    return Database::getConnection('default', 'fredge')
      ->select('payments_initial', 'f')
      ->fields('f', [
        'contribution_tracking_id',
        'gateway',
        'order_id',
        'gateway_txn_id',
        'validation_action',
        'payments_final_status',
        'payment_method',
        'payment_submethod',
        'country',
        'amount',
        'currency_code',
        'server',
        'date',
      ])
      ->condition('contribution_tracking_id', $ctId)
      ->condition('order_id', $orderId)
      ->execute()
      ->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
   * @return array
   */
  protected function getMessage() {
    $message = json_decode(
      file_get_contents(__DIR__ . '/../data/payments-init.json'),
      TRUE
    );
    $ctId = mt_rand();
    $oId = $ctId . '.0';
    $message['contribution_tracking_id'] = $ctId;
    $message['order_id'] = $oId;
    $message['amount'] = (float) $message['amount'];
    return $message;
  }

}
