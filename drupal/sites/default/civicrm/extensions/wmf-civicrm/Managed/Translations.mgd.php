<?php

use Civi\Api4\MessageTemplate;
use Civi\WMFHelpers\Language;

/** @noinspection PhpUnhandledExceptionInspection */
$recurringTemplate = MessageTemplate::get(FALSE)
  ->addWhere('workflow_name', '=', 'recurring_failed_message')
  ->addWhere('is_reserved', '=', 0)
  ->setSelect(['id'])->execute()->first();
if (empty($recurringTemplate['id'])) {
  return;
}
$translations = [];
$directory = __DIR__ . '/../msg_templates/recurring_failed_message/';
$files = array_diff(scandir($directory), array('.', '..'));

foreach ($files as $file){
  $content = file_get_contents($directory . $file);
  $parts = explode('.', $file);
  $language = $parts[1];
  $field = 'msg_' . $parts[2];

  $translations[] = [
    'name' => 'translation_' . $language . '_' . $field,
    'entity' => 'Translation',
    'cleanup' => 'never',
    'update' => 'never',
    'params' => [
      'version' => 4,
      'checkPermissions' => FALSE,
      'values' => [
        'entity_table' => 'civicrm_msg_template',
        'entity_field' => $field,
        'entity_id' => $recurringTemplate['id'],
        'language' => Language::getLanguageCode($language),
        'string' => $content,
        'status_id:name' => 'draft',
      ],
    ],
  ];

}
return $translations;
