<?php

namespace Civi\WMFQueueMessage;

use Civi\API\Exception\UnauthorizedException;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use Civi\Api4\TransactionLog;

class AuditMessage extends DonationMessage {

  /**
   * WMF Settlement message.
   *
   * @var array{
   *    gateway: string,
   *    audit_file_gateway: string,
   *    backend_processor: string,
   *    backend_processor_txn_id: string,
   *    backend_processor_parent_id: string,
   *    backend_processor_refund_id: string,
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
   *    settled_currency: string,
   *    gross_currency: string,
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
  private array $transactionDetails;

  /**
   * Original contribution in recurring series.
   *
   * Only loaded if required.
   *
   * @var array|null
   */
  private ?array $firstRecurringContribution;

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
   * @return array{
   *   contribution_id: int,
   *   parent_contribution_id: int,
   *   settled_currency: string,
   *   settled_date: int,
   *   gateway: string,
   *   gateway_account: string,
   *   gateway_txn_id: string,
   *   gateway_parent_id: string,
   *   gateway_refund_id: string,
   *   type: string,
   *   backend_processor_parent_id: string,
   *   backend_processor_refund_id: string,
   *   original_total_amount: float,
   *   original_net_amount: float,
   *   original_fee_amount: float,
   *   settled_net_amount: float,
   *   settled_fee_amount: float,
   *   settled_total_amount: float,
   *   payment_method: string,
   *   payment_submethod: string,
   *   date: int,
   * }
   * @throws \CRM_Core_Exception
   */
  public function normalize(): array {
    $message = $this->message;
    $message['contribution_id'] = $this->getExistingContributionID();
    $message['parent_contribution_id'] = $this->getParentContributionID();
    // Do not populate this unless we know it is settled.
    $message['settled_currency'] = $this->getSettlementCurrency();
    $message['settled_date'] = $this->getSettlementTimeStamp();
    $message['gateway'] = $this->getGateway();
    $message['gateway_txn_id'] = $this->getGatewayTxnId();
    $message['backend_processor'] = $this->getBackendProcessor();
    $message['backend_processor_txn_id'] = $this->getBackendProcessorTxnID();
    if ($this->message['settlement_batch_reference'] ?? NULL) {
      $message['settlement_batch_reference'] = $this->getSettlementBatchReference();
    }
    if ($this->isNegative()) {
      $message['gateway_parent_id'] = $this->getGatewayParentTxnID();
      $message['gateway_refund_id'] = $this->getGatewayRefundID();
    }
    else {
      $message['order_id'] = $this->getOrderID();
    }
    $message['contribution_tracking_id'] = $this->getContributionTrackingID();
    if (!$this->getExistingContributionID()) {
      $message['transaction_details'] = $this->getTransactionDetails();
    }
    if (!$this->getExistingContributionID() && $message['contribution_tracking_id']) {
      $message['contribution_tracking'] = ContributionTracking::get(FALSE)
        ->addWhere('id', '=', $message['contribution_tracking_id'])
        ->execute()->first();
    }
    return $message;
  }

  /**
   * Get the contribution tracking ID if it already exists.
   *
   * @return int|null
   * @throws \CRM_Core_Exception
   */
  public function getContributionTrackingID(): ?int {
    $id = parent::getContributionTrackingID();
    if (!$id) {
      $tracking = $this->getTransactionDetails();
      $id = $tracking['message']['contribution_tracking_id'] ?? NULL;
    }
    return $id;
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
      $this->existingContribution = [];
      if ($this->getPaymentOrchestratorReconciliationReference()) {
        $this->existingContribution = Contribution::get(FALSE)
          ->addSelect('contribution_status_id:name', 'fee_amount', 'contribution_extra.settlement_date')
          ->addWhere('contribution_extra.payment_orchestrator_reconciliation_id', '=', $this->getPaymentOrchestratorReconciliationReference())
          ->addWhere('contribution_extra.gateway', '=', $this->getGateway())
          ->execute()->first() ?? [];
      }
      $isGravy = FALSE;
      if (empty($this->existingContribution) && $this->getBackendProcessorTxnID() && $this->getParentTransactionGateway() === 'gravy' && $this->getBackEndProcessor()) {
        $isGravy = TRUE;
        // Looking at a gravy transaction in the Adyen file?
        $this->existingContribution = Contribution::get(FALSE)
          ->addSelect('contribution_status_id:name', 'fee_amount', 'contribution_extra.settlement_date')
          ->addWhere('contribution_extra.backend_processor', '=', $this->getBackendProcessor())
          ->addWhere('contribution_extra.backend_processor_txn_id', '=', $this->getBackendProcessorTxnID())
          ->execute()->first() ?? [];
      }
      if (empty($this->existingContribution) && $this->getGatewayParentTxnID()) {
        $this->existingContribution = Contribution::get(FALSE)
          ->addSelect('contribution_status_id:name', 'fee_amount', 'contribution_extra.settlement_date')
          ->addWhere('contribution_extra.gateway', '=', $this->getParentTransactionGateway())
          ->addWhere('contribution_extra.gateway_txn_id', '=', $this->getGatewayParentTxnID())
          ->execute()->first() ?? [];
      }
    }
    if (!$this->existingContribution) {
      static $isFirst = TRUE;
      if ($isFirst) {
        \Civi::log('wmf')->info("contribution not found using contribution_extra.gateway {gateway} and gateway_txn_id {gateway_txn_id}\n", [
            'gateway' => $this->getGateway(),
            'gateway_txn_id' => $this->getGatewayParentTxnID(),
            'backend_processor' => $this->getBackEndProcessor(),
            'backend_txn_id' => $this->getBackendProcessorTxnID(),
            'is_gravy' => $isGravy,
          ] + $this->message
        );
      }
      $isFirst = FALSE;
    }

    return $this->existingContribution ?: NULL;
  }

  public function isSubsequentRecurring(): bool {
    if ($this->getContributionRecurID()) {
      return TRUE;
    }
    $orderParts = explode('.', $this->getOrderID());
    if (($orderParts[1] ?? 0) <= 1) {
      return FALSE;
    }
    return !empty($this->getFirstRecurringContribution());
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

  public function getPaymentOrchestratorReconciliationReference(): ? string {
    return $this->message['payment_orchestrator_reconciliation_id'] ?? NULL;
  }

  /**
   *
   */
  public function getOrderID(): ?string {
    $value = NULL;
    if (!empty($this->message['invoice_id'])) {
      $value = $this->message['invoice_id'];
    }
    elseif (!empty($this->message['order_id'])) {
      $value = $this->message['order_id'];
    }
    $check = explode('.', $value);
    if (!is_numeric($check[0])) {
      // Might be a Gravy reference - do a look up.
      $transaction = $this->getTransactionDetails();
      if (!empty($transaction['order_id'])) {
        $value = $transaction['order_id'];
      }
    }
    return $value;
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

  public function getTransactionType(): string {
    $value = $this->getAuditMessageType();
    if ($value === 'refund') {
      return 'refunded';
    }
    return $value;
  }

  /**
   * Get the audit method type.
   *
   * This is used for cli statistics output and may not be meaningful in other contexts.
   *
   * @return string
   */
  public function getAuditMessageType(): string {
    $type = $this->message['type'] ?? 'settled';
    if ($type === 'donations' || $type === 'recurring' || $type === 'recurring-modify') {
      // It seems type could be one of these others here from fundraise up (the others are unset).
      // It might be nice to switch from main to donations but for now ...
      $type = 'settled';
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

  public function getGateway(): string {
    $gateway = $this->getParentTransactionGateway();
    if ($gateway === 'gravy' && $this->isChargeback()) {
      // For chargebacks we need to use the backend processor details.
      // This scenario only occurs with Gravy + adyen.
      return $this->message['backend_processor'];
    }
    return $gateway;
  }

  public function getParentTransactionGateway(): string {
    return trim($this->message['gateway']);
  }

  /**
   * @return ?string
   */
  public function getSettlementBatchReference(): ?string {
    if (empty($this->message['settlement_batch_reference'])) {
      return NULL;
    }
    return $this->getAuditFileGateway() . '_' . ($this->message['settlement_batch_reference'] ?? '')  . '_' .  $this->getSettlementCurrency();
  }

  public function getAuditFileGateway(): string {
    return $this->message['audit_file_gateway'] ?? '';
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  public function getTransactionDetails(): ?array {
    if (!isset($this->transactionDetails)) {
      $this->transactionDetails = [];
      $transactionDetails = (array)TransactionLog::get(FALSE)
        ->addWhere('gateway_txn_id', '=', $this->getGatewayTxnID())
        ->addWhere('gateway', '=', $this->getGateway())
        ->execute();
      foreach ($transactionDetails as $transactionDetail) {
        if ($this->getBackendProcessorTxnID() === ($transactionDetail['message']['backend_processor_txn_id'] ?? FALSE)
          // Only checking isNegative here because I haven't fully worked
          // through the negative transactions & want to just
          // double check we are always loading the right one.
          || (!$this->getBackendProcessorTxnID() && !$this->isNegative())
        ) {
          $this->transactionDetails = $transactionDetail;
          break;
        }
      }
      if (empty($this->transactionDetails) && $this->isSubsequentRecurring()) {
        // Let's make them up based on the first in the sequence.
        $contribution = $this->getFirstRecurringContribution();
        if ($contribution) {
          $contributionTrackingID = explode('.', $this->getOrderID())[0];
          $this->transactionDetails = [
            'gateway' => $this->getGateway(),
            'gateway_txn_id' => $this->getGatewayTxnID(),
            'message' => [
              'contact_id' => $contribution['contact_id'],
              'contribution_tracking_id' => $contributionTrackingID,
              'contribution_recur_id' => $contribution['contribution_recur_id'],
            ],
          ];
        }
      }
    }
    return empty($this->transactionDetails) ? NULL : $this->transactionDetails;
  }

  /**
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getFirstRecurringContribution(): array {
    if (!isset($this->firstRecurringContribution)) {
      $contributionTrackingID = explode('.', $this->getOrderID())[0];
      $this->firstRecurringContribution = Contribution::get(FALSE)
        ->addWhere('invoice_id', 'LIKE', ($contributionTrackingID . '.%|recur%'))
        ->addOrderBy('id')
        ->addSelect('contact_id', 'contribution_recur_id')
        ->setLimit(1)
        ->execute()->first() ?? [];
    }
    return $this->firstRecurringContribution;
  }

}
