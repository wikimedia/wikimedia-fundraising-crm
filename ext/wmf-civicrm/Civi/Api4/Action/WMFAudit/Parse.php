<?php

namespace Civi\Api4\Action\WMFAudit;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_SmashPig_ContextWrapper;

/**
 * @method string getGateway()
 * @method $this setGateway(string $gateway)
 * @method $this setIsMakeMissing(bool $isMakeMissing)
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
