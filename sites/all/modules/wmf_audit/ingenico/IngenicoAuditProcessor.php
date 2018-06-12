<?php

use SmashPig\PaymentProviders\Ingenico\Audit\IngenicoAudit;
use SmashPig\PaymentProviders\Ingenico\ReferenceData;

class IngenicoAuditProcessor extends BaseAuditProcessor {

  protected $name = 'ingenico';

  protected function get_audit_parser() {
    return new IngenicoAudit();
  }

  // TODO: wx2 files should supersede wx1 files of the same name
  protected function get_recon_file_sort_key($file) {
    // Example: wx1.000000123420160423.010211.xml.gz
    // For that, we'd want to return 20160423
    return substr($file, 15, 8);
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
    return '/wx\d\.\d{18}\.\d{6}.xml.gz/';
  }

  /**
   * Initial logs for the Ingenico Connect API have no gateway transaction id,
   * just our contribution tracking id and the hosted checkout session ID.
   *
   * @param array $transaction possibly incomplete set of transaction data
   *
   * @return string|false the order_id, or false if we can't figure it out
   */
  protected function get_order_id($transaction) {
    if (is_array($transaction)) {
      if (array_key_exists('order_id', $transaction)) {
        return $transaction['order_id'];
      }
      if (array_key_exists('gateway_parent_id', $transaction)) {
        return $transaction['gateway_parent_id'];
      }
    }
    return FALSE;
  }

  /**
   * TODO: transition from 'globalcollect' to 'ingenico' and stop
   * overriding these two functions
   *
   * @inheritdoc
   */
  protected function get_compressed_log_file_names($date) {
    return ["payments-globalcollect-{$date}.gz"];
  }

  /**
   * @inheritdoc
   */
  protected function get_uncompressed_log_file_names($date) {
    return ["payments-globalcollect-{$date}"];
  }

  /**
   * The regex to use to determine if a file is an uncompressed log for this
   * gateway.
   *
   * @return string regular expression
   */
  protected function regex_for_uncompressed_log() {
    return "/globalcollect_\d{8}/";
  }

  protected function pre_process_refund($transaction) {
    // We get woefully sparse records from some audit files.
    // Try to reconstruct missing/false-y gateway_parent_id from ct_id
    if (
      empty($transaction['gateway_parent_id']) &&
      empty($transaction['gateway_txn_id']) &&
      !empty($transaction['contribution_tracking_id'])
    ) {
      $transaction['gateway_parent_id'] = $this->getGatewayIdFromTracking($transaction);
    }
    if (empty($transaction['gateway_refund_id'])) {
      // This stinks, but Ingenico doesn't give refunds their own ID,
      // and sometimes even sends '0'
      // We'll prepend an 'RFD' in the trxn_id column later.
      $transaction['gateway_refund_id'] = $transaction['gateway_parent_id'];
    }
    return $transaction;
  }
}
