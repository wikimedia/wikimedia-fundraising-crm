<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return [
  0 =>
    [
      'name' => 'CRM_WMFFraud_Form_Report_Fraud',
      'entity' => 'ReportTemplate',
      'params' =>
        [
          'version' => 3,
          'label' => 'Fraud',
          'description' => 'Fraud (org.wikimedia.wmffraud)',
          'class_name' => 'CRM_WMFFraud_Form_Report_Fraud',
          'report_url' => 'wmffraud/fraud',
          'component' => 'CiviContribute',
        ],
    ],
];
