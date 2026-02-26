<?php
$directory = __DIR__ . '/../msg_templates/donor_portal_recurring/';
$htmlText = file_get_contents($directory . 'donor_portal_recurring.en.html.txt');
$subject = file_get_contents($directory . 'donor_portal_recurring.en.subject.txt');

/**
 * Add donor_portal_recurring template.
 */
return [
  [
    'name' => 'donor_portal_recurring',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Donor Portal Recurring Modification',
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'donor_portal_recurring',
      ],
    ],
  ],
  [
    'name' => 'donor_portal_recurring_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'Donor Portal Recurring Modification',
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'donor_portal_recurring',
      ],
    ],
  ],
];
