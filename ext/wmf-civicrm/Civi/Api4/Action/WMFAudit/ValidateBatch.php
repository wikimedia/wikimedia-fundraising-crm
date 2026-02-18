<?php

namespace Civi\Api4\Action\WMFAudit;

use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Civi\Api4\Batch;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\FinanceIntegration;
use Civi\Api4\WMFAudit;
use Civi\WMFBatch\BatchFile;
use CRM_Core_DAO;
use League\Csv\Writer;

/**
 * Validate the batch adds up.
 *
 * @method $this setId(int $id)
 * @method $this setName(string $name)
 */
class ValidateBatch extends AbstractAction {

  /**
   * Batch ID.
   *
   * @var int
   */
  protected $id;

  /**
   * Batch Name.
   *
   * Provide this OR id.
   *
   * @var string|null
   */
  protected ?string $name;

  /**
   * This function validates a batch and sets the status if invalid.
   *
   * @param Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $result = WMFAudit::generateBatch($this->checkPermissions)
      ->setIsDryRun(TRUE)
      ->setIsOutputCsv(FALSE)
      ->setIsOutputRows(FALSE)
      ->setIsOutputSql(FALSE)
      ->setEmailSummaryAddress('')
      ->setId($this->getId())
      ->execute();
  }

  public function getId() {
    if (isset($this->id)) {
      return $this->id;
    }
    return Batch::get(FALSE)->addWhere('name', '=', $this->name)->execute()->single()['id'];
  }

  private function log(string $string): void {
    $this->log[] = date('Y-m-d-m-Y-H-i-s') . ' ' . $string;
    \Civi::log('finance_integration')->info($string);
  }

}
