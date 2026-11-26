<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Batch;
use Civi\Api4\Generic\BasicBatchAction;
use Civi\WMFBatch\BatchDiscrepancyExplainer;
use Civi\WMFBatch\BatchFileComparer;

/**
 * Re-validate a batch and explain any discrepancy - in words, then per transaction against the audit file.
 *
 * e.g. wmf-cv api4 Batch.diagnose +w name=paypal_20261120_USD
 *
 * @method $this setIncomingDirectory(string $incomingDirectory)
 * @method $this setIsAlwaysCompareFile(bool $isAlwaysCompareFile)
 */
class Diagnose extends BasicBatchAction {

  /**
   * Audit file directory override (as WMFAudit.parse) - otherwise the gateway's incoming & completed directories.
   *
   * @var string
   */
  protected string $incomingDirectory = '';

  /**
   * Compare with the audit file even when the batch validates.
   *
   * @var bool
   */
  protected bool $isAlwaysCompareFile = FALSE;

  protected function getSelect(): array {
    return ['id', 'name', 'status_id:name', 'mode_id:name'];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function doTask($item): array {
    $record = Batch::validate($this->checkPermissions)
      ->addWhere('id', '=', $item['id'])
      ->execute()->first() ?? [];
    if (!empty($record['skipped'])) {
      return $record;
    }
    $diagnosis = [
      'name' => $item['name'],
      'is_valid' => empty(array_filter($record['validation'] ?? [])),
      'civicrm_minus_expected' => BatchDiscrepancyExplainer::getCiviMinusExpected($record['validation'] ?? []),
      'summary' => [($record['explanation'] ?? '') ?: 'Batch totals match.'],
    ];
    if (!$diagnosis['is_valid'] || $this->isAlwaysCompareFile) {
      $diagnosis['file_check'] = (new BatchFileComparer($item, $this->incomingDirectory))->compare();
      $diagnosis['summary'] = array_merge($diagnosis['summary'], $diagnosis['file_check']['summary']);
      if (isset($diagnosis['file_check']['explained'])) {
        $diagnosis['summary'][] = $this->describeRemainder($diagnosis['civicrm_minus_expected'], $diagnosis['file_check']['explained']);
      }
    }
    return $diagnosis + $record;
  }

  /**
   * Do the per-transaction differences account for the whole discrepancy?
   */
  private function describeRemainder(array $discrepancy, array $explained): string {
    $remainder = [];
    foreach ($discrepancy as $key => $value) {
      $left = round($value - ($explained[$key] ?? 0), 2);
      if ($left) {
        $remainder[] = "$key $left";
      }
    }
    return $remainder
      ? 'The transactions listed do not fully account for the discrepancy - still unexplained (CiviCRM minus file): ' . implode(', ', $remainder) . '. The batch\'s saved expected totals may be from a different file (e.g. TRR rather than STL) or a fee-only/adjustment row may differ.'
      : 'The transactions listed fully account for the discrepancy.';
  }

}
