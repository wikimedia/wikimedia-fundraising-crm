<?php
$directory = __DIR__ . '/../msg_templates/recurring_upgrade_message/';
$htmlText = file_get_contents($directory . 'recurring_upgrade_message.en.html.txt');
$msgText = file_get_contents($directory . 'recurring_upgrade_message.en.text.txt');
$subject = file_get_contents($directory . 'recurring_upgrade_message.en.subject.txt');

/**
 * Add recurring_upgrade_message template.
 */
return [
  [
    'name' => 'recurring_upgrade_message',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Recurring upgrade message',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'recurring_upgrade_message',
      ],
    ],
  ],
  [
    'name' => 'recurring_upgrade_message_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'Recurring upgrade message',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'recurring_upgrade_message',
      ],
    ],
  ],
];
