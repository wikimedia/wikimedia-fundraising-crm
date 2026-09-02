<?php

namespace Civi\WMFQueue;

use Civi\Api4\PaymentAttempt;
use Civi\Api4\PaymentAttemptModelScore;
use Civi\WMFException\FredgeDataValidationException;
use Civi\WMFException\WMFException;
use Civi\WMFQueueMessage\FredgeMessage;

class PaymentAttemptQueueConsumer extends QueueConsumer {

  /**
   * Fields that can be updated once the outcome of a payment attempt is known.
   */
  private const OUTCOME_FIELDS = [
    'fraud_flagged_by_processor',
    'auth_decline',
    'blocked_by_filter',
  ];

  /**
   * Validate and store messages from the payment_attempts_archive queue
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\FredgeDataValidationException
   * @throws \Civi\WMFException\WMFException
   */
  public function processMessage(array $message): void {
    if (empty($message['order_id'])) {
      throw new WMFException(WMFException::INVALID_MESSAGE, 'Missing order_id for payments attempt queue message');
    }
    $logId = "payment_attempt_{$message['order_id']}";
    \Civi::log('wmf')->info(
      "Beginning processing of civicrm_payment_attempt {type} message for {log_id}",
      [
        'log_id' => $logId,
        'type' => $message['type'] ?? 'unknown'
      ]
    );

    switch ($message['type']) {
      case 'outcome':
        $this->updatePaymentAttemptData($message, $logId);
        break;
      case 'scores':
        $this->storePaymentAttemptScores($message);
        break;
      default:
        $this->storePaymentAttemptData($message, $logId);
    }
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\FredgeDataValidationException
   */
  private function storePaymentAttemptData(array $message, string $logId): void {
    $existing = PaymentAttempt::get(FALSE)
      ->addWhere('order_id', '=', $message['order_id'])
      ->execute()->first();

    $paymentAttemptMessage = new FredgeMessage($message, 'PaymentAttempt', $logId);
    $data = $paymentAttemptMessage->normalize();
    if ($existing) {
      // Redelivered message for an order_id we've already recorded - update
      // the existing row rather than violating the unique index on order_id.
      $data['id'] = $existing['id'];
    }
    try {
      PaymentAttempt::save(FALSE)
        ->addRecord($data)
        ->execute();
    }
    catch (\CRM_Core_Exception $e) {
      if ($e->getErrorCode() === 'mandatory_missing') {
        $error = "$logId: Expected field " . implode($e->getErrorData()['fields']) . " bound for table civicrm_payment_attempt not present! Dropping message on floor.";
        throw new FredgeDataValidationException($error);
      }
      throw $e;
    }
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\FredgeDataValidationException
   */
  private function updatePaymentAttemptData(array $message, string $logId): void {
    $values = array_intersect_key($message, array_flip(self::OUTCOME_FIELDS));
    if (empty($values)) {
      $error = "$logId: Outcome message missing all of " . implode(', ', self::OUTCOME_FIELDS) . ". Dropping message on floor.";
      throw new FredgeDataValidationException($error);
    }
    $update = PaymentAttempt::update(FALSE)
      ->addWhere('order_id', '=', $message['order_id']);
    foreach ($values as $field => $value) {
      $update->addValue($field, $value);
    }
    $update->execute();
  }

  private function storePaymentAttemptScores(array $message) {
    $orderID = $message['order_id'];
    foreach ($message['scores'] as $score) {
      $data = [
        'order_id' => $orderID,
        'score' => $score['score'],
        'model_version' => $score['model_version'],
        'model_role' => $score['model_role'],
      ];

      $existing = PaymentAttemptModelScore::get(FALSE)
        ->addWhere('order_id', '=', $orderID)
        ->addWhere('model_version', '=', $score['model_version'])
        ->execute()->first();
      if ($existing) {
        $data['id'] = $existing['id'];
      }

      PaymentAttemptModelScore::save(FALSE)
        ->addRecord($data)->execute();
    }
  }

}
