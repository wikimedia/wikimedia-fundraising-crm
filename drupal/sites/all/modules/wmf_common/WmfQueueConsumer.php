<?php namespace wmf_common;

use SmashPig\Core\QueueConsumers\BaseQueueConsumer;
use Exception;
use SmashPig\Core\UtcDate;
use SmashPig\CrmLink\Messages\DateFields;
use \Civi\WMFException\WMFException;

/**
 * Queue consumer that knows what to do with WMFExceptions
 */
abstract class WmfQueueConsumer extends BaseQueueConsumer {

  protected function handleError($message, Exception $ex) {
    if (isset($message['gateway']) && isset($message['order_id'])) {
      $logId = "{$message['gateway']}-{$message['order_id']}";
    } else {
      foreach($message as $key => $value) {
        if (substr($key, -2, 2) === 'id') {
          $logId = "$key-$value";
          break;
        }
      }
      if (!isset($logId)) {
        $logId = 'Odd message type';
      }
    }
    if ($ex instanceof WMFException) {
      watchdog(
        'wmf_common',
        'Failure while processing message: ' . $ex->getMessage(),
        NULL,
        WATCHDOG_ERROR
      );

      $this->handleWMFException($message, $ex, $logId);
    }
    else {
      $error = 'UNHANDLED ERROR. Halting dequeue loop. Exception: ' .
        $ex->getMessage() . "\nStack Trace: " .
        $ex->getTraceAsString();
      watchdog('wmf_common', $error, NULL, WATCHDOG_ERROR);
      wmf_common_failmail('wmf_common', $error, NULL, $logId);

      throw $ex;
    }
  }

  /**
   * @param array $message
   * @param WMFException $ex
   * @param string $logId
   *
   * @throws \Civi\WMFException\WMFException when we want to halt the dequeue loop
   * @throws \SmashPig\Core\DataStores\DataStoreException
   */
  protected function handleWMFException(
    $message, WMFException $ex, $logId
  ) {
    $mailableDetails = '';
    $reject = FALSE;
    $requeued = FALSE;

    if ($ex->isRequeue()) {
      $delay = (int) variable_get('wmf_common_requeue_delay', 20 * 60);
      $maxTries = (int) variable_get('wmf_common_requeue_max', 10);
      $ageLimit = $delay * $maxTries;
      // TODO: add a requeueMessage hook that allows modifying
      // the message or the decision to requeue it. Or maybe a
      // more generic (WMF)Exception handling hook?
      if ($ex->getCode() === WMFException::DUPLICATE_INVOICE) {
        // Crappy special-case handling that we can't handle at
        // lower levels.
        $message = $this->modifyDuplicateInvoice($message);
      }
      // Defaulting to 0 means we'll always go the reject route
      // and log an error if no date fields are set.
      $queuedTime = DateFields::getOriginalDateOrDefault($message, 0);
      if ($queuedTime === 0) {
        watchdog(
          'wmf_common',
          "Message has no useful information about queued date",
          $message,
          WATCHDOG_NOTICE
        );
      }
      if ($queuedTime + $ageLimit < time()) {
        $reject = TRUE;
      }
      else {
        $retryDate = time() + $delay;
        $this->sendToDamagedStore($message, $ex, $retryDate);
        $requeued = TRUE;
      }
    }

    if ($ex->isDropMessage()) {
      watchdog(
        'wmf_common',
        "Dropping message altogether: $logId",
        NULL,
        WATCHDOG_ERROR
      );
    }
    elseif ($ex->isRejectMessage() || $reject) {
      $messageString = json_encode($message);
      watchdog(
        'wmf_common',
        "\nRemoving failed message from the queue: \n$messageString",
        NULL,
        WATCHDOG_ERROR
      );
      $damagedId = $this->sendToDamagedStore(
        $message,
        $ex
      );
      $mailableDetails = self::itemUrl($damagedId);
    }
    else {
      $mailableDetails = "Redacted contents of message ID: $logId";
    }

    if (!$ex->isNoEmail() && !$requeued) {
      wmf_common_failmail('wmf_common', '', $ex, $mailableDetails);
    }

    if ($ex->isFatal()) {
      $error = 'Halting Process.';
      watchdog('wmf_common', $error, NULL, WATCHDOG_ERROR);

      throw $ex;
    }
  }

  public function processMessageWithErrorHandling($message) {
    $this->logMessage($message);
    parent::processMessageWithErrorHandling($message);
  }

  protected function logMessage($message) {
    $className = preg_replace('/.*\\\/', '', get_called_class());
    $formattedMessage = json_encode($message);
    watchdog($className, $formattedMessage, NULL, WATCHDOG_INFO);
  }

  /**
   * Get a url to view the damaged message
   *
   * @param int $damagedId
   *
   * @return string
   */
  public static function itemUrl($damagedId) {
    global $base_url;
    return "{$base_url}/damaged/{$damagedId}";
  }

  protected function modifyDuplicateInvoice($message) {
    if (empty($message['invoice_id']) && isset ($message['order_id'])) {
      $message['invoice_id'] = $message['order_id'];
    }
    $message['invoice_id'] .= '|dup-' . UtcDate::getUtcTimeStamp();
    watchdog(
      'wmf_civicrm',
      'Found duplicate invoice ID, changing this one to ' .
      $message['invoice_id']
    );
    $message['contribution_tags'][] = 'DuplicateInvoiceId';
    return $message;
  }
}
