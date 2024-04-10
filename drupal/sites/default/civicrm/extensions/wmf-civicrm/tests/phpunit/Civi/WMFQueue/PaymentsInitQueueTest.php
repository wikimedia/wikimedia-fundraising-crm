<?php

namespace Civi\WMFQueue;

use Civi\Api4\PaymentsInitial;
use Civi\WMFException\FredgeDataValidationException;

/**
 * @group queues
 */
class PaymentsInitQueueTest extends BaseQueueTestCase {

  protected string $queueName = 'payments-init';

  protected string $queueConsumer = 'PaymentsInit';

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    PaymentsInitial::delete(FALSE)->addWhere('server', '=', 'test-payments1002')->execute();
    parent::tearDown();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testValidMessage(): void {
    $message = $this->getMessage();
    $this->processMessage($message);
    $this->comparePaymentsInitMessageWithDb($message);
  }

  /**
   * The first message for a ct_id / order_id pair needs to be complete
   */
  public function testIncompleteMessage(): void {
    $this->expectException(FredgeDataValidationException::class);
    $message = $this->getMessage();
    unset($message['server']);
    $this->processMessageWithoutQueuing($message);
  }

  /**
   * After one complete message has been inserted, a second message
   * with the same ct_id / order_id can update only selected fields
   *
   * @throws \CRM_Core_Exception
   */
  public function testUpdatedMessage(): void {
    $message1 = $this->getMessage();
    $message2 = $this->getMessage();
    $message2['contribution_tracking_id'] = $message1['contribution_tracking_id'];
    $message2['order_id'] = $message1['order_id'];

    $message1['payments_final_status'] = 'pending';
    $message2['payments_final_status'] = 'pending';
    unset($message2['payment_method']);

    $this->processMessage($message1);
    $this->comparePaymentsInitMessageWithDb($message1);

    $this->processMessage($message2);
    $updated = array_merge(
      $message1, $message2
    );
    $this->comparePaymentsInitMessageWithDb($updated);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function comparePaymentsInitMessageWithDb($message): void {
    $paymentsInitial = PaymentsInitial::get(FALSE)
      ->addWhere('contribution_tracking_id', '=', $message['contribution_tracking_id'])
      ->addWhere('order_id', '=', $message['order_id'])
      ->execute()->single();

    $this->assertEquals($message['gateway'], $paymentsInitial['gateway']);
    $this->assertEquals($message['gateway_txn_id'], $paymentsInitial['gateway_txn_id']);
    $this->assertEquals($message['validation_action'], $paymentsInitial['validation_action']);
    $this->assertEquals($message['payments_final_status'], $paymentsInitial['payments_final_status']);
    $this->assertEquals($message['payment_method'], $paymentsInitial['payment_method']);
    $this->assertEquals($message['payment_submethod'], $paymentsInitial['payment_submethod']);
    $this->assertEquals($message['country'], $paymentsInitial['country']);
    $this->assertEquals($message['amount'], $paymentsInitial['amount']);
    $this->assertEquals($message['server'], $paymentsInitial['server']);

    $this->assertEquals($message['currency'], $paymentsInitial['currency_code']);
    $this->assertEquals($message['date'], strtotime($paymentsInitial['date']));
  }

  /**
   * @return array
   */
  protected function getMessage(): array {
    $message = $this->loadMessage('payments-init');
    $message['contribution_tracking_id'] = 123456;
    $message['order_id'] = 123456 . '.0';
    $message['amount'] = (float) $message['amount'];
    return $message;
  }

}
