<?php

namespace Civi\WMFQueue;

class RecurDeadlockQueueConsumer extends RecurringQueueConsumer {

  /**
   * @throws \CRM_Core_Exception
   */
  protected function createContributionRecur(array $params): ?array {
    throw new \CRM_Core_Exception('DBException error', 123, ['error_code' => 'deadlock']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function updateContributionRecur($params): ?array {
    throw new \CRM_Core_Exception('DBException error', 123, ['error_code' => 'deadlock']);
  }

}
