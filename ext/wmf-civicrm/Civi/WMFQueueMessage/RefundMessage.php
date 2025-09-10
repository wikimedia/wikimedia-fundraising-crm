<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Contribution;
use Civi\WMFException\WMFException;

/**
 * Refund Message Class.
 *
 * The goal of this class (& other message classes) is to interpret the incoming message
 * such that the Consumer class is responsible for processing & the message class handles
 * interpretation.
 *
 * It may make sense for this to extend Donation Message but let's start
 * with only what we need.
 */
class RefundMessage extends Message {
  /**
   * Refund Message.
   *
   * @var array{
   *   gateway_parent_id: string,
   *   parent_contribution_id: int,
   *   type: string,
   *  }
   */
  protected array $message;

  /**
   * @throws \Civi\WMFException\WMFException
   */
  public function getContributionStatus(): string {
    $validTypes = [
      'refund' => 'Refunded',
      'chargeback' => 'Chargeback',
      'cancel' => 'Cancelled',
      // from the audit processor
      'reversal' => 'Chargeback',
      // raw IPN code
      'admin_fraud_reversal' => 'Chargeback',
    ];

    if (!array_key_exists($this->message['type'], $validTypes)) {
      throw new WMFException(WMFException::IMPORT_CONTRIB, "Unknown refund type '{$this->message['type']}'");
    }
    return $validTypes[$this->message['type']];
  }

  /**
   * Validate the message
   *
   * @return void
   * @throws \Civi\WMFException\WMFException|\CRM_Core_Exception
   */
  public function validate(): void {
    if (empty($this->message['gateway_parent_id']) && empty($this->message['parent_contribution_id'])) {
      throw new WMFException(WMFException::CIVI_REQ_FIELD, 'parent_contribution_id or parent_txn_id required');
    }
    if (empty($this->message['original_currency']) && empty($this->message['gross_currency'])) {
      throw new WMFException(WMFException::CIVI_REQ_FIELD, 'original_currency (recommended) or gross_currency (deprecated) required');
    }
  }

  /**
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getOriginalContribution(): array {
    if (!empty($this->message['parent_contribution_id'])) {
      return Contribution::get(FALSE)
        ->addWhere('id', '=', $this->message['parent_contribution_id'])
        ->execute()->single();
    }
    // @todo add functions ->getOriginalContributionID() and `->getOriginalContributionValue()`
    // similar to getRecurringPriorContributionValue on the RecurringQueue class.
    $originalContribution = Contribution::get(FALSE)
      ->addWhere('contribution_extra.gateway', '=', $this->getGateway())
      ->addWhere('contribution_extra.gateway_txn_id', '=', $this->message['gateway_parent_id'])
      ->execute()->first();
    // Fall back to searching by invoice ID, generally for Ingenico recurring
    if (!$originalContribution && !empty($this->message['invoice_id'])) {
      $originalContribution = Contribution::get(FALSE)
        ->addClause(
          'OR',
          ['invoice_id', '=', $this->message['invoice_id']],
          // For recurring payments, we sometimes append a | and a random number after the invoice ID
          ['invoice_id', 'LIKE', $this->message['invoice_id'] . '|%']
        )
        ->execute()->first();
    }

    if ($this->isPaypal() && !$originalContribution) {
      /**
       * Refunds raised by Paypal do not indicate whether the initial
       * payment was taken using the paypal express checkout (paypal_ec) integration or
       * the legacy paypal integration (paypal). We try to work this out by checking for
       * the presence of specific values in messages sent over, but it appears this
       * isn't watertight as we've seen refunds failing due to incorrect mappings
       * on some occasions. To mitigate this we now fall back to the alternative
       * gateway if no match is found for the gateway supplied.
       */
      $originalContribution = Contribution::get(FALSE)
        ->addWhere('contribution_extra.gateway', 'IN', ['paypal', 'paypal_ec'])
        ->addWhere('contribution_extra.gateway_txn_id', '=', $this->message['gateway_parent_id'])
        ->execute()->first();
    }
    return (array) $originalContribution;
  }

  /**
   * Is this a donation reversal?
   *
   * @return bool
   */
  public function isReversal(): bool {
    return TRUE;
  }

  /**
   * Get original currency.
   *
   * In this context the original currency is the currency in which the
   * donation is paid back to the donor (which may or may not be the
   * same as what they paid in - but probably is).
   *
   * @return string
   */
  public function getOriginalCurrency(): string {
    return $this->message['original_currency'] ?? $this->message['gross_currency'];
  }

}
