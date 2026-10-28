<?php

namespace Civi\WMFBatch;

use Brick\Money\Money;
use Civi\Api4\Generic\Result;

/**
 * Builds & sends the summary email for a WMFAudit.GenerateBatch run.
 */
class BatchSummaryMailer {

  const NUMBER_OF_DAYS_OLD = 2;

  private Result $result;

  private array $batches;

  private array $batchSummary;

  private array $incompleteRows;

  private array $log;

  private array $accountMapping;

  private string $outputMethod;

  private bool $isOutputCsv;

  private ?string $emailSummaryAddress;

  private array $mainIncompleteBatches;

  private array $incompleteOfflineBatches;

  private array $allOfflineBatches;

  private ?string $offlineBatchEmail;

  private array $incomingFiles;

  /**
   * @param Result $result
   * @param array $batches batches keyed by name, as collected by GenerateBatch::getBatches(), including 'is_valid'.
   * @param array $batchSummary per-batch account/fee totals, as collected by GenerateBatch::writeJournalToCsv().
   * @param array $incompleteRows contribution rows with no account code found, keyed by batch name.
   * @param array $log log entries collected over the run.
   * @param array $accountMapping account code => account name.
   * @param string $outputMethod 'api' if journals are pushed to Intacct.
   * @param bool $isOutputCsv whether CSVs (and hence download links) were generated.
   * @param string|null $emailSummaryAddress address to send the summary to - if empty, nothing is sent.
   * @param array $mainIncompleteBatches Automatic batches, plus stale offline batches, still open/needs_attention.
   * @param array $incompleteOfflineBatches all offline batches still open/needs_attention, regardless of age.
   * @param array $allOfflineBatches every offline batch, regardless of status.
   * @param string|null $offlineBatchEmail address to send the offline batch report to - if empty, nothing is sent.
   */
  public function __construct(
    Result $result,
    array $batches,
    array $batchSummary,
    array $incompleteRows,
    array $log,
    array $accountMapping,
    string $outputMethod,
    bool $isOutputCsv,
    ?string $emailSummaryAddress,
    array $mainIncompleteBatches,
    array $incompleteOfflineBatches,
    array $allOfflineBatches,
    ?string $offlineBatchEmail
  ) {
    $this->result = $result;
    $this->batches = $batches;
    $this->batchSummary = $batchSummary;
    $this->incompleteRows = $incompleteRows;
    $this->log = $log;
    $this->accountMapping = $accountMapping;
    $this->outputMethod = $outputMethod;
    $this->isOutputCsv = $isOutputCsv;
    $this->emailSummaryAddress = $emailSummaryAddress;
    $this->mainIncompleteBatches = $mainIncompleteBatches;
    $this->incompleteOfflineBatches = $incompleteOfflineBatches;
    $this->allOfflineBatches = $allOfflineBatches;
    $this->offlineBatchEmail = $offlineBatchEmail;
  }

  /**
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function send(): void {
    $this->sendMainSummary();
    $this->sendOfflineBatchSummary();
  }

  /**
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function sendMainSummary(): void {
    if (!$this->emailSummaryAddress) {
      return;
    }
    $hasUploadErrors = FALSE;
    foreach ($this->result as $row) {
      if ($this->outputMethod === 'api' && !$row['is_uploaded']) {
        $hasUploadErrors = TRUE;
      }
    }

    $params = [
      'toEmail' => $this->emailSummaryAddress,
      'subject' => 'Finance batch generated',
      'from' => \CRM_Core_BAO_Domain::getFromEmail(),
    ];
    $invalidBatches = 0;
    $html = '';
    $tableOpenHtml = $this->getTableOpenHtml();
    if (!count($this->result)) {
      $params['subject'] .= ' no batches able to be generated';
    }
    if (count($this->result)) {
      // Start styled table.
      $html = '<html> <h3>The following batches have been generated</h3>';
      if (empty($this->getInvalidBatches()) && empty($this->incompleteRows)) {
        if (!$hasUploadErrors) {
          $html .= '<p>All batches have validated and the batches have been closed.</p>';
        }
        else {
          $html .= '<p>All batches have validated and the batches have been closed but upload was not fully succesful.</p>';
        }
      }
      else {
        $html .= "<p>One of more errors have prevented the batch from being closed.</p>";
      }

      $html .= $tableOpenHtml . '
        <thead>
          <tr style="background-color: #f2f2f2; font-weight: bold;">
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Batch</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Settled Currency</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Settled Total (In Bank)</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Total in batch (From CiviCRM)</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Discrepancy</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Journal total (debits + credits)</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Intacct Batch</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Exchange rate in Intacct</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Files</th>
          </tr>
        </thead>
        <tbody>
    ';

      foreach ($this->result as $batch) {
        if (!empty(array_filter($batch['validation']))) {
          $invalidBatches++;
        }
        $currency = htmlspecialchars($batch['batch']['batch_data.settlement_currency'], ENT_QUOTES, 'UTF-8', $batch['batch']['batch_data.settlement_currency']);
        $discrepancy = $batch['validation']['settled'];

        // Base cell styles
        $cell = 'border: 1px solid #ccc; padding: 6px;';
        $cellRight = $cell . ' text-align: right;';

        // Discrepancy cell: red + bold when non-zero
        $discrepancyStyle = $cellRight;
        if ((float) $discrepancy !== 0.0) {
          $discrepancyStyle .= ' color: #b30000; font-weight: bold;';
        }
        $discrepancy = \Civi::format()->money($discrepancy, $currency);

        $batchName = htmlspecialchars($batch['batch']['name'], ENT_QUOTES, 'UTF-8');
        $batchUrl = (string) \Civi::url('backend://civicrm/contribution/settled#?finance_batch=' . $batch['batch']['name'], 'a');

        $settled = \Civi::format()
          ->money(htmlspecialchars($batch['totals']['settled'], ENT_QUOTES, 'UTF-8'), $currency);
        $journalTotal = \Civi::format()
          ->money(htmlspecialchars($batch['totals']['debit'] + $batch['totals']['credit'] + $batch['totals']['fee'], ENT_QUOTES, 'UTF-8'), $currency);
        $remoteBatch = $batch['remote']['foundation'] ?? [];
        $usdJournalTotal = $currency === 'USD' ? $journalTotal : $remoteBatch['usd_journal_total'] ?? '';
        $totalInBatch = \Civi::format()
          ->money(htmlspecialchars($batch['batch']['batch_data.settled_net_amount'] ?? 0, ENT_QUOTES, 'UTF-8'), $currency);
        $numberOfTransactions = $batch['batch']['total'];
        $transactionsUrl = BatchFile::getBatchFileUrl([$batchName], 'details');
        $journalUrl = BatchFile::getBatchFileUrl([$batchName], 'journals');
        $remoteURL = $remoteBatch['url'] ?? '';
        $exchangeRate = $remoteBatch['exchange_rate'] ?? '';
        $html .= "
        <tr>
          <td style=\"$cell\"><a href='{$batchUrl}'>{$batchName}</a></td>
          <td style=\"$cell\">{$currency}</td>
          <td style=\"$cellRight\">{$totalInBatch}</td>
          <td style=\"$cellRight\">{$settled}</td>
          <td style=\"$discrepancyStyle\">{$discrepancy}</td>
          <td style=\"$cellRight\">{$journalTotal} ($usdJournalTotal)</td>
          <td style=\"$cellRight\">" . ($remoteURL ? "<a href='{$remoteURL}'>{$remoteBatch['txn_number']}</a>" : '') . "</td>
          <td style=\"$cellRight\">{$exchangeRate}</td><td style=\"$cellRight\">" . ($this->isOutputCsv && $numberOfTransactions ? "<a href='{$transactionsUrl}'> Download Transactions</a>" . "<br><a href='{$journalUrl}'> Download Journals</a>" : '') . "</td>
        </tr>
      ";
      }
      $html .= "
        </tbody>
      </table>";

      if ($this->incompleteRows) {
        $html .= "<h3>Found contribution/s without Account code</h3><p>The summary table below will not add up to the total due to these</p>" . $tableOpenHtml . '
        <thead>
          <tr style="background-color: #f2f2f2; font-weight: bold;">
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Contribution</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Channel</th>
          </tr>
        </thead>
        <tbody>';
        foreach ($this->incompleteRows as $batchName => $rows) {
          foreach ($rows as $row) {
            $contributionURL = \CRM_Utils_System::url('civicrm/contact/view/contribution', [
              'id' => $row['contribution_id'],
              'reset' => 1,
              'action' => 'view',
            ], TRUE);
            $html .= "
        <tr>
          <td style=\"$cell\">{$batchName} <a href='{$contributionURL}'>{$row['contribution_id']}</a></td>
          <td style=\"$cell\">{$row['channel']}</td>
        </tr>
      ";
          }
        }
        $html .= " </tbody> </table>";
      }
    }

    // Offline (manually entered) batches that are not yet ready are noisy in their
    // first week (that's a normal part of manual entry), so only surface the stale
    // ones here. The full offline list goes to the offline batch email instead.
    if (!empty($this->mainIncompleteBatches)) {
      $html .= "<h3>Incomplete batches</h3><p>The following batches are still open, pending a verified total or manual intervention</p>";
      $html .= $this->getIncompleteBatchesHtml($this->mainIncompleteBatches);
    }
    $html .= $this->renderIncomingFilesHtml();

    if ($this->batchSummary) {
      $html .= '<h3>Batch Summary</h3>';
      $html .= $this->getTableHeader(['Batch', 'Account Code', 'Account', 'Endowment Amount', 'Annual Fund Amount']);
      foreach ($this->batchSummary as $batchName => $batch) {
        $start = "<tr><td>{$batchName}</td>";
        if (!$batch['annual_fund_fees']->isEqualTo(0)) {
          $amount = $this->formatAmount($batch['annual_fund_fees']);
          $html .= $start . "<td>60917</td><td>Fees</td><td></td><td>{$amount}</td></tr>";
        }

        if (!$batch['endowment_fund_fees']->isEqualTo(0)) {
          $amount = $this->formatAmount($batch['endowment_fund_fees']);
          $html .= $start . "<td>60917</td><td>Fees</td><td>{$amount}</td><td></td></tr>";
        }
        foreach ($batch['accounts'] ?? [] as $accountNumber => $account) {
          $accountName = $this->getAccountName($accountNumber);
          if (!$account['annual_fund']->isEqualTo(0)) {
            $amount = $this->formatAmount($account['annual_fund']);
            $html .= $start . "<td>{$accountNumber}</td><td>{$accountName}</td><td></td><td>{$amount}</td></tr>";
          }
          if (!$account['endowment_fund']->isEqualTo(0)) {
            $amount = $this->formatAmount($account['endowment_fund']);
            $html .= $start . "<td>{$accountNumber}</td><td>{$accountName}</td><td>{$amount}</td><td></td></tr>";
          }
        }
      }
      $html .= '</tbody></table>';
    }
    $html .= '<h3>Log</h3> ' . $tableOpenHtml;
    foreach ($this->log as $log) {
      $log = nl2br($log);
      $html .= "<tr><td>$log</td></tr>";
    }

    $html .= '</table></html>';

    if ($invalidBatches) {
      $params['subject'] .= " {$invalidBatches} need attention";
    }
    if ($this->incompleteRows) {
      $incompleteCount = 0;
      foreach ($this->incompleteRows as $batch) {
        $incompleteCount += count($batch);
      }
      $params['subject'] .= " $incompleteCount contributions need attention";
    }
    if ($this->countOldIncomingFiles()) {
      $params['subject'] .= ', ' . $this->countOldIncomingFiles() . " incoming files older than " . self::NUMBER_OF_DAYS_OLD . 'days';
    }

    $params['html'] = $html;

    if (!\CRM_Utils_Mail::send($params)) {
      \Civi::log('wmf')->warning('Summary failed to send to ' . $params['toEmail']);
    }
  }

  /**
   * Email the offline batch report to the configured address: the not-ready
   * batches, plus a full status table covering every offline batch.
   *
   * @return void
   */
  private function sendOfflineBatchSummary(): void {
    if (!$this->offlineBatchEmail || (empty($this->incompleteOfflineBatches) && empty($this->allOfflineBatches))) {
      return;
    }

    $html = '<html><h3>Offline batches needing attention</h3>';
    if (empty($this->incompleteOfflineBatches)) {
      $html .= '<p>No offline batches currently need attention.</p>';
    }
    else {
      $html .= '<p>The following manually entered batches are still open, pending a verified total or manual intervention</p>'
        . $this->getIncompleteBatchesHtml($this->incompleteOfflineBatches);
    }

    $html .= '<h3>All offline batches</h3>';
    if (empty($this->allOfflineBatches)) {
      $html .= '<p>No offline batches found.</p>';
    }
    else {
      $html .= '<p>Status of every manually entered batch processed</p>'
        . $this->getIncompleteBatchesHtml($this->allOfflineBatches);
    }
    $html .= '</html>';

    $params = [
      'toEmail' => $this->offlineBatchEmail,
      'subject' => 'Offline batch status (' . count($this->incompleteOfflineBatches) . ' needing attention)',
      'from' => \CRM_Core_BAO_Domain::getFromEmail(),
      'html' => $html,
    ];
    if (!\CRM_Utils_Mail::send($params)) {
      \Civi::log('wmf')->warning('Offline batch summary failed to send to ' . $this->offlineBatchEmail);
    }
  }

  /**
   * @param array $batches
   * @return string
   */
  private function getIncompleteBatchesHtml(array $batches): string {
    $html = $this->getTableHeader(['Batch', 'Created Date', 'Currency', 'Total', 'Count', 'Status', 'Phab']);
    foreach ($batches as $incompleteBatch) {
      $phabLink = ($incompleteBatch['batch_data.issue_tracker_reference'] ? ('<a href="https://phabricator.wikimedia.org/' . $incompleteBatch['batch_data.issue_tracker_reference'] . '">' . $incompleteBatch['batch_data.issue_tracker_reference'] . '</a>') : '');
      $html .= "<tr><td>{$incompleteBatch['name']}</td><td>{$incompleteBatch['created_date']}</td><td>{$incompleteBatch['batch_data.settlement_currency']}</td><td>{$incompleteBatch['batch_data.settled_net_amount']}</td><td>{$incompleteBatch['item_count']}</td><td>{$incompleteBatch['status_id:label']}</td>
        <td>{$phabLink}</td>
      </tr>";
    }
    $html .= '</tbody></table>';
    return $html;
  }

  /**
   * Get batches that did not validated.
   * @return array
   */
  private function getInvalidBatches(): array {
    $invalidBatches = [];
    foreach ($this->batches as $batchName => $batch) {
      if (!$batch['is_valid']) {
        $invalidBatches[] = $batchName;
      }
    }
    return $invalidBatches;
  }

  /**
   * @param Money $money
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function formatAmount(Money $money): string {
    return \Civi::format()->money($money->getAmount(), $money->getCurrency());
  }

  private function getAccountName($accountCode): string {
    return $this->accountMapping[$accountCode] ?? '';
  }

  /**
   * @return string
   */
  private function getTableOpenHtml(): string {
    return '<table style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 14px;">';
  }

  /**
   * @param array $columns
   * @return string
   */
  private function getTableHeader(array $columns): string {
    $html = $this->getTableOpenHtml() . '<thead><tr style="background-color: #f2f2f2; font-weight: bold;">';
    foreach ($columns as $columnName) {
      $html .= '<th style="border: 1px solid #ccc; padding: 6px; text-align: left;">' . $columnName . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    return $html;
  }

  private function renderIncomingFilesHtml(): string {
    $tableOpenHtml = $this->getTableOpenHtml();
    $files = $this->getUnprocessedAuditFiles();

    $html = '<h3>Incoming files</h3>';

    if (empty($files)) {
      $html .= '<p>No files found in incoming directories.</p>';
      return $html;
    }

    $oldCount = count(array_filter($files, fn(array $file): bool => !empty($file['old'])));

    if ($oldCount) {
      $html .= '<p style="color: #b30000; font-weight: bold;">'
        . $oldCount . ' file(s) older than 2 days were found in incoming directories.'
        . '</p>';
    }
    else {
      $html .= '<p>No files older than 2 days were found in incoming directories.</p>';
    }

    $html .= $tableOpenHtml . '
    <thead>
      <tr style="background-color: #f2f2f2; font-weight: bold;">
        <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Modified</th>
        <th style="border: 1px solid #ccc; padding: 6px; text-align: right;">Size</th>
        <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Path</th>
        <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Status</th>
        <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Age (days)</th>
      </tr>
    </thead>
    <tbody>
  ';

    foreach ($files as $file) {
      $rowStyle = 'border: 1px solid #ccc; padding: 6px;';
      $rowStyleRight = $rowStyle . ' text-align: right;';
      $highlight = !empty($file['old']) ? ' color: #b30000; font-weight: bold;' : '';

      $html .= '<tr>';
      $html .= '<td style="' . $rowStyle . $highlight . '">'
        . htmlspecialchars(date('Y-m-d H:i', $file['mtime']), ENT_QUOTES, 'UTF-8')
        . '</td>';
      $html .= '<td style="' . $rowStyleRight . $highlight . '">'
        . number_format($file['size'])
        . '</td>';
      $html .= '<td style="' . $rowStyle . $highlight . '">'
        . htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8')
        . '</td>';
      $html .= '<td style="' . $rowStyle . $highlight . '">'
        . (!empty($file['old']) ? 'OLDER THAN 2 DAYS' : 'OK')
        . '</td>';
      $html .= '<td style="' . $rowStyle . $highlight . '">' . $file['days_old'] . '</td>';
      $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    return $html;
  }

  private function getUnprocessedAuditFiles(): array {
    if (isset($this->incomingFiles)) {
      return $this->incomingFiles;
    }
    $basePath = \Civi::settings()->get('wmf_audit_directory_audit');
    $cutoff = time() - (self::NUMBER_OF_DAYS_OLD * 24 * 60 * 60);

    $this->incomingFiles = [];

    $topLevelDirs = new \DirectoryIterator($basePath);

    foreach ($topLevelDirs as $dir) {
      if (!$dir->isDir() || $dir->isDot()) {
        continue;
      }

      $incomingDir = $dir->getPathname() . DIRECTORY_SEPARATOR . 'incoming';
      \Civi::log('wmf')->info('Looking for files in ' . $incomingDir);

      if (!is_dir($incomingDir) || !is_readable($incomingDir)) {
        \Civi::log('wmf')->info('Problem reading ' . $incomingDir);
        continue;
      }

      $incomingFiles = new \DirectoryIterator($incomingDir);

      foreach ($incomingFiles as $file) {
        if (!$file->isFile() || $file->isDot()) {
          continue;
        }

        $mtime = $file->getMTime();

        $this->incomingFiles[] = [
          'path' => $file->getPathname(),
          'mtime' => $mtime,
          'size' => $file->getSize(),
          'old' => ($mtime < $cutoff),
          'days_old' => (int) floor((time() - $mtime) / (24 * 60 * 60)),
        ];
      }
    }

    usort($this->incomingFiles, function(array $a, array $b): int {
      return $a['mtime'] <=> $b['mtime'];
    });

    return $this->incomingFiles;
  }

  private function countOldIncomingFiles(): int {
    $files = $this->getUnprocessedAuditFiles();
    $count = 0;
    foreach ($files as $file) {
      if (!empty($file['old'])) {
        $count++;
      }
    }
    return $count;
  }

}
