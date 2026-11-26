<?php

namespace Civi\WMFBatch;

use Civi\Api4\Contribution;
use Civi\Api4\GatewayAccount;
use Civi\WMFAudit\BaseAuditProcessor;

/**
 * Compares a batch's audit file rows with the CiviCRM settlement records journalled to it, per transaction.
 *
 * Gateway knowledge (where files live & how they are named) comes from the audit processor's
 * getFilesForSettlementBatch(); file parsing uses the same SmashPig parser that built the expected totals.
 */
class BatchFileComparer {

  /**
   * Max rows listed per category - enough to spot a pattern without dumping a whole batch.
   */
  private const LIST_LIMIT = 50;

  private string $batchName;

  private string $gatewayPrefix;

  /**
   * Part of the batch prefix after the gateway (see AuditMessage) - '' for most gateways.
   */
  private string $accountString = '';

  private ?BaseAuditProcessor $processor;

  /**
   * Parsed file messages, keyed by file path.
   */
  private array $parsed = [];

  public function __construct(array $batch, string $incomingDirectory = '') {
    $this->batchName = $batch['name'];
    $this->gatewayPrefix = explode('_', $batch['name'])[0];
    $this->processor = $this->loadProcessor($incomingDirectory);
  }

  /**
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function compare(): array {
    if (!$this->processor) {
      return ['is_supported' => FALSE, 'summary' => ['No audit processor for ' . $this->gatewayPrefix . ' - file comparison not available.']];
    }
    $files = $this->processor->getFilesForSettlementBatch($this->batchName);
    if (!$files) {
      return ['is_supported' => FALSE, 'summary' => ['File lookup is not yet implemented for ' . $this->gatewayPrefix . ' (override getFilesForSettlementBatch() in its audit processor).']];
    }
    if (empty($files['primary'])) {
      // Validation's explanation already says no file was found.
      return ['is_supported' => TRUE, 'summary' => []];
    }
    $fileRows = [];
    foreach ($files['primary'] as $file) {
      foreach ($this->getBatchRowsFromFile($file, $this->batchName) as $row) {
        $fileRows[] = $row + ['file' => basename($file)];
      }
    }
    $civiLines = $this->getCiviLines();
    [$matched, $civiOnly, $fileOnly] = $this->match($fileRows, $civiLines);

    $mismatched = [];
    foreach ($matched as [$fileRow, $civiLine]) {
      if (abs($fileRow['gross'] - $civiLine['gross']) >= 0.005 || abs($fileRow['fee'] - $civiLine['fee']) >= 0.005) {
        $mismatched[] = ['file' => $fileRow, 'civicrm' => $civiLine];
      }
    }
    foreach ($civiOnly as &$civiLine) {
      $civiLine['found_in'] = $this->findInOtherFiles($civiLine['keys'], $files['other']);
    }
    unset($civiLine);
    foreach ($fileOnly as &$fileRow) {
      $fileRow['civicrm'] = $this->findContributions($fileRow['keys']);
    }
    unset($fileRow);

    return [
      'is_supported' => TRUE,
      'files' => array_map('basename', $files['primary']),
      'other_files' => array_map('basename', $files['other']),
      'file_row_count' => count($fileRows),
      'civicrm_row_count' => count($civiLines),
      'explained' => $this->getExplained($civiOnly, $fileOnly, $mismatched),
      'civicrm_only' => array_slice($civiOnly, 0, self::LIST_LIMIT),
      'file_only' => array_slice($fileOnly, 0, self::LIST_LIMIT),
      'amount_mismatch' => array_slice($mismatched, 0, self::LIST_LIMIT),
      'summary' => $this->summarise($civiOnly, $fileOnly, $mismatched),
    ];
  }

  private function loadProcessor(string $incomingDirectory): ?BaseAuditProcessor {
    $gateway = GatewayAccount::get(FALSE)
      ->addWhere('name', '=', $this->gatewayPrefix)
      ->addSelect('gateway')
      ->execute()->first()['gateway'] ?? $this->gatewayPrefix;
    $this->accountString = str_starts_with($this->gatewayPrefix, $gateway) ? substr($this->gatewayPrefix, strlen($gateway)) : '';
    $class = '\Civi\WMFAudit\\' . ucfirst($gateway) . 'AuditProcessor';
    if (!class_exists($class)) {
      return NULL;
    }
    \CRM_SmashPig_ContextWrapper::createContext($gateway . '_audit', $gateway);
    return new $class(['file_limit' => NULL, 'incoming_directory' => $incomingDirectory]);
  }

  /**
   * Normalised rows from one file for one batch (payout/aggregate rows dropped).
   */
  private function getBatchRowsFromFile(string $file, ?string $batchName): array {
    $this->parsed[$file] ??= $this->processor->parseFileForDiagnosis($file);
    $rows = [];
    foreach ($this->parsed[$file] as $message) {
      if (($message['type'] ?? '') === 'payout' || empty($message['settlement_batch_reference'])) {
        continue;
      }
      $rowBatch = ($message['audit_file_gateway'] ?? '') . $this->accountString . '_' . $message['settlement_batch_reference'] . '_' . $message['settled_currency'];
      if ($batchName && $rowBatch !== $batchName) {
        continue;
      }
      $isReversal = (float) ($message['settled_total_amount'] ?? 0) < 0;
      $keys = $isReversal
        ? [$message['gateway_refund_id'] ?? NULL, $message['backend_processor_reversal_id'] ?? NULL, $message['gateway_parent_id'] ?? NULL, $message['backend_processor_parent_id'] ?? NULL]
        : [$message['gateway_txn_id'] ?? NULL, $message['capture_id'] ?? NULL];
      $rows[] = [
        'kind' => $isReversal ? 'reversal' : 'donation',
        'type' => $message['type'] ?? 'donation',
        'batch' => $rowBatch,
        'gateway' => $message['gateway'] ?? '',
        'gateway_txn_id' => $message['gateway_txn_id'] ?? '',
        'keys' => array_values(array_unique(array_filter($keys))),
        'gross' => round((float) ($message['settled_total_amount'] ?? 0), 2),
        'fee' => round((float) ($message['settled_fee_amount'] ?? 0), 2),
      ];
    }
    return $rows;
  }

  /**
   * CiviCRM settlement lines for the batch - one per contribution side (donation and/or reversal) journalled to it.
   *
   * @throws \CRM_Core_Exception
   */
  private function getCiviLines(): array {
    $contributions = Contribution::get(FALSE)
      ->addSelect('id', 'trxn_id', 'contribution_status_id:name', 'contribution_settlement.*', 'contribution_extra.gateway_txn_id', 'contribution_extra.capture_id', 'contribution_extra.backend_processor_txn_id', 'contribution_extra.backend_processor_reversal_id')
      ->addClause('OR', ['contribution_settlement.settlement_batch_reference', '=', $this->batchName], ['contribution_settlement.settlement_batch_reversal_reference', '=', $this->batchName])
      ->addWhere('is_template', '=', FALSE)
      ->execute();
    $lines = [];
    foreach ($contributions as $contribution) {
      $base = [
        'contribution_id' => $contribution['id'],
        'trxn_id' => $contribution['trxn_id'],
        'status' => $contribution['contribution_status_id:name'],
      ];
      $txnKeys = [$contribution['contribution_extra.gateway_txn_id'], $contribution['contribution_extra.capture_id'], $contribution['contribution_extra.backend_processor_txn_id']];
      if ($contribution['contribution_settlement.settlement_batch_reference'] === $this->batchName) {
        $lines[] = $base + [
          'kind' => 'donation',
          'keys' => array_values(array_unique(array_filter($txnKeys))),
          'gross' => round((float) $contribution['contribution_settlement.settled_donation_amount'], 2),
          'fee' => round((float) $contribution['contribution_settlement.settled_fee_amount'], 2),
        ];
      }
      if ($contribution['contribution_settlement.settlement_batch_reversal_reference'] === $this->batchName) {
        $lines[] = $base + [
          'kind' => 'reversal',
          'keys' => array_values(array_unique(array_filter(array_merge([$contribution['contribution_extra.backend_processor_reversal_id']], $txnKeys)))),
          'gross' => round((float) $contribution['contribution_settlement.settled_reversal_amount'], 2),
          'fee' => round((float) $contribution['contribution_settlement.settled_fee_reversal_amount'], 2),
        ];
      }
    }
    return $lines;
  }

  /**
   * @return array [matched pairs, civi-only lines, file-only rows]
   */
  private function match(array $fileRows, array $civiLines): array {
    $index = [];
    foreach ($civiLines as $lineIndex => $line) {
      foreach ($line['keys'] as $key) {
        $index[$line['kind']][$key][] = $lineIndex;
      }
    }
    $matched = $fileOnly = $used = [];
    foreach ($fileRows as $fileRow) {
      $found = NULL;
      foreach ($fileRow['keys'] as $key) {
        foreach ($index[$fileRow['kind']][$key] ?? [] as $lineIndex) {
          if (!isset($used[$lineIndex])) {
            $found = $lineIndex;
            break 2;
          }
        }
      }
      if ($found === NULL) {
        $fileOnly[] = $fileRow;
        continue;
      }
      $used[$found] = TRUE;
      $matched[] = [$fileRow, $civiLines[$found]];
    }
    $civiOnly = [];
    foreach ($civiLines as $lineIndex => $line) {
      if (!isset($used[$lineIndex])) {
        $civiOnly[] = $line;
      }
    }
    return [$matched, $civiOnly, $fileOnly];
  }

  /**
   * Look for a CiviCRM-only transaction in the processor's 'other' files (e.g. neighbouring days, overlapping file types).
   */
  private function findInOtherFiles(array $keys, array $otherFiles): array {
    $found = [];
    foreach ($otherFiles as $file) {
      foreach ($this->getBatchRowsFromFile($file, NULL) as $row) {
        if (array_intersect($keys, $row['keys'])) {
          $found[] = ['file' => basename($file), 'batch' => $row['batch'], 'gross' => $row['gross'], 'fee' => $row['fee']];
        }
      }
    }
    return $found;
  }

  /**
   * Where a file-only transaction is in CiviCRM, if anywhere.
   */
  private function findContributions(array $keys): array {
    if (!$keys) {
      return [];
    }
    return (array) Contribution::get(FALSE)
      ->addSelect('id', 'trxn_id', 'contribution_status_id:name', 'contribution_settlement.settlement_batch_reference', 'contribution_settlement.settlement_batch_reversal_reference')
      ->addClause('OR',
        ['contribution_extra.gateway_txn_id', 'IN', $keys],
        ['contribution_extra.capture_id', 'IN', $keys],
        ['contribution_extra.backend_processor_reversal_id', 'IN', $keys]
      )
      ->setLimit(5)
      ->execute();
  }

  /**
   * How much of the discrepancy the per-transaction differences account for.
   *
   * Same shape & signs as BatchDiscrepancyExplainer::getCiviMinusExpected() so the two can be compared.
   */
  private function getExplained(array $civiOnly, array $fileOnly, array $mismatched): array {
    $explained = ['count' => count($civiOnly) - count($fileOnly), 'gross' => 0.0, 'refunds' => 0.0, 'fee' => 0.0];
    $add = function (array $row, int $sign) use (&$explained) {
      $explained[$row['kind'] === 'reversal' ? 'refunds' : 'gross'] += $sign * ($row['kind'] === 'reversal' ? -$row['gross'] : $row['gross']);
      $explained['fee'] -= $sign * $row['fee'];
    };
    foreach ($civiOnly as $line) {
      $add($line, 1);
    }
    foreach ($fileOnly as $row) {
      $add($row, -1);
    }
    foreach ($mismatched as $pair) {
      $add($pair['civicrm'], 1);
      $add($pair['file'], -1);
    }
    foreach (['gross', 'refunds', 'fee'] as $key) {
      $explained[$key] = round($explained[$key], 2);
    }
    $explained['net'] = round($explained['gross'] - $explained['refunds'] - $explained['fee'], 2);
    return $explained;
  }

  private function summarise(array $civiOnly, array $fileOnly, array $mismatched): array {
    $lines = [];
    foreach (array_slice($civiOnly, 0, 10) as $line) {
      $text = sprintf('In CiviCRM but not the file: contribution %d (%s) %s gross %s fee %s.', $line['contribution_id'], $line['trxn_id'], $line['kind'], $line['gross'], $line['fee']);
      foreach ($line['found_in'] as $found) {
        $text .= sprintf(' Found in %s for batch %s%s.', $found['file'], $found['batch'], $found['batch'] === $this->batchName ? '' : ' - its settlement batch reference looks wrong');
      }
      if (!$line['found_in']) {
        $text .= ' Not in the neighbouring days\' files either - check for a duplicate or a hand-edited settlement.';
      }
      $lines[] = $text;
    }
    foreach (array_slice($fileOnly, 0, 10) as $row) {
      $text = sprintf('In %s but not settled to this batch in CiviCRM: %s %s %s gross %s fee %s.', $row['file'], $row['gateway'], $row['type'], $row['gateway_txn_id'], $row['gross'], $row['fee']);
      if (!$row['civicrm']) {
        $text .= ' No matching contribution - it is still missing (re-run the audit, or check the pending/missing queues).';
      }
      foreach ($row['civicrm'] as $contribution) {
        $text .= sprintf(' Contribution %d is settled to %s.', $contribution['id'], ($row['kind'] === 'reversal' ? $contribution['contribution_settlement.settlement_batch_reversal_reference'] : $contribution['contribution_settlement.settlement_batch_reference']) ?: 'no batch');
      }
      $lines[] = $text;
    }
    foreach (array_slice($mismatched, 0, 10) as $pair) {
      $lines[] = sprintf('Amounts differ for contribution %d (%s): file gross %s fee %s vs CiviCRM gross %s fee %s.', $pair['civicrm']['contribution_id'], $pair['civicrm']['trxn_id'], $pair['file']['gross'], $pair['file']['fee'], $pair['civicrm']['gross'], $pair['civicrm']['fee']);
    }
    if (max(count($civiOnly), count($fileOnly), count($mismatched)) > 10) {
      $lines[] = 'More differences than shown - see civicrm_only / file_only / amount_mismatch.';
    }
    if (!$lines) {
      $lines[] = 'Every file row matched a CiviCRM row with the same amounts - the discrepancy is not per-transaction (check fee-only/adjustment rows and the batch\'s saved expected totals against the file).';
    }
    return $lines;
  }

}
