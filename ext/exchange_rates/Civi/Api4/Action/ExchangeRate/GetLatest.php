<?php

namespace Civi\Api4\Action\ExchangeRate;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Core\Exception\DBQueryException;
use CRM_Core_DAO;

/**
 * Gets the latest rates for all currencies from the database
 */
class GetLatest extends AbstractAction {

  /**
   * @inheritDoc
   * @throws DBQueryException
   */
  public function _run(Result $result): void {
    $rates = CRM_Core_DAO::executeQuery(
      'SELECT currency,
    (
        SELECT value_in_usd
        FROM civicrm_exchange_rate inside
        WHERE inside.currency = outside.currency
        ORDER BY bank_update DESC
        LIMIT 1
    ) AS latest_value

FROM civicrm_exchange_rate outside
GROUP BY currency
ORDER BY currency'
    );
    while ($rates->fetch()) {
      $result[] = ['currency' => $rates->currency, 'valueInUSD' => $rates->latest_value];
    }
  }

}
