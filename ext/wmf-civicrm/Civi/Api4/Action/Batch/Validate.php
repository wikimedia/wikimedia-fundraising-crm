<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Generic\BasicBatchAction;
use Civi\Api4\WMFAudit;

class Validate extends BasicBatchAction {

  /**
   * @inheritDoc
   */
  protected function getSelect(): array {
    return ['id', 'name', 'status_id:name'];
  }

  /**
   * Re-validates a batch's totals and, for batches that were previously
   * trusted (validated/Exported), raises an alert if that trust turns out
   * to be misplaced - e.g. a donation was added to CiviCRM after the batch
   * was exported (T419097).
   *
   * @inheritDoc
   *
   * @throws \CRM_Core_Exception
   */
  public function doTask($item): array {
    $statusBefore = $item['status_id:name'];
    $record = WMFAudit::generateBatch($this->checkPermissions)
      ->setIsDryRun(TRUE)
      ->setIsOutputCsv(FALSE)
      ->setIsOutputRows(FALSE)
      ->setIsOutputSql(FALSE)
      ->setEmailSummaryAddress('')
      ->setId($item['id'])
      ->execute()->first() ?? [];
    $isValid = empty(array_filter($record['validation'] ?? []));

    if (in_array($statusBefore, ['validated', 'Exported'], TRUE) && !$isValid) {
      \Civi::log('wmf')->alert('{subject} {message}', [
        'subject' => $item['name'] . ' was ' . $statusBefore . ' but is no longer valid',
        'message' => 'Batch ' . $item['name'] . ' was previously ' . $statusBefore . ' but re-validation found a discrepancy - it may no longer match what was sent to Intacct.',
        'batch' => $item['name'],
        'previous_status' => $statusBefore,
        'validation' => $record['validation'] ?? [],
      ]);
    }

    return $record;
  }

}
