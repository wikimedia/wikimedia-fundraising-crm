<?php

/**
 * Class CRM_Dedupetools_BAO_Resolver_PreferredContactFieldResolver
 */
class CRM_Dedupetools_BAO_Resolver_PreferredContactFieldResolver extends CRM_Dedupetools_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   */
  public function resolveConflicts() {
    $fieldsToResolve = (array) Civi::settings()->get('deduper_resolver_field_prefer_preferred_contact');
    $conflictedFields = (array) $this->getFieldsInConflict();
    $fieldsAffected = array_intersect($fieldsToResolve, $conflictedFields);
    foreach ($fieldsAffected as $field) {
      $this->setResolvedValue($field, $this->getPreferredContactValue($field));
    }
  }

}
