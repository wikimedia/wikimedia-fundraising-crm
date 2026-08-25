<?php
$directory = __DIR__ . '/../msg_templates/double_opt_in_lead_gen/';
$htmlText = file_get_contents($directory . 'double_opt_in_lead_gen.en.html.txt');
$subject = file_get_contents($directory . 'double_opt_in_lead_gen.en.html.subject.txt');


/**
 * Add lead gen double opt-in email template.
 */
return [
  [
    'name' => 'double_opt_in_lead_gen',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF Double Opt-In - Lead Gen',
        'msg_text' => ' ', // Text version is ui-required, but we don't use it - so use a space.
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'msg_language' => 'en_US',
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'double_opt_in_lead_gen',
      ],
    ],
  ],
  [
    'name' => 'double_opt_in_lead_gen_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'double_opt_in_lead_gen',
        'msg_text' => ' ', // Text version is ui-required, but we don't use it - so use a space.
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'msg_language' => 'en_US',
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'double_opt_in_lead_gen',
      ],
    ],
  ],
];
