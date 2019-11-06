<?php

use CRM_Dedupetools_ExtensionUtil as E;

/**
 * Class CRM_Dedupetools_BAO_Resolver_InitialResolver
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
      $this->resolveInitialsInFirstName($isContactToKeep);
      $this->resolveInitialsInLastName($isContactToKeep);
    }
  }

  /**
   * Resolve conflicts with initials in first name.
   *
   * @param bool $isContactToKeep
   */
  protected function resolveInitialsInFirstName(bool $isContactToKeep) {
    $contact1 = $this->getIndividualNameFieldValues($isContactToKeep);
    $contact2  = $this->getIndividualNameFieldValues(!$isContactToKeep);
    if ($contact1['first_name'] === $contact2['first_name']) {
      return;
    }
    $firstNameParts = explode(' ', $contact1['first_name']);
    if (isset($firstNameParts[1]) && strlen($firstNameParts[1]) === 1) {
      // First name is 'Bob M' - let's try M as an initial.
      if ($firstNameParts[0] === $contact2['first_name'] && empty($contact2['middle_name'])) {
        $this->setResolvedValue('first_name', $firstNameParts[0]);
        $this->setValue('middle_name', $firstNameParts[1]);
      }
    }
    elseif (isset($firstNameParts[0]) && strlen($firstNameParts[0]) === 1) {
      // First name is 'B' let's accept it as a match with 'Bob'
      if (stripos(strtolower($contact2['first_name']), strtolower($firstNameParts[0])) === 0) {
        $this->setResolvedValue('first_name', $contact2['first_name']);
      }
    }
  }

  /**
   * Resolve conflicts with initials in first name.
   *
   * @param bool $isContactToKeep
   */
  protected function resolveInitialsInLastName($isContactToKeep) {
    $contact1 = $this->getIndividualNameFieldValues($isContactToKeep);
    $contact2 = $this->getIndividualNameFieldValues(!$isContactToKeep);
    if ($contact1['last_name'] === $contact2['last_name']) {
      return;
    }
    $lastNameParts = explode(' ', $contact1['last_name']);
    if (isset($lastNameParts[1]) && strlen($lastNameParts[0]) === 1) {
      // Last name is 'M Smith' - let's try M as an initial.
      if ($lastNameParts[1] === $contact2['last_name'] && empty($contact2['middle_name'])) {
        $this->setResolvedValue('last_name', $lastNameParts[1]);
        $this->setValue('middle_name', $lastNameParts[0]);
      }
    }
    elseif (isset($lastNameParts[0]) && strlen($lastNameParts[0]) === 1) {
      // First name is 'B' let's accept it as a match with 'Bob'
      if (stripos(strtolower($contact2['last_name']), strtolower($lastNameParts[0])) === 0) {
        $this->setResolvedValue('last_name', $contact2['last_name']);
      }
    }
  }

}
