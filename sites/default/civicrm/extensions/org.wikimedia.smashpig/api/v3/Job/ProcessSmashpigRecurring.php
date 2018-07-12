<?php

/**
 * Pass all due recurring contributions to the SmashPig processor
 * Note that this filename needs to be cased the way it is - if you
 * uppercase the 'p' in 'Pig', Civi's MagicFunctionProvider won't
 * find it.
 *
 * @param array $params
 *
 * @return array
 *   API result array.
 */
function civicrm_api3_job_process_smashpig_recurring($params) {
  $allowedParams = [
    'use_queue',
    'retry_delay_days',
    'max_failures',
    'catch_up_days',
    'batch_size',
  ];
  $settings = Civi::settings();
  foreach ($allowedParams as $paramName) {
    if (!isset($params[$paramName])) {
      $settingName = 'smashpig_recurring_' . $paramName;
      $params[$paramName] = $settings->get($settingName);
    }
  }
  $recurringProcessor = new CRM_Core_Payment_SmashPigRecurringProcessor(
    $params['use_queue'],
    $params['retry_delay_days'],
    $params['max_failures'],
    $params['catch_up_days'],
    $params['batch_size']
  );
  $result = $recurringProcessor->run();
  return civicrm_api3_create_success($result, $params);
}

/**
 * Recurring payment charge job parameters.
 *
 * @param array $params
 */
function _civicrm_api3_job_process_smashpig_recurring_spec(&$params) {
  $params['use_queue']['title'] = ts('Send donations to queue');
  $params['retry_delay_days']['title'] = ts('Days to wait before retrying failed charge');
  $params['max_failures']['title'] = ts('Number of failures at which we stop retrying');
  $params['catch_up_days']['title'] = ts('Number of days in the past to look for charges due');
  $params['batch_size']['title'] = ts('Batch size');
}
