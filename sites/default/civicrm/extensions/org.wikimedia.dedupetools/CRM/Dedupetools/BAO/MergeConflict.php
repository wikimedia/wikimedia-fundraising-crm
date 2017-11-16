<?php

class CRM_Dedupetools_BAO_MergeConflict extends CRM_Dedupetools_DAO_MergeConflict {

  /**
   * Create a new MergeConflict based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Dedupetools_DAO_MergeConflict|NULL
   *
  public static function create($params) {
    $className = 'CRM_Dedupetools_DAO_MergeConflict';
    $entityName = 'MergeConflict';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
