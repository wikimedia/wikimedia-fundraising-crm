<?php

/**
 * Class CRM_Datachecks_DuplicateLocation
 *
 * Class to do checks to ensure people do not have duplicates of a particular location type.
 */
class CRM_Datachecks_BlankLocation extends  CRM_Datachecks_LocationBase {

  /**
   * Do data integrity check on rows with blank locations.
   */
  public function check() {
    $result = [];
    foreach ($this->entities as $entity) {
      $count = CRM_Core_DAO::singleValueQuery("SELECT count(*) FROM civicrm_{$entity} WHERE location_type_id IS NULL");
      if ($count) {
        $examples = [];
        $exampleContactsDAO = CRM_Core_DAO::executeQuery(
          "SELECT contact_id FROM civicrm_{$entity} WHERE location_type_id IS NULL ORDER BY id DESC LIMIT 5
        ");

        while ($exampleContactsDAO->fetch()) {
          $examples[] = $exampleContactsDAO->contact_id;
        }
        $result[$entity] = array(
          'message' => ts('%1 contact/s have %2 with no location type', array($count, $entity)),
          'example' => array('contact' => $examples),
        );
      }
    }
    return $result;
  }

  /**
   * Add a location type when there is none.
   */
  public function fix(): void {
    foreach ($this->entities as $entity) {
      foreach ($this->getLocationTypes() as $locationTypeID) {
        // Create a table of entities to change to this locationTypeID - ie.
        // they have no location_type_id and the contact does not already have an entry of that id.
        $temporaryTable = CRM_Utils_SQL_TempTable::build()->createWithQuery("
           SELECT t1.id
           FROM civicrm_{$entity} t1
           LEFT JOIN civicrm_{$entity} t2 ON
             t1.id <> t2.id
             AND t1.contact_id = t2.contact_id
             AND t2.location_type_id = $locationTypeID
           WHERE t1.location_type_Id IS NULL AND t2.id IS NULL;
        ")->getName();
        CRM_Core_DAO::executeQuery("
         UPDATE civicrm_{$entity}
         INNER JOIN $temporaryTable t ON civicrm_{$entity}.id = t.id
         SET location_type_id = $locationTypeID
       ");
      }
    }
  }
}
