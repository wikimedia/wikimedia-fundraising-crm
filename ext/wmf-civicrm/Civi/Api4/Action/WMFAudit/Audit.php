<?php

namespace Civi\Api4\Action\WMFAudit;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\GrantTransaction;
use Civi\Api4\SettlementTransaction;
use Civi\Api4\WMFAudit;
use Civi\WMFQueueMessage\AuditMessage;
use SmashPig\Core\DataStores\QueueWrapper;

/**
 * Settle transaction.
 *
 * @method $this setValues(array $values)
 * @method $this setProcessSettlement(string $actionToTake)
 * @method $this setIsSaveSettlementTransaction(bool $isSaveSettlementTransaction)
 */
class Audit extends AbstractAction {

  /**
   * Settlement message.
   *
   * @var array
   */
  protected array $values = [];

  /**
   * Settlement processing mode.
   *
   * queue|now
   *
   * @var string|null
   */
  protected ?string $processSettlement = NULL;

  /**
   * Is save settlement transaction.
   *
   * The settlement transaction is a load of data saved to the settlement_transaction
   * table for the purposes of validation / debugging. It might be dropped in the medium term.
   *
   * @var bool
   */
  protected bool $isSaveSettlementTransaction = FALSE;

  /**
   * This function updates the settled transaction with new fee & currency conversion data.
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $message = new AuditMessage($this->values);
    $isMissing = !$message->getExistingContributionID();
    if ($message->isPaypalGrant()) {
      GrantTransaction::save(FALSE)
        ->addRecord([
          'grant_provider' => $message->getGrantProvider(),
          'order_id' => $message->getOrderID(),
          'gateway_txn_id' => $message->getGatewayTxnID(),
          'gateway' => $message->getGateway(),
          'audit_file_gateway' => $message->getAuditFileGateway(),
          'date' => $message->getDate(),
          'backend_processor' => $message->getBackEndProcessor(),
          'backend_txn_id' => $message->getBackendProcessorTxnID(),
          'settlement_batch_reference' => $message->getSettlementBatchReference(),
          'settled_date' => $message->getSettledDate(),
          'settled_currency' => $message->getSettlementCurrency(),
          'settled_total_amount' => $message->getSettledAmountRounded(),
          'settled_net_amount' => $message->getSettledNetAmountRounded(),
          'settled_fee_amount' => $message->getSettledFeeAmountRounded(),
          'payment_method' => $message->getPaymentMethod(),
        ])->setMatch(['gateway', 'gateway_txn_id'])->execute();
    }

    elseif ($this->processSettlement) {
      // For now let's start tracking the messages...
      // Next will be to settle them.
      $record = $message->normalize();
      $record['date'] = date('Ymdhis', $record['date']);
      $record['settled_date'] = date('Y-m-d H:i:s T', $record['settled_date']);
      if ($record['gateway'] === 'gravy') {
        // This is very much tbd - but for adyen audit files we want to match
        // this field...
        $record['audit_file_gateway_txn_id'] = $record['backend_processor_txn_id'];
      }
      $this->saveSettlementTransaction($record, $message);

      if (!$isMissing && !empty($record['settlement_batch_reference'])
        && !$message->isSettled()
        && !$message->isFeeRow()
        && !$message->isAggregateRow()
      ) {
        $contribution = $message->getExistingContribution();
        $values = [
          'gateway' => $record['gateway'],
          'gateway_txn_id' => $record['gateway_txn_id'],
          'contribution_id' => $message->getExistingContributionID(),
          'settled_date' => $record['settled_date'],
          'settled_currency' => $record['settled_currency'],
          'settlement_batch_reference' => $record['settlement_batch_reference'],
          'settled_total_amount' => $record['settled_total_amount'],
          'settled_fee_amount' => $record['settled_fee_amount'],
        ];
        $isNegative = $record['settled_total_amount'] < 0;
        if (
          ($isNegative && $contribution['contribution_settlement.settlement_batch_reversal_reference'] === $record['settlement_batch_reference'])
          ||
          (!$isNegative && $contribution['contribution_settlement.settlement_batch_reference'] === $record['settlement_batch_reference'])
        ) {
          // Do nothing - it is settled.
        }
        elseif ($this->processSettlement === 'queue') {
          QueueWrapper::push('settle', $values, TRUE);
        }
        else {
          WMFAudit::settle(FALSE)
            ->setValues($values)->execute();
        }
      }
    }
    // also @todo if we are unable to find the extra data then queue to (e.g) a missing
    // queue - this would allow us to process each audit file only once & the transactions would
    // be 'in the system' from then on until resolved. I guess the argument for the files is that
    // if they do not get processed for any reason the file sits there until they are truly processed....
    $record = [
      'is_negative' => $message->isNegative(),
      'message' => $message->normalize(),
      'payment_method' => $message->getPaymentMethod(),
      // Caller still expects the ambiguous 'main'.
      'audit_message_type' => $message->getAuditMessageType() === 'settled' ? 'main' : $message->getAuditMessageType(),
      'is_missing' => $isMissing,
      'is_settled' => $message->isSettled(),
    ];
    $result[] = $record;
  }

  public static function fields(): array {
    $message = new AuditMessage([]);
    return $message->getFields();
  }

  /**
   * Save settlement transaction.
   *
   * This is something we are saving while we work through getting the audit right
   * to help us find issues. We might not keep it long-term. Alternatively we could
   * keep it & process out of it rather than queueing... e.g load rows to
   * settle by sql rather than Redis.
   *
   * @param array $record
   * @param AuditMessage $message
   * @return void
   * @throws \Brick\Money\Exception\MoneyMismatchException
   * @throws \Brick\Money\Exception\UnknownCurrencyException
   * @throws \CRM_Core_Exception
   */
  public function saveSettlementTransaction(array $record, AuditMessage $message): void {
    if (!$this->isSaveSettlementTransaction) {
      return;
    }
    // Fetch first & only write if create / change needed.
    $transaction = SettlementTransaction::get(FALSE)
      // We don't check gateway here just because we are mostly recording these
      // for trouble shooting & gateway might be wrong...
      ->addWhere('gateway_txn_id', '=', $record['gateway_txn_id'])
      ->addWhere('type', '=', $message->getTransactionType())
      ->execute()->first() ?? [];

    $fields = SettlementTransaction::getFields(FALSE)->execute()->indexBy('name');
    $settlementRecord = array_intersect_key($record, (array) $fields);
    foreach ($transaction as $key => $value) {
      if (!array_key_exists($key, $settlementRecord)) {
        continue;
      }
      if ($record[$key] === $value) {
        unset($settlementRecord[$key]);
      }
      elseif ($record[$key] !== NULL && $key === 'exchange_rate' && round($value, 4) === round($record[$key], 4)) {
        unset($settlementRecord[$key]);
      }
      elseif ($record[$key] && in_array($key, ['date', 'settled_date']) && strtotime($record[$key]) === strtotime($value)) {
        unset($settlementRecord[$key]);
      }
      elseif (is_float($value)) {
        // (float) .46 does not always equal (float) .46 ....
        // I'm actually interested in passing money objects up
        // from the settlement report & around our subsystem but let's come back to that.
        // Use USD as it just checks to 2 decimal places - seems enough for this
        // rather than figuring out which field.
        $newValue = Money::of($value, 'USD', NULL, RoundingMode::HALF_UP);
        if ($newValue->compareTo($record[$key] ?: 0) === 0) {
          unset($settlementRecord[$key]);
        }
      }
      elseif ($key === 'contribution_id' && !$record[$key]) {
        // Do not overwrite contribution_id with blank in this process.
        // Probably only happening due to poor test clean up but this table
        // is primarily for troubleshooting so better not to lose this data.
        unset($settlementRecord[$key]);
      }
    }
    if ($settlementRecord) {
      $settlementRecord['id'] = $transaction['id'] ?? NULL;
      SettlementTransaction::save(FALSE)
        ->addRecord(['type' => $message->getTransactionType()] + $settlementRecord)
        ->execute();
    }
  }

}
