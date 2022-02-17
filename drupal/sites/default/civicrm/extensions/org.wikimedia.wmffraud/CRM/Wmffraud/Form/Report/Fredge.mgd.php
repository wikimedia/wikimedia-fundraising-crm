<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return array (
  0 =>
  array (
    'name' => 'CRM_Wmffraud_Form_Report_Fredge',
    'entity' => 'ReportTemplate',
    'params' =>
    array (
      'version' => 3,
      'label' => 'Fredge',
      'description' => 'Fredge (org.wikimedia.wmffraud)',
      'class_name' => 'CRM_Wmffraud_Form_Report_Fredge',
      'report_url' => 'wmffraud/fredge',
      'component' => 'CiviContribute',
    ),
  ),
);
