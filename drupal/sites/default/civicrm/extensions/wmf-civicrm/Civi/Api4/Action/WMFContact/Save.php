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
      if ($existingContact) {
        $contact_id = $existingContact['contact_id'];
        $msg['contact_id'] = $contact_id;
        $replaceNames = (
          empty($existingContact['contact_id.first_name']) &&
          empty($existingContact['contact_id.last_name'])
        );
        $this->handleUpdate($msg, $replaceNames);
        $result[] = ['id' => $contact_id];
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
      'addressee_custom' => empty($msg['addressee_custom']) ? NULL : $this->cleanString($msg['addressee_custom'], 128),
      'addressee_display' => empty($msg['addressee_custom']) ? NULL : $this->cleanString($msg['addressee_custom'], 128),
      'addressee_id' => empty($msg['addressee_custom']) ? NULL : 'Customized',
      'legal_identifier' => empty($msg['fiscal_number']) ? NULL : $this->cleanString($msg['fiscal_number'], 32),
      // Major gifts wants greeting processing - but we are not sure speedwise.
      'skip_greeting_processing' => !\Civi::settings()->get('wmf_save_process_greetings_on_create'),
    ];
    if (!empty($msg['organization_name'])) {
      $contact['organization_name'] = $msg['organization_name'];
    }

    if (strtolower($msg['contact_type']) !== 'organization') {
      foreach (['first_name', 'last_name', 'middle_name', 'nick_name'] as $name) {
        if (isset($msg[$name])) {
          $contact[$name] = $this->cleanString($msg[$name], 64);
        }
      }
    }

    if (!$contact_id && !empty($msg['email'])) {
      // For updates we are still using our own process which may or may not confer benefits
      // For inserts however we can rely on the core api.
      $contact['email'] = $msg['email'];
    }
    if (strtolower($msg['contact_type']) === 'organization') {
      // @todo probably can remove handling for sort name and display name now.
      $contact['sort_name'] = $msg['organization_name'];
      $contact['display_name'] = $msg['organization_name'];
    }

    if (!empty($msg['prefix_id:label'])) {
      // prefix_id:label is APIv4 format. name_prefix is our own fandango.
      // We should start migrating to APIv4 format so supporting it
      // is a first step.
      $msg['name_prefix'] = $msg['prefix_id:label'];
    }
    if (!empty($msg['suffix_id:label'])) {
      // Same with suffix
      $msg['name_suffix'] = $msg['suffix_id:label'];
    }
    if (!empty($msg['name_prefix'])) {
      $contact['prefix_id'] = $msg['name_prefix'];
    }
    if (!empty($msg['name_suffix'])) {
      $contact['suffix_id'] = $msg['name_suffix'];
    }

    $contact['preferred_language'] = $this->getPreferredLanguage($msg);

    // Copy some fields, if they exist
    $direct_fields = [
      'do_not_email',
      'do_not_mail',
      'do_not_phone',
      'do_not_sms',
      'is_opt_out',
    ];
    foreach ($direct_fields as $field) {
      if (isset($msg[$field])) {
        if (in_array($msg[$field], [0, 1, '0', '1', TRUE, FALSE], TRUE)) {
          $contact[$field] = $msg[$field];
        }
        elseif (strtoupper($msg[$field]) === 'Y') {
          $contact[$field] = TRUE;
        }
      }
    }

    $custom_vars = [];
    $custom_field_mangle = [
      'opt_in' => 'opt_in',
      'do_not_solicit' => 'do_not_solicit',
      'org_contact_name' => 'Name',
      'org_contact_title' => 'Title',
      'employer' => 'Employer_Name',
      // Partner is the custom field's name, Partner is also the custom group's name
      // since other custom fields have names similar to core fields (Partner.Email)
      // this api-similar namespacing convention seems like a good choice.
      'Partner.Partner' => 'Partner',
      'Organization_Contact.Phone' => 'Phone',
      'Organization_Contact.Email' => 'Email',
      // These 2 fields already have aliases but adding
      // additional ones with the new standard allows migration
      // and means that the import file does not have to mix and match.
      'Organization_Contact.Title' => 'Title',
      'Organization_Contact.Name' => 'Name',
    ];
    if (!empty($this->getExternalIdentifierField($msg))) {
      $custom_field_mangle['external_identifier'] = $this->getExternalIdentifierField($msg);
    }
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
      $this->startTimer( 'create_contact_civi_api' );
      $contact_result = civicrm_api3('Contact', 'Create', $contact);
      $this->stopTimer( 'create_contact_civi_api' );
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

    // Add groups to this contact.
    if (!empty($msg['contact_groups'])) {
      // TODO: Use CRM_Contact_GroupContact::buildOptions in Civi 4.4, also
      // in place of ::tag below.
      $supported_groups = array_flip(\CRM_Core_PseudoConstant::allGroup());
      $stacked_ex = [];
      foreach (array_unique($msg['contact_groups']) as $group) {
        try {
          $tag_result = civicrm_api3("GroupContact", "Create", [
            'contact_id' => $contact_id,
            'group_id' => $supported_groups[$group],
          ]);
        }
        catch (\CRM_Core_Exception $ex) {
          $stacked_ex[] = "Failed to add group {$group} to contact ID {$contact_id}. Error: " . $ex->getMessage();
        }
      }
      if (!empty($stacked_ex)) {
        throw new WMFException(
          WMFException::IMPORT_CONTACT,
          implode("\n", $stacked_ex)
        );
      }
    }
    $this->addTagsToContact($msg['contact_tags'] ?? [], $contact_id);

    // Create a relationship to an existing contact?
    if (!empty($msg['relationship_target_contact_id'])) {
      $this->createRelationship( $contact_id, $msg['relationship_target_contact_id'], $msg['relationship_type'] );
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
   * Add tags to the contact.
   *
   * Note that this code may be never used - I logged
   * https://phabricator.wikimedia.org/T286225 to query whether the only
   * place that seems like it might pass in contact_tags is actually ever used.
   *
   * @param array $tags
   * @param int $contact_id
   *
   * @return void
   * @throws \Civi\WMFException\WMFException
   */
  protected function addTagsToContact(array $tags, int $contact_id): void {
    // Do we have any tags we need to add to this contact?
    if (!empty($tags)) {
      $supported_tags = array_flip(\CRM_Core_BAO_Tag::getTags('civicrm_contact'));
      $stacked_ex = [];
      foreach (array_unique($tags) as $tag) {
        try {
          civicrm_api3('EntityTag', 'Create', [
            'entity_table' => 'civicrm_contact',
            'entity_id' => $contact_id,
            'tag_id' => $supported_tags[$tag],
          ]);
        }
        catch (\CRM_Core_Exception $ex) {
          $stacked_ex[] = "Failed to add tag {$tag} to contact ID {$contact_id}. Error: " . $ex->getMessage();
        }
      }
      if (!empty($stacked_ex)) {
        throw new WMFException(
          WMFException::IMPORT_CONTACT,
          implode("\n", $stacked_ex)
        );
      }
    }
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
   * @throws \API_Exception
   */
  protected function getPreferredLanguage(array $msg): string {
    $incomingLanguage = $msg['language'] ?? '';
    $country = $msg['country'] ?? '';
    $preferredLanguage = '';
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
        catch(\CRM_Core_Exception $ex) {
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
    int    $contact_id,
    int    $relatedContactID,
    string $relationshipType,
    array  $customFields = []
  ): void {
    $params = array_merge($customFields, [
      'contact_id_a' => $contact_id,
      'contact_id_b' => $relatedContactID,
      'relationship_type_id:name' => $relationshipType,
      'is_active' => 1,
      'is_current_employer' => $relationshipType === 'Employee of'
    ]);

    try {
      Relationship::create(FALSE)
        ->setValues($params)
        ->execute();
    }
    catch (\API_Exception|\CRM_Core_Exception $ex) {
      throw new WMFException(WMFException::IMPORT_CONTACT, $ex->getMessage());
    }
  }

  /**
   * Handle a contact update - this is moved here but not yet integrated.
   *
   * This is an interim step... getting it onto the same class.
   *
   * @param array $msg
   *
   * @throws \API_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function handleUpdate(array $msg, bool $replaceNames = false): void {
    // This winds up being a list of permitted fields to update. The approach of
    // filtering out some fields here probably persists more because we
    // have not been brave enough to change historical code than an underlying reason.
    $updateFields = [
      'do_not_email',
      'do_not_mail',
      'do_not_trade',
      'do_not_phone',
      'is_opt_out',
      'do_not_sms',
      'Partner.Partner',
    ];
    if ($replaceNames) {
      $updateFields[] = 'first_name';
      $updateFields[] = 'last_name';
    }
    $updateParams = array_intersect_key($msg, array_fill_keys($updateFields, TRUE));
    if (!empty($msg['fiscal_number'])) {
      $updateParams['legal_identifier'] = $this->cleanString($msg['fiscal_number'], 32);
    }
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
          $existingCustomFields = Contact::get(FALSE)
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
        $updateParams['preferred_language'] = $this->getPreferredLanguage($msg);
      }
    }
    if (!empty($updateParams)) {
      $this->startTimer( 'update_contact_civi_api' );
      Contact::update(FALSE)
        ->addWhere('id', '=', $msg['contact_id'])
        ->setValues($updateParams)
        ->execute();
      $this->stopTimer( 'update_contact_civi_api' );
    }
    $this->createEmployerRelationshipIfSpecified($msg['contact_id'], $msg);

    // We have set the bar for invoking a location update fairly high here - ie state,
    // city or postal_code is not enough, as historically this update has not occurred at
    // all & introducing it this conservatively feels like a safe strategy.
    if (!empty($msg['street_address'])) {
      $this->startTimer('message_location_update');
      wmf_civicrm_message_location_update($msg, ['id' => $msg['contact_id']]);
      $this->stopTimer('message_location_update');
    }
    elseif (!empty($msg['email'])) {
      // location_update updates email, if set and address, if set.
      // However, not quite ready to start dealing with the situation
      // where less of the address is incoming than already exists
      // hence only call this part if street_address is empty.
      $this->startTimer('message_email_update');
      wmf_civicrm_message_email_update($msg, $msg['contact_id']);
      $this->stopTimer('message_email_update');
    }
  }

  protected function getExternalIdentifierField(array $msg): ?string {
    if (empty($msg['gateway'])) {
      return null;
    }
    // Save external platform contact id if braintree venmo, then save the user_name otherwise save to id
    $isBraintreeVenmoPayment = !empty($msg['payment_method']) && $msg['payment_method'] === 'venmo' && $msg['gateway'] === 'braintree';
    return ($isBraintreeVenmoPayment) ? 'venmo_user_name' : $msg['gateway'] . '_id';
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
    if (!empty($this->getExternalIdentifierField($msg))) {
      $external_identifier_field = $this->getExternalIdentifierField($msg);
      if (!empty($msg['external_identifier'])) {
        $matches = Contact::get(FALSE)
          ->addWhere('External_Identifiers.'.$external_identifier_field, '=', $msg['external_identifier'])
          ->execute()
          ->first();
        if(!empty($matches)) {
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
   * Clean up a string by
   *  - trimming preceding & ending whitespace
   *  - removing any in-string double whitespace
   *
   * @param string $string
   * @param int $length
   *
   * @return string
   */
  protected function cleanString($string, $length) {
    $replacements = [
      // Hex for &nbsp;
      '/\xC2\xA0/' => ' ',
      '/&nbsp;/' => ' ',
      // Replace multiple ideographic space with just one.
      '/(\xE3\x80\x80){2}/' => html_entity_decode("&#x3000;"),
      // Trim ideographic space (this could be done in trim further down but seems a bit fiddly)
      '/^(\xE3\x80\x80)/' => ' ',
      '/(\xE3\x80\x80)$/' => ' ',
      // Replace multiple space with just one.
      '/\s\s+/' => ' ',
      // And html ampersands with normal ones.
      '/&amp;/' => '&',
      '/&Amp;/' => '&',
    ];
    return mb_substr(trim(preg_replace(array_keys($replacements), $replacements, $string)), 0, $length);
  }

}
