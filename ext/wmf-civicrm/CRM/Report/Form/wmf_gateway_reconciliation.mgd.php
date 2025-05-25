<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return [
  0 => [
    'name' => 'WMF gateway reconciliation report - used by Pats Pena',
    'entity' => 'ReportTemplate',
    'params' => [
      'label' => 'Gateway Reconciliation',
      'name' => 'CRM_Report_Form_Contribute_GatewayReconciliation',
      'value' => 'contribute/reconciliation',
      'description' => 'WMF gateway reconciliation report - used by Pats Pena',
      'component_id' => CRM_Core_Component::getComponentID('CiviContribute'),
      'is_active' => TRUE,
    ],
  ],
];
