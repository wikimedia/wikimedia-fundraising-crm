<?php

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class CRM_MatchingGifts_SsbinfoProvider implements CRM_MatchingGifts_ProviderInterface{

  const BASE_URL = 'https://gpc.matchinggifts.com/api/V2/';

  protected $credentials;

  /**
   * @var ClientInterface
   */
  protected static $client;

  public function __construct(array $credentials) {
    $this->credentials = $credentials;
  }

  /**
   * Get HTTP client.
   *
   * @return ClientInterface
   */
  public static function getClient(): ClientInterface {
    if (self::$client === null) {
      self::setClient(new Client());
    }
    return self::$client;
  }

  /**
   * Set HTTP client.
   *
   * @param ClientInterface $client
   */
  public static function setClient(ClientInterface $client) {
    self::$client = $client;
  }

  /**
   * @param array $fetchParams
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function fetchMatchingGiftPolicies(array $fetchParams): array {
    $searchResults = $this->getSearchResults($fetchParams);
    $policies = [];
    foreach ($searchResults as $companyId => $searchResult) {
      $policies[$companyId] = $this->getPolicyDetails($searchResult['company_id']);
    }
    return $policies;
  }

  protected function getBaseParams(): array {
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
  public function getSearchResults(array $searchParams): array {
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
  public function getPolicyDetails(string $companyId): array {
    $url = self::BASE_URL . 'company_details_by_id/' . $companyId . '/?' .
      http_build_query($this->getBaseParams());
    $response = self::getClient()->request('GET', $url);
    return json_decode($response->getBody(), true);
  }

  protected function searchByCategory(array $queryData, string $category): array {
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
