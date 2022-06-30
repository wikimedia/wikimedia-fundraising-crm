<?php

use SmashPig\PaymentProviders\Braintree\Audit\BraintreeAudit;

class BraintreeAuditProcessor extends BaseAuditProcessor {

  protected $name = 'braintree';

  protected function get_audit_parser() {
    return new BraintreeAudit();
  }

  protected function get_recon_file_sort_key($file) {
    // Example:  settlement_batch_report_2022-06-21.csv
    // For that, we'd want to return 20220621
    $parts = preg_split('/_|\./', $file);
    $date_piece = $parts[count($parts) - 3];
    $date = preg_replace('/-/', '', $date_piece);
    if (!preg_match('/^\d{8}$/', $date)) {
      throw new Exception("Unparseable reconciliation file name: {$file}");
    }
    return $date;
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

  protected function regex_for_recon() {
    return '/settlement_batch_report_/';
  }

  /**
   * Initial logs for Braintree have no gateway transaction id, just our
   * contribution tracking id.
   *
   * @param array $transaction possibly incomplete set of transaction data
   *
   * @return string|false the order_id, or false if we can't figure it out
   */
  protected function get_order_id($transaction) {
    if (is_array($transaction) && array_key_exists('invoice_id', $transaction)) {
      return $transaction['invoice_id'];
    }
    return FALSE;
  }
}
