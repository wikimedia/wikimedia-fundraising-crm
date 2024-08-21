<?php

use CRM_Deduper_ExtensionUtil as E;

/**
 * Class CRM_Deduper_BAO_Resolver_UninformativeCharactersResolver
 */
class CRM_Deduper_BAO_Resolver_UninformativeCharactersResolver extends CRM_Deduper_BAO_Resolver {

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
        if ($this->isStripPunctuationForField($fieldName, $contact1, $contact2)) {
          $value = str_replace($this->getWhiteSpaceCharacters(), ' ', $value ?: '');
          $contact2Value = str_replace($this->getWhiteSpaceCharacters(), ' ', $contact2[$fieldName] ?: '');
          // Get rid of any double spaces now created. Perhaps a preg_replace would be better
          // but ... later.
          $value = trim(str_replace('  ', ' ', $value));
          $contact2Value = trim(str_replace('  ', ' ', $contact2Value));
          $value = str_replace($this->getPunctuationCharactersToRemove(), '', $value);
          $contact2Value = str_replace($this->getPunctuationCharactersToRemove(), '', $contact2Value);
          $this->setContactValue($fieldName, $value, $isContactToKeep);
          $fullyReplacedValue = str_replace($this->getPunctuationCharactersToEvaluate(), '', $value);
          $fullyReplacedContact2Value = str_replace($this->getPunctuationCharactersToEvaluate(), '', $contact2Value);
          if ($fullyReplacedValue !== $value && mb_strtolower($fullyReplacedValue) === mb_strtolower($fullyReplacedContact2Value)) {
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

  /**
   * Should we strip the punctuation for this field.
   *
   * The current thinking is that we would strip out punctuation if the fields are in conflict OR
   * either one is empty.
   *
   * In the latter case we are thinking about situations where the middle name might
   * not be in conflict but is J. whereas the first name is Bob J. In order to resolve the initial
   * later on we need both fields to have lost their dots.
   *
   * @param string $fieldName
   * @param array $contact1
   * @param array $contact2
   *
   * @return bool
   */
  protected function isStripPunctuationForField($fieldName, array $contact1, array $contact2): bool {
    if (!empty($contact1[$fieldName]) && !empty($contact2[$fieldName])) {
      return $this->isFieldInConflict($fieldName);
    }
    if (empty($contact1[$fieldName]) && empty($contact2[$fieldName])) {
      return FALSE;
    }
    return TRUE;
  }

}
