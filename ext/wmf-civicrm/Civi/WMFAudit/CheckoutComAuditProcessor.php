<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\CheckoutCom\Audit\CheckoutComAudit;
use SmashPig\PaymentProviders\CheckoutCom\Audit\SettlementBreakdownReport;

class CheckoutComAuditProcessor extends BaseAuditProcessor {

  protected $name = 'checkoutcom';

  protected function get_audit_parser() {
    return new CheckoutComAudit();
  }

  protected function get_recon_file_sort_key($file) {
    // Example:
    // settlement-breakdown_ent_mk6kxjvmys2llfqt2elyl3ogyq_20260702_00000003k599_1.csv
    // Sort key: 20260702
    if (preg_match('/^settlement-breakdown_.*?([0-9]{8})/', basename($file), $matches)) {
      return $matches[1];
    }
    throw new \Exception("Un-parseable reconciliation file name: {$file}");
  }

  /**
   * Match Checkout.com settlement breakdown report files.
   *
   * Examples:
   * settlement-breakdown_ent_mk6kxjvmys2llfqt2elyl3ogyq_20260702_00000003k599_1.csv
   * settlement-breakdown_ent_mk6kxjvmys2llfqt2elyl3ogyq_20260702_00000003k599_1.csv.gz
   *
   * @return string
   */
  protected function regexForFilesToProcess(): string {
    return '/^settlement-breakdown_.*\.csv(?:\.gz)?$/i';
  }

  /**
   * Move the payouts files to ignored.
   *
   * This is not strictly true - we do process them, but in conjunction with the settlement
   * file. They can be found & loaded from the ignored folder.
   *
   * @return string
   */
  protected function regexForFilesToIgnore(): string {
    return '/^payouts_.*\.csv(?:\.gz)?$/i';
  }

}
