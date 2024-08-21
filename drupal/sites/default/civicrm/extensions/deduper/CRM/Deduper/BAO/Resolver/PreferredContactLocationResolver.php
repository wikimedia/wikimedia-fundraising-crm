<?php

/**
 * CRM_Deduper_BAO_Resolver_PreferredContactEmailResolver
 */
class CRM_Deduper_BAO_Resolver_PreferredContactLocationResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolveConflicts(): void {
    foreach (['email', 'address', 'phone'] as $entity) {
      if (strpos(Civi::settings()->get('deduper_resolver_' . $entity), 'preferred_contact') === 0) {
        if ($this->isContactToKeepPreferred()) {
          $this->resolveLocationsPreferringContactToKeep($entity);
        }
        else {
          $this->resolveLocationsPreferringContactToRemove($entity);
        }
      }
    }
  }

  /**
   * Should we 're-home' the entity we are about to overwrite, so it's not lost
   *
   * If we have 2 Home emails that are different, and we wish to ensure our less preferred one is not lost
   * then we can adjust its location and keep it. However, if the contact already has that email
   * for another location no action is required. For each entity we identify a few fields that are salient to
   * whether this holds distinct information.
   *
   * @param 'phone'|'address'|'email' $entity
   * @param array $entityToConsiderRehoming
   *   Copy of the blocks for this entity from the contact to be kept.
   * @param int $blockNumber
   *
   * @return bool
   */
  protected function isReHomingRequired(string $entity, array $entityToConsiderRehoming, int $blockNumber): bool {
    if (Civi::settings()->get('deduper_resolver_' . $entity) !== 'preferred_contact_with_re-assign') {
      return FALSE;
    }
    return $this->isBlockUnique($entity, $entityToConsiderRehoming, $blockNumber);
  }

  /**
   * Get the primary block for the given contact.
   *
   * All contacts should have exactly 1 primary (if any emails exist).
   *
   * @param array $entities
   *
   * @return false|int
   */
  protected function getPrimaryBlockForContact(array $entities) {
    foreach ($entities as $block => $entity) {
      if ($entity['is_primary']) {
        return (int) $block;
      }
    }
    return FALSE;
  }

  /**
   * Resolve location details preferring those from the contact to be kept.
   *
   * We need to resolve conflicts such that
   * salient data is brought over but the primary flag remains with our contact-to-keep
   *  on the address marked as primary for them.
   *
   * @param "email"|"phone"|"address" $entity
   */
  protected function resolveLocationsPreferringContactToKeep(string $entity): void {
    $conflicts = $this->getAllConflictsForEntity($entity);
    $entitiesContactToDelete = $this->getLocationEntities($entity, FALSE);
    $entitiesContactToKeep = $this->getLocationEntities($entity, TRUE);
    // Get the index of the block holding the primary record.
    $primaryBlock = $this->getPrimaryBlockForContact($entitiesContactToDelete);
    if (!empty($conflicts)) {
      foreach ($conflicts as $block => $blockConflicts) {
        // Potentially relocate entities from the contact to keep to avoid overwrite.
        if ($this->isReHomingRequired($entity, $entitiesContactToDelete[$block], $block)) {
          $this->relocateLocation($entity, $block, FALSE, FALSE);
        }
        foreach (array_keys($blockConflicts['fields'] ?? []) as $fieldName) {
          // Keep the value from the contact to keep as that is preferred contact.
          $this->setResolvedLocationValue($fieldName, $entity, $block, $entitiesContactToKeep[$block][$fieldName]);
          if ($block === $primaryBlock && $entitiesContactToDelete[$block]['is_primary'] !== $entitiesContactToKeep[$block]['is_primary']) {
            $this->setResolvedLocationValue('is_primary', $entity, $block, 1);
          }
        }
      }
    }
    // Now that we have resolved the conflicts do one last pass to make sure we are not moving entities
    // that are the same except for their location
    foreach ($this->getLocationEntities($entity, FALSE) as $toDeleteBlockNumber => $toDeleteEntity) {
      foreach ($this->getLocationEntities($entity, TRUE) as $toKeepEntity) {
        if (!$this->isRelocated($entity, $toDeleteBlockNumber) && $this->isBlockEquivalent($entity, $toDeleteEntity, $toKeepEntity)) {
          $this->setDoNotMoveBlock($entity, $toDeleteBlockNumber);
        }
      }
    }
  }

  /**
   * Resolve location details preferring those from the contact to be removed.
   *
   * The contact about to be deleted is our preferred contact.
   *
   * @param 'email'|'phone'|'address' $entity
   */
  protected function resolveLocationsPreferringContactToRemove(string $entity): void {
    $conflicts = $this->getAllConflictsForEntity($entity);
    $entitiesContactToDelete = $this->getLocationEntities($entity, FALSE);
    // Make sure their addresses take precedence and any from the other contact get new locations, if needed.
    if (!empty($conflicts)) {
      foreach ($conflicts as $block => $blockConflicts) {
        // Potentially relocate entities from the contact to keep to avoid overwrite.
        if ($this->isReHomingRequired($entity, $blockConflicts['to_keep'], $block)) {
          $this->relocateLocation($entity, $block, TRUE, FALSE);
        }
        foreach (array_keys($blockConflicts['fields'] ?? []) as $fieldName) {
          // Keep the value from the contact to delete as that is preferred contact.
          $this->setResolvedLocationValue($fieldName, $entity, $block, $blockConflicts['to_remove'][$fieldName]);
        }
      }
    }

    // Block 0 is the primary entity. If they have different locations we need to make sure it stays primary.
    foreach ($entitiesContactToDelete as $blockNumber => $entityBlock) {
      if ($entityBlock['is_primary']) {
        $this->setPrimaryLocationToDeleteContact('email', $blockNumber);
      }
    }
  }

}
