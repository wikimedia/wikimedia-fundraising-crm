<?php

namespace Civi\Api4\Action\WMFContact;

use Civi\Api4\Address;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
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
 * @method $this setContactID(int $contactID) Set the contact id to update.
 * @method int|null getContactID() get the contact it to update.
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
   * Contact ID to update.
   *
   * @var int
   */
  protected $contactID;

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
    $contact_id = $this->getContactID();
    $isCreate = !$contact_id;
    $msg = $this->getMessage();

    if ($isCreate) {
      $existingContact = $this->getExistingContact($msg);
      $replaceNames = FALSE;
      if ($existingContact) {
        $msg['contact_id'] = $existingContact['contact_id'];
        $replaceNames = (
          empty($existingContact['contact_id.first_name']) &&
          empty($existingContact['contact_id.last_name'])
        );
      }
      if (!empty($msg['contact_id'])) {
        $this->setMessage($msg);
        $this->handleUpdate($replaceNames);
        $result[] = ['id' => $msg['contact_id']];
        return;
      }
    }
    // Set defaults for optional fields in the message
    if (!array_key_exists('contact_type', $msg)) {
      $msg['contact_type'] = "Individual";
    }
    elseif ($msg['contact_type'] !== 'Individual' && $msg['contact_type'] !== 'Organization') {
      // looks like an unsupported type was sent, revert to default
      \Civi::log('wmf')->warning('wmf_civicrm: Non-supported contact_type received: {type}', ['type' => $msg['contact_type']]);
      $msg['contact_type'] = "Individual";
    }

    if (!array_key_exists('contact_source', $msg)) {
      $msg['contact_source'] = "online donation";
    }

    // Create the contact record
    $contact = [
      'id' => $contact_id,
      'contact_type' => $msg['contact_type'],
      'contact_source' => $msg['contact_source'],
      'debug' => TRUE,
      'addressee_id' => empty($msg['addressee_custom']) ? NULL : 'Customized',
      // Major gifts wants greeting processing - but we are not sure speedwise.
      'skip_greeting_processing' => !\Civi::settings()->get('wmf_save_process_greetings_on_create'),
    ];
    if (!empty($msg['organization_name'])) {
      $contact['organization_name'] = $msg['organization_name'];
    }

    if (strtolower($msg['contact_type']) !== 'organization') {
      foreach (['first_name', 'last_name', 'middle_name', 'nick_name'] as $name) {
        if (isset($msg[$name])) {
          $contact[$name] = $msg[$name];
        }
      }
    }

    if (!$contact_id && !empty($msg['email'])) {
      // For updates we are still using our own process which may or may not confer benefits
      // For inserts however we can rely on the core api.
      $contact['email'] = $msg['email'];
    }

    $preferredLanguage = $this->getPreferredLanguage($msg);
    if ($preferredLanguage) {
      $contact['preferred_language'] = $preferredLanguage;
    }
    $contact += $this->getApiReadyFields(3);

    $custom_vars = [];
    $custom_field_mangle = [
      'opt_in' => 'opt_in',
      'do_not_solicit' => 'do_not_solicit',
      'org_contact_name' => 'Name',
      'org_contact_title' => 'Title',
      'employer' => 'Employer_Name',
      'Organization_Contact.Phone' => 'Phone',
      'Organization_Contact.Email' => 'Email',
      // These 2 fields already have aliases but adding
      // additional ones with the new standard allows migration
      // and means that the import file does not have to mix and match.
      'Organization_Contact.Title' => 'Title',
      'Organization_Contact.Name' => 'Name',
    ];
    foreach ($custom_field_mangle as $msgField => $customField) {
      if (isset($msg[$msgField])) {
        $custom_vars[$customField] = $msg[$msgField];
      }
    }

    $custom_name_mapping = wmf_civicrm_get_custom_field_map(array_keys($custom_vars));
    foreach ($custom_name_mapping as $readable => $machined) {
      if (array_key_exists($readable, $custom_vars)) {
        $contact[$machined] = $custom_vars[$readable];
      }
    }

    if (Database::isNativeTxnRolledBack()) {
      throw new WMFException(WMFException::IMPORT_CONTACT, "Native txn rolled back before inserting contact");
    }
    // Attempt to insert the contact
    try {
      $this->startTimer('create_contact_civi_api');
      $contact_result = civicrm_api3('Contact', 'Create', $contact);
      $this->stopTimer('create_contact_civi_api');
      \Civi::log('wmf')->debug('wmf_civicrm: Successfully ' . ($contact_id ? 'updated' : 'created ') . ' contact: {id}', ['id' => $contact_result['id']]);
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
    $contact_id = (int) $contact_result['id'];

    // Add phone number
    if (isset($msg['phone'])) {
      try {
        $phone_result = civicrm_api3('Phone', 'Create', [
          // XXX all the fields are nullable, should we set any others?
          'contact_id' => $contact_id,
          'location_type_id' => wmf_civicrm_get_default_location_type_id(),
          'phone' => $msg['phone'],
          'phone_type_id' => 'Phone',
          'is_primary' => 1,
          'debug' => TRUE,
        ]);
      }
      catch (\CRM_Core_Exception $ex) {
        throw new WMFException(
          WMFException::IMPORT_CONTACT,
          "Failed to add phone for contact ID {$contact_id}: {$ex->getMessage()} " . print_r($ex->getErrorData(), TRUE)
        );
      }
    }

    if ($isCreate) {
      // Insert the location records if this is being called as a create.
      // For update it's handled in the update routing.
      try {
        wmf_civicrm_message_address_insert($msg, $contact_id);
      }
      catch (\CRM_Core_Exception $ex) {
        $hasContact = Contact::get(FALSE)
          ->addSelect('id')
          ->addWhere('id', '=', $contact_id)->execute()->first();
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
    if (!$incomingLanguage && $this->getContactID()) {
      return '';
    }
    if (!$incomingLanguage) {
      // TODO: use LanguageTag to prevent truncation of >2 char lang codes
      // guess from contribution_tracking data
      if (isset($msg['contribution_tracking_id']) && is_numeric($msg['contribution_tracking_id'])) {
        $contributionTrackingID = (int) $msg['contribution_tracking_id'];
        $tracking = wmf_civicrm_get_contribution_tracking(['contribution_tracking_id' => $contributionTrackingID]);
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
        if (wmf_civicrm_check_language_exists($incomingLanguage)) {
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
      if (!wmf_civicrm_check_language_exists($preferredLanguage)) {
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
   * @param bool $replaceNames
   *
   * @throws WMFException
   * @throws \CRM_Core_Exception
   */
  public function handleUpdate(bool $replaceNames = FALSE): void {
    // This winds up being a list of permitted fields to update. The approach of
    // filtering out some fields here probably persists more because we
    // have not been brave enough to change historical code than an underlying reason.
    $updateFields = $replaceNames ? ['first_name', 'last_name'] : [];

    $msg = $this->getMessage();
    $updateParams = array_intersect_key($msg, array_fill_keys($updateFields, TRUE));
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
        ->addWhere('id', '=', $msg['contact_id'])
        ->setValues($updateParams)
        ->execute();
      $this->stopTimer('update_contact_civi_api');
    }
    $this->createEmployerRelationshipIfSpecified($msg['contact_id'], $msg);

    // We have set the bar for invoking a location update fairly high here - ie state,
    // city or postal_code is not enough, as historically this update has not occurred at
    // all & introducing it this conservatively feels like a safe strategy.
    if (!empty($msg['street_address'])) {
      $this->startTimer('message_location_update');
      wmf_civicrm_message_address_update($msg, $msg['contact_id']);
      $this->stopTimer('message_location_update');
    }
    if (!empty($msg['email'])) {
      $this->startTimer('message_email_update');
      wmf_civicrm_message_email_update($msg, $msg['contact_id']);
      $this->stopTimer('message_email_update');
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
   * @throws \API_Exception
   */
  protected function getExistingContact(array $msg): ?array {
    if (empty($msg['first_name']) || empty($msg['last_name'])) {
      return NULL;
    }
    if (!empty($msg['contact_id'])) {
      $contact = Contact::get(FALSE)->addWhere('id', '=', $msg['contact_id'])
        ->addSelect('first_name', 'last_name')->execute()->first();
      if ($contact) {
        return ['contact_id.first_name' => $contact['first_name'], 'contact_id.last_name' => $contact['last_name'], 'contact_id' => $contact['id']];
      }
      // @todo - should we throw an exception here? Should no be reachable.
    }
    $externalIdentifiers = array_flip($this->getExternalIdentifierFields());
    if ($externalIdentifiers) {
      // since venmo allow user to update their user_name, we can not use this as single select param,
      // we can probably use venmo_user_id in the future for this dedupe function works for venmo
      if (empty($externalIdentifiers['External_Identifiers.venmo_user_name'])) {
        $external_identifier_field = array_key_first($externalIdentifiers);
        $matches = Contact::get(FALSE)
          ->addWhere($external_identifier_field, '=', $this->message[$external_identifier_field])
          ->execute()
          ->first();
        if (!empty($matches)) {
          $matches['contact_id'] = $matches['id'];
          return $matches;
        }
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
        ->setLimit(2)
        ->execute();

      if (count($matches) === 1) {
        return $matches->first();
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
      ->setLimit(2)
      ->execute();
    if (count($matches) === 1) {
      return $matches->first();
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
      $this->isLowConfidenceNameSource = $this->getMessage()['payment_method'] === 'apple';
    }
    else {
      $this->isLowConfidenceNameSource = FALSE;
    }
    return $this->isLowConfidenceNameSource;
  }

  /**
   * @param int $destinationApiVersion
   * @return array
   */
  public function getApiReadyFields(int $destinationApiVersion = 4): array {
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
      $this->getApiv3FieldName('first_name_phonetic') => 'Communication.first_name_phonetic',
      $this->getApiv3FieldName('last_name_phonetic') => 'Communication.last_name_phonetic',
      $this->getApiv3FieldName('Partner') => 'Partner.Partner',
    ] + $this->getExternalIdentifierFields();

    foreach ($apiFields as $api3Field => $api4Field) {
      // We are currently calling apiv3 here but aim to call v4.
      // We can map either incoming, but prefer v4.
      $destinationField = $destinationApiVersion === 4 ? $api4Field : $api3Field;
      if (isset($this->message[$api4Field])) {
        $values[$destinationField] = $this->message[$api4Field];
      }
      elseif (isset($this->message[$api3Field])) {
        $values[$destinationField] = $this->message[$api3Field];
      }
    }
    return $values;
  }

  /**
   * Get the apiv3 name - e.g custom_6
   *
   * This should be short term enough it can just wrap the legacy function...
   *
   * @param string $name
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function getApiv3FieldName(string $name): string {
    return wmf_civicrm_get_custom_field_name($name);
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
        $externalIdentifierFields[$this->getApiv3FieldName(substr($field, 21))] = $field;
      }
    }
    return $externalIdentifierFields;
  }

}
