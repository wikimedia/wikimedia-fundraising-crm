<?php

namespace Civi\WMFQueue;

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
    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('cancel_reason', $message->getReason())
      ->addValue('cancel_date', $message->getDate())
      ->execute();

    if ($contribution['contribution_recur_id']) {
      // Handle the recurring failure
      $retryCadence = explode(',', \Civi::settings()->get('smashpig_recurring_retry_cadence'));
      $failureHandler = new RecurringFailureHandler($retryCadence);
      $contributionRecur = ContributionRecur::get(FALSE)
        ->addWhere('id', '=', $contribution['contribution_recur_id'])
        ->addSelect('*')
        ->addSelect('custom.*')
        ->execute()->first();

      $failureHandler->recordFailedPayment(
        $contributionRecur,
        $message->getReason(),
        $message->canRetry()
      );
    }
  }
}
