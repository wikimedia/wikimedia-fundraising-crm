<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\dlocal\Audit\DlocalAudit;

class DlocalAuditProcessor extends BaseAuditProcessor {

  protected $name = 'dlocal';

  protected function get_audit_parser() {
    return new DlocalAudit();
  }

  protected function get_recon_file_sort_key($file) {
    // Example:  wikimedia_report_2015-06-16.csv
    // For that, we'd want to return 20150616
    $parts = preg_split('/_|\./', $file);
    $date_piece = $parts[count($parts) - 2];
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
    return '/_report_/';
  }

  /**
   * This is glue to get the dlocal audit parser to look at
   * the dlocal named files from apiv2
   *
   */
  protected function get_compressed_log_file_names($date) {
    return [
      "payments-dlocal-{$date}.gz",
    ];
  }

  /**
   * This is glue to get the dlocal audit parser to look at
   * the dlocal named files from apiv2
   *
   */
  protected function get_uncompressed_log_file_names($date) {
    return [
      "payments-dlocal-{$date}",
    ];
  }

}
