<?php

class CRM_ExtendedMailingStats_BAO_MailingStats extends CRM_ExtendedMailingStats_DAO_MailingStats {

  /**
   * Create a new MailingStats based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_ExtendedMailingStats_DAO_MailingStats|NULL
   *
  public static function create($params) {
    $className = 'CRM_ExtendedMailingStats_DAO_MailingStats';
    $entityName = 'MailingStats';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
