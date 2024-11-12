<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\ContributionRecur;
use Civi\Api4\WMFContact;
use Civi\WMFHelper\PaymentProcessor;
use Civi\WMFQueueMessage\RecurDonationMessage;
use CRM_Core_Payment_Scheduler;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use Civi\WMFTransaction;

class UpiDonationsQueueConsumer extends QueueConsumer {

  public function processMessage(array $message) {
    $messageObject = new RecurDonationMessage($message);
    // Look up contribution_recur record
    $contributionRecur = $this->getExistingContributionRecur($message, $messageObject);

    if ($this->isRejectionMessage($message)) {
      // The gateway has sent us a 'Wallet Disabled' IPN, so close down the subscription.
      if ($contributionRecur) {
        $this->cancelRecurringContribution($contributionRecur['id']);
      }
      else {
        Civi::log('wmf')->info(
          "Unable to cancel subscription due to no active recurring record. "
          . $message['order_id']
        );
      }
      return;
    }

    if ($contributionRecur) {
      // This is an installment on an existing subscription. Just set
      // a couple of IDs on the message to avoid needing to look them
      // up again in the donation queue consumer.
      $message['contribution_recur_id'] = $contributionRecur['id'];
      $message['contact_id'] = $contributionRecur['contact_id'];
    }
    else {
      // This is the first donation. Set up the subscription.
      $message['contribution_recur_id'] = $this->insertContributionRecur($messageObject);
    }

    QueueWrapper::push('donations', $message);

    // Refund donation received after cancelled recurring
    if (!empty($contributionRecur) && $contributionRecur['contribution_status_id:name'] === 'Cancelled'
      && $message['gateway_status'] === 'PAID') {
      Civi::log('wmf')->info(
        "Refunding UPI payment from cancelled recurring with order ID: "
        . $message['order_id']
      );
      $refundMessage = array_merge([
        "amount" => $message['gross'],
        "payment_processor_id" => PaymentProcessor::getPaymentProcessorID($message['gateway']),
      ], $message);
      $refundResponse = $this->refundPayment($refundMessage);
      if ($refundResponse['payment_status'] === 'Refunded') {
        // Mark donation as refund
        $refundMessage = [
          "gateway_parent_id" => $message['gateway_txn_id'],
          "gross_currency" => $message['currency'],
          "gross" => $message['gross'],
          "date" => $message['date'],
          "gateway" => $message['gateway'],
          'gateway_refund_id' => $refundResponse['processor_id'],
          "type" => 'refund',
        ];
        QueueWrapper::push('refund', $refundMessage);
      }
    }
  }

  protected function refundPayment($refundMessage): array {
    $result = civicrm_api3('PaymentProcessor', 'refund', $refundMessage);
    return $result['values'][0];
  }

  /**
   * Finds an active contribution_recur record using a particular payment_token value
   *
   * @param array $message with at least 'gateway' and 'recurring_payment_token' set
   * @param \Civi\WMFQueueMessage\RecurDonationMessage $messageObject
   *
   * @return array|null The contribution_recur record if it exists, otherwise null
   * @throws \CRM_Core_Exception
   */
  protected function getExistingContributionRecur(array $message, RecurDonationMessage $messageObject): ?array {
    // Look up civicrm_payment_token record
    $paymentTokenID = $messageObject->getPaymentTokenID();
    if (!$paymentTokenID) {
      return NULL;
    }
    // Look up contribution recur row using token and recur amount
    $recurs = ContributionRecur::get(FALSE)
      ->addSelect('contribution_status_id:name', '*')
      ->addWhere('payment_token_id', '=', $paymentTokenID)
      ->addWhere('amount', '=', $message['gross'])
      ->execute();

    // For cases with multiple recur records for the payment_token and amount
    // check for the row with the closest schedule date and is "In Progress" status.
    if (count($recurs) > 1) {
      foreach ($recurs as $recur_record) {
        if ($recur_record['contribution_status_id:name'] === 'In Progress') {
          return $recur_record;
        }
      }
    }

    return $recurs[0] ?? NULL;
  }

  /**
   * Insert a new contribution_recur record, payment_token record, and if
   * necessary a new contact record.
   *
   * @param RecurDonationMessage $recurMessage
   *
   * @return int the resulting contribution_recur record's ID
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function insertContributionRecur($recurMessage): int {

    $normalized = $recurMessage->normalize();
    $normalized = $this->addContributionTrackingIfMissing($normalized);
    $recurMessage->validate();

    // Create (or update) the contact
    $contact = WMFContact::save(FALSE)
      ->setMessage($normalized)
      ->execute()->first();

    // Create a token
    $paymentToken = wmf_civicrm_recur_payment_token_create(
      $contact['id'], $normalized['gateway'], $normalized['recurring_payment_token'], $normalized['user_ip']
    );

    // Create the recurring record
    $params = [
      'amount' => $normalized['original_gross'],
      'contact_id' => $contact['id'],
      'create_date' => UtcDate::getUtcDatabaseString($normalized['date']),
      'currency' => $normalized['original_currency'],
      'cycle_day' => date('j', $normalized['date']),
      'financial_type_id' => 'Cash',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      // Set installments to 0 - they should all be open ended
      'installments' => 0,
      'payment_processor_id' => PaymentProcessor::getPaymentProcessorID($normalized['gateway']),
      'payment_token_id' => $paymentToken['id'],
      'processor_id' => $normalized['gateway_txn_id'],
      'start_date' => UtcDate::getUtcDatabaseString($normalized['date']),
      'trxn_id' => WMFTransaction::from_message($normalized)->get_unique_id(),
    ];

    $params['next_sched_contribution_date'] = CRM_Core_Payment_Scheduler::getNextContributionDate(
      $params, $normalized['date']
    );

    $newContributionRecur = civicrm_api3('ContributionRecur', 'create', $params);
    return $newContributionRecur['id'];
  }

  /**
   * @param $id
   *
   * @return array|int
   * @throws \CRM_Core_Exception
   */
  protected function cancelRecurringContribution($id) {
    $params['id'] = $id;
    $params['contribution_status_id'] = 'Cancelled';
    $params['cancel_date'] = UtcDate::getUtcDatabaseString();
    $params['cancel_reason'] = 'Subscription cancelled at gateway';
    return civicrm_api3('ContributionRecur', 'create', $params);
  }

  /**
   * @param array $message
   *
   * @return bool
   */
  protected function isRejectionMessage(array $message): bool {
    return $message['gateway_status'] === 'REJECTED' && $message['gateway_status_code'] === '322';
  }

}
