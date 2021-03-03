<?php

class CRM_Omnimail_BAO_OmnimailJobProgress extends CRM_Omnimail_DAO_OmnimailJobProgress {

  /**
   * Create a new OmnimailJobProgress based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Omnimail_DAO_OmnimailJobProgress
   *
   * public static function create($params) {
    * $className = 'CRM_Omnimail_DAO_OmnimailJobProgress';
    * $entityName = 'OmnimailJobProgress';
    * $hook = empty($params['id']) ? 'create' : 'edit';
 *
* CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    * $instance = new $className();
    * $instance->copyValues($params);
    * $instance->save();
    * CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);
 *
* return $instance;
  * } */

}
