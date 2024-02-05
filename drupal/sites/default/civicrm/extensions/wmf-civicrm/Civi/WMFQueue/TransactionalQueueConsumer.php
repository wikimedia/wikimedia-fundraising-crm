<?php

namespace Civi\WMFQueue;

use Civi\WMFHelper\Database;
use Exception;

/**
 * OK, this inheritance is getting Inception-level silly, but half our
 * queue consumers don't need to lock all the databases.
 */
abstract class TransactionalQueueConsumer extends QueueConsumer {

  /**
   * We override the base callback wrapper to run processMessage inside
   * a crazy multi-database transaction.
   *
   * @param array $message
   */
  public function processMessageWithErrorHandling($message) {
    $this->logMessage($message);
    $callback = [$this, 'processMessage'];
    try {
      Database::transactionalCall(
        $callback, [$message]
      );
    }
    catch (Exception $ex) {
      $this->handleError($message, $ex);
    }
  }

}
