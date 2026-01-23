<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\PayPal\Audit\PayPalAudit;

class PaypalAuditProcessor extends BaseAuditProcessor {

  protected $name = 'paypal';
  /**
   * There are two different files we use for the audit SettlementDetailReport is weekly
   * and PaymentsAccountingReport is nightly
   */
  protected function get_audit_parser() {
    return new PayPalAudit();
  }

  /**
   * Note: the output is only used to sort files in chronological order
   * The settlement detail report is named with sequential batch numbers
   * while the payments detail report has the date at the end of the name
   */
  protected function get_recon_file_sort_key($file) {
    $timeString = substr($file, 4, -4);
    $timeParts = explode('.', $timeString);
    $try = gmdate('Y-m-d H:i:s', strtotime($timeParts[0] . ' ' . $timeParts[1] . ':00'));
    if (substr($try, 0, 4) !== '1970') {
      return strtotime($try);
    }
    // sort by the modified date to get the most recent files
    $directory = $this->getIncomingFilesDirectory();
    $fullpath = $directory . '/' . $file;
    return filemtime($fullpath);
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

  protected function regexForFilesToProcess(): string {
    return '/TRR|STL-/';
  }

  protected function regexForFilesToIgnore(): string {
    return '/(DDR-|PPA-|RPP-|WIkimedia_)/';
  }

}
