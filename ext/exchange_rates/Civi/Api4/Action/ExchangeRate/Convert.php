<?php

namespace Civi\Api4\Action\ExchangeRate;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ExchangeRates\ExchangeRatesException;
use CRM_Core_DAO;
use CRM_ExchangeRates_BAO_ExchangeRate;
use CRM_ExchangeRates_DAO_ExchangeRate;
use CRM_ExchangeRates_ExtensionUtil as E;
use DateTime;
use Exception;
use SmashPig\PaymentData\ReferenceData\CurrencyRates;

/**
 * Uses stored exchange rates to convert an amount from another currency to USD.
 *
 * @method $this setFromAmount(string $fromAmount) set the amount in the currency to be converted (FIXME: should be float)
 * @method $this setFromCurrency(string $currency) set the currency to be converted
 * @method $this setTimestamp(string $timestamp) set the timestamp to indicate which rate to use
 */
class Convert extends AbstractAction {
  /**
   * Amount to convert, in the indicated 'from' currency (FIXME: should be float)
   * @var string
   * @required
   */
  protected string $fromAmount = '';

  /**
   * Currency to convert from
   * @var string
   * @required
   */
  protected string $fromCurrency = '';

  /**
   * Select the stored rate closest to this timestamp, defaults to 'now'
   * @var string
   */
  protected string $timestamp = '';

  /**
   * @inheritDoc
   * @throws Exception
   */
  public function _run(Result $result): void {
    $timestamp = (new DateTime($this->timestamp))->format('YmdHis');
    $rate = CRM_ExchangeRates_BAO_ExchangeRate::getFromCache($this->fromCurrency, $timestamp);
    if ($rate === NULL) {
      $rate = CRM_Core_DAO::singleValueQuery(
        'SELECT value_in_usd FROM ' . CRM_ExchangeRates_DAO_ExchangeRate::$_tableName .
        ' WHERE currency = %1 AND bank_update < %2' .
        ' ORDER BY bank_update DESC LIMIT 1',
        [1 => [$this->fromCurrency, 'String'], 2 => [$timestamp, 'Timestamp']]
      );
      if (!$rate) {
        $hardcodedRates = CurrencyRates::getCurrencyRates();
        if (array_key_exists($this->fromCurrency, $hardcodedRates)) {
          $rate = 1.0 / $hardcodedRates[$this->fromCurrency];
        }
      }
      if (!$rate) {
        throw new ExchangeRatesException(E::ts("No conversion available for currency %1", [1 => $this->fromCurrency]));
      }
      CRM_ExchangeRates_BAO_ExchangeRate::addToCache($this->fromCurrency, $timestamp, $rate);
    }

    $result[] = ['amount' => floatval($this->fromAmount) * $rate];
  }

}
