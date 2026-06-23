<?php

namespace Civi\Api4;

/**
 * PaymentAttemptLabel entity.
 *
 * Provided by the WMF CiviCRM extension.
 * @searchable primary
 * @searchFields order_id
 * @package Civi\Api4
 */
class PaymentAttemptLabel extends Generic\DAOEntity {
  /**
   * Get permissions.
   *
   * It may be that we don't need a permission check on this api at all at there is a check on the entity
   * retrieved.
   *
   * @return array
   */
  public static function permissions(): array {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }
}
