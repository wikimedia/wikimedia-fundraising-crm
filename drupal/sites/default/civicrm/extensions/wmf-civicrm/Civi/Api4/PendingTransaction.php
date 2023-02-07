<?php
namespace Civi\Api4;

use Civi\Api4\Action\PendingTransaction\Resolve;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Class PendingTransaction
 *
 * Api entity for resolving pending transactions
 *
 * @package Civi\Api4
 */
class PendingTransaction extends Generic\AbstractEntity {

  protected static $resolvableMethods = ['cc', 'google', 'paypal'];

  /**
   * Resolve a single pending transaction
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\PendingTransaction\Resolve
   *
   * @throws \API_Exception
   */
  public static function resolve(bool $checkPermissions = FALSE): Resolve {
    return (new Resolve(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields() {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [];
    });
  }

  public static function getResolvableMethods(): array {
    return self::$resolvableMethods;
  }
}
