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
  public static function getBooleanFields(): array {
    $booleanFields = [];
    $fields = civicrm_api3('Contact', 'getfields', [])['values'];
    $emailFields = civicrm_api3('Email', 'getfields', ['action' => 'create'])['values'];
    $ignoreList = ['skip_greeting_processing', 'is_primary', 'is_deleted', 'contact_is_deleted', 'dupe_check', 'uf_user'];
    foreach (array_merge($fields, $emailFields) as $fieldName => $fieldSpec) {
      if (!in_array($fieldName, $ignoreList)
        && isset($fieldSpec['type'])
        && (
          // As of CiviCRM 5.20 on_hold is a boolean field unless civimail_multiple_bulk_emails
          // is enabled, at which point it becomes a 3-way toggle (with theory being that opt_out is
          // set per email rather than per contact so we get 0 = no, 1 = bounce (regardless)
          // and then 2 = opt out IF the setting is in play.
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

  /**
   * Get Contact fields as a name => title array.
   *
   * @return array
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function getContactFields(): array {
    $fields = civicrm_api3('Contact', 'getfields', ['action' => 'get'])['values'];
    $generalFields = [];
    foreach ($fields as $key => $field) {
      if ((isset($field['entity']) && $field['entity'] === 'Contact')
      || !empty($field['extends'])) {
        // Only add genuine contact & contact custom fields - not stuff like 'street address'
        // that getfields retrieves.
        $generalFields[$key] = $field['title'];
      }
    }
    return $generalFields;
  }

  /**
   * Get the criteria for determining the contact whose data should be preferred.
   *
   * @return array
   */
  public static function getPreferredContactCriteria(): array {
    return [
      'most_recently_created_contact' => E::ts('More recently created contact'),
      'earliest_created_contact' => E::ts('Less recently created contact'),
      'most_recently_modified_contact' => E::ts('More recently modified contact'),
      'earliest_modified_contact' => E::ts('Less recently modified contact'),
      'most_recent_contributor' => E::ts('Contact with most recent contribution.'),
      'most_prolific_contributor' => E::ts('Contact with most contributions.'),
    ];
  }

  /**
   * Get the criteria for determining the contact whose data should be preferred if other methods fail.
   *
   * @return array
   */
  public static function getPreferredContactCriteriaFallback(): array {
    return array_intersect_key(self::getPreferredContactCriteria(),
      array_fill_keys([
        'most_recently_created_contact',
        'earliest_created_contact'
      ], 1)
    );
  }
}
