<?php

use CRM_Dedupetools_ExtensionUtil as E;

/**
 * Class CRM_Dedupetools_BAO_Resolver_BooleanYesResolver
 */
class CRM_Dedupetools_BAO_Resolver_InitialResolver extends CRM_Dedupetools_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   */
  public function resolveConflicts() {
    if (!$this->hasIndividualNameFieldConflict()) {
      return;
    }

    foreach ([TRUE, FALSE] as $isContactToKeep) {
      $contact1 = $this->getIndividualNameFieldValues($isContactToKeep);
      $contact2  = $this->getIndividualNameFieldValues(!$isContactToKeep);

      if ($contact1['first_name'] !== $contact2['first_name']) {
        $firstNameParts = explode(' ', $contact1['first_name']);
        if (isset($firstNameParts[1]) && strlen($firstNameParts[1]) === 1) {
          // This is our least complicated pattern - ie we have a string and a space and a letter.
          // For now this is the one we are going to solve as it is also the most prevalent.
          if ($firstNameParts[0] === $contact2['first_name'] && empty($contact2['middle_name'])) {
            $this->setResolvedValue('first_name', $firstNameParts[0]);
            $this->setValue('middle_name', $firstNameParts[1]);
          }
        }
      }
    }
  }

}
