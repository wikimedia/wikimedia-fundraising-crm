<?php


namespace Civi\Api4\Action\Address;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Address;
use Civi\Api4\CleanBase;

/**
 * Clean duplicate locations, missing primaries.
 *
 * @method $this setContactIDs(array $contactIDs) Set IDs of contacts to clean.
 * @method array getContactIDs() Set IDs of contacts to clean.
 */
class Clean extends CleanBase {
  /**
   * Phones retrieved for the contacts.
   *
   * These are keyed by the contact and ordered primary first.
   *
   * @var array
   */
  protected $entities = [];

  /**
   * Fetch the addresses for the contacts.
   *
   * @throws \CRM_Core_Exception
   */
  protected function loadEntities() {
    $entities = Address::get()->setCheckPermissions(FALSE)->setOrderBy(['is_primary' => 'DESC'])->setWhere([['contact_id', 'IN', $this->getContactIDs()]])->addSelect('*')->execute();
    foreach ($entities as $entity) {
      $this->entities[$entity['contact_id']][$entity['id']] = $entity;
    }
  }

  /**
   * Update the specified entity.
   *
   * @param int $entityID
   * @param array $values
   *
   * @throws \CRM_Core_Exception
   */
  protected function update($entityID, $values) {
    Address::update()->setCheckPermissions(FALSE)->addWhere('id', '=', $entityID)->setValues($values)->execute();
  }

  /**
   * Are the 2 entities equivalent.
   *
   * Equivalent means they are same entity (e.g. same email, phone, street address)
   * but there may be additional salient information on one but not the other (e.g email signature).
   *
   * The code will remove one, retaining salient information from it if appropriate.
   *
   * This match check is very minimal at the moment - ideally we would handle
   * 'UK' ==== '10 Downing Street, UK' at some point.
   *
   * @param array $entity1
   * @param array $entity2
   *
   * @return bool
   */
  protected function isMatch($entity1, $entity2): bool {
    $filteredEntity1 = $this->getFilteredAddress($entity1);
    $filteredEntity2 = $this->getFilteredAddress($entity2);
    return $filteredEntity1 === $filteredEntity2;
  }

  /**
   * Get a string that identifies this entity.
   *
   * @param array $entity
   *
   * @return string
   */
  protected function getIdentifier($entity): string {
    return implode('', $this->getFilteredAddress($entity));
  }

  /**
   * Get a filtered version of the address with the main values.
   *
   * @param array $entity1
   *
   * @return array
   */
  protected function getFilteredAddress($entity1): array {
    $orderedKeys = ['street_address', 'postal_code', 'city', 'county_id', 'state_province_id', 'country_id'];
    return array_intersect_key(array_filter($entity1), array_fill_keys($orderedKeys, 1));
  }

  /**
   * Process deletion of duplicate entities.
   *
   * @throws \CRM_Core_Exception
   */
  protected function processDeletes() {
    Address::delete()->setWhere([['id', 'IN', $this->idsToDelete]])->setCheckPermissions(FALSE)->execute();
  }

  /**
   * Get the values from the address that are worth preserving.
   *
   * These are fields that are not crucial to determining a match - but once we have
   * determined a match we will keep this data, if populated.
   *
   * @param array $entity
   *
   * @return array
   */
  protected function getSalientValues($entity): array {
    $salientValues = [];
    foreach ($this->getAllSalientFields() as $key) {
      if (!empty($entity[$key])) {
        $salientValues[$key] = $entity[$key];
      }
    }
    return $salientValues;
  }

  /**
   * Does the entity have salient data.
   *
   * If all meaningful fields are empty, return false.
   *
   * @param array $entity
   *
   * @return bool
   */
  protected function hasSalientData($entity): bool {
    $relevantFields = $this->getAllSalientFields();
    foreach ($relevantFields as $field) {
      if (!empty($entity[$field])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get all fieldnames that could hold data describing the address, but excluding metadata.
   *
   * @return array
   */
  protected function getAllSalientFields(): array {
    return [
      'street_address',
      'postal_code',
      'city',
      'county_id',
      'state_province_id',
      'country_id',
      'master_id',
      'postal_code_suffix',
      'supplemental_address_1',
      'supplemental_address_2',
      'supplemental_address_3',
      'timezone',
      'name',
    ];
  }

}
