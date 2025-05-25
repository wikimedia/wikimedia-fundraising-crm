<?php

namespace Civi\ExchangeRates\Retriever;

use Civi\ExchangeRates\ExchangeRateUpdateException;
use Civi\ExchangeRates\UpdateResult;

class EcbRetriever extends ExchangeRateRetriever {

  public function updateRates($currencies, $date = null) {
    if ($date !== null) {
      throw new ExchangeRateUpdateException('ECB does not provide historical exchange rates');
    }

    $url = 'http://www.ecb.int/stats/eurofxref/eurofxref-daily.xml';

    // Retrieve and parse the XML results
    $response = $this->client->get($url);
    $xml = $response->getBody();
    $p = xml_parser_create();
    $results = array();
    $index = array();
    xml_parse_into_struct($p, $xml, $results, $index);
    xml_parser_free($p);

    // Get date and base USD rate
    $usdBase = 0;
    $date = '';
    foreach ($index['CUBE'] as $valIndex) {
      $current = $results[$valIndex];
      if ($current['attributes']['CURRENCY'] == 'USD' && isset($current['attributes']['RATE'])) {
        $usdBase = $current['attributes']['RATE'];
      }
      if (isset($current['attributes']['TIME'])) {
        $date = $current['attributes']['TIME'];
      }
    }
    $bankUpdateTimestamp = strtotime($date . ' 00:00:00 GMT');
    $result = new UpdateResult();

    // Table is based on EUR, so must insert manually if we actually got anything
    if ($usdBase !== 0) {
      $result->rates['EUR'] = array(
        'value' => $usdBase,
        'date' => $bankUpdateTimestamp
      );

      // Calculate and insert remaining rates
      foreach ($index['CUBE'] as $valIndex) {
        $current = $results[$valIndex];
        if (isset($current['attributes']['CURRENCY']) && isset($current['attributes']['RATE'])) {
          $result->rates[$current['attributes']['CURRENCY']] = array(
            'value' => $usdBase / $current['attributes']['RATE'],
            'date' => $bankUpdateTimestamp
          );
        }
      }
    }
    return $result;
  }
}
