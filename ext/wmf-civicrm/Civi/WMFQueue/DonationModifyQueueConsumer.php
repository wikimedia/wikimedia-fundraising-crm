<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\ExchangeRates\ExchangeRatesException;
use Civi\SmashPig\RecurringFailureHandler;
use Civi\WMFException\WMFException;
use Civi\WMFQueueMessage\DonationModifyMessage;

class DonationModifyQueueConsumer extends TransactionalQueueConsumer {

  /**
   * @inheritDoc
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws WMFException|ExchangeRatesException
   */
  public function processMessage(array $message): void {
    $messageObject = new DonationModifyMessage($message);
    $messageObject->validate();

    if ($messageObject->isCancelled()) {
      $this->updateCancelledContribution($messageObject);
      return;
    }

    throw new WMFException(WMFException::INVALID_MESSAGE, 'Unknown modification type');
  }

  /**
   * Update a contribution to 'cancelled' status and potentially update the associated contribution_recur
   *
   * @param DonationModifyMessage $message
   *
   * @throws \CRM_Core_Exception
   */
  protected function updateCancelledContribution(DonationModifyMessage $message): void {
    // right now exclusive to ACH but do others fall into this situation, e.g. iDEAL?
    $contribution = $message->getContribution();
    if (!$contribution) {
      Civi::log('wmf')->info(
        "updateCancelledContribution: no contribution with gateway_txn_id {$message->getGatewayTxnId()}"
      );
      return;
    }
    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('cancel_reason', $message->getReason())
      ->addValue('cancel_date', $message->getDate())
      ->execute();

    if (!$contribution['contribution_recur_id']) {
      return;
    }

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contribution['contribution_recur_id'])
      ->addSelect('*')
      ->addSelect('contribution_status_id:name')
      ->addSelect('custom.*')
      ->execute()->first();

    if (in_array($contributionRecur['contribution_status_id:name'], ['Failed', 'Cancelled'])) {
      // Already failed, no need to do anything more
      return;
    }

    if ($contributionRecur['contribution_status_id:name'] === 'Failing') {
      // If we've already recorded a 'Recurring Failure' activity in the past hour
      // for this contribution_recur, stop. That means the failure was handled
      // synchronously in the recurring charge job.
      $existingActivity = Civi\Api4\Activity::get(FALSE)
        ->addWhere('activity_type_id:name', '=', 'Recurring Failure')
        ->addWhere('source_record_id', '=', $contributionRecur['id'])
        ->addWhere('activity_date_time', '>', '-1 HOUR')
        ->execute()->first();
      if ($existingActivity) {
        return;
      }
    }

    // Record the recurring failure and update the contribution_recur row
    $retryCadence = explode(',', \Civi::settings()->get('smashpig_recurring_retry_cadence'));
    $failureHandler = new RecurringFailureHandler($retryCadence);

    $failureHandler->recordFailedPayment(
      $contributionRecur,
      $message->getReason(),
      $message->canRetry()
    );
  }
}
