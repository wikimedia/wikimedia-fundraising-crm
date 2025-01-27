<?php

/**
 * Implementation of hook_drush_command()
 */
function civicrm_matching_gifts_employers_check_drush_command() {
  $items = [];

  $items['civicrm-matching-gifts-employers-check'] = [
    'description' => 'Check matching gifts employers data is up to date',
    'arguments' => [
      'sync-batch-size' => 'Matching Gifts Sync API call batch parameter value (defaults to 0 which is unlimited)',
    ],
    'examples' => [
      'Standard example' => 'drush civicrm-matching-gifts-employers-check',
      'Batch example' => 'drush civicrm-matching-gifts-employers-check 20',
    ],
    'aliases' => ['cvmgc'],
  ];

  return $items;
}

/**
 * Implementation of hook_drush_help().
 */
function civicrm_matching_gifts_employers_check_drush_help($section) {
  switch ($section) {
    case 'drush:civicrm-matching-gifts-employers-check':
      return dt('Check matching gifts employers data is up to date');
  }
}

/**
 * @param int $syncBatchSize
 *
 * @throws \CRM_Core_Exception
 * @throws \Civi\WMFException\WMFException
 */
function drush_civicrm_matching_gifts_employers_check($syncBatchSize = 0) {
  civicrm_initialize();

  \Civi\Api4\MatchingGift::verifyEmployerFile(FALSE)
    ->setLimit($syncBatchSize)
    ->execute();

  drush_print('Matching Gifts employers data check complete');
}
