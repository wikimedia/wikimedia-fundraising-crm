<?php

/**
 * Settings metadata file
 */
return [
  'custom_field_tracking' => [
    'group' => 'wmf-civicrm',
    'name' => 'custom_field_tracking',
    'type' => 'array',
    'title' => 'custom_field_tracking',
    'is_domain' => '1',
    'is_contact' => 0,
    'description' => 'Configuration for updating fields with the date another field was updated.',
    'help_text' => 'array of field pairs where the key is the field to track and the value is the field to track into',
  ],
];
