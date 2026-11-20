<?php

namespace Civi\Api4\Action\Batch;

use Brick\Money\Money;
use Civi\Api4\Batch;
use Civi\Api4\BatchFinancialBreakdown;
use Civi\Api4\CustomValue;
use Civi\Api4\GatewayAccount;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFBatch\FinanceConstants;
use Civi\WMFBatch\JournalGenerator;

/**
 * Write the journals that correct one contribution's GL coding.
 *
 * The batch comes from WMFAudit.ReserveAdjustmentBatches, which pairs it
 * with the FinancialDataAdjustment row describing what changed. The rule is
 * the same whatever changed: reverse the old line on the entity it was
 * booked to, carrying its original dimensions, and book the new line on the
 * entity it belongs to with that entity's dimensions.
 *
 * Which entity holds the revenue follows the contribution's endowment flag,
 * not the gateway - the gateway decides where the cash landed, and hence
 * which way a transfer runs, but an endowment gift's revenue ends up on the
 * endowment instance either way (see JournalWriter).
 *
 * So a correction that leaves the entity alone is one journal of two lines,
 * with no contra: the cash never moved, only its coding. A correction that
 * changes entity is two journals - precisely the cross-instance transfer
 * that would have run at settlement had the contribution been coded this
 * way to begin with - with the inter-entity accounts between them.
 *
 * @method $this setName(string $name)
 */
class GenerateAdjustmentJournals extends AbstractAction {

  /**
   * The adjustment Batch.name to generate journals for.
   *
   * @required
   *
   * @var string
   */
  protected string $name;

  /**
   * @param Result $result
   *   One record: 'csv' in the shape Batch.pushToFinance takes, and the
   *   'rows' that were written.
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    $batch = Batch::get(FALSE)
      ->addWhere('name', '=', $this->name)
      ->addWhere('mode_id:name', '=', 'Adjustment Batch')
      ->addSelect('*', 'batch_data.*')
      ->execute()->first();
    if (!$batch) {
      throw new \CRM_Core_Exception('No adjustment batch named ' . $this->name);
    }
    $adjustment = CustomValue::get('FinancialDataAdjustment', FALSE)
      ->addWhere('adjustment_batch_reference', '=', $this->name)
      ->execute()->first();
    if (!$adjustment) {
      throw new \CRM_Core_Exception('No adjustment is reserved against ' . $this->name);
    }
    // Copied from Gift_Data.gl_code, whose GL prefix the journals don't use.
    foreach (['gl_code_before', 'gl_code_after'] as $field) {
      $adjustment[$field] = substr($adjustment[$field], 2);
    }

    $journals = $this->getJournals($batch, $adjustment);
    $csvFiles = [];
    foreach ($journals as $journal) {
      $csvFiles[$journal['remote_descriptor']] = [
        'file' => $this->write($journal['rows'], $journal['remote_descriptor']),
        'is_journal' => TRUE,
        'instance' => $journal['instance'],
        'suffix' => $journal['suffix'],
        'remote_descriptor' => $journal['remote_descriptor'],
      ];
    }
    $this->recordFinancialBreakdown($batch, $adjustment);
    $result[] = ['csv' => $csvFiles, 'rows' => $journals];
  }

  /**
   * Record the move as BatchFinancialBreakdown rows - the old GL code & fund
   * reversed, the new one booked - so the original batch's rows plus these
   * give the current split.
   *
   * @throws \CRM_Core_Exception
   */
  private function recordFinancialBreakdown(array $batch, array $adjustment): void {
    $amount = Money::of((string) $batch['batch_data.settled_donation_amount'], $batch['batch_data.settlement_currency']);
    BatchFinancialBreakdown::delete(FALSE)->addWhere('batch_id', '=', $batch['id'])->execute();
    BatchFinancialBreakdown::save(FALSE)
      ->setRecords([
        ['gl_code' => (string) $adjustment['gl_code_before'], 'is_endowment' => !empty($adjustment['is_endowment_before']), 'amount' => (string) $amount->negated()->getAmount(), 'item_count' => 1],
        ['gl_code' => (string) $adjustment['gl_code_after'], 'is_endowment' => !empty($adjustment['is_endowment_after']), 'amount' => (string) $amount->getAmount(), 'item_count' => 1],
      ])
      ->setDefaults(['batch_id' => $batch['id']])
      ->execute();
  }

  /**
   * The journals this correction needs, each {instance, suffix,
   * remote_descriptor, rows}.
   *
   * @throws \CRM_Core_Exception
   */
  private function getJournals(array $batch, array $adjustment): array {
    $fromIsEndowment = !empty($adjustment['is_endowment_before']);
    $toIsEndowment = !empty($adjustment['is_endowment_after']);
    $fromAccount = (string) $adjustment['gl_code_before'];
    $toAccount = (string) $adjustment['gl_code_after'];
    // Through Money so both sides of a row are written to the same scale,
    // and to the currency's own - JPY has no minor unit, so a hardcoded
    // two-decimal zero would be wrong there.
    $currency = $batch['batch_data.settlement_currency'];
    $amount = (string) Money::of((string) $batch['batch_data.settled_donation_amount'], $currency)->getAmount();
    $zero = (string) Money::zero($currency)->getAmount();

    if ($fromIsEndowment === $toIsEndowment) {
      // Same entity - reverse the old coding and book the new, nothing else.
      // No contra line: the cash is where it always was.
      return [[
        'instance' => $fromIsEndowment ? 'endowment' : 'foundation',
        'suffix' => '',
        'remote_descriptor' => $fromIsEndowment ? 'endowment_instance' : 'foundation',
        'rows' => [
          $this->row($batch, $adjustment, $fromAccount, $amount, $zero, $fromIsEndowment),
          $this->row($batch, $adjustment, $toAccount, $zero, $amount, $toIsEndowment),
        ],
      ]];
    }

    // Different entities - the transfer that settlement would have made.
    // In endowment journals all major gifts are treated as restricted; the
    // settlement code does not do the reverse remap on the way back, so
    // neither do we (see JournalWriter::writeReplacementJournal()).
    if ($toIsEndowment && in_array((int) $toAccount, FinanceConstants::GL_MAJOR_GIFTS_ACCOUNTS, TRUE)) {
      $toAccount = (string) FinanceConstants::GL_MAJOR_GIFTS_RESTRICTED;
    }
    return [
      [
        'instance' => $fromIsEndowment ? 'endowment' : 'foundation',
        'suffix' => $toIsEndowment ? 'e' : 'f',
        'remote_descriptor' => $toIsEndowment ? 'to_endowment' : 'to_foundation',
        'rows' => [
          $this->row($batch, $adjustment, $fromAccount, $amount, $zero, $fromIsEndowment),
          $this->row($batch, $adjustment, $this->balancingAccount($fromIsEndowment), $zero, $amount, $fromIsEndowment),
        ],
      ],
      [
        'instance' => $toIsEndowment ? 'endowment' : 'foundation',
        'suffix' => $toIsEndowment ? 'e' : 'f',
        'remote_descriptor' => $toIsEndowment ? 'endowment_instance' : 'foundation',
        'rows' => [
          $this->row($batch, $adjustment, $toAccount, $zero, $amount, $toIsEndowment),
          $this->row($batch, $adjustment, $this->balancingAccount($toIsEndowment), $amount, $zero, $toIsEndowment),
        ],
      ],
    ];
  }

  /**
   * The inter-entity account for one side of a transfer. Both constants for
   * each instance hold the same value whichever way the money is going, so
   * only the side matters.
   */
  private function balancingAccount(bool $isEndowment): string {
    return (string) ($isEndowment
      ? FinanceConstants::GL_BALANCING_ACCOUNT_ENDOWMENT_INSTANCE
      : FinanceConstants::GL_BALANCING_ACCOUNT_WMF_INSTANCE);
  }

  /**
   * One journal line. Columns not set here are written blank - see
   * JournalGenerator::createJournalCsvWriter().
   *
   * @throws \CRM_Core_Exception
   */
  private function row(array $batch, array $adjustment, string $account, string $debit, string $credit, bool $isEndowmentSide): array {
    $date = date('m/d/Y', strtotime($batch['batch_data.settlement_date']));
    return [
      'JOURNAL' => 'CREV',
      'DATE' => $date,
      'ACCT_NO' => $account,
      'LOCATION_ID' => $isEndowmentSide ? FinanceConstants::LOCATION_ID_ENDOWMENT : FinanceConstants::LOCATION_ID_WMF,
      // Same rule as getDeptIDClause: no cost centre on endowment journals,
      // and otherwise it follows the account this line is coded to - so the
      // reversal keeps the old code's centre and the new line takes the new
      // code's.
      'DEPT_ID' => $isEndowmentSide ? '' : (in_array((int) $account, FinanceConstants::GL_MAJOR_GIFTS_ACCOUNTS, TRUE) ? 'CC-1004' : 'CC-1005'),
      'DOCUMENT' => $batch['name'],
      'DEBIT' => $debit,
      'CREDIT' => $credit,
      'CURRENCY' => $batch['batch_data.settlement_currency'],
      'EXCH_RATE_DATE' => $date,
      'STATE' => 'Draft',
      // Mirrors what the settlement journal's restrictions clause produces.
      'GLDIMFUNDING' => 'Unrestricted',
      'GLENTRY_VENDORID' => $this->getVendorCode($batch, $isEndowmentSide),
      'DESCRIPTION' => 'GL correction ' . $batch['name'],
      'MEMO' => $this->getMemo($batch, $adjustment, $date),
    ];
  }

  private function getMemo(array $batch, array $adjustment, string $date): string {
    $changes = [];
    if ($adjustment['gl_code_before'] !== $adjustment['gl_code_after']) {
      $changes[] = 'GL ' . $adjustment['gl_code_before'] . ' to ' . $adjustment['gl_code_after'];
    }
    if (!empty($adjustment['is_endowment_before']) !== !empty($adjustment['is_endowment_after'])) {
      $changes[] = empty($adjustment['is_endowment_after']) ? 'endowment to foundation' : 'foundation to endowment';
    }
    return $batch['batch_data.settlement_gateway'] . ' | ' . $batch['batch_data.settlement_currency']
      . ' | ' . $date . ' | 1 | Correction: ' . implode(', ', $changes);
  }

  /**
   * The gateway's vendor for whichever entity this line is on. Carried from
   * the batch the correction came from, not from the adjustment batch's own
   * name - "adjustment" is not a gateway.
   *
   * @throws \CRM_Core_Exception
   */
  private function getVendorCode(array $batch, bool $isEndowmentSide): string {
    $account = GatewayAccount::get(FALSE)
      ->addWhere('name', '=', $batch['batch_data.settlement_gateway'])
      ->addSelect('vendor_code_foundation', 'vendor_code_endowment')
      ->execute()->first();
    // Vendor code is optional - e.g. wire & ach have no vendor in Intacct.
    return (string) ($isEndowmentSide ? ($account['vendor_code_endowment'] ?? '') : ($account['vendor_code_foundation'] ?? ''));
  }

  /**
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   */
  private function write(array $rows, string $descriptor): string {
    $path = \Civi::settings()->get('wmf_audit_intact_files') . '/' . $this->name . '_' . $descriptor . '.csv';
    $writer = JournalGenerator::createJournalCsvWriter($path);
    $lineNumber = 1;
    foreach ($rows as $row) {
      $row['LINE_NO'] = $lineNumber;
      $lineNumber += 2;
      $writer->insertOne($row);
    }
    return $path;
  }

  /**
   * @return array
   */
  public function getPermissions(): array {
    return ['administer CiviCRM system'];
  }

}
