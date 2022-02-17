<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
return array (
  0 =>
  array (
    'name' => 'CRM_ExtendedMailingStats_Form_Report_ExtendedMailingStats',
    'entity' => 'ReportTemplate',
    'params' =>
    array (
      'version' => 3,
      'label' => 'Extended Mailing Stats',
      'description' => 'An extended version of the Mail Summary Report',
      'class_name' => 'CRM_ExtendedMailingStats_Form_Report_ExtendedMailingStats',
      'report_url' => 'au.org.greens.extendedmailingstats/extendedmailingstats',
      'component' => 'CiviMail',
    ),
  ),
);
