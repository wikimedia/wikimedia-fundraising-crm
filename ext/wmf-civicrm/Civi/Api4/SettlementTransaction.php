<?php
namespace Civi\Api4;

/**
 * SettlementTransaction entity.
 *
 * Provided by the WMF CiviCRM extension.
 *
 * @package Civi\Api4
 */
class SettlementTransaction extends Generic\DAOEntity {

  public static function permissions(): array {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviContribute'];
    return $permissions;
  }

}
