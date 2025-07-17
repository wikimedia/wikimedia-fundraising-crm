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
    $contribution = Contribution::update(FALSE)
      ->addWhere('contribution_extra.gateway', '=', $message->getGateway())
      ->addWhere('contribution_extra.gateway_txn_id', '=', $message->getGatewayTxnID())
      ->setValues([
        'fee_amount' => $message->getSettledFeeAmountRounded(),
        'total_amount' => $message->getSettledAmountRounded(),
        'contribution_extra.settlement_date' => $message->getSettledDate(),
      ])
      ->execute()->first();
    $result[] = $contribution;
  }

  public static function fields(): array {
    $message = new SettleMessage([]);
    return $message->getFields();
  }

}
