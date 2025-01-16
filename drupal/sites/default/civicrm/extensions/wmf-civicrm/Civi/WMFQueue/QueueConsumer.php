<?php

namespace Civi\WMFQueue;

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\Core\SequenceGenerators\Factory;
use Civi\Core\Exception\DBQueryException;
use Civi\WMFStatistic\ImportStatsCollector;
use SmashPig\Core\QueueConsumers\BaseQueueConsumer;
use Exception;
use SmashPig\Core\UtcDate;
use SmashPig\CrmLink\Messages\DateFields;
use Civi\WMFException\WMFException;

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
    elseif ($ex instanceof DBQueryException && in_array($ex->getDBErrorMessage(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
      $newException = new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $ex->getErrorData());
      \Civi::log('wmf')->error(
        'wmf_common: Message not saved due to database load: {message}',
        ['message' => $ex->getMessage()]
      );
      $this->handleWMFException($message, $newException, $logId);
      throw $ex;
    }
    else {
      $error = 'UNHANDLED ERROR. Halting dequeue loop. Exception: ' .
        $ex->getMessage() . "\nStack Trace: " .
        $ex->getTraceAsString();
      \Civi::log('wmf')->error(
        'wmf_common: {error}', ['error' => $error]);
      wmf_common_failmail('wmf_common', $error, NULL, $logId);
      $this->sendToDamagedStore($message, $ex);
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
      $delay = (int) \Civi::settings()->get('wmf_requeue_delay');
      $maxTries = (int) \Civi::settings()->get('wmf_requeue_max');
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
    // Example URL https://civicrm.wikimedia.org/civicrm/damaged/?action=update&id=1234&reset=1
    return "$base_url/civicrm/damaged/edit?action=update&id=$damagedId&reset=1";
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
    return $message;
  }

  public function initiateStatistics(): void {

  }

  public function reportStatistics(int $totalMessagesDequeued): void {

  }

  /**
   * @param string $action
   *
   * @return void
   */
  public function startTiming(string $action): void {
    ImportStatsCollector::getInstance()->startImportTimer($action);
  }

  /**
   * @param string $action
   *
   * @return void
   */
  public function stopTiming(string $action): void {
    ImportStatsCollector::getInstance()->endImportTimer($action);
  }


  /**
   * If we're missing a contribution tracking id, insert new record to the table.
   * This can happen if a user somehow makes a donation from outside the normal workflow
   * Historically checks have been ignored as they are completely offline.
   * T146295 has raised some questions about this.
   * We respect the recognition of 'payment_method' as being a little bit magic, but
   * also assume that if you are setting utm_medium or utm_source in your import you
   * intend them to be recorded.
   *
   * @deprecated - needs some more thought / clean up
   *
   * @param array $msg
   *
   * @return array same message, possibly with contribution_tracking_id set
   * @throws WMFException
   */
  protected function addContributionTrackingIfMissing($msg) {
    if (isset($msg['contribution_tracking_id'])) {
      return $msg;
    }
    $paymentMethodIsCheckOrEmpty = empty($msg['payment_method']) || strtoupper($msg['payment_method']) == 'CHECK';
    $hasUtmInfo = !empty($msg['utm_medium']) || !empty($msg['utm_source']);
    if ($paymentMethodIsCheckOrEmpty && !$hasUtmInfo) {
      return $msg;
    }
    \Civi::log('wmf')->debug('wmf_civicrm: Contribution missing contribution_tracking_id');

    $source = isset($msg['utm_source']) ? $msg['utm_source'] : '..' . $msg['payment_method'];
    $medium = isset($msg['utm_medium']) ? $msg['utm_medium'] : 'civicrm';
    $campaign = isset($msg['utm_campaign']) ? $msg['utm_campaign'] : NULL;

    $tracking = [
      'utm_source' => $source,
      'utm_medium' => $medium,
      'utm_campaign' => $campaign,
      'tracking_date' => date('Y-m-d H:i:s', $msg['date']),
    ];
    if (
      !empty($msg['country']) &&
      array_search($msg['country'], \CRM_Core_PseudoConstant::countryIsoCode()) !== FALSE
    ) {
      $tracking['country'] = $msg['country'];
    }
    try {
      $contribution_tracking_id = $this->generateContributionTracking($tracking);
    }
    catch (Exception $e) {
      throw new WMFException(WMFException::INVALID_MESSAGE, $e->getMessage());
    }
    \Civi::log('wmf')->debug('wmf_civicrm: Newly inserted contribution tracking id: {id}', ['id' => $contribution_tracking_id]);
    $msg['contribution_tracking_id'] = $contribution_tracking_id;
    return $msg;
  }

  /**
   * Insert a record into contribution_tracking table
   *
   * Primarily used when a record does not already exist in the table for a
   * particular transaction.  Rare, but inserting some data for a trxn when
   * absent helps facilitate better analytics.
   *
   * @param array $values associative array of columns => values to insert
   *  into the contribution tracking table
   *
   * @return int the contribution_tracking id
   *
   * @throws \Exception
   */
  protected function generateContributionTracking($values) {
    $generator = Factory::getSequenceGenerator('contribution-tracking');
    $contribution_tracking_id = $generator->getNext();
    $values['id'] = $contribution_tracking_id;
    QueueWrapper::push('contribution-tracking', $values);
    \Civi::log('wmf')->info('wmf_civicrm: Queued new contribution_tracking entry {id}', ['id' => $contribution_tracking_id]);
    return $contribution_tracking_id;
  }

}
