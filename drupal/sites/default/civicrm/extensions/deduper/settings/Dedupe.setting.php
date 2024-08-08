<?php

use CRM_Deduper_ExtensionUtil as E;
/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton
 * Date: 1/10/18
 * Time: 4:30 PM
 */
return [
  'deduper_resolver_bool_prefer_yes' => [
    'group_name' => 'Deduper Settings',
    'group' => 'deduper',
    'name' => 'deduper_resolver_bool_prefer_yes',
    'type' => 'Array',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Yes/No fields where yes always wins'),
    'default' => ['on_hold', 'do_not_email', 'do_not_phone', 'do_not_mail', 'do_not_sms', 'do_not_trade', 'is_opt_out'],
    'title' => E::ts('Fields to resolve preferring YES'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
      'multiple' => 1,
    ],
    'settings_pages' => ['deduper' => ['weight' => 20]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getBooleanFields',
    ],
  ],
  'deduper_resolver_field_prefer_preferred_contact' => [
    'group_name' => 'Deduper Settings',
    'group' => 'deduper',
    'name' => 'deduper_resolver_field_prefer_preferred_contact',
    'type' => 'Array',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Fields to resolve as values from preferred contact'),
    'default' => [],
    'title' => E::ts('Fields to take from preferred contact'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
      'multiple' => 1,
    ],
    'settings_pages' => ['deduper' => ['weight' => 30]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getContactFields',
    ],
  ],
  'deduper_resolver_preferred_contact_resolution' => [
    'group_name' => 'Deduper Settings',
    'group' => 'deduper',
    'name' => 'deduper_resolver_preferred_contact_resolution',
    'type' => 'Array',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts("When a conflict is to be resolved by taking the 'preferred contact's value' what criteria do we use."),
    'default' => ['most_recently_created_contact'],
    'title' => E::ts('Criteria to determine which contact\'s data should be preferred?'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
      'multiple' => 1,
    ],
    'settings_pages' => ['deduper' => ['weight' => 40]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getPreferredContactCriteria',
    ],
  ],
  'deduper_resolver_preferred_contact_last_resort' => [
    'group_name' => 'Deduper Settings',
    'group' => 'deduper',
    'name' => 'deduper_resolver_preferred_contact_last_resort',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Fall back for preferred contact (e.g if criteria is most recent contributor & neither have)'),
    'default' => 'most_recently_created_contact',
    'title' => E::ts('Criteria to fall back on to determine which contact\'s data should be preferred?'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'settings_pages' => ['deduper' => ['weight' => 50]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getPreferredContactCriteriaFallback',
    ],
  ],
  'deduper_equivalent_name_handling' => [
    'group_name' => 'Deduper Settings',
    'group' => 'deduper',
    'name' => 'deduper_equivalent_name_handling',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('If an option is selected then 2 contacts with equivalent names will be merged. (e.g Robert & Bob)'),
    'default' => '',
    'title' => E::ts('Resolution for contacts with known alternative names'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'settings_pages' => ['deduper' => ['weight' => 60]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getEquivalentNameOptions',
    ],
  ],
  // This doesn't work very well in the UI but we can set it via the api for now which I figure that out.
  'deduper_location_priority_order' => [
    'name' => 'deduper_location_priority_order',
    'type' => 'Array',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Location priority order. This is used to resolve data issues if a contact has, for example, 2 home emails. One would be moved to the highest priority available location'),
    'default' => array_keys(CRM_Deduper_BAO_MergeConflict::getLocationTypes()),
    'title' => E::ts('Priority order for locations'),
    'help_text' => 'This is only used in conjunction with other options',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
      'multiple' => 1,
      // @todo sortable doesn't work yet - https://lab.civicrm.org/dev/core/-/issues/1925
      // for now only really api-alterable.
      'sortable' => 1,
    ],
    'settings_pages' => ['deduper' => ['weight' => 70]],
    'pseudoconstant' => [
      'table' => 'civicrm_location_type',
      'keyColumn' => 'id',
      'labelColumn' => 'display_name',
    ]
  ],
  'deduper_resolver_email' => [
    'name' => 'deduper_resolver_email',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('What method should be used to resolve email conflicts'),
    'default' => 'preferred_contact_with_re-assign',
    'title' => E::ts('Method to resolve email conflicts?'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'settings_pages' => ['deduper' => ['weight' => 140]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getLocationResolvers',
    ],
  ],
  'deduper_resolver_phone' => [
    'name' => 'deduper_resolver_phone',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('What method should be used to resolve phone conflicts'),
    'default' => 'none',
    'title' => E::ts('Method to resolve phone conflicts?'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'settings_pages' => ['deduper' => ['weight' => 140]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getLocationResolvers',
    ],
  ],
  'deduper_resolver_address' => [
    'name' => 'deduper_resolver_address',
    'type' => 'String',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('What method should be used to resolve address conflicts'),
    'default' => 'none',
    'title' => E::ts('Method to resolve address conflicts?'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'settings_pages' => ['deduper' => ['weight' => 140]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getLocationResolvers',
    ],
  ],
  'deduper_resolver_custom_groups_to_skip' => [
    'name' => 'deduper_resolver_custom_groups_to_skip',
    'type' => 'Array',
    'serialize' => CRM_Core_DAO::SERIALIZE_JSON,
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Custom tables that should be completely ignored (generally calculated fields such as summary fields)'),
    'default' => [],
    'title' => E::ts('Custom tables to skip'),
    'help_text' => '',
    'html_type' => 'select',
    'html_attributes' => [
      'class' => 'crm-select2',
      'multiple' => 1,
    ],
    'settings_pages' => ['deduper' => ['weight' => 150]],
    'pseudoconstant' => [
      'callback' => 'CRM_Deduper_BAO_MergeConflict::getCustomGroups',
    ],
  ],
  'deduper_exception_relationship_type_id' => [
    'name' => 'deduper_exception_relationship_type_id',
    'group_name' => 'Deduper Settings',
    'group' => 'deduper',
    'type' => 'Integer',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts("When specified the user gets the option to create this relationship when marking non-duplicate."),
    'default' => NULL,
    'title' => E::ts('Relationship type for non-duplicates'),
    'help_text' => '',
    'html_type' => 'select',
    'is_required' => FALSE,
    'html_attributes' => [
      'class' => 'crm-select2',
    ],
    'settings_pages' => ['deduper' => ['weight' => 240]],
    'pseudoconstant' => [
      'table' => 'civicrm_relationship_type',
      'keyColumn' => 'id',
      'condition' => ["contact_type_a IS NULL", "contact_type_b IS NULL"],
      'nameColumn' => 'name_a_b',
      'labelColumn' => 'label_a_b',
    ],
  ],
];
