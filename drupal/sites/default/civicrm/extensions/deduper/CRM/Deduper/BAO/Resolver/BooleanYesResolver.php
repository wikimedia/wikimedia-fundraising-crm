<?php

use CRM_Deduper_ExtensionUtil as E;

/**
 * Class CRM_Deduper_BAO_Resolver_BooleanYesResolver
 */
class CRM_Deduper_BAO_Resolver_BooleanYesResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   */
  public function resolveConflicts(): void {
    $fieldsToResolve = (array) Civi::settings()->get('deduper_resolver_bool_prefer_yes');
    $conflictedFields = $this->getFieldsInConflict();
    $fieldsAffected = array_intersect($fieldsToResolve, $conflictedFields);
    foreach ($fieldsAffected as $field) {
      $this->setResolvedValue($field, 1);
    }
    foreach ($conflictedFields as $conflictedField) {
      if (strpos($conflictedField, 'location_email') === 0) {
        $emailBlockNumber = intval(str_replace('location_email_', '', $conflictedField));
        $emailDifferences = $this->getEmailConflicts($emailBlockNumber);
        foreach ($emailDifferences as $fieldName => $emailDifference) {
          if (in_array($fieldName, $fieldsToResolve, TRUE)) {
            $this->setResolvedLocationValue($fieldName, 'email', $emailBlockNumber, 1);
          }
        }
      }
    }
  }

}
