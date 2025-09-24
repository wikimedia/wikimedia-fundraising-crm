<?php
$directory = __DIR__ . '/../msg_templates/annual_recurring_prenotification/';
$htmlText = file_get_contents($directory . 'annual_recurring_prenotification.en.html.txt');
$msgText = file_get_contents($directory . 'annual_recurring_prenotification.en.text.txt');
$subject = file_get_contents($directory . 'annual_recurring_prenotification.en.subject.txt');

/**
 * Add annual_recurring_prenotification template.
 */
return [
  [
    'name' => 'annual_recurring_prenotification',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Annual recurring pre-notification',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'annual_recurring_prenotification',
      ],
    ],
  ],
  [
    'name' => 'annual_recurring_prenotification_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'Annual recurring pre-notification',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'annual_recurring_prenotification',
      ],
    ],
  ],
];
