<?php

namespace Civi\ExchangeRates\Retriever;

use Civi\ExchangeException\ExchangeRateUpdateException;
use Civi\ExchangeRates\UpdateResult;
use InvalidArgumentException;

class OandaRetriever extends ExchangeRateRetriever {

  protected $key;

  protected $quote;

  protected $fields;

  protected $endpoint = 'https://www.oanda.com/rates/api';

  /**
   * @param callable $httpRequester function to retrieve quotes
   * @param string $key OANDA API key
   * @param string $quote which data point to request
   */
  public function __construct($httpRequester, $key, $quote) {
    parent::__construct($httpRequester);
    $this->key = $key;
    $quoteFields = [
      'low_bid' => 'lows',
      'high_bid' => 'highs',
      'bid' => 'averages',
      'midpoint' => 'midpoint',
    ];
    if (!array_key_exists($quote, $quoteFields)) {
      throw new InvalidArgumentException("Cannot request data point $quote!");
    }
    $this->quote = $quote;
    $this->fields = $quoteFields[$quote];
  }

  public function updateRates($currencies, $date = NULL) {
    $url = $this->endpoint .
      '/v1/rates/USD.json' .
      '?fields=' . $this->fields .
      '&decimal_places=all' .
      '&quote=' . implode('&quote=', $currencies);
    if ($date) {
      $url .= '&date=' . $date->format('Y-m-d');
    }

    $response = call_user_func(
      $this->httpRequester,
      $url,
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->key,
        ],
      ]
    );
    if ($response->code != 200) {
      $msg = "OANDA API endpoint returned code {$response->code} for request URL $url.";
      if (property_exists($response, 'data')) {
        $msg .= "  Full response data: {$response->data}";
      }
      throw new ExchangeRateUpdateException($msg);
    }
    $result = new UpdateResult();
    if (array_key_exists('x-rate-limit-remaining', $response->headers)) {
      $remaining = $response->headers['x-rate-limit-remaining'];
      if (is_numeric($remaining)) {
        $result->quotesRemaining = (int) $remaining;
      }
      else {
        \Civi::log('wmf')->info('exchange-rates: Got weird x-rate-limit-remaining header: {remaining}', ['remaining' => $remaining]);
      }
    }
    $json = json_decode($response->data);
    if ($json === NULL) {
      throw new ExchangeRateUpdateException("OANDA response was null or invalid JSON.  Data: {$response->data}");
    }
    $valueProp = $this->quote;
    foreach ($json->quotes as $code => $quote) {
      $result->rates[$code] = [
        'value' => 1 / $quote->$valueProp,
        'date' => strtotime($quote->date),
      ];
    }
    return $result;
  }

}
