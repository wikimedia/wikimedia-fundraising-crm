<?php
use CRM_Deduper_ExtensionUtil as E;

class CRM_Deduper_BAO_ContactNamePair extends CRM_Deduper_DAO_ContactNamePair {

  /**
   * Create a new ContactNamePair based on array-data
   *
   * @param array $params key-value pairs
   *
   * @return CRM_Deduper_DAO_ContactNamePair
   * @throws \CRM_Core_Exception
   */
  public static function create($params) {
    $className = 'CRM_Deduper_DAO_ContactNamePair';
    $entityName = 'ContactNamePair';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, $params['id'] ?? NULL, $params);
    /* @var self $instance */
    $instance = new $className();
    if (!isset($params['id'])) {
      if (empty($params['name_a']) || empty($params['name_b'])) {
        throw new CRM_Core_Exception('name_a and name_b are required');
      }
      $instance->name_a = $params['name_a'];
      $instance->name_b = $params['name_b'];
      $instance->fetch();
    }
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  }

}
