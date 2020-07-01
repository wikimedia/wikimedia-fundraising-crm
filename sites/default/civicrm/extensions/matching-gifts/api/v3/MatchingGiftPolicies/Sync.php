<?php

/**
 * @param $params
 */
function _civicrm_api3_matching_gift_policies_sync_spec(&$params) {
  $params['provider']['api.default'] = 'ssbinfo';
  $params['batch']['api.default'] = 250;
}

/**
 * @param array $params
 * @return array API result descriptor
 */
function civicrm_api3_matching_gift_policies_sync($params) {
  $providerName = $params['provider'];
  $provider = CRM_MatchingGifts_ProviderFactory::getProvider($providerName);
  $synchronizer = new CRM_MatchingGifts_Synchronizer($provider);
  $syncParams = $params + CRM_MatchingGifts_ProviderFactory::getFetchDefaults(
      $providerName
    );
  $policies = $synchronizer->synchronize($syncParams);
  return civicrm_api3_create_success($policies);
}
