<?php

use CRM_Dedupetools_ExtensionUtil as E;
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
      'callback' => 'CRM_Dedupetools_BAO_MergeConflict::getBooleanFields',
    ],
  ],
];
