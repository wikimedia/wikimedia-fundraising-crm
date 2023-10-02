<?php

namespace Civi\Queue;

use Civi\Api4\Queue;

/**
 * Queue helper.
 *
 * This comes from https://gist.github.com/totten/fa830cfced9bb7a92dea485f5422055a
 * & will hopefully be moved to core.
 */
class QueueHelper {
  protected $queue;
  public const ITERATE_UNTIL_DONE = 1;
  public const ITERATE_RUN_ONCE = 0;

  /**
   * @var int|null
   */
  protected $runAs;

  public function __construct(\CRM_Queue_Queue $queue) {
    $this->queue = $queue;
  }

  /**
   * @param int|null $runAs
   *
   * @return $this
   */
  public function setRunAs(?int $runAs): QueueHelper {
    $this->runAs = $runAs;
    return $this;
  }

  /**
   * @param string $sql
   * @param array $params
   * @param int $iterate
   *
   * @return $this
   */
  public function sql(string $sql, array $params = [], int $iterate = self::ITERATE_RUN_ONCE): QueueHelper {
    $task = new \CRM_Queue_Task([self::class, 'doSql'], [
      $sql,
      $params,
      $iterate
    ]);
    $task->runAs = $this->runAs;
    $this->queue->createItem($task);
    return $this;
  }

  /**
   * Api3 & 4 helpers not yet tested ...
   *
   * @param string $entity
   * @param string $action
   * @param array $params
   *
   * @return $this
   *
  public function api4(string $entity, string $action, array $params = []) {
    $this->queue->createItem(new \CRM_Queue_Task([self::class, 'doApi4'], [$entity, $action, $params]));
    return $this;
  }

  public function api3(string $entity, string $action, array $params = []) {
    $this->queue->createItem(new \CRM_Queue_Task([self::class, 'doApi3'], [$entity, $action, $params]));
    return $this;
  }
   */

  /**
   * Do SQL in a queue context.
   *
   * @param \CRM_Queue_TaskContext $taskContext
   * @param string $sql
   * @param array $params
   *
   * @return bool
   * @throws \Civi\Core\Exception\DBQueryException
   * @internal only use from this class.
   */
  public static function doSql(\CRM_Queue_TaskContext $taskContext, string $sql, array $params, int $iterate): bool {
    $result = \CRM_Core_DAO::executeQuery($sql, $params);
    if ($iterate && $result->affectedRows() > 0) {
      // Not finished, queue another pass.
      $task = new \CRM_Queue_Task([self::class, 'doSql'], [
        $sql,
        $params,
        $iterate
      ]);
      $taskContext->queue->createItem($task);
    }
    return TRUE;
  }

  /**
   * Api3 & 4 helpers not yet working.
   *
   * Do apiv4 call in a queue context.
   *
   * @param \CRM_Queue_TaskContext $taskContext
   * @param string $entity
   * @param string $action
   * @param array $params
   *
   * @return bool
   * @internal only use from this class.
   *
  public static function doApi4(\CRM_Queue_TaskContext $taskContext, string $entity, string $action, array $params): bool {
    try {
      civicrm_api4($entity, $action, $params);
    }
    catch (\CRM_Core_Exception $e) {
      \Civi::log('queue')->error('queued action failed {entity} {action} {params} {message} {exception}', [
        'entity' => $entity,
        'action' => $action,
        'params' => $params,
        'message' => $e->getMessage(),
        'exception' => $e,
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Do apiv3 call in a queue context.
   *
   * @param \CRM_Queue_TaskContext $taskContext
   * @param string $entity
   * @params string $action
   * @param array $params
   *
   * @return bool
   *@internal only use from this class.
   *
   *
  public static function doApi3(\CRM_Queue_TaskContext $taskContext, string $entity, string $action, array $params): bool {
    try {
      civicrm_api3($entity, $action, $params);
    }
    catch (\CRM_Core_Exception $e) {
        \Civi::log('queue')->error('queued action failed {entity} {action} {params} {message} {exception}', [
          'entity' => $entity,
          'action' => $action,
          'params' => $params,
          'message' => $e->getMessage(),
          'exception' => $e,
        ]);
        return FALSE;
      }
    return TRUE;
  }
  */

}
