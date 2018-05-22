<?php

abstract class BaseAuditProcessor {

  protected $options;

  protected $name;

  protected $ready_files;

  public function __construct($options) {
    $this->options = $options;
    // FIXME: Copy to confusing global thing.
    $options['submod_prefix'] = $this->name;
    wmf_audit_runtime_options($options);
  }

  /**
   * Return an object that performs the file parsing.
   */
  abstract protected function get_audit_parser();

  abstract protected function get_log_distilling_grep_string();

  abstract protected function get_log_line_grep_string($order_id);

  /**
   * Create a normalized message from a line in a payments log file
   */
  abstract protected function parse_log_line($line);

  /**
   * Get some identifier for a given files to let us sort all
   * recon files for this processor chronologically.
   *
   * @param string $file name of the recon file
   *
   * @return string key for chronological sort
   */
  abstract protected function get_recon_file_sort_key($file);

  abstract protected function regex_for_recon();

  /**
   * Check for consistency
   *
   * @param array $log_data Transaction data from payments log
   * @param array $audit_file_data Transaction data from the audit file
   *
   * @return boolean true if data is good, false if something went wrong
   */
  protected function check_consistency($log_data, $audit_file_data) {
    if (empty($log_data) || empty($audit_file_data)) {
      $message = ": Missing one of the required arrays.\nLog Data: "
        . print_r($log_data, TRUE)
        . "\nAudit file Data: " . print_r($audit_file_data, TRUE);
      wmf_audit_log_error(__FUNCTION__ . $message, 'DATA_WEIRD');
      return FALSE;
    }

    //Cross-reference log and audit file data and complain loudly if something doesn't match.
    //@TODO: see if there's a way we can usefully use [settlement_currency] and [settlement_amount]
    //from the recon file. This is actually super useful, but might require new import code and/or schema change.

    $cross_check = [
      'currency',
      'gross',
    ];

    foreach ($cross_check as $field) {
      if (array_key_exists($field, $log_data) && array_key_exists($field, $audit_file_data)) {
        if (is_numeric($log_data[$field])) {
          //I actually hate everything.
          //Floatval all by itself doesn't do the job, even if I turn the !== into !=.
          //"Data mismatch between normal gross (5) and recon gross (5)."
          $log_data[$field] = (string) floatval($log_data[$field]);
          $audit_file_data[$field] = (string) floatval($audit_file_data[$field]);
        }
        if ($log_data[$field] !== $audit_file_data[$field]) {
          wmf_audit_log_error("Data mismatch between log and audit $field ({$log_data[$field]} != {$audit_file_data[$field]}). Investigation required. " . print_r($audit_file_data, TRUE), 'DATA_INCONSISTENT');
          return FALSE;
        }
      }
      else {
        wmf_audit_log_error("Audit data is expecting $field but at least one is missing. Investigation required. " . print_r($audit_file_data, TRUE), 'DATA_INCONSISTENT');
        return FALSE;
      }
    }
    return TRUE;
  }

  protected function merge_data($log_data, $audit_file_data) {
    return array_merge($audit_file_data, $log_data);
  }

  /**
   * Returns the configurable path to the recon files
   *
   * @return string Path to the directory
   */
  protected function get_recon_dir() {
    return variable_get($this->name . '_audit_recon_files_dir');
  }

  /**
   * Returns the configurable path to the completed recon files
   *
   * @return string Path to the directory
   */
  protected function get_recon_completed_dir() {
    return variable_get($this->name . '_audit_recon_completed_dir');
  }

  /**
   * Returns the configurable path to the working log dir
   *
   * @return string Path to the directory
   */
  protected function get_working_log_dir() {
    return variable_get($this->name . '_audit_working_log_dir');
  }

  /**
   * The regex to use to determine if a file is a working log for this gateway.
   *
   * @return string regular expression
   */
  protected function regex_for_working_log() {
    return "/\d{8}_{$this->name}[.]working/";
  }

  /**
   * The regex to use to determine if a file is a compressed payments log.
   *
   * @return string regular expression
   */
  protected function regex_for_compressed_log() {
    return '/.gz/';
  }

  /**
   * The regex to use to determine if a file is an uncompressed log for this
   * gateway.
   *
   * @return string regular expression
   */
  protected function regex_for_uncompressed_log() {
    return "/{$this->name}_\d{8}/";
  }

  /**
   * Returns the configurable number of days we want to jump back in to the
   * past, to look for transactions in the payments logs.
   *
   * @return in Number of days
   */
  protected function get_log_days_in_past() {
    return variable_get($this->name . '_audit_log_search_past_days');
  }

  /**
   * Given the name of a working log file, pull out the date portion.
   *
   * @param string $file Name of the working log file (not full path)
   *
   * @return string date in YYYYMMDD format
   */
  protected function get_working_log_file_date($file) {
    // '/\d{8}_GATEWAY\.working/';
    $parts = explode('_', $file);
    return $parts[0];
  }

  /**
   * Get the name of a compressed log file based on the supplied date.
   *
   * @param string $date date in YYYYMMDD format
   *
   * @return string Name of the file we're looking for
   */
  protected function get_compressed_log_file_name($date) {
    // payments-worldpay-20140413.gz
    return "payments-{$this->name}-{$date}.gz";
  }

  /**
   * Get the name of an uncompressed log file based on the supplied date.
   *
   * @param string $date date in YYYYMMDD format
   *
   * @return string Name of the file we're looking for
   */
  protected function get_uncompressed_log_file_name($date) {
    // payments-worldpay-20140413 - no extension. Weird.
    return "payments-{$this->name}-{$date}";
  }

  /**
   * Get the name of a working log file based on the supplied date.
   *
   * @param string $date date in YYYYMMDD format
   *
   * @return string Name of the file we're looking for
   */
  protected function get_working_log_file_name($date) {
    // '/\d{8}_worldpay\.working/';
    return "{$date}_{$this->name}.working";
  }

  /**
   * Checks the array to see if the data inside is describing a refund.
   *
   * @param array $record The transaction we would like to know is a refund or
   *   not.
   *
   * @return boolean true if it is, otherwise false
   */
  protected function record_is_refund($record) {
    return (array_key_exists('type', $record) && $record['type'] === 'refund');
  }

  /**
   * Checks the array to see if the data inside is describing a chargeback.
   *
   * @param array $record The transaction we would like to know is a chargeback
   *   or not.
   *
   * @return boolean true if it is, otherwise false
   */
  protected function record_is_chargeback($record) {
    return (array_key_exists('type', $record) && $record['type'] === 'chargeback');
  }

  /**
   * Checks the array to see if the data inside is describing a cancel.
   *
   * @param array $record The transaction we would like to know is a cancel or
   * not.
   *
   * @return boolean true if it is, otherwise false
   */
  protected function record_is_cancel($record) {
    return (array_key_exists('type', $record) && $record['type'] === 'cancel');
  }

  /**
   * Return a date in the format YYYYMMDD for the given record
   *
   * @param array $record A transaction, or partial transaction
   *
   * @return string Date in YYYYMMDD format
   */
  protected function get_record_human_date($record) {
    if (array_key_exists('date', $record)) {
      return date(WMF_DATEFORMAT, $record['date']); //date format defined in wmf_dates
    }

    echo print_r($record, TRUE);
    throw new Exception(__FUNCTION__ . ': No date present in the record. This seems like a problem.');
  }

  /**
   * Normalize refund/chargeback messages before sending
   *
   * @param array $record transaction data
   *
   * @return array The normalized data we want to send
   */
  protected function normalize_negative($record) {
    $send_message = [
      // FIXME: Use WmfTransaction
      'gross' => $record['gross'],
      //amount
      'date' => $record['date'],
      //timestamp
      // 'gateway_account' => $record['gateway_account'], //BOO. @TODO: Later.
      // 'payment_method' => $record['payment_method'], //Argh. Not telling you.
      // 'payment_submethod' => $record['payment_submethod'], //Still not telling you.
      'type' => $record['type'],
      //This actually works here. Weird, right?
    ];
    if (isset($record['gateway'])) {
      $send_message['gateway'] = $record['gateway'];
    }
    else {
      $send_message['gateway'] = $this->name;
    }
    // for now, just don't try to normalize if it's already normal
    if (isset($record['gateway_refund_id'])) {
      $send_message['gateway_refund_id'] = $record['gateway_refund_id'];
    }
    elseif (isset($record['gateway_txn_id'])) {
      $send_message['gateway_refund_id'] = $record['gateway_txn_id']; //Notes from a previous version: "after intense deliberation, we don't actually care what this is at all."
    }
    if (isset($record['gateway_parent_id'])) {
      $send_message['gateway_parent_id'] = $record['gateway_parent_id'];
    }
    else {
      $send_message['gateway_parent_id'] = $record['gateway_txn_id'];
    }
    if (isset($record['gross_currency'])) {
      $send_message['gross_currency'] = $record['gross_currency'];
    }
    else {
      $send_message['gross_currency'] = $record['gross'];
    }
    return $send_message;
  }

  /**
   * Used in makemissing mode
   * This should take care of any extra data not sent in the recon file, that
   * will actually make qc choke. Not so necessary with WP, but this will need
   * to happen elsewhere, probably. Just thinking ahead.
   *
   * @param array $record transaction data
   *
   * @return array The normalized data we want to send.
   */
  protected function normalize_partial($record) {
    //@TODO: Still need gateway account to go in here when that happens.
    return $record;
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
    if (wmf_civicrm_get_contributions_from_gateway_id($gateway, $positive_txn_id) === FALSE) {
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  /**
   * Checks to see if the refund or chargeback already exists in civi.
   * NOTE: This does not check to see if the parent is present at all, nor
   * should it. Call main_transaction_exists_in_civi for that.
   *
   * @param array $transaction Array of donation data
   *
   * @return boolean true if it's in there, otherwise false
   */
  protected function negative_transaction_exists_in_civi($transaction) {
    $positive_txn_id = $this->get_parent_order_id($transaction);
    $gateway = $transaction['gateway'];
    return wmf_civicrm_is_refunded_by_gateway_id($gateway, $positive_txn_id);
  }

  protected function get_runtime_options($name) {
    if (isset($this->options[$name])) {
      return $this->options[$name];
    }
    else {
      return NULL;
    }
  }

  /**
   * The main function, intended to be called straight from the drush command
   * and nowhere else.
   */
  public function run() {
    civicrm_initialize();

    //make sure all the things we need are there.
    if (!$this->setup_required_directories()) {
      throw new Exception('Missing required directories');
    }

    //find out what the situation is with the available recon files, by date
    $recon_files = $this->get_all_recon_files();

    //get missing transactions from one or more recon files
    //let's just assume that the default mode will be to pop off the top three (most recent) at this point. :)
    //...Three, because Shut Up.
    $count = count($recon_files);
    if ($count > 3 && !$this->get_runtime_options('run_all')) {
      $count = 3;
    }

    $total_missing = [];
    $recon_file_stats = [];

    for ($i = 0; $i < $count; ++$i) {
      $parsed = [];
      $missing = [];

      //parce the recon files into something relatively reasonable.
      $file = array_pop($recon_files);
      wmf_audit_echo("Parsing $file");
      $start_time = microtime(TRUE);
      $parsed = $this->parse_recon_file($file);
      $time = microtime(TRUE) - $start_time;
      wmf_audit_echo(count($parsed) . " results found in $time seconds\n");

      //remove transactions we already know about
      $start_time = microtime(TRUE);
      $missing = $this->get_missing_transactions($parsed);
      $recon_file_stats[$file] = wmf_audit_count_missing($missing);
      $time = microtime(TRUE) - $start_time;
      wmf_audit_echo(wmf_audit_count_missing($missing) . ' missing transactions (of a possible ' . count($parsed) . ") identified in $time seconds\n");

      //If the file is empty, move it off.
      // Note that we are not archiving files that have missing transactions,
      // which might be resolved below.	Those are archived on the next run,
      // once we can confirm they have hit Civi and are no longer missing.
      if (wmf_audit_count_missing($missing) <= $this->get_runtime_options('recon_complete_count')) {
        if (wmf_audit_runtime_options('test')) {
          wmf_audit_echo("Not moving file '{$file}' because test mode\n");
        }
        else {
          $this->move_completed_recon_file($file);
        }
      }

      //grumble...
      if (!empty($missing)) {
        foreach ($missing as $type => $data) {
          if (!empty($missing[$type])) {
            if (array_key_exists($type, $total_missing)) {
              $total_missing[$type] = array_merge($total_missing[$type], $missing[$type]);
            }
            else {
              $total_missing[$type] = $missing[$type];
            }
          }
        }
      }
    }
    $total_missing_count = wmf_audit_count_missing($total_missing);
    wmf_audit_echo("$total_missing_count total missing transactions identified at start");

    //get the date distribution on what's left... for ***main transactions only***
    //That should be to say: The things that are totally in the payments logs.
    //Other things, we will have to look other places, or just rebuild.
    $missing_by_date = [];
    if (array_key_exists('main', $total_missing) && !empty($total_missing['main'])) {
      foreach ($total_missing['main'] as $record) {
        $missing_by_date[$this->get_record_human_date($record)][] = $record;
      }
    }


    $remaining = NULL;
    if (!empty($missing_by_date)) {
      $remaining['main'] = $this->log_hunt_and_send($missing_by_date);
    }

    //@TODO: Handle the recurring type, once we have a gateway that gives some of those to us.
    //
    //Handle the negatives now. That way, the parent transactions will probably exist.
    wmf_audit_echo("Processing 'negative' transactions");
    $neg_count = 0;

    if (array_key_exists('negative', $total_missing) && !empty($total_missing['negative'])) {
      foreach ($total_missing['negative'] as $record) {
        //check to see if the parent exists. If it does, normalize and send.
        if ($this->main_transaction_exists_in_civi($record)) {
          $normal = $this->normalize_negative($record);
          wmf_audit_send_transaction($normal, 'negative');
          $neg_count += 1;
          wmf_audit_echo('!');
        }
        else {
          // Ignore cancels with no parents because they must have
          // been cancelled before reaching Civi.
          if (!$this->record_is_cancel($record)) {
            //@TODO: Some version of makemissing should make these, too. Gar.
            $remaining['negative'][$this->get_record_human_date($record)][] = $record;
            wmf_audit_echo('.');
          }
        }
      }
      wmf_audit_echo("Processed $neg_count 'negative' transactions\n");
    }

    //Wrap it up and put a bow on it.
    //@TODO much later: Make a fredge table for these things and dump some messages over there about what we just did.
    $missing_main = 0;
    $missing_negative = 0;
    $missing_recurring = 0;
    $missing_at_end = 0;
    if (is_array($remaining) && !empty($remaining)) {
      foreach ($remaining as $type => $data) {
        $count = wmf_audit_count_missing($data);
        ${'missing_' . $type} = $count;
        $missing_at_end += $count;
      }
    }

    $wrap_up = "\nDone! Final stats:\n";
    $wrap_up .= "Total missing at start: $total_missing_count\n";
    $wrap_up .= 'Missing at end: ' . $missing_at_end . "\n\n";

    if ($missing_at_end > 0) {
      $wrap_up .= "Missing transaction summary:\n";
      $wrap_up .= "Regular donations: $missing_main\n";
      if ($missing_main > 0) {
        foreach ($remaining['main'] as $date => $missing) {
          if (count($missing) > 0) {
            $wrap_up .= "\t$date: " . count($missing) . "\n";
          }
        }
      }
      $wrap_up .= "Refunds and chargebacks: $missing_negative\n";
      if ($missing_negative > 0) {
        foreach ($remaining['negative'] as $date => $missing) {
          if (count($missing) > 0) {
            $wrap_up .= "\t$date: " . count($missing) . "\n";
          }
        }
      }
      $wrap_up .= "Recurring donations: $missing_recurring\n";
      if ($missing_recurring > 0) {
        foreach ($remaining['recurring'] as $date => $missing) {
          if (count($missing) > 0) {
            $wrap_up .= "\t$date: " . count($missing) . "\n";
          }
        }
      }

      $wrap_up .= "Transaction IDs:\n";
      foreach ($remaining as $group => $transactions) {
        foreach ($transactions as $date => $missing) {
          foreach ($missing as $transaction) {
            $wrap_up .= "\t" . WmfTransaction::from_message($transaction)
                ->get_unique_id() . "\n";
          }
        }
      }

      $wrap_up .= 'Initial stats on recon files: ' . print_r($recon_file_stats, TRUE) . "\n";
    }

    wmf_audit_echo($wrap_up);
  }

  /**
   * Returns an array of the full paths to all valid reconciliation files,
   * sorted in chronological order.
   *
   * @return array Full paths to all recon files
   */
  protected function get_all_recon_files() {
    $files_directory = $this->get_recon_dir();
    //foreach file in the directory, if it matches our pattern, add it to the array.
    $files_by_sort_key = [];
    if ($handle = opendir($files_directory)) {
      while (($file = readdir($handle)) !== FALSE) {
        // ignore hidden files
        if (substr($file, 0, 1) == '.') {
          continue;
        }
        if ($this->get_filetype($file) === 'recon') {
          $sort_key = $this->get_recon_file_sort_key($file); // report date or sequence number or something
          $files_by_sort_key[$sort_key][] = $files_directory . '/' . $file;
        }
      }
      closedir($handle);
      ksort($files_by_sort_key);
      // now flatten it
      $files = [];
      foreach ($files_by_sort_key as $key => $key_files) {
        $files = array_merge($files, $key_files);
      }
      return $files;
    }
    else {
      //can't open the directory at all. Problem.
      wmf_audit_log_error("Can't open directory $files_directory", 'FILE_DIR_MISSING'); //should be fatal
    }
    return FALSE;
  }

  /**
   * Go transaction hunting in the payments logs. This is by far the most
   * processor-intensive part, but we have some timesaving new near-givens to
   * work with. Things to remember: The date on the payments log, probably
   * doesn't contain much of that actual date. It's going to be the previous
   * day, mostly. Also, remember logrotate exists, so it might be the next day
   * before we get the payments log we would be most interested in today.
   *
   * @param array $missing_by_date An array of all the missing transactions we
   * have pulled out of the nightlies, indexed by the standard WMF date format.
   *
   * @return mixed An array of transactions we couldn't find or deal with (by
   * date), or false on error
   */
  protected function log_hunt_and_send($missing_by_date) {

    if (empty($missing_by_date)) {
      wmf_audit_echo(__FUNCTION__ . ': No missing transactions sent to this function. Aborting.');
      return FALSE;
    }

    ksort($missing_by_date);

    //output the initial counts for each index...
    $earliest = NULL;
    $latest = NULL;
    foreach ($missing_by_date as $date => $data) {
      if (is_null($earliest)) {
        $earliest = $date;
      }
      $latest = $date;
      wmf_audit_echo($date . " : " . count($data));
    }
    wmf_audit_echo("\n");

    //REMEMBER: Log date is a liar!
    //Stepping backwards, log date really means "Now you have all the data for
    //this date, and some from the previous."
    //Stepping forwards, it means "Now you have all the data from the previous
    //date, and some from the next."
    //
    //Come up with the full range of logs to grab
    //go back the number of days we have configured to search in the past for the
    //current gateway
    $earliest = wmf_common_date_add_days($earliest, -1 * $this->get_log_days_in_past());

    //and add one to the latest to compensate for logrotate... unless that's the future.
    $today = wmf_common_date_get_today_string();
    $latest = wmf_common_date_add_days($latest, 1);
    if ($today < $latest) {
      $latest = $today;
    }

    //Correct for the date gap function being exclusive on the starting date param
    //More explain above.
    $earliest -= 1;

    //get the array of all the logs we want to check
    $logs_to_grab = wmf_common_date_get_date_gap($earliest, $latest);

    if (empty($logs_to_grab)) {
      wmf_audit_log_error(__FUNCTION__ . ': No logs identified as grabbable. Aborting.', 'RUNTIME_ERROR');
      return FALSE;
    }

    //want the latest first, from now on.
    rsort($logs_to_grab);
    krsort($missing_by_date);

    //Foreach log by date DESC, check all the transactions we are missing that might possibly be in this log.
    //This is going to look a little funny, because the logs are date-named and stamped after the day they rotate; Not after the dates for all the data in them.
    //As such, they mostly contain data for the previous day (but not exclusively, and not all of it)
    $tryme = [];
    foreach ($logs_to_grab as $log_date) {
      //Add to the pool of what's possible to find in this log, as we step backward through the log dates.
      //If the log date is less than or equal to the date on the transaction
      //(which may or may not be when it was initiated, but that's the past-iest
      //option), and it hasn't already been added to the pool, add it to the pool.
      //As we're stepping backward, we should look for transactions that come
      //from the current log date, or the one before.
      foreach ($missing_by_date as $date => $data) {
        if (wmf_common_date_add_days($date, 1) >= $log_date) {
          if (!array_key_exists($date, $tryme)) {
            wmf_audit_echo("Adding date $date to the date pool for log date $log_date");
            $tryme[$date] = $data;
          }
        }
        else {
          break;
        }
      }

      //log something sensible out for what we're about to do
      $display_dates = [];
      if (!empty($tryme)) {
        foreach ($tryme as $date => $thing) {
          if (count($thing) > 0) {
            $display_dates[$date] = count($thing);
          }
        }
      }
      $log = FALSE;
      if (!empty($display_dates)) {
        $message = "Checking log $log_date for missing transactions that came in with the following dates: ";
        foreach ($display_dates as $display_date => $local_count) {
          $message .= "\n\t$display_date : $local_count";
        }
        wmf_audit_echo($message);

        //now actually check the log from $log_date, for the missing transactions in $tryme
        // Get the prepped log with the current date, returning false if it's not there.
        $log = $this->get_log_by_date($log_date);
      }

      if ($log) {
        //check to see if the missing transactions we're trying now, are in there.
        //Echochar with results for each one.
        foreach ($tryme as $date => $missing) {
          if (!empty($missing)) {
            wmf_audit_echo("Log Date: $log_date: About to check " . count($missing) . " missing transactions from $date", TRUE);
            $checked = 0;
            $found = 0;
            foreach ($missing as $id => $transaction) {
              $checked += 1;
              //reset vars used below, for extra safety
              $order_id = FALSE;
              $data = FALSE;
              $all_data = FALSE;
              $contribution_tracking_data = FALSE;
              try {
                $order_id = $this->get_order_id($transaction);
                if (!$order_id) {
                  throw new WmfException(
                    'MISSING_MANDATORY_DATA',
                    'Could not get an order id for the following transaction ' . print_r($transaction, TRUE)
                  );
                }
                $data = $this->get_log_data_by_order_id($order_id, $log, $transaction);

                if (!$data) {
                  //no data found in this log, which is expected and normal and not a problem.
                  wmf_audit_echo('.');
                  continue;
                }
                $data['order_id'] = $order_id;
                //if we have data at this point, it means we have a match in the logs
                $found += 1;

                $all_data = $this->merge_data($data, $transaction);
                //lookup contribution_tracking data, and fill it in with audit markers if there's nothing there.
                $contribution_tracking_data = wmf_audit_get_contribution_tracking_data($all_data);

                if (!$contribution_tracking_data) {
                  throw new WmfException(
                    'MISSING_MANDATORY_DATA',
                    'No contribution tracking data retrieved for transaction ' . print_r($all_data, TRUE)
                  );
                }

                //Now that we've made it this far: Easy check to make sure we're even looking at the right thing...
                //I'm not totally sure this is going to be the right thing to do, though. Intended fragility.
                if (!$this->get_runtime_options('fakedb')) {
                  $method = $all_data['payment_method'];
                  // FIXME: should deal better with recurring. For now, we only
                  // get initial recurring records for GlobalCollect via these
                  // parsers, and we can treat those almost the same as one-time
                  // donations, just with 'recurring' => 1 in the message.
                  if (!empty($all_data['recurring'])) {
                    // Limit the bandaid to ONLY deal with first installments
                    if (!empty($all_data['installment']) && $all_data['installment'] > 1) {
                      throw new WmfException(
                        'INVALID_RECURRING',
                        "Audit parser found recurring order $order_id with installment {$all_data['installment']}"
                      );
                    }
                    $method = 'r' . $method;
                  }
                  if ((!empty($contribution_tracking_data['utm_payment_method'])) &&
                    ($contribution_tracking_data['utm_payment_method'] !== $method)) {

                    $message = 'Payment method mismatch between utm tracking data(' . $contribution_tracking_data['utm_payment_method'];
                    $message .= ') and normalized log and recon data(' . $method . '). Investigation required.';
                    throw new WmfException(
                      'DATA_INCONSISTENT',
                      $message
                    );
                  }
                }
                unset($contribution_tracking_data['utm_payment_method']);
                // On the next line, the date field from all_data will win, which we totally want.
                // I had thought we'd prefer the contribution tracking date, but that's just silly.
                // However, I'd just like to point out that it would be terribly enlightening for some gateways to log the difference...
                // ...but not inside the char block, because it'll break the pretty.
                $all_data = array_merge($contribution_tracking_data, $all_data);

                //Send to queue. Or somewhere. Or don't (if it's test mode).
                wmf_audit_send_transaction($all_data, 'main');
                unset($tryme[$date][$id]);
                wmf_audit_echo('!');
              } catch (WmfException $ex) {
                // End of the transaction search/destroy loop. If we're here and have
                // an error, we found something and the re-fusion didn't work.
                // Handle consistently, and definitely don't try looking in other
                // logs.
                wmf_audit_log_error($ex->getMessage(), $ex->getErrorName());
                unset($tryme[$date][$id]);
                wmf_audit_echo('X');
              }
            }
            wmf_audit_echo("Log Date: $log_date: Checked $checked missing transactions from $date, and found $found\n");
          }
        }
      }
    }

    //That loop has been stepping back in to the past. So, use what we have...
    wmf_audit_remove_old_logs($log_date, $this->read_working_logs_dir());

    //if we are running in makemissing mode: make the missing transactions.
    if ($this->get_runtime_options('makemissing')) {
      $missing_count = wmf_audit_count_missing($tryme);
      if ($missing_count === 0) {
        wmf_audit_echo('No further missing transactions to make.');
      }
      else {
        //today minus three. Again: The three is because Shut Up.
        wmf_audit_echo("Making up to $missing_count missing transactions:");
        $made = 0;
        $cutoff = wmf_common_date_add_days(wmf_common_date_get_today_string(), -3);
        foreach ($tryme as $date => $missing) {
          if ((int) $date <= (int) $cutoff) {
            foreach ($missing as $id => $message) {
              if (empty($message['contribution_tracking_id'])) {
                $contribution_tracking_data = wmf_audit_make_contribution_tracking_data($message);
                $all_data = array_merge($message, $contribution_tracking_data);
              }
              else {
                $all_data = $message;
              }
              $sendme = $this->normalize_partial($all_data);
              wmf_audit_send_transaction($sendme, 'main');
              $made += 1;
              wmf_audit_echo('!');
              unset($tryme[$date][$id]);
            }
          }
        }
        wmf_audit_echo("Made $made missing transactions\n");
      }
    }

    return $tryme; //this will contain whatever's left, if we haven't errored out at this point
  }

  /**
   * Both groom and return a distilled working payments log ready to be searched
   * for missing transaction data
   *
   * @param string $date The date of the log we want to grab
   */
  protected function get_log_by_date($date) {
    //Could be distilled already.
    //Could be either in .gz format in the archive
    //check for the distilled version first
    //check the local static cache to see if the file we want is available in distilled format.

    if (is_null($this->ready_files)) {
      $this->ready_files = $this->read_working_logs_dir();
    }

    //simple case: It's already ready, or none are ready
    if (!is_null($this->ready_files) && array_key_exists($date, $this->ready_files)) {
      return $this->ready_files[$date];
    }

    //This date is not ready yet. Get the zipped version from the archive, unzip
    //to the working directory, and distill.
    $compressed_filename = $this->get_compressed_log_file_name($date);
    $full_archive_path = wmf_audit_get_log_archive_dir() . '/' . $compressed_filename;
    $working_directory = $this->get_working_log_dir();
    $cleanup = []; //add files we want to make sure aren't there anymore when we're done here.
    if (file_exists($full_archive_path)) {
      wmf_audit_echo("Retrieving $full_archive_path");
      $cmd = "cp $full_archive_path " . $working_directory;
      exec(escapeshellcmd($cmd), $ret, $errorlevel);
      $full_compressed_path = $working_directory . '/' . $compressed_filename;
      if (!file_exists($full_compressed_path)) {
        wmf_audit_log_error("FILE PROBLEM: Trying to get log archives, and something went wrong with $cmd", 'FILE_MOVE');
        return FALSE;
      }
      else {
        $cleanup[] = $full_compressed_path;
      }
      //uncompress
      wmf_audit_echo("Gunzipping $full_compressed_path");
      $cmd = "gunzip -f $full_compressed_path";
      exec(escapeshellcmd($cmd), $ret, $errorlevel);
      //now check to make sure the file you expect, actually exists
      $uncompressed_file = $this->get_uncompressed_log_file_name($date);
      $full_uncompressed_path = $working_directory . '/' . $uncompressed_file;
      if (!file_exists($full_uncompressed_path)) {
        wmf_audit_log_error("FILE PROBLEM: Something went wrong with uncompressing logs: $cmd : $full_uncompressed_path doesn't exist.", 'FILE_UNCOMPRESS');
      }
      else {
        $cleanup[] = $full_uncompressed_path;
      }

      //distill & cache locally
      $distilled_file = $this->get_working_log_file_name($date);
      $full_distilled_path = $working_directory . $distilled_file;
      //Can't escape the hard-coded string we're grepping for, because it breaks terribly.
      $cmd = "grep '" . $this->get_log_distilling_grep_string() . "' " . escapeshellcmd($full_uncompressed_path) . " > " . escapeshellcmd($full_distilled_path);

      wmf_audit_echo($cmd);
      $ret = [];
      exec($cmd, $ret, $errorlevel);
      chmod($full_distilled_path, 0770);
      $this->ready_files[$date] = $full_distilled_path;

      //clean up
      if (!empty($cleanup)) {
        foreach ($cleanup as $deleteme) {
          if (file_exists($deleteme)) {
            unlink($deleteme);
          }
        }
      }

      //return
      return $full_distilled_path;
    }

    //this happens if the archive file doesn't exist. Definitely not the end of the world, but we should probably log about it.
    wmf_audit_log_error("Archive file $full_archive_path seems not to exist\n", 'MISSING_PAYMENTS_LOG');
    return FALSE;
  }

  /**
   * Construct an array of all the distilled working logs we have in the working
   * directory.
   *
   * @return array Array of date => full path to file for all distilled working
   * logs
   */
  protected function read_working_logs_dir() {
    $working_logs = [];
    $working_dir = $this->get_working_log_dir();
    //do the directory read and cache the results in the static
    if (!$handle = opendir($working_dir)) {
      throw new Exception(__FUNCTION__ . ": Can't open directory. We should have noticed earlier (in setup_required_directories) that this wasn't going to work. \n");
    }
    while (($file = readdir($handle)) !== FALSE) {
      $temp_date = FALSE;
      if ($this->get_filetype($file) === 'working_log') {
        $full_path = $working_dir . '/' . $file;
        $temp_date = $this->get_working_log_file_date($file);
      }
      if (!$temp_date) {
        continue;
      }
      $working_logs[$temp_date] = $full_path;
    }
    return $working_logs;
  }

  /**
   * Figures out what type of file you've got there, according to what the
   * gateway module has defined in its _regex_for_ functions.
   *
   * @param string $file Full path to the file of interest
   *
   * @return mixed 'recon'|'working_log'|'uncompressed_log'|'compressed_log'|false
   *   if it's nothing the gateway recognizes.
   */
  protected function get_filetype($file) {
    //we have three types of files, right? compressed, uncompressed, distilled, and recon file.
    //...four. Four types.

    $types = [
      'recon',
      'working_log',
      'uncompressed_log',
      'compressed_log',
    ];

    foreach ($types as $type) {
      $function_name = 'regex_for_' . $type;
      // TODO: map rather than functions
      if (preg_match($this->$function_name(), $file)) {
        return $type;
      }
    }

    return FALSE;
  }

  /**
   * Moves recon files to the completed directory. This should probably only be
   * done at the beginning of a run: If we're running in queue flood mode, we
   * don't know if the data will actually make it all the way in.
   *
   * @param string $file Full path to the file we want to move off
   *
   * @return boolean true on success, otherwise false
   */
  protected function move_completed_recon_file($file) {
    $files_directory = $this->get_recon_completed_dir();
    $completed_dir = $files_directory;
    if (!is_dir($completed_dir)) {
      if (!mkdir($completed_dir, 0770)) {
        $message = "Could not make $completed_dir";
        wmf_audit_log_error($message, 'FILE_PERMS');
        return FALSE;
      }
    }

    $filename = basename($file);
    $newfile = $completed_dir . '/' . $filename;

    if (!rename($file, $newfile)) {
      $message = "Unable to move $file to $newfile";

      wmf_audit_log_error($message, 'FILE_PERMS');
      return FALSE;
    }
    else {
      chmod($newfile, 0770);
    }
    wmf_audit_echo("Moved $file to $newfile");
    return TRUE;
  }

  /**
   * Make sure all the directories we need are there.
   *
   * @return boolean true on success, otherwise false
   */
  protected function setup_required_directories() {
    $directories = [
      'log_archive' => wmf_audit_get_log_archive_dir(),
      'recon' => $this->get_recon_dir(),
      'log_working' => $this->get_working_log_dir(),
      'recon_completed' => $this->get_recon_completed_dir(),
    ];

    foreach ($directories as $id => $dir) {
      if (!is_dir($dir)) {
        if ($id === 'log_archive' || $id === 'recon') {
          //already done. We don't want to try to create these, because required files come from here.
          wmf_audit_log_error("Missing required directory $dir", 'MISSING_DIR_' . strtoupper($id));
          return FALSE;
        }

        if (!mkdir($dir, 0770)) {
          wmf_audit_log_error("Missing and could not create required directory $dir", 'MISSING_DIR_' . strtoupper($id));
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Check the database to see if we have already recorded the transactions
   * present in the recon files.
   *
   * @param array $transactions An array of transactions we have already parsed
   * out from the recon files.
   *
   * @return mixed An array of transactions that are not in the database
   *   already, or false if something goes wrong enough
   */
  protected function get_missing_transactions($transactions) {
    if (empty($transactions)) {
      wmf_audit_echo(__FUNCTION__ . ': No transactions to find. Returning.');
      return FALSE;
    }
    //go through the transactions and check to see if they're in civi
    //@TODO: RECURRING. Won't matter for WP initially, though, so I'm leaving that for the WX integration phase.
    $missing = [
      'main' => [],
      'negative' => [],
      'recurring' => [],
    ];
    foreach ($transactions as $transaction) {
      if (
        $this->record_is_refund($transaction) ||
        $this->record_is_chargeback($transaction) ||
        $this->record_is_cancel($transaction)
      ) { //negative
        $transaction = $this->pre_process_refund($transaction);
        if ($this->negative_transaction_exists_in_civi($transaction) === FALSE) {
          wmf_audit_echo('-'); //add a subtraction. I am the helpfulest comment ever.
          $missing['negative'][] = $transaction;
        }
        else {
          wmf_audit_echo('.');
        }
      }
      else { //normal type
        if ($this->main_transaction_exists_in_civi($transaction) === FALSE) {
          wmf_audit_echo('!');
          $missing['main'][] = $transaction;
        }
        else {
          wmf_audit_echo('.');
        }
      }
    }
    return $missing;
  }

  /**
   * Visualization helper. Returns the character we want to display for the kind
   * of transaction we have just parsed out of a recon file.
   *
   * @param array $record A single transaction from a recon file
   *
   * @return string A single char to display in the char block.
   */
  protected function audit_echochar($record) {

    if ($this->record_is_refund($record)) {
      return 'r';
    }

    if ($this->record_is_chargeback($record)) {
      return 'b';
    }

    if ($this->record_is_cancel($record)) {
      return 'x';
    }

    switch ($record['payment_method']) {
      case 'cc':
        return 'c';
      case 'bt':
      case 'rtbt':
      case 'obt':
        return 't';
      case 'cash':
        return 'h';
      case 'amazon':
        return 'a';
      case 'paypal':
        return 'p';
      case 'dd':
        return 'd';
      case 'check':
      case 'stock':
        return 'k';
      case 'ew':
        return 'w';
    }
    if ($record) {
      echo print_r($record, TRUE);
    }
    wmf_audit_log_error(
      __FUNCTION__ . " Appeareth a payment_method hitherto unknown...",
      'DATA_WEIRD'
    );
    return '?';
  }

  /**
   * Returns supplemental data for the relevant order id from the specified
   * payments log, if there is any in there. This is the only sure place we can
   * catch data we didn't save, as we're basically grepping for the exact data
   * we sent to the gateway. It should be noted that this function makes no
   * attempt to normalize the data. If this log doesn't contain data for the
   * order_id in question, return false.
   *
   * @param string $order_id The order id (transaction id) of the missing
   *   payment
   * @param string $log The full path to the log we want to search
   * @param $audit_data array the data from the audit file.
   *
   * @return array|bool The data we sent to the gateway for that order id, or
   * false if we can't find it there.
   */
  protected function get_log_data_by_order_id($order_id, $log, $audit_data) {
    if (!$order_id) {
      return FALSE;
    }

    $cmd = 'grep \'' . $this->get_log_line_grep_string($order_id) . '\' ' . $log;
    wmf_audit_echo(__FUNCTION__ . ' ' . $cmd, TRUE);

    $ret = [];
    exec($cmd, $ret, $errorlevel);

    if (count($ret) > 0) {

      //In this wonderful new world, we only expect one line.
      if (count($ret) > 1) {
        wmf_audit_echo("Odd: More than one logline returned for $order_id. Investigation Required.");
      }
      $raw_data = [];

      // Get a log line that is consistent with the data from the audit file
      // Count backwards, because we used to only take the last one.
      foreach (array_reverse($ret) as $line) {
        // $linedata for *everything* from payments goes Month, day, time, box, bucket, CTID:OID, absolute madness with lots of unpredictable spaces.
        // Hack: logs space-pad single digit days, so we collapse all repeated spaces
        $unspaced = preg_replace('/ +/', ' ', $line);
        $linedata = explode(' ', $unspaced);
        $contribution_id = explode(':', $linedata[5]);
        $contribution_id = $contribution_id[0];

        $raw_data = $this->parse_log_line($line);

        if (empty($raw_data)) {
          wmf_audit_log_error(
            "We found a transaction in the logs for $order_id, but there's nothing left after we tried to grab its data. Investigation required.",
            'DATA_WEIRD'
          );
        }
        else {
          if ($this->check_consistency($raw_data, $audit_data)) {
            $raw_data['contribution_tracking_id'] = $contribution_id;
            return $raw_data;
          }
        }
      }
      // We have log data, but nothing matches. This is too weird.
      throw new WmfException(
        'DATA_INCONSISTENT',
        'Inconsistent data. Skipping the following: ' . print_r($audit_data, TRUE) . "\n" . print_r($raw_data, TRUE)
      );
    }
    return FALSE; //no big deal, it just wasn't there. This will happen most of the time.
  }

  protected function parse_json_log_line($line) {
    $matches = [];
    if (!preg_match('/[^{]*([{].*)/', $line, $matches)) {
      throw new WmfException(
        'MISSING_MANDATORY_DATA',
        "JSON data not found in $line"
      );
    }
    $log_data = json_decode($matches[1], TRUE);
    if (!$log_data) {
      throw new WmfException(
        'MISSING_MANDATORY_DATA',
        "Could not parse JSON data in $line"
      );
    }
    // Translate and filter field names
    // TODO: we're phasing out the non-standard front-end names
    // We can reduce this to a filter a month or so after that.
    $map = [
      'amount' => 'gross',
      'country' => 'country',
      'currency' => 'currency',
      'currency_code' => 'currency',
      'email' => 'email',
      'first_name' => 'first_name',
      'fname' => 'first_name',
      'gateway' => 'gateway',
      'gateway_account' => 'gateway_account',
      'gross' => 'gross',
      'language' => 'language',
      'last_name' => 'last_name',
      'lname' => 'last_name',
      'payment_method' => 'payment_method',
      'payment_submethod' => 'payment_submethod',
      'user_ip' => 'user_ip',
      'utm_campaign' => 'utm_campaign',
      'utm_medium' => 'utm_medium',
      'utm_source' => 'utm_source',
    ];
    $normal = [];
    foreach ($map as $logName => $normalName) {
      if (isset($log_data[$logName])) {
        $normal[$normalName] = $log_data[$logName];
      }
    }
    return $normal;
  }

  /**
   * Grabs the positive transaction id associated with a transaction.
   *
   * @param array $transaction possibly incomplete set of transaction data
   *
   * @return string|false the order_id, or false if we can't figure it out
   */
  protected function get_parent_order_id($transaction) {
    if (is_array($transaction) && array_key_exists('gateway_parent_id', $transaction)) {
      return $transaction['gateway_parent_id'];
    }
    if (is_array($transaction) && array_key_exists('gateway_txn_id', $transaction)) {
      return $transaction['gateway_txn_id'];
    }
    return FALSE;
  }

  /**
   * Grabs just the order_id out of a $transaction. Override if the
   * parser doesn't normalize.
   *
   * @param array $transaction possibly incomplete set of transaction data
   *
   * @return string|false the order_id, or false if we can't figure it out
   */
  protected function get_order_id($transaction) {
    if (is_array($transaction) && array_key_exists('gateway_txn_id', $transaction)) {
      return $transaction['gateway_txn_id'];
    }
    return FALSE;
  }

  /**
   * Just parse one recon file.
   *
   * @param string $file Absolute location of the recon file you want to parse
   *
   * @return mixed An array of recon data, or false
   */
  protected function parse_recon_file($file) {
    $recon_data = [];
    $recon_parser = $this->get_audit_parser();

    try {
      $recon_data = $recon_parser->parseFile($file);
    } catch (Exception $e) {
      wmf_audit_log_error(
        "Something went amiss with the recon parser while "
        . "processing $file: \"{$e->getMessage()}\""
        , 'RECON_PARSE_ERROR'
      );
    }

    //At this point, $recon_data already contains the usable portions of the file.

    if (!empty($recon_data)) {
      foreach ($recon_data as $record) {
        wmf_audit_echo($this->audit_echochar($record));
      }
    }
    if (count($recon_data)) {
      return $recon_data;
    }
    return FALSE;
  }

  protected function getGatewayIdFromTracking($record = []) {
    $tracking = wmf_civicrm_get_contribution_tracking($record);
    if (empty($tracking['contribution_id'])) {
      return NULL;
    }
    $contributions = wmf_civicrm_get_contributions_from_contribution_id(
      $tracking['contribution_id']
    );
    if (empty($contributions)) {
      return NULL;
    }
    return $contributions[0]['gateway_txn_id'];
  }

  /**
   * Override this if your gateway's audit process needs to do things
   * with refunds that can't be done in the file parser.
   *
   * @param array $transaction
   *
   * @return array
   */
  protected function pre_process_refund($transaction) {
    return $transaction;
  }
}
