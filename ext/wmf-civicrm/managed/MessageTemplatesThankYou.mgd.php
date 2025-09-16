<?php
$directory = __DIR__ . '/../msg_templates/thank_you/';
$htmlText = file_get_contents($directory . 'thank_you.en.html.txt');
$subject = file_get_contents($directory . 'thank_you.en.subject.txt');

/**
 * Add thank_you template.
 */
return [
  [
    'name' => 'thank_you',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Thank You',
        // Text version is ui-required but we don't use it - so use a space.
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'thank_you',
      ],
    ],
  ],
  [
    'name' => 'thank_you_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'thank_you',
        // Text version is ui-required but we don't use it - so use a space.
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'thank_you',
      ],
    ],
  ],
];
