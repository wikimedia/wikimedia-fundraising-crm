<?php

/**
 * Class CRM_Deduper_BAO_Resolver_MisplacedNameResolver
 */
class CRM_Deduper_BAO_Resolver_SubsetNameResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts where we have a record in the contact_name_pairs table telling us the names are equivalent.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolveConflicts() {
    $fields = (array) $this->getSetting('deduper_subset_name_handling');
    $strings = explode(',', (string) $this->getSetting('deduper_subset_name_handling_abort_strings'));
    foreach ($fields as $field) {
      if (!$this->isFieldInConflict($field)) {
        continue;
      }

      $toKeepValue = $this->getValueForField($field, TRUE);
      $toRemoveValue = $this->getValueForField($field, FALSE);
      if (!is_string($toRemoveValue) || !is_string($toKeepValue)) {
        continue;
      }
      $toRemoveValue = strtolower($toRemoveValue);
      $toKeepValue = strtolower($toKeepValue);
      foreach ([$toRemoveValue, $toKeepValue] as $name) {
        foreach ($strings as $string) {
          if (str_contains($name, trim($string))) {
            return;
          }
        }
      }

      if (str_contains($toRemoveValue, $toKeepValue)
        || str_contains($toKeepValue, $toRemoveValue)
      ) {
        $this->setResolvedValue($field, $this->getPreferredContactValue($field));
      }
    }

  }

}
