<?php

namespace Civi\Api4;

use Civi\Api4\Action\Base62\Convert;
use Civi\Api4\Action\Base62\Validate;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Base62 entity.
 *
 * Utility api for converting between a payment orchestrator's Base62-encoded
 * reconciliation_id and the gateway transaction_id (UUID) it encodes, using
 * SmashPig's Base62Helper.
 *
 * @package Civi\Api4
 */
class Base62 extends Generic\AbstractEntity {

  /**
   * Convert a reconciliation_id to a transaction_id, or vice versa.
   *
   * Pass whichever one you have - only one is required.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Base62\Convert
   */
  public static function convert(bool $checkPermissions = TRUE): Convert {
    return (new Convert(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Check whether a reconciliation_id and transaction_id match each other.
   *
   * Both parameters are required.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\Base62\Validate
   */
  public static function validate(bool $checkPermissions = TRUE): Validate {
    return (new Validate(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [
        [
          'name' => 'reconciliation_id',
          'data_type' => 'String',
          'description' => 'Base62-encoded payment orchestrator reconciliation ID.',
        ],
        [
          'name' => 'transaction_id',
          'data_type' => 'String',
          'description' => 'Gateway transaction ID (UUID) encoded by the reconciliation_id.',
        ],
      ];
    }))->setCheckPermissions($checkPermissions);
  }

}
