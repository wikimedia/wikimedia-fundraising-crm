<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Generic\BasicBatchAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\WMFAudit;

class Validate extends BasicBatchAction {

  /**
   * @inheritDoc
   *
   * @throws \CRM_Core_Exception
   */
  public function doTask($item): array {
    return WMFAudit::generateBatch($this->checkPermissions)
      ->setIsDryRun(TRUE)
      ->setIsOutputCsv(FALSE)
      ->setIsOutputRows(FALSE)
      ->setIsOutputSql(FALSE)
      ->setEmailSummaryAddress('')
      ->setId($item['id'])
      ->execute()->first() ?? [];

  }

}
