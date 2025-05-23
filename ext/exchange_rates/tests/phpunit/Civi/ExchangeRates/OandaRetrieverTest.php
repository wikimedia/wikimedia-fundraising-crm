<?php
namespace Civi\ExchangeRates;

use Civi\ExchangeRates\Retriever\OandaRetriever;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @group exchange_rates
 */
class OandaRetrieverTest extends TestCase implements HookInterface, TransactionalInterface {

  /**
   * @var Client|(Client&\PHPUnit_Framework_MockObject_MockObject)|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $client;

  public function setUp(): void {
    parent::setUp();
    $this->client = $this->createMock('GuzzleHttp\Client');
  }

  public function testExceptionOnBadHttpResponseCode(): void {
    $this->expectException('\Civi\ExchangeRates\ExchangeRateUpdateException');
    $this->client->expects($this->once())
      ->method('get')
      ->willReturn(
        new Response(404)
      );
    $retriever = new OandaRetriever(
      $this->client,
      'key',
      'bid'
    );

    $retriever->updateRates([]);
  }

  public function testExceptionOnBadHttpResponseBody(): void {
    $this->expectException('\Civi\ExchangeRates\ExchangeRateUpdateException');
    $this->client->expects($this->once())
      ->method('get')
      ->willReturn(
        new Response(200, [], '{this is not good JSON!}')
      );
    $retriever = new OandaRetriever(
      $this->client,
      'key',
      'bid'
    );
    $retriever->updateRates([]);
  }

  public function testNormalRetrieval(): void {
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
    $this->client->expects($this->once())
      ->method('get')
      ->with(
        'https://exchange-rates-api.oanda.com/v1/rates/USD.json?fields=midpoint&decimal_places=all&quote=EUR&quote=GBP',
        [
          'headers' => [
            'Authorization' => 'Bearer mzplx',
          ],
        ]
      )
      ->willReturn(
        new Response(200, ['x-rate-limit-remaining' => 144], $jsonResponse)
      );
    $retriever = new OandaRetriever(
      $this->client,
      'mzplx',
      'midpoint'
    );
    $result = $retriever->updateRates(['EUR', 'GBP']);
    $this->assertEquals(1.25, $result->rates['EUR']['value']);
    $this->assertEquals(2, $result->rates['GBP']['value']);
    $this->assertEquals(144, $result->quotesRemaining);
  }
}
