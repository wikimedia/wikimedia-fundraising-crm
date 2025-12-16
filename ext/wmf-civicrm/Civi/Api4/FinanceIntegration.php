<?php

namespace Civi\Api4;

use Civi\Api4\Action\FinanceIntegration\PushJournal;
use Civi\Api4\Generic\BasicGetFieldsAction;

class FinanceIntegration extends Generic\AbstractEntity {

  /**
   * Parse api audit files.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\FinanceIntegration\PushJournal
   *
   */
  public static function pushJournal(bool $checkPermissions = FALSE): PushJournal {
    return (new PushJournal(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    });
  }
}
