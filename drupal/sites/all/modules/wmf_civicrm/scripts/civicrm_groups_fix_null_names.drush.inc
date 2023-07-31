<?php

/**
 * Implementation of hook_drush_command()
 */
function civicrm_groups_fix_null_names_drush_command() {
  $items = [];
  $items['civicrm_groups_fix_null_names'] = [
    'description' => 'Fix contact groups with null in the name fields',
  ];

  return $items;
}

function drush_civicrm_groups_fix_null_names() {
  require_once 'drush_threshholds.php';
  module_invoke('civicrm', 'initialize');
  CRM_Core_DAO::executeQuery("
    UPDATE civicrm_group SET name = title WHERE name IS NULL AND title IS NOT NULL;
  ", []);
}
