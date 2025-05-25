<?php

namespace Civi\WMFQueue;

use Civi\Api4\PaymentsFraud;
use Civi\Api4\PaymentsFraudBreakdown;
use Civi\WMFException\FredgeDataValidationException;
use Civi\WMFQueueMessage\FredgeMessage;

class AntifraudQueueConsumer extends QueueConsumer {

  /**
   * Validate and store messages from the payments-antifraud queue
   *
   * @param array $message
   *
   * @throws \Civi\WMFException\WMFException
   */
  function processMessage($message) {
    $id = "{$message['gateway']}-{$message['order_id']}";
    \Civi::log('wmf')->info(
      'fredge: Beginning processing of payments-antifraud message for {id}',
      ['id' => $id]
    );

    // handle the IP address conversion to binary so we can do database voodoo later.
    if (array_key_exists('user_ip', $message)) {
      // check for IPv6
      if (strpos(':', $message['user_ip']) !== FALSE) {
        /**
         * despite a load of documentation to the contrary, the following line
         * ***doesn't work at all***.
         * Which is okay for now: We force IPv4 on payments.
         *
         * @TODO eventually: Actually handle IPv6 here.
         */
        // $message['user_ip'] = inet_pton($message['user_ip']);

        \Civi::log('wmf')->warning(
          'fredge: Weird. Somehow an ipv6 address got through on payments. ' .
          'Caught in antifraud consumer. {id}',
          ['id' => $id]
        );
        $message['user_ip'] = 0;
      }
      else {
        $message['user_ip'] = ip2long($message['user_ip']);
      }
    }

    $this->insertAntifraudData($message, $id);
  }

  /**
   * take a message and insert or update rows in payments_fraud and
   * payments_fraud_breakdown. If there is not yet an antifraud row for this
   * ct_id and order_id, all fields in the table must be present in the
   * message.
   *
   * @param array $msg the message that you want to upsert.
   * @param string $logIdentifier Some small string for the log that will help
   *   id the message if something goes amiss and we have to log about it.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\FredgeDataValidationException
   */
  protected function insertAntifraudData(array $msg, string $logIdentifier) {
    if (empty($msg['contribution_tracking_id']) || empty($msg['order_id'])) {
      $error = "$logIdentifier: missing essential payments_fraud IDs. Dropping message on floor.";
      throw new FredgeDataValidationException($error);
    }

    $result = PaymentsFraud::get(FALSE)
      ->addWhere('contribution_tracking_id', '=', $msg['contribution_tracking_id'])
      ->addWhere('order_id', '=', $msg['order_id'])
      ->execute()->first();
    $message = new FredgeMessage($msg, 'PaymentsFraud', $logIdentifier);
    $data = $message->normalize();
    if ($result) {
      $data['id'] = $result['id'];
    }

    try {
      $paymentsFraud = PaymentsFraud::save(FALSE)
        ->setRecords([$data])
        ->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      if ($e->getErrorCode() === 'mandatory_missing') {
        $error = $logIdentifier . ": Expected field " . implode($e->getErrorData()['fields']) . " bound for table payments_fraud not present! Dropping message on floor.";
        throw new FredgeDataValidationException($error);
      }
      throw $e;
    }

    foreach ($msg['score_breakdown'] as $test => $score) {
      if ($score > 100000000) {
        $score = 100000000;
      }
      $breakdown = [
        'payments_fraud_id' => $paymentsFraud['id'],
        'filter_name' => $test,
        'risk_score' => $score,
      ];
      try {
        PaymentsFraudBreakdown::create(FALSE)
          ->setValues($breakdown)
          ->execute();
      }
      catch (\CRM_Core_Exception $e) {
        if ($e->getErrorCode() === 'mandatory_missing') {
          $error = $logIdentifier . ": Expected field " . implode($e->getErrorData()['fields']) . " bound for table payments_initial not present! Dropping message on floor.";
          throw new FredgeDataValidationException($error);
        }
        throw $e;
      }
    }
  }

}
