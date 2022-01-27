<?php
// This file declares a new entity type. For more details, see "hook_civicrm_entityTypes" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
return [
  [
    'name' => 'EOYEmailJob',
    'class' => 'CRM_WmfThankyou_DAO_EOYEmailJob',
    'table' => 'wmf_eoy_receipt_donor',
  ],
];
