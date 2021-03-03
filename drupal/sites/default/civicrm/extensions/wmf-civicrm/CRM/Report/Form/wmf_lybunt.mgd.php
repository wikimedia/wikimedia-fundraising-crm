<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return [
  0 => [
    'name' => 'WMF LYBUNT report (possibly dead)',
    'entity' => 'ReportTemplate',
    'params' => [
      'version' => 3,
      'label' => 'WMF LYBUNT',
      'name' => 'CRM_Report_Form_Contribute_WmfLybunt',
      'value' => 'contribute/wmf_lybunt',
      'description' => 'WMF-customized LYBUNT - used by Major Gifts, but likely no more - see https://phabricator.wikimedia.org/T270684',
      'component_id' => CRM_Core_Component::getComponentID('CiviContribute'),
      'is_active' => TRUE,
    ],
  ],
];
