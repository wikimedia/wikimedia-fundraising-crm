<?php
namespace Civi\Queue;

use Civi\Api4\ContributionRecur;
use Civi\WMFHelpers\PaymentProcessor;
use CRM_Core_Payment_Scheduler;
use DateTimeZone;
use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\UtcDate;
use wmf_common\WmfQueueConsumer;
use WmfTransaction;

class UpiDonationsQueueConsumer extends WmfQueueConsumer {

  public function processMessage(array $message) {
    // Look up contribution_recur record based on token
    $contributionRecur = $this->getExistingContributionRecur($message);
    if ($contributionRecur) {
      // This is an installment on an existing subscription. Just set
      // a couple of IDs on the message to avoid needing to look them
      // up again in the donation queue consumer.
      $message['contribution_recur_id'] = $contributionRecur['id'];
      $message['contact_id'] = $contributionRecur['contact_id'];
    }
    else {
      // This is the first donation. Set up the subscription.
      $message['contribution_recur_id'] = $this->insertContributionRecur($message);
    }
    QueueWrapper::push('donations', $message);
  }

  /**
   * Finds a contribution_recur record using a particular payment_token value
   *
   * @param array $message with at least 'gateway' and 'recurring_payment_token' set
   * @return array|null The contribution_recur record if it exists, otherwise null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getExistingContributionRecur(array $message): ?array {
    // Look up civicrm_payment_token record
    $tokenRecord = wmf_civicrm_get_recurring_payment_token(
      $message['gateway'], $message['recurring_payment_token']
    );
    if (!$tokenRecord) {
      return NULL;
    }
    return ContributionRecur::get(FALSE)
      ->addWhere('payment_token_id', '=', $tokenRecord['id'])
      ->execute()
      ->first();
  }

  /**
   * Insert a new contribution_recur record, payment_token record, and if
   * necessary a new contact record.
   *
   * @param array $message
   * @return int the resulting contribution_recur record's ID
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function insertContributionRecur(array $message): int {
    // Cribbed and compacted from RecurringQueueConsumer::importSubscriptionSignup
    $normalized = wmf_civicrm_verify_message_and_stage($message);

    // Create (or update) the contact
    $contact = wmf_civicrm_message_contact_insert($normalized);

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
      'trxn_id' => WmfTransaction::from_message($normalized)->get_unique_id(),
    ];

    $params['next_sched_contribution_date'] = $this->getNextContributionDate($params, $normalized['date']);

    $newContributionRecur = civicrm_api3('ContributionRecur', 'create', $params);
    return $newContributionRecur['id'];
  }

  /**
   * Gets a date and time to send the next UPI prenotification
   *
   * @param array $params needs at least 'frequency_interval' and 'cycle_day'
   * @param int|null $previousContributionTimestamp timestamp of the previous contribution
   * @return string Date and time of next scheduled prenotification, formatted for Civi API calls.
   */
  protected function getNextContributionDate(array $params, ?int $previousContributionTimestamp = null): string {
    // Use the SmashPig extension's scheduler to get a standard next date
    // based on the params, then convert it to a DateTime object
    $standardDate = date_create(
      CRM_Core_Payment_Scheduler::getNextDateForMonth($params, $previousContributionTimestamp),
      new DateTimeZone('UTC')
    );
    // subtract 1 day for prenotification
    $difference = date_interval_create_from_date_string('1 day');
    return date_format(date_sub($standardDate, $difference), 'Y-m-d H:i:s');
  }
}