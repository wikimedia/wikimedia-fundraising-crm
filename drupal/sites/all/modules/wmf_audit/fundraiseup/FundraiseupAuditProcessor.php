<?php

use SmashPig\PaymentProviders\Fundraiseup\Audit\FundraiseupAudit;

class FundraiseupAuditProcessor extends BaseAuditProcessor {

  protected $name = 'fundraiseup';
  protected $cutoff = 0;

  /**
   * @inheritDoc
   */
  protected function get_audit_parser() {
    return new FundraiseupAudit();
  }

  /**
   * No logs for FRUP, hence no working log dir
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

  protected function handle_all_negatives($total_missing, &$remaining) {
    $neg_count = 0;
    if (array_key_exists('negative', $total_missing) && !empty($total_missing['negative'])) {
      foreach ($total_missing['negative'] as $record) {
        $normal = $this->normalize_negative($record);
        $this->send_queue_message($normal, 'negative');
        $neg_count += 1;
        wmf_audit_echo('!');
      }
      wmf_audit_echo("Processed $neg_count 'negative' transactions\n");
    }
  }

  protected function normalize_partial($record) {
    $msg = parent::normalize_partial($record);
    $msg['no_thank_you'] = 'Fundraiseup import';
    return $msg;
  }

  protected function log_hunt_and_send($missing_by_date) {
    $missing_count = wmf_audit_count_missing($missing_by_date);
    wmf_audit_echo("Making up to $missing_count missing transactions:");
    $made = 0;

    foreach ($missing_by_date as $date => $missing) {
      foreach ($missing as $id => $message) {
        $sendme = $this->normalize_partial($message);
        if (!empty($sendme['type']) && $sendme['type'] === 'recurring') {
          $this->send_queue_message($sendme, 'recurring');
        }
        else {
          $this->send_queue_message($sendme, 'main');
        }

        $made += 1;
        wmf_audit_echo('!');
        unset($missing_by_date[$date][$id]);
      }
    }

    wmf_audit_echo("Made $made missing transactions\n");

    return $missing_by_date;
  }

  protected function regex_for_recon() {
    return '/export_/';
  }

  /**
   * @param $recon_files
   * @return int|void
   */
  protected function get_recon_files_count($recon_files) {
    //...Five, for new donations, new recurring, cancellations, failed recurring, and refunds.
    $count = count($recon_files);
    if ($count > 5 && !$this->get_runtime_options('run_all')) {
      $count = 5;
    }
    return $count;
  }

}
