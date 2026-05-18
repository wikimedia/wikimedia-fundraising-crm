<?php


namespace Civi\Api4\Action\Phone;

use Civi\Api4\Phone;
use Civi\Api4\CleanBase;

/**
 * Clean duplicate locations, missing primaries.
 *
 * @method $this setContactIDs(array $contactIDs) Set IDs of contacts to clean.
 * @method array getContactIDs() Set IDs of contacts to clean.
 */
class Clean extends CleanBase {
  protected function getLocationTypeSettingName(): string {
    return 'deduper_clean_location_types_to_keep_phone';
  }

  /**
   * Fetch the phones for the contacts.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function loadEntities() {
    $entities = Phone::get()->setCheckPermissions(FALSE)->setOrderBy(['is_primary' => 'DESC'])->setWhere([['contact_id', 'IN', $this->getContactIDs()]])->addSelect('*')->execute();
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
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function update($entityID, $values) {
    Phone::update()->setCheckPermissions(FALSE)->addWhere('id', '=', $entityID)->setValues($values)->execute();
  }

  /**
   * Are the 2 entities equivalent.
   *
   * Equivalent means they are same entity (e.g. same email, phone, street address)
   * but there may be additional salient information on one but not the other (e.g email signature).
   *
   * The code will remove one, retaining salient information from it if appropriate.
   *
   * @param array $entity1
   * @param array $entity2
   *
   * @return bool
   */
  protected function isMatch($entity1, $entity2): bool {
    return $entity1['phone_numeric'] === $entity2['phone_numeric'];
  }

  /**
   * Get a string that identifies this entity.
   *
   * @param array $entity
   *
   * @return string
   */
  protected function getIdentifier($entity): string {
    return $entity['phone_numeric'];
  }

  /**
   * Process deletion of duplicate entities.
   *
   * @throws \CRM_Core_Exception
   */
  protected function processDeletes() {
    Phone::delete()->setWhere([['id', 'IN', $this->idsToDelete]])->setCheckPermissions(FALSE)->execute();
  }

  /**
   * Get the values from the phone that are worth preserving.
   *
   * In these fields 'some data' is better than no date.
   *
   * @param array $entity
   *
   * @return array
   */
  protected function getSalientValues($entity): array {
    $salientValues = [];
    // Phone duplicates email address & location, grab any extra detail & delete it.
    foreach (['phone_ext', 'phone_type_id'] as $key) {
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
    return !empty($entity['phone']);
  }

}
