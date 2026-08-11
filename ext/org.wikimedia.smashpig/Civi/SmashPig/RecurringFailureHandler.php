<?php

namespace Civi\SmashPig;

use Civi;
use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Helper\FailureEmail;
use CRM_Core_Payment_Scheduler;
use DateInterval;
use DateTimeImmutable;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentProviders\Responses\CreatePaymentWithProcessorRetryResponse;
use SmashPig\PaymentProviders\Responses\PaymentProviderExtendedResponse;
use UnexpectedValueException;

class RecurringFailureHandler {

  /**
   * Days to retry failed donations.
   * @var array
   */
  protected array $retryCadence;

  /**
   * Calculated from $retryCadence
   * @var int
   */
  protected int $maxFailures;

  public function __construct( array $retryCadence ) {
    $this->retryCadence = array_map('intval', $retryCadence);
    $this->maxFailures = count($retryCadence) + 1;
  }

  /**
   * @param array $recurringPayment
   * @param string $errorMessage
   * @param bool $canRetry
   * @param PaymentProviderExtendedResponse|null $errorResponse
   * @throws UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function recordFailedPayment(
    array $recurringPayment, string $errorMessage, bool $canRetry, ?PaymentProviderExtendedResponse $errorResponse = NULL
  ): void {
    $cancelRecurringDonation = FALSE;

    $this->createActivity($recurringPayment, $errorResponse, $errorMessage, 'failure');

    $params = [];
    if ($errorResponse instanceof CreatePaymentWithProcessorRetryResponse) {
      // if failed, also update the rescue_reference
      if (!empty($errorResponse->getProcessorRetryRescueReference())) {
        $params['contribution_recur_smashpig.rescue_reference'] = $errorResponse->getProcessorRetryRescueReference();
      }
      if ($errorResponse->getIsProcessorRetryScheduled()) {
        // Set status to Pending but advance the next charge date a month so we don't try to charge again
        $params['contribution_status_id:name'] = 'Pending';
        $params['next_sched_contribution_date'] = CRM_Core_Payment_Scheduler::getNextContributionDate($recurringPayment);
        $this->createActivity($recurringPayment, $errorResponse, $errorMessage, 'processorRetry');
      } else {
        // This happens when a payment cannot be rescued.
        // For example, because of account closure or fraud.
        $cancelRecurringDonation = TRUE;
        // retryWindowHasElapsed: The rescue window expired.
        // maxRetryAttemptsReached: The maximum number of retry attempts was made.
        // fraudDecline: The retry was rejected due to fraud.
        // internalError: An internal error occurred while retrying the payment.
        $params['cancel_reason'] = 'Payment cannot be rescued: ' . $errorResponse->getProcessorRetryRefusalReason();
        Civi::log('wmf')->info($params['cancel_reason'] . ' with contribution_recur_id:' . $recurringPayment['id']. ', and order reference is ' .  $recurringPayment['invoice_id']);
      }
    }
    else {
      // only if not handle by auto rescue, compare failure with maxFailure or update next retry day
      $previousFailureCount = $recurringPayment['failure_count'];
      $newFailureCount = $previousFailureCount + 1;
      $params['failure_count'] = $newFailureCount;
      if (!$canRetry) {
        $cancelRecurringDonation = TRUE;
        $params['cancel_reason'] = '(auto) un-retryable card decline reason code';
      }
      elseif ($newFailureCount >= $this->maxFailures) {
        $cancelRecurringDonation = TRUE;
        $params['cancel_reason'] = '(auto) maximum failures reached';
      }
      else {
        // Calculate the number of days between retry day N and N-1
        if ($previousFailureCount === 0) {
          $delayDays = $this->retryCadence[0];
        } else {
          $delayDays = $this->retryCadence[$previousFailureCount] - $this->retryCadence[$previousFailureCount - 1];
        }

        $delayInterval = new DateInterval('P' . $delayDays . 'D');

        $params['contribution_status_id:name'] = 'Failing';
        $params['next_sched_contribution_date'] = UtcDate::getUtcDatabaseString(
          (new DateTimeImmutable())->add($delayInterval)->getTimestamp()
        );
      }
    }
    if ($cancelRecurringDonation) {
      $params['contribution_status_id:name'] = 'Failed';
      $params['cancel_date'] = UtcDate::getUtcDatabaseString();
    }
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurringPayment['id'])
      ->setValues($params)
      ->execute();

    if ($cancelRecurringDonation) {
      $hasOtherActiveRecurring = $this->hasOtherActiveRecurringContribution(
        $recurringPayment['contact_id'],
        $recurringPayment['id']
      );

      if (!$hasOtherActiveRecurring) {
        // we only send a recurring failure email if the contact has no
        // other active recurring donations. see T260910
        $this->sendFailureEmail($recurringPayment['id'], $recurringPayment['contact_id']);
      }
    }
  }

  protected function createActivity($recurringPayment, $errorResponse, $errorMessage, $type): void {
    if ($type == 'failure') {
      $name = 'Recurring Failure';
      $subject = 'Payment of ' . $recurringPayment['amount']. ' ' . $recurringPayment['currency'] . ' failed with ' . $errorMessage;
      $details = $subject;
    } else if ($type == 'processorRetry') {
      $name = 'Recurring Processor Retry - Start';
      $subject = 'Processor retry started with rescue reference ' . $errorResponse->getProcessorRetryRescueReference();
      $details = 'Payment of ' . $recurringPayment['amount'] . ' ' .  $recurringPayment['currency'] . ' failed with ' . $errorMessage;
    } else {
      throw new UnexpectedValueException('Bad activity type: ' . $type);
    }

    $createCall = Activity::create(FALSE)
      ->addValue('activity_type_id:name', $name)
      ->addValue('source_record_id', $recurringPayment['id'])
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', $subject)
      ->addValue('details', $details)
      ->addValue('source_contact_id', $recurringPayment['contact_id'])
      ->addValue('target_contact_id', $recurringPayment['contact_id']);
    $createCall->execute();
  }

  /**
   * Send an email notifying donor of cancellation.
   *
   * @param int $contributionRecurID
   * @param int $contactID
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function sendFailureEmail(int $contributionRecurID, int $contactID): void {
    if (Civi::settings()->get('smashpig_recurring_send_failure_email')) {
      FailureEmail::sendViaQueue($contactID, $contributionRecurID);
    }
  }

  /**
   * Check if the donor has another active recurring contribution set up.
   *
   * @param int $contactID
   * @param int $recurringID ID of recurring contribution record
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  protected function hasOtherActiveRecurringContribution(int $contactID, int $recurringID) : bool {
    $result = civicrm_api3('ContributionRecur', 'get', [
      'id' => ['!=' => $recurringID],
      'contact_id' => $contactID,
      'contribution_status_id' => ['IN' => ['Pending', 'Overdue', 'In Progress', 'Failing']],
      'payment_token_id' => ['IS NOT NULL' => TRUE],
    ]);

    $hasActiveRecurring = !empty($result['count']);
    return $hasActiveRecurring;
  }

}
