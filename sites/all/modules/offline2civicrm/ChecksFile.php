<?php

use SmashPig\CrmLink\Messages\SourceFields;
use League\Csv\Reader;

/**
 * CSV batch format for manually-keyed donation checks
 */
abstract class ChecksFile {

  /**
   * Number of rows successfully imported.
   *
   * @var int
   */
  protected $numberSucceededRows = 0;

  /**
   * Number of rows found to be duplicates.
   *
   * @var int
   */
  protected $numberDuplicateRows = 0;

  /**
   * Number of rows found to be in error.
   *
   * @var int
   */
  protected $numberErrorRows = 0;

  /**
   * Number of rows ignored.
   *
   * @var int
   */
  protected $numberIgnoredRows = 0;

  /**
   * Number of contacts created.
   *
   * @var int
   */
  protected $numberContactsCreated = 0;

  /**
   * Number of rows in the csv.
   *
   * @var int
   */
  protected $totalNumberRows = 0;

  /**
   * The row that most recently failed.
   *
   * If we abort we advise this in order to allow a restart.
   *
   * @var int
   */
  protected $lastErrorRowNumber = 0;

  /**
   * Most recent error message.
   *
   * @var string
   */
  protected $lastErrorMessage = '';

  /**
   * How many errors in a row we should hit before aborting.
   *
   * @var int
   */
  protected $errorStreakThreshold = 10;

  /**
   * How many errors have we had in a row.
   *
   * (when we hit the errorStreakThreshold we bail).
   *
   * @var int
   */
  protected $errorStreakCount = 0;

  /**
   * What row did the latest error streak start on.
   *
   * @var int
   */
  protected $errorStreakStart = 0;


  protected $messages = array();

  protected $file_uri = '';

  protected $error_file_uri = '';

  protected $skipped_file_uri = '';

  protected $ignored_file_uri = '';

  protected $all_missed_file_uri = '';

  protected $all_not_matched_to_existing_contacts_file_uri = '';

  protected $row_index;


  /**
   * @var resource
   */
  protected $ignoredFileResource = NULL;

  /**
   * @var resource
   */
  protected $skippedFileResource = NULL;

  /**
   * @var resource
   */
  protected $errorFileResource = NULL;

  /**
   * @var resource
   */
  protected $allMissedFileResource = NULL;

  /**
   * @var resource
   */
  protected $allNotMatchedFileResource = NULL;

  /**
   * @var array
   */
  protected $additionalFields = [];

  /**
   * Header fields for the csv output.
   *
   * @var array
   */
  protected $headers = [];

  /**
   * Csv reader object.
   *
   * @var Reader
   */
  protected $reader;

  /**
   * @param string $file_uri path to the file
   * @param array $additionalFields
   *
   * @throws \WmfException
   */
  function __construct($file_uri, $additionalFields = array()) {
    $this->file_uri = $file_uri;
    global $user;
    $suffix = $user->uid . '.csv';
    $this->error_file_uri = str_replace('.csv', '_errors.' . $suffix, $file_uri);
    $this->skipped_file_uri = str_replace('.csv', '_skipped.' . $suffix, $file_uri);
    $this->ignored_file_uri = str_replace('.csv', '_ignored.' . $suffix, $file_uri);
    $this->all_missed_file_uri = str_replace('.csv', '_all_missed.' . $suffix, $file_uri);
    $this->all_not_matched_to_existing_contacts_file_uri = str_replace('.csv', '_all_not_matched.' . $suffix, $file_uri);
    $this->additionalFields = $additionalFields;
    if ($file_uri) {
      wmf_common_set_smashpig_message_source(
        'direct', 'Offline importer: ' . get_class($this)
      );
    }
    // Note this ini is still recommeded - see https://csv.thephpleague.com/8.0/instantiation/
    ini_set('auto_detect_line_endings', TRUE);

    try {
      $this->reader = Reader::createFromPath($this->file_uri, 'r');
    }
    catch (Exception $e) {
      throw new WmfException(WmfException::FILE_NOT_FOUND, 'Import checks: Could not open file for reading: ' . $this->file_uri);
    }
  }

  /**
   * Getter for messages array.
   *
   * @return array
   */
  public function getMessages() {
    return $this->messages;
  }

  /**
   * Read checks from a file and save to the database.
   *
   * @return array
   *   Output messages to display to the user.
   *
   * @throws \Exception
   */
  function import() {
    $type = get_called_class();
    ChecksImportLog::record("Beginning import of $type file {$this->file_uri}...");

    $this->headers = _load_headers($this->reader->fetchOne());

    $this->validateColumns($this->headers);

    $this->row_index = 1;
    $this->allMissedFileResource = $this->createOutputFile($this->all_missed_file_uri, 'Not Imported', $this->headers);

    while (($row = $this->reader->fetchOne($this->row_index)) !== []) {
      $this->processRow($row);
    }
    $this->doFinish();
    return $this->messages;

  }

  /**
   * Read a row and transform into normalized queue message form
   *
   * @param $data
   *
   * @return array queue message format
   *
   * @throws \EmptyRowException
   * @throws \WmfException
   */
  protected function parseRow($data) {
    $msg = array();

    foreach ($this->getFieldMapping() as $header => $normal) {
      if (!empty($data[$header])) {
        $msg[$normal] = $data[$header];
      }
    }

    if (!$msg) {
      throw new EmptyRowException();
    }

    $this->setDefaults($msg);

    $this->mungeMessage($msg);

    $this->validateRequiredFields($msg);

    SourceFields::addToMessage($msg);
    return $msg;
  }

  protected function handleDuplicate($duplicate) {
    watchdog('offline2civicrm', 'Contribution matches existing contribution (id: @id), skipping it.', array('@id' => $duplicate[0]['id']), WATCHDOG_INFO);
    return TRUE; // true means this was a duplicate and i skipped it
  }

  protected function setDefaults(&$msg) {
    foreach ($this->getDefaultValues() as $key => $defaultValue) {
      if (empty($msg[$key])) {
        $msg[$key] = $defaultValue;
      }
    }
  }

  /**
   * Do any final transformation on a normalized and default-laden queue
   * message.  Overrides are specific to each upload source.
   *
   * @param array $msg
   *
   * @throws \WmfException
   */
  protected function mungeMessage(&$msg) {
    if (isset($msg['raw_contribution_type'])) {
      $contype = $msg['raw_contribution_type'];
      switch ($contype) {
        case "Merkle":
          $msg['gateway'] = "merkle";
          break;

        case "Cash":
          $msg['contribution_type'] = "cash";
          break;

        default:
          $msg['contribution_type'] = $msg['raw_contribution_type'];
      }
    }

    if (isset($msg['organization_name'])) {
      $msg['contact_type'] = "Organization";
    }
    else {
      // If this is not an Organization contact, freak out if Name or Title are filled.
      if (!empty($msg['org_contact_name'])
        || !empty($msg['org_contact_title'])
      ) {
        throw new WmfException(WmfException::INVALID_MESSAGE, "Don't give a Name or Title unless this is an Organization contact.");
      }
    }

    $msg['gross'] = str_replace(',', '', trim($msg['gross'], '$'));

    if (isset($msg['contribution_source'])) {
      // Check that the message amounts match
      list($currency, $source_amount) = explode(' ', $msg['contribution_source']);

      if (abs($source_amount - $msg['gross']) > .01) {
        $pretty_msg = json_encode($msg);
        watchdog('offline2civicrm', "Amount mismatch in row: " . $pretty_msg, NULL, WATCHDOG_ERROR);
        throw new WmfException(WmfException::INVALID_MESSAGE, "Amount mismatch during checks import");
      }

      $msg['currency'] = $currency;
    }

    // left-pad the zipcode
    // Unclear whether US needs to be handled. United States is valid from a csv &
    // gets this far. United States covered by a unit test.
    if (($msg['country'] === 'US' || $msg['country'] === 'United States') && !empty($msg['postal_code'])) {
      if (preg_match('/^(\d{1,4})(-\d+)?$/', $msg['postal_code'], $matches)) {
        $msg['postal_code'] = str_pad($matches[1], 5, "0", STR_PAD_LEFT);
        if (!empty($matches[2])) {
          $msg['postal_code'] .= $matches[2];
        }
      }
    }

    // Generate a transaction ID so that we don't import the same rows multiple times
    if (empty($msg['gateway_txn_id'])) {
      if ($msg['contact_type'] === 'Individual') {
        $name_salt = $msg['first_name'] . $msg['last_name'];
      }
      else {
        $name_salt = $msg['organization_name'];
      }

      if (!empty($msg['check_number'])) {
        $msg['gateway_txn_id'] = md5($msg['check_number'] . $name_salt);
      }
      else {
        // The scenario where this would happen is anonymous cash gifts.
        // the name would be 'Anonymous Anonymous' and there might be several on the same
        // day. Hence we rely on them all being carefully arranged in a spreadsheet and
        // no-one messing with the order. I was worried this was fragile but there
        // is no obvious better way.
        $msg['gateway_txn_id'] = md5($msg['date'] . $name_salt . $this->row_index);
      }
    }

    // Expand soft credit short names.
    if (!empty($msg['soft_credit_to'])) {
      $nickname_mapping = array(
        'Fidelity' => 'Fidelity Charitable Gift Fund',
        'Vanguard' => 'Vanguard Charitable Endowment Program',
        'Schwab' => 'Schwab Charitable Fund',
      );
      if (array_key_exists($msg['soft_credit_to'], $nickname_mapping)) {
        $msg['soft_credit_to'] = $nickname_mapping[$msg['soft_credit_to']];
      }
    }

    if (empty($msg['gateway'])) {
      $msg['gateway'] = 'generic_import';
    }

    foreach ($this->getDatetimeFields() as $field) {
      if (!empty($msg[$field]) && !is_numeric($msg[$field])) {
        $msg[$field] = wmf_common_date_parse_string($msg[$field]);
      }
    }

    // Allow yes or true as inputs for opt-out fields
    $optOutFields = array(
      'do_not_email',
      'do_not_mail',
      'do_not_phone',
      'do_not_sms',
      'do_not_solicit',
      'is_opt_out',
    );

    $trueValues = array(
      'yes',
      'y',
      'true',
      't',
      '1',
    );

    foreach ($optOutFields as $field) {
      if (isset($msg[$field])) {
        if (in_array(strtolower($msg[$field]), $trueValues)) {
          $msg[$field] = 1;
        }
        else {
          $msg[$field] = 0;
        }
      }
    }

  }

  /**
   * Do fancy stuff with the contribution we just created
   *
   * FIXME: We need to wrap each loop iteration in a transaction to
   * make this safe.  Otherwise we can easily die before adding the
   * second message, and skip it when resuming the import.
   *
   * @param array $contribution
   */
  protected function mungeContribution($contribution) {
  }

  protected function getDefaultValues() {
    return array(
      'contact_source' => 'check',
      'contact_type' => 'Individual',
      'country' => 'US',
    );
  }

  /**
   * Return column mappings
   *
   * @return array of {spreadsheet column title} => {normalized field name}
   */
  protected function getFieldMapping() {
    return array(
      'Additional Address 1' => 'supplemental_address_1',
      'Additional Address 2' => 'supplemental_address_2',
      'Batch' => 'import_batch_number',
      # deprecated, use External Batch Number instead.
      'Banner' => 'utm_source',
      'Campaign' => 'utm_campaign',
      'Check Number' => 'check_number',
      'City' => 'city',
      'Contribution Type' => 'raw_contribution_type',
      'Contribution Tracking ID' => 'contribution_tracking_id',
      'Country' => 'country',
      'Description of Stock' => 'stock_description',
      'Direct Mail Appeal' => 'direct_mail_appeal',
      'Do Not Email' => 'do_not_email',
      'Do Not Mail' => 'do_not_mail',
      'Do Not Phone' => 'do_not_phone',
      'Do Not SMS' => 'do_not_sms',
      'Do Not Solicit' => 'do_not_solicit',
      'Email' => 'email',
      'External Batch Number' => 'import_batch_number',
      'First Name' => 'first_name',
      'Gift Source' => 'gift_source',
      'Groups' => 'contact_groups',
      'Is Opt Out' => 'is_opt_out',
      'Last Name' => 'last_name',
      'Letter Code' => 'letter_code',
      'Medium' => 'utm_medium',
      'Middle Name' => 'middle_name',
      'Name' => 'org_contact_name',
      'No Thank You' => 'no_thank_you',
      'Notes' => 'notes',
      'Organization Name' => 'organization_name',
      'Original Amount' => 'gross',
      'Original Currency' => 'currency',
      'Payment Instrument' => 'payment_method',
      'Payment Gateway' => 'gateway',
      'Postal Code' => 'postal_code',
      'Postmark Date' => 'postmark_date',
      'Prefix' => 'name_prefix',
      'Raw Payment Instrument' => 'raw_payment_instrument',
      'Received Date' => 'date',
      'Relationship Type' => 'relationship_type',
      'Restrictions' => 'restrictions',
      'Soft Credit To' => 'soft_credit_to',
      'Source' => 'contribution_source',
      'State' => 'state_province',
      'Street Address' => 'street_address',
      'Suffix' => 'name_suffix',
      'Tags' => 'contact_tags',
      'Target Contact ID' => 'relationship_target_contact_id',
      'Thank You Letter Date' => 'thankyou_date',
      'Title' => 'org_contact_title',
      'Total Amount' => 'gross',
      # deprecated, use Original Amount
      'Transaction ID' => 'gateway_txn_id',
    );
  }

  /**
   * Date fields which must be converted to unix timestamps
   *
   * @return array of field names
   */
  protected function getDatetimeFields() {
    return array(
      'date',
      'thankyou_date',
      'postmark_date',
    );
  }

  /**
   * Columns which must exist in the spreadsheet
   *
   * This is just a "schema" check.  We don't require that the fields contain
   * data.
   *
   * @return array of column header titles
   */
  abstract protected function getRequiredColumns();

  /**
   * Fields that must not be empty in the normalized message
   *
   * @return array of normalized message field names
   */
  protected function getRequiredData() {
    return array(
      'currency',
      'date',
      'gross',
    );
  }

  /**
   * Ensure the file contains all the data we need.
   *
   * @param array $headers Column names
   *
   * @throws WmfException if required columns are missing
   */
  protected function validateColumns($headers) {
    $failed = array();
    foreach ($this->getRequiredColumns() as $name) {
      if (!array_key_exists($name, $headers)) {
        $failed[] = $name;
      }
    }
    if ($failed) {
      throw new WmfException(WmfException::INVALID_FILE_FORMAT, "This file is missing column headers: " . implode(", ", $failed));
    }
  }

  /**
   * Create a file for output.
   *
   * @param string $uri
   * @param string $type
   * @param array $headers
   *
   * @return resource
   */
  public function createOutputFile($uri, $type, $headers) {
    $file = fopen($uri, 'w');
    fputcsv($file, array_merge(array($type => $type), array_flip($headers)));
    return $file;
  }

  /**
   * Close our csv files and set the messages to display.
   */
  public function closeFilesAndSetMessage() {
    foreach (array(
               $this->skippedFileResource,
               $this->errorFileResource,
               $this->ignoredFileResource,
               $this->allMissedFileResource,
               $this->allNotMatchedFileResource,
             ) as $fileResource) {
      if ($fileResource) {
        fclose($fileResource);
      }
    }

    $notImported = $this->totalNumberRows - $this->numberSucceededRows;
    if ($notImported === 0) {
      $this->messages['Result'] = ts("All rows were imported");
    }
    else {
      $this->messages['Result'] = ts("%1 out of %2 rows were imported.", array(
        '1' => $this->numberSucceededRows,
        2 => $this->totalNumberRows,
      ));

      if ($this->numberDuplicateRows !== $notImported && $this->numberErrorRows !== $notImported && $this->numberIgnoredRows !== $notImported) {
        // If the number of rows not imported is the same as the number skipped, or the number of errors etc
        // then the Not Imported csv will duplicate that & it is confusing to provide a link to it.
        $this->setMessage($this->all_missed_file_uri, 'not imported', $notImported);
      }
    }

    if ($this->numberErrorRows) {
      $this->setMessage($this->error_file_uri, 'Error', $this->numberErrorRows);
    }
    if ($this->numberIgnoredRows) {
      $this->setMessage($this->ignored_file_uri, 'Ignored', $this->numberIgnoredRows);
    }
    if ($this->numberDuplicateRows) {
      $this->setMessage($this->skipped_file_uri, 'Duplicate', $this->numberDuplicateRows);
    }
    if ($this->allNotMatchedFileResource) {
      $this->setMessage($this->all_not_matched_to_existing_contacts_file_uri, ts("Rows where new contacts were created"), $this->numberContactsCreated);
    }
  }

  /**
   * Set a message relating to this output.
   *
   * @param string $uri
   * @param string $type
   * @param int $count
   */
  public function setMessage($uri, $type, $count) {
    $row = ($count > 1) ? 'rows' : 'row';
    // The file name is the middle part, minus 'temporary://' and .csv'.
    // We stick to this rigid assumption because if it changes we might want to re-evaluate the security aspects of
    // allowing people to download csv files from the temp directory based on role.
    $this->messages[$type] = "$count $type $row logged to <a href='/import_output/" . substr($uri, 12, -4) . "'> file</a>.";
    ChecksImportLog::record($this->messages[$type]);
  }

  /**
   * Do the actual import.
   *
   * @param array $msg
   *
   * @return array
   * @throws \WmfException
   */
  public function doImport($msg) {
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->mungeContribution($contribution);
    return $contribution;
  }

  /**
   * Validate that required fields are present.
   *
   * @param array $msg
   *
   * @throws \WmfException
   */
  protected function validateRequiredFields($msg) {
    $failed = array();
    foreach ($this->getRequiredData() as $key) {
      if (!array_key_exists($key, $msg) or empty($msg[$key])) {
        $failed[] = $key;
      }
    }
    if ($failed) {
      throw new WmfException(WmfException::CIVI_REQ_FIELD, t("Missing required fields @keys during check import", array("@keys" => implode(", ", $failed))));
    }
  }

  /**
   * Check for any existing contributions for the given transaction.
   *
   * @param $msg
   *
   * @return array|bool
   * @throws \WmfException
   */
  protected function checkForExistingContributions($msg) {
    return  wmf_civicrm_get_contributions_from_gateway_id($msg['gateway'], $msg['gateway_txn_id']);
  }

  /**
   * Get any fields that can be set on import at an import wide level.
   */
  public function getImportFields() {
    return array();
  }

  /**
   * Validate the fields submitted on the import form.
   *
   * @param array $formFields
   *
   * @throws \Exception
   */
  public function validateFormFields($formFields) {}

  /**
   * Get the ID of our anonymous contact.
   *
   * @return int|NULL
   */
  protected function getAnonymousContactID() {
    static $contactID = NULL;
    if (!$contactID) {
      $contactID = civicrm_api3('Contact', 'getvalue', array(
        'return' => 'id',
        'contact_type' => 'Individual',
        'first_name' => 'Anonymous',
        'last_name' => 'Anonymous',
        'email' => 'fakeemail@wikimedia.org',
      ));
    }
    return $contactID;
  }

  /**
   * Set all address fields to empty to prevent an address being created or updated.
   *
   * @param array $msg
   */
  protected function unsetAddressFields(&$msg) {
    foreach (array('city', 'street_address', 'postal_code', 'state_province', 'country') as $locationField) {
      $msg[$locationField] = '';
    }
  }

  /**
   * @param $data
   * @param $headers
   */
  protected function importRow($data, $headers) {
    $msg = $this->parseRow($data);
    $existing = $this->checkForExistingContributions($msg);

    // check to see if we have already processed this check
    if ($existing) {
      $skipped = $this->handleDuplicate($existing);
      if ($skipped) {
        if ($this->numberDuplicateRows === 0) {
          $this->skippedFileResource = $this->createOutputFile($this->skipped_file_uri, 'Skipped', $headers);
        }
        $this->numberDuplicateRows++;
        fputcsv($this->skippedFileResource, array_merge(array('Skipped' => 'Duplicate'), $data));
        fputcsv($this->allMissedFileResource, array_merge(array('Not Imported' => 'Duplicate'), $data));

      }
      else {
        $this->numberSucceededRows++;
      }
      return;
    }

    // tha business.
    $contribution = WmfDatabase::transactionalCall(array(
      $this,
      'doImport',
    ), array($msg));

    if (empty($msg['contact_id'])) {
      $this->numberContactsCreated++;
      if (!$this->allNotMatchedFileResource) {
        $this->allNotMatchedFileResource = $this->createOutputFile($this->all_not_matched_to_existing_contacts_file_uri, 'Not Matched to existing', $headers);
      }
      fputcsv($this->allNotMatchedFileResource, array_merge(array('Not matched to existing' => 'Informational'), $data));
    }

    watchdog('offline2civicrm',
      'Import checks: Contribution imported successfully (@id): !msg', array(
        '@id' => $contribution['id'],
        '!msg' => print_r($msg, TRUE),
      ), WATCHDOG_INFO
    );
    $this->numberSucceededRows++;
  }

  /**
   * @param $row
   */
  protected function processRow($row) {
    // Reset the PHP timeout for each row.
    set_time_limit(30);

    $this->row_index++;

    // Zip headers and row into a dict
    $data = array_combine(array_keys($this->headers), array_slice($row, 0, count($this->headers)));

    // Strip whitespaces
    foreach ($data as $key => &$value) {
      $value = trim($value);
    }

    try {
      if ($this->errorStreakCount >= $this->errorStreakThreshold) {
        throw new IgnoredRowException(WmfException::IMPORT_CONTRIB, 'Error limit reached');
      }
      $this->importRow($data, $this->headers);
    }
    catch (EmptyRowException $ex) {
      return;
    }
    catch (IgnoredRowException $ex) {
      if ($this->numberIgnoredRows === 0) {
        $this->ignoredFileResource = $this->createOutputFile($this->ignored_file_uri, 'Ignored', $this->headers);
      }
      fputcsv($this->ignoredFileResource, array_merge(array('Ignored' => $ex->getUserErrorMessage()), $data));
      fputcsv($this->allMissedFileResource, array_merge(array('Not Imported' => 'Ignored: ' . $ex->getUserErrorMessage()), $data));
      $this->numberIgnoredRows++;
      return;
    }
    catch (WmfException $ex) {
      if ($this->numberErrorRows === 0) {
        $this->errorFileResource = $this->createOutputFile($this->error_file_uri, 'Error', $this->headers);
      }

      $this->numberErrorRows++;
      fputcsv($this->errorFileResource, array_merge(array('error' => $ex->getUserErrorMessage()), $data));
      fputcsv($this->allMissedFileResource, array_merge(array('Not Imported' => 'Error: ' . $ex->getUserErrorMessage()), $data));


      ChecksImportLog::record(t("Error in line @rownum: (@exception) @row", array(
        '@rownum' => $this->row_index,
        '@row' => implode(', ', $row),
        '@exception' => $ex->getUserErrorMessage(),
      )));

      if ($this->errorStreakStart + $this->errorStreakCount < $this->row_index) {
        // The last result must have been a success.  Restart streak counts.
        $this->errorStreakStart = $this->row_index;
        $this->errorStreakCount = 0;
      }
      $this->errorStreakCount++;
      $this->lastErrorMessage = $ex->getUserErrorMessage();
      $this->lastErrorRowNumber = $this->row_index;
    }
    return;
  }

  protected function doFinish() {
    $this->totalNumberRows = $this->row_index - 1;

    if ($this->errorStreakCount >= $this->errorStreakThreshold) {
      $this->closeFilesAndSetMessage();
      throw new Exception("Import aborted due to {$this->errorStreakCount} consecutive errors, last error was at row {$this->lastErrorRowNumber}: {$this->lastErrorMessage }. " . implode(' ', $this->messages)
      );
    }
    array_unshift($this->messages, "Successful import!");

    // Unset time limit.
    set_time_limit(0);

    ChecksImportLog::record(implode(' ', $this->messages));
    watchdog('offline2civicrm', implode(' ', $this->messages), array(), WATCHDOG_INFO);
    $this->closeFilesAndSetMessage();
  }

}
