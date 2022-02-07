<?php
$directory = __DIR__ . '/../msg_templates/eoy_thank_you/';
$htmlText = file_get_contents($directory . 'eoy_thank_you.en.html.txt');
// We populate the text version with a copy of the html version with html stripped.
// It is only seen by email clients which are configured not to display html
// It is a bit arguable now if we should include a text version or whether those
// clients (such as still are used) would kinda 'figure it out'
// However, it turns out that the code we are currently using converts anything
// in <b></b> tags to upper case - this <b>BREAKS</b> tokens.
// Currently emails are going out with this being wrong on prod - I'm inclined to
// think it is so edge it is safer to do nothing for now.. However this fixes
// for dev and prevents an e-notice which is unhushed next civi update
$msgText = str_replace(['<b>', '</b>'], ['', ''], $htmlText);
$msgText = CRM_Utils_String::htmlToText($msgText);
$subject = file_get_contents($directory . 'eoy_thank_you.en.subject.txt');

/**
 * Add recurring_failed_message template.
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
