<?php

use SmashPig\PaymentProviders\Adyen\Audit\AdyenAudit;

class AdyenAuditProcessor extends BaseAuditProcessor {

  protected $name = 'adyen';

  protected function get_audit_parser() {
    return new AdyenAudit();
  }

  // Note: the output is only used to sort files in chronological order
  // The settlement detail report is named with sequential batch numbers
  // so we just need to zero-pad them to make sure they sort correctly.
  protected function get_recon_file_sort_key($file) {
    // Example: settlement_detail_report_batch_1.csv
    // For that, we'd want to return 00000001 (same length as a date, just for the heck of it)
    $parts = preg_split('/_|\./', $file);
    if (count($parts) < 6) {
      throw new Exception("Unparseable reconciliation file name: {$file}");
    }
    $key = str_pad($parts[4], 8, '0', STR_PAD_LEFT);
    if (!preg_match('/^\d{8}$/', $key)) {
      throw new Exception("Unparseable reconciliation file name: {$file}");
    }
    return $key;
  }

  protected function get_log_distilling_grep_string() {
    return 'Redirecting for transaction:';
  }

  protected function get_log_line_grep_string($order_id) {
    return ":$order_id Redirecting for transaction:";
  }

  protected function parse_log_line($logline) {
    return $this->parse_json_log_line($logline);
  }

  protected function merge_data($log_data, $audit_file_data) {
    $merged = parent::merge_data($log_data, $audit_file_data);
    if ($merged) {
      unset($merged['log_id']);
    }
    return $merged;
  }

  protected function regex_for_recon() {
    return '/settlement_detail_report_batch_/';
  }

  /**
   * Initial logs for Adyen have no gateway transaction id, just our
   * contribution tracking id plus the attempt number.
   *
   * @param array $transaction possibly incomplete set of transaction data
   *
   * @return string|false the order_id, or false if we can't figure it out
   */
  protected function get_order_id($transaction) {
    if (is_array($transaction) && array_key_exists('log_id', $transaction)) {
      return $transaction['log_id'];
    }
    return FALSE;
  }
}
