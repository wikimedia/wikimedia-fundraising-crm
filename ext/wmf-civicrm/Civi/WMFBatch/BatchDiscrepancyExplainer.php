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
    $money = fn(float $amount) => $currency . ' ' . number_format(abs($amount), 2);
    $parts = [];
    foreach (['gross' => 'donations', 'refunds' => 'refunds', 'fee' => 'fees', 'net' => 'net'] as $key => $label) {
      if ($diff[$key]) {
        $parts[] = $money($diff[$key]) . ' ' . ($diff[$key] > 0 ? 'more' : 'less') . ' ' . $label;
      }
    }
    if ($diff['count']) {
      array_unshift($parts, abs($diff['count']) . ' ' . ($diff['count'] > 0 ? 'more' : 'fewer') . ' transaction' . (abs($diff['count']) === 1 ? '' : 's'));
    }
    $lines = ['CiviCRM has ' . implode(', ', $parts) . ' than the batch expects.'];

    // Each side should add up internally: gross - refunds - fee = net.
    if (abs($diff['gross'] - $diff['refunds'] - $diff['fee'] - $diff['net']) >= 0.005) {
      $lines[] = 'These do not add up (donations - refunds - fees != net), so a contribution probably has inconsistent settled amounts, or a fee-only/adjustment row is on one side only.';
    }
    elseif (abs($diff['count']) === 1) {
      $lines[] = 'That looks like a single whole transaction ' . ($diff['count'] > 0
        ? 'in CiviCRM that is not in the settlement file - look for a contribution with this settlement_batch_reference (or settlement_batch_reversal_reference) that belongs to a neighbouring day, is a duplicate, or was edited by hand.'
        : 'in the settlement file that is not settled to this batch in CiviCRM - it may still be missing or pending, or settled to a different batch.');
    }
    elseif ($diff['count'] > 0) {
      $lines[] = 'Look for contributions with this settlement_batch_reference (or settlement_batch_reversal_reference) that are not in the settlement file - e.g. from a neighbouring day, duplicates, or edited by hand.';
    }
    elseif ($diff['count'] < 0) {
      $lines[] = 'Look for settlement file rows that are not settled to this batch in CiviCRM - still missing or pending, or settled to a different batch.';
    }
    elseif (!$diff['net']) {
      $lines[] = 'The net amount matches, so the split between donations and fees differs on some transaction(s) - the cash is right but the GL split is not.';
    }
    elseif ($diff['fee'] && !$diff['gross'] && !$diff['refunds']) {
      $lines[] = 'Only fees differ - look for a fee changed on one side only (e.g. a fee reversal or chargeback fee).';
    }
    else {
      $lines[] = 'The transaction count matches, so look for a transaction whose settled amount differs (e.g. currency conversion) rather than a missing one.';
    }
    if ($diff['refunds']) {
      $lines[] = 'Refunds/chargebacks are matched to the batch by settlement_batch_reversal_reference on the reversal date.';
    }
    return implode(' ', $lines);
  }

}
