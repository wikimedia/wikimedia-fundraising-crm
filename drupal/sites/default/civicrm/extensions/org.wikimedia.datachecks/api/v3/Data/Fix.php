<?php

/**
 * Data fix api.
 *
 * @param array $params
 * @return array
 */
function civicrm_api3_data_fix($params) {
  $options = datachecks_civicrm_data_fix_get_options();
  if (!empty($params['check'])) {
    $options = array_intersect_key($options, array_flip((array) $params['check']));
  }
  $fix_options = CRM_Utils_Array::value('fix_options', $params, array());
  if (!is_array($fix_options)) {
    $optionItems = explode(',', $fix_options);
    $fix_options = array();
    foreach ($optionItems as $optionItem) {
      $parts = explode(':', $optionItem);
      $fix_options[$parts[0]] = array($parts[1] => TRUE);
    }

  }
  $result = array();
  foreach ($options as $option) {
    $checkObject = new $option['class'];
    $result[$option['name']] = $checkObject->fix(CRM_Utils_Array::value($option['name'], $fix_options));
  }
  return civicrm_api3_create_success($result);
}

/**
 * Metadata for fix function.
 *
 * @param array $params
 */
function _civicrm_api3_data_fix_spec(&$params) {
  $params['fix'] = array(
    'options' => datachecks_civicrm_data_get_option_pairs(),
    'name' => 'fix',
    'type' => CRM_Utils_Type::T_STRING,
    'description' => ts('Specify the fix to run'),
  );
   /*
   @todo - this is part of the goal of fixing the duplicates.
  foreach (datachecks_civicrm_data_fix_get_options() as $fix) {
    if (!empty($fix['fix_options'])) {
      $params[$fix['name']] = array(

      );
    }
  }
   */
  $params['fix_options'] = array(
    'name' => 'fix_options',
    'type' => CRM_Utils_Type::T_STRING,
    'description' => ts('Any options specific to the fix'),
  );
}
