<?php

/**
 * MergeConflict.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_merge_conflict_create_spec(&$spec) {
  // $spec['some_parameter']['api.required'] = 1;
}

/**
 * MergeConflict.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_merge_conflict_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MergeConflict.delete API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_merge_conflict_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MergeConflict.get API
 *
 * @param array $params
 *
 * @return array API result descriptor
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_merge_conflict_get($params) {

  CRM_Core_DAO::executeQuery('
  INSERT INTO civicrm_merge_conflict (contact_1, contact_2)
  SELECT DISTINCT
    entity_id1 as contact_1,
    entity_id2 as contact_2
    FROM civicrm_prevnext_cache pn
    LEFT JOIN civicrm_merge_conflict mc
    ON mc.contact_1 = pn.entity_id1 AND mc.contact_2 =pn.entity_id2
    WHERE cachekey LIKE "merge%conflicts"
    AND mc.id IS NULL
  ');

  $result = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_merge_conflict WHERE conflicted_field = "" LIMIT 10000');
  while ($result->fetch()) {
    $conflictData = CRM_Core_DAO::executeQuery(
      "SELECT * FROM civicrm_prevnext_cache
      WHERE entity_id1 = {$result->contact_1}
      AND entity_id2 = {$result->contact_2}
      AND cachekey LIKE 'merge%conflicts' LIMIT 1"
    );
    $conflictData->fetch();

    $keyParts = explode('_', $conflictData->cacheKey);
    $group = substr($keyParts[2], 0, strpos($keyParts[2], 'a'));
    $data = unserialize($conflictData->data);

    // Nasty string wrangling follows. Bear in mind we only need to do this fairly well as we
    // are after some data to evaluate not exact handling.
    $conflictedFieldParts = explode(':', $data['conflicts']);
    $conflictedField = $conflictedFieldParts[0];

    if (count($conflictedFieldParts) > 2) {
      // More than one conflict. Only getting one per pair so trim.
      $breakDown = explode(',', $conflictedFieldParts[1]);
      if (count($breakDown) > 2) {
        // this is getting too hard, we only want a big picture so bail & try another one.
        continue;
      }
      $conflictedFieldParts[1] = $breakDown[0];
    }

    if (count($conflictedFieldParts) > 2) {
      // More than one conflict. Only getting one per pair so trim.
      $breakDown = explode(',', $conflictedFieldParts[1]);
      if (count($breakDown) > 2) {
        // this is getting too hard, we only want a big picture so bail & try another one.
        continue;
      }
      $conflictedFieldParts[1] = $breakDown[0];
    }

    $conflictedFieldParts2 = explode("' vs. '", $conflictedFieldParts[1]);
    $value1 = substr($conflictedFieldParts2[0], 2);
    $value2 = substr($conflictedFieldParts2[1], 0, -1);
    $comparisonValue1 = strtolower($value1);
    $comparisonValue2 = strtolower($value2);

    $analysis = mergeconflict_get_analysis($value1, $value2, $comparisonValue1, $comparisonValue2, $conflictedField);

    CRM_Core_DAO::executeQuery("
    UPDATE civicrm_merge_conflict
    SET conflicted_field = %1,
    value_1 = %2, value_2 = %3, group_id = %4,
    analysis = %7
    WHERE contact_1 = %5
      AND contact_2 = %6
    ",
     array(
       1 => array($conflictedField, 'String'),
       2 => array($value1, 'String'),
       3 => array($value2, 'String'),
       4 => array($group, 'Integer'),
       5 => array($result->contact_1, 'Integer'),
       6 => array($result->contact_2, 'Integer'),
       7 => array($analysis, 'String'),
     )
    );
  }
  return civicrm_api3_create_success(1);
}

/**
 * @param $value1
 * @param $value2
 * @param $comparisonValue1
 * @param $comparisonValue2
 * @param $conflictedField
 * @return string
 */
function mergeconflict_get_analysis($value1, $value2, $comparisonValue1, $comparisonValue2, $conflictedField) {
  if ($value1 === $value2 && stristr($value2, '@')) {
    return 'email_weirdness';
  }

  $comparisonValue1 = str_replace(array("&amp;"), '&', $comparisonValue1);
  $comparisonValue2 = str_replace(array("&amp;"), '&', $comparisonValue2);
  if ($comparisonValue1 === $comparisonValue2) {
    return 'wierd_amp_yankovitch';
  }

  $comparisonValue1 = str_replace(array(' ', "\n", "\r", "\R", "\t"), '', $comparisonValue1);
  $comparisonValue2 = str_replace(array(' ', "\n", "\r", "\R", "\t"), '', $comparisonValue2);
  if ($comparisonValue1 === $comparisonValue2) {
    return 'whitespace';
  }

  $comparisonValue1 = str_replace(array("&amp;"), '&', $comparisonValue1);
  $comparisonValue2 = str_replace(array("&amp;"), '&', $comparisonValue2);
  if ($comparisonValue1 === $comparisonValue2) {
    return 'wierd_amp_yankovitch';
  }

  $comparisonValue1 = str_replace(array('.', '-', "'", '&', '#'), '', $comparisonValue1);
  $comparisonValue2 = str_replace(array('.', '-', "'", '&', '#'), '', $comparisonValue2);
  if ($comparisonValue1 === $comparisonValue2) {
    return 'punctuation';
  }

  if ($conflictedField === 'Last Name' || $conflictedField === 'First Name') {
    if (is_numeric($comparisonValue1) || is_numeric($comparisonValue2)) {
      return 'you_are_not_a_number';
    }
  }

  if (substr($conflictedField, 0, 7) === 'Address') {
    // This is a bad data situation where we were putting 0 in the city field.
    if (substr($comparisonValue1, 0, 1) == '0') {
      $comparisonValue1 = substr($comparisonValue1, 1);
    }
    if (substr($comparisonValue2, 0, 1) == '0') {
      $comparisonValue2 = substr($comparisonValue2, 1);
    }
    if ($comparisonValue1 === $comparisonValue2) {
      // dodgey city zero can still be a subset later on but we only do
      // one analysis per contact.
      return 'dodgey_city_zero';
    }

    $fullPostcodeRegex = "/\d{5}-\d{4}/";
    if (preg_match($fullPostcodeRegex, $value1, $matches)) {
      $postcodeParts = explode('-', $matches[0]);
    }
    if (preg_match($fullPostcodeRegex, $value2, $matches)) {
      $postcodeParts = explode('-', $matches[0]);
    }
    $comparisonValue1 = str_replace($postcodeParts[1], '', $comparisonValue1);
    $comparisonValue2 = str_replace($postcodeParts[1], '', $comparisonValue2);
    if (!empty($postcodeParts)) {
      if ($comparisonValue1 === $comparisonValue2) {
        return 'mishandled_postcode';
      }
    }
    $addressPieces = array(
      'road' => 'rd',
      'street' => 'st',
      'drive' => 'dr',
      'place' => 'pl',
    );
    if (str_replace(array_keys($addressPieces), $addressPieces, $comparisonValue1) === str_replace(array_keys($addressPieces), $addressPieces, $comparisonValue2)) {
      return 'address_abbreviations';
    }
  }

  $comparisonValue1 = normalizeUtf8String($comparisonValue1);
  $comparisonValue2 = normalizeUtf8String($comparisonValue2);
  if ($comparisonValue1 === $comparisonValue2) {
    return 'diacritic';
  }

  if (stristr($comparisonValue1, $comparisonValue2) || stristr($value2, $value1)) {
    return 'subset';
  }

  if (_mergeconflict_is_variant($comparisonValue1, $comparisonValue2)) {
    return 'name_variant';
  }

  if (_mergeconflict_uses_title($value1, $value2, $comparisonValue1, $comparisonValue2)) {
    return 'initials';
  }

  $similarTextPercent = 0;
  similar_text($comparisonValue1, $comparisonValue2, $similarTextPercent);

  if (strlen($comparisonValue1) > 10 && $similarTextPercent > 90) {
    return 'pretty_damn_close';
  }

  if (soundex($comparisonValue1) === soundex($comparisonValue2)) {
    return 'soundex_match';
  }

  if (metaphone($comparisonValue1) === metaphone($comparisonValue2)) {
    return 'metaphone_match';
  }

  $lev = levenshtein($comparisonValue1, $comparisonValue2);
  if ($lev) {
    $a = 1;
  }
  return 'different';
}


function _mergeconflict_is_variant($value1, $value2) {
  $map = array(
    'suzanne' => 'susan',
    'dafydd' => 'david',
    'pavel' => 'paul',
    'francis' => 'frank',
    'victoria' => 'vicky',
    'yarmolyuk' => 'yarmoliuk',
    'michail' => 'mikhail',
    'steven' => 'steve',
    'stephen' => 'steve',
    'nicolas' => 'nick',
    'robert' => 'bobby',
    'bob' => 'robert',
    'joe' => 'joseph',
    'bill' => 'william',
    'Benyamin' => 'Benjamin',
    'dave' => 'david',
    'jim' => 'james',
    'alex' => 'alexander',
  );
  $reverse = array_flip($map);
  if ((isset($map[$value1]) && $map[$value1] === $value2)
  || (isset($reverse[$value1]) && $reverse[$value1] === $value2)
  ) {
    return TRUE;
  }
  return FALSE;
}

function _mergeconflict_uses_title($value1, $value2, $comparisonValue1, $comparisonValue2) {
  $initialsRegex = '/[A-Z][\s|.]?[A-Z]?$/';
  $matches = array();
  foreach (array(array($value1, $value2), array($value2, $value1)) as $values) {
    if (preg_match($initialsRegex, $values[0], $matches)) {
      $parts = explode('-', $matches[0]);
      $initials = explode(' ', $parts[0]);
      $words = explode(' ', $values[1]);
      foreach ($initials as $index => $initial) {
        if (isset($words[$index]) && strtoupper(substr($words[$index], 0, 1)) !== $initial) {
          return FALSE;
        }
        return TRUE;
      }
    }
  }
}

/**
 * From http://nz2.php.net/manual/en/normalizer.normalize.php
 *
 * @param $original_string
 * @return mixed
 */
function normalizeUtf8String( $original_string)
{

  // maps German (umlauts) and other European characters onto two characters before just removing diacritics
  $s    = preg_replace( '@\x{00c4}@u'    , "AE",    $original_string );    // umlaut Ä => AE
  $s    = preg_replace( '@\x{00d6}@u'    , "OE",    $s );    // umlaut Ö => OE
  $s    = preg_replace( '@\x{00dc}@u'    , "UE",    $s );    // umlaut Ü => UE
  $s    = preg_replace( '@\x{00e4}@u'    , "ae",    $s );    // umlaut ä => ae
  $s    = preg_replace( '@\x{00f6}@u'    , "oe",    $s );    // umlaut ö => oe
  $s    = preg_replace( '@\x{00fc}@u'    , "ue",    $s );    // umlaut ü => ue
  $s    = preg_replace( '@\x{00f1}@u'    , "ny",    $s );    // ñ => ny
  $s    = preg_replace( '@\x{00ff}@u'    , "yu",    $s );    // ÿ => yu


  // maps special characters (characters with diacritics) on their base-character followed by the diacritical mark
  // exmaple:  Ú => U´,  á => a`
  $s    = Normalizer::normalize( $s, Normalizer::FORM_D );


  $s    = preg_replace( '@\pM@u'        , "",    $s );    // removes diacritics


  $s    = preg_replace( '@\x{00df}@u'    , "ss",    $s );    // maps German ß onto ss
  $s    = preg_replace( '@\x{00c6}@u'    , "AE",    $s );    // Æ => AE
  $s    = preg_replace( '@\x{00e6}@u'    , "ae",    $s );    // æ => ae
  $s    = preg_replace( '@\x{0132}@u'    , "IJ",    $s );    // ? => IJ
  $s    = preg_replace( '@\x{0133}@u'    , "ij",    $s );    // ? => ij
  $s    = preg_replace( '@\x{0152}@u'    , "OE",    $s );    // Œ => OE
  $s    = preg_replace( '@\x{0153}@u'    , "oe",    $s );    // œ => oe

  $s    = preg_replace( '@\x{00d0}@u'    , "D",    $s );    // Ð => D
  $s    = preg_replace( '@\x{0110}@u'    , "D",    $s );    // Ð => D
  $s    = preg_replace( '@\x{00f0}@u'    , "d",    $s );    // ð => d
  $s    = preg_replace( '@\x{0111}@u'    , "d",    $s );    // d => d
  $s    = preg_replace( '@\x{0126}@u'    , "H",    $s );    // H => H
  $s    = preg_replace( '@\x{0127}@u'    , "h",    $s );    // h => h
  $s    = preg_replace( '@\x{0131}@u'    , "i",    $s );    // i => i
  $s    = preg_replace( '@\x{0138}@u'    , "k",    $s );    // ? => k
  $s    = preg_replace( '@\x{013f}@u'    , "L",    $s );    // ? => L
  $s    = preg_replace( '@\x{0141}@u'    , "L",    $s );    // L => L
  $s    = preg_replace( '@\x{0140}@u'    , "l",    $s );    // ? => l
  $s    = preg_replace( '@\x{0142}@u'    , "l",    $s );    // l => l
  $s    = preg_replace( '@\x{014a}@u'    , "N",    $s );    // ? => N
  $s    = preg_replace( '@\x{0149}@u'    , "n",    $s );    // ? => n
  $s    = preg_replace( '@\x{014b}@u'    , "n",    $s );    // ? => n
  $s    = preg_replace( '@\x{00d8}@u'    , "O",    $s );    // Ø => O
  $s    = preg_replace( '@\x{00f8}@u'    , "o",    $s );    // ø => o
  $s    = preg_replace( '@\x{017f}@u'    , "s",    $s );    // ? => s
  $s    = preg_replace( '@\x{00de}@u'    , "T",    $s );    // Þ => T
  $s    = preg_replace( '@\x{0166}@u'    , "T",    $s );    // T => T
  $s    = preg_replace( '@\x{00fe}@u'    , "t",    $s );    // þ => t
  $s    = preg_replace( '@\x{0167}@u'    , "t",    $s );    // t => t

  // remove all non-ASCii characters
  $s    = preg_replace( '@[^\0-\x80]@u'    , "",    $s );


  // possible errors in UTF8-regular-expressions
  if (empty($s))
    return $original_string;
  else
    return $s;
}
