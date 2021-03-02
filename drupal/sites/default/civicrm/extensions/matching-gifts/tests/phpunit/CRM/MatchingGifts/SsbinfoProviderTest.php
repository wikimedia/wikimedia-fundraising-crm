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
    $this->provider = new CRM_MatchingGifts_SsbinfoProvider([
      'api_key' => 'blahDeBlah',
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

  public function testFetchOneCategory() {
    $this->setUpMockResponse([
      file_get_contents(__DIR__ . '/Responses/searchResult01.json'),
      file_get_contents(__DIR__ . '/Responses/detail01.json'),
      file_get_contents(__DIR__ . '/Responses/detail02.json'),
    ]);

    $result = $this->provider->fetchMatchingGiftPolicies([
      'matchedCategories' => [
        'educational_services',
      ]
    ]);

    $this->assertEquals(3, count($this->requests));
    $searchRequest = $this->requests[0]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_name_search_list_result/?' .
      'key=blahDeBlah&format=json&parent_only=yes&educational_services=yes',
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

    $this->assertCount(2, $result);
    $this->assertEquals(
      'Yoyodyne Corporation',
      $result[12340000]['name_from_matching_gift_db']
    );
    $this->assertEquals(
      'Advanced Idea Mechanics',
      $result[56780404]['name_from_matching_gift_db']
    );
  }

  public function testFetchMultipleCategories() {
    $this->setUpMockResponse([
      file_get_contents(__DIR__ . '/Responses/searchResult01.json'),
      file_get_contents(__DIR__ . '/Responses/searchResult02.json'),
      file_get_contents(__DIR__ . '/Responses/detail01.json'),
      file_get_contents(__DIR__ . '/Responses/detail02.json'),
      file_get_contents(__DIR__ . '/Responses/detail03.json'),
    ]);

    $result = $this->provider->fetchMatchingGiftPolicies([
      'matchedCategories' => [
        'educational_services',
        'zoos',
      ]
    ]);

    $this->assertEquals(5, count($this->requests));
    $searchRequest1 = $this->requests[0]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_name_search_list_result/?' .
      'key=blahDeBlah&format=json&parent_only=yes&educational_services=yes',
      (string) $searchRequest1->getUri()
    );

    $searchRequest2 = $this->requests[1]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_name_search_list_result/?' .
      'key=blahDeBlah&format=json&parent_only=yes&zoos=yes',
      (string) $searchRequest2->getUri()
    );

    $detailsRequest1 = $this->requests[2]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_details_by_id/12340000/?' .
      'key=blahDeBlah&format=json',
      (string) $detailsRequest1->getUri()
    );

    $detailsRequest2 = $this->requests[3]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_details_by_id/56780404/?' .
      'key=blahDeBlah&format=json',
      (string) $detailsRequest2->getUri()
    );

    $detailsRequest3 = $this->requests[4]['request'];
    $this->assertEquals(
      'https://gpc.matchinggifts.com/api/V2/company_details_by_id/75751100/?' .
      'key=blahDeBlah&format=json',
      (string) $detailsRequest3->getUri()
    );

    $this->assertCount(3, $result);
    $this->assertEquals(
      [
        'matching_gifts_provider_id' => '12340000',
        'name_from_matching_gift_db' => 'Yoyodyne Corporation',
        'matching_gifts_provider_info_url' =>
          'https://javamatch.matchinggifts.com/search/companyprofile/wikimedia_iframe/2222',
        'guide_url' => 'https://example.com/yoyodyne/matchingpolicy.pdf',
        'online_form_url' => 'https://yoyodyne.yourcause.com/auth',
        'minimum_gift_matched_usd' => 25,
        'match_policy_last_updated' => '2019-10-31',
        'subsidiaries' => '["Yoyodyne Aerospace Company","Yoyodyne Mortgage","Yoyodyne Defense Logistics"]'
      ],
      $result[12340000]
    );
    $this->assertEquals(
      [
        'matching_gifts_provider_id' => '56780404',
        'name_from_matching_gift_db' => 'Advanced Idea Mechanics',
        'matching_gifts_provider_info_url' =>
          'https://javamatch.matchinggifts.com/search/companyprofile/wikimedia_iframe/5555',
        'guide_url' => 'https://example.com/advancedideamechanics/matchingpolicy.pdf',
        'online_form_url' => 'https://advideamech.benevity.com/',
        'minimum_gift_matched_usd' => 25,
        'match_policy_last_updated' => '2018-01-04',
        'subsidiaries' => '["Targo Corporation","International Data and Control","Cadenza Industries","Koenig and Strey","Pacific Vista Laboratories","Omnitech"]'
      ],
      $result[56780404]
    );
    $this->assertEquals(
      [
        'matching_gifts_provider_id' => '75751100',
        'name_from_matching_gift_db' => 'Aperture Science, Inc.',
        'matching_gifts_provider_info_url' =>
          'https://javamatch.matchinggifts.com/search/companyprofile/wikimedia_iframe/7777',
        'guide_url' => 'https://example.com/aperturescience/matchingpolicy.pdf',
        'online_form_url' => 'https://aperture.benevity.com/',
        'minimum_gift_matched_usd' => 35,
        'match_policy_last_updated' => '2018-08-24',
        'subsidiaries' => '["Aperture Laboratories","Aperture Fixtures","Aperture Enrichment Centers"]'
      ],
      $result[75751100]
    );
  }
}
