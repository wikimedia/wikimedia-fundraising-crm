<?php

class CRM_SmashPig_Hook {

  /**
   * This hook allows a reporting library to output statistics related
   * to charges made via SmashPig.
   *
   * @param array $stats An array of counts of donations by status, i.e.
   *  'Completed', 'Failed', etc.
   *
   * @return mixed
   *   Ignored value.
   */
  public static function smashpigOutputStats($stats) {
    return CRM_Utils_Hook::singleton()->invoke(
      ['stats'],
      $stats,
      CRM_Core_DAO::$_nullObject,
      CRM_Core_DAO::$_nullObject,
      CRM_Core_DAO::$_nullObject,
      CRM_Core_DAO::$_nullObject,
      CRM_Core_DAO::$_nullObject,
      'civicrm_smashpig_stats'
    );

  }

}
