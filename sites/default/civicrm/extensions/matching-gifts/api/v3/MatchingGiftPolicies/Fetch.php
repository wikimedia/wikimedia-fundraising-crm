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
  $settings = Civi::settings();

  $credentials = $settings->get(
    "matchinggifts.{$providerName}_credentials"
  );
  $fetchParams = $params;
  if (!isset($fetchParams['lastUpdated'])) {
    $fetchParams['lastUpdated'] = $settings->get(
      "matchinggifts.{$providerName}_last_updated"
    );
  }
  if (!isset($fetchParams['matchedCategories'])) {
    $fetchParams['matchedCategories'] = $settings->get(
      "matchinggifts.{$providerName}_matched_categories"
    );
  }
  $className = 'CRM_MatchingGifts_' . ucfirst($providerName) . 'Provider';
  $provider = new $className($credentials);
  $policies = $provider->fetchMatchingGiftPolicies($fetchParams);
  return civicrm_api3_create_success($policies);
}
