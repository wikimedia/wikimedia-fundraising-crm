<?php

namespace Civi\Api4\Action\Contribution;

use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\Result;
use Civi\WMFHook\GLCode;

/**
 * Set the calculated GL code on contributions.
 *
 * Writes to the table directly, so no hooks run and FinancialDataChange
 * records no adjustments.
 *
 * e.g. cv api4 Contribution.backfillGLCode +w 'id = 123' isDryRun=1
 *
 * @method $this setIsOverwrite(bool $isOverwrite)
 * @method $this setIsDryRun(bool $isDryRun)
 */
class BackfillGLCode extends AbstractBatchAction {

  /**
   * Replace existing GL codes, rather than only filling empty ones.
   *
   * @var bool
   */
  protected $isOverwrite = FALSE;

  /**
   * Return the calculated GL codes without saving them.
   *
   * @var bool
   */
  protected $isDryRun = FALSE;

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $glCodeField = \CRM_Core_BAO_CustomField::getFieldByName('Gift_Data.gl_code');
    $table = $glCodeField['custom_group']['table_name'];
    $column = $glCodeField['column_name'];
    foreach ($this->getBatchRecords() as $contribution) {
      $existing = $contribution['Gift_Data.gl_code'];
      if ($existing && !$this->isOverwrite) {
        continue;
      }
      $glCode = GLCode::getGLCode($contribution);
      $result[] = ['id' => $contribution['id'], 'gl_code_before' => $existing, 'gl_code' => $glCode];
      if ($this->isDryRun || $glCode === NULL || $glCode === $existing) {
        continue;
      }
      \CRM_Core_DAO::executeQuery("
        INSERT INTO $table (entity_id, $column) VALUES (%1, %2)
        ON DUPLICATE KEY UPDATE $column = %2
      ", [1 => [$contribution['id'], 'Integer'], 2 => [$glCode, 'String']]);
    }
  }

  protected function getSelect(): array {
    return ['id', 'Gift_Data.gl_code', ...GLCode::INPUT_FIELDS];
  }

  public function getPermissions(): array {
    return ['administer CiviCRM'];
  }

}
