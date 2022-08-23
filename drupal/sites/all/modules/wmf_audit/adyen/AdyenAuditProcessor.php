<?php

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentProviders\Adyen\Audit\AdyenAudit;
use SmashPig\PaymentProviders\Adyen\TokenizeRecurringJob;

class AdyenAuditProcessor extends BaseAuditProcessor {

  protected $name = 'adyen';

  protected function get_audit_parser() {
    return new AdyenAudit();
  }

  // Note: the output is only used to sort files in chronological order
  // The settlement detail report is named with sequential batch numbers
  // so we just need to zero-pad them to make sure they sort correctly.
  protected function get_recon_file_sort_key($file) {
    // Example: settlement_detail_report_batch_1.csv
    // For that, we'd want to return 00000001 (same length as a date, just for the heck of it)
    $parts = preg_split('/_|\./', $file);
    if (count($parts) < 6) {
      throw new Exception("Unparseable reconciliation file name: {$file}");
    }
    $key = str_pad($parts[4], 8, '0', STR_PAD_LEFT);
    if (!preg_match('/^\d{8}$/', $key)) {
      throw new Exception("Unparseable reconciliation file name: {$file}");
    }
    return $key;
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
    return '/settlement_detail_report_batch_/';
  }

  /**
   * Initial logs for Adyen have no gateway transaction id, just our
   * contribution tracking id plus the attempt number.
   *
   * @param array $transaction possibly incomplete set of transaction data
   *
   * @return string|false the order_id, or false if we can't figure it out
   */
  protected function get_order_id($transaction) {
    if (is_array($transaction) && array_key_exists('invoice_id', $transaction)) {
      return $transaction['invoice_id'];
    }
    return FALSE;
  }


  /**
   * Checks to see if the transaction already exists in civi
   *
   * @param array $transaction Array of donation data
   *
   * @return boolean true if it's in there, otherwise false
   */
  protected function main_transaction_exists_in_civi($transaction) {
    $positive_txn_id = $this->get_parent_order_id($transaction);
    $gateway = $transaction['gateway'];
    //go through the transactions and check to see if they're in civi
    // there are Adyen situations where the $positive_txn_id is not what is stored in civi
    // look for modification_reference instead
    // T306944
    if (wmf_civicrm_get_contributions_from_gateway_id($gateway, $positive_txn_id) === FALSE) {
      if (isset($transaction['modification_reference']) && wmf_civicrm_get_contributions_from_gateway_id($gateway, $transaction['modification_reference']) !== FALSE) {
        return TRUE;
      }
      return FALSE;
    } else {
      return TRUE;
    }
  }

  /**
   * Override parent function to deal with recurring donations with modification_reference.
   * We do not want to pass the modification_reference,
   * also need to make sure the transaction id not null before send audit job to civi
   *
   * @param array $body
   * @param string $type
   *
   * @throws \Exception
   */
  protected function send_queue_message($body, $type) {
    unset($body['modification_reference']);
    // The processor_contact_id will be used for get tokenization for recurring donations.
    if( !empty( $body['recurring'] ) ) {
      $body['processor_contact_id'] = $body['order_id'];
    }
    if (
      $body['gateway'] === 'adyen' && TokenizeRecurringJob::donationNeedsTokenizing($body)
    ) {
      $job = TokenizeRecurringJob::fromDonationMessage($body);
      QueueWrapper::push('jobs-adyen', $job, true);
      return;
    }
    parent::send_queue_message($body, $type);
  }
}
