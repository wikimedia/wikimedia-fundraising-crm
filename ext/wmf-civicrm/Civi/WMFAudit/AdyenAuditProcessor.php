<?php

namespace Civi\WMFAudit;

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\PaymentProviders\Adyen\Audit\AdyenPaymentsAccountingReport;
use SmashPig\PaymentProviders\Adyen\Audit\AdyenSettlementDetailReport;
use SmashPig\PaymentProviders\Adyen\Jobs\TokenizeRecurringJob;

class AdyenAuditProcessor extends BaseAuditProcessor implements MultipleFileTypeParser {

  protected $name = 'adyen';

  protected $filePath;

  public function setFilePath($file) {
    $this->filePath = $file;
  }

  public function getFilePath() {
    return $this->filePath;
  }

  /**
   * There are two different files we use for the audit SettlementDetailReport is weekly
   * and PaymentsAccountingReport is nightly
   */
  protected function get_audit_parser() {
    if (preg_match('/payments_accounting_report_/', $this->getFilePath())) {
      return new AdyenPaymentsAccountingReport();
    }
    else {
      return new AdyenSettlementDetailReport();
    }
  }

  /**
   * Note: the output is only used to sort files in chronological order
   * The settlement detail report is named with sequential batch numbers
   * while the payments detail report has the date at the end of the name
   */
  protected function get_recon_file_sort_key($file) {
    // sort by the modified date to get the most recent files
    $directory = $this->getIncomingFilesDirectory();
    $fullpath = $directory . '/' . $file;
    $key = filemtime($fullpath);
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

  protected function regexForFilesToProcess() {
    return '/settlement_detail_report_|payments_accounting_report_/';
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
    if (!empty($body['recurring'])) {
      $body['processor_contact_id'] = $body['order_id'];
    }
    if (
      $body['gateway'] === 'adyen' && TokenizeRecurringJob::donationNeedsTokenizing($body)
    ) {
      $job = TokenizeRecurringJob::fromDonationMessage($body);
      QueueWrapper::push('jobs-adyen', $job, TRUE);
      return;
    }
    parent::send_queue_message($body, $type);
  }

}
