<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return array (
  0 =>
  array (
    'name' => 'CRM_Deduper_Form_Report_MergeConflict',
    'entity' => 'ReportTemplate',
    'params' =>
    array (
      'version' => 3,
      'label' => 'MergeConflict',
      'description' => 'MergeConflict (deduper)',
      'class_name' => 'CRM_Deduper_Form_Report_MergeConflict',
      'report_url' => 'deduper/mergeconflict',
      'component' => '',
    ),
  ),
);
