<?php

namespace Civi\Import\DataSource;

use PHPUnit\Framework\TestCase;

/**
 * @group FinanceSpreadsheet
 */
class FinanceSpreadsheetTest extends TestCase {

  private const EXPORT_HEADERS = ['GL', 'Posted dt.', 'Doc dt.', 'Doc', 'Memo/Description', 'Department', 'Region', 'Project', 'Vendor', 'Vendor name', 'Entity', 'Event ID', 'Customer name', 'Txn No', 'JNL', 'Curr', 'Txn amt', 'Amount $'];

  private const CITIBANK_HEADERS = ['Posted Dt.', 'Doc Dt.', 'Doc', 'Memo/Description', 'Department', 'Funding', 'Txn No', 'Jnl', 'Curr', 'Txn Amt', 'Debit', 'Credit', 'Balance'];

  private const CALCULATED_HEADERS = ['Batch Reference', 'Settlement Gateway', 'Settlement Gateway Account', 'Settlement Date', 'Batch Title', 'Total', 'Currency', 'Item Count', 'Batch Status', 'Total Verified Date'];

  // Those not set until the gateway account is looked up, or that vary, are left out.
  private const CHECKED_HEADERS = ['Settlement Gateway', 'Settlement Date', 'Batch Title', 'Total', 'Currency', 'Item Count', 'Batch Status'];

  /**
   * Donation rows get the calculated values, with the gateway from the memo.
   */
  public function testExportRows(): void {
    $sheetRows = [
      [...self::EXPORT_HEADERS, '', ''],
      ['43427', '11/20/2026', '11/16/2026', '', 'ACH-ABCPAY1116260 2026-11-16 0 11/16/2026', 'CC-1005', '', '', '', '', '100-WMF', '', '', '9093', 'BR', 'USD', '1,500', '1500'],
      ['43427', '11/20/2026', '11/16/2026', '', 'WIRE FROM ANNA MOUSE 111626 11/16/2026', 'CC-1005', '', '', '', '', '100-WMF', '', '', '9093', 'BR', 'USD', '68.59', '68.59'],
      ['43427', '11/20/2026', '11/19/2026', '', 'Donation Check #5001 11/19/2026', 'CC-1005', '', '', '', '', '100-WMF', '', '', '9094', 'BR', 'USD', '5', '5'],
      ['43427', '11/20/2026', '11/19/2026', '', 'Donation Check 5 Checks 11/18/2026', 'CC-1005', '', '', '', '', '100-WMF', '', '', '9094', 'BR', 'USD', '10.50', '10.50'],
      ['43431', '11/20/2026', '11/16/2026', '', 'Engage Deposited Check Return #1234 Uncollected 11/16/2026', 'CC-1005', '', '', '', '', '100-WMF', '', '', '9093', 'BR', 'USD', '200', '-200'],
      ['43427', '10/30/2026', '10/30/2026', '', 'CCK txfr to Citibank Wire Donations #1234 10/28/2026', '', '', '', 'V04824', 'Chisholm Chisholm & Kilpatrick', '100-WMF', '', '', '9070', 'BR', 'USD', '2,000.25', '2000.25'],
      ['43427', '10/30/2026', '10/30/2026', '', 'Oct 2026 - Stock Donations - 10 Shares of ACME CORP - 10/27/26', 'CC-1005', '', '', '', '', '100-WMF', '', '', '9082', 'BR', 'USD', '1,000.50', '1000.50'],
      ['43427', '11/20/2026', '11/16/2026', '', 'Bank fee 11/16/2026', 'CC-1005', '', '', '', '', '100-WMF', '', '', '9093', 'BR', 'USD', '15', '-15'],
      ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ];
    $rows = $this->invoke('extractDataRows', $sheetRows);
    $this->assertSame(self::CALCULATED_HEADERS, array_keys($rows[0]));
    $this->assertMatchesRegularExpression('#^\d\d/\d\d/\d{4} \d\d:\d\d:\d\d$#', $rows[0]['Total Verified Date']);
    $this->assertSame([
      ['ach', '11/16/2026', 'ACH-ABCPAY1116260 2026-11-16 0 11/16/2026', '1500', 'USD', '1', 'total_verified'],
      ['wire', '11/16/2026', 'WIRE FROM ANNA MOUSE 111626 11/16/2026', '68.59', 'USD', '1', 'total_verified'],
      ['bankcheck', '11/19/2026', '5001-20261119', '5', 'USD', '1', 'total_verified'],
      ['bankcheck', '11/19/2026', '20261118', '10.50', 'USD', '5', 'total_verified'],
      ['engage', '11/16/2026', 'Engage Deposited Check Return #1234 Uncollected 11/16/2026', '-200', 'USD', '1', 'total_verified'],
      ['cck', '10/30/2026', 'CCK txfr to Citibank Wire Donations #1234 10/28/2026', '2000.25', 'USD', '1', 'total_verified'],
      ['boa', '10/30/2026', 'Oct 2026 - Stock Donations - 10 Shares of ACME CORP - 10/27/26', '1000.50', 'USD', '1', 'total_verified'],
    ], $this->getCheckedValues($rows));
  }

  /**
   * Citibank export donation rows become citibank-public2 rows in their own currency.
   */
  public function testCitibankRows(): void {
    $sheetRows = [
      self::CITIBANK_HEADERS,
      ['10/30/26', '10/2/26', '1000000001', 'Citibank - EUR Public #2 Donation Received from 1/ANNA MOUSE 10/02/2026', '', 'Unrestricted', '9066', 'BR', 'EUR', '5', '5.64', '', '5.64'],
      ['10/30/26', '10/7/26', '1000000004', 'Citibank - GBP Public #2 Donation Received from DONALD DUCK 10/07/2026', '', 'Unrestricted', '9066', 'BR', 'GBP', '26.31', '35.08', '', '68.38'],
      ['10/30/26', '10/9/26', '1000000006', 'Bank charges', '', 'Unrestricted', '9066', 'BR', 'EUR', '-1', '-1.12', '', '67.26'],
    ];
    $this->assertSame([
      ['citibank-public2', '10/02/2026', 'Citibank - EUR Public #2 Donation Received from 1/ANNA MOUSE 10/02/2026', '5', 'EUR', '1', 'total_verified'],
      ['citibank-public2', '10/07/2026', 'Citibank - GBP Public #2 Donation Received from DONALD DUCK 10/07/2026', '26.31', 'GBP', '1', 'total_verified'],
    ], $this->getCheckedValues($this->invoke('extractDataRows', $sheetRows)));
  }

  /**
   * A sheet with no header row is skipped.
   */
  public function testNoHeaderRow(): void {
    $this->assertNull($this->invoke('extractDataRows', [['DATE', 'TRANSACTION TYPE', 'DESCRIPTION', 'AMOUNT (USD)', 'BALANCE (USD)']]));
  }

  /**
   * A header row missing a column we need fails, naming the column.
   */
  public function testMissingColumn(): void {
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessage('The spreadsheet is missing the column(s): Curr.');
    $this->invoke('extractDataRows', [['Doc dt.', 'Memo/Description', 'Txn amt']]);
  }

  /**
   * The amount is Amount $ if present, else Txn amt.
   */
  public function testAmount(): void {
    $columns = ['txn amt' => 0, 'amount $' => 1];
    $this->assertSame('-1500', $this->invoke('getAmount', ['1,500', '-1,500'], $columns));
    $this->assertSame('1500', $this->invoke('getAmount', ['1,500', ''], $columns));
    $this->assertSame('1500', $this->invoke('getAmount', ['1,500'], ['txn amt' => 0]));
  }

  /**
   * Excel serial dates are converted.
   */
  public function testExcelDate(): void {
    $this->assertSame('11/16/2026', $this->invoke('formatDate', '46342'));
  }

  private function getCheckedValues(array $rows): array {
    $values = [];
    foreach ($rows as $row) {
      $rowValues = [];
      foreach (self::CHECKED_HEADERS as $header) {
        $rowValues[] = $row[$header];
      }
      $values[] = $rowValues;
    }
    return $values;
  }

  private function invoke(string $methodName, ...$args) {
    $method = new \ReflectionMethod(FinanceSpreadsheet::class, $methodName);
    $method->setAccessible(TRUE);
    return $method->invoke(new FinanceSpreadsheet(), ...$args);
  }

}
