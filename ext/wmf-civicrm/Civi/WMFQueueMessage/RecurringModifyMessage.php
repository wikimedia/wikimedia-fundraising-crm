<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Address;
use Civi\ExchangeRates\ExchangeRatesException;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\ContributionRecur as RecurHelper;

class RecurringModifyMessage extends Message {

  public const DONOR_PORTAL_MESSAGE_SOURCE_TYPE = 'emailpreferences';

  private $contributionRecurID;

  /**
   * Constructor.
   */
  public function __construct(array $message) {
    parent::__construct($message);
  }

  public function isDecline(): bool {
    return $this->message['txn_type'] === 'recurring_upgrade_decline';
  }

  public function isUpgrade(): bool {
    return $this->message['txn_type'] === 'recurring_upgrade';
  }

  public function isDowngrade(): bool {
    return $this->message['txn_type'] === 'recurring_downgrade';
  }

  public function isExternalSubscriptionModification(): bool {
    return $this->message['txn_type'] === 'external_recurring_modification';
  }

  public function isPaused(): bool {
    return $this->message['txn_type'] === 'recurring_paused';
  }

  public function isAnnualConversion(): bool {
    return $this->message['txn_type'] === 'recurring_annual_conversion';
  }

  public function isCancelled(): bool {
    return $this->message['txn_type'] === 'recurring_cancel';
  }

  /**
   * Get the recurring contribution ID if it already exists.
   *
   * @return int|null
   */
  public function getContributionRecurID(): ?int {
    if (isset($this->contributionRecurID)) {
      return $this->contributionRecurID;
    }
    if (!empty($this->message['contribution_recur_id'])) {
      return (int) $this->message['contribution_recur_id'];
    }
    if (!empty($this->getSubscriptionID())) {
      $recurRecord = RecurHelper::getByGatewaySubscriptionId($this->getGateway(), $this->getSubscriptionID());
      if ($recurRecord) {
        \Civi::log('wmf')->info('recur_donation_import: Found matching recurring record for subscr_id: {subscriber_id}', ['subscriber_id' => $this->getSubscriptionID()]);
        // Since we have loaded this we should register it so we can lazy access it.
        $this->define('ContributionRecur', 'ContributionRecur', $recurRecord);
        $this->contributionRecurID = $recurRecord['id'];
        return $this->contributionRecurID;
      }
    }
    $this->contributionRecurID = NULL;
    return $this->contributionRecurID;
  }

  /**
   * @throws \Civi\WMFException\WMFException
   */
  public function validate(): void {
    if (!$this->getContributionRecurID()) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Invalid message type');
    }

    if ($this->isDecline() && !$this->getContactID()) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Invalid contact_id');
    }

    if ($this->isUpgrade()) {
      if (!isset($this->message['amount'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to upgrade recurring subscription but amount is not set');
      }
      if ($this->getDifferenceAmount() < 0) {
        $this->message['txn_type'] = 'recurring_downgrade';
      }
    }

    if ($this->isDowngrade()) {
      if (!isset($this->message['amount'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to downgrade recurring subscription but amount is not set');
      }
      if ($this->getDifferenceAmount() > 0) {
        $this->message['txn_type'] = 'recurring_upgrade';
      }
    }

    if ($this->isPaused()) {
      if (!isset($this->message['duration'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to pause recurring subscription but duration is not set');
      }
    }

    if ($this->isAnnualConversion()) {
      if (!isset($this->message['amount'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to convert current monthly recurring subscription but amount is not set');
      }
      if (!isset($this->message['next_sched_contribution_date'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to convert current monthly recurring subscription but next schedule date is not set');
      }
    }

    if ($this->isExternalSubscriptionModification() && !$this->getContributionRecurID()) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Unable to locate recur record without Subscription ID');
    }
  }

  public function normalize(): array {
    $message = $this->message + $this->getExternalIdentifierFields();
    $message['contact_id'] = $this->getExistingContributionRecurValue('contact_id');
    if (!empty($message['email'])) {
      $message['email_primary.email'] = $message['email'];
    }
    if (!empty($message['street_address'])) {
      $addressFields = Address::getFields(FALSE)
        ->execute()->indexBy('name');
      foreach ($addressFields as $field) {
        // Do not set id as we will not have one here https://github.com/civicrm/civicrm-core/pull/33284/files#r2275442330
        if ($field['name'] !== 'id') {
          $message['address_primary.' . $field['name']] = $message[$field['name']] ?? NULL;
        }
      }
      $message['address_primary.country_id:name'] = $message['country'] ?? NULL;
      unset($message['address_primary.country']);
      $message['address_primary.state_province_id:name'] = $message['state_province'] ?? NULL;
      unset($message['address_primary.state_province']);
    }
    return $message;
  }

  /**
   * Get the currency remitted by the donor.
   *
   * @return ?string
   */
  public function getModifiedCurrency(): string {
    return $this->message['currency'] ?? $this->getExistingContributionRecurValue('currency');
  }

  /**
   * Get the amount in the original currency.
   *
   * @return string
   */
  public function getModifiedAmountRounded(): string {
    return $this->round($this->getModifiedAmount(), $this->getModifiedCurrency());
  }

  /**
   * Get the updated amount in usd.
   *
   * @return string
   * @throws ExchangeRatesException
   */
  public function getSettledModifiedAmountRounded(): string {
    return $this->round($this->currencyConvert($this->getModifiedCurrency(), $this->getModifiedAmount()), $this->getModifiedCurrency());
  }

  public function getModifiedAmount(): float {
    return (float) $this->message['amount'];
  }

  /**
   * Get the difference between the incoming (modified) amount and the existing
   * amount.
   *
   * If the modified amount is greater than the existing amount (an increased
   * donation) this will be positive. If it is a negative amount the donor is
   * downgrading their recurring contribution.
   *
   * @return float
   */
  public function getDifferenceAmount(): float {
    return $this->getModifiedAmount() - $this->getExistingContributionRecurValue('amount');
  }

  public function getOriginalDecreaseAmountRounded(): string {
    return $this->round($this->getDecreaseAmount(), $this->getModifiedCurrency());
  }

  /**
   * @throws ExchangeRatesException
   */
  public function getSettledDecreaseAmountRounded(): string {
    return $this->round($this->currencyConvert($this->getModifiedCurrency(), $this->getDecreaseAmount()), $this->getModifiedCurrency());
  }

  public function getOriginalIncreaseAmountRounded(): string {
    return $this->round($this->getDifferenceAmount(), $this->getModifiedCurrency());
  }

  /**
   * @throws ExchangeRatesException
   */
  public function getSettledIncreaseAmountRounded(): string {
    return $this->round($this->currencyConvert($this->getModifiedCurrency(), $this->getDifferenceAmount()), $this->getModifiedCurrency());
  }

  public function getOriginalExistingAmountRounded(): string {
    return $this->round($this->getExistingContributionRecurValue('amount'), $this->getExistingContributionRecurValue('currency'));
  }

  public function getNextScheduledDate(): ?string {
    return $this->getExistingContributionRecurValue('next_sched_contribution_date');
  }

  /**
   * @return string
   * @throws ExchangeRatesException
   */
  public function getSettledExistingAmountRounded(): string {
    return $this->round($this->currencyConvert($this->getExistingContributionRecurValue('currency'), $this->getExistingContributionRecurValue('amount')), $this->getExistingContributionRecurValue('currency'));
  }

  /**
   * Get the amount of the decrease.
   *
   * @return string
   */
  public function getDecreaseAmount(): string {
    return -$this->getDifferenceAmount();
  }

  /**
   * Get the subscriber ID.
   *
   * @return string|null
   */
  public function getSubscriptionID(): ?string {
    $subscriptionID = $this->message['subscr_id'] ?? $this->message['trxn_id'] ?? NULL;
    return $subscriptionID ? trim($subscriptionID) : NULL;
  }

  /**
   * Get the cancel reason for subscription cancelled through the portal
   * @return string|null
   */
  public function getCancelReason(): ?string {
    return $this->message['cancel_reason'] ?? NULL;
  }

  /**
   * Get the cancel date for subscription cancelled through the portal
   * @return string
   */
  public function getCancelDate(): string {
    return $this->message['cancel_date'] ?? date('Ymd H:i:s');
  }

  /**
   * Returns true for messages send from the email prefs center or the donor portal
   */
  public function isFromDonorPortal(): bool {
    return $this->message['source_type'] ??= self::DONOR_PORTAL_MESSAGE_SOURCE_TYPE;
  }

  /**
   * Returns true when the action was taken after the donor initially sought to cancel their recurring donation
   * @return bool
   */
  public function isFromSaveFlow(): bool {
    return $this->message['is_from_save_flow'] ?? FALSE;
  }

  public function getActivityTracking(): array {
    $values = [
      'is_from_donor_portal' => $this->isFromDonorPortal(),
    ];
    foreach (['campaign', 'medium', 'source'] as $fieldName) {
      if (!empty($this->message[$fieldName])) {
        $values[$fieldName] = $this->message[$fieldName];
      }
    }
    return $values;
  }
}
