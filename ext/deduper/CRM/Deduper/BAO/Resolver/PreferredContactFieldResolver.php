<?php

/**
 * Class CRM_Deduperr_BAO_Resolver_PreferredContactFieldResolver
 */
class CRM_Deduper_BAO_Resolver_PreferredContactFieldResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolveConflicts() {
    $fieldsAffected = $this->getFieldsToResolveOnPreferredContact();
    foreach ($fieldsAffected as $field) {
      $this->setResolvedValue($field, $this->getPreferredContactValue($field));
    }
  }

}
