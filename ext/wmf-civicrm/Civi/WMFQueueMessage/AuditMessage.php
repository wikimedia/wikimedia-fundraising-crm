<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;

class AuditMessage extends DonationMessage {

  /**
   * WMF Settlement message.
   *
   * @var array{
   *    gateway: string,
   *    gateway_txn_id: string,
   *    gateway_refund_id: string,
   *    gateway_account: string,
   *    gateway_parent_id: string,
   *    invoice_id: string,
   *    contribution_tracking_id: string,
   *    payment_method: string,
   *    payment_submethod: string,
   *    payment_orchestrator_reconciliation_id: string,
   *    modification_reference: string,
   *    currency: string,
   *    original_currency: string,
   *    settled_currency: float,
   *    gross: float,
   *    settled_gross: float,
   *    settlement_batch_reference: string,
   *    fee: float,
   *    settled_fee_amount: float,
   *    settled_net_amount: float,
   *    settled_total_amount: float,
   *    original_net_amount: float,
   *    original_fee_amount: float,
   *    original_total_amount: float,
   *    exchange_rate: float,
   *    settled_date: string,
   *    date: string,
   *    gross: float|string|int,
   *    type: string,
   *    order_id: string,
   *    first_name: string,
   *    last_name: string,
   *    email: string,
   *    }
   */
  protected array $message;

  protected bool $isRestrictToSupportedFields = FALSE;
  protected bool $isLogUnsupportedFields = TRUE;
  protected bool $isLogUnavailableFields = TRUE;

  /**
   * Subset of the Message available fields required for this Message Type.
   *
   * @var array|string[]
   */
  protected array $requiredFields = [
    'gateway',
    'gateway_txn_id',
  ];
  private array $existingContribution;

  /**
   * Are we dealing with a message that had a currency other than our settlement currency.
   */
  public function isExchangeRateConversionRequired(): bool {
    return FALSE;
  }

  /**
   * Is this a negative payment.
   *
   * @return bool
   */
  public function isNegative(): bool {
    return $this->isRefund() ||
    $this->isChargeback() ||
    $this->isCancel();
  }

  /**
   * Is the message advising a payment refund.
   *
   * @return boolean
   */
  public function isRefund(): bool {
    return $this->getType() === 'refund';
  }

  /**
   * Is the message advising a payment chargeback.
   *
   * @return boolean
   */
  public function isChargeback(): bool {
    return $this->getType() === 'chargeback';
  }

  /**
   * Is the message advising a payment has been cancelled.
   *
   * @return boolean
   */
  public function isCancel(): bool {
    return $this->getType() === 'cancel';
  }

  public function getType(): string {
    return $this->message['type'] ?? '';
  }

  /**
   * Normalize the incoming message
   *
   * @return array
   */
  public function normalize(): array {
    $message = $this->message;
    $message['contribution_id'] = $this->getExistingContributionID();
    $message['parent_contribution_id'] = $this->getParentContributionID();
    // Do not populate this unless we know it is settled.
    $message['settled_currency'] = $this->getSettlementCurrency();
    $message['settled_date'] = $this->getSettlementTimeStamp();
    if ($this->isNegative()) {
      $message['gateway_parent_id'] = $this->getGatewayParentTxnID();
      $message['gateway_refund_id'] = $this->getGatewayRefundID();
    }
    return $message;
  }

  public function getParentContributionID(): ?int {
    if (!$this->isNegative()) {
      return NULL;
    }
    $existingContribution = $this->getExistingContribution();
    if (!$existingContribution && $this->getGatewayAlternateParentTxnID()) {
      $existingContribution = Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name', 'fee_amount', 'contribution_extra.settlement_date')
        ->addWhere('contribution_extra.gateway', '=', $this->getGateway())
        ->addWhere('contribution_extra.gateway_txn_id', '=', $this->getGatewayAlternateParentTxnID())
        ->execute()->first() ?? [];
    }
    if ($existingContribution && !in_array($existingContribution['contribution_status_id:name'], ['Cancelled', 'Chargeback', 'Refunded'])) {
      return $existingContribution['id'];
    }
    return NULL;
  }

  public function getExistingContributionID(): ?int {
    $existingContribution = $this->getExistingContribution();
    if (!$existingContribution) {
      return NULL;
    }
    if ($this->isNegative() && !in_array($existingContribution['contribution_status_id:name'], ['Cancelled', 'Chargeback', 'Refunded'])) {
      return NULL;
    }
    return $existingContribution['id'];
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  public function getExistingContribution(): ?array {
    if (!isset($this->existingContribution)) {
      $this->existingContribution = Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name', 'fee_amount', 'contribution_extra.settlement_date')
        ->addWhere('contribution_extra.gateway', '=', $this->getGateway())
        ->addWhere('contribution_extra.gateway_txn_id', '=', $this->getGatewayParentTxnID())
        ->execute()->first() ?? [];
    }

    return $this->existingContribution ?: NULL;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function getGatewayParentTxnID(): ?string {
    if (!empty($this->message['gateway_parent_id'])) {
      return $this->message['gateway_parent_id'];
    }
    if ($this->getGatewayTxnID()) {
      return $this->getGatewayTxnID();
    }
    // Try to reconstruct missing/false-y gateway_parent_id from ct_id.
    // This logic was in the Ingenico Audit processor but functionality-wise
    // it is generic based on the Message fields (even if it arises with Ingenico).
    if (
      $this->getContributionTrackingID()
    ) {
      return ContributionTracking::get(FALSE)
        ->addWhere('id', '=', $this->getContributionTrackingID())
        ->addSelect('contribution_id.contribution_extra.gateway_txn_id')
        ->execute()
        ->first()['contribution_id.contribution_extra.gateway_txn_id'] ?? NULL;
    }
    return '';
  }

  /**
   * Get alternate modification reference for gateways that return more than one.
   *
   * (looking at you Adyen T306944)
   */
  public function getGatewayAlternateParentTxnID(): ?string {
    return $this->message['modification_reference'] ?? NULL;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function getGatewayRefundID(): ?string {
    if (!empty($this->message['gateway_refund_id'])) {
      return $this->message['gateway_refund_id'];
    }
    // Handling for when it is not provided (notably Ingenico doesn't give refunds their own ID,
    // and sometimes even sends '0')
    // We'll prepend an 'RFD' in the trxn_id column later.
    return $this->getGatewayParentTxnID();
  }

  /**
   * Get the payment method.
   *
   * This is used for cli statistics output and may not be meaningful in other contexts.
   *
   * @return string
   */
  public function getPaymentMethod(): string {
    return $this->message['payment_method'] ?? 'unknown';
  }

  /**
   * Get the audit method type.
   *
   * This is used for cli statistics output and may not be meaningful in other contexts.
   *
   * @return string
   */
  public function getAuditMessageType(): string {
    $type = $this->message['type'] ?? 'main';
    if ($type === 'donations' || $type === 'recurring' || $type === 'recurring-modify') {
      // It seems type could be one of these others here from fundraise up (the others are unset).
      // It might be nice to switch from main to donations but for now ...
      $type = 'main';
    }
    return $type;
  }

  /**
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function isSettled(): bool {
    return (bool) ($this->getExistingContribution()['contribution_extra.settlement_date'] ?? FALSE);
  }

}
