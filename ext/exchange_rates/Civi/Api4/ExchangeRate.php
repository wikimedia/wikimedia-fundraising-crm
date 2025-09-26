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

  public static function convert($checkPermissions = TRUE): Action\ExchangeRate\Convert {
    return (new Action\ExchangeRate\Convert(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function convertContribution($checkPermissions = TRUE): Action\ExchangeRate\ConvertContribution {
    return (new Action\ExchangeRate\ConvertContribution(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getLatest($checkPermissions = TRUE): Action\ExchangeRate\GetLatest {
    return (new Action\ExchangeRate\GetLatest(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }
}
