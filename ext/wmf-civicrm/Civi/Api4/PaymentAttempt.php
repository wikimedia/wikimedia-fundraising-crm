<?php

namespace Civi\Api4;

use Civi\Api4\Action\PaymentAttempt\Label;

/**
 * PaymentAttempt entity.
 *
 * Provided by the WMF CiviCRM extension.
 * @searchable primary
 * @searchFields order_id,currency,country,utm_medium,utm_campaign
 * @package Civi\Api4
 */
class PaymentAttempt extends Generic\DAOEntity {
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

  public static function label(bool $checkPermissions = FALSE): Label {
    return (new Label(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }
}
