<?php

namespace Civi\WMFQueue;

use Civi\Api4\PaymentAttempt;
use Civi\WMFException\FredgeDataValidationException;
use Civi\WMFException\WMFException;

/**
 * @group queues
 */
class PaymentAttemptQueueTest extends BaseQueueTestCase {

  protected string $queueName = 'payment-attempts';

  protected string $queueConsumer = 'PaymentAttempt';

  /**
   * @throws \CRM_Core_Exception
   */
  public function tearDown(): void {
    PaymentAttempt::delete(FALSE)->addWhere('order_id', '=', '28713751.0')->execute();
    parent::tearDown();
  }

  /**
   * A message with no 'type' key is treated as a new attempt and stored.
   *
   * @throws \CRM_Core_Exception
   */
  public function testValidMessageIsStored(): void {
    $message = $this->getPaymentAttemptMessage();
    $this->processMessage($message);
    $this->comparePaymentAttemptMessageWithDb($message);
  }

  /**
   * We use the order id as an index to connect records as such it is mandatory to have in the message.
   */
  public function testMissingOrderIdIsRejected(): void {
    $this->expectException(WMFException::class);
    $this->expectExceptionMessage('Missing order_id for payments attempt queue message');
    $message = $this->getPaymentAttemptMessage();
    unset($message['order_id']);
    $this->processMessageWithoutQueuing($message);
  }

  /**
   * Fields not present on the PaymentAttempt entity should be silently
   * dropped rather than causing a save failure.
   *
   * @throws \CRM_Core_Exception
   */
  public function testUnrecognisedFieldsAreIgnored(): void {
    $message = $this->getPaymentAttemptMessage();
    $message['php-message-class'] = 'Random\\Class';
    $this->processMessage($message);
    $this->comparePaymentAttemptMessageWithDb($message);
  }

  /**
   * A redelivered attempt message for the same order_id should update the
   * existing row rather than falling foul of the unique index on order_id.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRedeliveredAttemptMessageUpdatesExistingRow(): void {
    $message = $this->getPaymentAttemptMessage();
    $this->processMessage($message);
    $original = $this->comparePaymentAttemptMessageWithDb($message);

    $message['payment_submethod'] = 'mc';
    $this->processMessage($message);
    $updated = $this->comparePaymentAttemptMessageWithDb($message);

    $this->assertEquals($original['id'], $updated['id'], 'Should update the existing row, not insert a new one');
  }

  /**
   * An outcome message should only touch the three outcome fields, leaving
   * the rest of the previously-stored attempt data untouched.
   *
   * @throws \CRM_Core_Exception
   */
  public function testOutcomeMessageUpdatesOnlyOutcomeFields(): void {
    $message = $this->getPaymentAttemptMessage();
    $this->processMessage($message);

    $outcomeMessage = [
      'type' => 'outcome',
      'order_id' => $message['order_id'],
      'fraud_flagged_by_processor' => TRUE,
      'auth_decline' => FALSE,
      'blocked_by_filter' => TRUE,
    ];
    $this->processMessage($outcomeMessage);

    $paymentAttempt = PaymentAttempt::get(FALSE)
      ->addWhere('order_id', '=', $message['order_id'])
      ->execute()->single();
    $this->assertEquals(1, $paymentAttempt['fraud_flagged_by_processor']);
    $this->assertEquals(0, $paymentAttempt['auth_decline']);
    $this->assertEquals(1, $paymentAttempt['blocked_by_filter']);
    // Confirm the original attempt data survived the update.
    $this->assertEquals($message['gateway'], $paymentAttempt['gateway']);
    $this->assertEquals($message['payment_method'], $paymentAttempt['payment_method']);
  }

  /**
   * An outcome message that carries none of the known outcome fields has
   * nothing useful to do and should be dropped rather than silently no-op'd.
   */
  public function testOutcomeMessageWithNoOutcomeFieldsIsRejected(): void {
    $this->expectException(FredgeDataValidationException::class);
    $message = $this->getPaymentAttemptMessage();
    $this->processMessageWithoutQueuing($message);

    $this->processMessageWithoutQueuing([
      'type' => 'outcome',
      'order_id' => $message['order_id'],
    ]);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function comparePaymentAttemptMessageWithDb(array $message): array {
    $paymentAttempt = PaymentAttempt::get(FALSE)
      ->addWhere('order_id', '=', $message['order_id'])
      ->execute()->single();
    $this->assertEquals($message['contribution_tracking_id'], $paymentAttempt['contribution_tracking_id']);
    $this->assertEquals($message['gateway'], $paymentAttempt['gateway']);
    $this->assertEquals($message['payment_method'], $paymentAttempt['payment_method']);
    $this->assertEquals($message['payment_submethod'], $paymentAttempt['payment_submethod']);
    $this->assertEquals($message['amount_in_minor_units'], $paymentAttempt['amount_in_minor_units']);
    $this->assertEquals($message['currency'], $paymentAttempt['currency']);
    $this->assertEquals($message['country'], $paymentAttempt['country']);
    $this->assertEquals($message['first_name'], $paymentAttempt['first_name']);
    $this->assertEquals($message['last_name'], $paymentAttempt['last_name']);
    $this->assertEquals($message['user_ip'], $paymentAttempt['user_ip']);
    return $paymentAttempt;
  }

  /**
   * @return array
   */
  protected function getPaymentAttemptMessage(): array {
    $message = $this->loadMessage('payment-attempt');
    $message['contribution_tracking_id'] = 28713751;
    $message['order_id'] = '28713751.0';
    return $message;
  }

}
