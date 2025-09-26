<?php

namespace Civi\Api4\Action\ExchangeRate;

use Civi\Api4\Contribution;
use Civi\Api4\ExchangeRate;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Exception;

/**
 * Uses stored exchange rates to convert a contribution from another currency to USD.
 * This will write to the database.
 *
 * @method $this setContributionID(int $contributionID)
 * @method $this setTargetCurrency(string $targetCurrency)
 */
class ConvertContribution extends AbstractAction {

  /**
   * ID of the contribution to convert
   * @required
   * @var int
   */
  protected int $contributionID;

  /**
   * @var string
   */
  protected string $targetCurrency = 'USD';

  /**
   * @inheritDoc
   * @throws Exception
   */
  public function _run(Result $result): void {
    $contribution = Contribution::get(FALSE)
      ->addWhere('id', '=', $this->contributionID)
      ->execute()->single();

    $params = [
      'currency' => $this->targetCurrency,
    ];
    foreach (['total_amount', 'fee_amount', 'net_amount'] as $field) {
      $params[$field] = ExchangeRate::convert(FALSE)
        ->setTimestamp($contribution['receive_date'])
        ->setFromAmount($contribution[$field])
        ->setFromCurrency($contribution['currency'])
        ->execute()->single()['amount'];
    }

    Contribution::update(FALSE)
      ->addWhere('id', '=', $this->contributionID)
      ->setValues($params)
      ->execute();

    $result[$this->contributionID] = $params;
  }

}
