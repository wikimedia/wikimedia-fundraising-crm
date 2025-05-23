<?php
$directory = __DIR__ . '/../msg_templates/recurring_failed_message/';
$htmlText = file_get_contents($directory . 'recurring_failed_message.en.html.txt');
$msgText = file_get_contents($directory . 'recurring_failed_message.en.text.txt');
$subject = file_get_contents($directory . 'recurring_failed_message.en.subject.txt');

/**
 * Add recurring_failed_message template.
 */
return [
  [
    'name' => 'recurring_failed_message',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Recurring failure message',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'recurring_failed_message',
      ],
    ],
  ],
  [
    'name' => 'recurring_failed_message_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'Recurring failure message',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'recurring_failed_message',
      ],
    ],
  ],
];
