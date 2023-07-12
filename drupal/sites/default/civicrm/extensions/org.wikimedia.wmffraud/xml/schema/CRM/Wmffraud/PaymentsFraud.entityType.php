<?php
// This file declares a new entity type. For more details, see "hook_civicrm_entityTypes" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
return [
  [
    'name' => 'PaymentsFraud',
    'class' => 'CRM_Wmffraud_DAO_PaymentsFraud',
    'table' => 'fredge.payments_fraud',
  ],
];
