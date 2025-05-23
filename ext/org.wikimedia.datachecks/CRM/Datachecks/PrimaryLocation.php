<?php

/**
 * Class CRM_Datachecks_PrimaryLocation
 *
 * Class to do checks to ensure everyone has a primary location for address-type
 * entities if they exist. ie. we don't want someone to have 2 emails with neither
 * set to primary.
 */
class CRM_Datachecks_PrimaryLocation {

  /**
   * Name of the temporary table for generating the data.
   *
   * @var string
   */
  protected $temporaryTable = 'civicrm_temp_no_primaries';

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
      $matchesTable = $this->createTemporaryTable($entity);

      $sql = "
        SELECT count(*)
        FROM $matchesTable
        INNER JOIN civicrm_contact c ON c.id = contact_id
        WHERE is_deleted = 0
      ";

      $count = CRM_Core_DAO::singleValueQuery($sql);
      if ($count) {
        $examples = array();
        $exampleContactsDAO = CRM_Core_DAO::executeQuery(
          "SELECT contact_id
           FROM $matchesTable
           INNER JOIN civicrm_contact c ON c.id = contact_id
           WHERE is_deleted = 0
           LIMIT 5"
        );

        while ($exampleContactsDAO->fetch()) {
          $examples[] = $exampleContactsDAO->contact_id;
        }
        // It would be interesting to use the api v3 style arrayobject return here.
        $result[$entity] = array(
          'message' => ts('%1 contact/s have at least one %2 but none are marked as primary', array($count, $entity)),
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
   * @param string $entity
   *
   * @return string
   */
  protected function createTemporaryTable($entity) {

    $this->temporaryTable = CRM_Utils_SQL_TempTable::build()->createWithQuery("
      SELECT contact_id, min(id) as id, sum(is_primary) as c
      FROM civicrm_{$entity}
      WHERE contact_id IS NOT NULL
      GROUP BY contact_id
      HAVING c = 0
    ")->getName();

    CRM_Core_DAO::executeQuery("
      ALTER TABLE {$this->temporaryTable}
      ADD INDEX contact_id(contact_id),
      ADD INDEX id(id)"
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
   * Note that we don't filter out deleted contacts. We don't check for deleted
   * contacts with no primary but if we are doing a fix we will fix them as well
   * as if they are ever restored they should have a primary marked.
   *
   * If there is more than one primary the one with the lowest id will be chosen.
   * I thought about a more complex mechanism but wasn't sure the benefit was clear enough.
   */
  public function fix() {
    foreach ($this->entities as $entity) {
      $matchesTable = $this->createTemporaryTable($entity);
      CRM_Core_DAO::executeQuery("
        UPDATE civicrm_{$entity} e
        INNER JOIN $matchesTable u ON e.id = u.id
        SET e.is_primary = 1
      ");
      $this->dropTemporaryTable();
    }


  }
}
