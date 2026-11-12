<?php

namespace Civi\Api4\Action\WMFAudit;

use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Civi\Api4\Batch;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\GatewayAccount;
use Civi\WMFBatch\BatchSummaryMailer;
use Civi\WMFBatch\FinanceConstants;
use Civi\WMFBatch\JournalGenerator;
use CRM_Core_DAO;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * Validate the batch adds up.
 *
 * @see https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_flow/Intacct#Intacct_Journal_Generation_&_Upload
 *
 * @method $this setBatchPrefix(string $batchPrefix)
 * @method $this setIsOutputCsv(bool $isOutputCsv)
 * @method $this setIsOutputSql(bool $isOutputSql)
 * @method $this setIsOutputRows(bool $isOutputRows)
 * @method $this setEmailSummaryAddress(string $email)
 * @method $this setIsDryRun(bool $isDryRun)
 * @method $this setId(int $batch_id)
 */
class GenerateBatch extends AbstractAction {

  /**
   * Batch modes this action knows how to export, matching the list in
   * FinanceBatchReferenceSpecProvider.
   *
   * An allow list rather than an exclusion, so a mode added later has to opt
   * in here before the settlement run touches it. Adjustment Batches are the
   * first to be left out: their journals come from a FinancialDataAdjustment
   * row rather than settlement data, so sweeping one up would find no settled
   * rows, fail validation and flag it needs_attention. mode_id is nullable
   * and NULL is not IN anything, so a batch with no mode is left alone too -
   * both of the places that create batches set one.
   */
  const EXPORTABLE_BATCH_MODES = ['Automatic Batch', 'Manual Batch'];

  /**
   * Is this a dry run (if so do not close batches or push to the api).
   *
   * @var bool
   */
  protected bool $isDryRun = FALSE;

  /**
   * Batch ID.
   *
   * Optional to run on a specific batch.
   *
   * @var int
   */
  protected $id;

  /**
   * Batch prefix.
   *
   * e.g adyen_1127 (the batch names are then adyen_1127_AUD)
   *
   * @var string
   */
  protected string $batchPrefix = '';

  /**
   * Is output rows.
   *
   * Generally rows might be useful in tests but are otherwise TMI.
   *
   * @var bool
   */
  protected bool $isOutputRows = FALSE;

  /**
   * Is output to Api.
   *
   * Should the result be pushed to the api or the sftp.
   *
   * @var string
   */
  protected string $outputMethod = '';

  /**
   * Is a csv to be output.
   *
   * @var bool
   */
  protected bool $isOutputCsv = FALSE;

  /**
   * Is the generated sql to be output.
   *
   * @var bool
   */
  protected bool $isOutputSql = FALSE;

  /**
   * The address to email a summary to.
   *
   * If provided a summary will be sent to this address.
   *
   * @var string|null
   */
  protected ?string $emailSummaryAddress = NULL;

  private array $headers = [];

  private array $incompleteRows = [];

  private array $log = [];

  private array $batchSummary = [];

  private array $batches;

  /**
   * GatewayAccount records, indexed by name. Lazy-loaded & cached for the
   * duration of the run - see getGatewayAccounts().
   *
   * @var array|null
   */
  private ?array $gatewayAccounts = NULL;

  /**
   * Get the configured GatewayAccount records, indexed by name.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function getGatewayAccounts(): array {
    if ($this->gatewayAccounts === NULL) {
      $this->gatewayAccounts = (array) GatewayAccount::get(FALSE)
        ->addSelect('name', 'gateway', 'is_endowment', 'vendor_code_foundation', 'vendor_code_endowment', 'balancing_account_foundation')
        ->execute()->indexBy('name');
    }
    return $this->gatewayAccounts;
  }

  /**
   * One JournalGenerator per batch, keyed by name - each is built around the
   * batch it generates for, and both call sites below want the same instance
   * for a given batch.
   *
   * @var JournalGenerator[]
   */
  private array $journalGenerators = [];

  private function getJournalGenerator(array $batch): JournalGenerator {
    if (!isset($this->journalGenerators[$batch['name']])) {
      $this->journalGenerators[$batch['name']] = new JournalGenerator($batch);
    }
    return $this->journalGenerators[$batch['name']];
  }

  /**
   * This function updates the settled transaction with new fee & currency conversion data.
   *
   * @param Result $result
   *
   * @return void
   * @throws MoneyMismatchException
   * @throws UnknownCurrencyException
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    foreach ($this->getBatches() as $batch) {
      $batchedData = $this->getJournalRows($batch);
      $record = array_filter([
        'csv_rows' => $batchedData,
        'batch' => $batch,
        'sql' => $this->isOutputSql ? $this->getBatchValue($batch['name'], 'sql') : NULL,
        'is_uploaded' => FALSE,
      ]);

      $record += $this->validateBatch($batch, $batchedData);
      if ($this->isExportLocked($batch)) {
        $this->handleExportLockedBatch($batch, $record);
      }
      elseif ($this->getBatchValue($batch['name'], 'is_valid')) {
        try {
          [$record['csv'], $isComplete] = $this->writeJournalToCsv($this->getRowsWithReversals($batchedData, $batch['name']), $batch);
        }
        catch (\Exception $e) {
          $this->log('Batch ' . $batch['name'] . ' failed to generate journal: ' . $e->getMessage());
          \Civi::log('wmf')->alert('{subject} {message}', [
            'subject' => $batch['name'] . ' failed to generate journal',
            'message' => $e->getMessage(),
            'batch' => $batch['name'],
          ]);
          $record['validation']['journal_generation'] = $e->getMessage();
          $isComplete = FALSE;
        }
        if (!$isComplete) {
          $this->log('Batch has missing GL data & hence not closed ' . $batch['name']);
        }
        elseif ($this->outputMethod === 'api') {
          $record = array_merge($record, $this->pushWithLock($batch, $record['csv'], TRUE));
        }
      }
      else {
        $this->log('Batch not valid & hence not closed ' . $batch['name']);
      }

      if (!$this->isOutputRows) {
        unset($record['csv_rows']);
      }
      $result[] = $record;
    }

    $this->sendSummary($result);
  }

  /**
   * Has any of this batch's journals been pushed to Intacct?
   */
  private function isPushed(array $batch): bool {
    foreach ($batch as $key => $value) {
      if (str_starts_with($key, 'batch_data.remote_identifier_') && !empty($value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Once pushed, the batch's files are the record of what went to Intacct, so
   * they are not regenerated.
   *
   * @see https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_flow/Intacct#Exported_batches
   */
  private function isExportLocked(array $batch): bool {
    return $this->isPushed($batch) && file_exists($this->getFilePath($batch['name'], 'journals'));
  }

  /**
   * Still valid: re-push the existing files - journals already in Intacct
   * with matching totals are confirmed rather than re-posted, so this
   * re-closes an Exported batch or completes a Partially Exported one.
   * Not valid: report what changed since the export.
   *
   * @throws \CRM_Core_Exception
   * @throws \League\Csv\Exception
   */
  private function handleExportLockedBatch(array $batch, array &$record): void {
    if ($this->getBatchValue($batch['name'], 'is_valid')) {
      $record['csv'] = $this->getExportedJournalFiles($batch);
      $this->log($batch['name'] . ' was already exported - re-pushing the existing files rather than regenerating them');
      if ($this->outputMethod === 'api') {
        $record = array_merge($record, $this->pushWithLock($batch, $record['csv'], FALSE));
      }
      return;
    }
    $record['changes_since_export'] = $this->getChangesSinceExport($batch);
    $this->log($batch['name'] . ' was already exported but no longer validates - contributions changed since export: ' . (implode(', ', array_keys($record['changes_since_export'])) ?: 'none found'));
  }

  /**
   * The journal files written at export, in the shape writeJournalToCsv() returns.
   *
   * Transfer files are only included if the export recorded a transfer, in
   * case one is left over from an earlier, pre-export generation.
   */
  private function getExportedJournalFiles(array $batch): array {
    $isEndowmentGateway = $this->batchSummary[$batch['name']]['is_endowment_gateway'];
    $csvFiles = ['journal_file' => $this->getPrimaryJournalFile($batch['name'])];
    $transferAmount = $batch[$isEndowmentGateway ? 'batch_data.amount_journaled_to_foundation' : 'batch_data.amount_journaled_to_endowment'] ?? 0;
    if (!(float) $transferAmount) {
      return $csvFiles;
    }
    $config = $isEndowmentGateway ? $this->getFoundationTransferConfig() : $this->getEndowmentTransferConfig();
    foreach (['from', 'to'] as $direction) {
      $key = $config[$direction . 'Key'];
      $csvFiles[$key] = ['file' => $this->getFilePath($batch['name'], substr($key, strlen('journal_'))), 'is_journal' => TRUE, 'instance' => $config[$direction . 'Instance'], 'suffix' => $config['suffix'], 'remote_descriptor' => $config[$direction . 'Descriptor']];
    }
    return $csvFiles;
  }

  private function getPrimaryJournalFile(string $batchName): array {
    $isEndowmentGateway = $this->batchSummary[$batchName]['is_endowment_gateway'];
    return [
      'file' => $this->getFilePath($batchName, 'journals'),
      'is_journal' => TRUE,
      'instance' => $isEndowmentGateway ? 'endowment' : 'foundation',
      'suffix' => '',
      'remote_descriptor' => $isEndowmentGateway ? 'endowment_instance' : 'foundation',
    ];
  }

  /**
   * Push under the batch lock, to avoid racing this code & adjustment tracking
   * per https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_flow/Intacct#Identifying_and_journaling_contribution_changes
   *
   * @throws \CRM_Core_Exception
   */
  private function pushWithLock(array $batch, array $csvFiles, bool $isRecordAmounts): array {
    $lock = \Civi::lockManager()->acquire(FinanceConstants::BATCH_LOCK_PREFIX . $batch['name']);
    if (!$lock->isAcquired()) {
      $this->log('Batch locked by a pending financial data change, skipping this run ' . $batch['name']);
      return [];
    }
    try {
      if ($isRecordAmounts) {
        $this->recordAmountsJournaled($batch);
      }
      return $this->pushJournalsToIntacct($batch, $csvFiles);
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Compare the current detail rows with the exported details csv.
   *
   * @return array
   *   Keyed by contribution_id, each with its 'exported' & 'current' rows.
   *
   * @throws \League\Csv\Exception
   * @throws \Civi\Core\Exception\DBQueryException
   */
  private function getChangesSinceExport(array $batch): array {
    $file = $this->getFilePath($batch['name'], 'details');
    if (!file_exists($file)) {
      return [];
    }
    $reader = Reader::from($file);
    $reader->setHeaderOffset(0);
    $exported = $this->getComparableRowsByContribution(iterator_to_array($reader->getRecords(), FALSE));
    $currentRows = [];
    foreach ($this->getDetailData($this->getBatchValue($batch['name'], 'sql'), $batch['name']) as $row) {
      $currentRows[] = $this->reOrderFields($row);
    }
    $current = $this->getComparableRowsByContribution($currentRows);
    $changes = [];
    foreach (array_keys($exported + $current) as $contributionID) {
      if (($exported[$contributionID] ?? []) !== ($current[$contributionID] ?? [])) {
        $changes[$contributionID] = ['exported' => $exported[$contributionID] ?? [], 'current' => $current[$contributionID] ?? []];
      }
    }
    return $changes;
  }

  /**
   * Reduce detail rows to the fields that affect totals, grouped by
   * contribution & sorted so row order doesn't matter.
   */
  private function getComparableRowsByContribution(array $rows): array {
    $fields = ['type', 'journal:ACCT_NO', 'is_endowment', 'settled_donation_amount', 'settled_fee_amount', 'settled_reversal_amount', 'settled_fee_reversal_amount'];
    $byContribution = [];
    foreach ($rows as $row) {
      $comparable = [];
      foreach ($fields as $field) {
        $value = (string) ($row[$field] ?? '');
        $comparable[$field] = (str_starts_with($field, 'settled_') && $value !== '') ? number_format((float) $value, 2, '.', '') : $value;
      }
      $byContribution[$row['contribution_id']][] = $comparable;
    }
    foreach ($byContribution as &$contributionRows) {
      sort($contributionRows);
    }
    return $byContribution;
  }

  /**
   * Push this batch's generated journals to Intacct.
   *
   * @return array with 'is_uploaded' (bool), 'remote' (per-journal remote
   *   info, keyed by remote_descriptor) and, on failure, 'upload_errors' -
   *   merge these into the caller's $record for this batch.
   *
   * @throws \CRM_Core_Exception
   */
  private function pushJournalsToIntacct(array $batch, array $journals): array {
    $outcome = Batch::pushToFinance($this->getCheckPermissions())
      ->setName($batch['name'])
      ->setJournals($journals)
      ->setBatchDescriptionPrefix($batch['batch_data.settlement_gateway'])
      ->setIsDryRun($this->isDryRun)
      ->execute()->first();
    // The action logs as it goes; fold its lines into ours so they still
    // reach the batch summary email.
    $this->log = array_merge($this->log, $outcome['log']);
    unset($outcome['log']);
    return $outcome;
  }

  /**
   * Record how this batch's donation total was split between the two
   * entities. Worked out here, from the journal we just wrote, so it is
   * written by the code that knows it rather than threaded through the
   * push for storage.
   *
   * @throws \CRM_Core_Exception
   */
  private function recordAmountsJournaled(array $batch): void {
    [$amountJournaledToEndowment, $amountJournaledToFoundation] = $this->getAmountsJournaled($batch);
    Batch::update(FALSE)
      ->addWhere('name', '=', $batch['name'])
      ->setValues([
        'batch_data.amount_journaled_to_endowment' => (string) $amountJournaledToEndowment->getAmount(),
        'batch_data.amount_journaled_to_foundation' => (string) $amountJournaledToFoundation->getAmount(),
      ])
      ->execute();
  }

  /**
   * The batch's donation total, split as [endowment, foundation] - whichever
   * instance the batch's gateway deposits to keeps whatever was not carved
   * out into a cross-instance transfer by writeJournalToCsv().
   *
   * @return Money[]
   */
  private function getAmountsJournaled(array $batch): array {
    $summary = $this->batchSummary[$batch['name']];
    $totalDonationAmount = Money::of((string) ($batch['batch_data.settled_donation_amount'] ?? 0), $summary['currency']);
    if ($summary['is_endowment_gateway']) {
      return [$totalDonationAmount->minus($summary['foundation_transfer']), $summary['foundation_transfer']];
    }
    return [$summary['endowment_transfer'], $totalDonationAmount->minus($summary['endowment_transfer'])];
  }

  public static function fields(): array {
    return [];
  }

  /**
   * @param array $csv_rows
   * @param array $batch
   *
   * @return array
   * @throws \Brick\Money\Exception\UnknownCurrencyException
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   * @throws \CRM_Core_Exception
   */
  private function writeJournalToCsv(array $csv_rows, array $batch): array {
    $batchName = $batch['name'];
    $renderedSql = $this->getBatchValue($batchName, 'sql');
    $isEndowmentGateway = $this->batchSummary[$batchName]['is_endowment_gateway'];
    $this->batchSummary[$batchName]['endowment_transfer'] = Money::zero($this->batchSummary[$batchName]['currency']);
    $this->batchSummary[$batchName]['foundation_transfer'] = Money::zero($this->batchSummary[$batchName]['currency']);

    if ($this->isOutputCsv) {
      if (!$csv_rows) {
        throw new \CRM_Core_Exception('No journal rows for batch ' . $batchName);
      }
      $headers = array_keys(reset($csv_rows));
      $batchJournalWriter = $this->getBatchJournalWriter($batchName);
      $batchJournalWriter->insertAll($csv_rows);
      $detailedData = $this->getDetailData($renderedSql, $batchName);

      foreach ($detailedData as $row) {
        if (empty($row['ACCT_NO'])) {
          $this->incompleteRows[$batchName][] = $row;
          $this->log("Account number not found for id {$row['contribution_id']} in channel . {$row['channel']}");
        }
        else {
          if (empty($this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']])) {
            $this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']] = [
              'annual_fund' => Money::zero($this->batchSummary[$batchName]['currency']),
              'endowment_fund' => Money::zero($this->batchSummary[$batchName]['currency']),
            ];
          }
          $fund = $row['is_endowment'] ? 'endowment_fund' : 'annual_fund';
          if ($this->isFee($row)) {
            $fee = $this->batchSummary[$batchName][$fund . '_fees'];
            $fee = $fee->plus($row['settled_fee_amount'] ?: 0);
            $fee = $fee->plus($row['settled_fee_reversal_amount'] ?: 0);
            $this->batchSummary[$batchName][$fund . '_fees'] = $fee;
          }
          else {
            $amount = $this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']][$fund];
            $amount = $amount->plus($row['settled_donation_amount'] ?: 0);
            $this->batchSummary[$batchName]['accounts'][$row['ACCT_NO']][$fund] = $amount;
          }
        }

        $formattedRow = $this->reOrderFields($row);
        if (!isset($detailWriter)) {
          $detailWriter = $this->getDetailsWriter(array_keys($formattedRow), $batchName);
        }
        $detailWriter->insertOne($formattedRow);
      }
      $primaryJournalFile = $this->getPrimaryJournalFile($batchName);
      $csvFiles = [
        'journal_file' => $primaryJournalFile,
        'detail_file' => ['file' => $detailWriter ? $detailWriter->getPathname() : NULL, 'is_journal' => FALSE, 'instance' => $primaryJournalFile['instance'], 'suffix' => ''],
      ];
      if (empty($this->incompleteRows[$batchName])) {
        foreach ($csv_rows as $row) {
          if (!$isEndowmentGateway && $row['is_endowment'] && !str_ends_with(trim($row['MEMO']), 'Fees') && ($row['ACCT_NO'] != $this->getReversalAccountCode($row['CURRENCY'], $batchName))) {
            $endowmentWriterFrom = $this->getJournalGenerator($batch)->getWriter($headers, 'source', 'foundation', 'endowment');
            $endowmentWriterTo = $this->getJournalGenerator($batch)->getWriter($headers, 'destination', 'foundation', 'endowment');
            $csvFiles += $this->writeCrossInstanceTransfer($row, $batchName, $endowmentWriterFrom, $endowmentWriterTo, $this->getEndowmentTransferConfig());
          }
          elseif ($isEndowmentGateway && !$row['is_endowment'] && !str_ends_with(trim($row['MEMO']), 'Fees') && ($row['ACCT_NO'] != $this->getReversalAccountCode($row['CURRENCY'], $batchName))) {
            // Reverse of the block above: an endowment-gateway batch whose
            // rows are earmarked for the annual (foundation) fund get
            // carved out and journaled over to the wmf/foundation instance.
            $foundationWriterFrom = $this->getJournalGenerator($batch)->getWriter($headers, 'source', 'endowment', 'foundation');
            $foundationWriterTo = $this->getJournalGenerator($batch)->getWriter($headers, 'destination', 'endowment', 'foundation');
            $csvFiles += $this->writeCrossInstanceTransfer($row, $batchName, $foundationWriterFrom, $foundationWriterTo, $this->getFoundationTransferConfig());
          }
        }
      }
      return [$csvFiles, empty($this->incompleteRows[$batchName])];
    }
    return [[], FALSE];
  }

  /**
   * Build and write one row's from/to balancing pair for a transfer
   * between the wmf and endowment Intacct instances, and accumulate the
   * transferred amount into batchSummary. Shared by the two directions -
   * see getEndowmentTransferConfig()/getFoundationTransferConfig() for what
   * differs between them.
   *
   * @param array $row
   * @param string $batchName
   * @param \League\Csv\Writer $writerFrom
   * @param \League\Csv\Writer $writerTo
   * @param array $config
   *
   * @return array
   *
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   */
  private function writeCrossInstanceTransfer(array $row, string $batchName, Writer $writerFrom, Writer $writerTo, array $config): array {
    $csvFiles = [
      $config['fromKey'] => ['file' => $writerFrom->getPathname(), 'is_journal' => TRUE, 'instance' => $config['fromInstance'], 'suffix' => $config['suffix'], 'remote_descriptor' => $config['fromDescriptor']],
      $config['toKey'] => ['file' => $writerTo->getPathname(), 'is_journal' => TRUE, 'instance' => $config['toInstance'], 'suffix' => $config['suffix'], 'remote_descriptor' => $config['toDescriptor']],
    ];

    $fromRow = $fromBalancingRow = $row;
    $toValues = [
      'LOCATION_ID' => $config['toLocationId'],
      'DEPT_ID' => '',
      'GLENTRY_VENDORID' => $this->{$config['vendorMapperMethod']}($batchName),
    ];
    $toBalancingRow = $toRow = array_merge($row, $toValues);
    if ($config['remapMajorGiftsToRestricted'] && str_starts_with($this->getAccountName($toRow['ACCT_NO']), 'Major Gifts')) {
      // In endowment journal treat all major gifts as restricted.
      $toRow['ACCT_NO'] = $this->getRestrictedMajorGiftsAccount();
    }

    $fromRow['DEBIT'] = $toBalancingRow['DEBIT'] = $row['CREDIT'];
    $fromRow['CREDIT'] = $toBalancingRow['CREDIT'] = $row['DEBIT'];
    $fromBalancingRow['ACCT_NO'] = $config['fromBalancingAccount'];
    $toBalancingRow['ACCT_NO'] = $config['toBalancingAccount'];
    $transfer = (string) ($row['CREDIT'] - $row['DEBIT']);
    $this->batchSummary[$batchName][$config['summaryKey']] = $this->batchSummary[$batchName][$config['summaryKey']]->plus($transfer);
    $writerFrom->insertOne($fromRow);
    $writerFrom->insertOne($fromBalancingRow);
    $writerTo->insertOne($toRow);
    $writerTo->insertOne($toBalancingRow);
    return $csvFiles;
  }

  /**
   * Config for the wmf->endowment transfer (normal gateways, donation rows
   * earmarked for the endowment fund) - see writeCrossInstanceTransfer().
   */
  private function getEndowmentTransferConfig(): array {
    return [
      'fromInstance' => 'foundation',
      'toInstance' => 'endowment',
      'fromKey' => 'journal_endowment_from_foundation',
      'toKey' => 'journal_foundation_to_endowment',
      'fromDescriptor' => 'to_endowment',
      'toDescriptor' => 'endowment_instance',
      'suffix' => 'e',
      'toLocationId' => FinanceConstants::LOCATION_ID_ENDOWMENT,
      'vendorMapperMethod' => 'mapVendorToEndowmentVendor',
      'remapMajorGiftsToRestricted' => TRUE,
      'fromBalancingAccount' => FinanceConstants::GL_BALANCING_ACCOUNT_WMF_TO_ENDOWMENT,
      'toBalancingAccount' => FinanceConstants::GL_BALANCING_ACCOUNT_ENDOWMENT_INSTANCE,
      'summaryKey' => 'endowment_transfer',
    ];
  }

  /**
   * Config for the endowment->foundation transfer (endowment-first gateways,
   * rows earmarked for the annual/foundation fund) - the reverse of
   * getEndowmentTransferConfig() - see writeCrossInstanceTransfer().
   */
  private function getFoundationTransferConfig(): array {
    return [
      'fromInstance' => 'endowment',
      'toInstance' => 'foundation',
      'fromKey' => 'journal_foundation_from_endowment',
      'toKey' => 'journal_endowment_to_foundation',
      'fromDescriptor' => 'to_foundation',
      'toDescriptor' => 'foundation',
      'suffix' => 'f',
      'toLocationId' => FinanceConstants::LOCATION_ID_WMF,
      'vendorMapperMethod' => 'mapVendorToFoundationVendor',
      // @todo unlike the endowment-bound transfer, this doesn't remap
      // Major Gifts to a restricted account - unconfirmed whether an
      // equivalent un-restricting remap is needed here.
      'remapMajorGiftsToRestricted' => FALSE,
      'fromBalancingAccount' => FinanceConstants::GL_BALANCING_ACCOUNT_ENDOWMENT_TO_WMF,
      'toBalancingAccount' => FinanceConstants::GL_BALANCING_ACCOUNT_WMF_INSTANCE,
      'summaryKey' => 'foundation_transfer',
    ];
  }

  private function mapVendorToEndowmentVendor(string $batchName): string {
    $accounts = $this->getGatewayAccounts();
    // Vendor code is optional - e.g. wire & ach have no vendor in Intacct.
    return (string) ($accounts[$this->getGateway($batchName)]['vendor_code_endowment'] ?? '');
  }

  private function mapVendorToFoundationVendor(string $batchName): string {
    $accounts = $this->getGatewayAccounts();
    // Vendor code is optional - e.g. wire & ach have no vendor in Intacct.
    return (string) ($accounts[$this->getGateway($batchName)]['vendor_code_foundation'] ?? '');
  }

  /**
   * @return Writer
   */
  public function getDetailsWriter(array $headers, $batchName): Writer {
    $detailWriter = Writer::from($this->getFilePath($batchName, 'details'), 'w');
    $detailWriter->insertOne($headers);
    return $detailWriter;
  }

  /**
   * @return Writer
   */
  private function getBatchJournalWriter(string $batchName): Writer {
    $journalWriter = Writer::from($this->getFilePath($batchName, 'journals'), 'w');
    $journalWriter->insertOne($this->headers);
    return $journalWriter;
  }

  private function getFilePath(string $batchName, string $type): string {
    return \Civi::settings()->get('wmf_audit_intact_files') . '/' . $this->getSafeFileName($batchName) . '_' . $type . '.csv';
  }

  /**
   * Batch names follow gateway_middle_currency (e.g adyen_1128_USD), but the
   * middle piece of a Manual Batch can be free text (e.g a wire description)
   * containing characters - "/" in particular - that aren't safe as part of
   * a path. Sanitize only that middle piece, replacing anything that isn't
   * alphanumeric (including any "_" already in there) with "-", so the
   * gateway_..._currency structure (relied on elsewhere, e.g. getGateway())
   * stays intact and the only underscores left are the two delimiters.
   *
   * e.g "wire_WIRE FROM 1/MICHAEL BERNARD_USD" becomes
   * "wire_WIRE-FROM-1-MICHAEL-BERNARD_USD".
   */
  private function getSafeFileName(string $batchName): string {
    $parts = explode('_', $batchName);
    if (count($parts) < 3) {
      return preg_replace('/[^a-zA-Z0-9_-]+/', '-', $batchName);
    }
    $gateway = array_shift($parts);
    $currency = array_pop($parts);
    $middle = preg_replace('/[^a-zA-Z0-9-]+/', '-', implode('_', $parts));
    return $gateway . '_' . $middle . '_' . $currency;
  }

  /**
   * @param array $defaults)
   * @return void
   */
  public function setHeaders(array $defaults): void {
    if (empty($this->headers)) {
      $this->headers = array_keys($defaults);
    }
  }

  private function getAccountName($accountCode): string {
    $map = $this->getAccountMapping();
    return $map[$accountCode] ?? '';
  }

  private function getRestrictedMajorGiftsAccount(): string {
    $map = array_flip($this->getAccountMapping());
    return $map['Major Gifts - Restricted'];
  }

  /**
   * @param string $renderedSql
   * @return array
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function getDetailData(string $renderedSql, $batchName): array {
    return $this->getJournalGenerator($this->getBatches()[$batchName])->getDetailData($renderedSql);
  }

  /**
   * Get the csv output rows augmented with reversal rows.
   *
   * The reversals code to 11250 so debits & credits wind up being equal.
   *
   * @param array $csvRows
   * @param string $batchName
   * @return array
   */
  public function getRowsWithReversals(array $csvRows, string $batchName): array {
    $rowsWithReversals = [];
    foreach ($csvRows as $row) {
      $rowsWithReversals[] = $row;
      $reversalRow = $row;
      $reversalRow['DEBIT'] = $row['CREDIT'];
      $reversalRow['CREDIT'] = $row['DEBIT'];
      $reversalRow['LINE_NO'] = $row['LINE_NO'] + 1;
      $reversalRow['ACCT_NO'] = $this->getReversalAccountCode($row['CURRENCY'], $batchName);
      $memoParts = explode(' | ', $row['MEMO']);
      unset($memoParts[3], $memoParts[6]);
      $reversalRow['MEMO'] = implode(' | ', $memoParts);
      $rowsWithReversals[] = $reversalRow;
    }
    return $rowsWithReversals;
  }

  private function getReversalAccountCode($currency, string $batchName): int {
    $balancingAccount = $this->getGatewayAccounts()[$this->getGateway($batchName)]['balancing_account_foundation'];
    if ($balancingAccount === FinanceConstants::BALANCING_ACCOUNT_HARD_CODED) {
      // Varies by currency - not yet representable as a single value on the
      // GatewayAccount record (see GatewayAccount_paypal in GatewayAccount.mgd.php).
      return FinanceConstants::PAYPAL_REVERSAL_ACCOUNT_CODES_BY_CURRENCY[$currency];
    }
    return (int) $balancingAccount;
  }

  /**
   * @param Result $result
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function sendSummary(Result $result): void {
    [$incompleteAutomaticBatches, $incompleteOfflineBatches] = $this->getIncompleteBatchesByMode();
    $staleOfflineBatches = [];
    foreach ($incompleteOfflineBatches as $batch) {
      if (!$this->isRecentOfflineBatch($batch)) {
        $staleOfflineBatches[] = $batch;
      }
    }
    $offlineResult = [];
    // Recent offline batches that failed are left out of the main email - they
    // are reported in the offline email.
    $mainResult = new Result();
    $mainBatches = $this->batches;
    $mainIncompleteRows = $this->incompleteRows;
    foreach ($result as $row) {
      if ($row['batch']['mode_id:name'] !== 'Automatic Batch') {
        $offlineResult[] = $row;
        $batchName = $row['batch']['name'];
        $isFailed = !empty(array_filter($row['validation'])) || !empty($this->incompleteRows[$batchName]);
        if ($isFailed && $this->isRecentOfflineBatch($row['batch'])) {
          unset($mainBatches[$batchName], $mainIncompleteRows[$batchName]);
          continue;
        }
      }
      $mainResult[] = $row;
    }

    (new BatchSummaryMailer(
      $mainResult,
      $mainBatches,
      $this->batchSummary,
      $mainIncompleteRows,
      $this->log,
      $this->getAccountMapping(),
      $this->outputMethod,
      $this->isOutputCsv,
      $this->emailSummaryAddress,
      array_merge($incompleteAutomaticBatches, $staleOfflineBatches),
      $incompleteOfflineBatches,
      $offlineResult,
      \Civi::settings()->get('wmf_audit_offline_batch_email')
    ))->send();
  }

  /**
   * Is this offline batch newer than wmf_audit_offline_batch_stale_days (by created_date)?
   */
  private function isRecentOfflineBatch(array $batch): bool {
    $staleDays = (int) \Civi::settings()->get('wmf_audit_offline_batch_stale_days');
    return strtotime($batch['created_date']) >= strtotime('-' . $staleDays . ' days');
  }

  /**
   * Get batches still open/needs_attention, split into Automatic vs offline (e.g Manual Batch).
   *
   * @return array{0: array, 1: array}
   * @throws \CRM_Core_Exception
   */
  private function getIncompleteBatchesByMode(): array {
    $incompleteBatches = Batch::get(FALSE)
      // Status list will probably grow.
      ->addWhere('status_id:name', 'IN', ['Open', 'needs_attention'])
      ->addWhere('mode_id:name', 'IN', self::EXPORTABLE_BATCH_MODES)
      ->addSelect('*', 'batch_data.*', 'status_id:label', 'mode_id:name')
      ->execute();

    $automaticBatches = [];
    $offlineBatches = [];
    foreach ($incompleteBatches as $batch) {
      if ($batch['mode_id:name'] === 'Automatic Batch') {
        $automaticBatches[] = $batch;
      }
      else {
        $offlineBatches[] = $batch;
      }
    }
    return [$automaticBatches, $offlineBatches];
  }

  private function log(string $string): void {
    $this->log[] = date('Y-m-d-H-i-s') . ' ' . $string;
    \Civi::log('finance_integration')->info($string);
  }

  /**
   * Is it a fee transaction.
   *
   * We loosely include adjustments here too as they are rare and like
   * fees affect our settlements but not our donations. The main difference being
   * that they are usually reversed again later (or re-adjusted).
   *
   * @param array $row
   * @return bool
   */
  public function isFee($row): bool {
    return str_contains($row['MEMO'], 'Fee') || str_ends_with($row['MEMO'], 'Adjustment');
  }

  /**
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function getBatches(): array {
    if (!isset($this->batches)) {
      $this->batches = [];
      $batchGet = Batch::get(FALSE)->addSelect('batch_data.*', '*', 'status_id:name', 'mode_id:name');
      if ($this->id) {
        $this->batches = (array) $batchGet->addWhere('id', '=', $this->id)
          ->execute()->indexBy('name');
        $batch = reset($this->batches);
        $isPreviouslyVerified = $batch['status_id:name'] === 'needs_attention' && !empty($batch['batch_data.last_successful_total_verification_date']);
        if (!$this->isDryRun && !in_array($batch['status_id:name'], ['validated', 'total_verified']) && !$isPreviouslyVerified) {
          // They have selected a specific batch, but it is not exportable, throw an exception.
          throw new \CRM_Core_Exception('Batch ' . $batch['name'] . ' status of ' . $batch['status_id:name'] . ' is not valid for export - it must be validated/total_verified, or needs_attention with a prior successful total verification.');
        }
      }
      else {
        $batchGet->addClause('OR',
          ['status_id:name', 'IN', ['total_verified', 'validated']],
          ['AND', [
            ['status_id:name', '=', 'needs_attention'],
            ['batch_data.last_successful_total_verification_date', 'IS NOT NULL'],
          ]]
        );

        $batchGet->addWhere('mode_id:name', 'IN', self::EXPORTABLE_BATCH_MODES);
        if ($this->batchPrefix) {
          $batchGet->addWhere('name', 'LIKE', '%' . $this->batchPrefix . '_%');
        }
        $this->batches = (array) $batchGet->execute()->indexBy('name');
      }
      if (empty($this->batches)) {
        return [];
      }

      foreach ($this->batches as $index => $batch) {
        // Get the settlement date range - this would be when it was settled to, e.g. our adyen account.
        // Fallback is required as we don't have a field for reversal settlement date so if it were only reversals...
        // Note in most cases settlement is daily so the date from the batch is expected to become the primary
        // way of doing this rather than just a fall-back.
        $result = CRM_Core_DAO::executeQuery(
          'SELECT MIN(DATE(settlement_date)) as from_date,
           MAX(DATE(settlement_date)) as to_date,
           TIMESTAMPDIFF(HOUR, MIN(settlement_date), MAX(settlement_date)) AS diff_hours
           FROM civicrm_value_contribution_settlement
           WHERE settlement_batch_reference = %1', [1 => [$batch['name'], 'String']]
         )->fetchAll()[0];

        if (empty($result['diff_hours']) || $result['diff_hours'] < 25) {
          // If we are looking at chargebacks only then the date range above will not find anything
          // or if we are looking at transactions that span 2 days but are only 24-ish hours then
          // let's just use the batch date settlement date.
          $result['from_date'] = $batch['batch_data.settlement_date'];
          $result['to_date'] = $batch['batch_data.settlement_date'];
        }

        $this->batches[$index] += $result;
        $this->batches[$index]['date_description'] = $result['from_date'] === $result['to_date'] ? gmdate('m/d/Y', strtotime($result['from_date'])) : gmdate('m/d/Y', strtotime($result['from_date'])) . ' - ' . gmdate('m/d/Y', strtotime($result['to_date']));
      }
    }
    return $this->batches;
  }

  /**
   * Get the gateway name used as the trxn_id prefix by the audit process.
   *
   * This is usually the same as the batch's gateway (per getGateway()), but
   * some GatewayAccount records (e.g. stripemg) settle transactions that are
   * actually audited, and trxn_id-prefixed, under a different gateway's name
   * (stripe) - see GatewayAccount.gateway.
   *
   * @param $batchName
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function getAuditGateway($batchName): string {
    $gateway = $this->getGateway($batchName);
    $accounts = $this->getGatewayAccounts();
    return $accounts[$gateway]['gateway'] ?? $gateway;
  }

  /**
   * @param array $batch
   *
   * @return array
   * @throws \Brick\Money\Exception\UnknownCurrencyException
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   */
  private function getJournalRows(array $batch): array {
    $generated = $this->getJournalGenerator($batch)->getJournalRows();
    $this->batchSummary[$batch['name']] = $generated['batchSummary'];
    $this->setHeaders($generated['defaults']);
    $this->batches[$batch['name']]['sql'] = $generated['sql'];
    return $generated['rows'];
  }

  /**
   * @param string $name
   * @param string $value
   *
   * @return mixed
   */
  private function getBatchValue(string $name, string $value): mixed {
    return $this->batches[$name][$value];
  }

  /**
   * @param mixed $batch
   * @param array $journalRows
   *
   * @return array
   * @throws \Brick\Money\Exception\MoneyMismatchException
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function validateBatch(array $batch, array $journalRows): array {
    $debit = $credit = $feeDebit = $feeCredit = Money::zero($batch['batch_data.settlement_currency']);
    $count = 0;
    foreach ($journalRows as $row) {
      $isFee = $this->isFee($row);
      $rowCount = (int) (explode(' | ', $row['MEMO'])[3]);
      if (!$isFee) {
        $count += $rowCount;
      }
      if (str_ends_with($row['MEMO'], 'Adjustment') || (str_ends_with($row['MEMO'], 'Fees')
        && !str_ends_with($row['MEMO'], 'Donation Fees'))
      ) {
        // These are the fee-only rows. Include them in the count
        // as they are in the batch count...
        $count += $rowCount;
      }
      if ($isFee) {
        $feeDebit = $feeDebit->plus($row['DEBIT'] ?: 0);
        $feeCredit = $feeCredit->plus($row['CREDIT'] ?: 0);
      }
      else {
        $debit = $debit->plus($row['DEBIT'] ?: 0);
        $credit = $credit->plus($row['CREDIT'] ?: 0);
      }
    }
    $result = [];

    $result['totals'] = [
      'debit' => (string) $debit->getAmount(),
      'credit' => (string) $credit->getAmount(),
      'fee_debit' => (string) $feeDebit->getAmount(),
      'fee_credit' => (string) $feeCredit->getAmount(),
      'fee' => (string) $feeDebit->minus($feeCredit)->getAmount(),
      'settled' => (string) $credit->minus($debit)->minus($feeDebit)->plus($feeCredit)->getAmount(),
      'count' => $count,
    ];
    $result['expected'] = [
      'count' => $batch['item_count'],
      'credit' => round($batch['batch_data.settled_donation_amount'] ?? 0, 2),
      'debit' => round($batch['batch_data.settled_reversal_amount'] ?? 0, 2),
      'fee' => $batch['batch_data.settled_fee_amount'],
      'settled' => round($batch['batch_data.settled_net_amount'], 2),
    ];
    $result['validation'] = [
      'count' => $result['expected']['count'] - $result['totals']['count'],
      'credit' => $result['expected']['credit'] - $result['totals']['credit'],
      'debit' => $result['expected']['debit'] + $result['totals']['debit'],
      'fee' => $result['expected']['fee'] + $result['totals']['fee'],
      'settled' => $result['expected']['settled'] - $result['totals']['settled'],
    ];
    $this->adjustManualBatchSecondaryTotals($batch, $result);
    $isValid = empty(array_filter($result['validation']));
    $this->batches[$batch['name']]['is_valid'] = $isValid;
    $this->log($batch['name'] . ' ' . ($isValid ? 'has valid totals' : ' has a discrepancy '));
    if ($isValid) {
      $update = Batch::update(FALSE)
        ->addValue('batch_data.last_attempted_validation_date', gmdate('Y-m-d H:i:s'))
        ->addValue('batch_data.last_discrepancy', 0)
        ->addValue('batch_data.last_successful_validation_date', gmdate('Y-m-d H:i:s'))
        ->addWhere('id', '=', $batch['id']);
      if (in_array($batch['status_id:name'], ['total_verified', 'needs_attention'], TRUE)
        && !empty($batch['batch_data.last_successful_total_verification_date'])
      ) {
        $update->addValue('status_id:name', 'validated');
        $this->batches[$batch['name']]['status_id:name'] = 'validated';
      }
      $update->execute();
      $this->log('The following batches have been validated ' . implode(',', array_keys($this->batchSummary)));
    }
    else {
      foreach (array_filter($result['validation']) as $key => $value) {
        if ($key !== 'count') {
          $value = \Civi::format()->money($value, $this->batchSummary[$batch['name']]['currency']);
        }
        $this->log($key . " has discrepancy of $value (expected {$result['expected'][$key]}, actual {$result['totals'][$key]} ). Batch needs attention");
        $this->batches[$batch['name']]['status_id:name'] = 'needs_attention';
        Batch::update(FALSE)
          ->addValue('batch_data.last_attempted_validation_date', gmdate('Y-m-d H:i:s'))
          ->addValue('batch_data.last_discrepancy', $result['validation']['settled'])
          ->addValue('status_id:name', 'needs_attention')
          ->addWhere('id', '=', $batch['id'])
          ->execute();
      }
    }
    return $result;
  }

  /**
   * For engage, ach and wire, trust our per-contribution count/fee/donation
   * breakdown over the batch's entered figures whenever the net settled amount
   * (and reversals) already reconcile.
   *
   * The entered item count and fee/donation split can drift from CiviCRM's
   * per-contribution data - e.g. staff split one check or transfer into
   * several contributions, or a fee is entered per-contribution but not at
   * the batch level because it is not known at that point.
   *
   * @param array $batch
   * @param array $result modified in place - 'expected'/'validation' are
   *   zeroed for whatever got corrected, and the Batch record is updated to
   *   match, when the net settled amount and reversals already reconcile.
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function adjustManualBatchSecondaryTotals(array $batch, array &$result): void {
    $auditGateway = $this->getAuditGateway($batch['name']);
    if (!in_array($auditGateway, ['engage', 'ach', 'wire'], TRUE)) {
      return;
    }
    $mustBalance = array_filter([
      'debit' => $result['validation']['debit'],
      'settled' => $result['validation']['settled'],
    ]);
    $toCorrect = array_filter([
      'count' => $result['validation']['count'],
      'credit' => $result['validation']['credit'],
      'fee' => $result['validation']['fee'],
    ]);
    if (empty($mustBalance) && !empty($toCorrect)) {
      Batch::update(FALSE)
        ->addValue('item_count', $result['totals']['count'])
        ->addValue('batch_data.settled_donation_amount', $result['totals']['credit'])
        // settled_fee_amount is stored as a negative value; totals['fee'] is its
        // positive mirror (see validation['fee'] = expected['fee'] + totals['fee']).
        ->addValue('batch_data.settled_fee_amount', -$result['totals']['fee'])
        ->addWhere('id', '=', $batch['id'])
        ->execute();
      $this->log($batch['name'] . ' item count, fee and/or donation amount corrected to the per-contribution totals (net settled amount already reconciled): ' . implode(', ', array_keys($toCorrect)));
      foreach (array_keys($toCorrect) as $key) {
        $result['expected'][$key] = $result['totals'][$key];
        $result['validation'][$key] = 0;
      }
    }
  }

  /**
   * @param $batchName
   *
   * @return string
   */
  public function getGateway($batchName): string {
    $parts = explode('_', $batchName);
    $gateway = $parts[0];
    return $gateway;
  }

  /**
   * @param mixed $row
   *
   * @return mixed
   */
  public function reOrderFields(array $row) {
    $reordered = [
      'gateway' => $row['gateway'],
      'settlement_date' => $row['settlement_date'],
      'type' => trim(str_replace(FinanceConstants::GL_ENDOWMENT_MEMO_SUFFIX, '', substr(strrchr($row['MEMO'], '|'), 1))),
      'DEBIT' => $row['DEBIT'],
      'CREDIT' => $row['CREDIT'],
    ];
    unset($row['type'], $row['gateway'], $row['settlement_date'], $row['DESCRIPTION'], $row['MEMO'], $row['DEBIT'], $row['CREDIT']);

    $glFields = ['ACCT_NO', 'LOCATION_ID', 'DEPT_ID', 'CURRENCY', 'GLDIMFUNDING', 'GL_VENDOR', 'GLENTRY_PROJECTID'];
    foreach ($glFields as $field) {
      $reordered['journal:' . $field] = $row[$field];
      unset($row[$field]);
    }
    $reordered += $row;
    return $reordered;
  }

  /**
   * @return string[]
   */
  private function getAccountMapping(): array {
    return [
      43480 => 'Recurring Gift',
      43481 => 'Banner',
      43482 => 'Email',
      43483 => 'Direct Mail',
      43484 => 'Online Other',
      43440 => 'Chapter Gifts',
      43485 => 'Major Gifts - Unrestricted',
      43428 => 'Major Gifts - Restricted',
    ];
  }

}
