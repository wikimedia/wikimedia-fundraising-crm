<?php

use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/**
 * @group MatchingGifts
 */
class CRM_MatchingGifts_SsbInfoProviderTest extends PHPUnit\Framework\TestCase
  implements \Civi\Test\HeadlessInterface {

  /**
   * @var \CRM_MatchingGifts_SsbinfoProvider
   */
  protected $provider;

  /**
   * @var array
   */
  protected $requests;

  public function setUp() {
    parent::setUp();
    civicrm_initialize();
    $this->setUpMockResponse([
      file_get_contents(__DIR__ . '/Responses/searchResult.json'),
      file_get_contents(__DIR__ . '/Responses/detail01.json'),
      file_get_contents(__DIR__ . '/Responses/detail02.json'),
    ]);
    $this->provider = new CRM_MatchingGifts_SsbinfoProvider([
      'api_key' => 'blahDeBlah'
    ]);
  }

  /**
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  protected function setUpMockResponse($responseBodies) {
    $this->requests = [];
    $history = Middleware::history($this->requests);
    $responses = [];
    foreach ($responseBodies as $responseBody) {
      $responses[] = new Response(200, [], $responseBody);
    }
    $mock = new MockHandler($responses);
    $handler = HandlerStack::create($mock);
    $handler->push($history);
    $httpClient = new Client(['handler' => $handler]);
    CRM_MatchingGifts_SsbinfoProvider::setClient($httpClient);
  }

  public function testFetch() {
    $this->provider->fetchMatchingGiftPolicies([]);

    $this->assertEquals(3, count($this->requests));
    $searchRequest = $this->requests[0]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_name_search_list_result/?' .
      'key=blahDeBlah&format=json&parent_only=yes',
      (string)$searchRequest->getUri()
    );

    $detailsRequest1 = $this->requests[1]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_details_by_id/12340000/?' .
      'key=blahDeBlah&format=json',
      (string)$detailsRequest1->getUri()
    );

    $detailsRequest2 = $this->requests[2]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_details_by_id/56780404/?' .
      'key=blahDeBlah&format=json',
      (string)$detailsRequest2->getUri()
    );
  }

}
