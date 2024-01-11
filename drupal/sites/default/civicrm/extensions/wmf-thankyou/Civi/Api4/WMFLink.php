<?php

namespace Civi\Api4;

use Civi\Api4\Action\WMFLink\GetUnsubscribeURL;
use Civi\Api4\Generic\BasicGetFieldsAction;

class WMFLink extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   *
   * @return GetUnsubscribeURL
   */
  public static function getUnsubscribeURL(bool $checkPermissions = TRUE): GetUnsubscribeURL{
    return (new GetUnsubscribeURL(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @return BasicGetFieldsAction
   */
  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(__CLASS__, __FUNCTION__, function() {
      return [];
    });
  }

}
