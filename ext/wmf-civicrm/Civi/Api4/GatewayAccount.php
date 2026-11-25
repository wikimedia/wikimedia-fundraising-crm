<?php

namespace Civi\Api4;

/**
 * GatewayAccount entity.
 *
 * Provided by the WMF CiviCRM extension.
 * @searchable secondary
 * @searchFields name,label
 * @package Civi\Api4
 */
class GatewayAccount extends Generic\DAOEntity {
  use Generic\Traits\ManagedEntity;

  public static function permissions(): array {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviContribute'];
    return $permissions;
  }

}
