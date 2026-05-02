<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\Braintree\Audit\BraintreeAudit;

class BraintreeAuditProcessor extends BaseAuditProcessor {

  protected $name = 'braintree';

  protected function get_audit_parser() {
    return new BraintreeAudit();
  }

  /**
   * @param $file
   * Get the date from parsed file name
   *
   * @return array|string|string[]|null
   * @throws \Exception
   */
  protected function get_recon_file_sort_key($file) {
    // Example: settlement_batch_report_2022-06-21.json or
    // settlement_batch_report_2022-06-21.csv
    // For that, we'd want to return 20220621
    if (preg_match('/[0-9]{4}[-][0-9]{2}[-][0-9]{2}/', $file, $date_piece)) {
      return preg_replace('/-/', '', $date_piece[0]);
    }
    else {
      throw new Exception("Un-parseable reconciliation file name: {$file}");
    }
  }

  /**
   * Save file from SmashPig\PaymentProviders\Braintree\Maintenance\SearchTransactions
   * Three reports (donation refund and dispute) will name as settlement_batch_report_yyyy-mm-dd.json,
   * settlement_batch_report_refund_yyyy-mm-dd.json and settlement_batch_report_dispute_yyyy-mm-dd.json
   *
   * @return string
   */
  protected function regexForFilesToProcess(): string {
    return '/^(settlement|raw)_(disbursement|batch)_report_/';
  }

}
