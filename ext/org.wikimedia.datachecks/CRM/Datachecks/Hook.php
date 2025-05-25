<?php

class CRM_Datachecks_Hook {

  /**
   * This hook allows a data check to be registered.
   *
   *
   * @param array $checks Get the avaliable checks
   *
   * @return mixed
   *   Ignored value.
   */
  public static function dataCheckGetChecks(&$checks) {
      return CRM_Utils_Hook::singleton()->invoke(
        ['checks'],
        $checks,
        CRM_Core_DAO::$_nullObject,
        CRM_Core_DAO::$_nullObject,
        CRM_Core_DAO::$_nullObject,
        CRM_Core_DAO::$_nullObject,
        CRM_Core_DAO::$_nullObject,
        'civicrm_datacheck_checks'
      );

  }

}
