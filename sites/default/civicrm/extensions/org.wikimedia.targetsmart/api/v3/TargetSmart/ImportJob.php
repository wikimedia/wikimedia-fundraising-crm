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
  $spec['identifier'] = ['title' => ts('Identifier for job - 1 to 8'), 'type' => CRM_Utils_Type::T_INT];
  $spec['mapping_name'] = [
    'title' => E::ts('Import name'),
    'api.default' => '2019_targetsmart_bulkimport',
  ];
  $spec['add_to_group_name'] = [
    'title' => E::ts('Add contacts to group (name)'),
    'api.default' => '2019_targetsmart_bulkimport',
  ];
  $spec['null_rows_at_end_count'] = [
    'title' => E::ts('Number of "nulls" columns to add at the end to blank out data'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 0,
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
  $offset = Civi::settings()->get('targetsmart_progress' . $params['identifier']) ?? 0;
  $result = civicrm_api3('TargetSmart', 'import', [
    'csv' => $params['csv'],
    'batch_size' => $params['batch_size'],
    'offset' => $offset,
    'mapping_name' => $params['mapping_name'],
    'add_to_group_name' => $params['add_to_group_name'],
    'null_rows_at_end_count' => $params['null_rows_at_end_count'],
  ]);
  Civi::settings()->set('targetsmart_progress' . $params['identifier'], $offset + $params['batch_size']);
  return $result;
}
