<?php

interface CRM_MatchingGifts_ProviderInterface {

  /**
   * @param array $fetchParams optional keys 'lastUpdated' and 'name'
   *
   * @return array of companies with policies
   */
  public function fetchMatchingGiftPolicies(array $fetchParams): array;

  public function getSearchResults(array $searchParams): array;

  public function getPolicyDetails(string $companyId): array;

  public function getName(): string;
}
