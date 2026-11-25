<?php

namespace Civi\Api4;

/**
 * BatchFinancialBreakdown entity - per GL code & fund totals for an exported batch.
 *
 * Provided by the WMF CiviCRM extension.
 * @searchable secondary
 * @package Civi\Api4
 */
class BatchFinancialBreakdown extends Generic\DAOEntity {

  public static function permissions(): array {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviContribute'];
    return $permissions;
  }

}
