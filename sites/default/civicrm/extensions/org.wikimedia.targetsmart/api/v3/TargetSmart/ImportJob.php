<?php
use CRM_Targetsmart_ExtensionUtil as E;

/**
 * TargetSmart.ImportJob API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_target_smart_import_job_spec(&$spec) {
  $spec['csv']['api.required'] = 1;
  $spec['batch_size'] = [
    'title' => E::ts('Number to parse per batch'),
    'api.default' => 1000,
  ];
}

/**
 * TargetSmart.ImportJob API
 *
 * @param array $params
 *
 * @return array API result descriptor
 *
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_target_smart_import_job($params) {
  $offset = Civi::settings()->get('targetsmart_progress') ?? 0;
  $result = civicrm_api3('TargetSmart', 'import', [
    'csv' => $params['csv'],
    'batch_size' => $params['batch_size'],
    'offset' => $offset,
  ]);
  Civi::settings()->set('targetsmart_progress', $offset + $params['batch_size']);
  return $result;
}
