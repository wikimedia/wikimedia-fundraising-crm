<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\RecurUpgradeEmail;
use Civi\ExchangeRates\ExchangeRatesException;
use Civi\WMFException\WMFException;
use Civi\WMFQueueMessage\RecurringModifyMessage;

class RecurringModifyQueueConsumer extends TransactionalQueueConsumer {

  public const RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_NAME = 'Recurring Upgrade';

  public const RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_NAME = 'Recurring Upgrade Decline';

  public const RECURRING_DOWNGRADE_ACTIVITY_TYPE_NAME = 'Recurring Downgrade';

  public const RECURRING_PAUSED_ACTIVITY_TYPE_NAME = 'Recurring Paused';

  public const RECURRING_CANCELLED_ACTIVITY_TYPE_NAME = 'Cancel Recurring Contribution';

  /**
   * @inheritDoc
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws WMFException|ExchangeRatesException
   */
  public function processMessage(array $message): void {
    $messageObject = new RecurringModifyMessage($message);
    $messageObject->validate();

    if ($messageObject->isDecline()) {
      $this->upgradeRecurDecline($messageObject, $message);
      return;
    }
    if ($messageObject->isUpgrade()) {
      $this->upgradeRecurAmount($messageObject, $message);
      return;
    }
    if ($messageObject->isDowngrade()) {
      $this->downgradeRecurAmount($messageObject, $message);
      return;
    }
    if ($messageObject->isPaused()) {
      $this->pauseRecurRecord($messageObject, $message);
      return;
    }
    if ($messageObject->isCancelled()) {
      $this->cancelRecurRecord($messageObject, $message);
      return;
    }
    if ($messageObject->isExternalSubscriptionModification()) {
      $this->importExternalModifiedRecurRecord($messageObject, $message);
      return;
    }
    throw new WMFException(WMFException::INVALID_RECURRING, 'Unknown transaction type');
  }

  /**
   * Decline upgrade recurring
   *
   * Completes the process of upgrading the contribution recur
   * if the donor decline
   *
   * @param RecurringModifyMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   */
  protected function upgradeRecurDecline(RecurringModifyMessage $message, array $msg): void {
    $createCall = Activity::create(FALSE)
      ->addValue('activity_type_id:name', self::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_NAME)
      ->addValue('source_record_id', $message->getContributionRecurID())
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', "Decline recurring upgrade")
      ->addValue('details', "Decline recurring upgrade")
      ->addValue('source_contact_id', $message->getContactID());
    foreach (['campaign', 'medium', 'source'] as $trackingField) {
      if (!empty($msg[$trackingField])) {
        $createCall->addValue('activity_tracking.activity_' . $trackingField, $msg[$trackingField]);
      }
    }
    $createCall->execute();
  }

  /**
   * Pause recur record
   */

  protected function pauseRecurRecord(RecurringModifyMessage $message, array $msg): void {
    $date = date_create($message->getNextScheduledDate());
    $new_date = date_add($date, date_interval_create_from_date_string($msg['duration']));
    $formatDate = date_format($new_date, 'Y-m-d H:i:s');
    $pauseScheduledParams = [
      'next_sched_contribution_date' => $formatDate,
      'old_date' => $date->format('Y-m-d H:i:s'),
      'duration' => $msg['duration']
    ];
    $activityParams = [
      'subject' => "Paused recurring till {$formatDate}",
      'contact_id' => $message->getExistingContributionRecurValue('contact_id'),
      'contribution_recur_id' => $message->getContributionRecurID(),
      'activity_type_id:name' => self::RECURRING_PAUSED_ACTIVITY_TYPE_NAME
    ];
    ContributionRecur::update(FALSE)->addValue('next_sched_contribution_date', $pauseScheduledParams['next_sched_contribution_date'])->addWhere(
      'id',
      '=',
      $activityParams['contribution_recur_id']
    )->execute();

    $this->createRecurringActivity(json_encode($pauseScheduledParams), $activityParams);
  }

  /**
   * Pause recur record
   */

  protected function cancelRecurRecord(RecurringModifyMessage $message, array $msg): void {
    $date = $message->getCancelDate();
    $update_params = [
      'id' => $message->getContributionRecurID(),
      'cancel_date' => $date,
      'cancel_reason' => $message->getCancelReason() ?? '(auto) User Cancelled via Donor Portal',
      'end_date' => $date,
    ];
    $activityParams = [
      'subject' => "Donor cancelled recurring through the Donor Portal on {$date}",
      'contact_id' => $message->getExistingContributionRecurValue('contact_id'),
      'contribution_recur_id' => $message->getContributionRecurID(),
      'activity_type_id:name' => self::RECURRING_CANCELLED_ACTIVITY_TYPE_NAME
    ];
    ContributionRecur::update(FALSE)
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->addValue('cancel_date', $update_params['cancel_date'])
      ->addValue('end_date',$update_params['end_date'])
      ->addValue('cancel_reason',$update_params['cancel_reason'])
      ->addWhere(
      'id',
      '=',
      $message->getContributionRecurID()
    )->execute();

    $this->createRecurringActivity(json_encode($update_params), $activityParams);
  }

  /**
   */
  protected function getActivityValues(RecurringModifyMessage $message, $msg): array {
    $activityParams = [
      'amount' => $message->getModifiedAmountRounded(),
      'contact_id' => $message->getExistingContributionRecurValue('contact_id'),
      'contribution_recur_id' => $message->getContributionRecurID(),
    ];
    foreach (['campaign', 'medium', 'source'] as $trackingField) {
      $activityParams[$trackingField] = $msg[$trackingField] ?? NULL;
    }
    return $activityParams;
  }

  /**
   * Upgrade Contribution Recur Amount
   *
   * Completes the process of upgrading the contribution recur amount
   * if the donor agrees
   *
   * @param RecurringModifyMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws ExchangeRatesException
   */
  protected function upgradeRecurAmount(RecurringModifyMessage $message, array $msg): void {
    $increaseAsFloat = floatval($message->getOriginalIncreaseAmountRounded());
    if ($increaseAsFloat === 0.0) {
      Civi::log('wmf')->info('Discarding (probable duplicate) recurring upgrade message with zero amount');
      return;
    }
    $amountDetails = [
      'native_currency' => $message->getModifiedCurrency(),
      'native_original_amount' => $message->getOriginalExistingAmountRounded(),
      'usd_original_amount' => $message->getSettledExistingAmountRounded(),
      'native_amount_added' => $message->getOriginalIncreaseAmountRounded(),
      'usd_amount_added' => $message->getSettledIncreaseAmountRounded(),
    ];

    $activityParams = $this->getActivityValues($message, $msg);
    $activityParams['subject'] = 'Added ' . $message->getOriginalIncreaseAmountRounded() . ' ' . $message->getModifiedCurrency();

    if ($increaseAsFloat < 0) {
      $activityParams['subject'] .= ' (Negative value probably indicates a second upgrade form click with a lower amount)';
    }

    $activityParams['activity_type_id:name'] = self::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_NAME;
    $this->updateContributionRecurAmountAndRecurringActivity($amountDetails, $activityParams);
    RecurUpgradeEmail::send()
      ->setCheckPermissions(FALSE)
      ->setContactID($message->getExistingContributionRecurValue('contact_id'))
      ->setContributionRecurID($message->getContributionRecurID())
      ->execute();
  }

  /**
   * Downgrade Contribution Recur Amount
   *
   * Completes the process of downgrading the contribution recur amount
   *
   * @param RecurringModifyMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws ExchangeRatesException
   */
  protected function downgradeRecurAmount(RecurringModifyMessage $message, array $msg): void {
    $amountDetails = [
      'native_currency' => $message->getModifiedCurrency(),
      'native_original_amount' => $message->getOriginalExistingAmountRounded(),
      'usd_original_amount' => $message->getSettledExistingAmountRounded(),
      'native_amount_removed' => $message->getOriginalDecreaseAmountRounded(),
      'usd_amount_removed' => $message->getSettledDecreaseAmountRounded(),
    ];
    $activityParams = $this->getActivityValues($message, $msg);

    $activityParams['subject'] = "Recurring amount reduced by " . $message->getOriginalDecreaseAmountRounded() . ' ' . $message->getModifiedCurrency();
    $activityParams['activity_type_id:name'] = self::RECURRING_DOWNGRADE_ACTIVITY_TYPE_NAME;
    $this->updateContributionRecurAmountAndRecurringActivity($amountDetails, $activityParams);
  }

  /**
   * @param array $amountDetails
   * @param array $activityParams array containing activity and contribution recur data
   * - contact_id (number): required
   * - contribution_recur_id (number): required
   * - activity_type_id (string): required
   * - amount (string): required
   * - subject (string): required
   *
   * @throws \CRM_Core_Exception
   */
  protected function updateContributionRecurAmountAndRecurringActivity(array $amountDetails, array $activityParams): void {
    ContributionRecur::update(FALSE)->addValue('amount', $activityParams['amount'])->addWhere(
      'id',
      '=',
      $activityParams['contribution_recur_id']
    )->execute();

    $this->createRecurringActivity(json_encode($amountDetails), $activityParams);
  }

  protected function createRecurringActivity(string $additionalData, array $activityParams): void {
    $createCall = Activity::create(FALSE)
      ->addValue('activity_type_id:name', $activityParams['activity_type_id:name'])
      ->addValue(
        'source_record_id',
        $activityParams['contribution_recur_id']
      )
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', $activityParams['subject'])
      ->addValue('details', $additionalData)
      ->addValue('source_contact_id', $activityParams['contact_id']);
    foreach (['campaign', 'medium', 'source'] as $trackingField) {
      if (!empty($activityParams[$trackingField])) {
        $createCall->addValue('activity_tracking.activity_' . $trackingField, $activityParams[$trackingField]);
      }
    }
    $createCall->execute();
  }

  /**
   * Import recur record from external payment orchestrator
   * ex. FundraiseUp
   *
   * @param RecurringModifyMessage $messageObject
   * @param array $msg
   * @return void
   *
   * @throws ExchangeRatesException
   * @throws \CRM_Core_Exception
   */
  private function importExternalModifiedRecurRecord(RecurringModifyMessage $messageObject, array $msg): void {
    $message = $messageObject->normalize();
    $message['id'] = $message['contact_id'];
    // FundraiseUp also sends contact updates in the notification
    Contact::save(FALSE)->addRecord($message)->execute();

    $recur_amount = (float) $messageObject->getExistingContributionRecurValue('amount');
    $recur_currency = $messageObject->getExistingContributionRecurValue('currency');

    //The subscr_modify message could also be a notification of changing amount
    $amount_mismatch = !empty($messageObject->getModifiedAmount()) && ($messageObject->getModifiedAmount() !== $recur_amount);
    if ($amount_mismatch) {
      $amountDetails = [
        'native_currency' => $messageObject->getModifiedCurrency(),
        'native_original_amount' => $recur_amount,
        'usd_original_amount' => $messageObject->getSettledExistingAmountRounded(),
      ];
      $activityParams = $this->getActivityValues($messageObject, $msg);

      if ($msg['amount'] < $recur_amount) {
        $amountDetails['native_amount_removed'] = $messageObject->getOriginalDecreaseAmountRounded();
        $amountDetails['usd_amount_removed'] = $messageObject->getSettledDecreaseAmountRounded();
        $activityParams['subject'] = "Recurring amount reduced by {$messageObject->getOriginalDecreaseAmountRounded()} {$recur_currency}";
        $activityParams['activity_type_id:name'] = self::RECURRING_DOWNGRADE_ACTIVITY_TYPE_NAME;
      }
      else {
        $amountDetails['native_amount_added'] = $messageObject->getOriginalIncreaseAmountRounded();
        $amountDetails['usd_amount_added'] = $messageObject->getSettledIncreaseAmountRounded();
        $activityParams['subject'] = "Recurring amount increased by {$messageObject->getOriginalIncreaseAmountRounded()} {$recur_currency}";
        $activityParams['activity_type_id:name'] = self::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_NAME;
      }
      $this->updateContributionRecurAmountAndRecurringActivity($amountDetails, $activityParams);
    }
  }

}
