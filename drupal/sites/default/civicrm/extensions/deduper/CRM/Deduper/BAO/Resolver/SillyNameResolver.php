<?php

/**
 * Class CRM_Deduper_BAO_Resolver_SillyNameResolver
 */
class CRM_Deduper_BAO_Resolver_SillyNameResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts where the issue is a silly name (ie. one that we can reasonably assume not to be real).
   */
  public function resolveConflicts() {
    if (!$this->hasIndividualNameFieldConflict()) {
      return;
    }
    foreach ([TRUE, FALSE] as $isContactToKeep) {
      $contact1 = $this->getIndividualNameFieldValues($isContactToKeep);
      $contact2 = $this->getIndividualNameFieldValues(!$isContactToKeep);
      foreach ($contact1 as $fieldName => $value) {
        if ($this->isFieldInConflict($fieldName) && $this->isSilly($value)) {
          $this->setResolvedValue($fieldName, $contact2[$fieldName]);
        }
      }
    }
  }

  /**
   * Is this a known silly value.
   *
   * We could make this configurable but probably for now it's better to make people
   * request additions so we can build up a better 'common list'.
   *
   * @param string $value
   *
   * @return bool
   */
  protected function isSilly($value) {
    if (is_numeric($value)) {
      return TRUE;
    }
    $knownSillyNames = ['first', 'last', 'blah', 'none'];
    if (in_array(strtolower(trim($value)), $knownSillyNames, TRUE)) {
      return TRUE;
    }
  }
}
