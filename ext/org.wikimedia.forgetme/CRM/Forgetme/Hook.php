<?php

class CRM_Forgetme_Hook {

  /**
   * Allows some pre-config to be done in test setup.
   *
   * See proposal to put in core.
   * https://lab.civicrm.org/dev/core/issues/182
   *
   * @return mixed
   *   Ignored value.
   */
  public static function testSetup(): void {
    CRM_Utils_Hook::singleton()->invoke([], CRM_Core_DAO::$_nullObject, CRM_Core_DAO::$_nullObject, CRM_Core_DAO::$_nullObject, CRM_Core_DAO::$_nullObject, CRM_Core_DAO::$_nullObject, CRM_Core_DAO::$_nullObject, 'civicrm_testSetup');
  }

}
