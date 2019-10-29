<?php

use CRM_Dedupetools_ExtensionUtil as E;

/**
 * Class CRM_Dedupetools_BAO_Resolver_MisplacedNameResolver
 */
class CRM_Dedupetools_BAO_Resolver_MisplacedNameResolver extends CRM_Dedupetools_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   *
   * Here we are resolving a scenario where the full name is in one field & another is empty.
   *
   * For example first_name = 'Bob Smith', last_name = ''
   * can be resolved to merge with first_name = 'Bob', last_name = 'Smith'
   */
  public function resolveConflicts() {
    if (!$this->hasIndividualNameFieldConflict()) {
      return;
    }

    foreach ([TRUE, FALSE] as $isContactToKeep) {
      $contact1 = $this->getIndividualNameFieldValues($isContactToKeep);
      $contact2  = $this->getIndividualNameFieldValues(!$isContactToKeep);

      if (empty(trim($contact1['first_name'])) && $this->isFieldInConflict('last_name')) {
        $lastNameParts = explode(' ', $contact1['last_name']);
        if (array_shift($lastNameParts) === $contact2['first_name']) {
          $this->setValue('first_name', $contact2['first_name']);

          $lastName = implode(' ', $lastNameParts);
          if ($contact2['last_name'] === $lastName) {
            $this->setResolvedValue('last_name', $lastName);
          }
          else {
            // There is still a conflict but a later resolver might sort it out.
            $this->setContactValue('last_name', $lastName, $isContactToKeep);
          }
        }
      }

      if (empty(trim($contact1['last_name'])) && $this->isFieldInConflict('first_name')) {
        $firstNameParts = explode(' ', $contact1['first_name']);
        if (array_pop($firstNameParts) === $contact2['last_name']) {
          $this->setValue('last_name', $contact2['last_name']);

          $firstName = implode(' ', $firstNameParts);
          if ($contact2['first_name'] === $firstName) {
            $this->setResolvedValue('first_name', $firstName);
          }
          else {
            $this->setContactValue('first_name', $firstName, $isContactToKeep);
          }
        }
      }
    }
  }

}
