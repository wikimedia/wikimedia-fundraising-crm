<?php

namespace Civi\Api4\Action\ExchangeRate;

use Civi;
use Civi\Api4\ExchangeRate;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\ExchangeRates\ExchangeRateUpdateException;
use Civi\ExchangeRates\Retriever\EcbRetriever;
use Civi\ExchangeRates\Retriever\OandaRetriever;
use GuzzleHttp\Client;
use SmashPig\PaymentData\ReferenceData\CurrencyRates;

/**
 * Retrieves new exchanges rates from the configured provider and stores them in the database
 */
class UpdateAll extends AbstractAction {

  /**
   * @inheritDoc
   */
  public function _run(Result $result): void {
    // Ouroboros
    $currencies = array_keys(CurrencyRates::getCurrencyRates());

    $retrievers = [];
    $oanda_key = Civi::settings()->get('exchange_rates_key_oanda') ?? '';
    $oanda_quote = Civi::settings()->get('exchange_rates_quote_oanda') ?? 'bid';
    if ($oanda_key === '') {
      Civi::log('wmf')->alert('exchange_rates: OANDA API key not set!  Will fall back to ECB');
    }
    else {
      $retrievers[] = new OandaRetriever(new Client(), $oanda_key, $oanda_quote);
    }

    $retrievers[] = new EcbRetriever(new Client());
    $result = NULL;

    foreach ($retrievers as $retriever) {
      try {
        $result = $retriever->updateRates($currencies);
        break;
      }
      catch (ExchangeRateUpdateException $ex) {
        Civi::log('wmf')->alert('exchange_rates: Exception updating rates - ' . $ex->getMessage(), [$ex]);
      }
    }
    if ($result === NULL) {
      Civi::log('wmf')->alert('exchange_rates: Could not update exchange rates from any provider!');
      return;
    }

    $date_set = FALSE;
    $last_update = Civi::settings()->get('exchange_rates_last_update_timestamp');
    foreach ($result->rates as $code => $rate) {
      ExchangeRate::create(FALSE)->setValues([
        'currency' => $code,
        'value_in_usd' => $rate['value'],
        'bank_update' => date('Y-m-d H:i:s', $rate['date']),
      ])->execute();
      if (!$date_set &&
        (!$last_update || $rate['date'] > $last_update)
      ) {
        Civi::settings()->set('exchange_rates_last_update_timestamp', $rate['date']);
        $date_set = TRUE;
      }
    }

    if ($result->quotesRemaining > -1) {
      Civi::settings()->set('exchange_rates_remaining_quotes', $result->quotesRemaining);
    }
  }

}
