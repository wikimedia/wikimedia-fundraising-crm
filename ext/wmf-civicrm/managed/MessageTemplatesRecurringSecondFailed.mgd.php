<?php
$directory = __DIR__ . '/../msg_templates/recurring_second_failed_message/';
$htmlText = file_get_contents($directory . 'recurring_second_failed_message.en.html.txt');
$subject = file_get_contents($directory . 'recurring_second_failed_message.en.subject.txt');

/**
 * Add recurring_failed_message template.
 */
return [
  [
    'name' => 'recurring_second_failed_message',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Recurring second failure message',
        // Text version is ui-required but we don't use it - so use a space.
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'recurring_second_failed_message',
      ],
    ],
  ],
  [
    'name' => 'recurring_second_failed_message_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'Recurring second failure message',
        // Text version is ui-required but we don't use it - so use a space.
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'recurring_second_failed_message',
      ],
    ],
  ],
];
