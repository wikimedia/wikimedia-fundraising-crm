<?php
use CRM_Deduper_ExtensionUtil as E;

class CRM_Deduper_BAO_ContactNamePairFamily extends CRM_Deduper_DAO_ContactNamePairFamily {

  /**
   * Callback to alter CRM_Deduper_DAO_ContactNamePairFamily::getReferenceColumns().
   *
   * Declare a pseudo-fk between name_b and Contact.first_name so it can be joined in SearchKit.
   *
   *  *
   * Note if this is working correctly then it will auto-join from first_name to the Japanese
   * family name in search kit (in order to find cases where the family name is in the first name
   * field)
   * 1) it works in search kit - to test this create a contact with the first name 鱸
   * 2) the url in search kit will look something like
   * https://localhost/civicrm/admin/search#/create/Contact?params=%7B%22version%22:4,%22select%22:%5B%22id%22,%22display_name%22%5D,%22orderBy%22:%7B%7D,%22where%22:%5B%5D,%22groupBy%22:%5B%5D,%22join%22:%5B%5B%22ContactNamePairFamily%20AS%20Contact_ContactNamePairFamily_name_b_01%22,%22INNER%22,%5B%22first_name%22,%22%3D%22,%22Contact_ContactNamePairFamily_name_b_01.name_b%22%5D%5D%5D,%22having%22:%5B%5D%7D
   * - ie Contacts with (required) contact name family pairs. Search kit will
   * be able to do this join as a result of the reference defined below.
   * 3) However, if you then do a contact.getrefs api call for that contact
   * the civicrm_contact_name_pair_family row should not be returned. Also there
   * should be no error if you do the contact.getrefs api call on a contact with
   * a NULL first name.
   *
   * @see deduper_civicrm_entityTypes
   *
   * @param string $className
   * @param array $links
   */
  public static function alterLinks($className, &$links) {
    $links[] = new CRM_Core_Reference_SearchOnly(self::getTableName(), 'name_b', 'civicrm_contact', 'first_name');
  }

}
