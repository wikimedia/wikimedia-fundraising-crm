<?php

interface CRM_MatchingGifts_ProviderInterface {

  /**
   * @param array $fetchParams optional keys 'lastUpdated' and 'name'
   *
   * @return array of companies with policies
   */
  public function fetchMatchingGiftPolicies($fetchParams);
}
