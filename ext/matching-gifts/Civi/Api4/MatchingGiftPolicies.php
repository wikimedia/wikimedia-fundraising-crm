<?php

namespace Civi\Api4;

use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Action\MatchingGiftPolicies\VerifyEmployerFile;
use Civi\Api4\Generic\BasicGetFieldsAction;

class MatchingGiftPolicies extends AbstractEntity {

  public static function verifyEmployerFile($checkPermissions = TRUE): VerifyEmployerFile {
    return (new VerifyEmployerFile(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @return \Civi\Api4\Generic\BasicGetFieldsAction
   */
  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [];
    });
  }

}
