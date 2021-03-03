<?php

/**
 * @param $params
 */
function _civicrm_api3_matching_gift_policies_fetch_spec(&$params) {
  $params['provider']['api.default'] = 'ssbinfo';
}

/**
 * @param array $params
 * @return array API result descriptor
 */
function civicrm_api3_matching_gift_policies_fetch($params) {
  $providerName = $params['provider'];
  $provider = CRM_MatchingGifts_ProviderFactory::getProvider($providerName);
  $fetchParams = $params + CRM_MatchingGifts_ProviderFactory::getFetchDefaults(
    $providerName
  );
  $policies = $provider->fetchMatchingGiftPolicies($fetchParams);
  return civicrm_api3_create_success($policies);
}
