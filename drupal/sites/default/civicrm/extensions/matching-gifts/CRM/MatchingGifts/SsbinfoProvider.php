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

  public function getName(): string {
    return 'ssbinfo';
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
   * @return array keys are identifiers from the matching gift provider, and
   *  values are arrays with keys for each of the custom fields in the
   *  matching_gift_policies group except for suppress_from_employer_field:
   *  matching_gifts_provider_id, matching_gifts_provider_info_url,
   *  name_from_matching_gift_db, guide_url, online_form_url
   *  minimum_gift_matched_usd, match_policy_last_updated, and subsidiaries
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function fetchMatchingGiftPolicies(array $fetchParams): array {
    $searchResults = $this->getSearchResults($fetchParams);
    $policies = [];
    foreach ($searchResults as $companyId => $searchResult) {
      $policies[$companyId] = $this->getPolicyDetails($companyId);
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
   * @return array with keys for each of the custom fields in the
   *  matching_gift_policies group except for suppress_from_employer_field:
   *  matching_gifts_provider_id, matching_gifts_provider_info_url,
   *  name_from_matching_gift_db, guide_url, online_form_url
   *  minimum_gift_matched_usd, match_policy_last_updated, and subsidiaries
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getPolicyDetails(string $companyId): array {
    $url = self::BASE_URL . 'company_details_by_id/' . $companyId . '/?' .
      http_build_query($this->getBaseParams());
    $response = self::getClient()->request('GET', $url);
    return self::normalizeResponse(json_decode($response->getBody(), true));
  }

  protected function searchByCategory(array $queryData, $category): array {
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
      $keyedResults[$rawResult['company_id']] = [
        'matching_gifts_provider_id' => $rawResult['company_id'],
        'name_from_matching_gift_db' => $rawResult['name'],
        'match_policy_last_updated' => self::normalizeSsbDate(
          $rawResult['last_updated']
        )
      ];
    }
    return $keyedResults;
  }

  protected static function normalizeResponse($rawResponse) {
    $minAmount = null;
    foreach($rawResponse['giftratios'] as $ratio) {
      if ($minAmount == null || $minAmount > (float)$ratio['min_amt']) {
        $minAmount = $ratio['min_amt'];
      }
    }
    $lastUpdated = self::normalizeSsbDate($rawResponse['last_updated']);
    $normalized = [
      'matching_gifts_provider_id' => $rawResponse['company_id'],
      // FIXME link has 'wikimedia' in it, use some kind of setting?
      // Also see this suggestion to host the page on our own domain: https://phabricator.wikimedia.org/T352898
      'matching_gifts_provider_info_url' => 'https://matchinggifts.com/wikimedia_iframe',
      'name_from_matching_gift_db' => $rawResponse['name'],
      'minimum_gift_matched_usd' => $minAmount,
      'match_policy_last_updated' => $lastUpdated,
      'guide_url' => '',
      'online_form_url' => '',
      'subsidiaries' => json_encode($rawResponse['subsidiaries'])
    ];
    if (!empty($rawResponse['online_resources'])) {
      $oRes = $rawResponse['online_resources'][0];
      $normalized['guide_url'] = $oRes['guideurl'];
      $normalized['online_form_url'] = $oRes['online_formurl'];
    }
    return $normalized;
  }

  protected static function normalizeSsbDate($usaDateString) {
    // Transform MM/DD/YYYY to non-ambiguous date format
    $lastUpdateYear = substr($usaDateString, 6, 4);
    $lastUpdateMonth = substr($usaDateString, 0, 2);
    $lastUpdateDay = substr($usaDateString, 3, 2);
    return "$lastUpdateYear-$lastUpdateMonth-$lastUpdateDay";
  }
}
