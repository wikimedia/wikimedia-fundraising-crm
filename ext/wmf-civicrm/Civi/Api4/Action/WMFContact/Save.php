<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Activity;
use Civi\Api4\Address;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\Phone;
use Civi\Api4\PhoneConsent;
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
      // for payment method from third party like apple venmo paypal assign different email type
      if (isset($msg['payment_method']) && !$this->isEmailSourceTrusted($msg['payment_method']) && $msg['payment_method'] !== 'ach') {
        // for ach, we have the ach email as $msg['billing_email']
        $contact['email_primary.location_type_id:name'] = $msg['payment_method'];
      }

      $contact['email_primary.email'] = $msg['email'];
    }
    // for gravy ACH, additional billing email might be provided here.
    if (!empty($msg['billing_email'])) {
      \Civi::log('wmf')->info("Add additional ach email " . $msg["billing_email"]);
      // email.email_billing here is only used as additional email other than primary. so here we save both primary and ach one as secondary for new contact
      $contact['email_billing.location_type_id:name'] = 'ach';
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
      $this->createPhoneConsent($contact_result['id']);
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
          $ex->getErrorData()
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
          $sameExceptForCase = (strcasecmp($existingContact[$field] ?? '', $this->message[$field]) === 0);
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

      // If there is a consent from the front end, save it
      $this->createPhoneConsent($existingContact['id']);
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
      throw new WMFException($code, "Couldn't store address for the contact.", $e->getErrorData());
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
   * @return int|null
   *
   * @throws \CRM_Core_Exception
   */
  private function getStateID(int $country_id, string $state): ?int {
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
    return null;
  }

  /**
   * Updates the email for a contact.
   * The un-trust email location type is from third party (venmo/paypal/google/ach/apple),
   * and we treat all others as trusted location_type.
   * Note here, when payment_method is ach, we might have billing_email from bank but email still from donation form so email should treat as trust source.
   *
   * https://gitlab.wikimedia.org/repos/fundraising-tech/fr-tech-diagrams/-/raw/main/contact_save/email_update.png
   *
   * @param array $msg
   * @param int $contact_id
   *
   * @throws \Civi\WMFException\WMFException
   */
  private function emailUpdate($msg, $contact_id) {
    try {
      $paymentMethod = isset($msg['payment_method']) ? strtolower($msg['payment_method']) : '';
      $incomingTrusted = $this->isEmailSourceTrusted($paymentMethod);

      if (!$incomingTrusted) {
        $msg['email_location_type_id'] = strtolower($msg['payment_method']);
      }

      $loc_type_id = $this->getEmailLocationTypeId($msg);
      $newEmail = $msg['email'];
      $existingEmails = $this->getContactEmails($contact_id);
      $emailContext = $this->analyzeExistingEmails($existingEmails, $newEmail, $loc_type_id);
      // 0️⃣ CHECK ACH EMAIL - if ach billing_address check diff for ach and update accordingly
      $this->handleBillingEmailIfPresent($msg, $existingEmails, $contact_id);
      // actual update based on priority: exact match primary > location type match > create new email
      $this->updateEmailBasedOnContext($contact_id, $newEmail, $emailContext, $incomingTrusted, $loc_type_id);
    }
    catch (\CRM_Core_Exception $e) {
      $code = (in_array($e->getErrorCode(), ['constraint violation', 'deadlock', 'database lock timeout'])) ? WMFException::DATABASE_CONTENTION : WMFException::IMPORT_CONTACT;
      throw new WMFException($code, "Couldn't store email for the contact.", $e->getErrorData());
    }
  }

  /**
   * use Home as default otherwise get the location id if the email location type is provided and valid.
   * if the location type is invalid, we will use default location type as well.
   *
   * @param $msg
   * @return int
   */
  private function getEmailLocationTypeId($msg): int {
    $loc_type_id = $msg['email_location_type_id'] ?? \CRM_Core_BAO_LocationType::getDefault()->id;
    if (!is_numeric($loc_type_id)) {
      $loc_type_id = \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Email', 'location_type_id', $loc_type_id);
    }
    return $loc_type_id;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function getContactEmails($contact_id): Result
  {
    return Email::get(FALSE)
      ->addWhere('contact_id', '=', $contact_id)
      ->addSelect('id', 'email', 'location_type_id', 'is_primary', 'on_hold', 'location_type_id:name')
      ->addOrderBy('is_primary')
      ->execute();
  }

  private function analyzeExistingEmails($existingEmails, $newEmail, $loc_type_id): array
  {
    $context = [
      'exactMatchPrimary' => NULL,
      'locationMatch' => NULL,
      'currentPrimary' => NULL,
      'primaryTrusted' => FALSE,
    ];

    foreach ($existingEmails as $email) {
      if (!empty($email['is_primary'])) {
        $context['currentPrimary'] = $email;
      }
      if (strcasecmp($email['email'], $newEmail) === 0) {
        if ($email['is_primary']) {
          $context['exactMatchPrimary'] = $email;
        }
      }
      if ($email['location_type_id'] == $loc_type_id) {
        $context['locationMatch'] = $email;
      }
    }

    $context['primaryTrusted'] = $context['currentPrimary'] && $this->isEmailSourceTrusted($context['currentPrimary']['location_type_id:name']);

    return $context;
  }

  /**
   * we want to make sure the billing email is created/updated even if the primary email is exact match and trusted source,
   * as long as the billing email is different from primary email.
   *
   * @param $msg
   * @param $existingEmails
   * @param $contact_id
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function handleBillingEmailIfPresent($msg, $existingEmails, $contact_id): void
  {
    if (empty($msg['billing_email'])) {
      return;
    }

    $billingEmail = $this->findAchEmail($existingEmails);
    $achLocationTypeId = \CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Email', 'location_type_id', 'ach');

    if (empty($billingEmail)) {
      $this->createSecondaryEmailWithLocation($contact_id, $msg['billing_email'], $achLocationTypeId);
    } elseif (strcasecmp($msg['billing_email'], $billingEmail['email']) !== 0) {
      // replace to make sure only have one ach per contact
      Email::save(FALSE)->addRecord([
        'id' => $billingEmail['id'],
        'email' => $msg['billing_email']
      ])->execute();
    }
  }

  private function findAchEmail($existingEmails)
  {
    return array_find((array)$existingEmails, fn($email) => ($email['location_type_id:name'] ?? '') === 'ach');
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function updateEmailBasedOnContext($contact_id, $newEmail, $emailContext, $incomingTrusted, $loc_type_id): void
  {
    // 1️⃣ EXACT MATCH BRANCH - email matched primary
    if ($emailContext['exactMatchPrimary']) {
      $this->handleExactMatchPrimary($contact_id, $newEmail, $emailContext, $incomingTrusted, $loc_type_id);
      return;
    }
    // 2️⃣ LOCATION MATCH BRANCH - location type match, update the same location to new email if different
    if ($emailContext['locationMatch']) {
      $this->handleLocationMatch($newEmail, $emailContext, $incomingTrusted);
      return;
    }
    // 3️⃣ CREATE BRANCH - nothing matched and incoming email is trust or primary is not trusted
    // incoming email should replace the current primary
    if ($incomingTrusted || !$emailContext['primaryTrusted']) {
      $this->createPrimaryEmail($contact_id, $newEmail, $loc_type_id);
      return;
    }

    $this->createSecondaryEmailWithLocation($contact_id, $newEmail, $loc_type_id);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function handleExactMatchPrimary($contact_id, $newEmail, $emailContext, $incomingTrusted, $loc_type_id): void
  {
    if ($incomingTrusted) {
      if (!$emailContext['primaryTrusted']) {
        $this->createPrimaryEmail($contact_id, $newEmail, $loc_type_id);
      }
    } else {
      if (!($emailContext['locationMatch'] && strcasecmp($emailContext['locationMatch']['email'], $newEmail) === 0)) {
        $this->createSecondaryEmailWithLocation($contact_id, $newEmail, $loc_type_id);
      }
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function handleLocationMatch($newEmail, $emailContext, $incomingTrusted): void
  {
    if (strcasecmp($emailContext['locationMatch']['email'], $newEmail) === 0) {
      // nothing should change, do nothing
      return;
    }
    Email::save(FALSE)->addRecord([
      'id' => $emailContext['locationMatch']['id'],
      'email' => $newEmail,
      'is_primary' => ($incomingTrusted || !$emailContext['primaryTrusted']) ? 1 : 0,
      'on_hold' => 0,
    ])->execute();
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function createPrimaryEmail($contact_id, $newEmail, $loc_type_id): void
  {
    Email::save(FALSE)->addRecord([
      'email' => $newEmail,
      'contact_id' => $contact_id,
      'location_type_id' => $loc_type_id,
      'is_primary' => 1,
    ])->execute();
  }

  /**
   * @param $contact_id
   * @param $newEmail
   * @param $loc_type_id
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function createSecondaryEmailWithLocation($contact_id, $newEmail, $loc_type_id): void
  {
    Email::save(FALSE)->addRecord([
      'email' => $newEmail,
      'contact_id' => $contact_id,
      'location_type_id' => $loc_type_id,
      'is_primary' => 0
    ])->execute();
  }

  /**
   * Look for existing exact-match contact in the database.
   *
   * Match strategy order of priority (Return the oldest contact ID (lowest ID) if multiple matches exist):
   * 1: Direct ID lookup – If contact_id is provided, fetch that contact directly.
   * 2: Name validation – If either first_name or last_name is missing and not from low confidence source, like ach might missing name, stop searching (return NULL).
   * 3: External identifier match – Look up by custom external identifier fields (e.g., Venmo username, paypal payerid).
   * -  Venmo-specific logic – For Venmo payments, prioritize matching by phone number or Venmo email, then update the username if needed.
   * 4: ACH billing email match – For ACH payments, check if a contact exists with the provided billing email tagged as ach location type.
   * 5: Email Match - Update based on Email/name/location type
   * 6: Address match – As a fallback, search by street address, city, postal code, and name if all address fields meet minimum length requirements.
   * No match – Return NULL if no contact is found through any method.
   * The method returns the contact array with id, first_name, and last_name keys, or NULL if no match is found.
   * @param array $msg
   * @return array|null
   * @throws \CRM_Core_Exception
   */
  protected function getExistingContact(array $msg): ?array {
    // Strategy 1: Direct ID lookup
    if (!empty($msg['contact_id'])) {
      $contact = Contact::get(FALSE)->addWhere('id', '=', $msg['contact_id'])
        ->addSelect('first_name', 'last_name')->execute()->first();
      if ($contact) {
        return $contact;
      }
    }

    // Strategy 2: Name validation if not low confidence name source - both names required for other lookups
    if (empty($msg['first_name']) || empty($msg['last_name']) && !$this->getIsLowConfidenceNameSource()) {
      return NULL;
    }

    // Strategy 3: External identifier match
    $externalIdentifiers = array_flip($this->getExternalIdentifierFields());
    if ($externalIdentifiers) {
      $contact = $this->matchByExternalIdentifier($msg, $externalIdentifiers);
      if ($contact) {
        return $contact;
      }
    }

    // Strategy 4: ACH billing email match
    if (!empty($msg['billing_email'])) {
      $contact = $this->matchByBillingEmail($msg);
      if ($contact) {
        return $contact;
      }
    }

    // Strategy 5: Match by Email with different confidence leve
    if (!empty($msg['email'])) {
      $contact = $this->matchByEmail($msg);
      if ($contact) {
        return $contact;
      }
    }

    // Strategy 6: Address match (fallback)
    $contact = $this->matchByAddress($msg);
    if ($contact) {
      return $contact;
    }

    return NULL;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function matchByExternalIdentifier(array $msg, array $externalIdentifiers): ?array {
    $external_identifier_field = array_key_first($externalIdentifiers);

    // Venmo-specific logic: try phone or email first cause username is changeable, we want to avoid false match
    // if username is taken by another contact. where phone and email is also changeable but can not be taken by other user easily
    if ($msg['payment_method'] === 'venmo' && !empty($msg['phone'])) {
      $contact = $this->matchVenmoByPhone($msg);
      if ($contact) {
        return $contact;
      }
      $contact = $this->matchVenmoByEmail($msg, $externalIdentifiers);
      if ($contact) {
        return $contact;
      }
    }

    // Fallback to external identifier field
    $matches = Contact::get(FALSE)
      ->addWhere($external_identifier_field, '=', $msg[$external_identifier_field])
      ->execute()->first();

    return !empty($matches) ? $matches : NULL;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function matchVenmoByPhone(array $msg): ?array {
    $matches = Contact::get(FALSE)
      ->addSelect('*', 'External_Identifiers.venmo_user_name')
      ->addWhere('phone_primary.phone_data.phone_source', '=', 'Venmo')
      ->addWhere('phone_primary.phone', '=', $msg['phone'])
      ->addOrderBy('id')  // get the oldest contact if multiple matches exist
      ->execute()->first();

    if (!empty($matches)) {
      $this->updateVenmoUsernameIfNeed($matches, array_flip($this->getExternalIdentifierFields()));
    }

    return !empty($matches) ? $matches : NULL;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function matchVenmoByEmail(array $msg, array $externalIdentifiers): ?array {
    $matches = Contact::get(FALSE)
      ->addSelect('*', 'External_Identifiers.venmo_user_name')
      ->addJoin('Email AS email')
      ->addWhere('is_deleted', '=', 0)
      ->addWhere('is_deceased', '=', 0)
      ->addWhere('email.email', '=', $msg['email'])
      ->addWhere('email.location_type_id:name', '=', 'venmo')
      ->addOrderBy('id') // get the oldest contact if multiple matches exist
      ->execute()
      ->first();

    if (!empty($matches)) {
      $this->updateVenmoUsernameIfNeed($matches, $externalIdentifiers);
    }

    return !empty($matches) ? $matches : NULL;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function matchByBillingEmail(array $msg): ?array {
    $matches = Email::get(FALSE)
      ->addWhere('contact_id.is_deleted', '=', 0)
      ->addWhere('contact_id.is_deceased', '=', 0)
      ->addWhere('email', '=', $msg['billing_email'])
      ->addWhere('location_type_id:name', '=', 'ach')
      ->setSelect(['contact_id', 'contact_id.first_name', 'contact_id.last_name'])
      ->setOrderBy(['contact_id' => 'ASC'])
      ->execute();

    return (count($matches) === 1) ? $this->keyAsContact($matches->first()) : NULL;
  }

  /**
   * Match by email with different confidence level (priority order):
   * 1) primary email + name (case-insensitive)
   * 2) email + name (case-insensitive)
   * 3) primary email where location type indicates a low-confidence source
   * 4) email + location type
   * 5) email where location type indicates a low-confidence source
   *
   * @throws \CRM_Core_Exception
   */
  private function matchByEmail(array $msg): ?array {
    $matches = Email::get(FALSE)
      ->addWhere('contact_id.is_deleted', '=', 0)
      ->addWhere('contact_id.is_deceased', '=', 0)
      ->addWhere('email', '=', $msg['email'])
      ->setSelect(['contact_id', 'contact_id.first_name', 'contact_id.last_name', 'location_type_id:name', 'is_primary'])
      ->setOrderBy(['contact_id' => 'ASC'])
      ->execute();

    if (count($matches) === 0) {
      return NULL;
    }

    $nameMatches = [];
    $primaryLowConfidence = [];
    $locationMatches = [];
    $lowConfidence = [];

    foreach ($matches as $candidate) {
      $isNameMatch = $this->isNameMatch($candidate, $msg);
      $isPrimary = $candidate['is_primary'];
      $isLowConfidence = $this->getIsLowConfidenceNameSource($candidate['location_type_id:name']);
      $isLocationMatch = strcasecmp($msg['payment_method'], $candidate['location_type_id:name']) === 0;

      // 1) primary email + name
      if ($isNameMatch && $isPrimary) {
        return $this->keyAsContact($candidate);
      }

      // 2) email + name
      if ($isNameMatch) {
        $nameMatches[] = $candidate;
      }

      // 3) primary email + low-confidence
      if ($isPrimary && $isLowConfidence) {
        $primaryLowConfidence[] = $candidate;
      }

      // 4) email + location type when low-confidence
      if ($isLocationMatch && $isLowConfidence) {
        $locationMatches[] = $candidate;
      }

      // 5) email + low-confidence
      if ($isLowConfidence) {
        $lowConfidence[] = $candidate;
      }
    }

    // Apply fallback priority
    if (!empty($nameMatches)) {
      return $this->keyAsContact($nameMatches[0]);
    }
    if (!empty($primaryLowConfidence)) {
      return $this->keyAsContact($primaryLowConfidence[0]);
    }
    if (!empty($locationMatches)) {
      return $this->keyAsContact($locationMatches[0]);
    }
    if (!empty($lowConfidence)) {
      return $this->keyAsContact($lowConfidence[0]);
    }

    return NULL;
  }

  private function isNameMatch(array $candidate, array $msg): bool {
    return strcasecmp($candidate['contact_id.first_name'] ?? '', $msg['first_name']) === 0
      && strcasecmp($candidate['contact_id.last_name'] ?? '', $msg['last_name']) === 0;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private function matchByAddress(array $msg): ?array {
    // Validate sufficient address data
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

    return (count($matches) === 1) ? $this->keyAsContact($matches->first()) : NULL;
  }

  /**
   * venmo username is not unique (phone is) identifier, but this is searchable in dashboard, and used in ty email
   * so update is needed
   *
   * @param array $matches
   * @param array $externalIdentifiers
   * @return void
   * @throws \CRM_Core_Exception
   */
  protected function updateVenmoUsernameIfNeed(array $matches, array $externalIdentifiers ): void {
    $matchVenmoUsername = $matches['External_Identifiers.venmo_user_name'];
    if ($matchVenmoUsername !== $externalIdentifiers['External_Identifiers.venmo_user_name']) {
      \Civi::log('wmf')->info("Updating venmo_user_name for contact ID {$matches['id']}
            from {$externalIdentifiers['External_Identifiers.venmo_user_name']} to
             $matchVenmoUsername");
      Contact::update(FALSE)
        ->addWhere('id', '=', (int)$matches['id'])
        ->setValues(['External_Identifiers.venmo_user_name' => $matchVenmoUsername])
        ->execute();
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
   * @throws \CRM_Core_Exception
   */
  protected function createEmployerRelationshipIfSpecified(int $contactId, array $msg): void
  {
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
          !$existingRelationship['is_active'] ||
          ($isProvidedByDonor && !$existingRelationship['Relationship_Metadata.provided_by_donor'])
        ) {
          // Set is_active and provided_by_donor flag
          $values = array_merge($relationshipParams, ['is_active' => 1]);
          Relationship::update(FALSE)
            ->addWhere('id', '=', $existingRelationship['id'])
            ->setValues($values)
            ->execute();
        }
      }
      elseif ($existingRelationship['is_active']) {
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
   *  Do we have low confidence in the name provided for the contact.
   *
   *  Some donation data sources provide unreliable contact name data e.g. Apple
   *  Pay. Knowing this allows us to give less weight to data from unreliable
   *  sources during the dedupe processes.
   *
   * @param null $primaryEmailType
   * @return bool
   */
  protected function getIsLowConfidenceNameSource($primaryEmailType = NULL): bool {
    $paymentMethodsReturnLowConfidenceName = ['apple', 'google', 'venmo', 'paypal', 'ach'];
    // todo: T418790 will use name type to define if IsLowConfidenceNameSource other than check email type
    // check if currency primary not trusted source, then no need to check first name and last name, otherwise check incoming payment_method.
    if (!empty($primaryEmailType) && in_array(strtolower($primaryEmailType), $paymentMethodsReturnLowConfidenceName)) {
      return true;
    } else {
      if (
        !empty($this->getMessage()['payment_method'])
      ) {
        // those 3rd party contact might have their own name, opt out name check for dedupe if external identifier matched
        $this->isLowConfidenceNameSource = in_array(strtolower($this->getMessage()['payment_method']), $paymentMethodsReturnLowConfidenceName);
      }
      else {
        // If contribution recur ID is populated we are not dealing with something they just entered on
        // our form. Their details may not be more up-to-date than what we have.
        $this->isLowConfidenceNameSource = !empty($this->message['contribution_recur_id']);
      }
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

  /**
   * Consider trusted email sources: anything that is not a known 3rd-party/email-source
   *
   * @param string $paymentMethod
   * @return bool
   */
  private function isEmailSourceTrusted(string $paymentMethod): bool {
    $pm = strtolower($paymentMethod);
    if ($pm === '') {
      return TRUE; // no payment method implies our site/source -> trusted
    }
    // ach email is still from donation form, the billing_email for ach queue msg is the untrusted one,
    // so do not mixed with below 3rd party email source list
    // each entry in this array has a corresponding locationType in locationTypes.mgd.php
    // treat these low confidence as untrusted
    return !in_array($pm, ['google', 'apple', 'venmo', 'paypal']);
  }

  /**
   * Save a phone consent from the front end
   * and create an activity
   *
   * @param string $contact_id
   *
   */
  private function createPhoneConsent($contact_id) {
    if (isset($this->message['sms_opt_in']) && (bool)$this->message['sms_opt_in'] === TRUE) {
      $date = (new \DateTime('@' . $this->message['date']))->format('Y-m-d H:i:s');
      // Right now they are US only and may or may not start with 1
      // No US area codes start with 0 or 1
      // TODO: Normalize this in the form with better UI and only pass over numbers

      // Get only the numbers
      $phoneNumber = preg_replace('/[^\d]/', '', $this->message['phone']);

      if (str_starts_with($phoneNumber, '1')) {
        $countryCode = substr($phoneNumber, 0, 1);
        $phoneNumber = substr($phoneNumber, 1);
      } else {
        $countryCode = 1;
      }

      $record = [
        'country_code' => $countryCode,
        'phone_number' => $phoneNumber,
        'consent_date' => $date,
        'consent_source' => 'Payments Form',
        'opted_in' => 1,
      ];

      // This is duplicated in the omnimail extension
      PhoneConsent::save(FALSE)
        ->setMatch(['phone_number'])
        ->addRecord($record)
        ->execute();

    Activity::create(FALSE)
      ->setValues([
        'activity_type_id:name' => 'sms_consent_given',
        'activity_date_time' => $date,
        'status_id:name' => 'Completed',
        'source_contact_id' =>  $contact_id,
        'subject' => 'SMS consent given for ' . $phoneNumber,
        'details' => 'Opted in from payments form',
        // These fields are kinda legacy but since they exist I guess we stick data in them.
        'phone_number' => $phoneNumber
      ])
      ->execute();
    }
  }

}
