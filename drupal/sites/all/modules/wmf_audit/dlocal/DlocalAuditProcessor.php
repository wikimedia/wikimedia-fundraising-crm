<?php

use Civi\WMFException\WMFException;
use SmashPig\PaymentProviders\dlocal\Audit\DlocalAudit;
use SmashPig\PaymentProviders\dlocal\ReferenceData;

class DlocalAuditProcessor extends BaseAuditProcessor {

  protected $name = 'dlocal';

  protected function get_audit_parser() {
    return new DlocalAudit();
  }

  /**
   * For the transition from drupal to extension
   */
  protected function getIncomingFilesDirectory(): string {
    return \Civi::settings()->get('wmf_audit_directory_audit') . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR . 'incoming' . DIRECTORY_SEPARATOR;
  }

  protected function getCompletedFilesDirectory(): string {
    return \Civi::settings()->get('wmf_audit_directory_audit') . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR . 'completed' . DIRECTORY_SEPARATOR;
  }

  protected function getWorkingLogDirectory(): string {
    return \Civi::settings()->get('wmf_audit_directory_working_log') . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR;
  }

  protected function get_recon_file_sort_key($file) {
    // Example:  wikimedia_report_2015-06-16.csv
    // For that, we'd want to return 20150616
    $parts = preg_split('/_|\./', $file);
    $date_piece = $parts[count($parts) - 2];
    $date = preg_replace('/-/', '', $date_piece);
    if (!preg_match('/^\d{8}$/', $date)) {
      throw new Exception("Unparseable reconciliation file name: {$file}");
    }
    return $date;
  }

  protected function get_log_distilling_grep_string() {
    return 'Redirecting for transaction:';
  }

  protected function get_log_line_grep_string($order_id) {
    return ":$order_id Redirecting for transaction:";
  }

  protected function get_log_line_grep_string_temp($order_id) {
    return ":$order_id  | Raw response from dlocal";
  }

  protected function get_outbound_log_line_grep_string_temp($order_id) {
    return ":$order_id  | Outbound data:";
  }

  protected function parse_log_line($logline) {
    return $this->parse_json_log_line($logline);
  }

  protected function parse_json_log_line_raw_response_payment_info($line) {
    $log_data = $this->decode_json_from_log($line);
    // Filter field names

    $filtered = [
      'gross' => $log_data['amount'],
      'currency' => $log_data['currency'],
      'country' => $log_data['country'],
      'gateway' => 'dlocal',
      'recurring' => 0,
      'full_name' => !empty($log_data['card']) ? $log_data['card']['holder_name'] : NULL,
    ];

    if (!empty($log_data['card']) && !array_key_exists('card_id', $log_data['card'])) {
      $filtered['recurring_payment_token'] = $log_data['card']['card_id'];
    }

    $method_type = $log_data['payment_method_type'] ?? '';
    $submethod = '';
    if ($method_type == 'CARD') {
      $submethod = $log_data['card']['brand'];
    } else {
      $submethod = $log_data['payment_method_id'] ?? '';
    }

    [$filtered['payment_method'], $filtered['payment_submethod']] = ReferenceData::decodePaymentMethod($method_type, $submethod);

    return $filtered;
  }

  protected function parse_json_log_line_raw_response_donor_info($line) {
    $log_data = $this->decode_json_from_log($line);
    // Filter field names
    $callback_url = $log_data['callback_url'];
    $full_name = !empty($log_data['payer']) ? $log_data['payer']['name'] : NULL;
    $filtered = [
      'email' => !empty($log_data['payer']) ? $log_data['payer']['email'] : NULL,
      'fiscal_number' => !empty($log_data['payer']) ? $log_data['payer']['document'] : NULL,
      'full_name' => !empty($log_data['payer']) ? $log_data['payer']['name'] : NULL,
    ];
    if(!empty($full_name)) {
      $name_arr = explode(' ', $full_name);
      $filtered['first_name'] = $name_arr[0];
      $filtered['last_name'] = $name_arr[count($name_arr)-1];
      if(count($name_arr) > 2){
        $filtered['middle_name'] = $name_arr[1];
      }
    }
    if (!empty($callback_url)) {
      $parts = parse_url($callback_url);
      if (isset($parts['query'])) {
        parse_str($parts['query'], $query);

        if (isset($query['payment_method'])) {
          $filtered['payment_method'] = $query['payment_method'];
        }
        if (isset($query['payment_submethod'])) {
          $filtered['payment_submethod'] = $query['payment_submethod'];
        }
        if (isset($query['utm_source'])) {
          $filtered['utm_source'] = $query['utm_source'];
        }
        if (isset($query['utm_campaign'])) {
          $filtered['utm_campaign'] = $query['utm_campaign'];
        }
        if (isset($query['utm_medium'])) {
          $filtered['utm_medium'] = $query['utm_medium'];
        }
        if (isset($query['recurring'])) {
          $filtered['recurring'] = $query['recurring'];
        }
      }
    }

    return $filtered;
  }

  protected function regex_for_recon() {
    return '/_report_/';
  }

  /**
   * Initial logs for Dlocal have no gateway transaction id, just our
   * contribution tracking id.
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
   * This is glue to get the dlocal audit parser to look at
   * the dlocal named files from apiv2
   *
   */
  protected function get_compressed_log_file_names($date) {
    return [
      "payments-dlocal-{$date}.gz",
    ];
  }

  /**
   * This is glue to get the dlocal audit parser to look at
   * the dlocal named files from apiv2
   *
   */
  protected function get_uncompressed_log_file_names($date) {
    return [
      "payments-dlocal-{$date}",
    ];
  }

  protected function get_logs_on_error_days() {
    // Dates the exception was active
    $fileDirs = [];
    $list = ['20240509', '20240510','20240511','20240512', '20240513','20240514', '20240515'];
    foreach ($list as $dateItem) {
      $compressed_filenames = $this->get_compressed_log_file_names($dateItem);
      foreach ($compressed_filenames as $filename) {
        $full_archive_path = wmf_audit_get_log_archive_dir() . '/' . $filename;
        $fileDirs[] = $full_archive_path;
      }
    }
    return $fileDirs;
  }
  protected function grep_by_order_id(array $logs, string $order_id, &$errorlevel, &$ret): void {
    parent::grep_by_order_id($logs, $order_id, $errorlevel, $ret);
    if (empty($ret)) {
      wmf_audit_echo("Checking logs on error days for transaction with order id $order_id");

      // get logfiles on dates when custom filter exceptions broke the payment flow
      $fileDirs = $this->get_logs_on_error_days();

      // combine the files to ensure they are all searched through
      $logPaths = implode(' ', $fileDirs);

      // use zgrep to grep the archived log files for the raw response from Dlocal
      $cmd = 'zgrep -h \'' . $this->get_log_line_grep_string_temp($order_id) . '\' ' . $logPaths;
      wmf_audit_echo(__FUNCTION__ . ' ' . $cmd, TRUE);
      exec($cmd, $ret, $errorlevel);

      $cmd = 'zgrep -h \'' . $this->get_outbound_log_line_grep_string_temp($order_id) . '\' ' . $logPaths;
      wmf_audit_echo(__FUNCTION__ . ' ' . $cmd, TRUE);
      $temp = [];
      exec($cmd, $temp, $errorlevel);

      if(count($ret) === 0 && count($temp) === 0) {
        return;
      }
      $ret = [$ret[count($ret)-1] . "/" . $temp[0]];
    }
  }

  protected function extract_raw_data_from_logline($line, &$contribution_id, &$raw_data): void {
    // If log available is from raw response (useful for missed transactions)
    if (str_contains($line, 'Raw response from dlocal')) {
      $unspaced = preg_replace('/ +/', ' ', $line);
      $linedata = explode('dlocal: ', $unspaced);
      //Get contribution tracking ID
      $contribution_id_line = explode(':', $linedata[0]);
      $contribution_id = $contribution_id_line[5];
      //Recreate contribution array from details in log line
      $json_half = explode('|', $linedata[1]);
      $payment_info = $this->parse_json_log_line_raw_response_payment_info($json_half[0]);
      $info = explode('data: ', $json_half[3]);
      $donor_info = $this->parse_json_log_line_raw_response_donor_info(preg_replace('/\'|\' +/', '', $info[1]));
      $raw_data = array_merge($payment_info, $donor_info);
    }
    else {
      parent::extract_raw_data_from_logline($line, $contribution_id, $raw_data);
    }
  }

  /**
   * @param $line
   * @return array
   */
  protected function decode_json_from_log($line): array {
    $matches = [];
    if (!preg_match('/[^{]*([{].*)/', $line, $matches)) {
      throw new WMFException(
      WMFException::MISSING_MANDATORY_DATA, "JSON data not found in $line"
       );
    }
    $log_data = json_decode($matches[1], TRUE);
    if (!$log_data) {
      throw new WMFException(
      WMFException::MISSING_MANDATORY_DATA, "Could not parse JSON data in $line"
       );
    }
    return $log_data;
  }

}
