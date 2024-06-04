<?php

use CRM_Deduper_ExtensionUtil as E;

/**
 * Class CRM_Deduper_BAO_Resolver_MisplacedNameResolver
 */
class CRM_Deduper_BAO_Resolver_MisplacedNameResolver extends CRM_Deduper_BAO_Resolver {

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
      if ($this->isFieldInConflict('last_name')) {
        if (empty($contact1['first_name'])) {
          // First is empty - but perhaps if we assume the first name is the first
          // part of the last name field the contact disappears.
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
        elseif (strpos($contact1['last_name'], $contact1['first_name'] . ' ') === 0) {
          // The first name is not empty, but it could be repeated in the last name.
          // In this scenario we have first_name = 'Bob', last_name = 'Bob Max Smith'
          // being compared with first_name = 'Bob', 'last_name' = 'Max Smith.
          // At this point we say 'if the last name starts with the first name + 1 space
          // then set the last name to be the last name without the first name'. If that is then a match it
          // will wind up resolved. Otherwise, maybe a later resolver will get there.
          // This feels 'safe' given the need for the first_name + a space to be the first
          // part of the last name.
          $length = strlen($contact1['first_name'] . ' ');
          $this->setContactValue('last_name', substr($contact1['last_name'], $length), $isContactToKeep);
        }
      }
      if ($this->isFieldInConflict('first_name')) {
        if (empty($contact1['last_name'])) {
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
        // If first name & last name are the revers of each other, use names from preferred contact.
        elseif ($this->isFieldInConflict('first_name')) {
          if ($contact1['first_name'] === $contact2['last_name']
            && $contact1['last_name'] === $contact2['first_name']
          ) {
            $this->setResolvedValue('first_name', $contact1['first_name']);
            $this->setResolvedValue('last_name', $contact1['last_name']);
          }
        }
      }
    }
  }

}
