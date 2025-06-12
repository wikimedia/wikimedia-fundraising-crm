<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\Gravy\Audit\GravyAudit;

class GravyAuditProcessor extends BaseAuditProcessor {

  protected $name = 'gravy';

  protected function get_audit_parser() {
    return new GravyAudit();
  }

  /**
   * @param $file
   * Get the date from parsed file name
   *
   * @return false|int
   * @throws \Exception
   */
  protected function get_recon_file_sort_key($file) {
    // sort by the modified date to get the most recent files
    $directory = $this->getIncomingFilesDirectory();
    $fullpath = $directory . '/' . $file;
    $key = filemtime($fullpath);
    return $key;
  }

  protected function get_log_distilling_grep_string() {
    return 'Completed donation:';
  }

  /**
   * This string is used as a grep match pattern to search the payment logs
   * for log lines relating to the donation that we're trying to reconcile.
   *
   * This behaviour made more sense with older integrations where the donor was
   * redirected off of our website and over to the payment gateway's payment pages
   * whereas for gravy, the donor doesn't leave our page, so there is less chance of
   * them "getting lost" mid-payment.
   *
   * @param $order_id
   *
   * @return string
   */
  protected function get_log_line_grep_string($order_id) {
    return ":$order_id Completed donation:";
  }

  protected function parse_log_line($logline) {
    return $this->parse_json_log_line($logline);
  }

  /**
   * Kinda self-explanatory if you know recon is short for reconcile,
   * which is another name for the settlement and audit reports.
   *
   * This is the regex used to match the beginning of the filename
   * in the supplied reconciliation file path.
   * @return string
   */
  protected function regex_for_recon() {
    return '/gravy_all_transactions_report/';
  }

  protected function get_order_id($transaction) {
    if (is_array($transaction) && array_key_exists('order_id', $transaction)) {
      return $transaction['order_id'];
    }
    return FALSE;
  }

}
