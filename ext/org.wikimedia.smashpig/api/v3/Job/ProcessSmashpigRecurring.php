<?php

use Civi\Helper\CadenceValidator;

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
    'retry_cadence',
    'catch_up_days',
    'batch_size',
    'charge_descriptor',
    'time_limit_in_seconds',
  ];
  if (isset($params['retry_cadence'])) {
    $cadenceError = CadenceValidator::hasErrors($params['retry_cadence']);
    if ($cadenceError) {
      return civicrm_api3_create_error($cadenceError);
    }
  }
  $settings = Civi::settings();
  foreach ($allowedParams as $paramName) {
    if (!isset($params[$paramName])) {
      $settingName = 'smashpig_recurring_' . $paramName;
      $params[$paramName] = $settings->get($settingName);
    }
  }
  $recurringProcessor = new CRM_Core_Payment_SmashPigRecurringProcessor(
    $params['use_queue'],
    explode(',', $params['retry_cadence']),
    $params['catch_up_days'],
    $params['batch_size'],
    $params['charge_descriptor'],
    $params['time_limit_in_seconds'],
    $params['min_recur_id'] ?? 0,
    $params['max_recur_id'] ?? 0
  );
  $result = $recurringProcessor->run(
    $params['contribution_recur_id'] ?? NULL
  );
  return civicrm_api3_create_success($result, $params);
}

/**
 * Recurring payment charge job parameters.
 *
 * @param array $params
 */
function _civicrm_api3_job_process_smashpig_recurring_spec(&$params) {
  $params['use_queue']['title'] = ts('Send donations to queue');
  $params['retry_cadence']['title'] = ts('Comma-separated list of days after failure on which to retry failed charge');
  $params['catch_up_days']['title'] = ts('Number of days in the past to look for charges due');
  $params['batch_size']['title'] = ts('Batch size');
  $params['charge_descriptor']['title'] = ts('Soft descriptor for recurring charge');
  $params['contribution_recur_id'] = [
    'title' => 'Contribution Recur ID (for testing)',
    'description' => ts('When specified, only charge this one recur record'),
    'type' => CRM_Utils_Type::T_INT,
  ];
  $params['min_recur_id']['title'] = ts('Minimum (inclusive) contribution_recur.id to charge, for segmenting jobs');
  $params['max_recur_id']['title'] = ts('Maximum (inclusive) contribution_recur.id to charge, for segmenting jobs');
}
