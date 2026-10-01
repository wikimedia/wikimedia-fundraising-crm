<?php

namespace Civi;

/**
 * Helper for tests that enqueue/run a CRM_Queue_Service-backed queue
 * (e.g. via \Civi::queue(...)), as opposed to the external SmashPig/donation
 * pipeline queues covered by WMFQueueTrait.
 */
trait WMFTaskQueueTrait {

  /**
   * Reset a named queue to a clean slate before a test.
   *
   * CRM_Queue_Service caches queue objects (and the spec they were created
   * with) in a process-wide static, and phpunit runs every test in one
   * process - so without this, a queue object left over from an earlier
   * test bleeds into this one. Forcing a fresh service and wiping any
   * persisted row ensures the next \Civi::queue() call for this name
   * starts clean.
   */
  protected function resetQueue(string $queueName): void {
    \CRM_Queue_Service::singleton(TRUE);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue_item WHERE queue_name = %1', [1 => [$queueName, 'String']]);
    \CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue WHERE name = %1', [1 => [$queueName, 'String']]);
  }

}
