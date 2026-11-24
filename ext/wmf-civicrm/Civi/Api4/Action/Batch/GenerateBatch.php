<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Generic\BasicBatchAction;
use Civi\Api4\WMFAudit;

class GenerateBatch extends BasicBatchAction {

  /**
   * Generates the batch's journal and pushes it to Intacct, closing the
   * batch (status Exported) on success.
   *
   * No summary email - SearchKit calls this per chunk of selected batches,
   * so it would send one per chunk. The scheduled run sends the summary.
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
      ->setEmailSummaryAddress('')
      ->setId($item['id'])
      ->execute()->first() ?? [];
  }

}
