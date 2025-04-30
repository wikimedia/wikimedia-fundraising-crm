<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHook;

use Civi\Api4\GroupContact;
use Civi\Api4\UserJob;
use Civi\Core\Event\GenericHookEvent;
use Civi\WMFHelper\Contact;
use Civi\WMFHelper\Contribution as ContributionHelper;
use CRM_Contribute_BAO_Contribution;
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
    $this->inValidateModeDoNotRequireTotalAmount();
    // Tweaks to apply during validate and import.
    $this->filterBadBenevityData();
    $this->applyFieldTransformations();

    if ($this->context === 'import' && $this->importType === 'contribution_import') {
      // Provide a default, allowing the import to be configured to override.
      $isMatchingGift = $this->isMatchingGift();

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
          $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'] = ContributionHelper::generateTransactionReference($this->mappedRow['Contact'], $this->mappedRow['Contribution']['receive_date'] ?? date('Y-m-d'), $this->mappedRow['Contribution']['check_number'] ?? NULL, $this->rowValues[array_key_last($this->rowValues)]);
        }
        if (empty($this->mappedRow['Contribution']['trxn_id'])) {
          $this->mappedRow['Contribution']['trxn_id'] = $this->getGateway() . ' ' . $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id'];
        }

        $this->mappedRow['Contribution']['contribution_extra.gateway'] = $this->getGateway();
        $existingContributionID = ContributionHelper::exists($this->mappedRow['Contribution']['contribution_extra.gateway'], $this->mappedRow['Contribution']['contribution_extra.gateway_txn_id']);
        if ($existingContributionID) {
          throw new \CRM_Core_Exception('This contribution appears to be a duplicate of contribution id ' . $existingContributionID);
        }

        if ($this->isFidelity()) {
          // For Fidelity we add a secondary contribution to Fidelity.
          // We also ensure any anonymous org ones are set to 'Anonymous Fidelity Donor Advised Fund')
          if (($this->mappedRow['Contact']['organization_name'] ?? '') === 'Anonymous') {
            $this->mappedRow['Contact']['organization_name'] = 'Anonymous Fidelity Donor Advised Fund';
          }
          foreach ($this->mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
            $isAnonymous = empty($softCreditContact['Contact']['first_name']) && empty($softCreditContact['Contact']['last_name']);
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
              if ($value && str_starts_with($field, 'address_primary.') && empty($softCreditContact['Contact'][$field])) {
                $this->mappedRow['SoftCreditContact'][$index]['Contact'][$field] = $value;
              }
            }
          }

          $this->mappedRow['SoftCreditContact']['Fidelity'] = [
            'soft_credit_type_id' => ContributionSoftHelper::getBankingInstitutionSoftCreditTypes()['Banking Institution'],
            'total_amount' => $this->mappedRow['Contribution']['total_amount'],
            'Contact' => [
              'contact_type' => 'Organization',
              'id' => Contact::getOrganizationID('Fidelity Charitable Gift Fund'),
            ],
          ];
        }
      }

      $isRequireOrganizationResolution = $this->mappedRow['Contribution']['contribution_extra.gateway'] !== 'fidelity';

      if (!empty($this->mappedRow['SoftCreditContact'])) {
        if (($this->mappedRow['Contact']['contact_type'] ?? NULL) === 'Organization') {
          // If we can identify the organization here then we can try to improve on the dedupe
          // contact look up for the related individual by looking for contacts
          // with a relationship or prior soft credit.
          try {
            $organizationName = self::resolveOrganization($this->mappedRow['Contact']);
            $this->mappedRow['Contribution']['contact_id'] = $this->mappedRow['Contact']['id'];
            foreach ($this->mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
              if (empty($this->mappedRow['SoftCreditContact'][$index]['Contact']['id'])) {
                $this->mappedRow['SoftCreditContact'][$index]['Contact']['id'] = Contact::getIndividualID(
                  $softCreditContact['Contact']['email_primary.email'] ?? NULL,
                  $softCreditContact['Contact']['first_name'] ?? NULL,
                  $softCreditContact['Contact']['last_name'] ?? NULL,
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
            if ($softCreditContact['Contact']['contact_type'] === 'Organization') {
              if (!empty($softCreditContact['Contact']['id'])) {
                $organizationID = $softCreditContact['Contact']['id'];
              }
              else {
                $organizationName = self::resolveOrganization($this->mappedRow['SoftCreditContact'][$index]['Contact']);
              }
            }
          }
          $this->mappedRow['Contact']['id'] = $this->mappedRow['Contribution']['contact_id'] ?? FALSE;
          if (!$this->mappedRow['Contact']['id']) {
            $this->mappedRow['Contact']['id'] = $this->mappedRow['Contribution']['contact_id'] = Contact::getIndividualID(
              $this->mappedRow['Contact']['email_primary.email'] ?? NULL,
              $this->mappedRow['Contact']['first_name'] ?? NULL,
              $this->mappedRow['Contact']['last_name'] ?? NULL,
              $organizationName,
              $organizationID
            );
          }
        }
      }

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
      if (str_starts_with($mappingName, 'contact.')) {
        $mappingName = substr($mappingName, 8);
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
      !empty($this->mappedRow['Contribution']['contribution_extra.original_currency'])
      && !empty($this->mappedRow['Contribution']['contribution_extra.original_amount'])
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

}
