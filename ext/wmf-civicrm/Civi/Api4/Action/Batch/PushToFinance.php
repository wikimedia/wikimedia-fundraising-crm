<?php

namespace Civi\Api4\Action\Batch;

use Civi\Api4\Batch;
use Civi\Api4\FinanceIntegration;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Push one batch's generated journals to Intacct and settle its status.
 *
 * Each journal records its own remote fields on the Batch - see
 * FinanceIntegration.pushJournal. What this adds is the decision no single
 * push can make: whether every journal for the batch is now accounted for
 * (Exported), or only some of them are (Partially Exported).
 *
 * Nothing here knows how the journals were arrived at, so a settlement
 * batch and an adjustment batch push the same way.
 *
 * @method $this setName(string $name)
 * @method $this setJournals(array $journals)
 * @method $this setBatchDescriptionPrefix(string $batchDescriptionPrefix)
 * @method $this setIsDryRun(bool $isDryRun)
 */
class PushToFinance extends AbstractAction {

  /**
   * The CiviCRM Batch.name whose journals these are.
   *
   * @required
   *
   * @var string
   */
  protected string $name;

  /**
   * The batch's generated files, in the shape writeJournalToCsv() returns -
   * each {file, instance, suffix, remote_descriptor}. Entries flagged
   * is_journal FALSE (e.g. the details csv) are skipped.
   *
   * @var array
   */
  protected array $journals = [];

  /**
   * @var string|null
   */
  protected ?string $batchDescriptionPrefix = NULL;

  /**
   * Is this a dry run (if so do not push to the api).
   *
   * @var bool
   */
  protected bool $isDryRun = FALSE;

  private array $log = [];

  /**
   * @param Result $result
   *   One record: 'is_uploaded' (bool), 'remote' (per-journal remote info,
   *   keyed by remote_descriptor), 'log' (lines for the caller's summary)
   *   and, on failure, 'upload_errors'.
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $journalsToUpload = [];
    foreach ($this->journals as $index => $journal) {
      if (empty($journal['is_journal'])) {
        // e.g. the details csv - generated alongside the journals but not
        // pushed. Skipped before validation, since it carries no descriptor.
        continue;
      }
      foreach (['file', 'instance', 'remote_descriptor'] as $required) {
        if (empty($journal[$required])) {
          throw new \CRM_Core_Exception("Journal $index for batch {$this->name} is missing $required");
        }
      }
      $journal['suffix'] = $journal['suffix'] ?? '';
      $journalsToUpload[] = $journal;
    }
    $outcome = ['is_uploaded' => FALSE, 'remote' => []];
    $successFullUploads = 0;
    try {
      foreach ($journalsToUpload as $journal) {
        foreach ($this->pushJournal($journal, $successFullUploads, count($journalsToUpload)) as $pushResult) {
          $successFullUploads++;
          $outcome['remote'][$pushResult['remote_descriptor']] = $pushResult['remote'];
          $outcome['is_uploaded'] = $pushResult['is_uploaded'];
        }
      }
    }
    catch (\Exception $e) {
      $this->log('failed to upload to Intacct with error ' . $e->getMessage());
      \Civi::log('wmf')->alert('{subject} {message}', [
        'subject' => $this->name . ' failed to upload to Intacct',
        'message' => $e->getMessage(),
        'batch' => $this->name,
      ]);
      $outcome['upload_errors'] = 'journal upload failed';
    }
    $journalCount = count($journalsToUpload);
    if ($journalCount && $successFullUploads > 0 && $successFullUploads < $journalCount) {
      // Exported to one entity but not the other - a straddling
      // state that needs manual review before any further export
      // attempt on this batch.
      $this->setStatus('Partially Exported');
      $this->log($this->name . ': only exported to ' . $successFullUploads . ' of ' . $journalCount . ' entities - status set to Partially Exported');
    }
    $outcome['log'] = $this->log;
    $result[] = $outcome;
  }

  /**
   * Push a single journal to Intacct.
   *
   * $priorSuccessCount is how many of this batch's journals have already
   * been confirmed by earlier calls - needed, together with $journalCount,
   * to know whether a given confirmation is the batch's last outstanding
   * one, so the batch can be marked Exported.
   *
   * @return array[] zero or more {remote_descriptor, remote, is_uploaded}
   *   results - normally one per journal, but pushJournal() can return
   *   more than one outcome for a single call.
   *
   * @throws \CRM_Core_Exception
   */
  private function pushJournal(array $journal, int $priorSuccessCount, int $journalCount): array {
    $this->log('Journal being pushed to the staging ' . $journal['instance'] . 'version of Intacct via the api : ' . $this->name . $journal['suffix']);
    $apiOutcome = FinanceIntegration::pushJournal($this->getCheckPermissions())
      ->setJournalFile($journal['file'])
      ->setIsDryRun($this->isDryRun)
      ->setInstance($journal['instance'])
      ->setBatchName($this->name . $journal['suffix'])
      ->setBatchDescriptionPrefix($this->batchDescriptionPrefix)
      ->setCiviBatchName($this->name)
      ->setRemoteDescriptor($journal['remote_descriptor'])
      ->execute();
    $this->log('Journal ' . $this->name . $journal['suffix'] . ' successfully pushed to Intacct ' . $journal['instance'] . ' instance with result ' . json_encode($apiOutcome, JSON_PRETTY_PRINT));
    $successCount = $priorSuccessCount;
    $results = [];
    foreach ($apiOutcome as $apiBatch) {
      if ($apiBatch['status'] === 'Valid Remotely') {
        $successCount++;
        $isUploaded = $successCount === $journalCount;
        $this->log($apiBatch['name'] . ': Batch has been verified against the batch in Intacct ' . ($isUploaded ? ' and is now being closed (status set to Exported)' : ''));
        if ($isUploaded) {
          $this->setStatus('Exported');
        }
        $results[] = [
          'remote_descriptor' => $journal['remote_descriptor'],
          'remote' => [
            'url' => $apiBatch['url'],
            'id' => $apiBatch['remote_journal_id'],
            'exchange_rate' => $apiBatch['exchange_rate'] ?? 1,
            'txn_number' => $apiBatch['txn_number'],
            'usd_journal_total' => $apiBatch['usd_journal_total'] ?? '',
            'usd_credit' => $apiBatch['usd_credit'] ?? '',
            'usd_debit' => $apiBatch['usd_debit'] ?? '',
          ],
          'is_uploaded' => $isUploaded,
        ];
      }
      $this->log('Remote batch url is <a href="' . $apiBatch['url'] . '">Intacct ' . $apiBatch['txn_number'] . '</a>');
    }
    return $results;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function setStatus(string $status): void {
    Batch::update(FALSE)
      ->addWhere('name', '=', $this->name)
      ->addValue('status_id:name', $status)
      ->execute();
  }

  /**
   * Batch declares no permissions of its own, so this would otherwise fall
   * through to the API4 default of 'administer CiviCRM'. Named explicitly -
   * and as the system half, since this writes to an external accounting
   * system rather than touching CiviCRM data. Note 'administer CiviCRM'
   * implies this one, so it is no narrower, just clearer.
   *
   * @return array
   */
  public function getPermissions(): array {
    return ['administer CiviCRM system'];
  }

  private function log(string $string): void {
    $this->log[] = date('Y-m-d-m-Y-H-i-s') . ' ' . $string;
    \Civi::log('finance_integration')->info($string);
  }

}
