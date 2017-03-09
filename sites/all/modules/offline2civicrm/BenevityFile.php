<?php

class BenevityFile extends ChecksFile {

  function __construct($file_uri) {
    parent::__construct($file_uri);
  }

  function getRequiredColumns() {
    return array(
      'Participating Corporation',
      'Date of Donation',
      'Donor First Name',
      'Donor Last Name',
      'Email',
      'Donation Amount',
      'Matched Amount',
      'Transaction ID',
    );
  }

  function getRequiredData() {
    return array(
      'matching_organization_name',
      'currency',
      'date',
    );
  }

  /**
   * Do any final transformation on a normalized and default-laden queue message.
   *
   * We transform the organization_name to a single matching contact ID and
   * apply that value to the soft_credit_id field. If there are multiple matches
   * or no matches we do not import.
   *
   * We find or create the individual described in the Donor details and set that to the
   * contact_id for the contribution.
   *
   * If there is a matching gift the contact_id & soft_credit_id are reversed for the
   * second donation.
   *
   * @param array $msg
   *   The normalized import parameters.
   *
   * @throws IgnoredRowException
   */
  protected function mungeMessage(&$msg) {
    $msg['gateway'] = 'benevity';
    if (!isset($msg['gross'])) {
      $msg['gross'] = 0;
    }
    if ($msg['gross'] >= 1000) {
      $msg['gift_source'] = 'Benefactor Gift';
    }
    else {
      $msg['gift_source'] = 'Community Gift';
    }
    foreach ($msg as $field => $value) {
      if ($value == 'Not shared by donor') {
        $msg[$field] = '';
      }
    }

    $msg['employer_id'] = $this->getOrganizationID($msg['matching_organization_name']);
    // If we let this go through the individual will be treated as an organization.
    parent::mungeMessage($msg);
    $msg['contact_id'] = $this->getIndividualID($msg);
    if ($msg['contact_id'] == $this->getAnonymousContactID()) {
      $this->unsetAddressFields($msg);
    }
    if ($msg['contact_id'] === FALSE) {
      if (($msg['contact_id'] = $this->getNameMatchedEmployedIndividualID($msg)) != FALSE) {
        $msg['email_location_type_id'] = 'Work';
      }
    }
  }

  /**
   * Validate that required fields are present.
   *
   * If a contact has already been identified name fields are not required.
   *
   * @param array $msg
   *
   * @throws \WmfException
   */
  protected function validateRequiredFields($msg) {
    $failed = array();
    $requiredFields = $this->getRequiredData();
    if (empty($msg['contact_id'])) {
      $requiredFields = array_merge($requiredFields, array('first_name', 'last_name', 'email'));
    }
    foreach ($requiredFields as $key) {
      if (!array_key_exists($key, $msg) or empty($msg[$key])) {
        $failed[] = $key;
      }
    }
    if (count($failed) === 3) {
      throw new WmfException('CIVI_REQ_FIELD', t("Missing required fields @keys during check import", array("@keys" => implode(", ", $failed))));
    }
  }

  protected function getDefaultValues() {
    return array(
      'source' => 'Matched gift',
      'payment_method' => 'EFT',
      'contact_type' => 'Individual',
      'country' => 'US',
      'currency' => 'USD',
      // Setting this avoids emails going out. We could set the thank_you_date
      // instead to reflect Benevity having sent them out
      // but we don't actually know what date they did that on,
      // and recording it in our system would seem to imply we know for
      // sure it happened (as opposed to Benevity says it happens).
      'no_thank_you' => 1,
      'financial_type_id' => "Benevity",
    );
  }

  /**
   * Map the import column headers to our normalized format.
   *
   * @return array
   */
  protected function getFieldMapping() {
    $mapping = parent::getFieldMapping();
    $mapping['Participating Corporation'] = 'matching_organization_name';
    // $mapping['Project'] = field just contains 'Wikimedia' intermittantly. Ignore.
    $mapping['Date of Donation'] = 'date';
    $mapping['Donor First Name'] = 'first_name';
    $mapping['Donor Last Name'] = 'last_name';
    $mapping['Email'] = 'email';
    $mapping['Address'] = 'street_address';
    $mapping['City'] = 'city';
    $mapping['State/Province'] = 'state_province';
    $mapping['Postal Code'] = 'postal_code';
    $mapping['Comment'] = 'notes';
    $mapping['Transaction ID'] = 'gateway_txn_id';
    // Not sure we need this - notes currently used for comments but few of them.
    // $mapping['Donation Frequency'] = 'notes';
    $mapping['Donation Amount'] = 'gross';
    $mapping['Matched Amount'] = 'matching_amount';
    return $mapping;
  }

  /**
   * Do the actual import.
   *
   * @param array $msg
   * @return array
   */
  public function doImport($msg) {
    $contribution = array();
    if (!empty($msg['gross']) && $msg['gross'] > 0) {
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    elseif (empty($msg['contact_id'])) {
      // We still want to create the contact and link it to the organization, and
      // soft credit it.
      wmf_civicrm_message_create_contact($msg);
    }

    if (!empty($msg['matching_amount']) && $msg['matching_amount'] > 0) {
      $matchedMsg = $msg;
      unset($matchedMsg['net'], $matchedMsg['fee']);
      $matchedMsg['contact_id'] = $msg['employer_id'];
      $matchedMsg['soft_credit_to_id'] = ($msg['contact_id'] == $this->getAnonymousContactID() ? NULL : $msg['contact_id']);
      $matchedMsg['gross'] = $msg['matching_amount'];
      $matchedMsg['gateway_txn_id'] = $msg['gateway_txn_id'] . '_matched';
      $matchedMsg['gift_source'] = 'Matching Gift';
      $matchedMsg['restrictions'] = 'Restricted - Foundation';
      $this->unsetAddressFields($matchedMsg);
      $matchingContribution = wmf_civicrm_contribution_message_import($matchedMsg);
    }

    if (empty($contribution)) {
      return $matchingContribution;
    }
    $this->mungeContribution($contribution);
    return $contribution;
  }

  /**
   * Get the id of the organization whose nick name (preferably) or name matches.
   *
   * If there are no possible matches this will fail. It will also fail if there
   * are multiple possible matches of the same priority (ie. multiple nick names
   * or multiple organization names.)
   *
   * @param string $organizationName
   *
   * @return array
   *
   * @throws \WmfException
   */
  protected function getOrganizationID($organizationName) {
    // Using the Civi Statics pattern for php caching makes it easier to reset in unit tests.
    if (!isset(\Civi::$statics[__CLASS__]['organization'][$organizationName])) {
      $contacts = civicrm_api3('Contact', 'get', array('nick_name' => $organizationName, 'contact_type' => 'Organization'));
      if ($contacts['count'] == 0) {
        $contacts = civicrm_api3('Contact', 'get', array('organization_name' => $organizationName, 'contact_type' => 'Organization'));
      }
      if ($contacts['count'] == 1) {
        \Civi::$statics[__CLASS__]['organization'][$organizationName] = $contacts['id'];
      }
      else {
        \Civi::$statics[__CLASS__]['organization'][$organizationName] = NULL;
      }
    }

    if (\Civi::$statics[__CLASS__]['organization'][$organizationName]) {
      return \Civi::$statics[__CLASS__]['organization'][$organizationName];
    }
    throw new WmfException(
      'IMPORT_CONTRIB',
      t("Did not find exactly one Organization with the details: @organizationName. You will need to ensure a single Organization record exists for the contact first",
        array(
          '@organizationName' => $organizationName,
        )
      )
    );
  }

  /**
   * Get the ID of a matching individual.
   *
   * Refer to https://phabricator.wikimedia.org/T115044#3012232 for discussion of logic.
   *
   * @param array $msg
   *
   * @return int|NULL
   *   Contact ID to use, if no integer is returned a new contact will be created
   *
   * @throws \WmfException
   */
  protected function getIndividualID(&$msg) {
    if (empty($msg['email'])
      && (empty($msg['first_name']) && empty($msg['last_name']))
    ) {
      try {
        // At best we have a first name or a last name. Match this to our anonymous contact.
        return $this->getAnonymousContactID();
      } catch (CiviCRM_API3_Exception $e) {
        throw new WmfException(
          'IMPORT_CONTRIB',
          t("The donation is anonymous but the anonymous contact is ambiguous. Ensure exactly one contact is in CiviCRM with the email fakeemail@wikimedia.org' and first name and last name being Anonymous "
          )
        );
      }
    }

    $params = array(
      'email' => $msg['email'],
      'first_name' => $msg['first_name'],
      'last_name' => $msg['last_name'],
      'contact_type' => 'Individual',
      'return' => 'current_employer',
      'sort' => 'organization_name DESC',
    );
    try {
      $contacts = civicrm_api3('Contact', 'get', $params);
      if ($contacts['count'] == 1) {
        if (!empty($params['email']) || $this->isContactEmployedByOrganization($msg['matching_organization_name'], $contacts['values'][$contacts['id']])) {
          return $contacts['id'];
        }
        return false;
      }
      elseif ($contacts['count'] > 1) {
        $contactID = NULL;
        foreach ($contacts['values'] as $contact) {
          if ($this->isContactEmployedByOrganization($msg['matching_organization_name'], $contact)) {
            if ($contactID) {
              throw new WmfException('IMPORT_CONTRIB', 'Ambiguous contact');
            }
            $contactID = $contact['id'];
          }
        }
        if (!$contactID && empty($params['email'])) {
          // Do not create a contact - error out & let importer ensure a contact exists.
          throw new WmfException('IMPORT_CONTRIB', 'Ambiguous contact');
        }

        return $contactID;
      }
      return FALSE;
    }
    catch (CiviCRM_API3_Exception $e) {
      throw new WmfException('IMPORT_CONTRIB', $e->getMessage());
    }
  }

  /**
   * Get the ID of our anonymous contact.
   *
   * @return int
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
   * Is the contact employed by the named organization.
   *
   * @param string $organization_name
   * @param array $contact
   *
   * @return bool
   */
  protected function isContactEmployedByOrganization($organization_name, $contact) {
    if ($contact['current_employer'] == $this->getOrganizationResolvedName($organization_name)) {
      return TRUE;
    }
    $softCredits = civicrm_api3('ContributionSoft', 'get', array('contact_id' => $contact['id'], 'api.Contribution.get' => array('return' => 'contact_id')));
    if ($softCredits['count'] == 0) {
      return FALSE;
    }
    foreach ($softCredits['values'] as $softCredit) {
      if ($softCredit['api.Contribution.get']['values'][0]['contact_id'] == $this->getOrganizationID($organization_name)) {
        return TRUE;
      }
    }
    return FALSE;

  }

  /**
   * Get the resolved name of an organization.
   *
   * @param string $organizationName
   *
   * @return string
   *   The name of an organization that matches the nick_name if one exists, otherwise the
   *   passed in name.
   *
   * @throws \WmfException
   */
  protected function getOrganizationResolvedName($organizationName) {
    if (!isset(\Civi::$statics[__CLASS__]['organization_resolved_name'][$organizationName])) {
      $contacts = civicrm_api3('Contact', 'get', array('nick_name' => $organizationName, 'contact_type' => 'Organization', 'return' => 'id,organization_name', 'sequential' => 1));
      if ($contacts['count'] == 1) {
        \Civi::$statics[__CLASS__]['organization_resolved_name'][$organizationName] = $contacts['values'][0]['organization_name'];
      }
      else {
        \Civi::$statics[__CLASS__]['organization_resolved_name'][$organizationName] = $organizationName;
      }
    }
    return \Civi::$statics[__CLASS__]['organization_resolved_name'][$organizationName];
  }

  /**
   * Get the id of any employee who is a full name match but has a different email.
   *
   * We handle this outside the main getIndividualID because contact's matched
   * by this method need to have their email preserved.
   *
   * @param array $msg
   *
   * @return mixed
   */
  protected function getNameMatchedEmployedIndividualID($msg) {
    $matches = array();
    if (isset($msg['first_name']) && isset($msg['last_name']) && isset($msg['email'])) {
      $params = array(
        'first_name' => $msg['first_name'],
        'last_name' => $msg['last_name'],
        'contact_type' => 'Individual',
        'return' => 'current_employer',
        'options' => array('limit' => 0),
      );
      unset($params['email']);
      $contacts = civicrm_api3('Contact', 'get', $params);
      foreach ($contacts['values'] as $contact) {
        if ($this->isContactEmployedByOrganization($msg['matching_organization_name'], $contact)) {
          $matches[] = $contact['id'];
        }
      }
    }
    if (count($matches) === 1) {
      return reset($matches);
    }
    return FALSE;
  }

}
