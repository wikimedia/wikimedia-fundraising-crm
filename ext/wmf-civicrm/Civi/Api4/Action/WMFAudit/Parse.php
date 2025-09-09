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
 * @method $this setIsSettle(bool $isSettle)
 * @method $this setIsStopOnFirstMissing(bool $isStopOnFirstMissing)
 * @method $this setFile(string $file)
 * @method $this setLogSearchPastDays(int $logSearchPastDays)
 * @method $this setFileLimit(?int $fileLimit)
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
   * Should settlement be done on this run.
   *
   * @var bool
   */
  public bool $isSettle = FALSE;

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

  protected function getOptions(): array {
    return [
      'makemissing' => $this->isMakeMissing,
      'recon_complete_count' => 0,
      'file_limit' => $this->fileLimit,
      'file' => $this->file,
      'log_search_past_days' => $this->logSearchPastDays,
      'is_settle' => $this->isSettle,
      'is_stop_on_first_missing' => $this->isStopOnFirstMissing,
    ];
  }

  public function _run(Result $result) {
    if ($this->isMakeMissing) {
      \Civi::log('wmf')->info('Making payments data for missing transactions');
    }
    if ($this->file) {
      \Civi::log('wmf')->info('File argument given, only processing specified file: ' . $this->file);
    }
    \CRM_SmashPig_ContextWrapper::createContext($this->gateway . '_audit', $this->gateway);
    \CRM_SmashPig_ContextWrapper::setMessageSource(
      'audit', $this->gateway . ' Recon Auditor'
    );
    $audit = $this->loadAuditProcessor();
    $audit->run();
    foreach ($audit->getBatchInformation() as $batch) {
      $result[] = $batch;
      // In time we should only overwrite open batches but for now we just update to what we find
      // as this is experimental.
      Batch::save(FALSE)
        ->addRecord([
          'name' => $batch['settlement_batch_reference'],
          'status_id:name' => 'Open',
          'type_id:name' => 'automatic',
          'total' => $batch['settled_total_amount'],
          'item_count' => $batch['transaction_count'],
          'batch_data.settled_fee_amount' => $batch['settled_fee_amount'],
          'batch_data.settled_reversal_amount' => $batch['settled_reversal_amount'],
          'batch_data.settled_net_amount' => $batch['settled_net_amount'],
          'batch_data.settled_donation_amount' => $batch['settled_donation_amount'],
          'batch_data.settlement_currency' => $batch['settlement_currency'],
          'batch_data.settlement_date' => $batch['settlement_date'],
          'batch_data.settlement_gateway' => $batch['settlement_gateway'],
        ])
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
