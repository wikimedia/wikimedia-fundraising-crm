<?php
// This file declares a new entity type. For more details, see "hook_civicrm_entityTypes" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
return [
  [
    'name' => 'ContactNamePairFamily',
    'class' => 'CRM_Deduper_DAO_ContactNamePairFamily',
    'table' => 'civicrm_contact_name_pair_family',
  ],
];
