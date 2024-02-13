<?php

namespace Civi\WMFQueue;

use SmashPig\Core\QueueConsumers\BaseQueueConsumer;
use Exception;
use SmashPig\Core\UtcDate;
use SmashPig\CrmLink\Messages\DateFields;
use \Civi\WMFException\WMFException;

/**
 * Queue consumer that knows what to do with WMFExceptions
 */
abstract class QueueConsumer extends BaseQueueConsumer {

  protected function handleError($message, Exception $ex) {
    if (isset($message['gateway']) && isset($message['order_id'])) {
      $logId = "{$message['gateway']}-{$message['order_id']}";
    }
    else {
      foreach ($message as $key => $value) {
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
      \Civi::log('wmf')->error(
        'wmf_common: Failure while processing message: {message}',
        ['message' => $ex->getMessage()]
      );

      $this->handleWMFException($message, $ex, $logId);
    }
    else {
      $error = 'UNHANDLED ERROR. Halting dequeue loop. Exception: ' .
        $ex->getMessage() . "\nStack Trace: " .
        $ex->getTraceAsString();
      \Civi::log('wmf')->error(
        'wmf_common: {error}', ['error' => $error]);
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
        \Civi::log('wmf')->notice('wmf_common: Message has no useful information about queued date {message}',
          ['message' => $message]
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
      \Civi::log('wmf')->error(
        'wmf_common: Dropping message altogether: {log_id}',
        ['log_id' => $logId]
      );
    }
    elseif ($ex->isRejectMessage() || $reject) {
      \Civi::log('wmf')->error(
        "wmf_common: Removing failed message from the queue: \n{message}",
        ['message' => $message]
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
      \Civi::log('wmf')->error(
        'wmf_common: Halting Process.');

      throw $ex;
    }
  }

  public function processMessageWithErrorHandling($message) {
    $this->logMessage($message);
    parent::processMessageWithErrorHandling($message);
  }

  protected function logMessage($message) {
    \Civi::log('wmf')->info('{class_name} {message}', [
      'class_name' => preg_replace('/.*\\\/', '', get_called_class()),
      'message' => $message,
    ]);
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
    // Example URL https://civicrm.wikimedia.org/civicrm/damaged/edit?&id=1234
    return "{$base_url}/civicrm/damaged/edit?id={$damagedId}";
  }

  protected function modifyDuplicateInvoice($message) {
    if (empty($message['invoice_id']) && isset ($message['order_id'])) {
      $message['invoice_id'] = $message['order_id'];
    }
    $message['invoice_id'] .= '|dup-' . UtcDate::getUtcTimeStamp();
    \Civi::log('wmf')->notice(
      'wmf_civicrm: Found duplicate invoice ID, changing this one to {invoice_id}',
      ['invoice_id' => $message['invoice_id']]
    );
    $message['contribution_tags'][] = 'DuplicateInvoiceId';
    return $message;
  }

}
