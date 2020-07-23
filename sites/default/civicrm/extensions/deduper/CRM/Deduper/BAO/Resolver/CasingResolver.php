<?php

use CRM_Deduper_ExtensionUtil as E;

/**
 * Class CRM_Deduper_BAO_Resolver_BooleanYesResolver
 */
class CRM_Deduper_BAO_Resolver_CasingResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts that are only about name casing.
   *
   * If we have a conflict of 'Sarah' vs 'sarah' vs 'SARAH' we choose the one
   * with
   * the least number of capitals, that is not all lower case.
   *
   * This is not perfect but only on edge cases would another choice be better
   * and, importantly, all these variants have been entered at some point
   * deliberately.
   *
   * The most common poor-data-options are not using the caps key at all, or
   * having caps lock on - so this formula prefers mixed caps.
   */
  public function resolveConflicts() {
    if (!$this->hasNameFieldConflict()) {
      return;
    }
    $contact1 = $this->getNameFieldValues(TRUE);
    $contact2 = $this->getNameFieldValues(FALSE);
    foreach ($contact1 as $fieldName => $contact1Value) {
      if ($this->isFieldInConflict($fieldName)) {
        $contact2Value = $contact2[$fieldName];
        if (strtoupper($contact1Value) === strtoupper($contact2Value)) {
          if ($this->countCapitalLetters($contact1Value) === 0) {
            $this->setResolvedValue($fieldName, $contact2Value);
          }
          elseif ($this->countCapitalLetters($contact2Value) === 0) {
            $this->setResolvedValue($fieldName, $contact1Value);
          }
          else {
            $valueToKeep = ($this->countCapitalLetters($contact1Value) <= $this->countCapitalLetters($contact2Value)) ? $contact1Value : $contact2Value;
            $this->setResolvedValue($fieldName, $valueToKeep);
          }
        }
      }
    }
  }

  /**
   *  * Count the number of capital letters in a string.
   *
   * @param $string
   *
   * @return int
   */
  protected function countCapitalLetters($string) {
    return strlen(preg_replace('/[^A-Z]+/', '', $string));
  }

}
