<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 => 
  array (
    'name' => 'CRM_ExtendedMailingstats_Form_Report_ExtendedMailingStats',
    'entity' => 'ReportTemplate',
    'params' => 
    array (
      'version' => 3,
      'label' => 'ExtendedMailingStats',
      'description' => 'ExtendedMailingStats (au.org.greens.extendedmailingstats)',
      'class_name' => 'CRM_ExtendedMailingstats_Form_Report_ExtendedMailingStats',
      'report_url' => 'au.org.greens.extendedmailingstats/extendedmailingstats',
      'component' => 'CiviMail',
    ),
  ),
);
