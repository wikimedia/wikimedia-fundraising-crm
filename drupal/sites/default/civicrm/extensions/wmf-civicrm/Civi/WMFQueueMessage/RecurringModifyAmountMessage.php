<?php

namespace Civi\WMFQueueMessage;

use Civi\WMFException\WMFException;

class RecurringModifyAmountMessage extends Message {

  public function isDecline(): bool {
    return $this->message['txn_type'] === 'recurring_upgrade_decline';
  }

  public function isUpgrade(): bool {
    return $this->message['txn_type'] === 'recurring_upgrade';
  }

  public function isDowngrade(): bool {
    return $this->message['txn_type'] === 'recurring_downgrade';
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
      if ($this->getDifferenceAmount() <= 0) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'upgradeRecurAmount: New recurring amount is less than the original amount.');
      }
    }

    if ($this->isDowngrade()) {
      if (!isset($this->message['amount'])) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to downgrade recurring subscription but amount is not set');
      }
      if ($this->getDifferenceAmount() >= 0) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'downgradeRecurAmount: New recurring amount is greater than the original amount.');
      }
    }
  }

  /**
   * Get the currency remitted by the donor.
   *
   * @return string
   */
  public function getModifiedCurrency(): string {
    return $this->message['currency'];
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
   * Get the amount in the original currency.
   *
   * @return string
   * @throws \Civi\ExchangeException\ExchangeRatesException
   */
  public function getUsdModifiedAmountRounded(): string {
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
   * @throws \Civi\ExchangeException\ExchangeRatesException
   */
  public function getUsdDecreaseAmountRounded(): string {
    return $this->round($this->currencyConvert($this->getModifiedCurrency(), $this->getDecreaseAmount()), $this->getModifiedCurrency());
  }

  public function getOriginalIncreaseAmountRounded(): string {
    return $this->round($this->getDifferenceAmount(), $this->getModifiedCurrency());
  }

  /**
   * @throws \Civi\ExchangeException\ExchangeRatesException
   */
  public function getUsdIncreaseAmountRounded(): string {
    return $this->round($this->currencyConvert($this->getModifiedCurrency(), $this->getDifferenceAmount()), $this->getModifiedCurrency());
  }

  public function getOriginalExistingAmountRounded(): string {
    return $this->round($this->getExistingContributionRecurValue('amount'), $this->getExistingContributionRecurValue('currency'));
  }

  /**
   * @return string
   * @throws \Civi\ExchangeException\ExchangeRatesException
   */
  public function getUsdExistingAmountRounded(): string {
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

}