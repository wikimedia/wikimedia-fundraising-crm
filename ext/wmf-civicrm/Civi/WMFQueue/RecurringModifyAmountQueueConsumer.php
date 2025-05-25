<?php

namespace Civi\WMFQueue;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\RecurUpgradeEmail;
use Civi\ExchangeRates\ExchangeRatesException;
use Civi\WMFException\WMFException;
use Civi\WMFQueueMessage\RecurringModifyAmountMessage;

class RecurringModifyAmountQueueConsumer extends TransactionalQueueConsumer {

  public const RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID = 165;

  public const RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID = 166;

  public const RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID = 168;

  /**
   * @inheritDoc
   *
   * @param array $message
   *
   * @throws \CRM_Core_Exception
   * @throws WMFException|ExchangeRatesException
   */
  public function processMessage(array $message): void {
    $messageObject = new RecurringModifyAmountMessage($message);
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
   * @param RecurringModifyAmountMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   */
  protected function upgradeRecurDecline(RecurringModifyAmountMessage $message, array $msg): void {
    $createCall = Activity::create(FALSE)
      ->addValue('activity_type_id', self::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID)
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
   */
  protected function getActivityValues(RecurringModifyAmountMessage $message, $msg): array {
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
   * @param RecurringModifyAmountMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws ExchangeRatesException
   */
  protected function upgradeRecurAmount(RecurringModifyAmountMessage $message, array $msg): void {
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

    $activityParams['activity_type_id'] = self::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID;
    $this->updateContributionRecurAndRecurringActivity($amountDetails, $activityParams);
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
   * @param RecurringModifyAmountMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws ExchangeRatesException
   */
  protected function downgradeRecurAmount(RecurringModifyAmountMessage $message, array $msg): void {
    $amountDetails = [
      'native_currency' => $message->getModifiedCurrency(),
      'native_original_amount' => $message->getOriginalExistingAmountRounded(),
      'usd_original_amount' => $message->getSettledExistingAmountRounded(),
      'native_amount_removed' => $message->getOriginalDecreaseAmountRounded(),
      'usd_amount_removed' => $message->getSettledDecreaseAmountRounded(),
    ];
    $activityParams = $this->getActivityValues($message, $msg);

    $activityParams['subject'] = "Recurring amount reduced by " . $message->getOriginalDecreaseAmountRounded() . ' ' . $message->getModifiedCurrency();
    $activityParams['activity_type_id'] = self::RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID;
    $this->updateContributionRecurAndRecurringActivity($amountDetails, $activityParams);
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
  protected function updateContributionRecurAndRecurringActivity(array $amountDetails, array $activityParams): void {
    $additionalData = json_encode($amountDetails);

    ContributionRecur::update(FALSE)->addValue('amount', $activityParams['amount'])->addWhere(
      'id',
      '=',
      $activityParams['contribution_recur_id']
    )->execute();

    $createCall = Activity::create(FALSE)
      ->addValue('activity_type_id', $activityParams['activity_type_id'])
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
   * @param RecurringModifyAmountMessage $messageObject
   * @param array $msg
   * @return void
   *
   * @throws ExchangeRatesException
   * @throws \CRM_Core_Exception
   */
  private function importExternalModifiedRecurRecord(RecurringModifyAmountMessage $messageObject, array $msg): void {
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
        $activityParams['activity_type_id'] = self::RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID;
      }
      else {
        $amountDetails['native_amount_added'] = $messageObject->getOriginalIncreaseAmountRounded();
        $amountDetails['usd_amount_added'] = $messageObject->getSettledIncreaseAmountRounded();
        $activityParams['subject'] = "Recurring amount increased by {$messageObject->getOriginalIncreaseAmountRounded()} {$recur_currency}";
        $activityParams['activity_type_id'] = self::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID;
      }
      $this->updateContributionRecurAndRecurringActivity($amountDetails, $activityParams);
    }
  }

}
