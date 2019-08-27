<?php
use CRM_Targetsmart_ExtensionUtil as E;

use League\Csv\Reader;
use League\Csv\Statement;

/**
 * TargetSmart.Import API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_target_smart_import_spec(&$spec) {
  $spec['csv']['api.required'] = 1;
  $spec['offset']['api.required'] = 1;
  $spec['batch_size'] = [
    'title' => E::ts('Number to parse per batch'),
    'api.default' => 1000,
  ];
}

/**
 * TargetSmart.Import API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \League\Csv\Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \CRM_Core_Exception
 *
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 */
function civicrm_api3_target_smart_import($params) {
  $reader = Reader::createFromPath($params['csv'])->setDelimiter("\t")->setHeaderOffset(0);
  $stmt = (new Statement())
    ->offset($params['offset'])
    ->limit($params['batch_size']);
  $records = $stmt->process($reader);
  $importer = new CRM_Targetsmart_ImportWrapper();
  $importer->setHeaders($reader->getHeader());

  try {
    foreach ($records as $record) {
      $importer->importRow($record);
    }
  }
  catch (Exception $e) {
    throw new CRM_Core_Exception($e->getMessage() . ' tada ' . print_r($record, TRUE));
  }
  return civicrm_api3_create_success(1);
}
