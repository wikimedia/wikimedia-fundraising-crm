<?php
namespace Civi\Api4\Action\PendingTable;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PendingTransaction;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\UtcDate;
use SmashPig\PaymentData\FinalStatus;

/**
 * Resolves pending transactions by completing, canceling or discarding them.
 * This is the action formerly known as 'rectifying' or 'slaying' an 'orphan'.
 *
 * Starts at the oldest pending transaction for the given gateway and
 * continues until it reaches transactions younger than $minimumAgeInMinutes.
 *
 * @method $this setGateway(string $gateway) Set gateway code
 * @method array getGateway() Get WMF normalised values.
 * @method $this setMinimumAge(int $minimumAge) Set minimum txn age (minutes)
 * @method int getMinimumAge() Get minimum age of txns to process
 * @method $this setBatch(int $batch) Set consumer batch limit
 * @method int getBatch() Get consumer batch limit
 * @method $this setTimeLimit(int $timeLimit) Set consumer time limit (seconds)
 * @method int getTimeLimit() Get consumer time limit (seconds)
 *
 */
class Consume extends AbstractAction {

  /**
   * @var string
   * @required
   */
  protected $gateway;

  /**
   * Minimum age of pending transactions to process (in minutes)
   * @var int
   */
  protected $minimumAge = 30;

  /**
   * @var int
   */
  protected $batch = 0;

  /**
   * @var int
   */
  protected $timeLimit = 0;

  protected array $emailsWithResolvedTransactions = [];

  public function _run(Result $result) {
    $startTime = time();
    $processed = 0;
    \CRM_SmashPig_ContextWrapper::createContext('pending_transaction_resolver', $this->gateway);
    $pendingDb = PendingDatabase::get();
    $message = $pendingDb->fetchMessageByGatewayOldest(
      $this->gateway, PendingTransaction::getResolvableMethods()
    );
    $statusCounts = [
      FinalStatus::COMPLETE => 0,
      FinalStatus::FAILED => 0,
      FinalStatus::PENDING_POKE => 0,
    ];
    while (
      $message &&
      $message['date'] < UtcDate::getUtcTimestamp("-{$this->minimumAge} minutes") &&
      ($this->timeLimit === 0 || time() < $startTime + $this->timeLimit) &&
      ($this->batch === 0 || $processed < $this->batch)
    ) {
      $resolveResult = PendingTransaction::resolve()
        ->setMessage($message)
        ->setAlreadyResolved($this->emailsWithResolvedTransactions)
        ->execute()->first();
      Civi::log('wmf')->info(
        "Pending transaction {$message['order_id']} was " .
        'resolved and the result is ' . json_encode($resolveResult)
      );
      $statusCounts[$resolveResult['status']] = ($statusCounts[$resolveResult['status']] ?? 0) + 1;
      $pendingDb->deleteMessage($message);
      $processed++;
      $message = $pendingDb->fetchMessageByGatewayOldest($this->gateway, PendingTransaction::getResolvableMethods());
      // Keep track of emails with completed transactions so we can skip duplicates
      if ($resolveResult['status'] === FinalStatus::COMPLETE) {
        $this->emailsWithResolvedTransactions[$resolveResult['email']] = TRUE;
      }
    }
    if (!$message) {
      Civi::log('wmf')->info(
        'All {gateway} pending transactions resolved', ['gateway' => $this->gateway]
      );
    }
    else if ($message['date'] >= UtcDate::getUtcTimestamp("-{$this->minimumAge} minutes")) {
      Civi::log('wmf')->info(
        'All {gateway} pending transactions older than {minimumAge} minutes resolved',
        ['gateway' => $this->gateway, 'minimumAge' => $this->minimumAge]
      );
    }
    if ($this->timeLimit > 0 && time() >= $startTime + $this->timeLimit) {
      Civi::log('wmf')->info(
        'Reached time limit of {timeLimit} seconds',
        ['timeLimit' => $this->timeLimit]
      );
    }
    if ($this->batch > 0 && $processed >= $this->batch) {
      Civi::log('wmf')->info(
        'Reached batch limit of {batch}', ['batch' => $this->batch]
      );
    }
    // TODO add to prometheus
    $result->append([
      'transactions_resolved' => $processed,
      'counts_by_status' => $statusCounts,
      'time_elapsed' => time() - $startTime
    ]);
  }
}
