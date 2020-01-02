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
    'title' => E::ts('Fields to resolve as YES'),
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
];
