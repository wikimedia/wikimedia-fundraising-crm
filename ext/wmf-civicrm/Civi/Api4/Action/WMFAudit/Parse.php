<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi;
use Civi\Api4\Batch;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_SmashPig_ContextWrapper;

/**
 * @method string getGateway()
 * @method $this setGateway(string $gateway)
 * @method $this setIsMakeMissing(bool $isMakeMissing)
 * @method $this setSettleMode(string $settleMode)
 * @method $this setIsStopOnFirstMissing(bool $isStopOnFirstMissing)
 * @method $this setIsMoveCompletedFile(bool $isMoveCompletedFile)
 * @method $this setIsSaveSettlementTransaction(bool $isSaveSettlementTransaction)
 * @method $this setIsCompleted(bool $isCompleted)
 * @method $this setFile(string $file)
 * @method $this setLogSearchPastDays(int $logSearchPastDays)
 * @method $this setLogInterval(int $logInterval)
 * @method $this setFileLimit(?int $fileLimit)
 * @method $this setRowLimit(?int $rowLimit)
 * @method $this setOffset(int $offset)
 */
class Parse extends AbstractAction {

  /**
   * 'Will reconstruct the un-rebuildable transactions found in the recon file, with default values.
   * USE WITH CAUTION: Currently this prevents real data from entering the system if we ever get it.'
   *
   * @var bool
   */
  public bool $isMakeMissing = FALSE;

  /**
   * How should settlement be done on this run? None (NULL) or queue|now.
   *
   * @var ?string
   */
  public ?string $settleMode = NULL;

  /**
   *
   * @var bool
   */
  public bool $isSaveSettlementTransaction = FALSE;

  /**
   * Should the parser find the file in the completed directory.
   *
   * @var bool
   */
  public bool $isCompleted = FALSE;

  /**
   * Is move completed file.
   *
   * In tests this is set to false for convenience.
   *
   * @var bool
   */
  public bool $isMoveCompletedFile = TRUE;

  /**
   * Should parsing stop on the first missing one.
   *
   * Useful in debug context.
   *
   * @var bool
   */
  public bool $isStopOnFirstMissing = FALSE;

  /**
   * Name of a file to parse (optional) (must be in the incoming directory, should not include full path).
   *
   * @var string
   */
  public string $file = '';

  /**
   * Number of days to go back in finding log files.
   *
   * @var int
   */
  public int $logSearchPastDays = 7;

  /**
   * @var string
   *
   * @required
   */
  public string $gateway;

  /**
   * How many files should be parsed.
   *
   * If left at NULL this will pick up the
   * @var int|null
   */
  public ?int $fileLimit = NULL;

  /**
   * Log progress after this many rows.
   *
   * @var int $logInterval
   */
  protected int $logInterval = 10000;

  /**
   * Number of rows to parse.
   *
   * By default all rows are passed. Useful for troubleshooting.
   *
   * @var int|null
   */
  public ?int $rowLimit = NULL;

  /**
   * Offset row to start at.
   *
   * By default all rows are passed. Useful for troubleshooting.
   *
   * @var int
   */
  public int $offset = 0;

  protected function getOptions(): array {
    return [
      'makemissing' => $this->isMakeMissing,
      'recon_complete_count' => 0,
      'file_limit' => $this->fileLimit,
      'file' => $this->file,
      'log_search_past_days' => $this->logSearchPastDays,
      'settle_mode' => $this->settleMode,
      'is_save_settlement_transaction' => $this->isSaveSettlementTransaction,
      'is_stop_on_first_missing' => $this->isStopOnFirstMissing,
      'is_move_completed_file' => $this->isMoveCompletedFile,
      'is_completed' => $this->isCompleted,
      'progress_log_count' => $this->logInterval,
      'row_limit' => $this->rowLimit,
      'row_offset' => $this->offset,
    ];
  }

  public function _run(Result $result) {
    if ($this->isMakeMissing) {
      \Civi::log('wmf')->info('Making payments data for missing transactions');
    }
    if ($this->file) {
      \Civi::log('wmf')->info('File argument given, only processing specified file: ' . $this->file);
    }
    if ($this->isCompleted && !$this->file) {
      throw new \CRM_Core_Exception('isCompleted is intended to be used for a specific already-parsed file');
    }
    if (($this->offset || $this->rowLimit) && !$this->file) {
      throw new \CRM_Core_Exception('offset and rowLimit only valid when parsing a specific file');
    }
    \CRM_SmashPig_ContextWrapper::createContext($this->gateway . '_audit', $this->gateway);
    \CRM_SmashPig_ContextWrapper::setMessageSource(
      'audit', $this->gateway . ' Recon Auditor'
    );
    $audit = $this->loadAuditProcessor();
    $audit->run();
    foreach ($audit->getBatchInformation() as $batch) {
      $existing = Batch::get(FALSE)
        ->addWhere('name', '=', $batch['settlement_batch_reference'])
        ->addSelect('status_id:name')
        ->execute()->first() ?? [];
      $batch['settled_total_amount'] = (string) $batch['settled_total_amount']->getAmount();
      $batch['settled_net_amount'] = (string) $batch['settled_net_amount']->getAmount();
      $batch['settled_fee_amount'] = (string) $batch['settled_fee_amount']->getAmount();
      $batch['settled_reversal_amount'] = (string) $batch['settled_reversal_amount']->getAmount();
      $batch['settled_donation_amount'] = (string) $batch['settled_donation_amount']->getAmount();
      $result[] = $batch;
      if (!empty($existing['status_id:name']) && !in_array($existing['status_id:name'], ['Reopened', 'Open'], TRUE)) {
        // Once it is verified or exported we don't overwrite it.
        continue;
      }
      // In time we should only overwrite open batches but for now we just update to what we find
      // as this is experimental.
      Batch::save(FALSE)
        ->addRecord([
          'name' => $batch['settlement_batch_reference'],
          'status_id:name' => $batch['status_id:name'],
          'type_id:name' => 'Contribution',
          'mode_id:name' => 'Automatic Batch',
          'total' => $batch['settled_total_amount'],
          'item_count' => $batch['transaction_count'],
          'batch_data.settled_fee_amount' => $batch['settled_fee_amount'],
          'batch_data.settled_reversal_amount' => $batch['settled_reversal_amount'],
          'batch_data.settled_net_amount' => $batch['settled_net_amount'],
          'batch_data.settled_donation_amount' => $batch['settled_donation_amount'],
          'batch_data.settlement_currency' => $batch['settlement_currency'],
          'batch_data.settlement_date' => $batch['settlement_date'],
          'batch_data.settlement_gateway' => $batch['settlement_gateway'],
        ] + $existing)
        ->setMatch(['name', 'type_id'])
        ->execute();
    }
  }

  /**
   * @return \Civi\WMFAudit\BaseAuditProcessor
   */
  private function loadAuditProcessor(): Civi\WMFAudit\BaseAuditProcessor {
    $class = '\Civi\WMFAudit\\' . ucfirst($this->gateway) . 'AuditProcessor';
    return new $class(
      $this->getOptions()
    );
  }

}
