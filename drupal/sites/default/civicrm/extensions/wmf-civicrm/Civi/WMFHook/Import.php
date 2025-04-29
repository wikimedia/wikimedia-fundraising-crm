<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHook;

use Civi\Api4\GroupContact;
use Civi\Api4\UserJob;
use Civi\WMFHelper\Contact;
use Civi\WMFHelper\Contribution as ContributionHelper;
use CRM_Contribute_BAO_Contribution;
use Civi\WMFHelper\ContributionSoft as ContributionSoftHelper;

class Import {

  /**
   * Alter the import mapped row for WMF specific handling.
   *
   * 1) If organization_name is set we prioritise any contact with a
   *   matching nick name (as long as there is only 1).
   * 2) If an individual is being soft credited, or has a contribution
   *   being soft credited to an organization, then
   *   we look up that individual using our custom logic which
   *   prioritises contacts with a relationship or soft credit
   *   with the organization.
   * 3) For Fidelity imports we
   *  - a) add an additional soft credit of the type 'Banking Institution' to the Fidelity organization
   *  - b) use 'Anonymous Fidelity Donor Advised Fund' for the organization if it is Anonymous
   *  - c) copy the street address from the main (organization/DAF donor) to the soft credited individual.
   * 4) wmf_contribution.extra.no_thankyou is set if not present - this prevents an email going out if
   *   they are already thanked.
   *
   * Note there is also custom code in the ContributionSoft pre hook
   * to create the relationship for contacts with an employee-ish soft
   * credit type (ie workplace or matching_gifts) and to create the DAF relationship
   * for donor advised fund soft credits.
   *
   * @see https://wikitech.wikimedia.org/w/index.php?title=Fundraising/Internal-facing/CiviCRM/Imports
   *
   * @param \Civi\Core\Event\GenericHookEvent $event
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public static function alterMappedRow($event): void {
    $importType = $event->importType;
    $context = $event->context;
    $mappedRow = $event->mappedRow;
    $rowValues = $event->rowValues;
    $userJobID = $event->userJobID;
    if ($context === 'validate' && empty($mappedRow['Contribution']['total_amount']) &&
      !empty($mappedRow['Contribution']['contribution_extra.original_currency'])
      && !empty($mappedRow['Contribution']['contribution_extra.original_amount'])
    ) {
      // This is strictly in validate mode so the value doesn't matter (although I
      // deliberately made it insanely large so it gets noticed if it IS used).
      // What matters is whether it is empty, not the value. As with the validateForm hook hack
      // I am hoping this is temporary - ref https://lab.civicrm.org/dev/core/-/issues/5456
      $mappedRow['Contribution']['total_amount'] = 99999999;
    }
    if ($context === 'import' && $importType === 'contribution_import') {
      // Provide a default, allowing the import to be configured to override.
      $isMatchingGift = in_array(self::getSoftCreditTypeIDForRow($mappedRow), ContributionSoftHelper::getEmploymentSoftCreditTypes(), TRUE);

      if ($isMatchingGift && !array_key_exists('contribution_extra.no_thank_you', $mappedRow['Contribution'])) {
        $mappedRow['Contribution']['contribution_extra.no_thank_you'] = 'Sent by portal (matching gift/ workplace giving)';
      }
      // Assume matching gifts if there is a soft credit involved...
      if (empty($mappedRow['Contribution']['contribution_extra.gateway'])) {
        $mappedRow['Contribution']['contribution_extra.gateway'] = $isMatchingGift ? 'Matching Gifts' : 'CiviCRM Import';
      }

      // If we have only a contact ID we can use that to determine the contact type
      // and to ensure we have any organization name loaded.
      if (
        !empty($mappedRow['Contribution']['contact_id'])
        && (
          empty($mappedRow['Contact'])
          || ($mappedRow['Contact']['contact_type'] === 'Organization' && empty($mappedRow['Contact']['organization_name']))
        )
      ) {
        // We have just an ID, so it is in ['Contribution']['contact_id']
        // Since we have handling further down let's populate the contact.
        $mappedRow['Contact']['id'] = (int) $mappedRow['Contribution']['contact_id'];
        $organizationName = Contact::getOrganizationName($mappedRow['Contact']['id']);
        if ($organizationName) {
          $mappedRow['Contact']['organization_name'] = $organizationName;
        }
        // If it didn't return a name we can assume it is an individual.
        $mappedRow['Contact']['contact_type'] = $organizationName ? 'Organization' : 'Individual';
      }

      if (empty($mappedRow['Contribution']['id'])) {
        if (empty($mappedRow['Contribution']['contribution_extra.gateway_txn_id'])) {
          // Generate a transaction ID so that we don't import the same rows multiple times
          $mappedRow['Contribution']['contribution_extra.gateway_txn_id'] = ContributionHelper::generateTransactionReference($mappedRow['Contact'], $mappedRow['Contribution']['receive_date'] ?? date('Y-m-d'), $mappedRow['Contribution']['check_number'] ?? NULL, $rowValues[array_key_last($rowValues)]);
        }

        if (empty($mappedRow['Contribution']['contribution_extra.gateway'])) {
          $mappedRow['Contribution']['contribution_extra.gateway'] = self::getGateway($userJobID);
        }
        $existingContributionID = ContributionHelper::exists($mappedRow['Contribution']['contribution_extra.gateway'], $mappedRow['Contribution']['contribution_extra.gateway_txn_id']);
        if ($existingContributionID) {
          throw new \CRM_Core_Exception('This contribution appears to be a duplicate of contribution id ' . $existingContributionID);
        }
        if ($mappedRow['Contribution']['contribution_extra.gateway'] === 'fidelity') {
          // For Fidelity we add a secondary contribution to Fidelity.
          // We also ensure any anonymous org ones are set to 'Anonymous Fidelity Donor Advised Fund')
          if (($mappedRow['Contact']['organization_name'] ?? '') === 'Anonymous') {
            $mappedRow['Contact']['organization_name'] = 'Anonymous Fidelity Donor Advised Fund';
          }
          foreach ($mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
            $isAnonymous = empty($softCreditContact['Contact']['first_name']) && empty($softCreditContact['Contact']['last_name']);
            if ($isAnonymous) {
              unset($mappedRow['SoftCreditContact'][$index]);
              continue;
            }
            if (!empty($softCreditContact['address_primary.street_address'])) {
              // If the street address is set let's assume it is deliberate and nothing else should copy over.
              continue;
            }
            // Otherwise we copy the address fields from the Main contact to the soft credit contact.
            // We do this because Fidelity only provides one address column, which applies to both.
            foreach ($mappedRow['Contact'] as $field => $value) {
              if ($value && str_starts_with($field, 'address_primary.') && empty($softCreditContact['Contact'][$field])) {
                $mappedRow['SoftCreditContact'][$index]['Contact'][$field] = $value;
              }
            }
          }

          $mappedRow['SoftCreditContact']['Fidelity'] = [
            'soft_credit_type_id' => ContributionSoftHelper::getBankingInstitutionSoftCreditTypes()['Banking Institution'],
            'total_amount' => $mappedRow['Contribution']['total_amount'],
            'Contact' => [
              'contact_type' => 'Organization',
              'id' => Contact::getOrganizationID('Fidelity Charitable Gift Fund'),
            ],
          ];
        }
      }

      $isRequireOrganizationResolution = $mappedRow['Contribution']['contribution_extra.gateway'] !== 'fidelity';

      if (!empty($mappedRow['SoftCreditContact'])) {
        if (($mappedRow['Contact']['contact_type'] ?? NULL) === 'Organization') {
          // If we can identify the organization here then we can try to improve on the dedupe
          // contact look up for the related individual by looking for contacts
          // with a relationship or prior soft credit.
          try {
            $organizationName = self::resolveOrganization($mappedRow['Contact']);
            $mappedRow['Contribution']['contact_id'] = $mappedRow['Contact']['id'];
            foreach ($mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
              if (empty($mappedRow['SoftCreditContact'][$index]['Contact']['id'])) {
                $mappedRow['SoftCreditContact'][$index]['Contact']['id'] = Contact::getIndividualID(
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
        elseif ($mappedRow['Contact']['contact_type'] === 'Individual') {
          $organizationName = $organizationID = NULL;
          foreach ($mappedRow['SoftCreditContact'] as $index => $softCreditContact) {
            if ($softCreditContact['Contact']['contact_type'] === 'Organization') {
              if (!empty($softCreditContact['Contact']['id'])) {
                $organizationID = $softCreditContact['Contact']['id'];
              }
              else {
                $organizationName = self::resolveOrganization($mappedRow['SoftCreditContact'][$index]['Contact']);
              }
            }
          }
          $mappedRow['Contact']['id'] = $mappedRow['Contribution']['contact_id'] ?? FALSE;
          if (!$mappedRow['Contact']['id']) {
            $mappedRow['Contact']['id'] = $mappedRow['Contribution']['contact_id'] = Contact::getIndividualID(
              $mappedRow['Contact']['email_primary.email'] ?? NULL,
              $mappedRow['Contact']['first_name'] ?? NULL,
              $mappedRow['Contact']['last_name'] ?? NULL,
              $organizationName,
              $organizationID
            );
          }
        }
      }

      self::setTimeOfDayIfStockDonation($mappedRow);
    }
    if ($mappedRow !== $event->mappedRow) {
      $event->mappedRow = $mappedRow;
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
   * @param int $userJobID
   *
   * @throws \CRM_Core_Exception
   */
  protected static function getGateway(int $userJobID): string {
    return self::getUserJob($userJobID)['gateway'];
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
   * @param array $mappedRow
   * @return void
   */
  private static function setTimeOfDayIfStockDonation(array &$mappedRow): void {
    static $stockType;
    if (!$stockType) {
      $stockType = array_search('Stock', CRM_Contribute_BAO_Contribution::buildOptions('financial_type_id', 'get'));
    }
    if (
      $mappedRow['Contribution']['financial_type_id'] == $stockType &&
      !empty($mappedRow['Contribution']['receive_date'])
    ) {
      $date = new \DateTime($mappedRow['Contribution']['receive_date']);
      $date->setTime(12, 0);
      $mappedRow['Contribution']['receive_date'] = date_format($date, 'YmdHis');
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
        'source' => 'Import duplicate contact'
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
   * @param int $userJobID
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getUserJob(int $userJobID): array {
    if (!isset(\Civi::$statics[__CLASS__]['user_job'])) {
      $userJob = UserJob::get(FALSE)->addWhere('id', '=', $userJobID)->execute()->first();
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

}
