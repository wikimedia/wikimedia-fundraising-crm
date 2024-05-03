<?php
namespace Civi\Api4;

/**
 * ExchangeRate entity.
 *
 * Provided by the Exchange Rates extension.
 *
 * @package Civi\Api4
 */
class ExchangeRate extends Generic\DAOEntity {

  public static function updateAll($checkPermissions = TRUE): Action\ExchangeRate\UpdateAll {
    return (new Action\ExchangeRate\UpdateAll(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }
}
