<?php

namespace Civi\Api4;

use Civi\Api4\Action\FinanceIntegration\GetFields;
use Civi\Api4\Action\FinanceIntegration\Ping;
use Civi\Api4\Action\FinanceIntegration\PushJournal;
use Civi\Api4\Action\FinanceIntegration\PushProcessLog;

class FinanceIntegration extends Generic\AbstractEntity {

  /**
   * Parse api audit files.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\FinanceIntegration\Ping
   *
   */
  public static function ping(bool $checkPermissions = FALSE): Ping {
    return (new Ping(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

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

  /**
   * Parse api audit files.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\FinanceIntegration\PushProcessLog
   *
   */
  public static function pushProcessLog(bool $checkPermissions = FALSE): PushProcessLog {
    return (new PushProcessLog(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Intacct dimensions (and GL account) as fields - use loadOptions for their values.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\FinanceIntegration\GetFields
   */
  public static function getFields(bool $checkPermissions = FALSE): GetFields {
    return (new GetFields(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }
}
