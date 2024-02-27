<?php

namespace Civi\WMFQueueMessage;

use Civi\WMFException\WMFException;

class RecurDonationMessage extends DonationMessage {

  /**
   * True if recurring is in the incoming array or a contribution_recur_id is present.
   *
   * @return bool
   */
  public function isRecurring(): bool {
    return TRUE;
  }

  /**
   * Normalize the queued message
   *
   * The goal is to break this up into multiple functions (mostly of the
   * getFinancialTypeID() nature)  now that it has been moved.
   *
   * @return array
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   */
  public function normalize(): array {
    $message = parent::normalize();

    if (!isset($message['start_date'])) {
      $message['start_date'] = $message['date'];
      $message['create_date'] = $message['date'];
    }

    $message['subscr_id'] = $this->getSubscriptionID();
    return $message;
  }

  /**
   * Validate the message
   *
   * @return void
   * @throws \Civi\WMFException\WMFException
   */
  public function validate(): void {
    if ($this->getFrequencyUnit()
      && !in_array($this->getFrequencyUnit(), ['day', 'week', 'month', 'year'])) {
      throw new WMFException(WMFException::INVALID_RECURRING, "Bad frequency unit: " . $this->getFrequencyUnit());
    }
    if (!$this->getSubscriptionID() && !$this->getContributionRecurID() && !$this->getRecurringPaymentToken()) {
      throw new WMFException(WMFException::INVALID_RECURRING, 'Recurring donation, but no subscription ID or recurring payment token found.');
    }
  }

  /**
   * @return string|int|null
   */
  public function getRecurringPaymentToken() {
    return $this->message['recurring_payment_token'] ?? NULL;
  }

  /**
   * @return int|null
   */
  public function getContributionRecurID(): ?int {
    return $this->message['contribution_recur_id'] ?? NULL;
  }

  public function getFrequencyUnit() {
    return $this->message['frequency_unit'] ?? NULL;
  }

  public function isInvalidRecurring(): bool {
    return empty($this->message['recurring_payment_token']) && empty($this->message['subscr_id']);
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithSubscriberID(): bool {
    return !empty($this->message['subscr_id']);
  }

  /**
   *
   * @return bool
   */
  public function isRecurringWithPaymentToken(): bool {
    return !empty($this->message['recurring_payment_token']);
  }

  /**
   * Get the subscriber ID.
   *
   * @return string|null
   */
  public function getSubscriptionID(): ?string {
    $subscriberID = trim($this->message['subscr_id'] ?? '');
    if ($subscriberID) {
      return $subscriberID;
    }
    if ($this->isAmazon()) {
      // Amazon 'subscription id' is the Billing Agreement ID, which
      // is a substring of the Capture ID we record as 'gateway_txn_id'
      $subscriberID = substr((string) $this->message['gateway_txn_id'], 0, 19);
    }

    return $subscriberID ?: NULL;
  }

}
