<?php
namespace Civi\Api4;

use Civi\Api4\Action\PhoneConsent\RemoteUpdate;

/**
 * PhoneConsent entity.
 *
 * Provided by the Omnimail for CiviCRM extension.
 *
 * @package Civi\Api4
 */
class PhoneConsent extends Generic\DAOEntity {


  /**
   * Phone Consent remote update.
   *
   * Flag remote records as 'orphans' and pushes consent information up.
   * This function extends PhoneConsent.update in order to leverage
   * 'where' handline from that.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\PhoneConsent\RemoteUpdate
   */
  public static function remoteUpdate(bool $checkPermissions = TRUE): RemoteUpdate {
    return (new remoteUpdate(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
