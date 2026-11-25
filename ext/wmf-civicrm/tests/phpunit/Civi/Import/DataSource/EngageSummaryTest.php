<?php

namespace Civi\Import\DataSource;

use PHPUnit\Framework\TestCase;

/**
 * @group EngageSummary
 */
class EngageSummaryTest extends TestCase {

  /**
   * A block's own type/group label (e.g. "WF") sits alone on a line right
   * before that block's timestamp + header lines, and the same raw "Batch"
   * numbers can recur in an unlabelled block elsewhere in the report. The
   * label must be prefixed onto the Batch id for its own block's rows only,
   * so a WF batch doesn't collide with an unrelated (unlabelled) batch that
   * happens to share the same raw number.
   */
  public function testWfBlockGetsBatchIdPrefixedWithoutLeakingToOtherBlocks(): void {
    $sheetRows = [
      $this->row(['A' => 'WF']),
      $this->row(['A' => '10/8/2026 12:00:30 PM']),
      $this->header(),
      $this->row(['A' => 'Clean', 'C' => '300110', 'E' => '10/08/2026', 'G' => '97', 'I' => '97', 'K' => '194', 'L' => '9539.00']),
      $this->row(['A' => 'Check Only', 'C' => '800107', 'E' => '10/08/2026', 'G' => '31', 'I' => '31', 'K' => '62', 'L' => '3447.00']),
      $this->row(['C' => 'Total', 'G' => '128', 'I' => '128', 'K' => '256', 'L' => '12986.00']),
      // A second, unlabelled block re-using batch number 300110.
      $this->row(['A' => '10/9/2026 9:00:00 AM']),
      $this->header(),
      $this->row(['A' => 'Clean', 'C' => '300110', 'E' => '10/09/2026', 'G' => '5', 'I' => '5', 'K' => '10', 'L' => '500.00']),
      $this->row(['C' => 'Total', 'G' => '5', 'I' => '5', 'K' => '10', 'L' => '500.00']),
    ];

    [, $dataRows] = $this->extractDataRows($sheetRows);
    $batchIds = array_column($dataRows, 2);

    $this->assertSame(['WF300110', 'WF800107', '300110'], $batchIds);
  }

  /**
   * A bare mid-block label (e.g. "DAF") doesn't match a known Batch Type
   * needing a prefix, so it's just skipped - it must not reset or overwrite
   * the running WF/ED prefix for the rows that follow it in the same block.
   */
  public function testBareNonMatchingLabelDoesNotDisruptRunningPrefix(): void {
    $sheetRows = [
      $this->row(['A' => 'ED']),
      $this->row(['A' => '10/8/2026 12:00:30 PM']),
      $this->header(),
      $this->row(['A' => 'Clean', 'C' => '450072', 'E' => '10/08/2026', 'G' => '3', 'I' => '3', 'K' => '6', 'L' => '325.00']),
      $this->row(['A' => 'DAF']),
      $this->row(['A' => 'Flag', 'C' => '480017', 'E' => '10/08/2026', 'G' => '3', 'I' => '3', 'K' => '6', 'L' => '180.00']),
      $this->row(['C' => 'Total', 'G' => '6', 'I' => '6', 'K' => '12', 'L' => '505.00']),
    ];

    [, $dataRows] = $this->extractDataRows($sheetRows);
    $batchIds = array_column($dataRows, 2);

    $this->assertSame(['ED450072', 'ED480017'], $batchIds);
  }

  /**
   * A "CREDIT CARDS - WF" label prefixes its block's batch ids with WF.
   */
  public function testCardBlockLabelPrefixesBatchId(): void {
    $sheetRows = [
      $this->row(['A' => 'CREDIT CARDS - WF']),
      $this->row(['A' => '10/8/2026 12:00:30 PM']),
      $this->header(),
      $this->row(['A' => 'Credit Cards', 'C' => '900012', 'E' => '10/08/2026', 'G' => '4', 'I' => '0', 'K' => '4', 'L' => '400.00']),
      $this->row(['A' => 'NS Credit Cards', 'C' => '910003', 'E' => '10/08/2026', 'G' => '2', 'I' => '0', 'K' => '2', 'L' => '50.00']),
      $this->row(['C' => 'Total', 'G' => '6', 'I' => '0', 'K' => '6', 'L' => '450.00']),
    ];

    [, $dataRows] = $this->extractDataRows($sheetRows);

    $this->assertSame(['WF900012', 'WF910003'], array_column($dataRows, 2));
  }

  /**
   * Card rows get Docs as their Checks count so they aren't skipped.
   */
  public function testCardRowsUseDocsAsChecks(): void {
    $headers = ['Batch Type', 'Group Value', 'Batch', 'Batch Date', 'Docs', 'Checks', 'Total Item', 'Amount'];
    $rows = [
      ['Credit Cards', '', 'WF900012', '10/08/2026', '4', '0', '4', '400.00'],
      ['NS Credit Cards', '', 'WF910003', '10/08/2026', '2', '0', '2', '50.00'],
      ['Clean', '', '300110', '10/08/2026', '97', '97', '194', '9539.00'],
      ['Flag', '', '300111', '10/08/2026', '3', '0', '3', '10.00'],
    ];

    $rows = $this->invoke('useDocsAsChecksForCardRows', $headers, $rows);
    $rows = $this->invoke('skipRowsWithoutChecks', $headers, $rows);

    $this->assertSame(['WF900012', 'WF910003', '300110'], array_column($rows, 2));
    $this->assertSame(['4', '2', '97'], array_column($rows, 5));
  }

  /**
   * Without a Currency column International rows get UNKNOWN (so they fail
   * to import) and the rest default to USD.
   */
  public function testInternationalRowsHaveNoCurrencyWithoutCurrencyColumn(): void {
    $headers = ['Batch Type', 'Batch', 'Amount'];
    $rows = [['Clean', '300110', '10.00'], ['international', '700001', '20.00']];

    [$headers, $rows] = $this->invoke('appendSettlementCurrencyColumn', $headers, $rows);

    $this->assertSame(['Batch Type', 'Batch', 'Amount', 'Settlement Currency'], $headers);
    $this->assertSame(['USD', 'UNKNOWN'], array_column($rows, 3));
  }

  /**
   * An added Currency column is moved to the end as Settlement Currency.
   */
  public function testCurrencyColumnIsMovedToSettlementCurrency(): void {
    $headers = ['batch type', 'CURRENCY', 'Batch', 'Amount'];
    $rows = [['Clean', '', '300110', '10.00'], ['INTERNATIONAL', 'eur', '700001', '20.00']];

    [$headers, $rows] = $this->invoke('appendSettlementCurrencyColumn', $headers, $rows);

    $this->assertSame(['batch type', 'Batch', 'Amount', 'Settlement Currency'], $headers);
    $this->assertSame([['Clean', '300110', '10.00', 'USD'], ['INTERNATIONAL', '700001', '20.00', 'EUR']], $rows);
  }

  /**
   * Header labels are matched case-insensitively.
   */
  public function testHeadersAreCaseInsensitive(): void {
    $headers = ['BATCH TYPE', 'Group Value', 'batch', 'Batch Date', 'docs', 'CHECKS', 'Total Item', 'Amount'];
    $rows = [['Credit Cards', '', '900012', '10/08/2026', '4', '0', '4', '400.00']];

    $rows = $this->invoke('useDocsAsChecksForCardRows', $headers, $rows);

    $this->assertSame('4', $rows[0][5]);
  }

  /**
   * @param string[] $overrides column letter => value
   * @return string[]
   */
  private function row(array $overrides): array {
    $row = array_fill_keys(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'], '');
    return array_merge($row, $overrides);
  }

  private function header(): array {
    return $this->row([
      'A' => 'Batch Type',
      'B' => 'Group Value',
      'C' => 'Batch',
      'E' => 'Batch Date',
      'G' => 'Docs',
      'I' => 'Checks',
      'K' => 'Total Item',
      'L' => 'Amount',
    ]);
  }

  private function extractDataRows(array $sheetRows): array {
    return $this->invoke('extractDataRows', $sheetRows);
  }

  private function invoke(string $methodName, ...$args): array {
    $method = new \ReflectionMethod(EngageSummary::class, $methodName);
    $method->setAccessible(TRUE);
    return $method->invoke(new EngageSummary(), ...$args);
  }

}
