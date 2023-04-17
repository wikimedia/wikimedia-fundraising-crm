<?php
// phpcs:disable
use CRM_Wmf_ExtensionUtil as E;
// phpcs:enable

class CRM_Wmf_BAO_ContributionTracking extends CRM_Wmf_DAO_ContributionTracking {

  /**
   * Create a new ContributionTracking based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Wmf_DAO_ContributionTracking|NULL
   */
  /*
  public static function create($params) {
    $className = 'CRM_Wmf_DAO_ContributionTracking';
    $entityName = 'ContributionTracking';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  }
  */

}
