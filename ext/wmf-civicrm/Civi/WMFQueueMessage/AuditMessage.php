<?php

namespace Civi\WMFQueueMessage;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\TransactionLog;
use Civi\WMFTransaction;

class AuditMessage extends DonationMessage {

  /**
   * WMF Audit message incoming from our smashpig audit reconciliation framework.
   *
   * @var array{
   *    gateway: string,
   *    audit_file_gateway: string,
   *    auth_id: string,
   *    backend_processor: string,
   *    backend_processor_txn_id: string,
   *    backend_processor_parent_id: string,
   *    backend_processor_reversal_id: string,
   *    capture_id: string,
   *    gateway_txn_id: string,
   *    gateway_refund_id: string,
   *    gateway_account: string,
   *    gateway_status: string,
   *    gateway_parent_id: string,
   *    grant_provider: string,
   *    invoice_id: string,
   *    contribution_tracking_id: string,
   *    payment_method: string,
   *    payment_submethod: string,
   *    payment_orchestrator_reconciliation_id: string,
   *    payment_orchestrator_reversal_id: string,
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
   *    external_identifier: string,
   *    date: string,
   *    gross: float|string|int,
   *    type: string,
   *    order_id: string,
   *    first_name: string,
   *    last_name: string,
   *    full_name: string,
   *    email: string,
   *    phone: string,
   *    country: string,
   *    postal_code: string,
   *    state_province: string,
   *    city: string,
   *    street_address: string,
   *    supplemental_address_1: string,
   *    txn_type: string,
   *    subscr_id: string,
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

  private ?array $parentContribution = NULL;
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
    $this->isReversal() ||
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
   * Is the message advising a reversal.
   *
   * Generally these are more obscure than the general refund or chargebacks.
   *
   * e.g. PayPal can issue 2 chargeback-like transactions, one is a hold and
   * we call that a reversal.
   *
   * https://phabricator.wikimedia.org/T418191
   *
   * @return boolean
   */
  public function isReversal(): bool {
    return $this->getType() === 'reversal';
  }

  /**
   * Is the message advising a chargeback has been reversed.
   *
   * @return boolean
   */
  public function isChargebackReversal(): bool {
    return $this->getType() === 'chargeback_reversed';
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
   *   backend_processor_reversal_id: string,
   *   payment_orchestrator_reversal_id: string,
   *   grant_provider: string,
   *   original_total_amount: float,
   *   original_net_amount: float,
   *   original_fee_amount: float,
   *   settled_net_amount: float,
   *   settled_fee_amount: float,
   *   settled_total_amount: float,
   *   payment_method: string,
   *   payment_submethod: string,
   *   date: int,
   *   phone: string,
   * }
   * @throws \CRM_Core_Exception
   */
  public function normalize(): array {
    $message = $this->message;
    $recurID = $this->getContributionRecurID();
    if ($recurID) {
      $message['contribution_recur_id'] = $recurID;
    }
    $message['contribution_id'] = $this->getExistingContributionID();
    $message['parent_contribution_id'] = $this->getParentContributionID();
    // Do not populate this unless we know it is settled.
    $message['settled_currency'] = $this->getSettlementCurrency();
    $message['settled_date'] = $this->getSettlementTimeStamp();
    $message['gateway'] = $this->getGateway();
    $message['gateway_txn_id'] = $this->getGatewayTxnId();
    if ($this->isGravyTrustly() && $this->getContributionRecurID()) {
      // Determining that the gateway should be treated as trustly means that the
      // gravy reference might relate to the first contribution (which might or might not be linked
      // via recurring contribution record) not this one. We need to unset that
      // information. If it is missing then it will fail to create without gateway_txn_id
      // which should cause noise (good because we expect this to be there for
      // other reasons) but settle should work.
      unset($message['gateway_txn_id']);
      unset($message['payment_orchestrator_reconciliation_id']);
    }
    $message['backend_processor'] = $this->getBackendProcessor();
    $message['backend_processor_txn_id'] = $this->getBackendProcessorTxnID();
    $message['payment_method'] = $this->getPaymentMethod();
    if ($this->message['settlement_batch_reference'] ?? NULL) {
      $message['settlement_batch_reference'] = $this->getSettlementBatchReference();
    }

    if ($this->isAggregateRow() || $this->isFeeRow()) {
      $message['type'] = $this->getAuditMessageType();
      return $message;
    }
    if ($this->isNegative()) {
      $message['gateway_parent_id'] = $this->getGatewayOriginalTxnID();
      $message['gateway_refund_id'] = $this->getGatewayRefundID();
      if (!isset($message['gross']) && $this->getParentContributionID()) {
        // If we have a refund with only settled currency information we can reasonably
        // assume the amount from the contribution is what is being refunded.
        // This occurs with Stripe - there are extra steps could take to retrieve this data from
        // Stripe but the assumption feels solid enough not to do it.
        $message['gross'] = $message['contribution_extra.original_amount'] = $this->getExistingContribution()['contribution_extra.original_amount'];
        $message['currency'] = $message['contribution_extra.original_currency'] = $this->getExistingContribution()['contribution_extra.original_currency'];
      }
    }
    else {
      $message['order_id'] = $this->getOrderID();
    }
    if ($this->isReversingPriorReversal()) {
      // Maybe always but definitely here.
      $message['invoice_id'] = $this->getOrderID();
      // These are such oddities we should keep them simple.
      $message['recurring'] = FALSE;
      $message['no_thank_you'] = $this->getType();
    }
    $message['contribution_tracking_id'] = $this->getContributionTrackingID();
    if ($this->getExistingContributionID()) {
      $existingContribution = $this->getExistingContribution();
      // Overwrite gateway as we might have found a paypal vs paypal_ex switcheroo instance.
      $message['gateway'] = $existingContribution['contribution_extra.gateway'];
    }
    else {
      $message['transaction_details'] = $this->getTransactionDetails();
      // Overwrite with the value from the transaction details
      // since it might transpose paypal vs paypal_ec and we treat the one from
      // transaction details as more accurate.
      $message['gateway'] = $message['transaction_details']['gateway'] ?? $message['gateway'];
    }
    if (!$this->getExistingContributionID() && $message['contribution_tracking_id']) {
      $message['contribution_tracking'] = ContributionTracking::get(FALSE)
        ->addWhere('id', '=', $message['contribution_tracking_id'])
        ->execute()->first();
    }
    if ($this->isPaypalGrant()) {
      $message['last_name'] = '';
      $message['first_name'] = '';
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
    if ($this->isPaypalGrant()) {
      return NULL;
    }
    $id = parent::getContributionTrackingID();
    if (!$id) {
      $tracking = $this->getTransactionDetails();
      $id = $tracking['message']['contribution_tracking_id'] ?? NULL;
    }
    return is_numeric($id) ? (int) $id : NULL;
  }

  public function getParentContributionID(): ?int {
    if (!$this->isNegative()) {
      return NULL;
    }
    $existingContribution = $this->getExistingContribution();
    if (!$existingContribution && $this->parentContribution) {
      return $this->parentContribution['id'];
    }
    if (!$existingContribution && $this->getGatewayAlternateParentTxnID()) {
      $existingContribution = Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name', 'fee_amount', 'contribution_extra.settlement_date')
        ->addWhere('contribution_extra.gateway', '=', $this->getGateway())
        ->addWhere('contribution_extra.gateway_txn_id', '=', $this->getGatewayAlternateParentTxnID())
        ->execute()->first() ?? [];
    }
    if ($existingContribution && $this->isStatusChanged($existingContribution['contribution_status_id:name'])) {
      return $existingContribution['id'];
    }
    return NULL;
  }

  public function getExistingContributionID(): ?int {
    $existingContribution = $this->getExistingContribution();
    if (!$existingContribution) {
      return NULL;
    }
    if (
      $this->isNegative() &&
      // If we have a status change (ie negative transaction) and the contribution has not yet been updated to
      // that status then treat as 'missing' so it goes into the refund queue. Note it is possible
      // for a contribution to be both refunded and charged back (although hopefully in time the
      // processor will make us whole)
      $this->isStatusChanged($existingContribution['contribution_status_id:name'])
    ) {
      static $isFirstNegative = TRUE;
      if ($isFirstNegative) {
        \Civi::log('wmf')->info("contribution status difference \n existing status: {$existingContribution['contribution_status_id:name']}\n incoming status : " . $this->getMappedStatus(), [
            'gateway' => $this->getGateway(),
            'trxn_id' => WMFTransaction::from_message($this->message)->get_unique_id(),
            'gateway_txn_id' => $this->getGatewayOriginalTxnID(),
            'backend_processor' => $this->getBackEndProcessor(),
            'backend_txn_id' => $this->getBackendProcessorTxnID(),
            'existing_contribution_id' => $existingContribution['id'],
            'existing_status' => $existingContribution['contribution_status_id:name'],
            'is_chargeback' => $this->isChargeback(),
            'is_refund' => $this->isRefund(),
            'is_reversal' => $this->isReversal(),
          ] + $this->message
        );
        $isFirstNegative = FALSE;
      }
      return NULL;
    }
    return $existingContribution['id'];
  }

  protected function isStatusChanged(string $existingContributionStatus): bool {
    return $existingContributionStatus !== $this->getMappedStatus();
  }

  /**
   * Get the CiviCRM status that maps to the audit status.
   *
   * @return string
   */
  protected function getMappedStatus(): string {
    if ($this->isCancel()) {
      return 'Cancelled';
    }
    if ($this->isRefund()) {
      return 'Refunded';
    }
    if ($this->isChargeback()) {
      return 'Chargeback';
    }
    if ($this->isReversal()) {
      return 'Reversal';
    }
    if ($this->isRefundReversal()) {
      return 'refund_reversal';
    }
    if ($this->isChargebackReversal()) {
      return 'chargeback_reversal';
    }
    if ($this->isReversalReversal()) {
      return 'reversal_reversal';
    }
    return 'Completed';
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function getExistingContribution(): ?array {
    $debugInformation = [];
    if (!isset($this->existingContribution)) {
      $this->existingContribution = [];

      // See first if it is gravy trustly as the trustly ID is reliable here but the
      // gravy ID might refer to a different contribution. We can probably do some more here but
      // failing to link up is less evil than linking to the wrong one & we will be warning if that
      // happens as the batch will not close.
      $isAvoidGravyLookups = $this->isGravyTrustly() && $this->getContributionRecurID();
      if ($this->existingContribution) {
        // This could have been set during the trustly contribution recur lookup.
        return $this->existingContribution;
      }

      $selectFields = $this->getContributionSelectFields();
      if ($this->isRefund() || $this->isChargeback() || $this->isReversal()
        || $this->isReversingPriorReversal()
      ) {
        // First check whether a standalone negative or reversal-reversing contribution already exists.
        $this->existingContribution = $this->lookupByTrxnIdAndStatus() ?: [];
      }
      if (!$this->isReversingPriorReversal()) {
        if (!$isAvoidGravyLookups && empty($this->existingContribution) && $this->getPaymentOrchestratorReconciliationReference()) {
          $this->existingContribution = $this->lookupByPaymentOrchestratorReconciliationId() ?? [];
        }
        if (empty($this->existingContribution) && $this->getBackendProcessorTxnID() && $this->getParentTransactionGateway() === 'gravy' && $this->getBackEndProcessor()) {
          // Looking at a gravy transaction in the Adyen file or a recurring in the trustly file.
          $this->existingContribution = $this->lookupByBackendProcessorTrxnId() ?? [];
        }
        $orderID = $this->getOrderID();
        $gatewayOperator = $this->isPaypal() || $this->isPaypalGrant() ? 'LIKE' : '=';
        $gatewayString = $this->isPaypal() || $this->isPaypalGrant() ?'paypal%' : $this->getParentTransactionGateway();
        if ($this->isDlocal()) {
          // Try order ID lookup first for dlocal - this will only take place if there is one - in which
          // case it is more reliable.
          $this->lookupByOrderId();
        }
        if (!$isAvoidGravyLookups && empty($this->existingContribution) && $this->getGatewayOriginalTxnID()) {
          $this->existingContribution = Contribution::get(FALSE)
            ->setSelect($selectFields)
            ->addWhere('financial_type_id:name', 'NOT IN', $this->getReversalReversalFinancialTypeNames())
            ->addWhere('contribution_extra.gateway', $gatewayOperator, $gatewayString)
            ->addWhere('contribution_extra.gateway_txn_id', '=', $this->getGatewayOriginalTxnID())
            ->execute()->first() ?? [];
        }
        if (!$this->existingContribution) {
          $this->lookupByOrderId();
        }

        // Do not accept existing contribution with mismatch against backend_processor_txn_id.
        if ($this->existingContribution &&
          (
            (
              !$this->isNegative()
              && !empty($this->message['backend_processor_txn_id'])
              && !empty($this->existingContribution['contribution_extra.backend_processor_txn_id'])
              && $this->message['backend_processor_txn_id'] !== $this->existingContribution['contribution_extra.backend_processor_txn_id']
            )
            ||
            (
              $this->isNegative()
              && !empty($this->message['backend_processor_reversal_id'])
              && !empty($this->existingContribution['contribution_extra.backend_processor_reversal_id'])
              && $this->message['backend_processor_reversal_id'] !== $this->existingContribution['contribution_extra.backend_processor_reversal_id']
            )
          )
        ) {
          $this->parentContribution = $this->existingContribution;
          $this->existingContribution = [];
        }
      }
    }
    if (!$this->existingContribution && !$this->isAggregateRow()) {
      static $isFirst = TRUE;
      if ($isFirst) {
        \Civi::log('wmf')->info("contribution not found using contribution_extra.gateway {gateway} and gateway_txn_id {gateway_txn_id}\n", $debugInformation + [
            'gateway' => $this->getGateway(),
            'gateway_txn_id' => $this->getGatewayOriginalTxnID(),
            'backend_processor' => $this->getBackEndProcessor(),
            'backend_txn_id' => $this->getBackendProcessorTxnID(),
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
    $orderParts = explode('.', (string) $this->getOrderID());
    if (!$this->isGravyTrustly() && (int) ($orderParts[1] ?? 0) <= 1) {
      return FALSE;
    }
    return !empty($this->getFirstRecurringContribution());
  }

  /**
   * Is this transaction reversing a prior refund, chargeback or generic reversal.
   *
   * @return bool
   */
  public function isReversingPriorReversal(): bool {
    return $this->isChargebackReversal() || $this->isRefundReversal() || $this->isReversalReversal();
  }

  /**
   * Get the original transaction ID - for refunds etc this is the original donation.
   *
   * @throws \CRM_Core_Exception
   */
  public function getGatewayOriginalTxnID(): ?string {
    if (!empty($this->message['gateway_parent_id'])) {
      return $this->message['gateway_parent_id'];
    }
    if ($this->isReversingPriorReversal()) {
      // We treat these as a new contribution on their own
      // and ignore the parent.
      return NULL;
    }
    if ($this->getGatewayTxnID()) {
      return $this->getGatewayTxnID();
    }
    // Try to reconstruct missing/false-y gateway_parent_id from ct_id.
    // This logic was in the Ingenico Audit processor but functionality-wise
    // it is generic based on the Message fields (even if it arises with Ingenico).
    // @todo - I have some real doubts about this code. It seems better to use
    // the order ID to look up the contribution - like we now do in getExistingContribution()
    // I've mitigated with the str_ends_with check...
    if (
      $this->getContributionTrackingID() && $this->getOrderID() && str_ends_with($this->getOrderID(), '.1')
    ) {
      return ContributionTracking::get(FALSE)
        ->addWhere('id', '=', $this->getContributionTrackingID())
        ->addSelect('contribution_id.contribution_extra.gateway_txn_id')
        ->execute()
        ->first()['contribution_id.contribution_extra.gateway_txn_id'] ?? NULL;
    }
    return '';
  }

  public function getGatewayTxnID(): ?string {
    $gatewayTxnID = parent::getGatewayTxnID();
    if ($this->isFeeRow()) {
      return $gatewayTxnID . ' ' . $this->getSettlementBatchReference();
    }
    return $gatewayTxnID;
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
    if (!$value) {
      return NULL;
    }
    $check = explode('.', $value);
    if ($this->isPaypal() && empty($this->message['type'])) {
      // The PayPal order IDs are not reliable.
      // A lot of them end in .1 when they are not the original.
      return NULL;
    }
    if (!is_numeric($check[0])) {
      // Might be a Gravy reference - do a look-up.
      $transaction = $this->getTransactionDetails();
      if (!empty($transaction['order_id'])) {
        $value = $transaction['order_id'];
      }
    }
    if ($this->isChargebackReversal() && !empty($value)) {
      $value .= '-cr';
    }
    if ($this->isRefundReversal() && !empty($value)) {
      $value .= '-rr';
    }
    if ($this->isReversalReversal() && !empty($value)) {
      $value .= '-rvr';
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
    return $this->getGatewayOriginalTxnID();
  }

  /**
   * Get the payment method.
   *
   * This is used for cli statistics output and may not be meaningful in other contexts.
   *
   * @return string
   */
  public function getPaymentMethod(): string {
    if ($this->isPaypalGrant()) {
      return 'Paypal Grants';
    }
    return $this->message['payment_method'] ?? '';
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
    if ($type === 'donation' || $type === 'recurring' || $type === 'recurring-modify') {
      // It seems type could be one of these others here from fundraise up (the others are unset).
      // It might be nice to switch from main to donations but for now ...
      $type = 'settled';
    }
    if ($type === 'payout') {
      return 'aggregate';
    }
    return $type;
  }

  /**
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function isSettled(): bool {
      $settledField = $this->isNegative() ? 'contribution_settlement.settlement_batch_reversal_reference' : 'contribution_settlement.settlement_batch_reference';
      return (bool) ($this->getExistingContribution()[$settledField] ?? FALSE);
  }

  public function getGateway(): string {
    $gateway = $this->getParentTransactionGateway();

    if ($gateway === 'gravy' &&
      ($this->isChargeback() && $this->getBackendProcessor() === 'adyen')
      ||
      ($this->getBackendProcessor() === 'trustly' && !empty($this->message['backend_processor_parent_id']) && empty($this->message['payment_orchestrator_reconciliation_id']))
    ) {
      // For some chargebacks and refunds we need to use the backend processor details.
      // This scenario only occurs with Gravy + adyen, except when it happens for Gravy + trustly refunds.
      return $this->message['backend_processor'];
    }

    if ($this->isPaypalGrant()) {
      return 'Paypal DAF';
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
      $gatewayOperator = $this->isPaypal() ? 'LIKE' : '=';
      $gatewayString = $this->isPaypal() ?'paypal%' : $this->getGateway();
      $transactionDetails = (array)TransactionLog::get(FALSE)
        ->addClause('OR', ['gateway_txn_id', '=', $this->getGatewayTxnID()], ['order_id', '=', $this->getInvoiceID()])
        ->addWhere('gateway', $gatewayOperator, $gatewayString)
        ->execute();

      foreach ($transactionDetails as $transactionDetail) {
        if ($this->getBackendProcessorTxnID() === ($transactionDetail['message']['backend_processor_txn_id'] ?? FALSE)
          // Only checking isNegative here because I haven't fully worked
          // through the negative transactions & want to just
          // double-check we are always loading the right one.
          || (!$this->getBackendProcessorTxnID() && !$this->isNegative())
          // If we only found one for the relevant gateway then we want to use
          // that - in practice this could be a paypal gravy that has a different
          // txn_id in our SmashPig pending / TransactionLog vs the incoming
          // but the important gravy ID matches
          || count($transactionDetails) === 1
        ) {
          $this->transactionDetails = $transactionDetail;
          break;
        }
      }
      if (!empty($transactionDetail)) {
        // We found matches for the gravy ID. None match the back end processor ID but
        // let's use what we got. https://phabricator.wikimedia.org/T415744
        $this->transactionDetails = $transactionDetail;
      }
      if (empty($this->transactionDetails)) {
        $contribution = NULL;
        if ($this->isSubsequentRecurring()) {
          // Let's make them up based on the first in the sequence.
          $contribution = $this->getFirstRecurringContribution();
        }
        else {
          $contributionTrackingID = explode('.', (string) $this->getOrderID())[0];
          if (is_numeric($contributionTrackingID)) {
            if ($this->isReversingPriorReversal() || $this->isRefund() || $this->isReversal() || $this->isChargeback()) {
              // If we are dealing with a chargeback or refund or reversal of one of them
              // then we probably only really need the contact ID to go ahead. If the transaction details
              // are missing them let's use what we have.
              $contributionTracking = ContributionTracking::get(FALSE)
                ->addWhere('id', '=', $contributionTrackingID)
                ->addSelect('contribution_id', 'contribution_id.contact_id')
                ->execute()->first();
              if ($contributionTracking && $contributionTracking['contribution_id.contact_id']) {
                $contribution = [
                  'contact_id' => $contributionTracking['contribution_id.contact_id'],
                ];
              }
            }
          }
        }
        if ($contribution) {
          $contributionTrackingID = explode('.', $this->getOrderID() ?? '')[0];
          $this->transactionDetails = [
            'gateway' => $this->getGateway(),
            'gateway_txn_id' => $this->getGatewayTxnID(),
            'message' => [
              'contact_id' => $contribution['contact_id'],
              'contribution_tracking_id' => $contributionTrackingID,
              'contribution_recur_id' => $contribution['contribution_recur_id'] ?? NULL,
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
      if ($this->getContributionRecurID()) {
        $this->firstRecurringContribution = Contribution::get(FALSE)
          ->addWhere('contribution_recur_id', '=', $this->getContributionRecurID())
          ->addOrderBy('id')
          ->addSelect('contact_id', 'contribution_recur_id')
          ->setLimit(1)
          ->execute()->first() ?? [];
      }
      else {
        $contributionTrackingID = explode('.', $this->getOrderID() ?? '')[0];
        $this->firstRecurringContribution = Contribution::get(FALSE)
          ->addWhere('invoice_id', 'LIKE', ($contributionTrackingID . '.%|recur%'))
          ->addOrderBy('id')
          ->addSelect('contact_id', 'contribution_recur_id')
          ->setLimit(1)
          ->execute()->first() ?? [];
      }
    }
    return $this->firstRecurringContribution;
  }

  /**
   * @return bool
   */
  public function isAggregateRow(): bool {
    return $this->getAuditMessageType() === 'aggregate';
  }

  public function isFeeRow(): bool {
    return $this->getAuditMessageType() === 'fee';
  }

  /**
   * @return bool|mixed
   */
  public function isPaypalGrant(): bool {
    return $this->getGrantProvider() && $this->message['audit_file_gateway'] === 'paypal';
  }

  /**
   * Is this an older Adyen transaction with the backend_gateway_txn_id holding capture rather than authorize id.
   * @return bool
   */
  public function isRequiresBackendProcessorTxnIdRepair(): bool {
    if (!$this->getGateway() === 'gravy' || !in_array($this->getBackendProcessor(), ['adyen', 'paypal'], TRUE)
      || !$this->getCaptureID() || !$this->getAuthID()
      || $this->message['type'] !== 'donation'
    ) {
      return FALSE;
    }
    if ($this->getSettlementTimeStamp() > strtotime('2026-05-01')) {
      return FALSE;
    }
    if ($this->getPaymentOrchestratorReconciliationReference() && (
      $this->getAuthID() === $this->getCaptureID()
      || $this->getBackendProcessor() === 'paypal')
    ) {
      // We don't get a capture ID here to check against - we just want to update to
      // the transaction ID - to the one from the file
      return !\CRM_Core_DAO::singleValueQuery('SELECT id
        FROM wmf_contribution_extra WHERE backend_processor_txn_id = %1
            AND backend_processor = %2', [
        1 => [$this->message['backend_processor_txn_id'], 'String'],
        2 => [$this->getBackendProcessor(), 'String'],
      ]);
    }
    return (bool) \CRM_Core_DAO::singleValueQuery('SELECT id FROM wmf_contribution_extra WHERE backend_processor_txn_id = %1 AND backend_processor = %2', [
      1 => [$this->message['capture_id'], 'String'],
      2 => [$this->getBackendProcessor(), 'String'],
    ]);
  }

  public function getAuthID(): ?string {
    return $this->message['auth_id'] ?? NULL;
  }

  public function getCaptureID(): ?string {
    return $this->message['capture_id'] ?? NULL;
  }

  public function getGrantProvider(): ?string {
    return ($this->message['grant_provider'] ?? NULL);
  }

  /**
   * Get the recurring contribution ID if it already exists.
   *
   * @return int|null
   */
  public function getContributionRecurID(): ?int {
    if (isset($this->contributionRecurID)) {
      return $this->contributionRecurID ?: NULL;
    }
    if ($this->isGravyTrustly()) {
      $this->getTrustlyRecurringContribution();
      return $this->contributionRecurID ?: NULL;
    }
    else {
      return parent::getContributionRecurID();
    }
  }

  /**
   * @return string[]
   */
  private function getContributionSelectFields(): array {
    return [
      'id',
      'contribution_status_id:name',
      'fee_amount',
      'contribution_settlement.settlement_batch_reference',
      'contribution_settlement.settlement_batch_reversal_reference',
      'contribution_extra.gateway',
      'contribution_extra.original_amount',
      'contribution_extra.original_currency',
      'contribution_recur_id',
      'contact_id',
      'invoice_id',
      'contribution_extra.backend_processor_txn_id',
      'contribution_extra.backend_processor_gateway_txn_id',
      'contribution_extra.backend_processor_reversal_id',
      'contribution_extra.payment_orchestrator_reconciliation_id',
      'contribution_extra.payment_orchestrator_reversal_id',
    ];
  }

  /**
   * Get the recurring contribution record for a trustly contribution.
   *
   * With Trustly Audit messages for subsequent recurrings we get
   * - the gravy ID that relates to the FIRST contribution. This is also
   * the trxn_id on the contribution recur
   * - Trustly IDs.
   *
   * We need to treat to unset any references to the original gravy ID in this case.
   * On the off chance they do not exist in Civi we can either
   * 1) create as a trustly transaction or
   * 2) require divine or fr-tech intervention to create as a gravy transaction.
   *
   * But, we do need to be able to match for settlement.
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  private function getTrustlyRecurringContribution(): ?array {
    $recurring = Contribution::get(FALSE)
      ->setSelect([
          'contribution_extra.gateway_txn_id',
          'contribution_extra.backend_processor_txn_id',
          'contribution_recur_id.*',
        ] + $this->getContributionSelectFields())
      ->addWhere('contribution_recur_id.trxn_id', '=', $this->message['gateway_txn_id'])
      ->addOrderBy('id')
      ->execute()->first();
    $this->contributionRecurID = $recurring['contribution_recur_id'] ?? FALSE;
    if ($recurring) {
      // Since we have loaded this we should register it, so we can lazy access it.
      $this->define('ContributionRecur', 'ContributionRecur', \CRM_Utils_Array::filterByPrefix($recurring, 'contribution_recur_id'));
      if ($this->message['backend_processor_txn_id'] === $recurring['contribution_extra.backend_processor_txn_id']) {
        $this->existingContribution = $recurring;
      }
      else {
        $this->firstRecurringContribution = $recurring;
      }
    }
    else {
      // Even if there is no linked contribution the presence of a contribution_recur_id
      // linked by trxn_id is significant in the Trustly context
      $recurring = ContributionRecur::get(FALSE)
        ->addClause('OR', ['trxn_id', '=', $this->message['gateway_txn_id']], ['trxn_id', '=', 'RECURRING GRAVY ' . $this->message['gateway_txn_id']])
        ->addOrderBy('id')
        ->execute()->first();
      $this->contributionRecurID = $recurring['id'] ?? FALSE;
    }
    return $recurring;
  }

  /**
   * Lookup contribution by Order ID.
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  protected function lookupByOrderId(): void {
    $orderID = $this->getOrderID();
    if (!$orderID || !str_contains($orderID, '.')) {
      return;
    }
    $gatewayOperator = $this->isPaypal() || $this->isPaypalGrant() ? 'LIKE' : '=';
    $gatewayString = $this->isPaypal() || $this->isPaypalGrant() ?'paypal%' : $this->getParentTransactionGateway();
    $this->existingContribution = Contribution::get(FALSE)
      ->setSelect($this->getContributionSelectFields())
      ->addWhere('financial_type_id:name', 'NOT IN', $this->getReversalReversalFinancialTypeNames())
      ->addWhere('contribution_extra.gateway', $gatewayOperator, $gatewayString)
      ->addClause('OR',
        ['invoice_id', '=', $orderID],
        ['invoice_id', 'LIKE', $orderID . '|%']
      )
      ->execute()->first() ?? [];
  }

  /**
   * Check whether a standalone contribution for the transaction has been created.
   *
   * These standalone transactions would occur when a contribution is charged back AND refunded.
   * One of these would likely later be reversed - which would also be caught here.
   *
   * Trxn ID examples
   * - CHARGEBACK_REVERSAL GRAVY e6d5ed2f-00cc-4e1f-a840-09dbc4a28df9
   * - CHARGEBACK GRAVY e6d5ed2f-00cc-4e1f-a840-09dbc4a28df9
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
 */
  private function lookupByTrxnIdAndStatus(): ?array {
    $transaction = WMFTransaction::from_message($this->message);
    $trxn_id = $transaction->get_unique_id();
    $transaction->is_recurring = TRUE;
    $trxn_id_recur = $transaction->get_unique_id();
    return Contribution::get(FALSE)
      ->setSelect($this->getContributionSelectFields())
      // Include status as otherwise we might pick up a balance transaction
      // which would have a status of completed, rather than falling through
      // to look at the main contribution record.
      // @see RefundQueueConsumer->markRefund
      // @todo - maybe give balance transactions an extra twiddle in
      // their trxn_id - if we do that this will age out...
      ->addWhere('contribution_status_id:name', '=', $this->getMappedStatus())
      ->addClause('OR', ['trxn_id', '=', $trxn_id], ['trxn_id', '=', $trxn_id_recur])
      ->execute()->first();
  }

  /**
   * Lookup using the payment orchestrator reconciliation ID.
   *
   * This is the Base62 version of the Gravy Gateway Txn ID.
   *
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  private function lookupByPaymentOrchestratorReconciliationId(): ?array {
    return Contribution::get(FALSE)
      ->setSelect($this->getContributionSelectFields())
      ->addWhere('contribution_extra.payment_orchestrator_reconciliation_id', '=', $this->getPaymentOrchestratorReconciliationReference())
      // @todo - maybe remove the gateway check in favour of returning early if the
      // payment_orchestrator_reconciliation_id is empty. Gateway hasn't turned out to be very reliable
      // when dealing with refunds when Gr4vy involved.
      ->addWhere('contribution_extra.gateway', '=', $this->getGateway())
      ->addWhere('financial_type_id:name', 'NOT IN', $this->getReversalReversalFinancialTypeNames())
      ->execute()->first();
  }

  private function getReversalReversalFinancialTypeNames(): array {
    return ['Chargeback Reversal', 'Refund Reversal', 'Reversal Reversal'];
  }

  /**
   * @return array|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function lookupByBackendProcessorTrxnId(): ?array {
    return Contribution::get(FALSE)
      ->setSelect($this->getContributionSelectFields())
      ->addWhere('contribution_extra.backend_processor', '=', $this->getBackendProcessor())
      // Try the parent ID, if provided (e.g. refund) or the backend processor txn ID.
      ->addWhere('contribution_extra.backend_processor_txn_id', '=', $this->getBackendProcessorParentTxnID() ?: $this->getBackendProcessorTxnID())
      ->addWhere('financial_type_id:name', 'NOT IN', $this->getReversalReversalFinancialTypeNames())
      ->execute()->first();
  }

}
