<?php

/**
 * Class CRM_Deduper_BAO_Resolver_InitialResolver
 */
class CRM_Deduper_BAO_Resolver_InitialResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   */
  public function resolveConflicts(): void {
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
    if (isset($firstNameParts[1]) && mb_strlen($firstNameParts[1]) === 1) {
      // First name is 'Bob M' - let's try M as an initial.
      if ($firstNameParts[0] === $contact2['first_name']
        && (empty($contact2['middle_name']) || $contact2['middle_name'] === $firstNameParts[1])) {
        $this->setResolvedValue('first_name', $firstNameParts[0]);
        $this->setValue('middle_name', $firstNameParts[1]);
      }
    }
    elseif (isset($firstNameParts[0]) && mb_strlen($firstNameParts[0]) === 1) {
      // First name is 'B' let's accept it as a match with 'Bob'
      if (stripos(mb_strtolower($contact2['first_name']), mb_strtolower($firstNameParts[0])) === 0) {
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
    if (isset($lastNameParts[1]) && mb_strlen($lastNameParts[0]) === 1) {
      $lastNamePart = array_pop($lastNameParts);
      if ($lastNamePart === $contact2['last_name']) {
        // Last name starts with a single letter - could be an initial.
        // Let's see if we have a case where the last name includes one or more initials.
        // Note it's possible the last name could also include a suffix - that's a headache for next round.
        if (empty($contact2['middle_name'])
          || $contact2['middle_name'] === implode(' ', $lastNameParts)
        ) {
          $this->setResolvedValue('last_name', $lastNamePart);
          $this->setValue('middle_name', implode(' ', $lastNameParts));
        }
      }
    }
    elseif (isset($lastNameParts[0]) && mb_strlen($lastNameParts[0]) === 1) {
      // Last name is 'B' let's accept it as a match with 'Bob'
      if (stripos(mb_strtolower($contact2['last_name']), mb_strtolower($lastNameParts[0])) === 0) {
        $this->setResolvedValue('last_name', $contact2['last_name']);
      }
    }
  }

}
