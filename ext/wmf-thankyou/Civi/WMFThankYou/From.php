<?php
namespace Civi\WMFThankYou;

class From {

  public static function getFromName($template) {
    return \Civi::settings()->get("wmf_{$template}_from_name") ?? \CRM_Core_BAO_Domain::getNameAndEmail()[0];
  }

  public static function getFromAddress($template) {
    return \Civi::settings()->get("wmf_{$template}_from_address") ?? \CRM_Core_BAO_Domain::getNameAndEmail()[1];
  }
}