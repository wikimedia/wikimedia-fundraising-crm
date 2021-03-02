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
   * with the least number of capitals, that is not all lower case.
   *
   * In order to cope with possible initials in the name we do this by word - so
   * a last_name conflict of 'T SMITH' vs 'Smith' should be able to handle 'Smith'
   * even though the full string doesn't match.
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
        $wordsInContact1 = explode(' ', $contact1Value);
        $wordsInContact2 = explode(' ', $contact2Value);
        foreach ($wordsInContact1 as $wordIndexContact1 => $wordInContact1) {
          // Check each word in the contact's name field to see if it occurs in the same name field
          // in the other contact.
          if (in_array($wordInContact1, $wordsInContact2, TRUE)) {
            // There is an edge possibility the same word could appear more than one with different degrees of case matching
            // so bail here.
            continue;
          }
          foreach ($wordsInContact2 as $wordIndexContact2 => $wordInContact2) {
            // If the other contact has the same word with different capitalisation pick one (see function comments).
            if (strtoupper($wordInContact1) === strtoupper($wordInContact2)) {
              if ($this->isBetterCapitalization($wordInContact1, $wordInContact2)) {
                $wordsInContact1[$wordIndexContact1] = $wordInContact2;
                $this->setContactValue($fieldName, implode(' ', $wordsInContact1), TRUE);
              }
              else {
                $wordsInContact2[$wordIndexContact2] = $wordInContact1;
                $this->setContactValue($fieldName, implode(' ', $wordsInContact2), FALSE);
              }
            }
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
  protected function countCapitalLetters($string): int {
    return strlen(preg_replace('/[^A-Z]+/', '', $string));
  }

  /**
   * Is word 2 better than word 1 from a capitalisation point of view.
   *
   * We define 'better' as the least capital letters, but more than zero.
   *
   * @param string $word1
   * @param string $word2
   *
   * @return bool
   *   Word 2 is better
   */
  protected function isBetterCapitalization($word1, $word2): bool {
    return $this->countCapitalLetters($word1) === 0
      || ($this->countCapitalLetters($word2) > 0 && $this->countCapitalLetters($word2) < $this->countCapitalLetters($word1)
    );
  }

}
