<?php

/**
 * CRM_Deduper_BAO_Resolver_EquivalentNameResolver
 */
class CRM_Deduper_BAO_Resolver_EquivalentNameResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Potential alternatives for name.
   *
   * @var array
   */
  protected $alternatives = [];

  /**
   * Setting for name handling.
   *
   * @var string
   */
  protected $nameHandlingSetting;

  /**
   * Should the nick name be retained.
   *
   * @var string
   */
  protected $isKeepNickName;

  /**
   * Is the nick name preferred.
   *
   * @var bool
   */
  protected $isPreferNickName;

  /**
   * Is the other name preferred.
   *
   * @var bool
   */
  protected $isPreferOtherName;

  /**
   * Should the preferred contact's value be the resolution.
   *
   * @var bool
   */
  protected $isResolveByPreferredContact;

  /**
   * Resolve conflicts where we have a record in the contact_name_pairs table telling us the names are equivalent.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolveConflicts() {
    if (!$this->hasIndividualNameFieldConflict()) {
      return;
    }
    $this->interpretSetting();

    $contact1 = $this->getIndividualNameFieldValues(TRUE);
    $contact2 = $this->getIndividualNameFieldValues(FALSE);

    if ($this->isFieldInConflict('first_name')) {
      $contact1FirstName = $contact1['first_name'];
      $contact2FirstName = $contact2['first_name'];

      $this->loadAlternatives($contact1FirstName);
      $this->loadAlternatives($contact2FirstName);
      $hasNickName = !empty($contact1['nick_name']) || !empty($contact2['nick_name']);
      $this->resolveNamesForPair($contact1FirstName, $contact2FirstName, TRUE, $hasNickName);
      $this->resolveNamesForPair($contact2FirstName, $contact1FirstName, FALSE, $hasNickName);
      if ($this->isResolveEquivalentNamesOnPreferredContact($contact1FirstName, $contact2FirstName)) {
        $this->setResolvedValue('first_name', $this->getPreferredContactValue('first_name'));
      }
    }
  }

  /**
   * Load alternative variants of the given name.
   *
   * @param string $value
   *
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function loadAlternatives($value) {
    if (isset($this->alternatives[$value])) {
      return;
    }
    if (!\Civi::cache('dedupe_pairs')->has('name_alternatives_' . md5($value))) {
      $namePair = \Civi\Api4\ContactNamePair::get()
        ->addClause('OR', ['name_a', '=', $value], ['name_b', '=', $value])
        ->setCheckPermissions(FALSE)
        ->execute();
      $alternatives = ['inferior_version_of' => [], 'nick_name_of' => []];
      foreach ($namePair as $pair) {
        if ($pair['name_b'] === $value) {
          if ($pair['is_name_b_inferior']) {
            $alternatives['inferior_version_of'][] = $pair['name_a'];
          }
          else {
            $alternatives['alternative_of'][] = $pair['name_a'];
          }
          if ($pair['is_name_b_nickname']) {
            $alternatives['nick_name_of'][] = $pair['name_a'];
          }
        }
        else {
          $alternatives['alternative_of'][] = $pair['name_b'];
        }
      }
      \Civi::cache('dedupe_pairs')->set('name_alternatives_' . md5($value), $alternatives);
    }
    $this->alternatives[$value] = \Civi::cache('dedupe_pairs')->get('name_alternatives_' . md5($value));
  }

  /**
   * Is this a known misspelling / less preferred version.
   *
   * We plan to expose saving these in the deduper.
   *
   * @param string $value
   *
   * @return bool
   */
  protected function isInferior($value): bool {
    if (is_numeric($value)) {
      return TRUE;
    }
    return !empty($this->alternatives[$value]['inferior_version_of']);
  }

  /**
   * Resolve a misspelling / less preferred version.
   *
   * If there is only one potential alternative we choose it. If there is more than one we
   * look for one which would resolve the conflict.
   *
   * @param string $value
   * @param string $fieldName
   * @param bool $isContactToKeep
   * @param string $otherContactValue
   */
  protected function resolveInferiorValue($value, $fieldName, $isContactToKeep, string $otherContactValue) {
    $inferiorVersionOf = $this->alternatives[$value]['inferior_version_of'];
    if (count($inferiorVersionOf) === 1) {
      $this->setContactValue($fieldName, $inferiorVersionOf[0], $isContactToKeep);
    }
    elseif (in_array($otherContactValue, $inferiorVersionOf, TRUE)) {
      $this->setContactValue($fieldName, $otherContactValue, $isContactToKeep);
    }
  }

  /**
   * Are there alternatives to consider using instead.
   *
   * @return bool
   */
  protected function hasAlternatives($value): bool {
    if (empty($this->alternatives[$value]['alternative_of'])) {
      return FALSE;
    }
    $viableAlternatives = array_intersect_key($this->alternatives, array_fill_keys($this->alternatives[$value]['alternative_of'], 1));
    return !empty($viableAlternatives);
  }

  /**
   * Do the 2 contacts have equivalent names.
   *
   * Equivalent names are interchangeable with no specified preference.
   *
   * (ie. a specified preference would be when one is a inferior, like a misspelling).
   *
   * @param string $name1
   * @param string $name2
   *
   * @return bool
   */
  protected function isResolveEquivalentNamesOnPreferredContact($name1, $name2) {
    if (!$this->isResolveByPreferredContact) {
      // This is kind of short hand. We don't currently permit a combination of
      // prefer nick name but fall back on preferred contact. Preferred contact 'stands alone'
      // so we can filter by it. It's conceivable we would add prefer_non_nick_name_then_prefer_preferred_contact
      // and we'd need to do more work here but for now.
      return FALSE;
    }
    if ($this->isInferior($name1)) {
      return FALSE;
    }
    if ($this->isInferior($name2)) {
      return FALSE;
    }
    if (!empty($this->hasAlternatives($name1))) {
      return TRUE;
    }
    if (!empty($this->hasAlternatives($name2))) {
      return TRUE;
    }
  }

  /**
   * Is the given value the nickname of the other contact's name
   *
   * @param string $value
   * @param string $otherValue
   *
   * @return bool
   */
  protected function isNickNameOf($value, $otherValue):bool {
    if (empty($this->alternatives[$value]['nick_name_of'])) {
      return FALSE;
    }
    return in_array($otherValue, $this->alternatives[$value]['nick_name_of'], TRUE);
  }

  /**
   * Interpret the setting into its components.
   */
  protected function interpretSetting() {
    $this->nameHandlingSetting = $this->getSetting('deduper_equivalent_name_handling');
    if (in_array($this->nameHandlingSetting, ['prefer_non_nick_name_keep_nick_name', 'prefer_preferred_contact_value_keep_nick_name'], TRUE)) {
      $this->isKeepNickName = TRUE;
    }
    if ($this->nameHandlingSetting === 'prefer_nick_name') {
      $this->isPreferNickName = TRUE;
    }
    if (in_array($this->nameHandlingSetting, ['prefer_non_nick_name', 'prefer_non_nick_name_keep_nick_name'], TRUE)) {
      $this->isPreferOtherName = TRUE;
    }
    if (in_array($this->nameHandlingSetting, ['prefer_preferred_contact_value', 'prefer_preferred_contact_value_keep_nick_name'], TRUE)) {
      $this->isResolveByPreferredContact = TRUE;
    }
  }

  /**
   * Resolve names for the given pair if they are equivalent names.
   *
   * @param string $name
   * @param string $otherName
   * @param bool $isContactToKeep
   * @param bool $hasNickName
   * @param string $fieldName
   *
   * @throws \CRM_Core_Exception
   */
  protected function resolveNamesForPair($name, $otherName, $isContactToKeep, $hasNickName, $fieldName = 'first_name') {
    if ($this->isInferior($name)) {
      $this->resolveInferiorValue($name, 'first_name', $isContactToKeep, $otherName);
    }

    if ($this->nameHandlingSetting && $this->hasAlternatives($name)) {
      if (!$hasNickName && $this->isNickNameOf($name, $otherName)) {
        if ($this->isKeepNickName) {
          // Always set for the contact Not to keep to force it to be saved since it is on
          // neither contact at the moment.
          $this->setContactValue('nick_name', $name, FALSE);
        }
        if ($this->isPreferNickName) {
          $this->setResolvedValue($fieldName, $name);
        }
        if ($this->isPreferOtherName) {
          $this->setResolvedValue($fieldName, $otherName);
        }
      }
    }
  }

}
