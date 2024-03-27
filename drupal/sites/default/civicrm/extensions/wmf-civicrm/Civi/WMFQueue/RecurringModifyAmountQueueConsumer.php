<?php

namespace Civi\WMFQueue;

use Civi\Api4\RecurUpgradeEmail;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Activity;
use Civi\WMFException\WMFException;
use Civi\WMFQueueMessage\RecurringModifyAmountMessage;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;

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
   * @throws \Civi\ExchangeException\ExchangeRatesException
   * @throws \Civi\WMFException\WMFException
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
    throw new WMFException(WMFException::INVALID_RECURRING, 'Unknown transaction type');
  }

  /**
   * Decline upgrade recurring
   *
   * Completes the process of upgrading the contribution recur
   * if the donor decline
   *
   * @param \Civi\WMFQueueMessage\RecurringModifyAmountMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   */
  protected function upgradeRecurDecline(RecurringModifyAmountMessage $message, array $msg): void {
    $createCall = Activity::create(FALSE)
      ->addValue('activity_type_id', self::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID)
      ->addValue('source_record_id', $msg['contribution_recur_id'])
      ->addValue('status_id:name', 'Completed')
      ->addValue('subject', "Decline recurring update")
      ->addValue('details', "Decline recurring update")
      ->addValue('source_contact_id', $msg['contact_id']);
    foreach (['campaign', 'medium', 'source'] as $trackingField) {
      if (!empty($msg[$trackingField])) {
        $createCall->addValue('activity_tracking.activity_' . $trackingField, $msg[$trackingField]);
      }
    }
    $createCall->execute();
  }

  /**
   * @throws \Civi\ExchangeException\ExchangeRatesException
   */
  protected function getSubscrModificationParameters($msg, $recur_record): array {
    $amountDetails = [
      'native_currency' => $msg['currency'],
      'native_original_amount' => CurrencyRoundingHelper::round(
        $recur_record['amount'], $msg['currency']
      ),
      'usd_original_amount' => CurrencyRoundingHelper::round(
        exchange_rate_convert($msg['currency'], $recur_record['amount']), 'USD'
      ),
    ];
    $activityParams = [
      'amount' => CurrencyRoundingHelper::round($msg['amount'], $msg['currency']),
      'contact_id' => $recur_record['contact_id'],
      'contribution_recur_id' => $recur_record['id'],
    ];
    foreach (['campaign', 'medium', 'source'] as $trackingField) {
      $activityParams[$trackingField] = $msg[$trackingField] ?? NULL;
    }
    return [$amountDetails, $activityParams];
  }

  /**
   * Upgrade Contribution Recur Amount
   *
   * Completes the process of upgrading the contribution recur amount
   * if the donor agrees
   *
   * @param \Civi\WMFQueueMessage\RecurringModifyAmountMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\ExchangeException\ExchangeRatesException
   * @throws \Civi\WMFException\WMFException
   */
  protected function upgradeRecurAmount(RecurringModifyAmountMessage $message, array $msg): void {
    $recur_record = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $message->getContributionRecurID())
      ->execute()
      ->first();

    [$amountDetails, $activityParams] = $this->getSubscrModificationParameters($msg, $recur_record);
    $amountAdded = $msg['amount'] - $recur_record['amount'];
    $amountAddedRounded = CurrencyRoundingHelper::round($amountAdded, $msg['currency']);
    $amountDetails['native_amount_added'] = $amountAddedRounded;
    $amountDetails['usd_amount_added'] = CurrencyRoundingHelper::round(
      exchange_rate_convert($msg['currency'], $amountAdded), 'USD'
    );

    $activityParams['subject'] = "Added $amountAddedRounded {$msg['currency']}";
    $activityParams['activity_type_id'] = self::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID;
    $this->updateContributionRecurAndRecurringActivity($amountDetails, $activityParams);

    RecurUpgradeEmail::send()
      ->setCheckPermissions(FALSE)
      ->setContactID($recur_record['contact_id'])
      ->setContributionRecurID($recur_record['id'])
      ->execute();
  }

  /**
   * Downgrade Contribution Recur Amount
   *
   * Completes the process of downgrading the contribution recur amount
   *
   * @param \Civi\WMFQueueMessage\RecurringModifyAmountMessage $message
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\ExchangeException\ExchangeRatesException
   * @throws \Civi\WMFException\WMFException
   */
  protected function downgradeRecurAmount(RecurringModifyAmountMessage $message, array $msg): void {
    $recur_record = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_recur_id'])
      ->execute()
      ->first();

    [$amountDetails, $activityParams] = $this->getSubscrModificationParameters($msg, $recur_record);
    $amountRemoved = $recur_record['amount'] - $msg['amount'];
    $amountRemovedRounded = CurrencyRoundingHelper::round($amountRemoved, $msg['currency']);
    $amountDetails['native_amount_removed'] = CurrencyRoundingHelper::round($amountRemoved, $msg['currency']);
    $amountDetails['usd_amount_removed'] = CurrencyRoundingHelper::round(
      exchange_rate_convert($msg['currency'], $amountRemoved), 'USD'
    );

    $activityParams['subject'] = "Recurring amount reduced by $amountRemovedRounded {$msg['currency']}";
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
   * @throws \Civi\API\Exception\UnauthorizedException
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

}
