<?php
$directory = __DIR__ . '/../msg_templates/monthly_convert/';
$htmlText = file_get_contents($directory . 'monthly_convert.en.html.txt');
$subject = file_get_contents($directory . 'monthly_convert.en.subject.txt');

/**
 * Add monthly_convert template.
 */
return [
  [
    'name' => 'monthly_convert',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Monthly convert',
        // Text version is ui-required but we don't use it - so use a space.
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'monthly_convert',
      ],
    ],
  ],
  [
    'name' => 'monthly_convert_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'monthly_convert',
        // Text version is ui-required but we don't use it - so use a space.
        'msg_text' => ' ',
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'monthly_convert',
      ],
    ],
  ],
];
