<?php
use CRM_Deduper_ExtensionUtil as E;

class CRM_Deduper_BAO_ContactNamePairFamily extends CRM_Deduper_DAO_ContactNamePairFamily {

  /**
   * Callback to alter CRM_Deduper_DAO_ContactNamePairFamily::getReferenceColumns().
   *
   * Declare a pseudo-fk between name_b and Contact.first_name so it can be joined in SearchKit.
   *
   * @see deduper_civicrm_entityTypes
   *
   * @param string $className
   * @param array $links
   */
  public static function alterLinks($className, &$links) {
    $links[] = new CRM_Core_Reference_Basic(self::getTableName(), 'name_b', 'civicrm_contact', 'first_name');
  }

}
