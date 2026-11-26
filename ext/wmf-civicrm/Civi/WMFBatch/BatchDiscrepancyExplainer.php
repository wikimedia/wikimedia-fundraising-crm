<?php

namespace Civi\WMFBatch;

/**
 * Turns a batch validation result into a plain-English explanation.
 *
 * 'expected' is the batch's settled figures (from the audit file, or entered for manual batches),
 * 'totals' the CiviCRM settlement records journalled to the batch.
 */
class BatchDiscrepancyExplainer {

  /**
   * Get the discrepancy as CiviCRM minus expected, positive meaning CiviCRM has more.
   *
   * The validation array mixes signs - fee & debit are expected (stored negative) plus totals (positive).
   *
   * @param array $validation
   *
   * @return array{count: int, gross: float, refunds: float, fee: float, net: float}
   */
  public static function getCiviMinusExpected(array $validation): array {
    return [
      'count' => (int) -($validation['count'] ?? 0),
      'gross' => round(-($validation['credit'] ?? 0), 2),
      'refunds' => round((float) ($validation['debit'] ?? 0), 2),
      'fee' => round((float) ($validation['fee'] ?? 0), 2),
      'net' => round(-($validation['settled'] ?? 0), 2),
    ];
  }

  /**
   * @param array $validation
   * @param string $currency
   *
   * @return string
   */
  public static function explain(array $validation, string $currency): string {
    $diff = self::getCiviMinusExpected($validation);
    if (!array_filter($diff)) {
      return '';
    }
    $parts = [];
    foreach (['gross' => 'donations', 'refunds' => 'refunds', 'fee' => 'fees', 'net' => 'net'] as $key => $label) {
      if ($diff[$key]) {
        $parts[] = $label . ' ' . ($diff[$key] > 0 ? '+' : '-') . $currency . ' ' . number_format(abs($diff[$key]), 2);
      }
    }
    $amounts = $parts ? ' (' . implode(', ', $parts) . ')' : '';
    $lines = [$diff['count']
      ? 'CiviCRM has ' . abs($diff['count']) . ' ' . ($diff['count'] > 0 ? 'more' : 'fewer') . ' transaction' . (abs($diff['count']) === 1 ? '' : 's') . ' than the batch expects' . $amounts . '.'
      : 'CiviCRM has the same number of transactions as the batch expects but different amounts' . $amounts . '.',
    ];

    // Each side should add up internally: gross - refunds - fee = net.
    if (abs($diff['gross'] - $diff['refunds'] - $diff['fee'] - $diff['net']) >= 0.005) {
      $lines[] = 'Donations - refunds - fees != net, so a contribution has inconsistent settled amounts or a fee-only row is on one side only.';
    }
    elseif (!$diff['count'] && !$diff['net']) {
      $lines[] = 'Net matches - only the donation/fee split differs.';
    }
    return implode(' ', $lines);
  }

}
