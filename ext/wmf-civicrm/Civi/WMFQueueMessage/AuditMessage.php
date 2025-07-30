<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\ContributionTracking;

class AuditMessage extends DonationMessage {

  /**
   * WMF Settlement message.
   *
   * @var array{
   *    gateway: string,
   *    gateway_txn_id: string,
   *    gateway_account: string,
   *    gateway_parent_id: string,
   *    invoice_id: string,
   *    contribution_tracking_id: string,
   *    payment_method: string,
   *    payment_submethod: string,
   *    modification_reference: string,
   *    currency: string,
   *    gross: float,
   *    fee: float,
   *    settled_gross: float,
   *    settled_currency: float,
   *    settled_fee: float,
   *    settled_date: string,
   *    date: string,
   *    currency: string,
   *    gross: float|string|int,
   *    settled_currency: string,
   *    fee: string,
   *    type: string
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
    if ($this->isNegative()) {
      $message['gateway_parent_id'] = $this->getGatewayParentTxnID();
      $message['gateway_refund_id'] = $this->getGatewayRefundID();
    }
    return $message;
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

}
