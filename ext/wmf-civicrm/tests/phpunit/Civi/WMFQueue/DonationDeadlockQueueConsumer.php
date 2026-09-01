<?php

namespace Civi\WMFQueue;

use Civi\Core\Exception\DBQueryException;
use Civi\WMFQueueMessage\DonationMessage;
use Civi\WMFQueueMessage\RecurDonationMessage;

/**
 * Test double that fails the final contribution insert with a raw
 * DBQueryException, to exercise QueueConsumer::handleError()'s
 * DBQueryException branches.
 *
 * Set self::$errorCode to a PEAR DB error code (see vendor/pear/db/DB.php)
 * before use - defaults to DB_ERROR_DEADLOCK (-31).
 *
 * Set self::$deadlockTxnId to a message's gateway_txn_id to only fail that
 * message and process everything else normally or leave it NULL to fail
 * every message the consumer handles.
 */
class DonationDeadlockQueueConsumer extends DonationQueueConsumer {

  public static int $errorCode = -31;

  public static $deadlockTxnId = NULL;

  public function saveContribution(DonationMessage|RecurDonationMessage $message, array $msg): array {
    if (static::$deadlockTxnId !== NULL && (string) $msg['gateway_txn_id'] !== (string) static::$deadlockTxnId) {
      return parent::saveContribution($message, $msg);
    }
    $pearError = new \DB_Error(
      code: static::$errorCode,
      debuginfo: '[nativecode=' . abs(static::$errorCode) . ' ** simulated error]'
    );
    throw new DBQueryException($pearError->getMessage(), $pearError->getCode(), ['exception' => $pearError]);
  }

}
