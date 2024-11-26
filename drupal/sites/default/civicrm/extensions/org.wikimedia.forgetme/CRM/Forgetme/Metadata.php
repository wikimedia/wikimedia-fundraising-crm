<?php
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 7/5/18
 * Time: 2:01 PM
 */

class CRM_Forgetme_Metadata {

  /**
   * @param $entityName
   * @param $metadataType
   *
   * @return mixed
   */
  public static function getMetadataForEntity($entityName, $metadataType) {
    $metadata = self::getEntitiesMetadata();
    $table = self::getTableName($entityName);
    return isset($metadata[$table][$metadataType]) ? $metadata[$table][$metadataType] : [];
  }

  /**
   * Get a list of the entities to delete.
   */
  public static function getEntitiesToDelete() {
    $deleteEntities = [];
    $metadata = self::getEntitiesMetadata();
    foreach ($metadata as $entity => $spec) {
      if (!empty($spec['forgetme'])) {
        $deleteEntities[$entity] = self::getEntityName($entity);
      }
    }
    return $deleteEntities;
  }

  /**
   * Get an array of all entities with forget actions.
   *
   * We cache this for mild performance gain but it's not clear php caching
   * helps us much as this is not often called multiple times within one php call.
   *
   * However, once we have upgraded & switched to Redis caching we could move this
   * over and probably get more benefit.
   *
   * @return array
   */
  public static function getEntitiesToForget() {
    if (!isset(\Civi::$statics[__CLASS__]['forget_entities'])) {
      $forgets = _civicrm_api3_showme_get_entities_with_action('forgetme');
      unset($forgets[array_search('Contact', $forgets)]);
      uasort($forgets, function($a, $b) { return($b !== 'Logging'); });
      \Civi::$statics[__CLASS__]['forget_entities'] = $forgets;
    }
    return \Civi::$statics[__CLASS__]['forget_entities'];
  }

  /**
   * Describe what to do with the various entities.
   *
   * All entities that interact with contacts are listed for information value.
   * Originally I was going to display any that existed, until I realised that in some
   * cases doing that could expose information about another contact - the specific
   * example is that a if we start from an organisation some contacts might
   * have that row as 'employer_id' and the employee's name row would be returned.
   *
   * From the privacy policty PI includes - your real name, address, phone number, email address, password,
   * identification number on government-issued ID, IP address, web browser user-agent information,
   * credit or debit card number, bank account number and routing number,
   * personal identification number in association with the relevant account; and
   * 1. When associated with one of the items in subsection (a), any sensitive data such as date of birth,
   * gender, sexual orientation, racial or ethnic origins, marital or familial status,
   * medical conditions or disabilities, political affiliation, and religion.
   *
   * We don't record password, identification number on government-issued ID,
   * credit or debit card number, bank account number and routing number. Any personal data from the
   * second set would be in custom fields which we should clear. However, relationship data is a bit
   * tricky as we would show 'spouse of' when we have both contacts in the DB & that info still
   * applies to the other spouse...
   *
   * Entities are can have the following values :
   *   - showme - display on forgetmet screen
   *   - forgetme - delete entry on 'forget'
   *   - forget_fields - only forget these fields
   *   - forget_filters - delete entries that match this filter.
   *
   * @return array
   */
  public static function getEntitiesMetadata() {
    $entities = [
      'civicrm_contact' => [
        // Only delete limited contact fields, retain name data.
        'showme' => TRUE,
        'forget_fields' => self::getContactFieldMetadata(['gender_id', 'birth_date']),
        'custom_forget_fields' => self::getContactCustomFields(),
        'internal_fields' => [
          'hash',
          'api_key',
          'sort_name',
          'created_date',
          'modified_date',
          // Phone has a showme so we can hide here.
          'phone_id',
          'phone',
          'phone_type_id',
        ],
        'negative_fields' => [
          'do_not_email',
          'do_not_trade',
          'do_not_phone',
          'do_not_email',
          'do_not_mail',
          'do_not_sms',
          'is_opt_out',
          'is_deceased',
          'is_deleted',
          'contact_is_deleted',
          'on_hold',
        ],
      ],
      // These entities should be shown but not deleted.
      'civicrm_address' => [
        'showme' => TRUE,
      ],
      'civicrm_contribution' => [
        'showme' => TRUE,
        ],
      'civicrm_contribution_recur' => [
        'showme' => TRUE,
      ],
      'civicrm_contribution_soft' => [
        'showme' => TRUE,
      ],
      'civicrm_participant' => [
        'showme' => TRUE,
      ],
      // These entities should be shown and deleted.
      'civicrm_note' => ['showme' => TRUE, 'forgetme' => TRUE,],
      'civicrm_phone' => [
        'showme' => TRUE,
        'forgetme' => TRUE,
        'internal_fields' => ['phone_numeric', 'is_billing', 'contact_id', 'is_primary', 'phone_numeric'],
      ],
      'civicrm_im' => [
        'showme' => TRUE,
        'forgetme' => TRUE,
        'internal_fields' => ['contact_id']
       ],
      'civicrm_website' => [
        'showme' => TRUE,
        'forgetme' => TRUE,
        'internal_fields' => ['contact_id'],
      ],
      'civicrm_email' => [
        'showme' => TRUE,
        'forgetme' => TRUE,
        'internal_fields' => ['is_billing', 'contact_id', 'is_bulkmail', 'location_type_id', 'is_primary'],
        'negative_fields' => ['on_hold'],
       ],
      // delete activity contact records by type
      'civicrm_activity_contact' => [
        'showme' => TRUE,
        'forgetme' => TRUE,
        'forget_filters' => [
          'activity_id.activity_type_id' => [
            'NOT IN' => self::getActivityTypesToKeep(),
          ],
        ]
      ],
      // Showing relationships would be data about another contact too - just forget?
      'civicrm_relationship' => ['forgetme' => TRUE, 'keys' => ['contact_id_a', 'contact_id_b']],
      // Group membership & tags  & financial items seem
      // mostly internal info rather than personal data.
      'civicrm_group_contact' => [],
      'civicrm_subscription_history' => [],
      'civicrm_entity_tag' => [],
      'civicrm_financial_item' => [],
      'civicrm_payment_token' => [
        'showme' => TRUE,
        'forgetme' => TRUE,
      ],
      // We don't really use open id.
      //'civicrm_openid' => ['showme' => TRUE, 'forgetme' => TRUE,],
      /* I believe the tables below are not relevant to individual contacts.
      'civicrm_event',
      'civicrm_batch',
      'civicrm_mailing_abtest',
      'civicrm_financial_account',
      'civicrm_campaign',
      'civicrm_survey',
      'civicrm_event_carts',
      'civicrm_dedupe_exception',
      'civicrm_pcp',
      'civicrm_custom_group',
      'civicrm_domain',
      'civicrm_file',
      'civicrm_tag',
      'civicrm_uf_match',
      'civicrm_setting',
      'civicrm_print_label',
      'civicrm_group',
      'civicrm_group_organization',
      'civicrm_contribution_page',
      'civicrm_membership_type',
      'civicrm_case_contact',
      'civicrm_pledge',
      'civicrm_report_instance',
      'civicrm_uf_group',
      'civicrm_dashboard_contact',
      'civicrm_mailing',
      'civicrm_membership',
      */
    ];
    return $entities;
  }

  public static function getActivityTypesToKeep() {
    return [
      'Payment',
      'Refund',
      'Cancel Recurring Contribution',
      'Update Recurring Contribution Billing Details',
      'Update Recurring Contribution',
      'Contact Merged',
      'Failed Payment',
      'Contact Deleted by Merge',
      'unsubscribe',
      'contact_type_changed',
      'forget_me',
      'Contribution',
    ];
  }

  /**
   * Get entity name when only table is known.
   *
   * @param string $tableName
   *
   * @return NULL|string
   */
  public static function getEntityName($tableName) {
    return CRM_Core_DAO_AllCoreTables::getEntityNameForClass(CRM_Core_DAO_AllCoreTables::getClassForTable($tableName));
  }

  /**
   * Get table name from entity string.
   *
   * @param string $entity
   *
   * @return string
   */
  public static function getTableName($entity) {
    // If there is no DAO its not a 'real' entity so won't have a table.
    $dao = CRM_Core_DAO_AllCoreTables::getDAONameForEntity($entity);
    return !$dao ? '' : CRM_Core_DAO_AllCoreTables::getTableForClass($dao);
  }

  public static function getContactFieldMetadata($fields) {
    return array_intersect_key(civicrm_api3('Contact', 'getfields', ['action' => 'create'])['values'], array_fill_keys($fields,1));
  }
  /**
   * Get the contact custom fields to be shown / deleted.
   *
   * Filter out calculated wmf_donor & return the rest.
   *
   * @return array
   */
  public static function getContactCustomFields() {
    $fields = civicrm_api3('Contact', 'getfields', ['action' => 'create'])['values'];
    foreach ($fields as $fieldName => $field) {
      if (!empty($field['is_core_field']) || empty($field['table_name']) || $field['table_name'] === 'wmf_donor') {
        unset($fields[$fieldName]);
      }
    }
    return $fields;
  }

  public static function getContactExtendingCustomTables() {
    $fields = civicrm_api3('Contact', 'getfields', ['action' => 'create'])['values'];
    $tables = [];
    foreach ($fields as $fieldName => $field) {
      if (empty($field['is_core_field']) && !empty($field['table_name']) && $field['table_name'] !== 'wmf_donor') {
        $tables[$field['table_name']] = ['showme' => TRUE, 'forgetme' => TRUE, 'is_custom' => TRUE, 'internal_fields' => ['entity_id']];
      }
    }
    return $tables;
  }

}
