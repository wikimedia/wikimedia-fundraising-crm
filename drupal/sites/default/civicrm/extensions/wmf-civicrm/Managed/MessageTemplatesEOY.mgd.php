<?php
$directory = __DIR__ . '/../msg_templates/eoy_thank_you/';
$htmlText = file_get_contents($directory . 'eoy_thank_you.en.html.txt');
// Text version is ui-required but we don't use it - so use a space.
$msgText = ' ';
$subject = file_get_contents($directory . 'eoy_thank_you.en.subject.txt');

/**
 * Add eoy_thank_you template.
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
        'msg_title' => 'WMF End of year thank you message',
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
