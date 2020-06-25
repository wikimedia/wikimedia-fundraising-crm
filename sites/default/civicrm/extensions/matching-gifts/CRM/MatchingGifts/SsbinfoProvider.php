<?php

use GuzzleHttp\Client;

class CRM_MatchingGifts_SsbinfoProvider implements CRM_MatchingGifts_ProviderInterface{

  const BASE_URL = 'https://gpc.matchinggifts.com/api/V2/';

  protected $credentials;

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected static $client;

  public function __construct($credentials) {
    $this->credentials = $credentials;
  }

  /**
   * Get HTTP client.
   *
   * @return \GuzzleHttp\ClientInterface
   */
  public static function getClient() {
    return self::$client;
  }

  /**
   * Set HTTP client.
   *
   * @param \GuzzleHttp\ClientInterface $client
   */
  public static function setClient($client) {
    self::$client = $client;
  }

  /**
   * @param array $fetchParams
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function fetchMatchingGiftPolicies($fetchParams): array {
    if (!self::getClient()) {
      self::setClient(new Client());
    }
    $searchResults = $this->getSearchResults($fetchParams);
    $policies = [];
    foreach ($searchResults as $companyId => $searchResult) {
      $policies[$companyId] = $this->getPolicyDetails($searchResult['company_id']);
    }
    return $policies;
  }

  protected function getBaseParams() {
    return [
      'key' => $this->credentials['api_key'],
      'format' => 'json'
    ];
  }

  /**
   * @param array $searchParams
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getSearchResults($searchParams): array {
    $queryData = $this->getBaseParams() + [
      'parent_only' => 'yes',
    ];
    if (!empty($searchParams['lastUpdated'])) {
      $formattedDate = (new DateTime($searchParams['lastUpdated']))->format('m/d/Y');
      $queryData['last_updated_after'] = $formattedDate;
    }
    if (!empty($searchParams['name'])) {
      $queryData['name'] = $searchParams['name'];
    }
    if (empty($searchParams['matchedCategories'])) {
      $searchResult = $this->searchByCategory($queryData, null);
    } else {
      $searchResult = [];
      foreach($searchParams['matchedCategories'] as $category) {
        $searchResult += $this->searchByCategory($queryData, $category);
      }
    }
    return $searchResult;
  }

  /**
   * @param string $companyId
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getPolicyDetails($companyId) {
    $url = self::BASE_URL . 'company_details_by_id/' . $companyId . '/?' .
      http_build_query($this->getBaseParams());
    $response = self::getClient()->request('GET', $url);
    return json_decode($response->getBody(), true);
  }

  protected function searchByCategory(array $queryData, $category) {
    if ($category) {
      $queryData[$category] = 'yes';
    }
    $url = self::BASE_URL . 'company_name_search_list_result/?' .
      http_build_query($queryData);
    $response = self::getClient()->request('GET', $url);
    // TODO error handling for non-200 response code
    $rawResults = json_decode($response->getBody(), true);
    $keyedResults = [];
    foreach($rawResults as $rawResult) {
      $keyedResults[$rawResult['company_id']] = $rawResult;
    }
    return $keyedResults;
  }
}
