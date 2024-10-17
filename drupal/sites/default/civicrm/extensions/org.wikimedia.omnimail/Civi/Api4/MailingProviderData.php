<?php
namespace Civi\Api4;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 *  Class OmnimailJobProgress.
 *
 * Provided by the  extension.
 *
 * @package Civi\Api4
 */
class MailingProviderData extends Generic\DAOEntity {

 /**
  * Get permissions.
  *
  * It may be that we don't need a permission check on this api at all at there is a check on the entity
  * retrieved.
  *
  * @return array
  */
  public static function permissions():array {
    return ['check' => 'administer CiviCRM'];
  }

}
