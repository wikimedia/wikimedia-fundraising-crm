<?php
declare(strict_types = 1);

namespace Civi\Api4;

use Civi\Api4\Action\StockGift\Queue;
use Civi\Api4\Action\StockGift\Save;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * StockGift entity.
 *
 * Extends Contribution entity to create Overflow stock gift contributions,
 * created directly from the audit process (there is no earlier queue message
 * for these - the donor never passes through a WMF payment gateway).
 *
 * @package Civi\Api4
 */
class StockGift extends AbstractEntity {

  /**
   * Create contributions from Overflow audit rows.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\StockGift\Queue
   */
  public static function queue(bool $checkPermissions = FALSE): Queue {
    return (new Queue(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\StockGift\Save
   */
  public static function save($checkPermissions = TRUE): Save {
    return (new Save(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [
        'Stock_Information.Stock Ticker' => ['name' => 'Stock_Information.Stock Ticker'],
        'Stock_Information.Stock Quantity' => ['name' => 'Stock_Information.Stock Quantity'],
      ];
    }))->setCheckPermissions($checkPermissions);
  }

}
