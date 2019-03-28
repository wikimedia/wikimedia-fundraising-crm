<?php

/**
 * Class CRM_Datachecks_DuplicateLocation
 *
 * Class to do checks to ensure people do not have duplicates of a particular location type.
 */
class CRM_Datachecks_DuplicateLocation extends  CRM_Datachecks_LocationBase {

  /**
   * Name of the temporary table for generating the data.
   *
   * @var string
   */
  protected $temporaryTable;

  /**
   * Do data integrity check on duplicate locations.
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
          WHERE c.is_deleted = 0 ORDER BY id DESC LIMIT 5
        ");

        while ($exampleContactsDAO->fetch()) {
          $examples[] = $exampleContactsDAO->contact_id;
        }
        // It would be interesting to use the api v4 style arrayobject return here.
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
   * This query is much slower if you try to include location_type_id in
   * the group by.
   *
   * @param string $entity
   *
   * @return string
   */
  protected function createTemporaryTable($entity) {
    $phoneClause = ($entity === 'phone') ? ', phone_type_id' : '';
    // This query basically says - is the count of rows for this contact greater than the count of
    // different location types? For phones it compares to the count of location type / phone combos.
    $this->temporaryTable = CRM_Utils_SQL_TempTable::build()->createWithQuery("
      SELECT contact_id,
      # The COALESCE here is a hack to avoid it not counting empty-location-type rows.
      IF(count(*) > COUNT(DISTINCT COALESCE(location_type_id, 999) $phoneClause), 1, 0) as is_duplicate
      FROM civicrm_{$entity}
      WHERE contact_id IS NOT NULL
      GROUP BY contact_id
      HAVING is_duplicate  = 1"
    )->getName();
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
   * Fix the duplicates.
   *
   * If we have an exact match we will delete the duplicates. If not we will change the
   * location type of the second one to 'Other'
   *
   * @param array $options
   */
  public function fix($options) {
    foreach ($this->entities as $entity) {
      $this->createTemporaryTable($entity);
      $result = CRM_Core_DAO::executeQuery('Select * FROM ' . $this->temporaryTable);
      while ($result->fetch()) {
        $data = civicrm_api3($entity, 'get', [
          'contact_id' => $result->contact_id,
          ['options' => ['sort' => 'is_primary']]
        ])['values'];
        $byLocation = [];
        foreach ($data as $index => $instance) {
          $byLocation[CRM_Utils_Array::value('location_type_id', $instance)][] = $instance;
        }
        foreach ($byLocation as $locationTypeID => $entitiesArray) {
          if ($entity === 'phone') {
            $this->filterOutPhonesWhereTypeIsDifferentButLocationIsSame($entitiesArray);
          }
          if (count($entitiesArray) === 1) {
            continue;
          }
          // Here we have 2 contacts with the same location type. Scenarios are
          // 1 they match - we delete one
          // 2 they don't match - we change the type on one
          foreach ($entitiesArray as $index => $thisEntity) {
            // Compare the entity with the next one in the array to see it is the same, if it is
            // then delete it, otherwise update it.
            if (empty($entitiesArray[$index + 1])) {
              continue;
            }
            else {
              $nextEntity = $entitiesArray[$index + 1];
              if ($this->isDataMatch($thisEntity, $nextEntity)) {
                civicrm_api3($entity, 'delete', ['id' => $thisEntity['id']]);
              }
              else {
                $newLocationType = $this->getBestLocationType($byLocation);
                // Yay - we found a 'free type'
                $byLocation[$newLocationType] = $thisEntity;
                civicrm_api3($entity, 'create', [
                  'id' => $thisEntity['id'],
                  'location_type_id' => $newLocationType
                ]);
              }
            }
          }
        }
      }
    }
  }

  /**
   * @param $byLocation
   *
   * @return int
   */
  protected function getBestLocationType($byLocation) {
    static $locationTypes = NULL;
    if (!$locationTypes) {
      $locationTypes = $this->getLocationTypes();
    }

    foreach ($locationTypes as $locationType) {
      if (!isset($byLocation[$locationType])) {
        return $locationType;
      }
    }
  }

  protected function isDataMatch($entity1, $entity2) {
    return ($this->getFilteredEntity($entity1) === $this->getFilteredEntity($entity2));
  }

  /**
   * Get the entity with only the compariable fields.
   *
   * @param array $entity
   *
   * @return array
   */
  protected function getFilteredEntity($entity) {
    return array_intersect_key(array_filter($entity), array_fill_keys([
      'street_address',
      'name',
      'supplemental_address_1',
      'supplemental_address_2',
      'supplemental_address_3',
      'city',
      'postal_code',
      'postal_code_suffix',
      'geo_code_1',
      'geo_code_2',
      'state_province',
      'country_id',
      'county_id',
      'phone',
      'phone_ext',
      'phone_numeric',
      'mobile_provider_id',
      'phone_type_id',
      'email',
      'signature_text',
      'signature_html',
      'on_hold',
      'is_bulkmail',
      'provider_id',
    ], 1));
  }

  /**
   * Filter out phones that are not true duplicates as they have different types.
   *
   * @param $entitiesArray
   */
  protected function filterOutPhonesWhereTypeIsDifferentButLocationIsSame(&$entitiesArray) {

    $phonesByTypeArray = [];
    foreach ($entitiesArray as $index => $entityArray) {
      $phonesByTypeArray[$entityArray['phone_type_id']][$index] = TRUE;
    }
    foreach ($phonesByTypeArray as $phonesByType) {
      if (count($phonesByType) === 1) {
        // This one is actually unique when you take type into account so let it go... let it go..
        unset($entitiesArray[key($phonesByType)]);
      }
    }
  }
}
