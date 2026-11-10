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
    $method = new \ReflectionMethod(EngageSummary::class, 'extractDataRows');
    $method->setAccessible(TRUE);
    return $method->invoke(new EngageSummary(), $sheetRows);
  }

}
