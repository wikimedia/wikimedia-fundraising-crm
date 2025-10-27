<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHook;

use Civi\Api4\ExchangeRate;
use Civi\Api4\GroupContact;
use Civi\Api4\UserJob;
use Civi\Core\Event\GenericHookEvent;
use Civi\WMFHelper\Contact;
use Civi\WMFHelper\Contribution as ContributionHelper;
use Civi\WMFHelper\ContributionSoft as ContributionSoftHelper;

class Import {

  private GenericHookEvent $event;
  private string $importType;

  /**
   * @var mixed|void
   */
  private string $context;

  private array $mappedRow;

  private array $rowValues;

  private int $userJobID;

  /**
   * Call WMF import hook functionality.
   *
   * @param \Civi\Core\Event\GenericHookEvent $event
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public static function alterMappedRow(GenericHookEvent $event): void {
    $importAlterer = new self($event);
    $importAlterer->alterRow();
  }

  /** @noinspection PhpUndefinedFieldInspection */
  public function __construct($event) {
    $this->event = $event;
    $this->importType = $this->event->importType;
    $this->context = $this->event->context;
    $this->mappedRow = $event->mappedRow;
    $this->rowValues = $event->rowValues;
    $this->userJobID = $event->userJobID;
  }

  /**
   *  Alter the import mapped row for WMF specific handling.
   *
   *  1) If organization_name is set we prioritise any contact with a
   *    matching nick name (as long as there is only 1).
   *  2) If an individual is being soft credited, or has a contribution
   *    being soft credited to an organization, then
   *    we look up that individual using our custom logic which
   *    prioritises contacts with a relationship or soft credit
   *    with the organization.
   *  3) For Fidelity imports we
   *   - a) add an additional soft credit of the type 'Banking Institution' to the Fidelity organization
   *   - b) use 'Anonymous Fidelity Donor Advised Fund' for the organization if it is Anonymous
   *   - c) copy the street address from the main (organization/DAF donor) to the soft credited individual.
   *  4) wmf_contribution.extra.no_thankyou is set if not present - this prevents an email going out if
   *    they are already thanked.
   * 5) For Benevity imports we
   *    a) filter out any values set to 'Not shared by donor'
   *    b) applies a mapping to the GiftData.Campaign field mapping incoming values to our values.
   *    c) handles fee_amount split over 2 fields
   *    d) handles the benevity specific combination of donations per row (see doBenevityWrangling())
   *
   *  Note there is also custom code in the ContributionSoft pre hook
   *  to create the relationship for contacts with an employee-ish soft
   *  credit type (ie workplace or matching_gifts) and to create the DAF relationship
   *  for donor advised fund soft credits.
   *
   * @see https://wikitech.wikimedia.org/w/index.php?title=Fundraising/Internal-facing/CiviCRM/Imports
   *
   * @throws \CRM_Core_Exception
   */
  private function alterRow(): void {
    // Tweaks to apply during validate only.
    $this->inValidateModeDoNotRequireTotalAmount();
    // Tweaks to apply during validate and import.
    $this->filterBadBenevityData();
    $this->applyFieldTransformations();

    // Tweaks to apply during import only.
    if ($this->context === 'import' && $this->importType === 'contribution_import') {
      if (!empty($this->mappedRow['SoftCreditContact'])) {
        // Upstream this got converted from an array of arrays to just a single array.
        // The thinking being that another one would be added more like $this->mappedRow['SoftCreditContact2']
        // but it's kinda in limbo. Still upstream copes with the original format at save time
        // and all our code is written for that...
        $this->mappedRow['SoftCreditContact'] = [$this->mappedRow['SoftCreditContact']];
      }
      // Provide a default, allowing the import to be configured to override.
      $isMatchingGift = $this->isMatchingGift();
      // Now ensure converted total_amount is set.
      // We haven't really done any updates so far but possibly it we do we would
      // want to extend the if to check the provided values. But safer not to touch on update if we don't have to.
      if (!isset($this->mappedRow['Contribution']['total_amount']) && empty($this->mappedRow['Contribution']['id'])) {
        $this->mappedRow['Contribution']['total_amount'] = ContributionHelper::getConvertedTotalAmount($this->mappedRow['Contribution']);
      }

      if ($isMatchingGift && !array_key_exists('contribution_extra.no_thank_you', $this->mappedRow['Contribution'])) {
        $this->mappedRow['Contribution']['contribution_extra.no_thank_you'] = 'Sent by portal (matching gift/ workplace giving)';
      }
      // Assume matching gifts if there is a soft credit involved...
      if (empty($this->mappedRow['Contribution']['contribution_extra.gateway'])) {
        $this->mappedRow['Contribution']['contribution_extra.gateway'] = $isMatchingGift ? 'Matching Gifts' : 'CiviCRM Import';
      }

      // If we have only a contact ID we can use that to determine the contact type
      // and to ensure we have any organization name loaded.
      if (
        !empty($this->mappedRow['Contribution']['contact_id'])
        && (
          empty($this->mappedRow['Contact'])
          || ($this->mappedRow['Contact']['contact_type'] === 'Organization' && empty($this->mappedRow['Contact']['organization_name']))
        )
      ) {
        // We have just an ID, so it is in ['Contribution']['contact_id']
        // Since we have handling further down let's populate the contact.
        $this->mappedRow['Contact']['id'] = (int) $this->mappedRow['Contribution']['contact_id'];
        $organizationName = Contact::getOrganizationName($this->mappedRow['Contact']['id']);
        if ($organizationName) {
          $this->mappedRow['Contact']['organization_name'] = $organizationName;
        }
        // If it didn't return a name we can assume it is an individual.
        $this->mappedRow['Contact']['contact_type'] = $organizationName ? 'Organization' : 'Individual';
      }

      if (empty($this->mappedRow['Contribution']['id'])) {
        if (empty($this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'])) {
          // Generate a transaction ID so that we don't import the same rows multiple times
          $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'] = ContributionHelper::generateTransactionReference(
            $this->mappedRow['Contact'],
            $this->mappedRow['Contribution']['receive_date'] ?? date('Y-m-d'),
            $this->mappedRow['Contribution']['check_number'] ?? NULL,
            $this->rowValues[array_key_last($this->rowValues)],
            $this->mappedRow['Contribution']['Gift_Information.import_batch_number'] ?? NULL,
            $this->userJobID);
        }

        $this->mappedRow['Contribution']['contribution_extra.gateway'] = $this->getGateway();
        $existingContributionID = ContributionHelper::exists($this->mappedRow['Contribution']['contribution_extra.gateway'], $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id']);
        if ($existingContributionID) {
          throw new \CRM_Core_Exception('This contribution appears to be a duplicate of contribution id ' . $existingContributionID);
        }

        $this->doFidelityWrangling();
      }

      $isRequireOrganizationResolution = !$this->isFidelity();

      if (!empty($this->mappedRow['SoftCreditContact'])) {
        if (($this->mappedRow['Contact']['contact_type'] ?? NULL) === 'Organization') {
          // If we can identify the organization here then we can try to improve on the dedupe
          // contact look up for the related individual by looking for contacts
          // with a relationship or prior soft credit.
          try {
            $organizationName = self::resolveOrganization($this->mappedRow['Contact']);
            $this->mappedRow['Contribution']['contact_id'] = $this->mappedRow['Contact']['id'];
            foreach ($this->mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
              if (empty($this->mappedRow['SoftCreditContact'][$index]['id'])) {
                $this->mappedRow['SoftCreditContact'][$index]['id'] = Contact::getIndividualID(
                  $softCreditContact['email_primary.email'] ?? NULL,
                  $softCreditContact['first_name'] ?? NULL,
                  $softCreditContact['last_name'] ?? NULL,
                  $organizationName
                );
              }
            }
          }
          catch (\CRM_Core_Exception $e) {
            if ($isRequireOrganizationResolution) {
              // The prior soft-credit resolution is possibly going out of favour - it
              // may have been more transitional. Regardless we only want it as an optional
              // extra for Fidelity.
              throw $e;
            }
          }
        }
        elseif ($this->mappedRow['Contact']['contact_type'] === 'Individual') {
          $organizationName = $organizationID = NULL;
          foreach ($this->mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
            if ($softCreditContact['contact_type'] === 'Organization') {
              if (!empty($softCreditContact['id'])) {
                $organizationID = $softCreditContact['id'];
              }
              else {
                $organizationName = self::resolveOrganization($this->mappedRow['SoftCreditContact'][$index]);
              }
            }
          }
          $this->mappedRow['Contact']['id'] = $this->mappedRow['Contribution']['contact_id'] ?? FALSE;
          if (!$this->mappedRow['Contact']['id']) {
            $this->mappedRow['Contact']['id'] = Contact::getIndividualID(
              $this->mappedRow['Contact']['email_primary.email'] ?? NULL,
              $this->mappedRow['Contact']['first_name'] ?? NULL,
              $this->mappedRow['Contact']['last_name'] ?? NULL,
              $organizationName,
              $organizationID
            );
          }
        }
      }
      $this->doBenevityWrangling();
      if (!empty($this->mappedRow['Contact']['id'])) {
        $this->mappedRow['Contribution']['contact_id'] = (int) $this->mappedRow['Contact']['id'];
        if ($this->mappedRow['Contribution']['contact_id'] === Contact::getAnonymousContactID()) {
          // Unset all other contact fields - we do not want to update the Anonymous contact.
          $this->mappedRow['Contact'] = ['id' => $this->mappedRow['Contribution']['contact_id']];
        }
      }
      $this->ensureTrxnIdentifiersSet();
      $this->setTimeOfDayIfStockDonation();
    }
    if ($this->mappedRow !== $this->event->mappedRow) {
      $this->event->mappedRow = $this->mappedRow;
    }
  }

  /**
   * Get the soft_credit_type_id for the given row.
   *
   * @param array $row
   * @return int|null
   */
  private static function getSoftCreditTypeIDForRow(array $row): ?int {
    if (empty($row['SoftCreditContact'])) {
      return NULL;
    }
    $record = reset($row['SoftCreditContact']);
    return (int) $record['soft_credit_type_id'];
  }

  /**
   * @param array $organizationContact
   * @return string
   * @throws \CRM_Core_Exception
   */
  private static function resolveOrganization(array &$organizationContact): string {
    if (empty($organizationContact['id']) && empty($organizationContact['organization_name'])) {
      $organizationName = '';
    }
    elseif (empty($organizationContact['id'])) {
      $organizationName = Contact::resolveOrganizationName((string) $organizationContact['organization_name']);
      $organizationContact['id'] = Contact::getOrganizationID($organizationContact['organization_name']);
    }
    else {
      $organizationName = Contact::getOrganizationName($organizationContact['id']);
    }
    // We don't want to over-write the organization_name as we might have matched on nick name.
    // It's arguable as to whether this is the right behaviour when id is set (perhaps
    // they are trying to update the name?) but this is at least consistent.
    unset($organizationContact['organization_name']);
    return $organizationName;
  }

  /**
   * Get the gateway (e.g fidelity, matching_gifts, benevity).
   *
   * Generally this is mapped in the import mapping and will show up in the mapped row.
   * That may always be true now, but originally it was not possible to set a default
   * so there is some additional handling based on the import template job name which
   * possibly can go now.
   *
   * @throws \CRM_Core_Exception
   */
  protected function getGateway(): string {
    return !empty($this->mappedRow['Contribution']['contribution_extra.gateway']) ? $this->mappedRow['Contribution']['contribution_extra.gateway'] : $this->getUserJob()['gateway'];
  }

  /**
   * Get the transformed field based on the relevant field transformation.
   *
   * This is a public static function for now as we are calling it from
   * Benevity import (as part of moving that into this extension/methodology).
   * It should become private or protected on this class later on as we consolidate
   * here.
   *
   * @param string $transformation
   * @param string $originalValue
   *   At time of writing only strings are valid for original & return value.
   *   This could reasonably change - at which point we can loosen this type strictness.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public static function getTransformedField(string $transformation, string $originalValue): string {
    $mapping = self::getFieldTransformation($transformation);
    if (array_key_exists($originalValue, $mapping)) {
      return $mapping[$originalValue];
    }
    // What do we do if it doesn't exist. For now we don't transform but
    // potentially the transform array could include a 'default' key (the value
    // to use if originalValue is empty) and a 'not_found' key (the value to use
    // if the original value is not mapped.
    return $originalValue;
  }

  /**
   *
   * @param string $transformation
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private static function getFieldTransformation(string $transformation): array {
    $mappings = self::getAvailableTransformations();
    if (!array_key_exists($transformation, $mappings) || !is_array($mappings[$transformation])) {
      throw new \CRM_Core_Exception('invalid transformation requested');
    }
    return $mappings[$transformation];
  }

  /**
   * Get our available transformations.
   *
   * All this does at the moment is retrieve arrays from a json file.
   * They could easily be declared as an array in place - but the direction I
   * am experimenting with is the idea that they could be selected & applied
   * to a field in the UI, in which case just maybe the user could edit them there.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   * @noinspection PhpMultipleClassDeclarationsInspection
   */
  private static function getAvailableTransformations(): array {
    try {
      // @todo - we only have one transformation right now but in future we can
      // think about one big one vs multiple import specific ones.
      return json_decode(file_get_contents(__DIR__ . '/Import/field_transformations.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new \CRM_Core_Exception('JSON is invalid');
    }
  }

  /**
   * For stock donations, we want to make sure the date on the import spreadsheet is the same as
   * the date on the thank you mail. Since our mailing process uses Hawaii time (UTC-10) we have
   * to set the hour to something later than that.
   *
   * @return void
   */
  private function setTimeOfDayIfStockDonation(): void {
    if (!isset(\Civi::$statics[__CLASS__]['stockType'])) {
      \Civi::$statics[__CLASS__]['stockType'] = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Stock');
    }
    $stockType = \Civi::$statics[__CLASS__]['stockType'];
    if (
      $this->mappedRow['Contribution']['financial_type_id'] == $stockType &&
      !empty($this->mappedRow['Contribution']['receive_date'])
    ) {
      $date = new \DateTime($this->mappedRow['Contribution']['receive_date']);
      $date->setTime(12, 0);
      $this->mappedRow['Contribution']['receive_date'] = date_format($date, 'YmdHis');
    }
  }

  /**
   * Creates an 'empty' dedupe contact to be populated during csv import.
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  public static function createDedupeContact() {
    $contactID = \Civi\Api4\Contact::create(FALSE)
      ->setValues([
        'contact_type',
        '=',
        'Individual',
        'source' => 'Import duplicate contact',
      ])
      ->execute()->first()['id'];
    GroupContact::create(FALSE)
      ->setValues([
        'contact_id' => $contactID,
        'group_id:name' => 'imported_duplicates',
        'status' => 'Added',
      ])
      ->execute();
    return $contactID;
  }

  /**
   * @param string $fieldName
   *
   * @return string|null
   * @throws \CRM_Core_Exception
   */
  public function getOriginalValue(string $fieldName): ?string {
    $userJob = $this->getUserJob();
    $mappings = $userJob['metadata']['import_mappings'] ?? [];
    foreach ($mappings as $mapping) {
      $mappingName = $mapping['name'] ?? '';
      if (str_starts_with($mappingName, 'Contact.')) {
        $mappingName = substr($mappingName, 8);
      }
      if (str_starts_with($mappingName, 'Contribution.')) {
        $mappingName = substr($mappingName, 13);
      }
      if ($mappingName === $fieldName) {
        return $this->rowValues[$mapping['column_number']];
      }
    }
    return NULL;
  }

  /**
   * Get the user job.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  private function getUserJob(): array {
    if (!isset(\Civi::$statics[__CLASS__]['user_job'])) {
      $userJob = UserJob::get(FALSE)->addWhere('id', '=', $this->userJobID)->execute()->first();
      $templateID = $userJob['metadata']['template_id'] ?? NULL;
      $gateway = 'civicrm_import';
      if ($templateID) {
        try {
          $gateway = UserJob::get(FALSE)->addSelect('name')->addWhere('id', '=', $templateID)->execute()->first()['name'];
        }
        catch (\CRM_Core_Exception $e) {
          // ah well.
        }
      }
      $userJob['gateway'] = $gateway;
      \Civi::$statics[__CLASS__]['user_job'] = $userJob;
    }
    return \Civi::$statics[__CLASS__]['user_job'];
  }

  /**
   * In validate mode to not require total amount.
   *
   * Total amount is a required import field, but we calculate it in the Contribution pre
   * hook from the original amound & original currency. Here we trick the import
   * validate into thinking it is present in the import.
   *
   * @return void
   */
  private function inValidateModeDoNotRequireTotalAmount(): void {
    if ($this->context === 'validate' && empty($this->mappedRow['Contribution']['total_amount']) &&
      // Currency should be mapped - even if just as a default but this check is arguably not needed.
      !empty($this->mappedRow['Contribution']['contribution_extra.original_currency'])
      // Note for Benevity this could be set to 0 - because Benevity is special...
      // see doBenevityWrangling() fot the handling.
      && ($this->isBenevity() || !empty($this->mappedRow['Contribution']['contribution_extra.original_amount']))
    ) {
      // This is strictly in validate mode so the value doesn't matter (although I
      // deliberately made it insanely large so it gets noticed if it IS used).
      // What matters is whether it is empty, not the value. As with the validateForm hook hack
      // I am hoping this is temporary - ref https://lab.civicrm.org/dev/core/-/issues/5456
      $this->mappedRow['Contribution']['total_amount'] = 99999999;
    }
  }

  private function isBenevity(): bool {
    return $this->getGateway() === 'benevity';
  }

  /**
   * Filter out data from the benevity file that denotes no data provided.
   *
   * The rows may be 'Not shared by donor' - we filter this out, including if it
   * has already been compared with valid options.
   *
   * @throws \CRM_Core_Exception
   */
  public function filterBadBenevityData(): void {
    if ($this->isBenevity() && isset($this->mappedRow['Contact'])) {
      foreach ($this->mappedRow['Contact'] as $field => $value) {
        if ($value === 'Not shared by donor' || ($value === 'invalid_import_value' && $this->getOriginalValue($field) === 'Not shared by donor')) {
          unset($this->mappedRow['Contact'][$field]);
        }
      }
    }
  }

  /**
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function isMatchingGift(): bool {
    return in_array(self::getSoftCreditTypeIDForRow($this->mappedRow), ContributionSoftHelper::getEmploymentSoftCreditTypes(), TRUE);
  }

  /**
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function isFidelity(): bool {
    return $this->getGateway() === 'fidelity';
  }

  /**
   * Quasi-generic method for transforming field values based on a json file holding an array of swaps.
   *
   * Currently only applies to the Gift_Data.Campaign field in the benevity import.
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function applyFieldTransformations(): void {
    foreach (self::getAvailableTransformations() as $transformation => $mapping) {
      if (($this->mappedRow['Contribution'][$transformation] ?? '') === 'invalid_import_value') {
        $transformedValue = self::getTransformedField($transformation, $this->getOriginalValue($transformation));
        if ($transformedValue) {
          $this->mappedRow['Contribution'][$transformation] = $transformedValue;
        }
      }
    }
  }

  /**
   * Do Fidelity specific wrangling.
   *
   * - Add 'Banking Institution' soft credit to the Fidelity contact.
   * - Use specific anonymous organization contact when the fund is unknown.
   * - Copy address data from the contribution contact to the soft credit contact.
   *
   * @throws \CRM_Core_Exception
   */
  public function doFidelityWrangling(): void{
    if ($this->isFidelity()) {
      // For Fidelity we add a secondary contribution to Fidelity.
      // We also ensure any anonymous org ones are set to 'Anonymous Fidelity Donor Advised Fund')
      if (($this->mappedRow['Contact']['organization_name'] ?? '') === 'Anonymous') {
        $this->mappedRow['Contact']['organization_name'] = 'Anonymous Fidelity Donor Advised Fund';
      }
      foreach ($this->mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
        $isAnonymous = empty($softCreditContact['first_name']) && empty($softCreditContact['last_name']);
        if ($isAnonymous) {
          unset($this->mappedRow['SoftCreditContact'][$index]);
          continue;
        }
        if (!empty($softCreditContact['address_primary.street_address'])) {
          // If the street address is set let's assume it is deliberate and nothing else should copy over.
          continue;
        }
        // Otherwise we copy the address fields from the Main contact to the soft credit contact.
        // We do this because Fidelity only provides one address column, which applies to both.
        foreach ($this->mappedRow['Contact'] as $field => $value) {
          if ($value && str_starts_with($field, 'address_primary.') && empty($softCreditContact[$field])) {
            $this->mappedRow['SoftCreditContact'][$index][$field] = $value;
          }
        }
      }

      $this->mappedRow['SoftCreditContact']['Fidelity'] = [
        'soft_credit_type_id' => ContributionSoftHelper::getBankingInstitutionSoftCreditTypes()['Banking Institution'],
        'total_amount' => $this->mappedRow['Contribution']['total_amount'],
        'contact_type' => 'Organization',
        'id' => Contact::getOrganizationID('Fidelity Charitable Gift Fund'),
      ];
    }
  }

  /**
   * Ensure trxn_id is set.
   *
   * Do this near the end of the import so that we can base trxn_id off gateway_txn_id,
   * incorporating any changes made to the latter (looking at you Benevity import).
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function ensureTrxnIdentifiersSet(): void {
    if (empty($this->mappedRow['Contribution']['id'])) {
      if (empty($this->mappedRow['Contribution']['trxn_id'])) {
        $this->mappedRow['Contribution']['trxn_id'] = $this->getGateway() . ' ' . $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'];
      }
    }
  }

  /**
   * Retrieves the first soft credit contact from the mapped row.
   *
   * In most cases there will only be one.
   *
   * Returns the contact details for the first soft credit contact or an empty array if none exist.
   *
   * @return array The contact details of the first soft credit contact, or an empty array if no contacts are available.
   */
  private function getFirstSoftCreditContact(): array {
    foreach ($this->mappedRow['SoftCreditContact'] as $softCreditContact) {
      return $softCreditContact;
    }
    return [];
  }

  /**
   * Do Benevity specific wrangling.
   *
   * This includes
   * - adding the 2 incoming fee columns together (we unset 'contribution_extra.scheme_fee' afterwards)
   * - handling the very specific Benevity import which has columns for the individual gift and the matched
   *   gift with either or both populated (we unset 'Matching_Gift_Information.Match_Amount' afterwards)
   *
   * The Benevity import might have one of the following combinations. We cope.
   * - Individual contribution (no wrangling required)
   * - Individual contribution + matched contribution from an organization
   * - Matched contribution only.
   *
   * The individual may or may not provide enough information for us to treat them as a contact record
   * (email or first + last name). If not we put any donations to the anonymous donor and do not
   * add soft credits for the anonymous donor.
   *
   * @throws \CRM_Core_Exception
   */
  private function doBenevityWrangling(): void {
    if ($this->isBenevity()) {
      // Calculate the fee_amount from the 2 fee fields.
      $this->mappedRow['Contribution']['fee_amount'] = $this->mappedRow['Contribution']['fee_amount'] + $this->mappedRow['Contribution']['contribution_extra.scheme_fee'];
      if ($this->mappedRow['Contribution']['contribution_extra.original_currency'] !== 'USD') {
        $this->mappedRow['Contribution']['fee_amount'] = (float) ExchangeRate::convert(FALSE)
          ->setFromCurrency($this->mappedRow['Contribution']['contribution_extra.original_currency'])
          ->setFromAmount($this->mappedRow['Contribution']['fee_amount'])
          ->setTimestamp($this->mappedRow['Contribution']['receive_date'] ?? 'now')
          ->execute()->first()['amount'];
      }

      // Create the individual donor if not anonymous.
      $isIndividualAnonymous = $this->isMainContactAnonymous();
      if (!$isIndividualAnonymous && empty($this->mappedRow['Contact']['id'])) {
        $this->mappedRow['Contact']['id'] = \Civi\Api4\Contact::create(FALSE)
          ->setValues($this->mappedRow['Contact'])
          ->execute()->first()['id'];
      }

      // Handle possibility of matched individual contribution AND an organization contribution.
      if ($this->benevityHasOrganizationDonation() && $this->benevityHasIndividualDonation()) {
        // If we reach this point then we have two contributions on one row..
        // The import will handle the main donation but it does not expect to handle a second contribution.
        // So we have to create the matched organization contribution here.
        // Fortunately we should be able to count on the organization_id having been resolved by now.
        $contributionValues = array_merge($this->mappedRow['Contribution'], [
          'contact_id' => $this->getFirstSoftCreditContact()['id'],
          // The full fee will have been assigned to the main donation.
          'fee_amount' => 0,
          'trxn_id' => $this->getGateway() . ' ' . $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'] . '_MATCHED',
          'contribution_extra.gateway_txn_id' => $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'] . '_MATCHED',
          'contribution_extra.original_amount' => $this->mappedRow['Contribution']['Matching_Gift_Information.Match_Amount'],
          'contribution_extra.original_currency' => $this->mappedRow['Contribution']['contribution_extra.original_currency'],
        ]);
        // total_amount should be recalculated in the hook
        // scheme fee is otherwise unmapped further down & not for saving.
        unset($contributionValues['total_amount'], $contributionValues['contribution_extra.scheme_fee']);
        $contribution = \Civi\Api4\Contribution::create(FALSE)
          ->setValues($contributionValues)
          ->execute()->first();
        if (!$isIndividualAnonymous) {
          \Civi\Api4\ContributionSoft::create(FALSE)
            ->setValues([
              'contact_id' => $this->mappedRow['Contact']['id'],
              'contribution_id' => $contribution['id'],
              'amount' => $contribution['total_amount'],
              'soft_credit_type_id:name' => 'matched_gift',
            ])
            ->execute();
        }
      }

      // Handle possibility of matched organization contribution but no individual contribution.
      if ($this->benevityHasOrganizationDonation() && !$this->benevityHasIndividualDonation()) {
        // If there is no individual main donation then we switch the soft credit
        // to be the main donation & remove the soft credit.
        $this->mappedRow['Contribution']['contribution_extra.original_amount'] = $this->mappedRow['Contribution']['Matching_Gift_Information.Match_Amount'];
        $this->mappedRow['Contribution']['total_amount'] = ContributionHelper::getConvertedTotalAmount($this->mappedRow['Contribution']);
        $individualContact = $this->mappedRow['Contact'];
        $this->mappedRow['Contact'] = $this->getFirstSoftCreditContact();

        if ($isIndividualAnonymous) {
          unset($this->mappedRow['SoftCreditContact']);
        }
        else {
          // The individual contact is now the soft credit contact.
          $this->mappedRow['SoftCreditContact'][array_key_first($this->mappedRow['SoftCreditContact'])] = $individualContact;
          $this->mappedRow['SoftCreditContact'][array_key_first($this->mappedRow['SoftCreditContact'])]['soft_credit_type_id'] = ContributionSoftHelper::getEmploymentSoftCreditTypes()['matched_gift'];
        }
        $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'] .= '_MATCHED';
      }
      // Unset fields only mapped for this hook to perform the above calculations.
      unset($this->mappedRow['Contribution']['Matching_Gift_Information.Match_Amount']);
      unset($this->mappedRow['Contribution']['contribution_extra.scheme_fee']);
    }
  }

  private function benevityHasIndividualDonation(): bool {
    return $this->isBenevity() && !empty($this->mappedRow['Contribution']['contribution_extra.original_amount']);
  }

  private function benevityHasOrganizationDonation(): bool {
    return $this->isBenevity() && !empty($this->mappedRow['Contribution']['Matching_Gift_Information.Match_Amount']);
  }

  /**
   * Is the main contact anonymous?
   *
   * We treat the as anonymous if there is neither email or sufficient name information.
   *
   * @return bool
   */
  private function isMainContactAnonymous(): bool {
    if (!empty($this->mappedRow['Contact']['id'])) {
      return (int) $this->mappedRow['Contact']['id'] === Contact::getAnonymousContactID();
    }
    if (!empty($this->mappedRow['Contact']['email_primary.email'])) {
      return FALSE;
    }
    if ($this->mappedRow['Contact']['contact_type'] === 'Individual') {
      $names = ['first_name', 'last_name'];
    }
    else {
      $names = ['organization_name'];
    }
    foreach ($names as $name) {
      // If we don't have email we must have both first & last name or for organizations organization_name.
      if (!empty($this->mappedRow['Contact'][$name]) && $this->mappedRow['Contact'][$name] !== 'Anonymous') {
        return FALSE;
      }
    }
    return TRUE;
  }

}
