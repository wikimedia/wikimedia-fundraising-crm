<?php

use CRM_Dedupetools_ExtensionUtil as E;

/**
 * Class CRM_Dedupetools_BAO_Resolver_BooleanYesResolver
 */
class CRM_Dedupetools_BAO_Resolver_BooleanYesResolver extends CRM_Dedupetools_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   */
  public function resolveConflicts() {
    $fieldsToResolve = (array) Civi::settings()->get('deduper_resolver_bool_prefer_yes');
    $conflictedFields = (array) $this->getFieldsInConflict();
    $fieldsAffected = array_intersect($fieldsToResolve, $conflictedFields);
    foreach ($fieldsAffected as $field) {
      $this->setResolvedValue($field, 1);
    }

  }

}
