<?php

use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Api4\RelationshipType;
use SmashPig\CrmLink\Messages\SourceFields;
use League\Csv\Reader;
use SmashPig\Core\Context;
use League\Csv\Writer;
use League\Csv\Statement;
use Civi\WMFException\WMFException;
use Civi\WMFException\EmptyRowException;
use Civi\WMFException\IgnoredRowException;

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
   * The import type descriptor.
   *
   * @var string
   */
  protected $gateway;

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
   * Number of skipped at the batch level due to inability to acquire lock.
   *
   * @var int
   */
  protected $totalBatchSkippedRows = 0;

  /**
   * @var array
   */
  protected $relationshipTypes = [];

  /**
   * @return int
   */
  public function getTotalBatchSkippedRows(): int {
    return $this->totalBatchSkippedRows;
  }

  /**
   * @param int $totalBatchSkippedRows
   */
  public function setTotalBatchSkippedRows(int $totalBatchSkippedRows): void {
    $this->totalBatchSkippedRows = $totalBatchSkippedRows;
  }

  /**
   * @return int
   */
  public function getNumberSucceededRows(): int {
    return $this->numberSucceededRows;
  }

  /**
   * @param int $numberSucceededRows
   */
  public function setNumberSucceededRows(int $numberSucceededRows): void {
    $this->numberSucceededRows = $numberSucceededRows;
  }

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


  protected $messages = [];

  protected $file_uri = '';

  /**
   * @return string|null
   */
  public function getFileUri(): ?string {
    return $this->file_uri;
  }

  /**
   * @param string|null $file_uri
   */
  public function setFileUri(?string $file_uri): void {
    $this->file_uri = $file_uri;
  }

  protected $error_file_uri = '';

  protected $skipped_file_uri = '';

  protected $ignored_file_uri = '';

  protected $all_missed_file_uri = '';

  protected $all_not_matched_to_existing_contacts_file_uri = '';

  protected $row_index;

  /**
   * @var Writer
   */
  protected $ignoredFileResource = NULL;

  /**
   * @var Writer
   */
  protected $skippedFileResource = NULL;

  /**
   * @var Writer
   */
  protected $errorFileResource = NULL;

  /**
   * @var Writer
   */
  protected $allMissedFileResource = NULL;

  /**
   * @var Writer
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
   * @return \League\Csv\Reader
   */
  public function getReader(): \League\Csv\Reader {
    return $this->reader;
  }

  /**
   * Set up the reader object.
   *
   * @throws \League\Csv\Exception
   */
  public function setUpReader() {
    $this->reader = Reader::createFromPath($this->file_uri, 'r');
    $this->reader->setHeaderOffset(0);
  }

  /**
   * @param string $file_uri path to the file
   * @param array $additionalFields
   *
   * @throws \Civi\WMFException\WMFException
   */
  function __construct($file_uri = NULL, $additionalFields = []) {
    $this->file_uri = $file_uri;
    global $user;
    $suffix = $user->uid . '.csv';
    $this->error_file_uri = str_replace('.csv', '_errors.' . $suffix, $file_uri);
    $this->skipped_file_uri = str_replace('.csv', '_skipped.' . $suffix, $file_uri);
    $this->ignored_file_uri = str_replace('.csv', '_ignored.' . $suffix, $file_uri);
    $this->all_missed_file_uri = str_replace('.csv', '_all_missed.' . $suffix, $file_uri);
    $this->all_not_matched_to_existing_contacts_file_uri = str_replace('.csv', '_all_not_matched.' . $suffix, $file_uri);
    $this->additionalFields = $additionalFields;

    if (Context::get()) {
      wmf_common_set_smashpig_message_source(
        'direct', 'Offline importer: ' . get_class($this)
      );
    }

    // If we have the file_uri we are constructing this to do an import. Without a file uri we are on the
    // user form & just getting field information.
    if ($file_uri) {
      // Note this ini is still recommeded - see https://csv.thephpleague.com/8.0/instantiation/
      ini_set('auto_detect_line_endings', TRUE);

      try {
        $this->setUpReader();
        $this->headers = _load_headers($this->reader->getHeader());
        $this->validateColumns();
        $this->createOutputFile($this->skipped_file_uri, 'Skipped');
        $this->createOutputFile($this->all_missed_file_uri, 'Not Imported');
        $this->createOutputFile($this->all_not_matched_to_existing_contacts_file_uri, 'Not Matched to existing');
        $this->createOutputFile($this->ignored_file_uri, 'Ignored');
        $this->createOutputFile($this->error_file_uri, 'Error');
      }
      catch (WMFException $e) {
        // Validate columns throws a WMFException - we just want to re-throw that one unchanged.
        throw $e;
      }
      catch (Exception $e) {
        throw new WMFException(WMFException::FILE_NOT_FOUND, 'Import checks: Could not open file for reading: ' . $this->file_uri);
      }
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
   * @param int $offset
   * @param int $limit
   *
   * @return array
   *   Output messages to display to the user.
   *
   * @throws \League\Csv\Exception
   */
  function import($offset = 0, $limit = 0) {
    if ($limit === 0) {
      $limit = $this->getRowCount();
    }
    ChecksImportLog::record("Beginning import of " . $this->getImportType() . " file {$this->file_uri}...");

    $stmt = (new Statement())
      ->offset($offset);
    if ($limit) {
      $stmt = $stmt->limit((int) $limit);
    }
    $this->setUpReader();
    $records = $stmt->process($this->getReader());

    $this->row_index = $offset;
    foreach ($records as $row) {
      $this->processRow($row);
    }

    $this->totalNumberRows = $this->row_index;
    $this->setMessages();
    // This is brought forward from previous iterations. I think it might not have been put in with
    // full understanding but without it our tests can't complete in the time they have so need to keep
    // for now.
    set_time_limit(0);
    return $this->messages;
  }

  /**
   * Get progress.
   *
   * We get all the information about where the import is up to.
   *
   * This is passed back to the batch api which serialises it & stores it,
   * and then unserialises it & passes it to the next batch.
   *
   * Only information that can be serialised can be passed between batches.
   *
   * @return array
   */
  public function getProgress() {
    return [
      'errorStreakCount' => $this->errorStreakCount,
      'errorStreakStart' => $this->errorStreakStart,
      'row_index' => $this->row_index,
      'messages' => $this->messages,
      'lastErrorMessage' => $this->lastErrorMessage,
      'lastErrorRowNumber' => $this->lastErrorRowNumber,
      'numberIgnoredRows' => $this->numberIgnoredRows,
      'numberContactsCreated' => $this->numberContactsCreated,
      'numberErrorRows' => $this->numberErrorRows,
      'numberSucceededRows' => $this->numberSucceededRows,
      'numberDuplicateRows' => $this->numberDuplicateRows,
      'isSuccess' => $this->isSuccess(),
    ];
  }

  /**
   * Set the values in the progress array on the class.
   *
   * @param $progress
   */
  public function setProgress($progress) {
    foreach ($progress as $key => $value) {
      if (property_exists($this, $key)) {
        $this->$key = $value;
      }
    }
  }

  /**
   * Get the type for log messages.
   *
   * @return string
   */
  protected function getImportType() {
    return get_called_class();
  }

  /**
   * Get the number of rows in the csv
   *
   * https://csv.thephpleague.com/9.0/reading/
   *
   * @return int
   */
  public function getRowCount() {
    return count($this->reader);
  }

  /**
   * Read a row and transform into normalized queue message form
   *
   * @param $data
   *
   * @return array queue message format
   *
   * @throws \Civi\WMFException\EmptyRowException
   * @throws \Civi\WMFException\WMFException
   */
  protected function parseRow($data) {
    $msg = [];

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
    watchdog('offline2civicrm', 'Contribution matches existing contribution (id: @id), skipping it.', ['@id' => $duplicate[0]['id']], WATCHDOG_INFO);
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
   * @throws \API_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function mungeMessage(&$msg) {
    if (empty($msg['gateway'])) {
      $msg['gateway'] = $this->gateway;
    }
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

    if (isset($msg['organization_name'])
      // Assume individual if they have an individual-only relationship.
      && empty($msg['relationship.Holds a Donor Advised Fund of'])
    ) {
      $msg['contact_type'] = "Organization";
    }
    else {
      // If this is not an Organization contact, freak out if Name or Title are filled.
      if (!empty($msg['org_contact_name'])
        || !empty($msg['org_contact_title'])
      ) {
        throw new WMFException(WMFException::INVALID_MESSAGE, "Don't give a Name or Title unless this is an Organization contact.");
      }
    }

    $msg['gross'] = str_replace(',', '', trim($msg['gross'], '$'));

    if (isset($msg['contribution_source'])) {
      // Check that the message amounts match
      [$currency, $source_amount] = explode(' ', $msg['contribution_source']);

      if (abs($source_amount - $msg['gross']) > .01) {
        $pretty_msg = json_encode($msg);
        watchdog('offline2civicrm', "Amount mismatch in row: " . $pretty_msg, NULL, WATCHDOG_ERROR);
        throw new WMFException(WMFException::INVALID_MESSAGE, "Amount mismatch during checks import");
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
      $nickname_mapping = [
        'Fidelity' => 'Fidelity Charitable Gift Fund',
        'Vanguard' => 'Vanguard Charitable Endowment Program',
        'Schwab' => 'Schwab Charitable Fund',
      ];
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
    $optOutFields = [
      'do_not_email',
      'do_not_mail',
      'do_not_phone',
      'do_not_sms',
      'do_not_solicit',
      'is_opt_out',
    ];

    $trueValues = [
      'yes',
      'y',
      'true',
      't',
      '1',
    ];

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
    return [
      'contact_source' => 'check',
      'contact_type' => 'Individual',
      'country' => 'US',
    ];
  }

  /**
   * Return column mappings
   *
   * @return array of {spreadsheet column title} => {normalized field name}
   */
  protected function getFieldMapping() {
    return [
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
      'Fee Amount' => 'fee',
      'First Name' => 'first_name',
      'Gift Source' => 'gift_source',
      'Groups' => 'contact_groups',
      'Is Opt Out' => 'is_opt_out',
      'Last Name' => 'last_name',
      'Letter Code' => 'letter_code',
      'Medium' => 'utm_medium',
      'Middle Name' => 'middle_name',
      'Money Order Number' => 'gateway_txn_id',
      // @todo deprecate this in favour of the more descriptive Organization Contact Name
      // If we need to use name to match a vendor do that in the specific import, not
      // generically.
      'Name' => 'org_contact_name',
      'No Thank You' => 'no_thank_you',
      'Notes' => 'notes',
      'Organization Name' => 'organization_name',
      'Organization Contact Name' => 'Organization_Contact.Name',
      'Organization Contact Email' => 'Organization_Contact.Email',
      'Organization Contact Phone' => 'Organization_Contact.Phone',
      'Organization Contact Title' => 'Organization_Contact.Title',
      'Original Amount' => 'gross',
      'Original Currency' => 'currency',
      'Partner' => 'Partner.Partner',
      'Payment Instrument' => 'payment_method',
      'Payment Gateway' => 'gateway',
      'Postal Code' => 'postal_code',
      'Postmark Date' => 'postmark_date',
      'Phone Number' => 'phone',
      'Phone' => 'phone',
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
      // This name is super wonky but it is what it is in the db.
      'Owns the Donor Advised Fund' => 'relationship.Holds a Donor Advised Fund of',
    ];
  }

  /**
   * Date fields which must be converted to unix timestamps
   *
   * @return array of field names
   */
  protected function getDatetimeFields() {
    return [
      'date',
      'thankyou_date',
      'postmark_date',
    ];
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
    return [
      'currency',
      'date',
      'gross',
    ];
  }

  /**
   * Ensure the file contains all the data we need.
   *
   * @throws Civi\WMFException\WMFException if required columns are missing
   */
  protected function validateColumns() {
    $failed = [];
    foreach ($this->getRequiredColumns() as $name) {
      if (!array_key_exists($name, $this->headers)) {
        $failed[] = $name;
      }
    }
    if ($failed) {
      throw new WMFException(WMFException::INVALID_FILE_FORMAT, "This file is missing column headers: " . implode(", ", $failed));
    }
  }

  /**
   * Create a file for output.
   *
   * @param string $uri
   * @param string $type
   *
   * @return Writer
   */
  public function createOutputFile($uri, $type) {
    // This fopen & fclose is clunky - I was just having trouble getting 'better looking'
    // variants to work.
    $file = fopen($uri, 'w');
    fclose($file);
    $writer = $this->openFile($uri);
    $writer->insertOne(array_merge([$type => $type], array_flip($this->headers)));
    return $writer;
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
    $this->messages[$type] = "$count $type $row " . ($uri ? "logged to <a href='/import_output/" . substr($uri, 12, -4) . "'> file</a>." : '');
  }

  /**
   * Do the actual import.
   *
   * @param array $msg
   *
   * @return array
   * @throws \Civi\WMFException\WMFException
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function doImport($msg) {
    $contribution = wmf_civicrm_contribution_message_import($msg);
    $this->mungeContribution($contribution);
    foreach ($msg as $key => $value) {
      if (strpos($key, 'relationship.') === 0) {
        $relationshipNameAB = substr($key, 13);
        $this->createRelatedOrganization($relationshipNameAB, $value, $contribution['contact_id']);
      }
    }

    return $contribution;
  }

  /**
   * Validate that required fields are present.
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function validateRequiredFields($msg) {
    $failed = [];
    foreach ($this->getRequiredData() as $key) {
      if (!array_key_exists($key, $msg) or empty($msg[$key])) {
        $failed[] = $key;
      }
    }
    if ($failed) {
      throw new WMFException(WMFException::CIVI_REQ_FIELD, t("Missing required fields @keys during check import", ["@keys" => implode(", ", $failed)]));
    }
  }

  /**
   * Check for any existing contributions for the given transaction.
   *
   * @param $msg
   *
   * @return array|bool
   * @throws \Civi\WMFException\WMFException
   */
  protected function checkForExistingContributions($msg) {
    return wmf_civicrm_get_contributions_from_gateway_id($msg['gateway'], $msg['gateway_txn_id']);
  }

  /**
   * Get any fields that can be set on import at an import wide level.
   */
  public function getImportFields() {
    return [];
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
   * @throws \CiviCRM_API3_Exception
   */
  protected function getAnonymousContactID() {
    static $contactID = NULL;
    if (!$contactID) {
      $contactID = (int) civicrm_api3('Contact', 'getvalue', array(
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
   *
   * @throws \Civi\WMFException\EmptyRowException
   * @throws \Civi\WMFException\WMFException
   */
  protected function importRow($data): void {
    $msg = $this->parseRow($data);
    $existing = $this->checkForExistingContributions($msg);

    // check to see if we have already processed this check
    if ($existing) {
      $skipped = $this->handleDuplicate($existing);
      if ($skipped) {
        $this->markRowSkipped($data);
      }
      else {
        $this->numberSucceededRows++;
      }
      return;
    }

    // tha business.
    $contribution = WmfDatabase::transactionalCall([
      $this,
      'doImport',
    ], [$msg]);

    if (empty($msg['contact_id'])) {
      $this->markRowNotMatched($data);
    }

    watchdog('offline2civicrm',
      'Import checks: Contribution imported successfully (@id): !msg', [
        '@id' => $contribution['id'],
        '!msg' => print_r($msg, TRUE),
      ], WATCHDOG_INFO
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
        throw new IgnoredRowException(WMFException::IMPORT_CONTRIB, 'Error limit reached');
      }
      $this->importRow($data);
    }
    catch (EmptyRowException $ex) {
      return;
    }
    catch (IgnoredRowException $ex) {
      $this->markRowIgnored($data, $ex->getUserErrorMessage());
      return;
    }
    catch (WMFException $ex) {
      $this->markRowError($row, $ex->getUserErrorMessage(), $data);
    }
    return;
  }

  /**
   * Has the import successfully completed.
   *
   * Successful means all rows have been processed (even if not all have
   * succeeded). This would be false if the importer had stopped processing due
   * to 10 successive errors.
   *
   * @return bool
   */
  public function isSuccess() {
    if ($this->errorStreakCount >= $this->errorStreakThreshold) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Mark the row as skipped.
   *
   * @param array $data
   */
  protected function markRowSkipped($data) {
    $this->numberDuplicateRows++;
    $this->outputResultRow('skipped', array_merge(['Skipped' => 'Duplicate'], $data));
    $this->outputResultRow('all_missed', array_merge(['Not Imported' => 'Duplicate'], $data));
  }

  /**
   * Mark the row as ignored.
   *
   * @param array $data
   * @param string $errorMessage
   */
  protected function markRowIgnored($data, $errorMessage) {
    $this->outputResultRow('ignored', array_merge(['Ignored' => $errorMessage], $data));
    $this->outputResultRow('all_missed', array_merge(['Not Imported' => 'Ignored: ' . $errorMessage], $data));
    $this->numberIgnoredRows++;
  }

  /**
   * Mark the row as not matched.
   *
   * @param array $data
   */
  protected function markRowNotMatched($data) {
    $this->numberContactsCreated++;
    $this->outputResultRow('all_not_matched_to_existing_contacts', array_merge(['Not matched to existing' => 'Informational'], $data));
  }

  /**
   * Mark row as an error.
   *
   * @param array $row Original row data.
   * @param string $errorMessage
   * @param array $data Processed data.
   *
   * @todo we are outputting 'data' but perhaps 'row' is better as it is the
   *   input.
   */
  protected function markRowError($row, $errorMessage, $data) {
    $this->numberErrorRows++;
    $this->outputResultRow('error', array_merge(['error' => $errorMessage], $data));
    $this->outputResultRow('all_missed', array_merge(['Not Imported' => 'Error: ' . $errorMessage], $data));

    ChecksImportLog::record(t("Error in line @rownum: (@exception) @row", [
      '@rownum' => $this->row_index,
      '@row' => implode(', ', $row),
      '@exception' => $errorMessage,
    ]));

    if ($this->errorStreakStart + $this->errorStreakCount < $this->row_index) {
      // The last result must have been a success.  Restart streak counts.
      $this->errorStreakStart = $this->row_index;
      $this->errorStreakCount = 0;
    }
    $this->errorStreakCount++;
    $this->lastErrorMessage = $errorMessage;
    // Add one because this is for human's to read & they start from one not Zero
    $this->lastErrorRowNumber = $this->row_index + 1;
  }

  /**
   * Open file for writing.
   *
   * @param path $uri
   *
   * @return Writer
   */
  protected function openFile($uri) {
    $writer = Writer::createFromPath($uri, 'a');
    return $writer;
  }

  protected function setMessages() {
    $notImported = $this->totalNumberRows - $this->numberSucceededRows;
    $this->messages[0] = $this->isSuccess() ? ts("Successful import!") : "Import aborted due to {$this->errorStreakCount} consecutive errors, last error was at row {$this->lastErrorRowNumber}: {$this->lastErrorMessage }. ";
    if ($notImported === 0) {
      $this->messages['Result'] = ts("All rows were imported");
    }
    else {
      $this->messages['Result'] = ts("%1 out of %2 rows were imported.", [
        '1' => $this->numberSucceededRows,
        2 => $this->totalNumberRows,
      ]);

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
   * Write a row to the relevant output file.
   *
   * @param string $type Type of output file
   *  - skipped
   *  - error
   *  - ignored
   *  - all_not_matched_to_contact
   *  - all_missed
   *  -
   * @param $data
   */
  protected function outputResultRow($type, $data) {
    $resource = $this->getResource($type);
    $resource->insertOne($data);
  }

  /**
   * Get the file resource, if instantiated.
   *
   * When using the batch api this object is instantiated at the start and the
   * original object is passed around, not the processed object. If a new batch
   * has been kicked off we will need to fire up the resource again.
   *
   * Note that the batch runs in separate php processes and only objects that
   * can be serialised can be passed around. File objects are not serialisable.
   *
   * @param $type
   *
   * @return Writer
   */
  protected function getResource($type) {
    $resourceName = $this->getResourceName($type);
    if (!$this->$resourceName) {
      $uri = $type . '_file_uri';
      $this->$resourceName = $this->openFile($this->$uri);
    }
    return $this->$resourceName;
  }

  /**
   * Get the name of the related resource.
   *
   * Of course it might have been easier not to mix camel & lc underscore -
   * just saying.
   *
   * @param $string
   *
   * @return string
   */
  protected function getResourceName($string) {
    $mappings = [
      'error' => 'errorFileResource',
      'all_missed' => 'allMissedFileResource',
      'all_not_matched_to_existing_contacts' => 'allNotMatchedFileResource',
      'skipped' => 'skippedFileResource',
      'ignored' => 'ignoredFileResource',
    ];
    return $mappings[$string];
  }

  /**
   * Get the id of the organization whose nick name (preferably) or name matches.
   *
   * If there are no possible matches this will fail. It will also fail if there
   * are multiple possible matches of the same priority (ie. multiple nick names
   * or multiple organization names.)
   *
   * @param string $organizationName
   * @param bool $isCreateIfNotExists
   *
   * @return int
   *
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function getOrganizationID(string $organizationName, bool $isCreateIfNotExists = FALSE): int {
    // Using the Civi Statics pattern for php caching makes it easier to reset in unit tests.
    if (!isset(\Civi::$statics[__CLASS__]['organization'][$organizationName])) {
      $contacts = civicrm_api3('Contact', 'get', ['nick_name' => $organizationName, 'contact_type' => 'Organization']);
      if ($contacts['count'] == 0) {
        $contacts = civicrm_api3('Contact', 'get', ['organization_name' => $organizationName, 'contact_type' => 'Organization']);
      }
      if ($contacts['count'] == 1) {
        \Civi::$statics[__CLASS__]['organization'][$organizationName] = $contacts['id'];
      }
      else {
        \Civi::$statics[__CLASS__]['organization'][$organizationName] = NULL;
        if ($isCreateIfNotExists) {
          \Civi::$statics[__CLASS__]['organization'][$organizationName] = Contact::create(FALSE)->setValues([
            'organization_name' => $organizationName,
            'source' => $this->gateway . ' created via import',
          ])->execute()->first()['id'];
        }
      }
    }
    if (\Civi::$statics[__CLASS__]['organization'][$organizationName]) {
      return \Civi::$statics[__CLASS__]['organization'][$organizationName];
    }
    throw new WMFException(
      WMFException::IMPORT_CONTRIB,
      t("Did not find exactly one Organization with the details: @organizationName. You will need to ensure a single Organization record exists for the contact first",
        [
          '@organizationName' => $organizationName,
        ]
      )
    );
  }

  /**
   * Create a relationship with a related organization, potentially creating the organization.
   *
   * @param string $relationshipNameAB
   * @param string $organizationName
   * @param int $contact_id
   *
   * @throws \API_Exception
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function createRelatedOrganization(string $relationshipNameAB, string $organizationName, int $contact_id): void {
    if (!isset($this->relationshipTypes[$relationshipNameAB])) {
      $this->relationshipTypes[$relationshipNameAB] = RelationshipType::get(FALSE)
        ->addWhere('name_a_b', '=', $relationshipNameAB)
        ->addSelect('id')->execute()->first()['id'];
    }

    $relatedContactID = $this->getOrganizationID($organizationName, TRUE);

    if (!count(Relationship::get(FALSE)
      ->addWhere('contact_id_b', '=', $contact_id)
      ->addWhere('contact_id_a', '=', $relatedContactID)
      ->addWhere('relationship_type_id', '=', $this->relationshipTypes[$relationshipNameAB])
      ->addSelect('id')->execute())) {
      // Relationship type is is a required field so if not found this would
      // throw an error and the line import would be rolled back. There would be
      // an error line in the csv presented to the user.
      Relationship::create(FALSE)->setValues([
        'contact_id_b' => $contact_id,
        'contact_id_a' => $relatedContactID,
        'relationship_type_id' => $this->relationshipTypes[$relationshipNameAB],
      ])->execute();
    }
  }

}
