<?php

namespace Civi\Api4;

/**
 * Ad-hoc API for Standalone Migrations
 *
 * @package Civi\Api4
 */
class StandaloneMigration extends Generic\AbstractEntity {

  /**
   * Some fields our migration might have
   *
   * @param bool $checkPermissions
   *
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function($getFieldsAction) {
      return [
        [
          'name' => 'id',
          'data_type' => 'Integer',
          'description' => 'Unique identifier.',
        ],
//        [
//          'name' => 'export_path',
//          'description' => "Full path to a server folder for SQL dumps",
//        ],
//        [
//          'name' => 'target_database',
//          'description' => "Name for target database (will use the same server and connection credentials as your current CiviCRM DSN)",
//        ],
//        [
//          'name' => 'status',
//          'description' => "Status of the migration",
//        ],
      ];
    }))->setCheckPermissions($checkPermissions);
  }
}
