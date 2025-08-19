<?php

namespace Civi\WMFAudit;

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentProviders\Amazon\Audit\AmazonAudit;
use SmashPig\PaymentProviders\Amazon\RecordPaymentJob;

class AmazonAuditProcessor extends BaseAuditProcessor {

  protected $name = 'amazon';

  protected function get_audit_parser() {
    return new AmazonAudit();
  }

  protected function get_recon_file_sort_key($file) {
    // Example:  2015-09-29-SETTLEMENT_DATA_353863080016707.csv
    // For that, we'd want to return 20150929
    $parts = preg_split('/-/', $file);
    if (count($parts) !== 4) {
      throw new Exception("Unparseable reconciliation file name: {$file}");
    }
    $date = "{$parts[0]}{$parts[1]}{$parts[2]}";

    return $date;
  }

  protected function get_log_distilling_grep_string() {
    return 'Got info for Amazon donation: ';
  }

  protected function get_log_line_grep_string($order_id) {
    return ":$order_id Got info for Amazon donation: ";
  }

  protected function parse_log_line($logline) {
    return $this->parse_json_log_line($logline);
  }

  protected function regex_for_recon() {
    return '/SETTLEMENT_DATA|REFUND_DATA/';
  }

  /**
   * @inheritdoc
   */
  protected function get_compressed_log_file_names($date) {
    return ["payments-amazon_gateway-{$date}.gz"];
  }

  /**
   * @inheritdoc
   */
  protected function get_uncompressed_log_file_names($date) {
    return ["payments-amazon_gateway-{$date}"];
  }

  /**
   * Override parent function to send messages with no donor data to
   * a queue where they can be looked up.
   *
   * @param array $body
   * @param string $type
   *
   * @throws \Exception
   */
  protected function send_queue_message($body, $type) {
    if (
      $type === 'main' &&
      empty($body['contribution_tracking_id'])
    ) {
      $body['order_reference_id'] = substr($body['gateway_txn_id'], 0, 19);
      $job = RecordPaymentJob::fromAmazonMessage($body);
      QueueWrapper::push('jobs-amazon', $job, TRUE);
      return;
    }
    parent::send_queue_message($body, $type);
  }

}
