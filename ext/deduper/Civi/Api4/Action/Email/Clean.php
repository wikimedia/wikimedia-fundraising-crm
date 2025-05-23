<?php


namespace Civi\Api4\Action\Email;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Email;
use Civi\Api4\CleanBase;

/**
 * Clean duplicate locations, missing primaries.
 *
 * @method $this setContactIDs(array $contactIDs) Set IDs of contacts to clean.
 * @method array getContactIDs() Set IDs of contacts to clean.
 */
class Clean extends CleanBase {

  /**
   * Fetch the emails for the contacts.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function loadEntities() {
    $entities = Email::get()->setCheckPermissions(FALSE)->setOrderBy(['is_primary' => 'DESC'])->setWhere([['contact_id', 'IN', $this->getContactIDs()]])->addSelect('*')->execute();
    foreach ($entities as $entity) {
      $this->entities[$entity['contact_id']][$entity['id']] = $entity;
    }
  }

  /**
   * Get the values from the email that are worth preserving.
   *
   * In these fields 'some data' is better than no date.
   *
   * @param array $email
   *
   * @return array
   */
  protected function getSalientValues($email): array {
    $salientValues = [];
    // Email duplicates email address & location, grab any extra detail & delete it.
    foreach (['on_hold', 'is_billing', 'is_bulk_mail', 'hold_date', 'reset_date', 'signature_text', 'signature_html'] as $key) {
      if (!empty($email[$key])) {
        $salientValues[$key] = $email[$key];
      }
    }
    return $salientValues;
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
    Email::update()->setCheckPermissions(FALSE)->addWhere('id', '=', $entityID)->setValues($values)->execute();
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
    return $entity1['email'] === $entity2['email'];
  }

  /**
   * Get a string that identifies this entity.
   *
   * @param array $entity
   *
   * @return string
   */
  protected function getIdentifier($entity): string {
    return $entity['email'];
  }

  /**
   * Process deletion of duplicate entities.
   *
   * @throws \CRM_Core_Exception
   */
  protected function processDeletes() {
    Email::delete()->setWhere([['id', 'IN', $this->idsToDelete]])->setCheckPermissions(FALSE)->execute();
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
    return !empty($entity['email']);
  }

}
