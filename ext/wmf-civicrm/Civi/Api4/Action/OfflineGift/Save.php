<?php
namespace Civi\Api4\Action\OfflineGift;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\DedupeRuleGroup;
use Civi\Api4\Name;
use Civi\WMFException\DuplicateContactException;
use Civi\WMFHelper\Contact;
use Civi\WMFTransaction;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;
use CRM_Wmf_ExtensionUtil as E;

/**
 *
 */
class Save extends \Civi\Api4\Action\Contribution\Save {

  protected $_entityName = 'Contribution';

  public function validateValues() {}

  /**
   * @param array $record
   *
   * @return bool
   */
  public function isAnonymous(array $record): bool {
    if (!empty($record['first_name']) || !empty($record['last_name']) || !empty($record['full_name'])) {
      return FALSE;
    }
    $significantValues = ['street_address', 'postal_code', 'email', 'phone'];
    foreach ($significantValues as $value) {
      if (!empty($record[$value])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param array $record
   *
   * @return array
   */
  protected function getLocationValues(array $record): array {
    return array_filter([
      'email_primary.email' => $record['email'] ?? '',
      'address_primary.postal_code' => $record['postal_code'] ?? '',
      'address_primary.street_address' => $record['street_address'] ?? '',
      'address_primary.city' => $record['city'] ?? '',
      'address_primary.state_province:abbr' => $record['state_province'] ?? '',
      'address_primary.country:abbr' => $record['country'] ?? '',
    ]);
  }

  protected function getOrganizationLocationValues(array $record): array {
    return [];
  }

  /**
   * Save contribution.
   *
   * @param array $record
   * @param array $extraValues
   * @param int $contactId
   * @param float|int $giftRatio
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   */
  protected function saveContribution(array $record, array $extraValues, int $contactId, float|int $giftRatio): array {
    // @todo - pass through any other contribution fields? Perhaps this can
    // be an import target if we do - ie we set up DafGift as an entity that extends
    // contribution and cn be selected for import.
    $gatewayAccount = 'Chariot Disbursements';
    if ($record['payment_method'] === 'Check' ){
      $gatewayAccount = 'Chariot Digital Mailbox';
    }
    $channel = ($record['gift_source'] ?? '') === 'Employee Giving' ? 'Workplace Giving' : 'Other Offline';

    return Contribution::create($this->checkPermissions)
      ->setValues($extraValues + [
        'contact_id' => $contactId,
        'receive_date' => gmdate('Y-m-d', $record['date']),
        'total_amount' => $this->getProportionalGiftAmountInReportingCurrency($record['settled_total_amount'], $giftRatio),
        'fee_amount' => CurrencyRoundingHelper::round($record['settled_fee_amount'], 'USD'),
        'payment_instrument_id:name' => $record['payment_method'],
        'financial_type_id:name' => 'Cash',
        'check_number' => $record['check_number'] ?? NULL,
        'trxn_id' => WMFTransaction::from_message($record)->get_unique_id(),
        'contribution_extra.original_amount' => CurrencyRoundingHelper::round($record['original_total_amount'] * $giftRatio, $record['original_currency']),
        'contribution_extra.original_currency' => $record['original_currency'],
        'contribution_extra.gateway' => $record['gateway'],
        'contribution_extra.gateway_account' => $gatewayAccount,
        'contribution_extra.gateway_txn_id' => $record['gateway_txn_id'],
        'contribution_extra.backend_processor' => $record['backend_processor'],
        'contribution_extra.backend_processor_txn_id' => $record['backend_processor_txn_id'],
        'contribution_settlement.settlement_date' => gmdate('Y-m-d', $record['settled_date']),
        'contribution_settlement.settlement_currency' => 'USD',
        'contribution_settlement.settled_donation_amount' => CurrencyRoundingHelper::round($record['settled_total_amount'] * $giftRatio, 'USD'),
        'contribution_settlement.settled_fee_amount' => CurrencyRoundingHelper::round($record['settled_fee_amount'], 'USD'),
        'contribution_settlement.settlement_batch_reference' => $record['settlement_batch_reference'],
        'Gift_Data.Channel' => $channel,
        'Gift_Data.Appeal' => 'White Mail',
        'Gift_Data.Fund' => 'Major Gifts - CC104',
        'Gift_Data.is_major_gift' => TRUE,
        'Gift_Information.import_batch_number' => 'deposit_' . substr($record['settlement_batch_reference'], 8, -4),
        'contribution_extra.source_enqueued_time' => date('Y-m-d H:i:s', $record['source_enqueued_time']),
        'contribution_extra.source_name' => $record['source_name'],
        'contribution_extra.source_type' => $record['source_type'],
        'contribution_extra.source_version' => $record['source_version'],
        'contribution_extra.source_run_id' => $record['source_run_id'],
        'contribution_extra.source_host' => $record['source_host'],
        'Gift_Data.Campaign' => $this->getGiftType($record['gift_source'] ?? ''),
      ])
      ->execute()->single();
  }

  private function getGiftType(string $giftSource): string {
    $path = E::path('Civi/WMFHook/Import/field_transformations.json');
    $transformation = 'Gift_Data.Campaign';
    $mappings = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    return $mappings[$transformation][$giftSource] ?? $giftSource;
  }

  /**
   * @param array $record
   * @param mixed $contributionID
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createBankingInstitutionSoftCredit(array $record, int $contributionID): void {
    $bankingInstitutionID = $this->getBankingInstitution($record);
    if ($contributionID && $bankingInstitutionID) {
      ContributionSoft::create($this->checkPermissions)
        ->setValues([
          'contribution_id' => $contributionID,
          'soft_credit_type_id:name' => 'Banking Institution',
          'contact_id' => $bankingInstitutionID,
          'amount' => $record['settled_total_amount'],
        ])
        ->execute();
    }
  }

  /**
   * @param array $record
   *
   * @return int|null
   * @throws \CRM_Core_Exception
   */
  protected function getMatchingGiftOrganizationID(array $record): ?int {
    if (!empty($record['original_matching_gift_total_amount']) && empty($record['matching_gift_organization'])) {
      throw new \CRM_Core_Exception('missing matching gift organization name');
    }
    return empty($record['matching_gift_organization']) ? NULL : Contact::getOrganizationID($record['matching_gift_organization']);
  }

  /**
   * @param array $record
   *
   * @return int|null
   * @throws \CRM_Core_Exception
   */
  protected function getDAFOrganizationID(array $record): ?int {
    if (!empty($record['original_matching_gift_total_amount'])) {
      throw new \CRM_Core_Exception('unexpected DAF look up when matching_gift amount present');
    }
    $id = NULL;
    try {
      $id = empty($record['donor_advised_fund_name']) ? NULL : Contact::getOrganizationID($record['donor_advised_fund_name']);
    }
    catch (DuplicateContactException $e) {
      // Can we pick the right one - let's start with the address - people should use their
      // own address here...
      $contacts = $e->getDuplicateContacts();
      foreach ($contacts as $contact) {
        if (!empty($contact['address_primary.street_address'])
          && strtolower($contact['address_primary.street_address']) === strtolower($record['street_address'])
        ) {
          if ($id) {
            // We have 2 with the same name & street address - keep calm & panic.
            throw $e;
          }
          $id = $contact['id'];
        }
      }
      if (!$id) {
        throw $e;
      }
    }
    return $id;
  }

  /**
   * @param array $record
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  protected function createIndividual(array $record, array $nameValues): int {
    $values = array_filter([
      'contact_type' => 'Individual',
      'Partner.Partner' => $record['partner_full_name'] ?? '',
    ] + $this->getLocationValues($record)
      + $nameValues);
    // We can look at using WMFContact.save, but it needs to add something other than complexity to be useful.
    return \Civi\Api4\Contact::create(FALSE)
      ->setValues($values)->execute()->single()['id'];
  }

  /**
   * @param array $record
   * @param string $organizationName
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  protected function createOrganization(array $record, string $organizationName): int {
    $values = $this->getOrganizationLocationValues($record);
    // We can look at using WMFContact.save, but it needs to add something other than complexity to be useful.
    $id = \Civi\Api4\Contact::create(FALSE)
      ->setValues(['contact_type' => 'Organization', 'organization_name' => $organizationName] + $values)->execute()->single()['id'];
    \Civi::$statics['wmf_contact']['organization'][$organizationName]['id'] = $id;
    return $id;
  }

  /**
   * @param array $record
   * @param int|null $matchingGiftOrganizationID
   * @param string|null $organizationName
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  protected function getOrCreateIndividual(array $record, ?int $matchingGiftOrganizationID, ?string $organizationName): int {
    $parsedName = array_filter([
      'first_name' => $record['first_name'] ?? '',
      'last_name' => $record['last_name'] ?? '',
      'prefix_id:label' => $record['prefix'] ?? '',
    ]);
    if (empty($record['first_name']) && empty($record['last_name']) && !empty($record['full_name'])) {
      $parsedName = Name::parse(FALSE)
        ->setNames([$record['full_name']])
        ->execute()->first();
      $parsedName['addressee_custom'] = $parsedName['addressee_display'] = $record['full_name'];
    }
    $individualID = $this->getIndividualID($parsedName + $record, $matchingGiftOrganizationID, $organizationName);

    if (!$individualID) {
      if ($this->isAnonymous($record)) {
        $individualID = Contact::getAnonymousContactID();
      }
      else {
        $individualID = $this->createIndividual($record, $parsedName);
      }
    }
    return $individualID;
  }

  /**
   * @param array $record
   *
   * @return int|null
   * @throws \CRM_Core_Exception
   */
  protected function getBankingInstitution(array $record): ?int {
    if (empty($record['banking_institution'])) {
      return NULL;
    }
    $id = Contact::getOrganizationID($record['banking_institution']);
    if (!$id) {
      $id = \Civi\Api4\Contact::create(FALSE)
        ->setValues(['contact_type' => 'Organization', 'organization_name' => $record['banking_institution']])->execute()->single()['id'];
      \Civi::$statics['wmf_contact']['organization'][$record['banking_institution']]['id'] = $id;
    }
    return $id;
  }

  /**
   * Options callback for $this->match
   * @return array
   */
  protected function getMatchFields(): array {
    return ['invoice_id', 'invoice_number', 'contribution_extra.backend_processor_txn_id'];
  }

  /**
   * @param mixed $record
   * @param int|null $organizationID
   *
   * @return false|int
   * @throws \CRM_Core_Exception
   */
  protected function getIndividualID(mixed $record, ?int $organizationID, ?string $organizationName): int|false {
    return Contact::getIndividualID(
      $record['email'] ?? NULL,
      $record['first_name'] ?? NULL,
      $record['last_name'] ?? NULL,
      $record['postal_code'] ?? NULL,
        $organizationName,
      $organizationID
    );
  }

  /**
   * @param int $organizationID
   * @param array $duplicateContacts
   *
   * @return void
   */
  protected function organizationDuplicateAlert(int $organizationID, array $duplicateContacts, string $organizationName): void {
    $message = 'Existing duplicate contacts were not used because there were multiple matches. A new organization was created';

    $urls[] = \CRM_Utils_System::url('civicrm/contact/dedupefind', [
      'reset' => 1,
      'action' => 'update',
      'rgid' => $this->getOrganizationDedupeRuleGroupID(),
      'limit' => 1,
      'criteria' => '{"contact"%3A{"id"%3A{"IN"%3A[' . $organizationID . ']}}}',
      'cid' => $organizationID,
    ], TRUE, NULL, FALSE);
    foreach ($duplicateContacts as $contact) {
      $mergeUrl = \CRM_Utils_System::url('civicrm/contact/merge', [
        'reset' => 1,
        'cid' => $organizationID,
        'oid' => $contact['id'],
      ], TRUE, NULL, FALSE);
      $urls[] = $mergeUrl;
    }
    \Civi::log('offline_gifts')->log('info', $message . "\n" . implode("\n", $urls), [
      'subject' => $organizationName,
      'organization_id' => $organizationID,
      'organization_name' => $organizationName,
    ]);
  }

  /**
   * @return int|null
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function getOrganizationDedupeRuleGroupID(): ?int {
    $ruleGroupID = DedupeRuleGroup::get(FALSE)
      ->addWhere('name', '=', 'Organization_Name')
      ->execute()->first()['id'] ?? NULL;
    if (!$ruleGroupID) {
      $ruleGroupID = DedupeRuleGroup::get(FALSE)
        ->addWhere('name', '=', 'OrganizationUnsupervised')
        ->execute()->first()['id'] ?? NULL;
    }
    return $ruleGroupID;
  }


  /**
   * Get the proportion of the settled amount that relates to the portion of the gift.
   *
   * The settled_amount is the whole amount in the settled currency (always USD).
   *
   * This amount may be made up of a combination of individual & organization gift
   * so this splits it out.
   *
   * @param string $settled_total_amount
   * @param float|int $matchingGiftRatio
   * @param string $currency
   *
   * @return string
   */
  protected function getProportionalGiftAmountInReportingCurrency(string $settled_total_amount, float|int $matchingGiftRatio, $currency = 'USD'): string {
    return CurrencyRoundingHelper::round($settled_total_amount * $matchingGiftRatio, $currency);
  }

}
