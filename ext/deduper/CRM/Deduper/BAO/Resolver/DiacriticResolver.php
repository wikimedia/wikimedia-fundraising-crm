<?php

use CRM_Deduper_ExtensionUtil as E;

/**
 * Class CRM_Deduper_BAO_Resolver_BooleanYesResolver
 */
class CRM_Deduper_BAO_Resolver_DiacriticResolver extends CRM_Deduper_BAO_Resolver {

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
        if (strtoupper($this->normalizeUtf8String($contact1Value)) === strtoupper($this->normalizeUtf8String($contact2Value))) {
          $valueToUse = $this->isUtfNormalised($contact1Value) ? $contact2Value : $contact1Value;
          $this->setResolvedValue($fieldName, $valueToUse);
        }
      }
    }
  }

  /**
   * Check for special characters (none latin) in the string.
   *
   * @param string $string
   *
   * @return bool
   */
  protected function isUtfNormalised($string): bool {
    if (!Normalizer::isNormalized($string, Normalizer::FORM_D)) {
      return FALSE;
    }
    $s = preg_replace('@[^\0-\x80]@u', '', $string);
    return $s === $string;

  }

  /**
   * From http://nz2.php.net/manual/en/normalizer.normalize.php
   *
   * I have since found
   * https://core.trac.wordpress.org/browser/trunk/src/wp-includes/formatting.php#L1127
   * & packages
   * https://packagist.org/packages/neitanod/forceutf8
   * & https://packagist.org/packages/patchwork/utf8
   *
   * My feeling after viewing them is that with the change below this is safe but
   * it could be more complete. There are a few tests implemented & my
   * inclination is to deploy & iterate depending on the results.
   *
   * & perhaps might try in a second iteration taking more from that.
   *
   * @param $original_string
   *
   * @return mixed
   */
  protected function normalizeUtf8String($original_string) {

    // maps German (umlauts) and other European characters onto two characters before just removing diacritics
    $s = preg_replace('@\x{00c4}@u', 'AE', $original_string);    // umlaut Ä => AE
    $s = preg_replace('@\x{00d6}@u', 'OE', $s);    // umlaut Ö => OE
    $s = preg_replace('@\x{00dc}@u', 'UE', $s);    // umlaut Ü => UE
    $s = preg_replace('@\x{00e4}@u', 'ae', $s);    // umlaut ä => ae
    $s = preg_replace('@\x{00f6}@u', 'oe', $s);    // umlaut ö => oe
    $s = preg_replace('@\x{00fc}@u', 'ue', $s);    // umlaut ü => ue
    $s = preg_replace('@\x{00f1}@u', 'ny', $s);    // ñ => ny
    $s = preg_replace('@\x{00ff}@u', 'yu', $s);    // ÿ => yu


    // maps special characters (characters with diacritics) on their base-character followed by the diacritical mark
    // exmaple:  Ú => U´,  á => a`
    $s = Normalizer::normalize($s, Normalizer::FORM_D);

    $s = preg_replace('@\pM@u', '', $s);    // removes diacritics

    $s = preg_replace('@\x{00df}@u', 'ss', $s);    // maps German ß onto ss
    $s = preg_replace('@\x{00c6}@u', 'AE', $s);    // Æ => AE
    $s = preg_replace('@\x{00e6}@u', 'ae', $s);    // æ => ae
    $s = preg_replace('@\x{0132}@u', 'IJ', $s);    // ? => IJ
    $s = preg_replace('@\x{0133}@u', 'ij', $s);    // ? => ij
    $s = preg_replace('@\x{0152}@u', 'OE', $s);    // Œ => OE
    $s = preg_replace('@\x{0153}@u', 'oe', $s);    // œ => oe

    $s = preg_replace('@\x{00d0}@u', 'D', $s);    // Ð => D
    $s = preg_replace('@\x{0110}@u', 'D', $s);    // Ð => D
    $s = preg_replace('@\x{00f0}@u', 'd', $s);    // ð => d
    $s = preg_replace('@\x{0111}@u', 'd', $s);    // d => d
    $s = preg_replace('@\x{0126}@u', 'H', $s);    // H => H
    $s = preg_replace('@\x{0127}@u', 'h', $s);    // h => h
    $s = preg_replace('@\x{0131}@u', 'i', $s);    // i => i
    $s = preg_replace('@\x{0138}@u', 'k', $s);    // ? => k
    $s = preg_replace('@\x{013f}@u', 'L', $s);    // ? => L
    $s = preg_replace('@\x{0141}@u', 'L', $s);    // L => L
    $s = preg_replace('@\x{0140}@u', 'l', $s);    // ? => l
    $s = preg_replace('@\x{0142}@u', 'l', $s);    // l => l
    $s = preg_replace('@\x{014a}@u', 'N', $s);    // ? => N
    $s = preg_replace('@\x{0149}@u', 'n', $s);    // ? => n
    $s = preg_replace('@\x{014b}@u', 'n', $s);    // ? => n
    $s = preg_replace('@\x{00d8}@u', 'O', $s);    // Ø => O
    $s = preg_replace('@\x{00f8}@u', 'o', $s);    // ø => o
    $s = preg_replace('@\x{017f}@u', 's', $s);    // ? => s
    $s = preg_replace('@\x{00de}@u', 'T', $s);    // Þ => T
    $s = preg_replace('@\x{0166}@u', 'T', $s);    // T => T
    $s = preg_replace('@\x{00fe}@u', 't', $s);    // þ => t
    $s = preg_replace('@\x{0167}@u', 't', $s);    // t => t

    // remove all non-ASCii characters
    // This is in the original function but is too broad. There is a test that will fail
    // if you remove this.
    // $s    = preg_replace( '@[^\0-\x80]@u'    , "",    $s );

    // possible errors in UTF8-regular-expressions
    if (empty($s)) {
      return $original_string;
    }
    return $s;
  }

}
