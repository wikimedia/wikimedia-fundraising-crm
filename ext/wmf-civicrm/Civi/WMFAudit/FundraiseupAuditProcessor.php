<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\Fundraiseup\Audit\FundraiseupAudit;

class FundraiseupAuditProcessor extends BaseAuditProcessor {

  protected $name = 'fundraiseup';

  protected $cutoff = 0;

  /**
   * Number of file to parse per run, absent any incoming parameter.
   *
   * Six, for new donations, new recurring, cancellations, failed recurring, recurring updates, and refunds.
   *
   * Note that 0 is equivalent to all or no limit.
   *
   * @var int
   */
  protected int $fileLimit = 6;

  /**
   * @inheritDoc
   */
  protected function get_audit_parser() {
    return new FundraiseupAudit();
  }

  /**
   * No logs for FRUP, hence no working log dir.
   *
   * @return false
   */
  protected function get_working_log_dir() {
    return FALSE;
  }

  protected function get_log_distilling_grep_string() {
    return NULL;
  }

  protected function get_log_line_grep_string($order_id) {
    return NULL;
  }

  protected function get_logs_by_date($date) {
    return FALSE;
  }

  /**
   * @inheritDoc
   */
  protected function parse_log_line($line) {
    return NULL;
  }

  /**
   * @inheritDoc
   */
  protected function get_recon_file_sort_key($file) {
    // Example: export_donations_2023-09-11_00-00_2023-09-20_23-59.csv
    // For that, we'd want to return 20230911
    if (preg_match('/[0-9]{4}[-][0-9]{2}[-][0-9]{2}/', $file, $date_piece)) {
      return preg_replace('/-/', '', $date_piece[0]);
    }
    else {
      throw new Exception("Un-parseable reconciliation file name: {$file}");
    }
  }

  protected function handleNegatives($total_missing, &$remaining) {
    $neg_count = 0;
    if (array_key_exists('negative', $total_missing) && !empty($total_missing['negative'])) {
      foreach ($total_missing['negative'] as $record) {
        $normal = $this->normalize_negative($record);
        $this->send_queue_message($normal, 'negative');
        $neg_count += 1;
        $this->echo('!');
      }
      $this->echo("Processed $neg_count 'negative' transactions\n");
    }
  }

  protected function normalize_partial($record) {
    $msg = parent::normalize_partial($record);
    $msg['no_thank_you'] = 'Fundraiseup import';
    return $msg;
  }

  protected function log_hunt_and_send($missing_by_date) {
    $missing_count = $this->countMissing($missing_by_date);
    $this->echo("Making up to $missing_count missing transactions:");
    $made = 0;

    foreach ($missing_by_date as $date => $missing) {
      foreach ($missing as $id => $message) {
        $sendme = $this->normalize_partial($message);
        if (!empty($sendme['type']) && $sendme['type'] === 'recurring') {
          $this->send_queue_message($sendme, 'recurring');
        }
        elseif (!empty($sendme['type']) && $sendme['type'] === 'recurring-modify') {
          $this->send_queue_message($sendme, 'recurring-modify');
        }
        else {
          $this->send_queue_message($sendme, 'main');
        }

        $made += 1;
        $this->echo('!');
        unset($missing_by_date[$date][$id]);
      }
    }

    $this->echo("Made $made missing transactions\n");

    return $missing_by_date;
  }

  protected function regex_for_recon() {
    return '/export_/';
  }

}
