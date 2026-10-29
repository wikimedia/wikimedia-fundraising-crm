<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Generic\BasicBatchAction;
use Civi\Api4\WMFAudit;

class GenerateBatch extends BasicBatchAction {

  /**
   * Generates the batch's journal and pushes it to Intacct, closing the
   * batch (status Exported) on success.
   *
   * @inheritDoc
   *
   * @throws \CRM_Core_Exception
   */
  public function doTask($item): array {
    return WMFAudit::generateBatch($this->checkPermissions)
      ->setIsDryRun(FALSE)
      ->setOutputMethod('api')
      ->setIsOutputCsv(TRUE)
      ->setId($item['id'])
      ->execute()->first() ?? [];
  }

}
