<?php
$directory = __DIR__ . '/../msg_templates/set_primary_email/';
$htmlText = file_get_contents($directory . 'set_primary_email.en.html.txt');
$subject = file_get_contents($directory . 'set_primary_email.en.html.subject.txt');

/**
 * Add set_primary_email template.
 */
return [
  [
    'name' => 'set_primary_email',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF set (verify) primary email message',
        'msg_text' => ' ', // Text version is ui-required, but we don't use it - so use a space.
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'set_primary_email',
      ],
    ],
  ],
  [
    'name' => 'set_primary_email_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'set_primary_email',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'set_primary_email',
      ],
    ],
  ],
];
