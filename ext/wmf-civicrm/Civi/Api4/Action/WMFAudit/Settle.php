<?php

namespace Civi\Api4\Action\WMFAudit;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Civi;
use Civi\Api4\Contribution;
use Civi\Api4\ExchangeRate;
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
    $values = [
      'contribution_settlement.settlement_date' => $message->getSettledDate(),
      'contribution_settlement.settlement_batch_reference' => $message->isReversal() ? NULL : $message->getSettlementBatchReference(),
      'contribution_settlement.settlement_batch_reversal_reference' => $message->isReversal() ? $message->getSettlementBatchReference() : NULL,
      'contribution_settlement.settlement_currency' => $message->getSettlementCurrency(),
      'contribution_settlement.settled_donation_amount' => $message->isReversal() ? NULL : $message->getSettledAmount(),
      'contribution_settlement.settled_fee_amount' => $message->isReversal() ? NULL : $message->getSettledFeeAmount(),
      'contribution_settlement.settled_reversal_amount' => $message->isReversal() ? $message->getSettledAmount() : NULL,
      'contribution_settlement.settled_fee_reversal_amount' => $message->isReversal() ? $message->getSettledFeeAmount() : NULL,

    ];
    $settlementCurrency = $values['contribution_settlement.settlement_currency'];
    // Avoid calling update if we can by checking first.
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $message->getContributionID())
      ->setSelect(array_merge(array_keys($values), ['fee_amount', 'total_amount']))
      ->execute()->first();

    if ($message->getSettlementCurrency() === 'USD' && !$message->isReversal()) {
      $values['fee_amount'] = (float) $message->getSettledFeeAmountRounded();
      $values['total_amount'] = (float) $message->getSettledAmountRounded();
    }
    elseif (!$message->isReversal()) {
      // Fill in missing fee amount. No need to alter total - we don't have more up-to-date
      // info & net should recalculate.
      if (empty($contribution['fee_amount']) && !empty($values['contribution_settlement.settled_fee_amount'])) {
        $values['fee_amount'] = ExchangeRate::convert(FALSE)
          ->setFromCurrency($settlementCurrency)
          ->setFromAmount($values['contribution_settlement.settled_fee_amount'])
          ->setTimestamp($values['contribution_settlement.settlement_date'])
          ->execute()->first()['amount'];

      }
    }

    foreach ($values as $name => $value) {
      if (!isset($contribution[$name])) {
        continue;
      }
      // Do not overwrite existing values with NULL as there might be a batch_reference
      // AND a batch_reversal_reference for a given transaction.
      if ($value === NULL
        || $contribution[$name] === $value
        || ($name === 'contribution_settlement.settlement_date' && strtotime($contribution[$name]) === strtotime($value))
      ) {
        unset($values[$name]);
      }
      elseif (is_float($value)) {
        // (float) .46 does not always equal (float) .46 ....
        // I'm actually interested in passing money objects up
        // from the settlement report & around our subsystem but let's come back to that.
        $newValue = Money::of($value, $settlementCurrency, NULL, RoundingMode::HALF_UP);
        if ($newValue->compareTo($contribution[$name]) === 0) {
          unset($values[$name]);
        }
      }
    }
    if ($values) {
      $contribution = Contribution::update(FALSE)
        ->addWhere('id', '=', $message->getContributionID())
        ->setValues($values)
        ->execute()->first();
    }
    $result[] = $contribution;
  }

  public static function fields(): array {
    $message = new SettleMessage([]);
    return $message->getFields();
  }

}
