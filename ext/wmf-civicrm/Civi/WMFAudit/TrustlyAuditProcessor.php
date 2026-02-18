<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\Trustly\Audit\TrustlyAudit;

class TrustlyAuditProcessor extends BaseAuditProcessor {

  protected $name = 'trustly';
  /**
   * There are two different files we get from Trustly.
   *
   * The P11KFUN one holds settlement information.
   */
  protected function get_audit_parser() {
    return new TrustlyAudit();
  }

  /**
   * Note: the output is only used to sort files in chronological order.
   */
  protected function get_recon_file_sort_key($file) {

    // Remove extension
    $base = substr($file, 0, -4);

    // Split by "-"
    $parts = explode('-', $base);

    // Expected format:
    // P11KFUN | 3618 | 20260123120000 | 20260126120000 | 0001of0001
    if (count($parts) >= 4) {

      // Second date is index 3
      $secondDate = $parts[3];

      // Convert YYYYMMDDHHMMSS → timestamp
      $try = \DateTime::createFromFormat("YmdHis", $secondDate, new \DateTimeZone("UTC"));

      if ($try !== false) {
        return $try->getTimestamp();
      }
    }

    // Fallback: sort by modified time
    $directory = $this->getIncomingFilesDirectory();
    return filemtime($directory . '/' . $file);
  }

  protected function get_log_distilling_grep_string() {
    return 'Redirecting for transaction:';
  }

  protected function get_log_line_grep_string($order_id) {
    return ":$order_id Redirecting for transaction:";
  }

  protected function parse_log_line($line) {
    return $this->parse_json_log_line($line);
  }

  protected function regexForFilesToProcess(): string {
    return '/P11KFUN/';
  }

  protected function regexForFilesToIgnore(): string {
    return '/P11KREC/';
  }

}
