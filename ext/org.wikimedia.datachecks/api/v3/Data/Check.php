<?php

/**
 * Run data checks.
 *
 * @param $params
 * @return array
 */
function civicrm_api3_data_check($params) {
  $dataChecks = datachecks_civicrm_data_fix_get_options();
  if (!empty($params['check'])) {
    $dataChecks = array_intersect_key($dataChecks, array_flip((array) $params['check']));
  }
  $result = array();
  foreach ($dataChecks as $dataCheck) {
    $checkObject = new $dataCheck['class']();
    $result[$dataCheck['name']] = $checkObject->check();
  }
  return civicrm_api3_create_success($result);
}

/**
 * Spec for data check action.
 *
 * @param array $params
 */
function _civicrm_api3_data_check_spec(&$params) {
  $params['check'] = array(
    'options' => datachecks_civicrm_data_get_option_pairs(),
    'name' => 'check',
    'type' => CRM_Utils_Type::T_STRING,
    'description' => ts('Specify the check to run'),
  );
}
