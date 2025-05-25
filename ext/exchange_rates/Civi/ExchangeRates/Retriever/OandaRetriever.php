<?php

namespace Civi\ExchangeRates\Retriever;

use Civi\ExchangeRates\ExchangeRateUpdateException;
use Civi\ExchangeRates\UpdateResult;
use GuzzleHttp\Client;
use InvalidArgumentException;

class OandaRetriever extends ExchangeRateRetriever {

  protected $key;

  protected $quote;

  protected $fields;

  protected $endpoint = 'https://exchange-rates-api.oanda.com';

  /**
   * @param Client $client function to retrieve quotes
   * @param string $key OANDA API key
   * @param string $quote which data point to request
   */
  public function __construct(Client $client, string $key, string $quote) {
    parent::__construct($client);
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

    $response = $this->client->get(
      $url,
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->key,
        ],
      ]
    );
    if ($response->getStatusCode() != 200) {
      $msg = "OANDA API endpoint returned code {$response->getStatusCode()} for request URL $url.";
      if (property_exists($response, 'data')) {
        $msg .= "  Full response data: {$response->data}";
      }
      throw new ExchangeRateUpdateException($msg);
    }
    $result = new UpdateResult();
    if (array_key_exists('x-rate-limit-remaining', $response->getHeaders())) {
      $remaining = $response->getHeaders()['x-rate-limit-remaining'];
      if (is_array($remaining)) {
        $remaining = $remaining[0];
      }
      if (is_numeric($remaining)) {
        $result->quotesRemaining = (int) $remaining;
      }
      else {
        \Civi::log('wmf')->info('exchange-rates: Got weird x-rate-limit-remaining header: {remaining}', ['remaining' => $remaining]);
      }
    }
    $json = json_decode($response->getBody());
    if ($json === NULL) {
      throw new ExchangeRateUpdateException("OANDA response was null or invalid JSON.  Data: {$response->getBody()}");
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
