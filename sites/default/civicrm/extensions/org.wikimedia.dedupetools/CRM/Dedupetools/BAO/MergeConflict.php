<?php

use CRM_Dedupetools_ExtensionUtil as E;

class CRM_Dedupetools_BAO_MergeConflict extends CRM_Dedupetools_DAO_MergeConflict {

  /**
   * Get boolean fields that may be involved in merges.
   *
   * These are fields which can be resolved by forcing to no or yes.
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function getBooleanFields() {
    $booleanFields = [];
    $fields = civicrm_api3('Contact', 'getfields', [])['values'];
    $emailFields = civicrm_api3('Email', 'getfields', ['action' => 'create'])['values'];
    $ignoreList = ['skip_greeting_processing', 'is_primary', 'is_deleted', 'contact_is_deleted', 'dupe_check', 'uf_user'];
    foreach (array_merge($fields, $emailFields) as $fieldName => $fieldSpec) {
      if (!in_array($fieldName, $ignoreList)
        && isset($fieldSpec['type'])
        && (
          $fieldSpec['type'] === CRM_Utils_Type::T_BOOLEAN
          || ($fieldName === 'on_hold' && !Civi::settings()->get('civimail_multiple_bulk_emails'))
        )
      ) {
        $prefix = CRM_Utils_Array::value('entity', $fieldSpec) === 'Email' ? E::ts('Email::') : '';
        $booleanFields[$fieldSpec['name']] = $prefix . $fieldSpec['title'];
      }
    }
    return $booleanFields;

  }

}
