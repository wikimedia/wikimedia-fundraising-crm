<?php
$directory = __DIR__ . '/../msg_templates/eoy_thank_you/';
$htmlText = file_get_contents($directory . 'eoy_thank_you.en.html.txt');
$msgText = CRM_Utils_String::htmlToText($htmlText);
$subject = file_get_contents($directory . 'eoy_thank_you.en.subject.txt');

/**
 * Add recurring_failed_message template.
 */
return [
  [
    'name' => 'eoy_thank_you',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'End of year thank you message',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'eoy_thank_you',
      ],
    ],
  ],
  [
    'name' => 'eoy_thank_you_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'eoy_thank_you',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'eoy_thank_you',
      ],
    ],
  ],
];
