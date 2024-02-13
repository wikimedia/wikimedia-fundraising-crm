<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return [
  0 =>
    [
      'name' => 'CRM_WMFFraud_Form_Report_PaymentAttempts',
      'entity' => 'ReportTemplate',
      'params' =>
        [
          'version' => 3,
          'label' => 'Payment Attempts',
          'description' => 'Payment Attempts (org.wikimedia.wmffraud)',
          'class_name' => 'CRM_WMFFraud_Form_Report_PaymentAttempts',
          'report_url' => 'wmffraud/paymentattempts',
          'component' => 'CiviContribute',
        ],
    ],
];
