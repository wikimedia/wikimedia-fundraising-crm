<?php

namespace Civi\WMFAudit;

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentProviders\Ingenico\Audit\IngenicoAudit;
use SmashPig\PaymentProviders\Ingenico\TokenizeRecurringJob;

class IngenicoAuditProcessor extends BaseAuditProcessor {

  protected $name = 'ingenico';

  protected function get_audit_parser() {
    return new IngenicoAudit();
  }

  /**
   * TODO: wx2 files should supersede wx1 files of the same name
   */
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
   * just our invoice id and the hosted checkout session ID.
   *
   * @param array $transaction possibly incomplete set of transaction data
   *
   * @return string|false the order_id, or false if we can't figure it out
   */
  protected function get_order_id($transaction) {
    if (is_array($transaction)) {
      if ($transaction['gateway'] === 'ingenico') {
        if (array_key_exists('invoice_id', $transaction)) {
          return $transaction['invoice_id'];
        }
      }
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
   * overriding these three functions
   *
   * @inheritdoc
   */
  protected function get_compressed_log_file_names($date) {
    return [
      "payments-globalcollect-{$date}.gz",
      "payments-ingenico-{$date}.gz",
    ];
  }

  /**
   * @inheritdoc
   */
  protected function get_uncompressed_log_file_names($date) {
    return [
      "payments-globalcollect-{$date}",
      "payments-ingenico-{$date}",
    ];
  }

  /**
   * @inheritdoc
   */
  protected function get_working_log_file_names($date) {
    return [
      "{$date}_globalcollect.working",
      "{$date}_ingenico.working",
    ];
  }

  /**
   * The regex to use to determine if a file is an working log for this
   * gateway.
   *
   * @return string regular expression
   */
  protected function regex_for_working_log() {
    return "/\d{8}_(globalcollect|ingenico)/";
  }

  /**
   * Override parent function to deal with recurring donations. We may need to
   * tokenize them before sending them to Civi. If that's the case, send a job
   * to a special-purpose queue.
   *
   * @param array $body
   * @param string $type
   *
   * @throws \Exception
   */
  protected function send_queue_message($body, $type) {
    if (
      // FIXME: below fn should check gateway.
      $body['gateway'] === 'ingenico' &&
      TokenizeRecurringJob::donationNeedsTokenizing($body)
    ) {
      $job = TokenizeRecurringJob::fromDonationMessage($body);
      QueueWrapper::push('jobs-ingenico', $job, TRUE);
      return;
    }
    parent::send_queue_message($body, $type);
  }

}
