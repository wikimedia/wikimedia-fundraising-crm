<?php
$directory = __DIR__ . '/../msg_templates/new_checksum_link/';
$htmlText = file_get_contents($directory . 'new_checksum_link.en.html.txt');
// Text version is ui-required but we don't use it - so use a space.
$msgText = ' ';
$subject = file_get_contents($directory . 'new_checksum_link.en.subject.txt');

/**
 * Add new_checksum_link template.
 */
return [
  [
    'name' => 'new_checksum_link',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'WMF new checksum link (donor prefs or recur upgrade) message',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => TRUE,
        'is_active' => TRUE,
        'is_reserved' => FALSE,
        'workflow_name' => 'new_checksum_link',
      ],
    ],
  ],
  [
    'name' => 'new_checksum_link_reserved',
    'entity' => 'MessageTemplate',
    'cleanup' => 'never',
    'params' => [
      'debug' => TRUE,
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'msg_title' => 'new_checksum_link',
        'msg_text' => $msgText,
        'msg_html' => $htmlText,
        'msg_subject' => $subject,
        'is_default' => FALSE,
        'is_active' => TRUE,
        'is_reserved' => TRUE,
        'workflow_name' => 'new_checksum_link',
      ],
    ],
  ],
];
