<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Address;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Phone;
use Civi\Api4\Relationship;
use Civi\WMFException\WMFException;
use Civi\Api4\Email;
use Civi\WMFHelper\Database;
use Civi\Api4\Contact;
use Civi\WMFStatistic\ImportStatsCollector;
use Civi\WMFHelper\Language;

/**
 * Class Create.
 *
 * Create a contact with WMF special handling (both logical and legacy/scary).
 *
 * Potentially this could extend the main apiv4 Contact class but there are
 * some baby-steps to take first. In the meantime this at least allows
 * us to rationalise code into a class.
 *
 * @method $this setMessage(array $msg) Set WMF normalised values.
 * @method array getMessage() Get WMF normalised values.
 * @method $this setIsLowConfidenceNameSource(bool $isLowConfidenceNameSource) Set IsLowConfidenceNameSource.
 *
 * @package Civi\Api4
 */
class Save extends AbstractAction {

  /**
   * WMF style input.
   *
   * @var array
   */
  protected $message = [];

  /**
   * Indicate that donation came in via a low confidence name data source e.g.
   * Apple Pay
   *
   * @var bool
   */
  protected $isLowConfidenceNameSource;

  protected function getTimer(): \Statistics\Collector\AbstractCollector {
    return ImportStatsCollector::getInstance();
  }

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function _run(Result $result): void {
    $msg = $this->getMessage();

    $existingContact = $this->getExistingContact($msg);
    if ($existingContact) {
      $this->handleUpdate($existingContact);
      $result[] = ['id' => $existingContact['id']];
      return;
    }

    // Create the contact record
    $contact = [
      'contact_type' => $msg['contact_type'] ?? 'Individual',
      'source' => $msg['contact_source'] ?? 'online donation',
      'debug' => TRUE,
      // Major gifts wants greeting processing - but we are not sure speedwise.
      'skip_greeting_processing' => !\Civi::settings()->get('wmf_save_process_greetings_on_create'),
    ];
    if (!empty($msg['organization_name'])) {
      $contact['organization_name'] = $msg['organization_name'];
    }

    if (strtolower($contact['contact_type']) !== 'organization') {
      foreach (['first_name', 'last_name', 'middle_name', 'nick_name'] as $name) {
        if (isset($msg[$name])) {
          $contact[$name] = $msg[$name];
        }
      }
    }

    if (!empty($msg['email'])) {
      // For updates we are still using our own process which may or may not confer benefits
      // For inserts however we can rely on the core api.
      $contact['email_primary.email'] = $msg['email'];
    }
    // for gravy ACH, assign email as billing email
    if (!empty($msg['billing_email'])) {
      \Civi::log('wmf')->info('add additional billing email');
      $contact['email_billing.location_type_id:name'] = 'Billing';
      $contact['email_billing.email'] = $msg['billing_email'];
    }
    $preferredLanguage = $this->getPreferredLanguage($msg);
    if ($preferredLanguage) {
      $contact['preferred_language'] = $preferredLanguage;
    }
    $contact += $this->getApiReadyFields();
    // These fields have historically been permitted for create but not
    // update - or they would be in getApiReadyFields()
    $allowedCreateFields = [
      // opt_in is more nuanced on update.
      'Communication.opt_in',
      // do_not_solicit should probably be removed from the queue processing sub-system.
      // It was probably only used for legacy imports.
      'Communication.do_not_solicit',
      // Donors can select an employer on the form for us to send them matching gift info
      // Those messages should have both employer ID (creates a relationship) and name
      'Communication.Employer_Name',
      // Update DOES save these too - it does it with a separate api call
      // & filters off the prefix. Unclear if it is different for *reasons* or just cos.
      'phone_primary.phone',
      'phone_primary.phone_type_id:name',
      'phone_primary.location_type_id:name',
      'phone_primary.phone_data.recipient_id',
      'phone_primary.phone_data.phone_source',
      'phone_primary.phone_data.phone_update_date',
    ];
    foreach ($allowedCreateFields as $customField) {
      if (isset($this->message[$customField])) {
        $contact[$customField] = $this->message[$customField];
      }
    }

    if (Database::isNativeTxnRolledBack()) {
      throw new WMFException(WMFException::IMPORT_CONTACT, "Native txn rolled back before inserting contact");
    }
    // Attempt to insert the contact
    try {
      $this->startTimer('create_contact_civi_api');
      $contact_result = Contact::create(FALSE)
        ->setValues($contact)
        ->execute()->single();
      $this->stopTimer('create_contact_civi_api');
      \Civi::log('wmf')->debug('wmf_civicrm: Successfully created contact: {id}', ['id' => $contact_result['id']]);
      $this->createEmployerRelationshipIfSpecified($contact_result['id'], $msg);
      if (Database::isNativeTxnRolledBack()) {
        throw new WMFException(WMFException::IMPORT_CONTACT, "Native txn rolled back after inserting contact");
      }
    }
    // Soon we will only catch CRM_Core_Exception as the other exceptions are now aliased to it
    // preparatory to being phased out
    catch (\CRM_Core_Exception $ex) {
      if (in_array($ex->getErrorCode(), ['constraint violation', 'deadlock', 'database lock timeout'])) {
        throw new WMFException(
          WMFException::DATABASE_CONTENTION,
          'Contact could not be added due to database contention',
          $ex->getErrorData()
        );
      }
      throw new WMFException(
        WMFException::IMPORT_CONTACT,
        'Contact could not be added. Aborting import. Contact data was ' . print_r($contact, TRUE) . ' Original error: ' . $ex->getMessage()
        . ' Details: ' . print_r($ex->getErrorData(), TRUE),
        $ex->getErrorData()
      );
    }

    // Insert the location records if this is being called as a create.
    // For update it's handled in the update routing.
    try {
      $this->createAddress($msg, (int) $contact_result['id']);
    }
    catch (\CRM_Core_Exception $ex) {
      $hasContact = Contact::get(FALSE)
        ->addSelect('id')
        ->addWhere('id', '=', (int) $contact_result['id'])->execute()->first();
      // check contact_id exist in table
      if (!$hasContact) {
        // throw the DATABASE_CONTENTION exception will to trigger retry
        throw new WmfException(
          WmfException::DATABASE_CONTENTION,
          'Contact could not be added due to database contention',
          $ex->getExtraParams()
        );
      }
      else {
        throw $ex;
      }
    }

    if (Database::isNativeTxnRolledBack()) {
      throw new WMFException(WMFException::IMPORT_CONTACT, "Native txn rolled back after inserting contact auxiliary fields");
    }
    $result[] = $contact_result;
  }

  /**
   * Start the timer on a process.
   *
   * @param string $description
   */
  protected function startTimer($description) {
    $this->getTimer()->startImportTimer($description);
  }

  /**
   * Start the timer on a process.
   *
   * @param string $description
   */
  protected function stopTimer($description) {
    $this->getTimer()->endImportTimer($description);
  }

  /**
   * Get the preferred language.
   *
   * This is a bit of a nasty historical effort to come up with a civi-like
   * language string. It often creates nasty variants like 'es_NO' - Norwegian
   * Spanish - for spanish speakers who filled in the form while in Norway.
   *
   * Note that the function Civi\WMFHelper\Language::getLanguageCode is likely useful.
   *
   * We hateses it my precious.
   *
   * Bug https://phabricator.wikimedia.org/T279389 is open to clean this up.
   *
   *
   * @param array $msg
   * @return string
   */
  protected function getPreferredLanguage(array $msg): string {
    $incomingLanguage = $msg['language'] ?? '';
    $country = $msg['country'] ?? '';
    $preferredLanguage = '';
    if (!$incomingLanguage) {
      // TODO: use LanguageTag to prevent truncation of >2 char lang codes
      // guess from contribution_tracking data
      if (isset($msg['contribution_tracking_id']) && is_numeric($msg['contribution_tracking_id'])) {
        $tracking = ContributionTracking::get(FALSE)
          ->addWhere('id', '=', (int) $msg['contribution_tracking_id'])
          ->execute()
          ->first();
        if ($tracking and !empty($tracking['language'])) {
          if (strpos($tracking['language'], '-')) {
            // If we are already tracking variant, use that
            [$language, $variant] = explode('-', $tracking['language']);
            $preferredLanguage = $language . '_' . strtoupper($variant);
          }
          else {
            $preferredLanguage = $tracking['language'];
            if (!empty($tracking['country'])) {
              $preferredLanguage .= '_' . $tracking['country'];
            }
          }
        }
      }
      if (!$preferredLanguage) {
        \Civi::log('wmf')->info('wmf_civicrm Failed to guess donor\'s preferred language, falling back to some hideous default');
      }
    }
    else {
      if (strlen($incomingLanguage) > 2) {
        if ($this->checkLanguageExists($incomingLanguage)) {
          // If the language is already an existing full locale, don't mangle it
          $preferredLanguage = $incomingLanguage;
        }
        elseif (preg_match('/^[A-Za-z]+$/', $incomingLanguage)) {
          // If the language code is 3 or more letters, we can't easily shoehorn it
          // into CiviCRM. It's possible we could find better fallbacks, but for
          // now just go with the system default.
          $preferredLanguage = 'en_US';
        }
      }
      if (!$preferredLanguage) {
        $preferredLanguage = strtolower(substr($incomingLanguage, 0, 2));
        if ($country) {
          $preferredLanguage .= '_' . strtoupper(substr($country, 0, 2));
        }
      }
    }
    if ($preferredLanguage) {
      if (!$this->checkLanguageExists($preferredLanguage)) {
        try {
          $parts = explode('_', $preferredLanguage);
          // If we don't find a locale below then an exception will be thrown.
          $default_locale = Language::getLanguageCode($parts[0]);
          $preferredLanguage = $default_locale;
        }
        catch (\CRM_Core_Exception $ex) {
          $preferredLanguage = 'en_US';
        }
      }
    }
    return $preferredLanguage;
  }

  /**
   * Check if the language string exists.
   *
   * @param string $languageAbbreviation
   *
   * @return bool
   */
  private function checkLanguageExists($languageAbbreviation) {
    static $languages;
    if (empty($languages)) {
      $available_options = civicrm_api3('Contact', 'getoptions', [
        'field' => 'preferred_language',
      ]);
      $languages = $available_options['values'];
    }
    return !empty($languages[$languageAbbreviation]);
  }

  /**
   * Insert a new address for a contact.
   *
   * If updating or unsure use the marginally slower update function.
   *
   * @param array $msg
   * @param int $contact_id
   *
   * @throws \Civi\WMFException\WMFException
   */
  private function createAddress(array $msg, int $contact_id) {

    // We can do these lookups a bit more efficiently than Civi
    $country_id = $this->getCountryID($msg['country']);

    if (!$country_id) {
      return;
    }
    $address_params = [
      'contact_id' => $contact_id,
      'location_type_id' => \CRM_Core_BAO_LocationType::getDefault()->id,
      'is_primary' => 1,
      'street_address' => $msg['street_address'],
      'supplemental_address_1' => !empty($msg['supplemental_address_1']) ? $msg['supplemental_address_1'] : NULL,
      'city' => $msg['city'],
      'postal_code' => $msg['postal_code'],
      'country_id' => $country_id,
      'is_billing' => 1,
      // Once we are sure that the source is being specified by messages coming
      // from Fundraise Up, Paypal & any remaining imports that use
      // we can uncomment this source.
      // 'address_data.address_source' => $msg['address_data.address_source'] ?? 'donor',
      'address_data.address_update_date' => 'now',
    ];

    if (!empty($msg['state_province'])) {
      $address_params['state_province_id'] = $this->getStateID($country_id, $msg['state_province']);
    }
    if (Database::isNativeTxnRolledBack()) {
      throw new WMFException(WMFException::IMPORT_CONTACT, "Native txn rolled back before inserting address");
    }
    try {
      // @todo - remove this from here & do in pre like this
      // https://issues.civicrm.org/jira/browse/CRM-21786
      // or don't pass fix_address= 0 (but we need to understand performance reasons
      // why we haven't done that.
      \CRM_Core_BAO_Address::addGeocoderData($address_params);
      Address::save(FALSE)
        ->addRecord($address_params)
        ->execute();
    }
    catch (\CRM_Core_Exception $ex) {
      throw new WMFException(
        WMFException::IMPORT_CONTACT,
        'Couldn\'t store address for the contact: ' .
        $ex->getMessage()
      );
    }

    if (Database::isNativeTxnRolledBack()) {
      throw new WMFException(WMFException::IMPORT_CONTACT, "Native txn rolled back after inserting address");
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
  protected function createRelationship(
    int $contact_id,
    int $relatedContactID,
    string $relationshipType,
    array $customFields = []
  ): void {
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
   * Handle a contact update - this is moved here but not yet integrated.
   *
   * Calling this directly is deprecated - we are working towards eliminating that.
   *
   * This is an interim step... getting it onto the same class.
   *
   * @param array $existingContact
   *
   * @throws WMFException
   * @throws \CRM_Core_Exception
   */
  private function handleUpdate(array $existingContact): void {
    $updateFields = [];
    // This winds up being a list of permitted fields to update. The approach of
    // filtering out some fields here probably persists more because we
    // have not been brave enough to change historical code than an underlying reason.
    // Here we have tended to discard incoming names on update - why??
    // The latest cut only discards them if we have reason to think it might be poor quality.
    if ((empty($existingContact['first_name']) && empty($existingContact['last_name']))
      || (!$this->getIsLowConfidenceNameSource()
        && !empty($this->message['first_name'])
        && !empty($this->message['last_name'])
      )
    ) {
      // When new name fields only differ from old name fields in case, only update
      // when the new case is better than the old case
      foreach(['first_name', 'last_name'] as $field) {
        if (!empty($this->message[$field])) {
          $sameExceptForCase = (strcasecmp($existingContact[$field], $this->message[$field]) === 0);
          if ($sameExceptForCase) {
            if (\Civi\WMFHelper\Name::isBetterCapitalization(
              $existingContact[$field], $this->message[$field]
            )) {
              $updateFields[] = $field;
            }
          }
          else {
            $updateFields[] = $field;
          }
        }
      }
    }

    $updateParams = [];

    if (isset($this->getMessage()['Communication.opt_in'])) {
      if (!isset($existingContact['Communication.opt_in'])
        || !$existingContact['wmf_donor.last_donation_date']
        || strtotime($existingContact['wmf_donor.last_donation_date']) < strtotime($this->getMessage()['date'])
      ) {
        // Update the opt in - unless we are processing a donation that is older than the contact's most recent.
        $updateFields[] = 'Communication.opt_in';
        if ((bool)$this->getMessage()['Communication.opt_in'] === TRUE) {
          $updateParams += [
            'Communication.do_not_solicit' => 0,
            'do_not_email' => 0,
            'is_opt_out' => 0,
          ];
        }
      }
      else {
        // @todo - this is good enough maybe for a first run - but what about recurrings.
        \Civi::log('wmf')->notice('opt in not updated for contact ' . $existingContact['id']);
      }
    }

    $msg = $this->getMessage();
    $updateParams += array_intersect_key($msg, array_fill_keys($updateFields, TRUE));
    $updateParams += $this->getApiReadyFields();
    if (empty($msg['contact_type']) || $msg['contact_type'] === 'Individual') {
      // Individual-only custom fields
      if (!empty($msg['employer'])) {
        // WMF-only custom field
        $updateParams['Communication.Employer_Name'] = $msg['employer'];
      }
      if (!empty($msg['language'])) {
        // Only update this if we've got something on the message, so we don't
        // overwrite previous good data with some lame default.
        $updateParams['preferred_language'] = $this->getPreferredLanguage($msg);
      }
    }
    if (!empty($updateParams)) {
      $this->startTimer('update_contact_civi_api');
      Contact::update(FALSE)
        ->addWhere('id', '=', $existingContact['id'])
        ->setValues($updateParams)
        ->execute();
      $this->stopTimer('update_contact_civi_api');
    }
    $this->createEmployerRelationshipIfSpecified($existingContact['id'], $msg);

    // We have set the bar for invoking a location update fairly high here - ie state,
    // city or postal_code is not enough, as historically this update has not occurred at
    // all & introducing it this conservatively feels like a safe strategy.
    if (!empty($msg['street_address'])) {
      $this->startTimer('message_location_update');
      $this->updateAddress($msg, $existingContact['id']);
      $this->stopTimer('message_location_update');
    }
    else {
      // If there is no address at all, add whatever is in the message
      $addressExists = Address::get(FALSE)
        ->setSelect(['id'])
        ->addWhere('contact_id', '=', $existingContact['id'])
        ->setLimit(1)
        ->execute()->count();
      if (!$addressExists) {
        $this->createAddress($msg, $existingContact['id']);
      }
    }
    if (!empty($msg['email'])) {
      $this->startTimer('message_email_update');
      $this->emailUpdate($msg, $existingContact['id']);
      $this->stopTimer('message_email_update');
    }
    $phoneFields = \CRM_Utils_Array::filterByPrefix($msg, 'phone_primary.');
    // Only save the phone number if is has location type SMS Mobile. This is consistent
    // with historical behaviour and as of writing there would not be others coming in
    // so better to make a conscious choice if we want to change this in the future.
    // The testPhoneImport() test locks this in.
    if (!empty($phoneFields['location_type_id:name']) && $phoneFields['location_type_id:name'] === 'sms_mobile') {
      $phoneFields['contact_id'] = $existingContact['id'];
      // In practice as of late 2024 these would all be incoming SMS consent numbers.
      // We have historically not brought in phones from banners or emails and the support for
      // phones in create probably dates back to the old offline2civicrm manual imports.
      // I went back and forth on whether to check for existing or just overwrite - but
      // have opted to overwrite (possibly replacing a good number with the dummy number temporarily)
      // because it would also cause us to get any updates to consent data - which we otherwise would not
      // get.
      Phone::save(FALSE)
        ->addRecord($phoneFields)
        ->setMatch(['contact_id', 'location_type_id:name', 'phone_type_id:name'])
        ->execute();
    }
  }

  /**
   * Update address for a contact.
   *
   * @param array $msg
   * @param int $contact_id
   *
   * @throws \Civi\WMFException\WMFException|\CRM_Core_Exception
   */
  private function updateAddress($msg, $contact_id) {
    // CiviCRM does a DB lookup instead of checking the pseudoconstant.
    // @todo fix Civi to use the pseudoconstant.
    $country_id = $this->getCountryID($msg['country']);
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
      $address['state_province_id'] = $this->getStateID($country_id, $msg['state_province']);
    }
    $address['location_type_id'] = \CRM_Core_BAO_LocationType::getDefault()->id;
    $address['contact_id'] = $contact_id;

    try {
      Address::replace(FALSE)
        ->addWhere('contact_id', '=', $address['contact_id'])
        ->addWhere('location_type_id', '=', $address['location_type_id'])
        ->addRecord($address)->execute();
    }
    catch (\CRM_Core_Exception $e) {
      // Constraint violations occur when data is rolled back to resolve a deadlock.
      $code = $e->getErrorCode() === 'constraint violation' ? WMFException::DATABASE_CONTENTION : WMFException::IMPORT_CONTACT;
      throw new WMFException($code, "Couldn't store address for the contact.", $e->getExtraParams());
    }
  }

  private function getCountryID($raw) {
    // ISO code, or outside chance this could be a lang_COUNTRY pair
    if (preg_match('/^([a-z]+_)?([A-Z]{2})$/', $raw, $matches)) {
      $code = $matches[2];

      $iso_cache = \CRM_Core_PseudoConstant::countryIsoCode();
      $id = array_search(strtoupper($code), $iso_cache);
      if ($id !== FALSE) {
        return $id;
      }
    }
    else {
      $country_cache = \CRM_Core_PseudoConstant::country(FALSE, FALSE);
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
  private function getStateID($country_id, $state) {
    $stateID = \CRM_Core_DAO::singleValueQuery('
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
  private function emailUpdate($msg, $contact_id) {
    try {
      $loc_type_id = isset($msg['email_location_type_id']) ? $msg['email_location_type_id'] : \CRM_Core_BAO_LocationType::getDefault()->id;
      if (!is_numeric($loc_type_id)) {
        $loc_type_id = \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Email', 'location_type_id', $loc_type_id);
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
    catch (\CRM_Core_Exception $e) {
      // Constraint violations occur when data is rolled back to resolve a deadlock.
      $code = (in_array($e->getErrorCode(), ['constraint violation', 'deadlock', 'database lock timeout'])) ? WMFException::DATABASE_CONTENTION : WMFException::IMPORT_CONTACT;
      throw new WMFException($code, "Couldn't store email for the contact.", $e->getExtraParams());
    }
  }

  /**
   * Look for existing exact-match contact in the database.
   *
   * Note if there is more than one possible match we treat it as
   * 'no match'.
   *
   * @param array $msg
   *
   * @return array|null
   *
   * @throws \CRM_Core_Exception
   */
  protected function getExistingContact(array $msg): ?array {
    if (!empty($msg['contact_id'])) {
      $contact = Contact::get(FALSE)->addWhere('id', '=', $msg['contact_id'])
        ->addSelect('first_name', 'last_name')->execute()->first();
      if ($contact) {
        return $contact;
      }
      // @todo - should we throw an exception here? Should no be reachable.
    }
    // @todo - does this still make sense? Before or after Venmo?
    if (empty($msg['first_name']) || empty($msg['last_name'])) {
      return NULL;
    }
    $externalIdentifiers = array_flip($this->getExternalIdentifierFields());
    if ($externalIdentifiers) {
      $external_identifier_field = array_key_first($externalIdentifiers);
      if ($this->message['payment_method'] === 'venmo' && !empty($this->message['phone'])) {
        // venmo_user_name can be updated manually, so should use the only unique identifier - phone for venmo
        // check if contact has primary phone with source venmo and the same phone number, if not,
        // fallback to check with external identifier field
        $matches = Contact::get(FALSE)
        ->addWhere('phone_primary.phone_data.phone_source', '=', 'Venmo')
        ->addWhere('phone_primary.phone', '=', $this->message['phone'])->execute()->first();
        if (!empty($matches)) {
          return $matches;
        }
      }
      // fallback to check with external identifier field for venmo if no phone matched, and for other payment method
      $matches = Contact::get(FALSE)
        ->addWhere($external_identifier_field, '=', $this->message[$external_identifier_field])
        ->execute()->first();
      if (!empty($matches)) {
        return $matches;
      }
    }

    if (!empty($msg['email'])) {
      // Check for existing....
      $email = Email::get(FALSE)
        ->addWhere('contact_id.is_deleted', '=', 0)
        ->addWhere('contact_id.is_deceased', '=', 0)
        ->addWhere('email', '=', $msg['email'])
        ->addWhere('is_primary', '=', TRUE);

      // Skip name matching for low confidence contact name sources
      if ($this->getIsLowConfidenceNameSource() === FALSE) {
        $email->addWhere('contact_id.first_name', '=', $msg['first_name'])
          ->addWhere('contact_id.last_name', '=', $msg['last_name']);
      }

      $matches = $email->setSelect(['contact_id', 'contact_id.first_name', 'contact_id.last_name'])
        ->setLimit(1)
        ->setOrderBy(['contact_id' => 'ASC']) // in case of duplicates, get the oldest cid
        ->execute();

      if (count($matches) === 1) {
        return $this->keyAsContact($matches->first());
      }
      return NULL;
    }
    // If we have sufficient address data we will look up from the database.
    // original discussion at https://phabricator.wikimedia.org/T283104#7171271
    // We didn't talk about min_length for the other fields so I just went super
    // conservative & picked 2
    $addressCheckFields = ['street_address' => 5, 'city' => 2, 'postal_code' => 2];
    foreach ($addressCheckFields as $field => $minLength) {
      if (strlen($msg[$field] ?? '') < $minLength) {
        return NULL;
      }
    }
    $matches = Address::get(FALSE)
      ->addWhere('city', '=', $msg['city'])
      ->addWhere('postal_code', '=', $msg['postal_code'])
      ->addWhere('street_address', '=', $msg['street_address'])
      ->addWhere('contact_id.first_name', '=', $msg['first_name'])
      ->addWhere('contact_id.last_name', '=', $msg['last_name'])
      ->addWhere('contact_id.is_deleted', '=', 0)
      ->addWhere('contact_id.is_deceased', '=', 0)
      ->addWhere('is_primary', '=', TRUE)
      ->setSelect(['contact_id', 'contact_id.first_name', 'contact_id.last_name'])
      ->setLimit(1)
      ->setOrderBy(['contact_id' => 'ASC'])
      ->execute();
    if (count($matches) === 1) {
      return $this->keyAsContact($matches->first());
    }

    return NULL;
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
   * Do we have low confidence in the name provided for the contact.
   *
   * Some donation data sources provide unreliable contact name data e.g. Apple
   * Pay. Knowing this allows us to give less weight to data from unreliable
   * sources during the dedupe processes.
   *
   * @return bool
   */
  protected function getIsLowConfidenceNameSource(): bool {
    if (
      $this->isLowConfidenceNameSource === NULL &&
      !empty($this->getMessage()['payment_method'])
    ) {
      // those 3rd party contact might have their own name, opt out name check for dedupe if external identifier matched
      $paymentMethodsReturnLowConfidenceName = ['apple', 'google', 'amazon', 'venmo', 'paypal'];
      $this->isLowConfidenceNameSource = in_array(strtolower($this->getMessage()['payment_method']), $paymentMethodsReturnLowConfidenceName);
    }
    else {
      // If contribution recur ID is populated we are not dealing with something they just entered on
      // our form. Their details may not be more up-to-date than what we have.
      $this->isLowConfidenceNameSource = !empty($this->message['contribution_recur_id']);
    }
    return $this->isLowConfidenceNameSource;
  }

  /**
   * @return array
   */
  public function getApiReadyFields(): array {
    $values = [];
    // Copy some fields, if they exist
    // Why do this rather than just do pattern based conversion?
    // At this stage there is an allow-list of sorts in play - it's
    // not totally clear how deliberate it is but as we work through
    // it, we will hopefully reach a zen-like state of clarity.
    $apiFields = [
      'do_not_email' => 'do_not_email',
      'do_not_mail' => 'do_not_mail',
      'do_not_phone' => 'do_not_phone',
      'do_not_trade' => 'do_not_trade',
      'do_not_sms' => 'do_not_sms',
      'is_opt_out' => 'is_opt_out',
      'prefix_id' => 'prefix_id:label',
      'suffix_id' => 'suffix_id:label',
      'legal_identifier' => 'legal_identifier',
      'addressee_custom' => 'addressee_custom',
      'addressee_display' => 'addressee_display',
      'first_name_phonetic' => 'Communication.first_name_phonetic',
      'last_name_phonetic' => 'Communication.last_name_phonetic',
      'Partner' => 'Partner.Partner',
    ] + $this->getExternalIdentifierFields();

    foreach ($apiFields as $api3Field => $destinationField) {
      // We are currently calling apiv3 here but aim to call v4.
      // We can map either incoming, but prefer v4.
      if (isset($this->message[$destinationField])) {
        $values[$destinationField] = $this->message[$destinationField];
      }
      elseif (isset($this->message[$api3Field])) {
        $values[$destinationField] = $this->message[$api3Field];
      }
    }
    return $values;
  }

  /**
   * Get any custom fields that represent external identifiers.
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function getExternalIdentifierFields(): array {
    $externalIdentifierFields = [];
    foreach (array_keys($this->message) as $field) {
      if (str_starts_with($field, 'External_Identifiers.')) {
        $externalIdentifierFields[substr($field, 21)] = $field;
      }
    }
    return $externalIdentifierFields;
  }

  /**
   * Reformat an array from another entity as a contact array.
   *
   * e.g an email array of ['contact_id' => 5, 'contact_id.first_name' => 'Tinkerbell']
   * will be swapped to ['id' => 5, 'first_name' => 'Tinkerbell']
   *
   * @param array $result
   *
   * @return array
   */
  private function keyAsContact(array $result): array {
    $contact = [];
    foreach ($result as $key => $value) {
      if ($key === 'contact_id') {
        $contact['id'] = $value;
      }
      elseif ($key !== 'id') {
        $contact[substr($key, 11)] = $value;
      }
    }
    return $contact;
  }

}
