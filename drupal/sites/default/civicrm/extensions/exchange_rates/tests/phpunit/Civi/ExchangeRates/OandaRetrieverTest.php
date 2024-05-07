<?php
namespace Civi\ExchangeRates;

use Civi\ExchangeRates\Retriever\OandaRetriever;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group exchange_rates
 */
class OandaRetrieverTest extends TestCase implements HookInterface, TransactionalInterface {

  public function testExceptionOnBadHttpResponseCode() {
    $this->expectException('\Civi\ExchangeException\ExchangeRateUpdateException');
    $retriever = new OandaRetriever(
      function( $url, $options ) {
        return (object) array( 'code' => 404 );
      },
      'key',
      'bid'
    );

    $retriever->updateRates( array() );
  }

  public function testExceptionOnBadHttpResponseBody() {
    $this->expectException('\Civi\ExchangeException\ExchangeRateUpdateException');
    $retriever = new OandaRetriever(
      function( $url, $options ) {
        return (object) array(
          'code' => 200,
          'data' => '{this is not good JSON!}',
          'headers' => array(),
        );
      },
      'key',
      'bid'
    );
    $retriever->updateRates( array() );
  }

  public function testNormalRetrieval() {
    $that = $this;
    $retriever = new OandaRetriever(
      function( $url, $options ) use ( $that ) {
        $that->assertEquals( 'Bearer mzplx', $options['headers']['Authorization'] );
        $urlParts = parse_url( $url );
        $that->assertEquals( 'https', $urlParts['scheme'] );
        $that->assertEquals( 'www.oanda.com', $urlParts['host'] );
        $that->assertEquals( '/rates/api/v1/rates/USD.json', $urlParts['path'] );
        $jsonResponse = '{
   "base_currency" : "USD",
   "meta" : {
      "effective_params" : {
         "date" : "2014-01-01",
         "decimal_places" : "all",
         "fields" : [
            "midpoint"
         ],
         "quote_currencies" : [
            "EUR",
            "GBP"
         ]
      },
      "request_time" : "2014-03-30T19:04:25+0000",
      "skipped_currencies" : []
   },
   "quotes" : {
      "EUR" : {
         "date" : "2014-01-01T21:00:00+0000",
         "midpoint" : "0.8"
      },
      "GBP" : {
         "date" : "2014-01-01T21:00:00+0000",
         "midpoint" : "0.5"
      }
   }
}';
        return (object)array(
          'code' => 200,
          'data' => $jsonResponse,
          'headers' => array( 'x-rate-limit-remaining' => 144 )
        );
      },
      'mzplx',
      'midpoint'
    );
    $result = $retriever->updateRates( array( 'EUR', 'GBP' ) );
    $this->assertEquals( 1.25, $result->rates['EUR']['value'] );
    $this->assertEquals( 2, $result->rates['GBP']['value'] );
    $this->assertEquals( 144, $result->quotesRemaining );
  }
}
