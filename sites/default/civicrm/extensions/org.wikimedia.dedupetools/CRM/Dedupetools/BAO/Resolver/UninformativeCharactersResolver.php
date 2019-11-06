<?php

use CRM_Dedupetools_ExtensionUtil as E;

/**
 * Class CRM_Dedupetools_BAO_Resolver_UninformativeCharactersResolver
 */
class CRM_Dedupetools_BAO_Resolver_UninformativeCharactersResolver extends CRM_Dedupetools_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   *
   * Here we are removing non alphabetical characters from name fields.
   *
   * Note that we should consider making it optional which fields this resolver applies to
   * but for now it's name fields.
   */
  public function resolveConflicts() {
    if (!$this->hasIndividualNameFieldConflict()) {
      return;
    }
    foreach ([TRUE, FALSE] as $isContactToKeep) {
      $contact1 = $this->getIndividualNameFieldValues($isContactToKeep);
      $contact2 = $this->getIndividualNameFieldValues(!$isContactToKeep);
      foreach ($contact1 as $fieldName => $value) {
        if ($this->isFieldInConflict($fieldName)) {
          $value = str_replace($this->getWhiteSpaceCharacters(), ' ', $value);
          // Get rid of any double spaces now created. Perhaps a preg_replace would be better
          // but ... later.
          $value = trim(str_replace('  ', ' ', $value));
          $value = str_replace($this->getPunctuationCharactersToRemove(), '', $value);
          $this->setContactValue($fieldName, $value, $isContactToKeep);
          $fullyReplacedValue = str_replace($this->getPunctuationCharactersToEvaluate(), '', $value);
          if ($fullyReplacedValue !== $value && $fullyReplacedValue === $contact2[$fieldName]) {
            // In this case we will prefer the one with the extra punctuation as it might
            // be O'Connell vs OConnell or a hyphenated name. This is a margin call but
            // we could add more configurability.
            $this->setContactValue($fieldName, $value, !$isContactToKeep);
          }
        }
      }
    }
  }

  /**
   * Get whitespace characters to swap for spaces.
   *
   * @return array
   */
  protected function getWhiteSpaceCharacters(): array {
    $whitespaceChars = [
      // Taken from trim spec
      // ' ' leave out space for now.
      "\t",
      "\n",
      "\r",
      "\0",
      "\x0B",
      // And lets add a couple more we are too familiar with
      // We probably removed these from the name fields already but if we extend
      // this to other fields they may not be clean.
      "&nbsp;",
      // Hex nbsp
      '/\xC2\xA0/',
      // double space.
      '  '
    ];
    return $whitespaceChars;
  }

  /**
   * Get punctuation characters that we should remove at this stage.
   *
   * Removing them, even if it does not resolve a conflict will help later evaluators.
   *
   * @return array
   */
  protected function getPunctuationCharactersToRemove(): array {
    $punctuationChars = [
      '.',
      ':',
      ';',
      '_',
      '?',
      '(',
      ')',
      '!',
      '"',
      // Exclude ' and '-' from the main list as they are frequently valid, handle separately
    ];
    return $punctuationChars;
  }

  /**
   * Get punctuation characters that we should evaluate at this stage.
   *
   * We evaluate these to see if they are the difference between conflict & no conflict.
   *
   * @return array
   */
  protected function getPunctuationCharactersToEvaluate(): array {
    return [
      ',',
      '&',
      '-',
      "'",
      ' ',
      // ideographic space.
      "\xE3\x80\x80",
    ];
  }

}
