<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFQueueMessage\SettleMessage;
use CRM_SmashPig_ContextWrapper;

/**
 * Settle transaction.
 *
 * @method $this setValues(array $values)
 */
class Settle extends AbstractAction {

  /**
   * Settlement message.
   *
   * @var array
   */
  protected $values;

  /**
   * This function updates the settled transaction with new fee & currency conversion data.
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $message = new SettleMessage($this->values);
    // Existing contribution_extra fields - these currently do not have data
    // and some could be removed but they are available for this use.
    // Existing thinking is settlement_date is the date settled to the processor
    // and deposit date is settled to the bank
    // We may not always track deposit date but when deposited in EUR it will
    // be our final calculation date.
    // **settlement_date**
    // gateway_date
    // settlement_usd
    // **settlement_batch_number**
    // deposit_date
    // deposit_usd
    // deposit_currency
    $values = [
      'contribution_extra.settlement_date' => $message->getSettledDate(),
      'contribution_extra.settlement_batch_number' => $message->getSettlementBatchReference(),
      'contribution_extra.settlement_currency' => $message->getSettlementCurrency(),
    ];
    if ($message->getSettlementCurrency() === 'USD') {
      $values['fee_amount'] = $message->getSettledFeeAmountRounded();
      $values['total_amount'] = $message->getSettledAmountRounded();
    }
    else {
      $values['contribution_extra.deposit_currency'] = $message->getSettlementCurrency();
      // Here we need to do a final conversion to get fee_amount & USD total
      // based on our best numbers?
      // @todo
    }
    $contribution = Contribution::update(FALSE)
      ->addWhere('contribution_extra.gateway', '=', $message->getGateway())
      ->addWhere('contribution_extra.gateway_txn_id', '=', $message->getGatewayTxnID())
      ->setValues($values)
      ->execute()->first();
    $result[] = $contribution;
  }

  public static function fields(): array {
    $message = new SettleMessage([]);
    return $message->getFields();
  }

}
