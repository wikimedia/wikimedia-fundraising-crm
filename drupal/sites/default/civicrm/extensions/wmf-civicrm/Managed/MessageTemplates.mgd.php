<?php
$directory = __DIR__ . '/../msg_templates/recurring_failed_message/';
$htmlText = file_get_contents($directory . 'recurring_failed_message.html.txt');
$msgText = file_get_contents($directory . 'recurring_failed_message.text.txt');
$subject = file_get_contents($directory . 'recurring_failed_message.subject.txt');

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
        'msg_title' => 'Recurring failure message',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'workflow_name' => 'recurring_failed_message',
      ],
    ],
  ],
];
