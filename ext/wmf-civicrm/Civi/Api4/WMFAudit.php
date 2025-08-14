<?php

namespace Civi\Api4;

use Civi\Api4\Action\WMFAudit\Parse;
use Civi\Api4\Action\WMFAudit\Audit;
use Civi\Api4\Action\WMFAudit\Settle;
use Civi\Api4\Generic\BasicGetFieldsAction;

class WMFAudit extends Generic\AbstractEntity {

  /**
   * Parse api audit files.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFAudit\Parse
   *
   */
  public static function parse(bool $checkPermissions = FALSE): Parse {
    return (new Parse(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Settle contributions once full settlement data is received.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFAudit\Settle
   *
   */
  public static function settle(bool $checkPermissions = TRUE): Settle {
    return (new Settle(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Settle contributions once full settlement data is received.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFAudit\Audit
   *
   */
  public static function audit(bool $checkPermissions = TRUE): Audit {
    return (new Audit(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields(bool $checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    }))->setCheckPermissions($checkPermissions);
  }

}
