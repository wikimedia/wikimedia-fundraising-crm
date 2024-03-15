<?php

//use CRM_Damaged_ExtensionUtil as E;
use SmashPig\Core\DataStores\QueueWrapper;

class CRM_Damaged_BAO_Damaged extends CRM_Damaged_DAO_Damaged {

  /**
   * Create a new Damaged based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Damaged_DAO_Damaged
   */
  public static function create(array $params): CRM_Damaged_DAO_Damaged {
    $className = 'CRM_Damaged_DAO_Damaged';
    $entityName = 'Damaged';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  }

  /**
   * Retrieve DB object and copy to defaults array.
   *
   * @param array $params
   *   Array of criteria values.
   * @param array $defaults
   *   Array to be populated with found values.
   *
   * @return self|null
   *   The DAO object, if found.
   *
   */
  public static function retrieve($params, &$defaults): ?self {
    return self::commonRetrieve(self::class, $params, $defaults);
  }

  /**
   * Delete Damaged message.
   *
   * @param int $damagedId
   *
   * @throws CRM_Core_Exception
   * @return mixed
   */
  public static function del($damagedId) {
    // make sure damagedId is an integer
    // @todo review this as most delete functions rely on the api & form layer for this
    // or do a find first & throw error if no find
    if (!CRM_Utils_Rule::positiveInteger($damagedId)) {
      throw new CRM_Core_Exception(ts('Invalid Damaged row id'));
    }
    return static::deleteRecord(['id' => $damagedId]);
  }

   /**
   * @param CRM_Damaged_DamagedRow $row
   *
   * @return CRM_Damaged_DamagedRow
   * @throws \CRM_Core_Exception|\JsonException
   */
  public static function pushObjectsToQueue(CRM_Damaged_DamagedRow $row): CRM_Damaged_DamagedRow {
    try {
      CRM_SmashPig_ContextWrapper::createContext('damaged');
      QueueWrapper::push( $row->getOriginalQueue(), $row->getMessage() );
      return $row;
    }
    catch (\CRM_Core_Exception
    | \SmashPig\Core\ConfigurationKeyException
    | \SmashPig\Core\DataStores\DataStoreException $exception ) {
      throw new CRM_Core_Exception('Error sending message to queue:'. $exception->getMessage());
    }
  }

}
