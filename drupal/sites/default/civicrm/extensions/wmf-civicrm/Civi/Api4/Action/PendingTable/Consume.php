<?php
namespace Civi\Api4\Action\PendingTable;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PendingTransaction;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\UtcDate;

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

  public function _run(Result $result) {
    $startTime = time();
    $processed = 0;
    wmf_common_create_smashpig_context('pending_transaction_resolver', $this->gateway);
    $pendingDb = PendingDatabase::get();
    $message = $pendingDb->fetchMessageByGatewayOldest($this->gateway);
    while (
      $message &&
      $message['date'] < UtcDate::getUtcTimestamp("-{$this->minimumAge} minutes") &&
      ($this->timeLimit === 0 || time() < $startTime + $this->timeLimit) &&
      ($this->batch === 0 || $processed < $this->batch)
    ) {
      $resolveResult = PendingTransaction::resolve()
        ->setMessage($message)
        ->execute();
      \Civi::Log('wmf')->info(
        "Pending transaction {$message['contribution_tracking_id']} was " .
        'resolved and the result is ' . json_encode($resolveResult->first())
      );
      $pendingDb->deleteMessage($message);
      $processed++;
      $message = $pendingDb->fetchMessageByGatewayOldest($this->gateway);
    }
    // TODO add to prometheus
    $result->append([
      'transactions_resolved' => $processed,
      'time_elapsed' => time() - $startTime
    ]);
  }
}
