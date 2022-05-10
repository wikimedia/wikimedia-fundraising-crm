<?php

use Civi\WMFException\WMFException;

class BenevityFile extends ChecksFile {

  /**
   * @var int
   */
  protected $conversionRate;

  /**
   * The import type descriptor.
   *
   * @var string
   */
  protected $gateway = 'benevity';

  /**
   * @return array
   */
  protected function getRequiredColumns() {
    return [
      'Company',
      'Donor First Name',
      'Donor Last Name',
      'Email',
      'Total Donation to be Acknowledged',
      'Match Amount',
      'Transaction ID',
    ];
  }

  function getRequiredData() {
    return [
      'matching_organization_name',
      'currency',
      'date',
    ];
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
   * @throws \Civi\WMFException\WMFException
   */
  protected function mungeMessage(&$msg) {
    $moneyFields = ['original_gross', 'fee', 'merchant_fee_amount', 'original_matching_amount'];
    foreach ($moneyFields as $moneyField) {
      $msg[$moneyField] = isset($msg[$moneyField]) ? (str_replace(',', '', $msg[$moneyField])) : 0;
    }

    if ($msg['original_gross'] >= 1000) {
      $msg['gift_source'] = 'Benefactor Gift';
    }
    else {
      $msg['gift_source'] = 'Community Gift';
    }
    foreach ($msg as $field => $value) {
      if ($value === 'Not shared by donor') {
        $msg[$field] = '';
      }
    }

    // Ensure currency is USD as we have done currency calculations within the Benevity import with the
    // import specific conversion rate.
    $msg['currency'] = 'USD';
    if (!empty($this->additionalFields['original_currency'])) {
      $msg['original_currency'] = $this->additionalFields['original_currency'];
    }
    $msg['gross'] = $this->getUSDAmount($msg['original_gross']);
    if (!empty($msg['merchant_fee_amount'])) {
      $msg['fee'] = $this->getUSDAmount($msg['merchant_fee_amount']) + (empty($msg['fee']) ? 0 : $this->getUSDAmount($msg['fee']));
    }

    try {
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
    catch (CiviCRM_API3_Exception $e) {
      throw new WMFException(WMFException::INVALID_MESSAGE, $e->getMessage());
    }
    $msg['date'] = $this->additionalFields['date']['year'] . '-' . $this->additionalFields['date']['month'] . '-' . $this->additionalFields['date']['day'];

  }

  /**
   * Get the amount in the original currency.
   *
   * We reverse engineer this by calculating an exchange rate from the total
   * USD amount for the import & the total original amount from the import.
   *
   * @param int $original_amount
   *
   * @return int Original Amount.
   */
  protected function getUSDAmount($original_amount) {
    if (empty($this->conversionRate)) {
      if (!empty($this->additionalFields['usd_total']) && !empty($this->additionalFields['original_currency_total'])) {
        $this->conversionRate = $this->additionalFields['usd_total'] / $this->additionalFields['original_currency_total'];
      }
      else {
        $this->conversionRate = 1;
      }
    }
    return $original_amount * $this->conversionRate;
  }

  /**
   * Validate that required fields are present.
   *
   * If a contact has already been identified name fields are not required.
   *
   * @param array $msg
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function validateRequiredFields($msg) {
    $failed = [];
    $requiredFields = $this->getRequiredData();
    if (empty($msg['contact_id'])) {
      $requiredFields = array_merge($requiredFields, ['first_name', 'last_name', 'email']);
    }
    foreach ($requiredFields as $key) {
      if (!array_key_exists($key, $msg) or empty($msg[$key])) {
        $failed[] = $key;
      }
    }
    if (count($failed) === 3) {
      throw new WMFException(WMFException::CIVI_REQ_FIELD, t("Missing required fields @keys during check import", ["@keys" => implode(", ", $failed)]));
    }
  }

  protected function getDefaultValues() {
    return [
      'source' => 'Matched gift',
      'payment_method' => 'EFT',
      'contact_type' => 'Individual',
      'country' => 'US',
      'currency' => 'USD',
      'date' => $this->additionalFields['date']['year'] . '-' . $this->additionalFields['date']['month'] . '-' . $this->additionalFields['date']['day'],
      'original_currency' => (empty($this->additionalFields['original_currency']) ? 'USD' : $this->additionalFields['original_currency']),
      // Setting this avoids emails going out. We could set the thank_you_date
      // instead to reflect Benevity having sent them out
      // but we don't actually know what date they did that on,
      // and recording it in our system would seem to imply we know for
      // sure it happened (as opposed to Benevity says it happens).
      'no_thank_you' => 1,
      'financial_type_id' => "Benevity",
    ];
  }

  /**
   * Map the import column headers to our normalized format.
   *
   * @return array
   */
  protected function getFieldMapping() {
    $mapping = parent::getFieldMapping();
    $mapping['Company'] = 'matching_organization_name';
    // $mapping['Project'] = field just contains 'Wikimedia' intermittantly. Ignore.
    $mapping['Donation Date'] = 'date';
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
    $mapping['Total Donation to be Acknowledged'] = 'original_gross';
    $mapping['Match Amount'] = 'original_matching_amount';
    $mapping['Currency'] = 'original_currency';
    $mapping['Cause Support Fee'] = 'fee';
    $mapping['Merchant Fee'] = 'merchant_fee_amount';
    // The parent sets this mapping - but it's expected to be in the format USD 15.15 - which it isn't.
    // We should find & rework the reason for this source-handling (if any). But for now just handle in Benevity.
    unset($mapping['Source']);
    return $mapping;
  }

  /**
   * Do the actual import.
   *
   * @param array $msg
   *
   * @return array
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  public function doImport($msg) {
    $contribution = [];
    if (!empty($msg['gross']) && $msg['gross'] > 0) {
      $contribution = wmf_civicrm_contribution_message_import($msg);
    }
    elseif (empty($msg['contact_id'])) {
      // We still want to create the contact and link it to the organization, and
      // soft credit it.
      wmf_civicrm_message_create_contact($msg);
    }
    if (isset($msg['employer_id']) && $msg['contact_id'] != $this->getAnonymousContactID()) {
      // This is done in the import but if we have no donation let's still do this update.
      civicrm_api3('Contact', 'create', ['contact_id' => $msg['contact_id'], 'employer_id' => $msg['employer_id']]);
    }


    if (!empty($msg['original_matching_amount']) && $msg['original_matching_amount'] > 0) {
      $msg['matching_amount'] = $this->getUSDAmount($msg['original_matching_amount']);
      $matchedMsg = $msg;
      unset($matchedMsg['net'], $matchedMsg['fee'], $matchedMsg['email']);
      $matchedMsg['contact_id'] = $msg['employer_id'];
      $matchedMsg['soft_credit_to_id'] = ($msg['contact_id'] == $this->getAnonymousContactID() ? NULL : $msg['contact_id']);
      $matchedMsg['original_gross'] = $msg['original_matching_amount'];
      $matchedMsg['gross'] = $msg['matching_amount'];
      $matchedMsg['gateway_txn_id'] = $msg['gateway_txn_id'] . '_matched';
      $matchedMsg['gift_source'] = 'Matching Gift';
      $matchedMsg['restrictions'] = 'Restricted - Foundation';
      if (empty($msg['gross'])) {
        // We no longer get separate values for the fee for the matching donation vs the main one.
        // So, now we assign the whole fee to the main donation, if there is one. Otherwise
        // it goes to the matched donation.
        $matchedMsg['fee'] = $msg['fee'];
      }

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
   * Get the ID of a matching individual.
   *
   * Refer to https://phabricator.wikimedia.org/T115044#3012232 for discussion of logic.
   *
   * @param array $msg
   *
   * @return int|NULL
   *   Contact ID to use, if no integer is returned a new contact will be created
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function getIndividualID(&$msg) {
    if (empty($msg['email'])
      && (empty($msg['first_name']) && empty($msg['last_name']))
    ) {
      try {
        // We do not have an email or a name, match to our anonymous contact (
        // note address details are discarded in this case).
        return $this->getAnonymousContactID();
      }
      catch (CiviCRM_API3_Exception $e) {
        throw new WMFException(
          WMFException::IMPORT_CONTRIB,
          t("The donation is anonymous but the anonymous contact is ambiguous. Ensure exactly one contact is in CiviCRM with the email fakeemail@wikimedia.org' and first name and last name being Anonymous "
          )
        );
      }
    }

    $params = [
      'email' => $msg['email'],
      'first_name' => $msg['first_name'],
      'last_name' => $msg['last_name'],
      'is_deleted' => 0,
      'contact_type' => 'Individual',
      'return' => 'current_employer',
      'sort' => 'organization_name DESC',
    ];
    try {
      $contacts = civicrm_api3('Contact', 'get', $params);
      if ($contacts['count'] == 1) {
        if (!empty($params['email']) || $this->isContactEmployedByOrganization($msg['matching_organization_name'], $contacts['values'][$contacts['id']])) {
          return $contacts['id'];
        }
        return FALSE;
      }
      elseif ($contacts['count'] > 1) {
        $possibleContacts = [];
        $contactID = NULL;
        foreach ($contacts['values'] as $contact) {
          if ($this->isContactEmployedByOrganization($msg['matching_organization_name'], $contact)) {
            $possibleContacts[] = $contact['id'];
          }
          if (count($possibleContacts) > 1) {
            foreach ($possibleContacts as $index => $possibleContactID) {
              if (
                $contacts['values'][$possibleContactID]['current_employer']
                !== $this->getOrganizationResolvedName($msg['matching_organization_name'])
              ) {
                unset($possibleContacts[$index]);
              }
            }
          }
        }
        return (count($possibleContacts) == 1) ? reset($possibleContacts) : FALSE;
      }
      return FALSE;
    }
    catch (CiviCRM_API3_Exception $e) {
      throw new WMFException(WMFException::IMPORT_CONTRIB, $e->getMessage());
    }
  }

  /**
   * Is the contact employed by the named organization.
   *
   * @param string $organization_name
   * @param array $contact
   *
   * @return bool
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function isContactEmployedByOrganization($organization_name, $contact) {
    if ($contact['current_employer'] == $this->getOrganizationResolvedName($organization_name)) {
      return TRUE;
    }
    $softCredits = civicrm_api3('ContributionSoft', 'get', ['contact_id' => $contact['id'], 'api.Contribution.get' => ['return' => 'contact_id']]);
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
   * @throws \CiviCRM_API3_Exception
   */
  protected function getOrganizationResolvedName($organizationName) {
    if (!isset(\Civi::$statics['offline2civicrm']['organization_resolved_name'][$organizationName])) {
      $contacts = civicrm_api3('Contact', 'get', ['nick_name' => $organizationName, 'contact_type' => 'Organization', 'return' => 'id,organization_name', 'sequential' => 1]);
      if ($contacts['count'] == 1) {
        \Civi::$statics['offline2civicrm']['organization_resolved_name'][$organizationName] = $contacts['values'][0]['organization_name'];
      }
      else {
        \Civi::$statics['offline2civicrm']['organization_resolved_name'][$organizationName] = $organizationName;
      }
    }
    return \Civi::$statics['offline2civicrm']['organization_resolved_name'][$organizationName];
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
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function getNameMatchedEmployedIndividualID($msg) {
    $matches = [];
    if (isset($msg['first_name']) && isset($msg['last_name']) && isset($msg['email'])) {
      $params = [
        'first_name' => $msg['first_name'],
        'last_name' => $msg['last_name'],
        'contact_type' => 'Individual',
        'is_deleted' => 0,
        'return' => 'current_employer',
        'options' => ['limit' => 0],
      ];
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

  /**
   * Check for any existing contributions for the given transaction.
   *
   * If either the donor transaction of the matching gift transaction have already
   * been imported return (1) imported transaction.
   *
   * If both the matching and donor transactions have been imported previously it
   * is OK to return only one
   *
   * If it appears there has been a previous partial import
   *
   * @param $msg
   *
   * @return array|bool
   *
   * @throws \Civi\WMFException\WMFException
   */
  protected function checkForExistingContributions($msg) {
    $donorTransactionNeedsProcessing = (!empty($msg['gross']) && $msg['gross'] !== "0.00");
    $matchingTransactionNeedsProcessing = (!empty($msg['original_matching_amount']) && $msg['original_matching_amount'] !== "0.00");

    $main = $matched = FALSE;
    if ($donorTransactionNeedsProcessing) {
      $main = wmf_civicrm_get_contributions_from_gateway_id($msg['gateway'], $msg['gateway_txn_id']);
    }

    if ($matchingTransactionNeedsProcessing) {
      $matched = wmf_civicrm_get_contributions_from_gateway_id($msg['gateway'], $msg['gateway_txn_id'] . '_matched');
    }

    if ($matchingTransactionNeedsProcessing && $donorTransactionNeedsProcessing) {
      // Both transactions need processing. If one finds a match and the other doesn't we have a potential error scenario
      // and should throw an exception.
      $duplicates = ($main ? 1 : 0) + ($matched ? 1 : 0);
      if ($duplicates === 1) {
        throw new WMFException(WMFException::INVALID_MESSAGE, 'Row has already been partially imported. Try searching for, and potentially deleting, a contribution with a Transaction ID of ' . (!$matched ? $msg['gateway_txn_id'] : $msg['gateway_txn_id'] . '_matched'));
      }
    }
    return $main ? $main : $matched;
  }

  /**
   * Get any fields that can be set on import at an import wide level.
   */
  public function getImportFields() {
    return [
      'usd_total' => [
        '#title' => t('USD Total'),
        '#type' => 'textfield',
      ],
      'original_currency_total' => [
        '#title' => t('Original Currency Total'),
        '#type' => 'textfield',
      ],
      'original_currency' => [
        '#title' => t('Original Currency'),
        '#type' => 'textfield',
        '#size' => 3,
        '#maxlength' => 3,
        '#default_value' => 'USD',
      ],
      'date' => [
        '#title' => t('Date'),
        '#type' => 'date',
        '#description' => t('Date money received'),
      ],
    ];
  }

  /**
   * Validate the fields submitted on the import form.
   *
   * @param array $formFields
   *
   * @throws \Exception
   */
  public function validateFormFields($formFields) {
    if (empty($formFields)) {
      // The first time this is called no fields are passed.
      return;
    }
    if ($formFields['original_currency'] !== 'USD') {
      if (empty($formFields['usd_total']) || empty($formFields['original_currency_total'])) {
        throw new Exception(t('Total fields must be set if currency is not USD'));
      }
      $numericFields = ['usd_total', 'original_currency_total'];
      foreach ($numericFields as $numericField) {
        if (!empty($formFields[$numericField]) && !is_numeric($formFields[$numericField])) {
          throw new Exception(t('Invalid value for field: ' . $numericField));
        }
      }
      civicrm_initialize();
      $currencies = civicrm_api3('Contribution', 'getoptions', ['field' => 'currency']);
      if (!empty($formFields['original_currency']) && empty($currencies['values'][$formFields['original_currency']])) {
        throw new Exception(t('Invalid currency'));
      }
    }
  }

}
