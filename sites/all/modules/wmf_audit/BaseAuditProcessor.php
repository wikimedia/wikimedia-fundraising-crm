<?php

abstract class BaseAuditProcessor {
    protected $options;
    protected $name;
    protected $ready_files;

    public function __construct( $options ) {
	$this->options = $options;
	// FIXME: Copy to confusing global thing.
	$options['submod_prefix'] = $this->name;
	wmf_audit_runtime_options( $options );
    }

    abstract protected function get_log_distilling_grep_string();
    abstract protected function get_log_line_grep_string( $order_id );
    abstract protected function get_log_line_xml_data_nodes();
    abstract protected function get_log_line_xml_outermost_node();
    abstract protected function get_log_line_xml_parent_nodes();
    abstract protected function get_order_id( $transaction );
    abstract protected function get_recon_file_date( $file );
    abstract protected function normalize_and_merge_data( $data, $transaction );
    abstract protected function parse_recon_file( $file );
    abstract protected function regex_for_recon();

    /**
     * Returns the configurable path to the recon files
     * @return string Path to the directory
     */
    protected function get_recon_dir() {
      return variable_get( $this->name . '_audit_recon_files_dir' );
    }

    /**
     * Returns the configurable path to the completed recon files
     * @return string Path to the directory
     */
    protected function get_recon_completed_dir() {
      return variable_get($this->name . '_audit_recon_completed_dir');
    }

    /**
     * Returns the configurable path to the working log dir
     * @return string Path to the directory
     */
    protected function get_working_log_dir() {
      return variable_get($this->name . '_audit_working_log_dir');
    }

    /**
     * The regex to use to determine if a file is a working log for this gateway.
     * @return string regular expression
     */
    protected function regex_for_working_log() {
      return "/\d{8}_{$this->name}[.]working/";
    }

    /**
     * The regex to use to determine if a file is a compressed payments log.
     * @return string regular expression
     */
    protected function regex_for_compressed_log() {
      return '/.gz/';
    }

    /**
     * The regex to use to determine if a file is an uncompressed log for this
     * gateway.
     * @return string regular expression
     */
    protected function regex_for_uncompressed_log() {
      return "/{$this->name}_\d{8}/";
    }

    /**
     * Returns the configurable number of days we want to jump back in to the past,
     * to look for transactions in the payments logs.
     * @return in Number of days
     */
    protected function get_log_days_in_past() {
      return variable_get($this->name . '_audit_log_search_past_days');
    }

    /**
     * Given the name of a working log file, pull out the date portion.
     * @param string $file Name of the working log file (not full path)
     * @return string date in YYYYMMDD format
     */
    protected function get_working_log_file_date($file) {
      //  '/\d{8}_GATEWAY\.working/';
      $parts = explode('_', $file);
      return $parts[0];
    }

    /**
     * Get the name of a compressed log file based on the supplied date.
     * @param string $date date in YYYYMMDD format
     * @return string Name of the file we're looking for
     */
    protected function get_compressed_log_file_name($date) {
    //  payments-worldpay-20140413.gz
      return "payments-{$this->name}-{$date}.gz";
    }

    /**
     * Get the name of an uncompressed log file based on the supplied date.
     * @param string $date date in YYYYMMDD format
     * @return string Name of the file we're looking for
     */
    protected function get_uncompressed_log_file_name($date) {
    //  payments-worldpay-20140413 - no extension. Weird.
      return "payments-{$this->name}-{$date}";
    }

    /**
     * Get the name of a working log file based on the supplied date.
     * @param string $date date in YYYYMMDD format
     * @return string Name of the file we're looking for
     */
    protected function get_working_log_file_name($date) {
      //  '/\d{8}_worldpay\.working/';
      return "{$date}_{$this->name}.working";
    }

    /**
     * Checks the array to see if the data inside is describing a refund.
     * @param aray $record The transaction we would like to know is a refund or not.
     * @return boolean true if it is, otherwise false
     */
    protected function record_is_refund($record) {
      return (array_key_exists('type', $record) && $record['type'] === 'refund');
    }

    /**
     * Checks the array to see if the data inside is describing a chargeback.
     * @param aray $record The transaction we would like to know is a chargeback or
     * not.
     * @return boolean true if it is, otherwise false
     */
    protected function record_is_chargeback($record) {
      return (array_key_exists('type', $record) && $record['type'] === 'chargeback');
    }

    /**
     * Return a date in the format YYYYMMDD for the given record
     * @param array $record A transaction, or partial transaction
     * @return string Date in YYYYMMDD format
     */
    protected function get_record_human_date($record) {
      if (array_key_exists('date', $record)) {
	return date(WMF_DATEFORMAT, $record['date']); //date format defined in wmf_dates
      }

      echo print_r($record, true);
      throw new Exception( __FUNCTION__ . ': No date present in the record. This seems like a problem.' );
    }

    /**
     * Normalize refund/chargeback messages before sending
     * @param array $record transaction data
     * @return array The normalized data we want to send
     */
    protected function normalize_negative($record) {
      $send_message = array(
	'gateway_refund_id' => 'RFD' . $record['gateway_txn_id'], //Notes from a previous version: "after intense deliberation, we don't actually care what this is at all."
	'gateway_parent_id' => $record['gateway_txn_id'], //gateway transaction ID
	'gross_currency' => $record['currency'], //currency code
	'gross' => $record['gross'], //amount
	'date' => $record['date'], //timestamp
	'gateway' => $this->name,
    //  'gateway_account' => $record['gateway_account'], //BOO. @TODO: Later.
    //  'payment_method' => $record['payment_method'], //Argh. Not telling you.
    //  'payment_submethod' => $record['payment_submethod'], //Still not telling you.
	'type' => $record['type'], //This actually works here. Weird, right?
      );
      return $send_message;
    }

    /**
     * Used in makemissing mode
     * This should take care of any extra data not sent in the recon file, that will
     * actually make qc choke. Not so necessary with WP, but this will need to
     * happen elsewhere, probably. Just thinking ahead.
     * @param array $record transaction data
     * @return type The normalized data we want to send.
     */
    protected function normalize_partial($record) {
      //@TODO: Still need gateway account to go in here when that happens.
      return $record;
    }

    /**
     * Checks to see if the transaction already exists in civi
     * @param array $transaction Array of donation data
     * @return boolean true if it's in there, otherwise false
     */
    protected function main_transaction_exists_in_civi($transaction) {
      //go through the transactions and check to see if they're in civi
      if (wmf_civicrm_get_contributions_from_gateway_id($this->name, $transaction['gateway_txn_id']) === false) {
	return false;
      } else {
	return true;
      }
    }

    /**
     * Checks to see if the refund or chargeback already exists in civi.
     * NOTE: This does not check to see if the parent is present at all, nor should
     * it. Call worldpay_audit_main_transaction_exists_in_civi for that.
     * @param array $transaction Array of donation data
     * @return boolean true if it's in there, otherwise false
     */
    protected function negative_transaction_exists_in_civi($transaction) {
      //go through the transactions and check to see if they're in civi
      if (wmf_civicrm_get_child_contributions_from_gateway_id($this->name, $transaction['gateway_txn_id']) === false) {
	return false;
      } else {
	return true;
      }
    }

    protected function get_runtime_options( $name ) {
	if ( isset( $this->options[$name] ) ) {
	    return $this->options[$name];
	} else {
	    return null;
	}
    }

    /**
     * The main function, intended to be called straight from the drush command and
     * nowhere else.
     */
    public function run() {
	civicrm_initialize();

	//make sure all the things we need are there.
	if (!$this->setup_required_directories()) {
	  throw new Exception( 'Missing required directories' );
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

	$total_missing = array();
	$recon_file_stats = array();

	for ($i = 0; $i < $count; ++$i) {
	  $parsed = array();
	  $missing = array();

	  //parce the recon files into something relatively reasonable.
	  $file = array_pop($recon_files);
	  wmf_audit_echo("Parsing $file");
	  $start_time = microtime(true);
	  $parsed = $this->parse_recon_file( $file );
	  $time = microtime(true) - $start_time;
	  wmf_audit_echo(count($parsed) . " results found in $time seconds\n");

	  //remove transactions we already know about
	  $start_time = microtime(true);
	  $missing = $this->get_missing_transactions($parsed);
	  $recon_file_stats[$file] = wmf_audit_count_missing($missing);
	  $time = microtime(true) - $start_time;
	  wmf_audit_echo(wmf_audit_count_missing($missing) . ' missing transactions (of a possible ' . count($parsed) . ") identified in $time seconds\n");

	  //If the file is empty, move it off.
	  // Note that we are not archiving files that have missing transactions,
	  // which might be resolved below.  Those are archived on the next run,
	  // once we can confirm they have hit Civi and are no longer missing.
	  if (wmf_audit_count_missing($missing) <= $this->get_runtime_options('recon_complete_count')) {
	    wmf_audit_move_completed_recon_file($file, $this->get_recon_completed_dir());
	  }

	  //grumble...
	  if (!empty($missing)) {
	    foreach ($missing as $type => $data) {
	      if (!empty($missing[$type])) {
		if (array_key_exists($type, $total_missing)) {
		  $total_missing[$type] = array_merge($total_missing[$type], $missing[$type]);
		} else {
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
	$missing_by_date = array();
	if (array_key_exists('main', $total_missing) && !empty($total_missing['main'])) {
	  foreach ($total_missing['main'] as $record) {
	    $missing_by_date[$this->get_record_human_date( $record )][] = $record;
	  }
	}


	$remaining = null;
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
	    if (worldpay_audit_main_transaction_exists_in_civi($record)) {
	      $normal = $this->normalize_negative( $record );
	      if (wmf_audit_send_transaction($normal, 'negative')) {
		$neg_count += 1;
		wmf_audit_echo('!');
	      }
	      wmf_audit_echo('X');
	    } else {
	      //@TODO: Some version of makemissing should make these, too. Gar.
	      $remaining['negative'][$this->get_record_human_date( $record )][] = $record;
	      wmf_audit_echo('.');
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
		$wrap_up .= "\t" . WmfTransaction::from_message($transaction)->get_unique_id() . "\n";
	      }
	    }
	  }

	  $wrap_up .= 'Initial stats on recon files: ' . print_r($recon_file_stats, true) . "\n";
	}

	wmf_audit_echo($wrap_up);
    }

    /**
     * Returns an array of the full paths to all valid reconciliation files
     * @return array Full paths to all recon files
     */
    protected function get_all_recon_files() {
      $files_directory = $this->get_recon_dir();
      //foreach file in the directory, if it matches our pattern, add it to the array.
      $files = array();
      if ($handle = opendir($files_directory)) {
	while (( $file = readdir($handle) ) !== false) {
	  if ($this->get_filetype($file) === 'recon') {
	    $filedate = $this->get_recon_file_date( $file ); //which is not the same thing as the edited date... probably.
	    $files[$filedate] = $files_directory . $file;
	  }
	}
	closedir($handle);
	ksort($files);
	return $files;
      } else {
	//can't open the directory at all. Problem.
	wmf_audit_log_error("Can't open directory $files_directory", 'FILE_DIR_MISSING'); //should be fatal
      }
      return false;
    }

    /**
     * Go transaction hunting in the payments logs. This is by far the most
     * processor-intensive part, but we have some timesaving new near-givens to work
     * with.
     * Things to remember:
     * The date on the payments log, probably doesn't contain much of that actual
     * date. It's going to be the previous day, mostly.
     * Also, remember logrotate exists, so it might be the next day before we get
     * the payments log we would be most interested in today.
     *
     * @param array $missing_by_date An array of all the missing transactions we
     * have pulled out of the nightlies, indexed by the standard WMF date format.
     * @return mixed An array of transactions we couldn't find or deal with (by
     * date), or false on error
     */
    protected function log_hunt_and_send($missing_by_date) {

      if (empty($missing_by_date)) {
	wmf_audit_echo(__FUNCTION__ . ': No missing transactions sent to this function. Aborting.');
	return false;
      }

      ksort($missing_by_date);

      //output the initial counts for each index...
      $earliest = null;
      $latest = null;
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
	return false;
      }

      //want the latest first, from now on.
      rsort($logs_to_grab);
      krsort($missing_by_date);

      //Foreach log by date DESC, check all the transactions we are missing that might possibly be in this log.
      //This is going to look a little funny, because the logs are date-named and stamped after the day they rotate; Not after the dates for all the data in them.
      //As such, they mostly contain data for the previous day (but not exclusively, and not all of it)
      $tryme = array();
      foreach ($logs_to_grab as $log_date) {
	//Add to the pool of what's possible to find in this log, as we step backward through the log dates.
	//If the log date is less than or equal to the date on the transaction
	//(which may or may not be when it was initiated, but that's the past-iest
	//option), and it hasn't already been added to the pool, add it to the pool.
	//As we're stepping backward, we should look for transactions that come
	//from the current log date, or the one before.
	foreach ($missing_by_date as $date => $data) {
	  if ($date >= ($log_date - 1)) {
	    if (!array_key_exists($date, $tryme)) {
	      wmf_audit_echo("Adding date $date to the date pool for log date $log_date");
	      $tryme[$date] = $data;
	    }
	  } else {
	    break;
	  }
	}

	//log something sensible out for what we're about to do
	$display_dates = array();
	if (!empty($tryme)) {
	  foreach ($tryme as $date => $thing) {
	    if (count($thing) > 0) {
	      $display_dates[$date] = count($thing);
	    }
	  }
	}
	$log = false;
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
	      wmf_audit_echo("Log Date: $log_date: About to check " . count($missing) . " missing transactions from $date", true);
	      $checked = 0;
	      $found = 0;
	      foreach ($missing as $id => $transaction) {
		$checked += 1;
		//reset vars used below, for extra safety
		$order_id = false;
		$data = false;
		$all_data = false;
		$contribution_tracking_data = false;
		$error = false;

		$order_id = $this->get_order_id( $transaction );
		if (!$order_id) {
		  $error = array(
		    'message' => 'Could not get an order id for the following transaction ' . print_r($transaction, true),
		    'code' => 'MISSING_MANDATORY_DATA',
		  );
		} else {
		  //@TODO: If you ever have a gateway that doesn't communicate with xml, this is going to have to be abstracted slightly.
		  //Not going to worry about that right now, though.
		  $data = $this->get_xml_log_data_by_order_id($order_id, $log);
		}

		//if we have data at this point, it means we have a match in the logs
		if ($data) {
		  $found += 1;
		  $all_data = $this->normalize_and_merge_data( $data, $transaction );
		  if (!$all_data) {
		    $error = array(
		      'message' => 'Error normalizing data. Skipping the following: ' . print_r($transaction, true) . "\n" . print_r($data, true),
		      'code' => 'NORMALIZE_DATA',
		    );
		  }
		  if (!$error) {
		    //lookup contribution_tracking data, and fill it in with audit markers if there's nothing there.
		    $contribution_tracking_data = wmf_audit_get_contribution_tracking_data($all_data);
		  }

		  if (!$contribution_tracking_data) {
		    $error = array(
		      'message' => 'No contribution trackin data retrieved for transaction ' . print_r($all_data, true),
		      'code' => 'MISSING_MANDATORY_DATA',
		    );
		  }

		  if (!$error) {
		    //Now that we've made it this far: Easy check to make sure we're even looking at the right thing...
		    //I'm not totally sure this is going to be the right thing to do, though. Intended fragility.
		    if ((!$this->get_runtime_options('fakedb')) &&
		      (!empty($contribution_tracking_data['utm_payment_method'])) &&
		      ($contribution_tracking_data['utm_payment_method'] !== $all_data['payment_method'])) {

		      $message = 'Payment method mismatch between utm tracking data(' . $contribution_tracking_data['utm_payment_method'];
		      $message .= ') and normalized log and recon data(' . $all_data['payment_method'] . '). Investigation required.';
		      $error = array(
			'message' => $message,
			'code' => 'UTM_DATA_MISMATCH',
		      );
		    } else {
		      unset($contribution_tracking_data['utm_payment_method']);
		      // On the next line, the date field from all_data will win, which we totally want.
		      // I had thought we'd prefer the contribution tracking date, but that's just silly.
		      // However, I'd just like to point out that it would be terribly enlightening for some gateways to log the difference...
		      // ...but not inside the char block, because it'll break the pretty.
		      $all_data = array_merge($contribution_tracking_data, $all_data);
		    }

		    if (!$error) {
		      //Send to stomp. Or somewhere. Or don't (if it's test mode).
		      wmf_audit_send_transaction($all_data, 'main');
		      unset($tryme[$date][$id]);
		      wmf_audit_echo('!');
		    }
		  }

		} else {
		  //no data found in this log, which is expected and normal and not a problem.
		  wmf_audit_echo('.');
		}

		//End of the transaction search/destroy loop. If we're here and have
		//an error, we found something and the re-fusion didn't work.
		//Handle consistently, and definitely don't try looking in other
		//logs.
		if (is_array($error)) {
		  wmf_audit_log_error($error['message'], $error['code']);
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
	} else {
	  //today minus three. Again: The three is because Shut Up.
	  wmf_audit_echo("Making up to $missing_count missing transactions:");
	  $made = 0;
	  $cutoff = wmf_common_date_add_days(wmf_common_date_get_today_string(), -3);
	  foreach ($tryme as $date => $missing) {
	    if ((int) $date <= (int) $cutoff) {
	      foreach ($missing as $id => $message) {
		$contribution_tracking_data = wmf_audit_make_contribution_tracking_data($message);
		$all_data = array_merge($message, $contribution_tracking_data);
		$sendme = $this->normalize_partial( $all_data );
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
      $compressed_filename = $this->get_compressed_log_file_name( $date );
      $full_archive_path = wmf_audit_get_log_archive_dir() . $compressed_filename;
      $working_directory = $this->get_working_log_dir();
      $cleanup = array(); //add files we want to make sure aren't there anymore when we're done here.
      if (file_exists($full_archive_path)) {
	wmf_audit_echo("Retrieving $full_archive_path");
	$cmd = "cp $full_archive_path " . $working_directory;
	exec(escapeshellcmd($cmd), $ret, $errorlevel);
        $full_compressed_path = $working_directory . $compressed_filename;
	if (!file_exists($full_compressed_path)) {
	  wmf_audit_log_error("FILE PROBLEM: Trying to get log archives, and something went wrong with $cmd", 'FILE_MOVE');
	  return false;
	} else {
	  $cleanup[] = $full_compressed_path;
	}
	//uncompress
	wmf_audit_echo("Gunzipping $full_compressed_path");
	$cmd = "gunzip -f $full_compressed_path";
	exec(escapeshellcmd($cmd), $ret, $errorlevel);
	//now check to make sure the file you expect, actually exists
	$uncompressed_file = $this->get_uncompressed_log_file_name( $date );
	$full_uncompressed_path = $working_directory . $uncompressed_file;
	if (!file_exists($full_uncompressed_path)) {
	  wmf_audit_log_error("FILE PROBLEM: Something went wrong with uncompressing logs: $cmd : $full_uncompressed_path doesn't exist.", 'FILE_UNCOMPRESS');
	} else {
	  $cleanup[] = $full_uncompressed_path;
	}

	//distill & cache locally
	$distilled_file = $this->get_working_log_file_name( $date );
        $full_distilled_path = $working_directory . $distilled_file;
	//Can't escape the hard-coded string we're grepping for, because it breaks terribly.
	$cmd = "grep '" . $this->get_log_distilling_grep_string() . "' " . escapeshellcmd($full_uncompressed_path) . " > " . escapeshellcmd($full_distilled_path);

	wmf_audit_echo($cmd);
	$ret = array();
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
      return false;
    }

    /**
     * Construct an array of all the distilled working logs we have in the working
     * directory.
     * @return array Array of date => full path to file for all distilled working
     * logs
     */
    protected function read_working_logs_dir() {
      $working_logs = array();
      $working_dir = $this->get_working_log_dir();
      //do the directory read and cache the results in the static
      if (!$handle = opendir($working_dir)) {
	throw new Exception(__FUNCTION__ . ": Can't open directory. We should have noticed earlier (in setup_required_directories) that this wasn't going to work. \n");
      }
      while (( $file = readdir($handle) ) !== false) {
	$temp_date = false;
	if ($this->get_filetype($file) === 'working_log') {
	  $full_path = $working_dir . $file;
	  $temp_date = $this->get_working_log_file_date( $file );
	}
	if (!$temp_date) {
	  continue;
	}
	$working_logs[$temp_date] = $full_path;
      }
      return $working_logs;
    }

    /**
     * Figures out what type of file you've got there, according to what the gateway
     * module has defined in its _regex_for_ functions.
     * @param string $file Full path to the file of interest
     * @return mixed 'recon'|'working_log'|'uncompressed_log'|'compressed_log'|false
     * if it's nothing the gateway recognizes.
     */
    protected function get_filetype($file) {
      //we have three types of files, right? compressed, uncompressed, distilled, and recon file.
      //...four. Four types.

      $types = array(
	'recon',
	'working_log',
	'uncompressed_log',
	'compressed_log',
      );

      foreach ($types as $type) {
	$function_name = 'regex_for_' . $type;
	// TODO: map rather than functions
	if (preg_match($this->$function_name(), $file)) {
	  return $type;
	}
      }

      return false;
    }

    /**
     * Moves recon files to the completed directory. This should probably only be
     * done at the beginning of a run: If we're running in queue flood mode, we
     * don't know if the data will actually make it all the way in.
     * @param string $file Full path to the file we want to move off
     * @return boolean true on success, otherwise false
     */
    protected function move_completed_recon_file($file) {
      $files_directory = $this->get_recon_completed_dir();
      $completed_dir = $files_directory;
      if (!is_dir($completed_dir)) {
	if (!mkdir($completed_dir, 0770)) {
	  $message = "Could not make $completed_dir";
	  wmf_audit_log_error($message, 'FILE_PERMS');
	  return false;
	}
      }

      $filename = basename($file);
      $newfile = $completed_dir . '/' . $filename;

      if (!rename($file, $newfile)) {
	$message = "Unable to move $file to $newfile";

	wmf_audit_log_error($message, 'FILE_PERMS');
	return false;
      } else {
	chmod($newfile, 0770);
      }
      wmf_audit_echo("Moved $file to $newfile");
      return true;
    }

    /**
     * Make sure all the directories we need are there.
     * @return boolean true on success, otherwise false
     */
    protected function setup_required_directories() {
      $directories = array(
	'log_archive' => wmf_audit_get_log_archive_dir(),
	'recon' => $this->get_recon_dir(),
	'log_working' => $this->get_working_log_dir(),
	'recon_completed' => $this->get_recon_completed_dir(),
      );

      foreach ($directories as $id => $dir) {
	if (!is_dir($dir)) {
	  if ($id === 'log_archive' || $id === 'recon') {
	    //already done. We don't want to try to create these, because required files come from here.
	    wmf_audit_log_error("Missing required directory $dir", 'MISSING_DIR_' . strtoupper($id));
	    return false;
	  }

	  if (!mkdir($dir, 0770)) {
	    wmf_audit_log_error("Missing and could not create required directory $dir", 'MISSING_DIR_' . strtoupper($id));
	    return false;
	  }
	}
      }
      return true;
    }

    /**
     * Check the database to see if we have already recorded the transactions
     * present in the recon files.
     * @param array $transactions An array of transactions we have already parsed
     * out from the recon files.
     * @return mixed An array of transactions that are not in the database already,
     * or false if something goes wrong enough
     */
    protected function get_missing_transactions($transactions) {
      if (empty($transactions)) {
	wmf_audit_echo(__FUNCTION__ . ': No transactions to find. Returning.');
	return false;
      }
      //go through the transactions and check to see if they're in civi
      //@TODO: RECURRING. Won't matter for WP initially, though, so I'm leaving that for the WX integration phase.
      $missing = array(
	'main' => array(),
	'negative' => array(),
	'recurring' => array(),
      );
      foreach ($transactions as $transaction) {
	if ($this->record_is_refund( $transaction ) || $this->record_is_chargeback( $transaction )) { //negative
	  if ($this->negative_transaction_exists_in_civi( $transaction ) === false) {
	    wmf_audit_echo('-'); //add a subtraction. I am the helpfulest comment ever.
	    $missing['negative'][] = $transaction;
	  } else {
	    wmf_audit_echo('.');
	  }
	} else { //normal type
	  if ($this->main_transaction_exists_in_civi( $transaction ) === false) {
	    wmf_audit_echo('!');
	    $missing['main'][] = $transaction;
	  } else {
	    wmf_audit_echo('.');
	  }
	}
      }
      return $missing;
    }

    /**
     * Visualization helper. Returns the character we want to display for the kind
     * of transaction we have just parsed out of a recon file.
     * @param array $record A single transaction from a recon file
     * @return char A single char to display in the char block.
     */
    protected function audit_echochar($record) {

      if ($this->record_is_refund( $record )) {
	return 'r';
      }

      if ($this->record_is_chargeback( $record )) {
	return 'b';
      }

      if ($record['payment_method'] === 'cc') {
	return 'c';
      }

      echo print_r($record, true);
      throw new Exception(__FUNCTION__ . " Not cc...");
    }

    /**
     * Returns supplemental data for the relevant order id from the specified
     * payments log, if there is any in there. This is the only sure place we can
     * catch data we didn't save, as we're basically grepping for the exact data we
     * sent to the gateway.
     * It should be noted that this function makes no attempt to normalize the data.
     * If this log doesn't contain data for the order_id in question, return false.
     * @param string $order_id The order id (transaction id) of the missing payment
     * @param string $log The full path to the log we want to search
     * @return array|boolean The data we sent to the gateway for that order id, or
     * false if we can't find it there.
     */
    protected function get_xml_log_data_by_order_id($order_id, $log) {
      if (!$order_id) {
	return false;
      }

      $cmd = 'grep ' . $this->get_log_line_grep_string( $order_id ) . ' ' . $log;
      wmf_audit_echo(__FUNCTION__ . ' ' . $cmd, true);

      $ret = array();
      exec(escapeshellcmd($cmd), $ret, $errorlevel);

      if (count($ret) > 0) {

	//In this wonderful new world, we only expect one line.
	if (count($ret) > 1) {
	  wmf_audit_echo("Odd: More than one logline returned for $order_id. Investigation Required.");
	}

	//just take the last one, just in case somebody did manage to do a duplicate.
	$line = $ret[count($ret) - 1];
	// $linedata for *everything* from payments goes Month, day, time, box, bucket, CTID:OID, absolute madness with lots of unpredictable spaces.
	$linedata = explode(' ', $line);
	$contribution_id = explode(':', $linedata[5]);
	$contribution_id = $contribution_id[0];


	//look for the raw xml
	$full_xml = false;
	$node = $this->get_log_line_xml_outermost_node();
	$xmlstart = strpos($line, '<?xml');
	if ($xmlstart === false) {
	  $xmlstart = strpos($line, "<$node>");
	}
	$xmlend = strpos($line, "</$node>");
	if ($xmlend) {
	  $full_xml = true;
	  $xmlend += (strlen($node) + 3);
	  $xml = substr($line, $xmlstart, $xmlend - $xmlstart);
	} else {
	  //this is a broken line, and it won't load... but we can still parse what's left of the thing, the slow way.
	  $xml = substr($line, $xmlstart);
	}
	// Syslog wart.  Other control characters should be encoded normally.
	$xml = str_replace( '#012', "\n", $xml );

	$donor_data = array();

	if ($full_xml) {
	  $xmlobj = new DomDocument;
	  $xmlobj->loadXML($xml);

	  $parent_nodes = $this->get_log_line_xml_parent_nodes();

	  if (empty($parent_nodes)) {
	    wmf_audit_log_error(__FUNCTION__ . ': No parent nodes defined. Can not continue.', 'RUNTIME_ERROR');
	    throw new Exception("Stop dying here");
	  }

	  foreach ($parent_nodes as $parent_node) {
	    foreach ($xmlobj->getElementsByTagName($parent_node) as $node) {
	      foreach ($node->childNodes as $childnode) {
		if (trim($childnode->nodeValue) != '') {
		  $donor_data[$childnode->nodeName] = $childnode->nodeValue;
		}
	      }
	    }
	  }
	} else {
	  //the XML got cut off prematurely, probably because syslog was set up to truncate on payments.
	  //rebuild what we can the old-fashioned way.
	  $search_for_nodes = $this->get_log_line_xml_data_nodes();

	  if (empty($search_for_nodes)) {
	    wmf_audit_log_error(__FUNCTION__ . ': No searchable nodes defined. Can not continue.', 'RUNTIME_ERROR');
	    throw new Exception("Stop dying here");
	  }
	  foreach ($search_for_nodes as $node) {
	    $tmp = wmf_audit_get_partial_xml_node_value($node, $xml);
	    if (!is_null($tmp)) {
	      $donor_data[$node] = $tmp;
	    }
	  }
	}

	if (!empty($donor_data)) {
	  $donor_data['contribution_tracking_id'] = $contribution_id;
	  return $donor_data;
	} else {
	  wmf_audit_log_error("We found a transaction in the logs for $order_id, but there's nothing left after we tried to grab its data. Investigation required.", 'DATA_WEIRD');
	}
      }
      return false; //no big deal, it just wasn't there. This will happen most of the time.
    }
}
