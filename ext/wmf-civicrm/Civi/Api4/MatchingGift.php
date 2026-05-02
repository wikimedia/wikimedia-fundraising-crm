<?php
declare(strict_types = 1);

namespace Civi\Api4;

use Civi\Api4\Action\MatchingGift\Queue;
use Civi\Api4\Action\MatchingGift\Save;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * MatchingGift entity.
 *
 * Extends Contribution entity to
 *  1) save banking_institution
 *  2) save appropriate matching gift soft credits
 *  3) implement WMF specific logic.
 *
 * @package Civi\Api4
 */
class MatchingGift extends AbstractEntity {
  /**
   * Consume and rectify pending table messages
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\MatchingGift\Queue
   */
  public static function queue(bool $checkPermissions = FALSE): Queue {
    return (new Queue(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\MatchingGift\Save
   */
  public static function save(bool $checkPermissions = TRUE): Save {
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
        // @todo - pad this out, share appropriately with matching gift, include contribution fields
        // re api Order create for parsing through Contribution fields  - then this can
        // be like contribution + more fields.
        'banking_institution' => [
          'name' => 'banking_institution',
          'title' => 'Banking Institution',
          'data_type' => 'String',
        ],
      ];
    }))->setCheckPermissions($checkPermissions);
  }
}
