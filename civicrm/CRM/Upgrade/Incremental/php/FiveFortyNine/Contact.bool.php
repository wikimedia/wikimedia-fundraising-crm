<?php
return [
  'civicrm_contact_type' => [
    'is_active' => "DEFAULT 1 COMMENT 'Is this entry active?'",
    'is_reserved' => "DEFAULT 0 COMMENT 'Is this contact type a predefined system type'",
  ],
  'civicrm_dashboard_contact' => [
    'is_active' => "DEFAULT 1 COMMENT 'Is this widget active?'",
  ],
  'civicrm_group' => [
    'is_active' => "DEFAULT 1 COMMENT 'Is this entry active?'",
    'is_hidden' => "DEFAULT 0 COMMENT 'Is this group hidden?'",
    'is_reserved' => "DEFAULT 0",
  ],
  'civicrm_relationship' => [
    'is_active' => "DEFAULT 1 COMMENT 'is the relationship active ?'",
  ],
  'civicrm_relationship_cache' => [
    'is_active' => "DEFAULT 1 COMMENT 'is the relationship active ?'",
  ],
  'civicrm_relationship_type' => [
    'is_reserved' => "DEFAULT 0 COMMENT 'Is this relationship type a predefined system type (can not be changed or de-activated)?'",
    'is_active' => "DEFAULT 1 COMMENT 'Is this relationship type currently active (i.e. can be used when creating or editing relationships)?'",
  ],
];
