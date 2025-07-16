<?php

namespace Civi\WMFAudit;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionTracking;
use SmashPig\Core\DataStores\QueueWrapper;
use Civi\WMFException\WMFException;
use Civi\WMFTransaction;

abstract class BaseAuditProcessor {
  /**
   * @var int
   *   number of days of log to search in before transaction date
   */
  const LOG_SEARCH_WINDOW = 30;

  protected $options;

  protected $name;

  protected $ready_files;

  private array $missingTransactions = [];

  private array $statistics = ['total_records' => 0, 'total_missing' => 0];

  private array $timings = [];

  protected $cutoff = -3;

  /**
   * Number of file to parse per run, absent any incoming parameter.
   *
   * Note that 0 is equivalent to all or no limit.
   *
   * @var int
   */
  protected int $fileLimit = 3;

  public function __construct($options) {
    $this->options = $options;
    if (is_numeric($options['file_limit'])) {
      $this->fileLimit = (int) $options['file_limit'];
    }
    \Civi::$statics['wmf_audit_runtime'] = $options;
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
      $this->logError(__FUNCTION__ . $message, 'DATA_WEIRD');
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
          $this->logError("Data mismatch between log and audit $field ({$log_data[$field]} != {$audit_file_data[$field]}). Investigation required. " . print_r($audit_file_data, TRUE), 'DATA_INCONSISTENT');
          return FALSE;
        }
      }
      else {
        $this->logError("Audit data is expecting $field but at least one is missing. Investigation required. " . print_r($audit_file_data, TRUE), 'DATA_INCONSISTENT');
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Logs the errors we get in a consistent way
   *
   * @param string $message The message we want to log. Should be
   * descriptive enough that we can bug hunt without having to go all cowboy in
   * prod.
   * @param string $drush_code If this code is fatal (According to
   * wmf_audit_error_isfatal), this will result in the whole script dying.
   */
  protected function logError($message, $drush_code) {
    \Civi::log('wmf')
      ->error($this->name . '_audit: {message}',
        ['message' => $message]);

    //Maybe explode
    //All of these "nonfatal" things are meant to be nonfatal to the *job*, and
    //not nonfatal to the contribution itself. We hit one of these,
    //the contribution will be skipped, and we move to the next one.
    //ALL OTHER CODES will cause the process to come to a screeching halt.
    $nonfatal = [
      'DATA_INCONSISTENT',
      'DATA_INCOMPLETE',
      'DATA_WEIRD',
      'MISSING_PAYMENTS_LOG',
      'MISSING_MANDATORY_DATA',
      'UTM_DATA_MISMATCH',
      'NORMALIZE_DATA',
    ];
    if (!in_array($drush_code, $nonfatal)) {
      die("\n*** Fatal Error $drush_code: $message");
    }
  }

  /**
   * Counts the missing transactions in the main array of missing transactions.
   * This is annoying and needed its own function, because the $missing array goes
   * $missing[$type]=> array of transactions.
   * Naturally, we're not checking to see that $type is one of the big three that
   * we expect, so it's possible to use this badly. So, don't.
   *
   * @param array $missing An array of missing transactions by type.
   *
   * @return int The total missing transactions in a missing transaction array
   */
  protected function countMissing($missing) {
    $count = 0;
    if (!is_array($missing) || empty($missing)) {
      return 0;
    }
    foreach ($missing as $type => $data) {
      $count += count($missing[$type]);
    }
    return $count;
  }

  /**
   * A slight twist on array_merge - don't overwrite non-blank data with blank strings.
   *
   * @param array $log_data
   * @param array $audit_file_data
   *
   * @return array
   */
  protected function merge_data($log_data, $audit_file_data) {
    $merged = $audit_file_data;
    foreach ($log_data as $key => $value) {
      $absentFromMerged = !array_key_exists($key, $merged);
      $logDataNotBlank = ($value !== '');
      if ($absentFromMerged || $logDataNotBlank || $merged[$key] === '') {
        $merged[$key] = $value;
      }
    }

    return $merged;
  }

  /**
   * Returns the configurable path to the recon files
   *
   * @return string Path to the directory
   */
  protected function getIncomingFilesDirectory(): string {
    return \Civi::settings()->get('wmf_audit_directory_audit') . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR . 'incoming' . DIRECTORY_SEPARATOR;
  }

  /**
   * Returns the configurable path to the completed recon files
   *
   * @return string Path to the directory
   */
  protected function getCompletedFilesDirectory(): string {
    return \Civi::settings()->get('wmf_audit_directory_audit') . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR . 'completed' . DIRECTORY_SEPARATOR;
  }

  /**
   * Returns the configurable path to the working log dir, or false if not needed.
   *
   * @return string Path to the directory
   */
  protected function getWorkingLogDirectory(): string {
    return \Civi::settings()->get('wmf_audit_directory_working_log') . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR;
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
   * Returns the configurable number of days we want to jump back in to the
   * past, to look for transactions in the payments logs.
   *
   * @return int Number of days
   */
  protected function get_log_days_in_past(): int {
    if (isset($this->options['log_search_past_days'])) {
      $this->echo('got log_search_past_days from command line: ') . $this->options['log_search_past_days'] ?? '';
    }
    else {
      $this->echo('defaulting to 7 days');
    }
    return (int) ($this->options['log_search_past_days'] ?? 7);
  }

  /**
   * Wrapper for echo
   * Lets us switch on things we only want to see in verbose mode.
   *
   * @param string $string The thing you want to echo. Single chars will be added to
   * the current line, while longer strings will get their own new line.
   * @param boolean $verbose If true, this message will only appear when we are
   * running in verbose mode. The verbose option is set at the command line.
   */
  protected function echo(string $string, bool $verbose = FALSE): void {
    if ($verbose) {
      \Civi::log('wmf')->info($string);
    }
    else {
      \Civi::log('wmf')->notice($string);
    }
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
   * Get the names of compressed log files based on the supplied date.
   * Needs to return the same number of entries as the next two fns.
   *
   * @param string $date date in YYYYMMDD format
   *
   * @return string[] Names of the files we're looking for
   */
  protected function get_compressed_log_file_names($date) {
    // payments-worldpay-20140413.gz
    return ["payments-{$this->name}-{$date}.gz"];
  }

  /**
   * Get the names of uncompressed log files based on the supplied date.
   * Needs to return the same number of entries as the above and below fns.
   *
   * @param string $date date in YYYYMMDD format
   *
   * @return string[] Names of the files we're looking for
   */
  protected function get_uncompressed_log_file_names($date) {
    // payments-worldpay-20140413 - no extension. Weird.
    return ["payments-{$this->name}-{$date}"];
  }

  /**
   * Get the names of working log files based on the supplied date.
   * Needs to return the same number of entries as the last two fns.
   *
   * @param string $date date in YYYYMMDD format
   *
   * @return string[] Names of the files we're looking for
   */
  protected function get_working_log_file_names($date) {
    // '/\d{8}_worldpay\.working/';
    return ["{$date}_{$this->name}.working"];
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
      //date format defined in wmf_dates
      return date('Ymd', $record['date']);
    }

    echo print_r($record, TRUE);
    throw new \Exception(__FUNCTION__ . ': No date present in the record. This seems like a problem.');
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
      //Notes from a previous version: "after intense deliberation, we don't actually care what this is at all."
      $send_message['gateway_refund_id'] = $record['gateway_txn_id'];
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
      $send_message['gross_currency'] = $record['currency'];
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
   * Override if the parser doesn't normalize.
   *
   * @param array $transaction Array of donation data
   *
   * @return boolean true if it's in there, otherwise false
   */
  protected function main_transaction_exists_in_civi($transaction) {
    $positive_txn_id = $this->get_parent_order_id($transaction);
    $gateway = $transaction['gateway'];
    //go through the transactions and check to see if they're in civi
    if ($this->getContributions($gateway, $positive_txn_id) === FALSE) {
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

    $contributions = $this->getContributions($gateway, $positive_txn_id);
    if (!$contributions) {
      return FALSE;
    }
    return \CRM_Contribute_BAO_Contribution::isContributionStatusNegative(
      $contributions[0]['contribution_status_id']
    );
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
   *
   * @throws \Exception
   */
  public function run() {

    //make sure all the things we need are there.
    if (!$this->setup_required_directories()) {
      throw new \Exception('Missing required directories');
    }

    $recon_file_stats = [];
    foreach ($this->getReconciliationFiles() as $file) {
      //parse the recon files into something relatively reasonable.
      $this->statistics[$file] = ['main' => ['found' => 0, 'missing' => 0, 'total' => 0, 'by_payment' => []], 'cancel' => ['found' => 0, 'missing' => 0, 'total' => 0, 'by_payment' => []], 'chargeback' => ['found' => 0, 'missing' => 0, 'total' => 0, 'by_payment' => []], 'refund' => ['found' => 0, 'missing' => 0, 'total' => 0, 'by_payment' => []]];
      $parsed = $this->parseReconciliationFile($file);

      //remove transactions we already know about
      $this->startTiming(' get missing on ' . $file);
      $missing = $this->getMissingTransactions($parsed, $file);

      $recon_file_stats[$file] = $this->getFileStatistic($file, 'total_missing');
      $time = $this->stopTiming(' get missing on ' . $file);
      $this->echo($this->countMissing($missing) . ' missing transactions (of a possible ' . $this->getFileStatistic($file, 'total_records') . ") identified in $time seconds\n");

      //If the file is empty, move it off.
      // Note that we are not archiving files that have missing transactions,
      // which might be resolved below. Those are archived on the next run,
      // once we can confirm they have hit Civi and are no longer missing.
      if ($this->countMissing($missing) <= $this->get_runtime_options('recon_complete_count')) {
        $this->move_completed_recon_file($file);
      }
    }
    $this->echo($this->statistics['total_missing'] . " total missing transactions identified at start");
    $total_missing = $this->missingTransactions;

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
    $this->handleNegatives($total_missing, $remaining);

    //Wrap it up and put a bow on it.
    //@TODO much later: Make a fredge table for these things and dump some messages over there about what we just did.
    $missing_main = 0;
    $missing_negative = 0;
    $missing_recurring = 0;
    $missing_at_end = 0;
    if (is_array($remaining) && !empty($remaining)) {
      foreach ($remaining as $type => $data) {
        $count = $this->countMissing($data);
        ${'missing_' . $type} = $count;
        $missing_at_end += $count;
      }
    }
    $total_donations_found_in_log = $this->statistics['total_missing'] - $missing_at_end;
    $wrap_up = "\nDone! Final stats:\n";
    $wrap_up .= "Total number of donations in audit file: " . $this->statistics['total_records'] . "\n";
    $wrap_up .= "Number missing from database: {$this->statistics['total_missing']}\n";
    $wrap_up .= 'Missing transactions found in logs: ' . $total_donations_found_in_log . "\n";
    $wrap_up .= 'Missing transactions not found in logs: ' . $missing_at_end . "\n\n";

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

      $wrap_up .= "Missing Transaction IDs:\n";
      foreach ($remaining as $group => $transactions) {
        foreach ($transactions as $date => $missing) {
          foreach ($missing as $transaction) {
            $wrap_up .= "\t" . WMFTransaction::from_message($transaction)
              ->get_unique_id() . "\n";
          }
        }
      }

      $wrap_up .= 'Initial stats on recon files: ' . print_r($recon_file_stats, TRUE) . "\n";
    }

    $this->echo($wrap_up);
  }

  protected function handleNegatives($total_missing, &$remaining) {
    $this->echo("Processing 'negative' transactions");
    $numberProcessed = 0;
    $numberSkipped = 0;
    if (array_key_exists('negative', $total_missing) && !empty($total_missing['negative'])) {
      foreach ($total_missing['negative'] as $record) {
        //check to see if the parent exists. If it does, normalize and send.
        $parentByInvoice = [];
        $foundParent = $this->main_transaction_exists_in_civi($record);
        if (!$foundParent && !empty($record['invoice_id'])) {
          // Sometimes it's difficult to find a parent transaction by the
          // gateway-side ID, for example for Ingenico recurring refunds.
          // Try again by invoice ID.
          if (!empty($record['invoice_id'])) {
            $parentByInvoice = Contribution::get(FALSE)
              ->addClause(
                'OR',
                ['invoice_id', '=', $record['invoice_id']],
                // For recurring payments, we sometimes append a | and a random
                // number after the invoice ID
                ['invoice_id', 'LIKE', $record['invoice_id'] . '|%']
              )
              ->execute()
              ->first();
            if (!empty($parentByInvoice) && $parentByInvoice['trxn_id']) {
              // $parentByInvoice['trxn_id'] has extra information in it for example
              // RECURRING INGENICO 000000123410000010640000200001
              // Need to get just the transaction id after the processor name
              $record['gateway_parent_id'] = (WMFTransaction::from_unique_id($parentByInvoice['trxn_id']))->gateway_txn_id;
              $record['gateway_refund_id'] = $record['gateway_parent_id'];
              $foundParent = TRUE;
            }
          }
        }
        if ($foundParent) {
          if (
            count($parentByInvoice)
            && \CRM_Contribute_BAO_Contribution::isContributionStatusNegative($parentByInvoice['contribution_status_id'])
          ) {
            continue;
          }
          $normal = $this->normalize_negative($record);
          $this->send_queue_message($normal, 'negative');
          $numberProcessed += 1;
        }
        else {
          // Ignore cancels with no parents because they must have
          // been cancelled before reaching Civi.
          if (!$this->record_is_cancel($record)) {
            //@TODO: Some version of makemissing should make these, too. Gar.
            $remaining['negative'][$this->get_record_human_date($record)][] = $record;
            $numberSkipped++;
          }
        }
      }
      $this->echo("Processed $numberProcessed 'negative' transactions\n");
      $this->echo("Skipped $numberSkipped 'negative' transactions (no parent record to cancel, probably cancelled before reaching CiviCRM)\n");
    }
  }

  /**
   * Returns an array of the full paths of the files to reconcile.
   *
   * The files returned will be the latest files, sorted in chronological order, up
   * to the number of files defined in the fileLimit (if it is not 0/unlimited).
   *
   * @return array
   *   Full paths to the files to reconcile.
   */
  protected function getReconciliationFiles(): array {
    $files_directory = $this->getIncomingFilesDirectory();
    $fileFromCommandLine = $this->get_runtime_options('file');
    if ($fileFromCommandLine) {
      return [$files_directory . DIRECTORY_SEPARATOR . $fileFromCommandLine];
    }
    //foreach file in the directory, if it matches our pattern, add it to the array.
    $files_by_sort_key = [];
    if ($handle = opendir($files_directory)) {
      while (($file = readdir($handle)) !== FALSE) {
        // ignore hidden files
        if (substr($file, 0, 1) == '.') {
          continue;
        }
        if (preg_match($this->regex_for_recon(), $file)) {
          // sort the files depending on how each processor handles the file names
          // the last three files in $files_by_sort_key will be the ones looked at
          $sort_key = $this->get_recon_file_sort_key($file);
          $files_by_sort_key[$sort_key][] = $files_directory . '/' . $file;
        }
      }
      closedir($handle);
      krsort($files_by_sort_key);
      // now flatten it
      $files = [];

      foreach ($files_by_sort_key as $key_files) {
        foreach ($key_files as $file) {
          $files[] = $file;
          if ($this->fileLimit !== 0 && count($files) === $this->fileLimit) {
            // Hit the limit, that's enough.
            return $files;
          }
        }
      }
      return $files;
    }
    else {
      //can't open the directory at all. Problem.
      //should be fatal
      $this->logError("Can't open directory $files_directory", 'FILE_DIR_MISSING');
    }
    return [];
  }

  /**
   * Go transaction hunting in the payments logs. This is by far the most
   * processor-intensive part, but we have some timesaving new near-givens to
   * work with. Things to remember: The date on the payments log, probably
   * doesn't contain much of that actual date. It's going to be the previous
   * day, mostly. For some offline payment methods, the log entry might be from
   * a month or so before the posted transaction date, so we search logs up to
   * LOG_SEARCH_WINDOW days before. Also, remember logrotate exists, so it
   * might be the next day before we get the payments log we would be most
   * interested in today.
   *
   * @param array $missing_by_date An array of all the missing transactions we
   * have pulled out of the nightlies, indexed by the standard WMF date format.
   *
   * @return mixed
   *   An array of transactions we couldn't find or deal with (by date),
   *   or false on error
   */
  protected function log_hunt_and_send($missing_by_date) {
    if (empty($missing_by_date)) {
      $this->echo(__FUNCTION__ . ': No missing transactions sent to this function. Aborting.');
      return FALSE;
    }

    ksort($missing_by_date);

    //output the initial counts for each index...
    $earliest = NULL;
    $latest = NULL;
    foreach ($missing_by_date as $audit_date => $data) {
      if (is_null($earliest)) {
        $earliest = $audit_date;
      }
      $latest = $audit_date;
      $this->echo($audit_date . " : " . count($data));
    }
    $this->echo("\n");

    //REMEMBER: Log date is a liar!
    //Stepping backwards, log date really means "Now you have all the data for
    //this date, and some from the previous."
    //Stepping forwards, it means "Now you have all the data from the previous
    //date, and some from the next."
    //
    //Come up with the full range of logs to grab
    //go back the number of days we have configured to search in the past for the
    //current gateway
    $earliest = $this->dateAddDays($earliest, -1 * $this->get_log_days_in_past());

    //and add one to the latest to compensate for logrotate... unless that's the future.
    $today = $this->wmf_common_date_get_today_string();
    $latest = $this->dateAddDays($latest, 1);
    if ($today < $latest) {
      $latest = $today;
    }

    //Correct for the date gap function being exclusive on the starting date param
    //More explain above.
    $earliest -= 1;

    //get the array of all the logs we want to check
    $logs_to_grab = $this->wmf_common_date_get_date_gap($earliest, $latest);

    if (empty($logs_to_grab)) {
      $this->logError(__FUNCTION__ . ': No logs identified as grabbable. Aborting.', 'RUNTIME_ERROR');
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
      //from the current log date, or LOG_SEARCH_WINDOW days before.
      foreach ($missing_by_date as $audit_date => $data) {
        $window_end = $this->dateAddDays($audit_date, 1);
        $window_start = $this->dateAddDays($audit_date, -1 * self::LOG_SEARCH_WINDOW);
        if ($window_end >= $log_date && $window_start <= $log_date) {
          if (!array_key_exists($audit_date, $tryme)) {
            $this->echo("Adding date $audit_date to the date pool for log date $log_date");
            $tryme[$audit_date] = $data;
          }
        }
        else {
          break;
        }
      }

      //log something sensible out for what we're about to do
      $display_dates = [];
      if (!empty($tryme)) {
        foreach ($tryme as $audit_date => $thing) {
          if (count($thing) > 0) {
            $display_dates[$audit_date] = count($thing);
          }
        }
      }
      $logs = FALSE;
      if (!empty($display_dates)) {
        $message = "Checking log $log_date for missing transactions that came in with the following dates: ";
        foreach ($display_dates as $display_date => $local_count) {
          $message .= "\n\t$display_date : $local_count";
        }
        $this->echo($message);

        // now actually check the log from $log_date, for the missing transactions in $tryme
        // Get the prepped log(s) with the current date, returning false if it's not there.
        $logs = $this->get_logs_by_date($log_date);
      }

      if ($logs) {
        //check to see if the missing transactions we're trying now, are in there.
        //Echochar with results for each one.
        foreach ($tryme as $audit_date => $missing) {
          if (!empty($missing)) {
            $this->echo("Log Date: $log_date: About to check " . count($missing) . " missing transactions from $audit_date", TRUE);
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
                  throw new WMFException(
                    WMFException::MISSING_MANDATORY_DATA,
                    'Could not get an order id for the following transaction ' . print_r($transaction, TRUE)
                  );
                }
                $data = $this->get_log_data_by_order_id($order_id, $logs, $transaction);

                if (!$data) {
                  //no data found in this log, which is expected and normal and not a problem.
                  continue;
                }
                $data['order_id'] = $order_id;
                //if we have data at this point, it means we have a match in the logs
                $found += 1;

                $all_data = $this->merge_data($data, $transaction);
                //lookup contribution_tracking data, and fill it in with audit markers if there's nothing there.
                $contribution_tracking_data = $this->getContributionTrackingData($all_data);

                if (!$contribution_tracking_data) {
                  throw new WMFException(
                    WMFException::MISSING_MANDATORY_DATA,
                    'No contribution tracking data retrieved for transaction ' . print_r($all_data, TRUE)
                  );
                }

                //Now that we've made it this far: Easy check to make sure we're even looking at the right thing...
                //I'm not totally sure this is going to be the right thing to do, though. Intended fragility.
                $method = $all_data['payment_method'];
                // FIXME: should deal better with recurring. For now, we only
                // get initial recurring records for GlobalCollect via these
                // parsers, and we can treat those almost the same as one-time
                // donations, just with 'recurring' => 1 in the message.
                if (!empty($all_data['recurring'])) {
                  // Limit the bandaid to ONLY deal with first installments
                  if (!empty($all_data['installment']) && $all_data['installment'] > 1) {
                    throw new WMFException(
                      WMFException::INVALID_RECURRING,
                      "Audit parser found recurring order $order_id with installment {$all_data['installment']}"
                    );
                  }
                  $method = 'r' . $method;
                }

                unset($contribution_tracking_data['utm_payment_method']);
                // On the next line, the date field from all_data will win, which we totally want.
                // I had thought we'd prefer the contribution tracking date, but that's just silly.
                // However, I'd just like to point out that it would be terribly enlightening for some gateways to log the difference...
                // ...but not inside the char block, because it'll break the pretty.
                $all_data = array_merge($contribution_tracking_data, $all_data);

                //Send to queue.
                $this->send_queue_message($all_data, 'main');
                unset($tryme[$audit_date][$id]);
                $this->echo('!');
              }
              catch (WMFException $ex) {
                // End of the transaction search/destroy loop. If we're here and have
                // an error, we found something and the re-fusion didn't work.
                // Handle consistently, and definitely don't try looking in other
                // logs.
                $this->logError($ex->getMessage(), $ex->getErrorName());
                unset($tryme[$audit_date][$id]);
                $this->echo('X');
              }
            }
            $this->echo("Log Date: $log_date: Checked $checked missing transactions from $audit_date, and found $found\n");
          }
        }
      }
    }

    //That loop has been stepping back in to the past. So, use what we have...
    $this->removeOldLogs($log_date, $this->read_working_logs_dir());

    //if we are running in makemissing mode: make the missing transactions.
    if ($this->get_runtime_options('makemissing')) {
      $missing_count = $this->countMissing($tryme);
      if ($missing_count === 0) {
        $this->echo('No further missing transactions to make.');
      }
      else {
        //today minus three. Again: The three is because Shut Up.
        $this->echo("Making up to $missing_count missing transactions:");
        $made = 0;
        $cutoff = $this->dateAddDays($this->wmf_common_date_get_today_string(), $this->cutoff);
        foreach ($tryme as $audit_date => $missing) {
          if ((int) $audit_date <= (int) $cutoff) {
            foreach ($missing as $id => $message) {
              if (empty($message['contribution_tracking_id'])) {
                $contribution_tracking_data = $this->makeContributionTrackingData($message);
                $all_data = array_merge($message, $contribution_tracking_data);
              }
              else {
                $all_data = $message;
              }
              $sendme = $this->normalize_partial($all_data);
              if (!empty($sendme['recurring']) && $sendme['recurring'] === '1') {
                $this->send_queue_message($sendme, 'recurring');
              }
              else {
                $this->send_queue_message($sendme, 'main');
              }

              $made += 1;
              unset($tryme[$audit_date][$id]);
            }
          }
        }
        $this->echo("Made $made missing transactions\n");
      }
    }
    //this will contain whatever is left, if we haven't errored out at this point.
    return $tryme;
  }

  /**
   * Returns the contribution tracking data for $record, if we can find it.
   * If we can't find it fabricate something and send it to the queue.
   *
   * @param array $record The re-fused and normal transaction that doesn't yet
   * exist in civicrm.
   *
   * @return array
   */
  private function getContributionTrackingData($record): array {

    $contributionTrackingId = $record['contribution_tracking_id'];
    $result = ContributionTracking::get(FALSE)->addWhere('id', '=', $contributionTrackingId)->execute()->first();

    if (!$result) {
      $this->logError("Missing Contribution Tracking data. Supposed ID='$contributionTrackingId'", 'DATA_INCOMPLETE');
      $paymentMethod = $record['payment_method'] ?? '';
      $fallbackContributionTrackingData = [
        'id' => $contributionTrackingId,
        'utm_medium' => 'audit',
        'utm_source' => "audit..$paymentMethod",
        'payment_method' => $paymentMethod,
        'tracking_date' => date('Y-m-d H:i:s', $record['date'] ?? time()),
        'language' => $record['language'] ?? NULL,
        'country' => $record['country'] ?? NULL,
      ];
      QueueWrapper::push('contribution-tracking', $fallbackContributionTrackingData);
      $fallbackContributionTrackingData['date'] = $record['date'];
      $fallbackContributionTrackingData['utm_payment_method'] = $paymentMethod;
      foreach (['language', 'ts', 'id', 'payment_method'] as $unsetme) {
        unset($fallbackContributionTrackingData[$unsetme]);
      }
      return $fallbackContributionTrackingData;
    }

    $this->echo("Found Contribution Tracking data. ID='$contributionTrackingId'", TRUE);
    // Some of the normalization below has been done in the civicrm contribution tracking table
    if (!is_null($result['utm_source'])) {
      $utm_payment_method = explode('.', $result['utm_source']);
      //...sure.
      $utm_payment_method = $utm_payment_method[2];
    }
    else {
      //probably one of us silly people doing things in testing...
      $utm_payment_method = NULL;
    }

    // The audit is expecting a date in Unix timestamp format
    $result['date'] = strtotime($result['tracking_date'] ?? 'now');

    $keep = [
      'utm_source',
      'utm_medium',
      'utm_campaign',
      'language',
      'date',
    ];

    $ret = [];
    foreach ($keep as $thing) {
      $ret[$thing] = $result[$thing];
    }

    //and then, so we can double-check ourselves on the outside...
    $ret['utm_payment_method'] = $utm_payment_method;
    return $ret;
  }

  /**
   * Makes everything we need to fake contribution tracking data.
   * So: Mostly the timestamp.
   *
   * @param array $record Transaction parsed into an array
   *
   * @return array utm and date data appropriate for $record
   */
  private function makeContributionTrackingData($record) {
    $utm_stuffer = $this->name . '_audit';
    //anything we don't put in here should be handled by the universal defaults on
    //import. And that's good.
    $return = [
      'utm_source' => $record['utm_source'] ?? $utm_stuffer,
      'utm_medium' => $record['utm_medium'] ?? $utm_stuffer,
      'utm_campaign' => $record['utm_campaign'] ?? $utm_stuffer,
    ];

    if (!array_key_exists('date', $record)) {
      $this->logError(__FUNCTION__ . ": Record has no date field. Weirdness probably ensues", 'DATA_WEIRD');
    }
    else {
      $return['date'] = $record['date'];
    }

    return $return;
  }

  /**
   * Remove all distilled logs older than the oldest date ($date)
   * Not even a big deal if we overshoot and remove too many, because we'll just
   * remake them next time if they're missing.
   *
   * @param string $date The date string for the oldest log we want to keep
   * @param array $working_logs list of working log files by date
   */
  public function removeOldLogs($date, $working_logs) {
    if (!empty($working_logs)) {
      foreach ($working_logs as $logdate => $files) {
        foreach ($files as $file) {
          if ((int) $logdate < (int) $date) {
            unlink($file);
          }
        }
      }
    }
  }

  /**
   * Both groom and return a distilled working payments log ready to be searched
   * for missing transaction data
   *
   * @param string $date The date of the log we want to grab
   *
   * @return string[]|false
   *   Full paths to all logs for the given date, or false
   *   if something went wrong.
   */
  protected function get_logs_by_date($date) {
    //Could be distilled already.
    //Could be either in .gz format in the archive
    //check for the distilled version first
    //check the local static cache to see if the file we want is available in distilled format.

    if (is_null($this->ready_files)) {
      $this->ready_files = $this->read_working_logs_dir();
    }

    $compressed_filenames = $this->get_compressed_log_file_names($date);
    $uncompressed_filenames = $this->get_uncompressed_log_file_names($date);
    $distilled_filenames = $this->get_working_log_file_names($date);
    $count = count($compressed_filenames);
    if (
      $count !== count($uncompressed_filenames) ||
      $count !== count($distilled_filenames)
    ) {
      throw new WMFException(
        WMFException::UNKNOWN,
        'Bad programmer! Get_X_log_file_names return inconsistent counts'
      );
    }

    // simple case: They're already ready, or none are ready
    // When we can have multiple patterns for a day, make sure we've got all of
    // them. If we just have one of two, we'll overwrite the existing one, but
    // that's OK.
    if (
      !is_null($this->ready_files) &&
      array_key_exists($date, $this->ready_files) &&
      count($this->ready_files[$date]) == $count
    ) {
      return $this->ready_files[$date];
    }

    // This date is not ready yet. Get the zipped versions from the archive,
    // unzip to the working directory, and distill.
    $full_distilled_paths = [];
    for ($i = 0; $i < $count; $i++) {
      $compressed_filename = $compressed_filenames[$i];
      $full_archive_path = $this->getLogArchiveDirectory() . '/' . $compressed_filename;
      $working_directory = $this->getWorkingLogDirectory();
      // Add files we want to make sure aren't there anymore when we're done here.
      $cleanup = [];
      if (file_exists($full_archive_path)) {
        $this->echo("Retrieving $full_archive_path");
        $cmd = "cp $full_archive_path " . $working_directory;
        exec(escapeshellcmd($cmd), $ret, $errorlevel);
        $full_compressed_path = $working_directory . '/' . $compressed_filename;
        if (!file_exists($full_compressed_path)) {
          $this->logError("FILE PROBLEM: Trying to get log archives, and something went wrong with $cmd", 'FILE_MOVE');
          return FALSE;
        }
        else {
          $cleanup[] = $full_compressed_path;
        }
        //uncompress
        $this->echo("Gunzipping $full_compressed_path");
        $cmd = "gunzip -f $full_compressed_path";
        exec(escapeshellcmd($cmd), $ret, $errorlevel);
        //now check to make sure the file you expect, actually exists
        $uncompressed_file = $uncompressed_filenames[$i];
        $full_uncompressed_path = $working_directory . '/' . $uncompressed_file;
        if (!file_exists($full_uncompressed_path)) {
          $this->logError("FILE PROBLEM: Something went wrong with uncompressing logs: $cmd : $full_uncompressed_path doesn't exist.", 'FILE_UNCOMPRESS');
        }
        else {
          $cleanup[] = $full_uncompressed_path;
        }

        //distill & cache locally
        $distilled_file = $distilled_filenames[$i];
        $full_distilled_path = $working_directory . $distilled_file;
        //Can't escape the hard-coded string we're grepping for, because it breaks terribly.
        $cmd = "grep '" . $this->get_log_distilling_grep_string() . "' " . escapeshellcmd($full_uncompressed_path) . " > " . escapeshellcmd($full_distilled_path);

        $this->echo($cmd);
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
        $full_distilled_paths[] = $full_distilled_path;
      }
      else {
        //this happens if the archive file doesn't exist. Definitely not the end of the world, but we should probably log about it.
        $this->logError("Archive file $full_archive_path seems not to exist\n", 'MISSING_PAYMENTS_LOG');
      }
    }
    //return
    return $full_distilled_paths;
  }

  /**
   * Construct an array of all the distilled working logs we have in the working
   * directory.
   *
   * @return array
   *   Array of date => array of full paths to file for all
   *   distilled working logs
   */
  protected function read_working_logs_dir() {
    $working_logs = [];
    $working_dir = $this->getWorkingLogDirectory();
    //do the directory read and cache the results in the static
    if (!$handle = opendir($working_dir)) {
      throw new Exception(__FUNCTION__ . ": Can't open directory. We should have noticed earlier (in setup_required_directories) that this wasn't going to work. \n");
    }
    while (($file = readdir($handle)) !== FALSE) {
      $temp_date = FALSE;
      if (preg_match($this->regex_for_working_log(), $file)) {
        $full_path = $working_dir . '/' . $file;
        $temp_date = $this->get_working_log_file_date($file);
      }
      if (!$temp_date) {
        continue;
      }
      $working_logs[$temp_date][] = $full_path;
    }
    return $working_logs;
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
    $files_directory = $this->getCompletedFilesDirectory();
    $completed_dir = $files_directory;
    if (!is_dir($completed_dir)) {
      if (!mkdir($completed_dir, 0770)) {
        $message = "Could not make $completed_dir";
        $this->logError($message, 'FILE_PERMS');
        return FALSE;
      }
    }

    $filename = basename($file);
    $newfile = $completed_dir . '/' . $filename;

    if (!rename($file, $newfile)) {
      $message = "Unable to move $file to $newfile";

      $this->logError($message, 'FILE_PERMS');
      return FALSE;
    }
    $this->echo("Moved $file to $newfile");
    return TRUE;
  }

  /**
   * Make sure all the directories we need are there.
   *
   * @return boolean true on success, otherwise false
   */
  protected function setup_required_directories() {
    $directories = [
      'log_archive' => $this->getLogArchiveDirectory(),
      'recon' => $this->getIncomingFilesDirectory(),
      'log_working' => $this->getWorkingLogDirectory(),
      'recon_completed' => $this->getCompletedFilesDirectory(),
    ];

    foreach ($directories as $id => $dir) {
      if ($dir && !is_dir($dir)) {
        if ($id === 'log_archive' || $id === 'recon') {
          //already done. We don't want to try to create these, because required files come from here.
          $this->logError("Missing required directory $dir", 'MISSING_DIR_' . strtoupper($id));
          return FALSE;
        }

        if (!mkdir($dir, 0770)) {
          $this->logError("Missing and could not create required directory $dir", 'MISSING_DIR_' . strtoupper($id));
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Get the payments log archive directory. Same across all gateways.
   *
   * @return string
   */
  protected function getLogArchiveDirectory() {
    return \Civi::settings()->get('wmf_audit_directory_payments_log');
  }

  /**
   * Check the database to see if we have already recorded the transactions
   * present in the recon files.
   *
   * @param array $transactions An array of transactions we have already parsed
   * out from the recon files.
   *
   * @return array|false
   *   An array of transactions that are not in the database
   *   already, or false if something goes wrong enough
   */
  protected function getMissingTransactions(array $transactions, string $file) {
    if (empty($transactions)) {
      $this->echo(__FUNCTION__ . ': No transactions to find. Returning.');
      return FALSE;
    }
    //go through the transactions and check to see if they're in civi
    $missing = [
      'main' => [],
      'negative' => [],
    ];

    $fileStatistics = &$this->statistics[$file];
    foreach ($transactions as $transaction) {
      $paymentMethod = $transaction['payment_method'] ?? 'unknown';
      $type = $transaction['type'] ?? 'main';
      if ($type === 'donations' || $type === 'recurring' || $type === 'recurring-modify') {
        // It seems type could be one of these others here from fundraise up (the others are unset).
        // It might be nice to switch from main to donations but for now ...
        $type = 'main';
      }
      if (!isset($fileStatistics[$type]['by_payment'][$paymentMethod])) {
        $fileStatistics[$type]['by_payment'][$paymentMethod] = ['missing' => 0, 'found' => 0];
      }
      if (
        $this->record_is_refund($transaction) ||
        $this->record_is_chargeback($transaction) ||
        $this->record_is_cancel($transaction)
      ) {
        //negative
        $transaction = $this->pre_process_refund($transaction);
        if ($this->negative_transaction_exists_in_civi($transaction) === FALSE) {
          $missing['negative'][] = $transaction;
          $fileStatistics[$type]['missing']++;
          $fileStatistics[$type]['total']++;
          $fileStatistics[$type]['by_payment'][$paymentMethod]['missing']++;
          $this->missingTransactions['negative'][] = $transaction;
        }
        else {
          $fileStatistics[$type]['found']++;
          $fileStatistics[$type]['total']++;
          $fileStatistics[$type]['by_payment'][$paymentMethod]['found']++;
        }
      }
      else {
        //normal type
        if ($this->main_transaction_exists_in_civi($transaction) === FALSE) {
          $missing['main'][] = $transaction;
          $fileStatistics[$type]['missing']++;
          $fileStatistics[$type]['by_payment'][$paymentMethod]['missing']++;
          $this->missingTransactions['main'][] = $transaction;
        }
        else {
          $fileStatistics[$type]['found']++;
          $fileStatistics[$type]['by_payment'][$paymentMethod]['found']++;
        }
      }
    }
    $this->statistics[$file]['missing_negative'] = count($missing['negative']);
    $this->statistics[$file]['missing_main'] = count($missing['main']);
    $this->statistics[$file]['total_missing'] = $this->statistics[$file]['missing_negative'] + $this->statistics[$file]['missing_main'];
    $this->statistics['total_missing'] += $this->statistics[$file]['total_missing'];
    $this->echo('Transactions');
    $this->echoFileSummaryRow($file, 'main');
    $this->echoFileSummaryRow($file, 'refund');
    $this->echoFileSummaryRow($file, 'cancel');
    $this->echoFileSummaryRow($file, 'chargeback');

    return $missing;
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
   * @param string[] $logs The full paths to the log we want to search
   * @param $audit_data array the data from the audit file.
   *
   * @return array|bool
   *   The data we sent to the gateway for that order id, or
   *   false if we can't find it there.
   */
  protected function get_log_data_by_order_id($order_id, $logs, $audit_data) {
    if (!$order_id) {
      return FALSE;
    }

    // grep in all of the date's files at once
    $logPaths = implode(' ', $logs);
    // -h means don't print the file name prefix when grepping multiple files
    $cmd = 'grep -h \'' . $this->get_log_line_grep_string($order_id) . '\' ' . $logPaths;

    $ret = [];
    exec($cmd, $ret, $errorlevel);

    if (count($ret) > 0) {
      //In this wonderful new world, we only expect one line.
      if (count($ret) > 1) {
        $this->echo("Odd: More than one logline returned for $order_id. Investigation Required.");
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
          $this->logError(
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
      throw new WMFException(
        WMFException::DATA_INCONSISTENT,
        'Inconsistent data. Skipping the following: ' . print_r($audit_data, TRUE) . "\n" . print_r($raw_data, TRUE)
      );
    }
    //no big deal, it just wasn't there. This will happen most of the time.
    return FALSE;
  }

  /**
   * @param $file
   * @param string $statistic
   *
   * @return mixed
   */
  public function getFileStatistic($file, string $statistic) {
    return $this->statistics[$file][$statistic];
  }

  /**
   * @param string $name
   */
  protected function startTiming(string $name): void {
    $this->echo('Starting process: ' . $name);
    $this->timings[$name]['start'] = microtime(TRUE);
  }

  protected function stopTiming(string $name): float {
    $this->timings[$name]['stop'] = microtime(TRUE);
    return $this->timings[$name]['stop'] - $this->timings[$name]['start'];
  }

  /**
   * Output the statistics for this type of transaction.
   *
   * @param string $file
   * @param string $type
   *
   * @return void
   */
  public function echoFileSummaryRow(string $file, string $type): void {
    $fileStatistics = $this->statistics[$file][$type];
    $foundByType = $missingByType = [];
    foreach ($fileStatistics['by_payment'] as $paymentType => $number) {
      if ($number['found']) {
        $foundByType[] = $paymentType . ': ' . $number['found'];
      }
      if ($number['missing']) {
        $missingByType[] = $paymentType . ': ' . $number['missing'];
      }
    }
    $foundByTypeString = $foundByType ? '(' . implode(',', $foundByType) . ')' : '';
    $missingByTypeString = $missingByType ? '(' . implode(',', $missingByType) . ')' : '';
    $this->echo($type . "|  total : {$fileStatistics['total']}    | found : {$fileStatistics['found']}  $foundByTypeString     | missing: {$fileStatistics['missing']} $missingByTypeString");
  }

  protected function parse_json_log_line($line) {
    $matches = [];
    if (!preg_match('/[^{]*([{].*)/', $line, $matches)) {
      throw new WMFException(
        WMFException::MISSING_MANDATORY_DATA,
        "JSON data not found in $line"
      );
    }
    $log_data = json_decode($matches[1], TRUE);
    if (!$log_data) {
      throw new WMFException(
        WMFException::MISSING_MANDATORY_DATA,
        "Could not parse JSON data in $line"
      );
    }
    // Filter field names
    $filter = [
      'gross',
      'city',
      'country',
      'currency',
      'email',
      'first_name',
      'frequency_interval',
      'frequency_unit',
      'gateway',
      'gateway_account',
      'gross',
      'language',
      'last_name',
      'payment_method',
      'payment_submethod',
      'postal_code',
      'recurring',
      'recurring_payment_token',
      'state_province',
      'street_address',
      'user_ip',
      'utm_campaign',
      'utm_medium',
      'utm_source',
      'opt_in',
    ];
    $filtered = [];
    foreach ($filter as $fieldName) {
      if (isset($log_data[$fieldName])) {
        $filtered[$fieldName] = $log_data[$fieldName];
      }
    }
    return $filtered;
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
   * Parse single reconciliation file.
   *
   * @param string $file Absolute location of the recon file you want to parse
   *
   * @return array An array of date loaded from the reconciliation file.
   */
  protected function parseReconciliationFile(string $file): array {
    $this->startTiming("Parsing $file");
    $records = [];
    // Send the file through to the processor if needed
    if ($this instanceof MultipleFileTypeParser) {
      $this->setFilePath($file);
    }
    $recon_parser = $this->get_audit_parser();

    try {
      $records = $recon_parser->parseFile($file);
    }
    catch (\Exception $e) {
      $this->logError(
        "Something went amiss with the recon parser while "
        . "processing $file: \"{$e->getMessage()}\"",
        'RECON_PARSE_ERROR'
      );
    }
    $time = $this->stopTiming("Parsing $file");
    $this->echo(count($records) . " results found in $time seconds\n");
    $this->statistics[$file]['total_records'] = count($records);
    $this->statistics['total_records'] += $this->statistics[$file]['total_records'];
    return $records;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function getGatewayIdFromTracking(int $contributionTrackingID) {
    $tracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $contributionTrackingID)
      ->addSelect('contribution_id.contribution_extra.gateway_txn_id')
      ->execute()
      ->first();
    return $tracking ? $tracking['contribution_id.contribution_extra.gateway_txn_id'] : NULL;
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

  /**
   * Send the message in $body to appropriate message queues.
   *
   * @param array $body An array representing the transaction we're trying to
   * send.
   * @param string $type The type of transaction. 'main'|'negative'|'recurring'.
   *
   * @throws \Exception
   */
  protected function send_queue_message($body, $type) {
    $queueNames = [
      'main' => 'donations',
      'negative' => 'refund',
      'recurring' => 'recurring',
      'recurring-modify' => 'recurring-modify',
    ];

    if (!array_key_exists($type, $queueNames)) {
      throw new \Exception(__FUNCTION__ . ": Unhandled message type '$type'");
    }

    QueueWrapper::push($queueNames[$type], $body, TRUE);
  }

  /**
   * Returns today's date string value
   *
   * @return int Today's date in the format yyyymmdd.
   */
  private function wmf_common_date_get_today_string() {
    $timestamp = time();
    return $this->wmf_common_date_format_string($timestamp);
  }

  /**
   * Get an array of all the valid dates between $start(exclusive) and
   * $end(inclusive)
   *
   * @param int $start Date string in the format yyyymmdd
   * @param int $end Date string in the format yyyymmdd
   *
   * @return array all date strings between the $start and $end values
   */
  private function wmf_common_date_get_date_gap($start, $end) {
    $startdate = date_create_from_format('Ymd', (string) $start);
    $enddate = date_create_from_format('Ymd', (string) $end);

    $next = $startdate;
    $interval = new \DateInterval('P1D');
    $ret = [];
    while ($next < $enddate) {
      $next = date_add($next, $interval);
      $ret[] = $this->wmf_common_date_format_string($next);
    }
    return $ret;
  }

  /**
   * Converts various kinds of dates to our favorite string format.
   *
   * @param mixed $date An integer in ***timestamp*** format, or a DateTime
   *   object.
   *
   * @return string The date in the format yyyymmdd.
   */
  private function wmf_common_date_format_string($date) {
    if (is_numeric($date)) {
      return date('Ymd', $date);
    }
    elseif (is_object($date)) {
      return date_format($date, 'Ymd');
    }
  }

  /**
   * Adds a number of days to a specified date
   *
   * @param string $date Date in a format that date_create recognizes.
   * @param int $add Number of days to add
   *
   * @return string|false Date in 'Ymd'
   */
  private function dateAddDays($date, $add) {
    $date = date_create($date);
    date_add($date, date_interval_create_from_date_string("$add days"));

    return date_format($date, 'Ymd');
  }


  /**
   * @todo - in most places this becomes obsolete when Message::normlize() is used.
   * Pulls all records in the wmf_contribution_extras table that match the gateway
   * and gateway transaction id.
   *
   * @deprecated - in many cases \Civi\WMFHelper\Contribution::exists() is more appropriate
   * - this has a weird return signature.g
   *
   * @param string $gateway
   * @param string $gateway_txn_id
   *
   * @return mixed array of result rows, or false if none present.
   * TODO: return empty set rather than false.
   * @throws \Civi\WMFException\WMFException
   */
  protected function getContributions($gateway, $gateway_txn_id) {
    // If you only want to know if it exists then call \Civi\WMFHelper\Contribution::exists()
    // Use apiv4
    $gateway = strtolower($gateway);
    $query = "SELECT cx.*, cc.* FROM wmf_contribution_extra cx LEFT JOIN civicrm_contribution cc
		ON cc.id = cx.entity_id
		WHERE gateway = %1 AND gateway_txn_id = %2";

    $dao = \CRM_Core_DAO::executeQuery($query, [
      1 => [$gateway, 'String'],
      2 => [$gateway_txn_id, 'String'],
    ]);
    $result = [];
    while ($dao->fetch()) {
      $result[] = $dao->toArray();
    }
    // FIXME: pick wart
    if (empty($result)) {
      return FALSE;
    }
    return $result;
  }

}
