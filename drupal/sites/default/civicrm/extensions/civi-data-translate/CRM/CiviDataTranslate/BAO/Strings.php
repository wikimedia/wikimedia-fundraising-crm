<?php
use CRM_CiviDataTranslate_ExtensionUtil as E;

class CRM_CiviDataTranslate_BAO_Strings extends CRM_CiviDataTranslate_DAO_Strings {

  /**
   * Create a new Strings based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_CiviDataTranslate_DAO_Strings|NULL
   *
  public static function create($params) {
    $className = 'CRM_CiviDataTranslate_DAO_Strings';
    $entityName = 'Strings';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
