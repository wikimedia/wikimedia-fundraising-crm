<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\Relationship;
use Civi\Api4\RelationshipType;
use Civi\Api4\WMFContact;
use Civi\Core\Exception\DBQueryException;
use Civi\WMFException\EmptyRowException;
use Civi\WMFException\IgnoredRowException;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\Contact;
use Civi\WMFHelper\Contribution as ContributionHelper;
use Civi\WMFHelper\Database;
use Civi\WMFQueueMessage\DonationMessage;
use Civi\WMFTransaction;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\Writer;
use SmashPig\Core\Context;
use SmashPig\CrmLink\Messages\SourceFields;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;
use SmashPig\Core\UtcDate;

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
      \CRM_SmashPig_ContextWrapper::setMessageSource(
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
   * @param array $msg
   * @return array
   * @throws CRM_Core_Exception
   * @throws WMFException
   */
  public function insertRow(array $msg): array {
    $message = new DonationMessage($msg);
    $msg = $message->normalize();
    if (!$msg['contact_id']) {
      $contact = WMFContact::save(FALSE)
        ->setMessage($msg)
        ->execute()->first();
      $msg['contact_id'] = $contact['id'];
    }
    else {
      // Note that the difference between this & the above
      // is SUPER confusing because of the code history.
      // For now, we have copied same wrangling as is done in message_insert
      // but hope to consolidate on 1 call.
      $this->handleUpdate($msg);
    }
    if (!empty($msg['no_thank_you'])) {
      $msg['contribution_extra.no_thank_you'] = $msg['no_thank_you'];
    }
    return $this->importContribution($msg);
  }

  /**
   * Insert the contribution record.
   *
   * This is an internal method, you must be looking for
   *
   * @param array $msg
   *
   * @return array
   *
   * @throws \Civi\WMFException\WMFException
   * @throws \CRM_Core_Exception
   *
   */
  private function importContribution($msg) {
    $transaction = WMFTransaction::from_message($msg);
    $trxn_id = $transaction->get_unique_id();

    $contribution = [
      'contact_id' => $msg['contact_id'],
      'total_amount' => $msg['gross'],
      'financial_type_id' => $msg['financial_type_id'],
      'payment_instrument_id' => $msg['payment_instrument_id'],
      'fee_amount' => $msg['fee'],
      'net_amount' => $msg['net'],
      'trxn_id' => $trxn_id,
      'receive_date' => wmf_common_date_unix_to_civicrm($msg['date']),
      'currency' => $msg['currency'],
      'source' => $msg['original_currency'] . ' ' . CurrencyRoundingHelper::round($msg['original_gross'], $msg['original_currency']),
      'contribution_recur_id' => $msg['contribution_recur_id'],
      'check_number' => $msg['check_number'],
      'soft_credit_to' => $msg['soft_credit_to'] ?? NULL,
      'debug' => TRUE,
    ];

    // Add the contribution status if its known and not completed
    if (!empty($msg['contribution_status_id'])) {
      $contribution['contribution_status_id'] = $msg['contribution_status_id'];
    }

    // Add the thank you date when it exists and is not null (e.g.: we're importing from a check)
    if (array_key_exists('thankyou_date', $msg) && is_numeric($msg['thankyou_date'])) {
      $contribution['thankyou_date'] = wmf_common_date_unix_to_civicrm($msg['thankyou_date']);
    }

    // Store the identifier we generated on payments
    $invoice_fields = ['invoice_id', 'order_id'];
    foreach ($invoice_fields as $invoice_field) {
      if (!empty($msg[$invoice_field])) {
        $contribution['invoice_id'] = $msg[$invoice_field];
        // The invoice_id column has a unique constraint
        if ($msg['recurring']) {
          $contribution['invoice_id'] .= '|recur-' . UtcDate::getUtcTimestamp();
        }
        break;
      }
    }

    $customFields = (array) Contribution::getFields(FALSE)
      ->addWhere('custom_field_id', 'IS NOT EMPTY')
      ->addSelect('name')
      ->execute()->indexBy('name');
    $contribution += array_intersect_key($msg, $customFields);

    \Civi::log('wmf')->debug('wmf_civicrm: Contribution array for contribution create {contribution}: ', ['contribution' => $contribution, TRUE]);
    try {
      $contributionAction = Contribution::create(FALSE)
        ->setValues(array_merge($contribution, ['skipRecentView' => 1]));
      if (!empty($msg['soft_credit_to'])) {
        // @todo - consider moving this back to the import class!
        // We don't do soft credit for Queue purposes do we?
        $contributionSoftAction = ContributionSoft::create(FALSE)
          ->setValues([
            'contribution_id' => '$id',
            'contact_id' => $msg['soft_credit_to'],
            'currency' => $msg['currency'],
            'amount' => $msg['gross'],
            // Traditionally this has wound up being NULL on production because it was calling
            // CRM_Core_OptionGroup::getDefaultValue("soft_credit_type") which ran a query each time but
            // did not find a default. Locally a default is found. The newer import approach usually
            // does set soft credit type and accounts for the small number of soft credits with type
            // set in our DB.
            'soft_credit_type_id' => NULL,
          ]);
        $contributionAction->addChain('ContributionSoft', $contributionSoftAction);
      }
      $contribution_result = $contributionAction->execute()->first();
      Civi::log('wmf')->debug('wmf_civicrm: Successfully created contribution {contribution_id} for contact {contact_id}', [
        'contribution_id' => $contribution_result['id'],
        'contact_id' => $contribution['contact_id'],
      ]);
      return $contribution_result;
    }
    catch (DBQueryException $e) {
      Civi::log('wmf')->info('wmf_civicrm: SQL Error inserting contribution: {message} {code}', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
      // Constraint violations occur when data is rolled back to resolve a deadlock.
      if (in_array($e->getDBErrorMessage(), ['constraint violation', 'deadlock', 'database lock timeout'], TRUE)) {
        // @todo - consider just re-throwing here.... it will be caught higher up.
        throw new WMFException(WMFException::DATABASE_CONTENTION, 'Contribution not saved due to database load', $e->getErrorData());
      }
      // Rethrowing this here will cause it to be caught by the next catch
      // as it extends CRM_Core_Exception.
      throw $e;
    }
    catch (CRM_Core_Exception $e) {
      Civi::log('wmf')->info('wmf_civicrm: Error inserting contribution: {message} {code}', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
      $duplicate = 0;

      try {
        if (array_key_exists('invoice_id', $contribution)) {
          \Civi::log('wmf')->info('wmf_civicrm : Checking for duplicate on invoice ID {invoice_id}', ['invoice_id' => $contribution['invoice_id']]);
          $invoice_id = $contribution['invoice_id'];
          $duplicate = civicrm_api3("Contribution", "getcount", ["invoice_id" => $invoice_id]);
        }
        if ($duplicate > 0) {
          // We can't retry the insert here because the original API
          // error has marked the Civi transaction for rollback.
          // This WMFException code has special handling in the
          // WmfQueueConsumer that will alter the invoice_id before
          // re-queueing the message.
          throw new WMFException(
            WMFException::DUPLICATE_INVOICE,
            'Duplicate invoice ID, should modify and retry',
            $e->getExtraParams()
          );
        }
        else {
          throw new WMFException(
            WMFException::INVALID_MESSAGE,
            'Cannot create contribution, civi error!',
            $e->getExtraParams()
          );
        }
      }
      catch (CRM_Core_Exception $eInner) {
        throw new WMFException(
          WMFException::INVALID_MESSAGE,
          'Cannot create contribution, civi error!',
          $eInner->getExtraParams()
        );
      }
    }
  }

  /**
   * Handle a contact update - this is moved here but not yet integrated.
   *
   * Calling this directly is deprecated - we are working towards eliminating that.
   *
   * This is an interim step... getting it onto the same class.
   *
   * @param array $msg
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function handleUpdate(array $msg): void {
    $updateFields = [
      'do_not_email',
      'do_not_mail',
      'do_not_phone',
      'do_not_trade',
      'do_not_sms',
      'is_opt_out',
      'prefix_id:label',
      'suffix_id:label',
      'legal_identifier',
      'addressee_custom',
      'addressee_display',
      'Partner.Partner',
    ];
    $updateParams = array_intersect_key($msg, array_fill_keys($updateFields, TRUE));

    if (($msg['contact_type'] ?? NULL) === 'Organization') {
      // Find which of these keys we have update values for.
      $customFieldsToUpdate = array_filter(array_intersect_key($msg, array_fill_keys([
        'Organization_Contact.Name',
        'Organization_Contact.Email',
        'Organization_Contact.Phone',
        'Organization_Contact.Title',
      ], TRUE)));
      if (!empty($customFieldsToUpdate)) {
        if ($msg['gross'] >= 25000) {
          // See https://phabricator.wikimedia.org/T278892#70402440)
          // 25k plus gifts we keep both names for manual review.
          $existingCustomFields = \Civi\Api4\Contact::get(FALSE)
            ->addWhere('id', '=', $msg['contact_id'])
            ->setSelect(array_keys($customFieldsToUpdate))
            ->execute()
            ->first();
          foreach ($customFieldsToUpdate as $fieldName => $value) {
            if (stripos($existingCustomFields[$fieldName], $value) === FALSE) {
              $updateParams[$fieldName] = empty($existingCustomFields[$fieldName]) ? $value : $existingCustomFields[$fieldName] . '|' . $value;
            }
          }
        }
        else {
          $updateParams = array_merge($updateParams, $customFieldsToUpdate);
        }
      }
    }
    else {
      // Individual-only custom fields
      if (!empty($msg['employer'])) {
        // WMF-only custom field
        $updateParams['Communication.Employer_Name'] = $msg['employer'];
      }
      if (!empty($msg['language'])) {
        // Only update this if we've got something on the message, so we don't
        // overwrite previous good data with some lame default.
        $updateParams['preferred_language'] = $msg['language'];
      }
    }
    if (!empty($updateParams)) {
      \Civi\Api4\Contact::update(FALSE)
        ->addWhere('id', '=', $msg['contact_id'])
        ->setValues($updateParams)
        ->execute();
    }
    $this->createEmployerRelationshipIfSpecified($msg['contact_id'], $msg);

    // We have set the bar for invoking a location update fairly high here - ie state,
    // city or postal_code is not enough, as historically this update has not occurred at
    // all & introducing it this conservatively feels like a safe strategy.
    if (!empty($msg['street_address'])) {
      $this->wmf_civicrm_message_address_update($msg, $msg['contact_id']);
    }
    if (!empty($msg['email'])) {
      $this->wmf_civicrm_message_email_update($msg, $msg['contact_id']);
    }
  }

  /**
   * Update address for a contact.
   *
   * @param array $msg
   * @param int $contact_id
   *
   * @throws \Civi\WMFException\WMFException
   *
   */
  private function wmf_civicrm_message_address_update($msg, $contact_id) {
    // CiviCRM does a DB lookup instead of checking the pseudoconstant.
    // @todo fix Civi to use the pseudoconstant.
    $country_id = $this->wmf_civicrm_get_country_id($msg['country']);
    if (!$country_id) {
      return;
    }
    $address = [
      'is_primary' => 1,
      'street_address' => $msg['street_address'],
      'supplemental_address_1' => !empty($msg['supplemental_address_1']) ? $msg['supplemental_address_1'] : '',
      'city' => $msg['city'],
      'postal_code' => $msg['postal_code'],
      'country_id' => $country_id,
      'country' => $msg['country'],
      'is_billing' => 1,
      'debug' => 1,
    ];
    if (!empty($msg['state_province'])) {
      $address['state_province'] = $msg['state_province'];
      $address['state_province_id'] = $this->wmf_civicrm_get_state_id($country_id, $msg['state_province']);
    }

    $address_params = [
      'contact_id' => $contact_id,
      'location_type_id' => \CRM_Core_BAO_LocationType::getDefault()->id,
      'values' => [$address],
    ];

    try {
      civicrm_api3('Address', 'replace', $address_params);
    }
    catch (CRM_Core_Exception $e) {
      // Constraint violations occur when data is rolled back to resolve a deadlock.
      $code = $e->getErrorCode() === 'constraint violation' ? WMFException::DATABASE_CONTENTION : WMFException::IMPORT_CONTACT;
      throw new WMFException($code, "Couldn't store address for the contact.", $e->getExtraParams());
    }
  }

  private function wmf_civicrm_get_country_id($raw) {
    // ISO code, or outside chance this could be a lang_COUNTRY pair
    if (preg_match('/^([a-z]+_)?([A-Z]{2})$/', $raw, $matches)) {
      $code = $matches[2];

      $iso_cache = CRM_Core_PseudoConstant::countryIsoCode();
      $id = array_search(strtoupper($code), $iso_cache);
      if ($id !== FALSE) {
        return $id;
      }
    }
    else {
      $country_cache = CRM_Core_PseudoConstant::country(FALSE, FALSE);
      $id = array_search($raw, $country_cache);
      if ($id !== FALSE) {
        return $id;
      }
    }

    \Civi::log('wmf')->notice('wmf_civicrm: Cannot find country: [{country}]',
      ['country' => $raw]
    );
    return FALSE;
  }

  /**
   * Get the state id for the named state in the given country.
   *
   * @param int $country_id
   * @param string $state
   *
   * @return int|null
   */
  private function wmf_civicrm_get_state_id($country_id, $state) {
    $stateID = CRM_Core_DAO::singleValueQuery('
  SELECT id
FROM civicrm_state_province s
WHERE
    s.country_id = %1
    AND ( s.abbreviation = %2 OR s.name = %3)
  ', [
      1 => [$country_id, 'String'],
      2 => [$state, 'String'],
      3 => [$state, 'String'],
    ]);
    if ($stateID) {
      return (int) $stateID;
    }

    \Civi::log('wmf')->notice('wmf_civicrm: Cannot find state: {state} (country {country})',
      ['state' => $state, 'country' => $country_id]
    );
  }

  /**
   * Updates the email for a contact.
   *
   * @param array $msg
   * @param int $contact_id
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function wmf_civicrm_message_email_update($msg, $contact_id) {
    try {
      $loc_type_id = isset($msg['email_location_type_id']) ? $msg['email_location_type_id'] : \CRM_Core_BAO_LocationType::getDefault()->id;
      if (!is_numeric($loc_type_id)) {
        $loc_type_id = CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Email', 'location_type_id', $loc_type_id);
      }
      $isPrimary = isset($msg['email_location_type_id']) ? 0 : 1;

      $emailParams = [
        'email' => $msg['email'],
        'is_primary' => $isPrimary,
        'is_billing' => $isPrimary,
        'contact_id' => $contact_id,
      ];

      // Look up contact's existing email to get the id and to determine
      // if the email has changed.
      $existingEmails = civicrm_api3("Email", 'get', [
        'return' => ['location_type_id', 'email', 'is_primary'],
        'contact_id' => $contact_id,
        'sequential' => 1,
        'options' => ['sort' => 'is_primary'],
      ])['values'];

      if (!empty($existingEmails)) {
        foreach ($existingEmails as $prospectiveEmail) {
          // We will update an existing one if it has the same email or the same
          // location type it, preferring same email+location type id over
          // same email over same location type id.
          if ($prospectiveEmail['email'] === $msg['email']) {
            if (empty($existingEmail)
              || $existingEmail['email'] !== $msg['email']
              || $prospectiveEmail['location_type_id'] == $loc_type_id
            ) {
              $existingEmail = $prospectiveEmail;
            }
          }
          elseif ($prospectiveEmail['location_type_id'] == $loc_type_id) {
            if (empty($existingEmail)) {
              $existingEmail = $prospectiveEmail;
            }
          }
        }

        if (!empty($existingEmail)) {
          if (strtolower($existingEmail['email']) === strtolower($msg['email'])) {
            // If we have the email already it still may make sense
            // to update to primary if this is (implicitly) an update of
            // primary email
            if (!$isPrimary || $existingEmail['is_primary']) {
              return;
            }
          }
          $emailParams['id'] = $existingEmail['id'];
          $emailParams['on_hold'] = 0;
        }
      }

      civicrm_api3('Email', 'create', $emailParams);
    }
    catch (CRM_Core_Exception $e) {
      // Constraint violations occur when data is rolled back to resolve a deadlock.
      $code = (in_array($e->getErrorCode(), ['constraint violation', 'deadlock', 'database lock timeout'])) ? WMFException::DATABASE_CONTENTION : WMFException::IMPORT_CONTACT;
      throw new WMFException($code, "Couldn't store email for the contact.", $e->getExtraParams());
    }
  }

  /**
   * When employer_id is present in the message, create the 'Employee of' relationship,
   * specifying that it was provided by the donor if the source_type is 'payments'.
   * Also set any other employer relationships to inactive.
   *
   * @param int $contactId
   * @param array $msg
   * @throws WMFException
   */
  protected function createEmployerRelationshipIfSpecified(int $contactId, array $msg) {
    if (empty($msg['employer_id']) || $contactId == $msg['employer_id']) {
      // Do nothing if employer ID is unset or the same as the contact ID
      // The latter can happen when we're importing matching gifts
      return;
    }

    $existingRelationships = Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $contactId)
      ->addWhere('relationship_type_id:name', '=', 'Employee of')
      ->addSelect('is_active')
      ->addSelect('contact_id_b')
      ->addSelect('custom.*')
      ->execute();

    $needToAddNewRelationship = TRUE;
    $isProvidedByDonor = isset($msg['source_type']) && $msg['source_type'] === 'payments';
    $relationshipParams = [];
    if ($isProvidedByDonor) {
      $relationshipParams['Relationship_Metadata.provided_by_donor'] = 1;
    }

    foreach ($existingRelationships as $existingRelationship) {
      if ($existingRelationship['contact_id_b'] == $msg['employer_id']) {
        // Found existing relationship with the same employer
        $needToAddNewRelationship = FALSE;
        if (
          ($existingRelationship['is_active'] == FALSE) ||
          ($isProvidedByDonor && $existingRelationship['Relationship_Metadata.provided_by_donor'] == FALSE)
        ) {
          // Set is_active and provided_by_donor flag
          $values = array_merge($relationshipParams, ['is_active' => 1]);
          Relationship::update(FALSE)
            ->addWhere('id', '=', $existingRelationship['id'])
            ->setValues($values)
            ->execute();
        }
      }
      elseif ($existingRelationship['is_active'] == TRUE) {
        // Active relationship with different employer should be set to inactive
        Relationship::update(FALSE)
          ->addWhere('id', '=', $existingRelationship['id'])
          ->setValues(['is_active' => 0])
          ->execute();
      }
    }

    if ($needToAddNewRelationship) {
      $this->createRelationship($contactId, $msg['employer_id'], 'Employee of', $relationshipParams);
    }
  }

  /**
   * Create a relationship to another specified contact.
   *
   * @param int $contact_id
   * @param int $relatedContactID
   * @param string $relationshipType
   * @param array $customFields relationship-specific custom fields
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function createRelationship(int $contact_id, int $relatedContactID, string $relationshipType, array  $customFields = []): void {
    $params = array_merge($customFields, [
      'contact_id_a' => $contact_id,
      'contact_id_b' => $relatedContactID,
      'relationship_type_id:name' => $relationshipType,
      'is_active' => 1,
      'is_current_employer' => $relationshipType === 'Employee of',
    ]);

    try {
      Relationship::create(FALSE)
        ->setValues($params)
        ->execute();
    }
    catch (\CRM_Core_Exception $ex) {
      throw new WMFException(WMFException::IMPORT_CONTACT, $ex->getMessage());
    }
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

  protected function handleDuplicate($duplicateID) {
    \Civi::log('wmf')->info('offline2civicrm: Contribution matches existing contribution (id: {id}), skipping it.', ['id' => $duplicateID]);
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
    //TODO: Find a better way to check the DB for disabled gift source
    $disabledGiftSources = ['Community Gift', 'Benefactor Gift'];
    if (!empty($msg['gift_source']) && in_array($msg['gift_source'], $disabledGiftSources)) {
      unset($msg['gift_source']);
    }
    if (empty($msg['gateway'])) {
      $msg['gateway'] = $this->gateway;
    }
    if (isset($msg['raw_contribution_type'])) {
      $msg['financial_type_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', $msg['raw_contribution_type']);
    }

    if (isset($msg['organization_name'])
      // Assume individual if they have an individual-only relationship.
      && empty($msg['relationship.Holds a Donor Advised Fund of'])
    ) {
      $msg['contact_type'] = "Organization";
    }
    else {
      // If this is not an Organization contact, freak out if Name or Title are filled.
      if (!empty($msg['Organization_Contact.Name'])
        || !empty($msg['Organization_Contact.Title'])
      ) {
        throw new WMFException(WMFException::INVALID_MESSAGE, "Don't give a Name or Title unless this is an Organization contact.");
      }
    }

    $msg['gross'] = str_replace(',', '', trim($msg['gross'], '$'));

    if (isset($msg['contribution_source'])) {
      // Check that the message amounts match
      [$currency, $source_amount] = explode(' ', $msg['contribution_source']);

      if (abs($source_amount - $msg['gross']) > .01) {
        \Civi::log('wmf')->error('offline2civicrm: Amount mismatch in row: {message}', ['message' => $msg]);
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
      $msg['gateway_txn_id'] = ContributionHelper::generateTransactionReference($msg, $msg['date'], $msg['check_number'] ?? NULL, (int) $this->row_index);
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
    // Look up soft credit contact.
    if (!empty($msg['soft_credit_to']) && !is_numeric($msg['soft_credit_to'])) {
      $soft_credit_contact = civicrm_api3('Contact', 'Get', [
        'organization_name' => $msg['soft_credit_to'],
        'contact_type' => 'Organization',
        'sequential' => 1,
        'return' => 'id',
      ]);
      if ($soft_credit_contact['count'] !== 1) {
        throw new WMFException(
          WMFException::INVALID_MESSAGE,
          "Bad soft credit target, [${msg['soft_credit_to']}]"
        );
      }
      # FIXME: awkward to have the two fields.
      $msg['soft_credit_to'] = $soft_credit_contact['id'];
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
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash'),
      'restrictions' => 'Unrestricted - General',
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
      'Donor Specified' => 'Donor_Specified',
      'Email' => 'email',
      'External Batch Number' => 'import_batch_number',
      'Fee Amount' => 'fee',
      'First Name' => 'first_name',
      'Gift Source' => 'gift_source',
      'Is Opt Out' => 'is_opt_out',
      'Last Name' => 'last_name',
      'Letter Code' => 'letter_code',
      'Medium' => 'utm_medium',
      'Middle Name' => 'middle_name',
      'Money Order Number' => 'gateway_txn_id',
      // @todo deprecate this in favour of the more descriptive Organization Contact Name
      // If we need to use name to match a vendor do that in the specific import, not
      // generically.
      'Name' => 'Organization_Contact.Name',
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
      'Prefix' => 'prefix_id:label',
      'Raw Payment Instrument' => 'raw_payment_instrument',
      'Received Date' => 'date',
      'Relationship Type' => 'relationship_type',
      'Restrictions' => 'restrictions',
      'Soft Credit To' => 'soft_credit_to',
      'Source' => 'contribution_source',
      'State' => 'state_province',
      'Street Address' => 'street_address',
      'Suffix' => 'suffix_id:label',
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
   * @throws \CRM_Core_Exception
   */
  public function doImport($msg) {
    $contribution = $this->insertRow($msg);
    // It's not clear this is used in practice.
    if (!empty($msg['notes'])) {
      civicrm_api3("Note", "Create", [
        'entity_table' => 'civicrm_contact',
        'entity_id' => $contribution['contact_id'],
        'note' => $msg['notes'],
      ]);
    }
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
   * @return false|int
   * @throws \CRM_Core_Exception
   */
  protected function checkForExistingContributions($msg) {
    return ContributionHelper::exists($msg['gateway'], $msg['gateway_txn_id']);
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
   * @throws \CRM_Core_Exception
   */
  protected function getAnonymousContactID(): ?int {
    return Contact::getAnonymousContactID();
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
    $contribution = Database::transactionalCall([
      $this,
      'doImport',
    ], [$msg]);

    if (empty($msg['contact_id'])) {
      $this->markRowNotMatched($data);
    }

    \Civi::log('wmf')->info('offline2civicrm: Import checks: Contribution imported successfully ({id}): {message}', [
      'id' => $contribution['id'],
      'message' => print_r($msg, TRUE),
    ]);
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
   * @throws \Civi\WMFException\WMFException|\CRM_Core_Exception
   */
  protected function getOrganizationID(string $organizationName, bool $isCreateIfNotExists = FALSE): int {
    return Contact::getOrganizationID($organizationName, $isCreateIfNotExists, ['source' => $this->gateway . ' created via import']);
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
