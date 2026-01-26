<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\dlocal\Audit\DlocalAudit;

class DlocalAuditProcessor extends BaseAuditProcessor {

  protected $name = 'dlocal';

  protected function get_audit_parser() {
    return new DlocalAudit();
  }

  protected function get_recon_file_sort_key($file) {
    // Match YYYY-MM-DD
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $file, $matches)) {
      return $matches[1] . $matches[2] . $matches[3];
    }
    // Match YYYYMMDD (standalone or beginning of timestamp)
    if (preg_match('/(\d{8})/', $file, $matches)) {
      return $matches[1];
    }
    throw new \Exception("Unparseable reconciliation file name: {$file}");
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

  protected function regexForFilesToProcess() {
    return '/_report|Settlement|border_/';
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

  protected function regexForFilesToIgnore(): string {
    return '/_Cleared.csv/';
  }

}
