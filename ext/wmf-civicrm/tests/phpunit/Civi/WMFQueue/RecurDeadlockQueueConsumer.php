<?php

namespace Civi\WMFQueue;

use Civi\Core\Exception\DBQueryException;

class RecurDeadlockQueueConsumer extends RecurringQueueConsumer {

  /**
   * @throws \CRM_Core_Exception
   */
  protected function createContributionRecur(array $params): ?array {

    $pearError = new \DB_Error(-31);
    throw new DBQueryException($pearError->getMessage(), $pearError->getCode(), ['exception' => $pearError]);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function updateContributionRecur($params): ?array {
    $pearError = new \DB_Error(-31);
    throw new DBQueryException($pearError->getMessage(), $pearError->getCode(), ['exception' => $pearError]);
  }

}
