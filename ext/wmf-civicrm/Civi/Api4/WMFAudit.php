<?php

namespace Civi\Api4;

use Civi\Api4\Action\WMFAudit\Parse;
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

  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    });
  }

}
