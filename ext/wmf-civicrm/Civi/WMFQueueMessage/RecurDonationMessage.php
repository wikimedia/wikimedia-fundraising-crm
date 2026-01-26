<?php

namespace Civi\WMFQueueMessage;

use Civi\API\EntityLookupTrait;
use Civi\Api4\ContributionRecur;
use Civi\WMFException\WMFException;

class RecurDonationMessage extends DonationMessage {

  use EntityLookupTrait;

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
   * @throws WMFException
   * @throws \CRM_Core_Exception
   */
  public function normalize(): array {
    $message = parent::normalize();

    if (!isset($message['start_date'])) {
      $message['start_date'] = $message['date'];
      $message['create_date'] = $message['date'];
    }
    $message['subscr_id'] = $this->getSubscriptionID();
    $message['contribution_recur_id'] = $this->getContributionRecurID();
    $message['payment_token_id'] = $this->getPaymentTokenID();
    $message['payment_processor_id'] = $this->getExistingPaymentTokenValue('payment_processor_id');
    if (isset($message['txn_type']) && $message['txn_type'] == 'subscr_failed') {
      if (empty($message['failure_count'])) {
        $message['failure_count'] = $this->getRecurringFailCount();
      }
    }
    return $message;
  }

  /**
   * Validate the message
   *
   * @return void
   * @throws WMFException
   */
  public function validate(): void {
    try {
      if ($this->getFrequencyUnit()
        && !in_array($this->getFrequencyUnit(), [
          'day',
          'week',
          'month',
          'year',
        ])) {
        throw new WMFException(WMFException::INVALID_RECURRING, "Bad frequency unit: " . $this->getFrequencyUnit());
      }
      if (!$this->getSubscriptionID() && !$this->getContributionRecurID() && !$this->getRecurringPaymentToken()) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Recurring donation, but no subscription ID or recurring payment token found.');
      }
      if ($this->getContributionRecurID() && !$this->isRecurringFound()) {
        throw new WMFException(WMFException::INVALID_RECURRING, 'Contribution recur ID passed in but could not be loaded');
      }
      // If our RecurDonationMessage is not actually a recur donation
      // message but is actually some other kind of message we would ideally
      // split it into it's own class but for now we only validate the amounts
      // if the message is coming through a flow which we know to be a payment
      // flow.
      if ($this->isPayment()) {
        parent::validate();
      }
    }
    catch (\CRM_Core_Exception $e) {
      throw new WMFException(WMFException::UNKNOWN, 'unknown error' . $e->getMessage() . $e->getTraceAsString());
    }
  }

  /**
   * Is this a payment message.
   *
   * The default is that messages ARE payment - however we do have sign-ups etc.
   * coming in though the Recurring Queue Consumer.
   *
   * @return bool
   */
  public function isPayment() : bool {
    if (isset($this->isPayment)) {
      return $this->isPayment;
    }
    if (!isset($this->message['txn_type'])) {
      return TRUE;
    }
    return $this->message['txn_type'] === 'subscr_payment';
  }

  /**
   * Is the requested recurring contribution to be found in the database.
   *
   * @return bool
   */
  private function isRecurringFound(): bool {
    try {
      return (bool) $this->getExistingContributionRecurValue('contribution_status_id');
    }
    catch (\CRM_Core_Exception $e) {
      return FALSE;
    }
  }

  /**
   * @return string|int|null
   */
  public function getRecurringPaymentToken() {
    return $this->message['recurring_payment_token'] ?? NULL;
  }

  /**
   * Get the recurring contribution ID if it already exists.
   *
   * @return int|null
   * @throws \CRM_Core_Exception
   * @throws WMFException
   */
  public function getContributionRecurID(): ?int {
    $id = parent::getContributionRecurID();
    if ($id) {
      return (int) $id;
    }
    if ($this->isAutoRescue()) {
      $recurRecord = ContributionRecur::get(FALSE)
        ->addWhere('contribution_recur_smashpig.rescue_reference', '=', $this->getAutoRescueReference())
        ->addSelect('*', 'contribution.*')
        ->addJoin('Contribution AS contribution', 'LEFT', ['contribution.contribution_recur_id', '=', 'id'])
        ->setLimit(1)
        ->execute()
        ->first();
      if (!$recurRecord) {
        throw new WMFException(WMFException::INVALID_RECURRING, "Error finding rescued recurring payment with recurring reference " . $this->getAutoRescueReference());
      }
      $contributionValues = [];
      foreach ($recurRecord as $key => $value) {
        if (str_starts_with($key, 'contribution.')) {
          $contributionValues[substr($key, 13)] = $value;
          unset($recurRecord[$key]);
        }
      }
      // Register the entities we have loaded so we can lazy access them.
      $this->define('ContributionRecur', 'ContributionRecur', $recurRecord);
      $this->contributionRecurID = $recurRecord['id'];
      if (!empty($contributionValues['id'])) {
        $this->define('Contribution', 'PriorContribution', $contributionValues);
      }
      else {
        \Civi::log('wmf')->info('No previous contribution found for auto-rescue %url', [
          'contribution_recur_id' => $this->contributionRecurID,
          'auto_rescue' => $this->getAutoRescueReference(),
          'contact_id' => $recurRecord['contact_id'],
          'url' => \CRM_Utils_System::url('civicrm/contact/view/contributionrecur', [
            'reset' => 1,
            'id' => $this->contributionRecurID,
          ], TRUE),
        ]);
      }
      return $this->contributionRecurID;
    }
    $this->contributionRecurID = NULL;
    return $this->contributionRecurID;
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
   * @throws \CRM_Core_Exception
   */
  public function getContactID(): ?int {
    // We prefer these existing contact look ups over the parent derived one as they would pick up merges better.
    if ($this->getExistingContributionRecurValue('contact_id')) {
      return $this->getExistingContributionRecurValue('contact_id');
    }
    if ($this->getPaymentTokenID()) {
      return $this->getExistingPaymentTokenValue('contact_id');
    }
    return parent::getContactID();
  }

  /**
   * Get the subscriber ID.
   *
   * @return string|null
   */
  public function getSubscriptionID(): ?string {
    $subscriberID = parent::getSubscriptionID();
    if ($subscriberID) {
      return $subscriberID;
    }
    if ($this->isAmazon()) {
      // Amazon 'subscription id' is the Billing Agreement ID, which
      // is a substring of the Capture ID we record as 'gateway_txn_id'
      $subscriberID = substr((string) $this->message['gateway_txn_id'], 0, 19);
    }
    if ($this->isAutoRescue()) {
      return $this->getExistingContributionRecurValue('trxn_id');
    }

    return $subscriberID ?: NULL;
  }

  /**
   * Set the contribution Recur ID.
   *
   * This would be used when the calling code has created a missing contribution
   * recur.
   *
   * @param int|null $contributionRecurID
   * @return void
   */
  public function setContributionRecurID(?int $contributionRecurID): void {
    $this->contributionRecurID = $contributionRecurID;
  }

  /**
   * Is this a recurring payment which the provider has been able to 'rescue'.
   *
   * Adyen is able to get the donor's failing recurring back on track in some
   * cases - these manifest as an auto-rescue.
   *
   * @return bool
   */
  public function isAutoRescue(): bool {
    return (isset($this->message['is_successful_autorescue']) && $this->message['is_successful_autorescue']) ||
      (isset($this->message['is_autorescue']) && $this->message['is_autorescue']);
  }

  public function isGravyPaypal(): bool {
    return ($this->getGateway() === 'gravy' && $this->message['payment_method'] === 'paypal');
  }

  /**
   * Get the reference associated with the auto-rescue attempt.
   *
   * @return string|null
   */
  public function getAutoRescueReference(): ?string {
    return $this->message['rescue_reference'] ?? NULL;
  }

  /**
   * @return int
   * @throws WMFException
   * @throws \CRM_Core_Exception
   */
  public function getPaymentInstrumentID(): ?int {
    if ($this->getRecurringPriorContributionValue('id') && empty($this->message['payment_instrument_id']) && empty($this->message['payment_instrument'])) {
      // If it was not in the message we can look it up from the previous donation.
      return $this->getRecurringPriorContributionValue('payment_instrument_id');
    }

    return parent::getPaymentInstrumentID();
  }

  /**
   * @return int
   * @throws WMFException
   * @throws \CRM_Core_Exception
   */
  public function getRecurringFailCount(): int {
    try {
      $fail_count = $this->getExistingContributionRecurValue('failure_count');
      return (int) $fail_count;
    }
    catch (\CRM_Core_Exception $exception) {
      return 0;
    }
  }

  public function getCancelReason(): ?string {
    return $this->message['cancel_reason'] ?? NULL;
  }

  /**
   * Get the formatted cancel date.
   *
   * @todo Our code has handling for 'cancel' and 'cancel_date'
   * with slightly different timestamp nuance - this feels like an
   * error we should fix & consolidate.
   *
   * @return string|null
   * @throws \DateMalformedStringException
   */
  public function getCancelDate(): ?string {
    if (!empty($this->message['cancel_date'])) {
      return date('Y-m-d H:i:s', $this->message['cancel_date']);
    }
    if (!empty($this->message['cancel'])) {
      return $this->parseDateString($this->message['cancel']);
    }
    return NULL;
  }

  /**
   * Get the start date unix timestamp.
   *
   * @return null|int
   */
  public function getStartTimeStamp(): ?int {
    if (!empty($this->message['start_date'])) {
      return $this->message['start_date'];
    }
    return NULL;
  }

  /**
   * Get the create date unix timestamp.
   *
   * @return null|int
   */
  public function getCreateTimeStamp(): ?int {
    if (!empty($this->message['create_date'])) {
      return $this->message['create_date'];
    }
    return NULL;
  }

  /**
   * Get the failure_retry_date unix timestamp.
   *
   * @return null|int
   */
  public function getFailureRetryTimeStamp(): ?int {
    if (!empty($this->message['failure_retry_date'])) {
      return $this->message['failure_retry_date'];
    }
    return NULL;
  }

  /**
   * Get formatted start date.
   *
   * @return string|null
   */
  public function getStartDate (): ?string {
    return $this->getStartTimeStamp() ? date('Y-m-d H:i:s', $this->getStartTimeStamp()) : NULL;
  }

  /**
   * Get formatted create date.
   *
   * @return string|null
   */
  public function getCreateDate (): ?string {
    return $this->getCreateTimeStamp() ? date('Y-m-d H:i:s', $this->getCreateTimeStamp()) : NULL;
  }

  /**
   * Get the formatted failure retry date.
   *
   * @return string|null
   */
  public function getFailureRetryDate(): ?string {
    return $this->getFailureRetryTimeStamp() ? date('Y-m-d H:i:s', $this->getFailureRetryTimeStamp()) : NULL;
  }

  public function getInvoiceID(): ?string {
    $invoiceID = parent::getInvoiceID();
    if (!$invoiceID) {
      return NULL;
    }
    // The invoice_id column has a unique constraint.
    return $invoiceID . '|recur-' . time();
  }

}
