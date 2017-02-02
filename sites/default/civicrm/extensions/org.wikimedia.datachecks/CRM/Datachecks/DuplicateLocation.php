<?php

/**
 * Class CRM_Datachecks_DuplicateLocation
 *
 * Class to do checks to ensure people do not have duplicates of a particular location type.
 */
class CRM_Datachecks_DuplicateLocation {

  /**
   * Name of the temporary table for generating the data.
   *
   * @var string
   */
  protected $temporaryTable = 'civicrm_temp_duplicate_location';
  /**
   * List of entities to check.
   *
   * @var array
   */
  protected $entities = array('email', 'phone', 'address', 'im');

  /**
   * Do data integrity check on primary locations.
   */
  public function check() {
    $result = array();
    foreach ($this->entities as $entity) {
      $this->createTemporaryTable($entity);

      $count = CRM_Core_DAO::singleValueQuery("
        SELECT count(*)
        FROM $this->temporaryTable e
        LEFT JOIN civicrm_contact c ON e.contact_id = c.id
        WHERE c.is_deleted = 0
      ");
      if ($count) {
        $examples = array();
        $exampleContactsDAO = CRM_Core_DAO::executeQuery(
          "SELECT contact_id
          FROM $this->temporaryTable e
          LEFT JOIN civicrm_contact c ON e.contact_id = c.id
          WHERE c.is_deleted = 0 LIMIT 5
        ");

        while ($exampleContactsDAO->fetch()) {
          $examples[] = $exampleContactsDAO->contact_id;
        }
        // It would be interesting to use the api v3 style arrayobject return here.
        $result[$entity] = array(
          'message' => ts('%1 contact/s have multiple %2 with the same location type', array($count, $entity)),
          'example' => array('contact' => $examples),
        );
      }
      $this->dropTemporaryTable();
    }
    return $result;
  }

  /**
   * Create a temporary table of all results.
   *
   * Filtering this against deleted contacts is more efficient on dbs
   * with lots of deleted contacts than a single query.
   *
   * This query is much slower if you try to include location_type_id in
   * the group by.
   *
   * @param string $entity
   *
   * @return string
   */
  protected function createTemporaryTable($entity) {

    CRM_Core_DAO::executeQuery("
      CREATE TEMPORARY TABLE {$this->temporaryTable}(
      SELECT contact_id,
      IF(count(location_type_id) > COUNT(DISTINCT location_type_id), 1, 0) as is_duplicate
      FROM civicrm_{$entity}
      WHERE contact_id IS NOT NULL
      GROUP BY contact_id
      HAVING is_duplicate  = 1
      )"
    );
    CRM_Core_DAO::executeQuery("
      ALTER TABLE {$this->temporaryTable}
      ADD INDEX contact_id(contact_id)"
    );
    return $this->temporaryTable;
  }

  /**
   * Drop the temporary table.
   *
   * This would happen anyway but seems cleaner to drop & re-use.
   */
  protected function dropTemporaryTable() {
    CRM_Core_DAO::executeQuery("DROP TEMPORARY TABLE {$this->temporaryTable}");
  }

  /**
   * Fix the lack of primaries.
   *
   * Use a temporary table as a straight update could fail if logging it on
   * due to logic loop.
   *
   * @param array $options
   */
  public function fix($options) {
    // @todo unfinished.
    return;
    /**
    foreach ($this->entities as $entity) {
      CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS civicrm_location_updates");
      $this->createTemporaryTable($entity);

      if (!empty($options['delete_exact_duplicates']) && $entity == 'email') {
        // @todo figure out how to deal with other entities.
        CRM_Core_DAO::executeQuery(
          "DELETE l, e2 FROM civicrm_location_updates l
           LEFT JOIN civicrm_{$entity} e
           ON e.id = l.id
           INNER JOIN civicrm_email e2 ON e2.email = e.email
           AND e.contact_id = e2.contact_id
           // If
           AND (e.on_hold = 1 OR e2.is_oh_hold = 0)
           AND (e.is_bulkmail = 1 OR e2.is_bulkmail = 0)
           AND e2.id >e.id
           AND e.is_primary = 1
        "
        );
      }
    }
    **/
  }
}
