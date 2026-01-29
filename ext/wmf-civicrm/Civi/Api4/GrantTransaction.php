<?php
declare(strict_types = 1);

namespace Civi\Api4;

use Civi\Api4\Action\GrantTransaction\GetMatches;

/**
 * GrantTransaction entity.
 *
 * Provided by the WMF CiviCRM extension.
 *
 * @package Civi\Api4
 */
class GrantTransaction extends Generic\DAOEntity {

  /**
   * Get matching donations for grant transactions.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\WMFAudit\Parse
   *
   */
  public static function getMatches(bool $checkPermissions = FALSE): GetMatches {
    return (new GetMatches(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }
}
