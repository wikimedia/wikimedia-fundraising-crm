<?php

namespace Civi\ExchangeRates\Retriever;

use GuzzleHttp\Client;

abstract class ExchangeRateRetriever {
  protected Client $client;

  /**
   * @param Client $client - either Guzzle Http Client or a mock
   */
  public function __construct(Client $client) {
    $this->client = $client;
  }

  /**
   * Retrieve updated rates using $this->httpRequester
   * @param array $currencies - list of currency codes to update
   * @param \DateTime $date - retrieve rates for this date.  If omitted, get latest rates.
   * @return \Civi\ExchangeRates\UpdateResult
   */
  abstract function updateRates($currencies, $date = null);
}
