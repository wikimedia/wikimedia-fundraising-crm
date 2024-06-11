<?php


namespace Civi\Api4;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Clean duplicate locations, missing primaries.
 *
 * @method $this setContactIDs(array $contactIDs) Set IDs of contacts to clean.
 * @method array getContactIDs() Set IDs of contacts to clean.
 */
abstract class CleanBase extends AbstractAction {
  /**
   * Contact IDS to clean.
   *
   * @var array
   * @required
   */
  protected $contactIDs = [];

  /**
   * Ids of entities that should be deleted as duplicates.
   *
   * @var array
   */
  protected $idsToDelete = [];

  /**
   * Ids of entities that should be moved to a new location.
   *
   * @var array
   */
  protected $idsToRelocate = [];

  /**
   * Array of entities to retain, keyed by the unique data identifier for the entity (e.g email, phone, concatenated address).
   *
   * @var array
   */
  protected $entitiesToKeep = [];

  /**
   * Entities retrieved for the contacts (email, address, phone depending which we are acting on).
   *
   * These are keyed by the contact and ordered primary first.
   *
   * @var array
   */
  protected $entities = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   */
  public function _run(Result $result) {
    $this->loadEntities();
    if (empty($this->entities)) {
      return;
    }
    $this->ensurePrimaryExists();
    $this->identifyEntitiesToCleanUp();
    $this->relocateDoubleUps();
    if (!empty($this->idsToDelete)) {
      $this->processDeletes();
    }
  }

  /**
   * Ensure each contact has one primary email.
   */
  protected function ensurePrimaryExists() {
    $countPrimary = array_fill_keys(array_keys($this->entities), 0);
    foreach ($this->entities as $contactID => $entities) {
      foreach ($entities as $entity) {
        if ($entity['is_primary']) {
          if ($countPrimary[$contactID] > 0) {
            // We already have a primary, remove it from this entity.
            $this->update($entity['id'], ['is_primary' => FALSE]);
            $this->entities[$contactID][$entity['id']]['is_primary'] = FALSE;
          }
          else {
            $countPrimary[$contactID]++;
          }
        }
      }
    }
    foreach ($countPrimary as $contactID => $count) {
      if ($count === 0) {
        // No primaries - set the first entity to be the primary.
        $primaryEntityID = reset($this->entities[$contactID])['id'];
        $this->update($primaryEntityID, ['is_primary' => TRUE]);
        $this->entities[$contactID][$primaryEntityID]['is_primary'] = TRUE;
      }
    }
  }

  /**
   * Get contact's emails keyed by location.
   *
   * @param int $contactID
   *
   * @return array
   */
  protected function getEntitiesForContactByLocation($contactID): array {
    $return = [];
    $locationTypeForUnassigned = \CRM_Core_BAO_LocationType::getDefault()->id;
    foreach ($this->entities[$contactID] as $id => $entity) {
      if ($entity['location_type_id'] === 0) {
        // Treat it as 'one of the other existing types' so re-homing kicks in.
        $entity['location_type_id'] = $locationTypeForUnassigned;
      }
      $return[$entity['location_type_id'] . ($entity['phone_type_id'] ?? '')][$id] = $entity;
      $locationTypeForUnassigned = $entity['location_type_id'];
    }
    return $return;
  }

  /**
   * Identify any entities that have duplicate locations.
   *
   * Determine if they are hold duplicate information, and should be deleted, or different information
   * and should be relocated.
   */
  protected function identifyEntitiesToCleanUp() {
    foreach (array_keys($this->entities) as $contactID) {
      $this->entitiesToKeep[$contactID] = [];
      foreach ($this->getEntitiesForContactByLocation($contactID) as $entities) {
        if (count($entities) > 1) {
          $keeper = array_shift($entities);
          $originalKeeper = $keeper;
          // We have a duplicate for the same location and same entity, let's delete one.
          foreach ($entities as $entity) {
            if ($this->isMatch($entity, $keeper) || !$this->hasSalientData($keeper)) {
              foreach ($this->getSalientValues($entity) as $key => $salientValue) {
                $keeper[$key] = $entity[$key];
              }
              $this->idsToDelete[] = $entity['id'];
            }
            elseif (!$this->hasSalientData($entity)) {
              $this->idsToDelete[] = $entity['id'];
            }
            else {
              $entityKey = $this->getIdentifier($entity);
              if (isset($this->idsToRelocate[$contactID][$entityKey])) {
                // Not our first rodeo. We already have this entity to relocate or there is no data in it.
                // Let's just delete this copy. First we'll check if we want to copy in any salient values.
                $matchingEntity = $originalEntity = $this->idsToRelocate[$contactID][$entityKey];
                foreach ($this->getSalientValues($entity) as $key => $salientValue) {
                  $matchingEntity[$key] = $salientValue;
                }
                if ($matchingEntity !== $originalEntity) {
                  // We've found something worth keeping, save it now. This is an edge case so should
                  // be so rare there is no point trying to 'save the save' in case of further updates from
                  // yet more matching emails.
                  $this->update($matchingEntity['id'], $matchingEntity);
                }
                $this->idsToDelete[] = $entity['id'];
              }
              elseif ($this->hasSalientData($entity)) {
                $this->idsToRelocate[$contactID][$entityKey] = $entity;
              }
            }
          }
          if ($keeper !== $originalKeeper) {
            // We have altered keeper (augmented it with detail from another address) so we save the update.
            $this->update($keeper['id'], $keeper);
          }
        }
        else {
          foreach($entities as $entity) {
            if (!$this->hasSalientData($entity)) {
              // Deleting empty entities pre-merge prevents them overwriting good entities.
              $this->idsToDelete[] = $entity['id'];
            }
            else {
              $keeper = $entity;
            }
          }
        }

        if (!empty($keeper)) {
          $this->setEntityToBeDeletedIfEquivalentAlreadyBeingKept($contactID, $keeper);
        }
      }
    }
  }

  /**
   * Relocated any entities which have the same location as another of the same entity.
   *
   * If we have, for example, 2 emails assigned to 'Home' that are dissimilar we
   * alter the location on one of them to 'fix' the data in a best-effort way.
   */
  protected function relocateDoubleUps() {
    foreach ($this->idsToRelocate as $contactID => $entities) {
      $locationsHeldByContact = array_keys($this->getEntitiesForContactByLocation($contactID));
      $availableLocations = (array) Civi::settings()->get('deduper_location_priority_order');
      $availableLocations = array_diff($availableLocations, $locationsHeldByContact);
      foreach ($entities as $entity) {
        if (!empty($availableLocations)) {
          // If we run out of locations this silently skips at the moment.
          // This should be an edge case & possibly will never arise as an issue.
          $entity['location_type_id'] = array_shift($availableLocations);
          $this->update($entity['id'], $entity);
        }
      }
    }
  }

  /**
   * Set any the entity to be deleted if we have already identified an equivalent entity for retention.
   *
   * We have already addressed the situation where more than oe entity has the same location. This goes further and deletes,
   * for example a home email if it is a match for the Main email. This allows for better deduping results. As we
   * have ordered selection by is_primary DESC the primary will never be the one dropped in this function as it will
   * be the first to be processed through it.
   *
   * @param int $contactID
   * @param array $keeper
   */
  protected function setEntityToBeDeletedIfEquivalentAlreadyBeingKept($contactID, $keeper) {
    $originalKeeper = $keeper;
    if (empty($this->entitiesToKeep[$contactID][$this->getIdentifier($keeper)])) {
      $this->entitiesToKeep[$contactID][$this->getIdentifier($keeper)] = $keeper;
    }
    else {
      $emailToDelete = $keeper;
      $keeper = $originalKeeper = $this->entitiesToKeep[$contactID][$this->getIdentifier($keeper)];
      foreach ($this->getSalientValues($emailToDelete) as $key => $salientValue) {
        if (empty($keeper[$key])) {
          $keeper[$key] = $salientValue;
        }
      }
      $this->idsToDelete[] = $emailToDelete['id'];
    }
    if ($keeper !== $originalKeeper) {
      $this->update($keeper['id'], $keeper);
    }
  }

}
