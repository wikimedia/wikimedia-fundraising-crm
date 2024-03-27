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

    if ($this->isUpgrade() && !isset($this->message['amount'])) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to upgrade recurring subscription but amount is not set');
    }

    if ($this->isDowngrade() && !isset($this->message['amount'])) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Trying to downgrade recurring subscription but amount is not set');
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
    return $this->round($this->message['amount'], $this->getModifiedCurrency());
  }

}
