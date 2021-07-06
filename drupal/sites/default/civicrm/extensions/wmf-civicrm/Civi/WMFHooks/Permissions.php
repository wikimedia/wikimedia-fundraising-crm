<?php
// Class to hold wmf functionality that alters permissions.

namespace Civi\WMFHooks;

use CRM_Wmf_ExtensionUtil as E;

class Permissions {

  /**
   * Add an engage role permission as a way to move from drupal roles to native.
   *
   * https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_permission/
   *
   * @param array $permissions
   */
  public static function permissions(array &$permissions): void {
    $permissions['engage role'] = [
      E::ts('Engage role'),
      E::ts('Applies defaults and validations for engage users'),
    ];
  }
}
