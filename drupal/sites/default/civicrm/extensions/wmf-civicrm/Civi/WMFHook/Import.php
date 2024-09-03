<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHook;

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
   *
   * Note there is also custom code in the ContributionSoft pre hook
   * to create the relationship for contacts with an employee-ish soft
   * credit type (ie workplace or matching_gifts).
   *
   * @param string $importType
   * @param string $context
   * @param array $mappedRow
   * @param array $rowValues
   * @param int $userJobID
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  public static function alterMappedRow(string $importType, string $context, array &$mappedRow, array $rowValues, int $userJobID): void {
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

      if (!empty($mappedRow['SoftCreditContact'])) {
        if (($mappedRow['Contact']['contact_type'] ?? NULL) === 'Organization') {
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

          $mappedRow['Contact']['id'] = $mappedRow['Contribution']['contact_id'] = Contact::getIndividualID(
            $mappedRow['Contact']['email_primary.email'] ?? NULL,
            $mappedRow['Contact']['first_name'] ?? NULL,
            $mappedRow['Contact']['last_name'] ?? NULL,
            $organizationName,
            $organizationID
          );
        }
      }

      $originalCurrency = $mappedRow['Contribution']['currency'] ?? 'USD';
      // @todo handle conversion here but for now we are only dealing with US donations.
      // Question - should we map the custom currency field or the currency field?
      // Also, can we move this to the Contribution pre hook.
      $mappedRow['Contribution']['source'] = $originalCurrency . ' ' . \Civi::format()->machineMoney($mappedRow['Contribution']['total_amount']);
      self::setTimeOfDayIfStockDonation($mappedRow);
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
    return \Civi::$statics[__CLASS__]['user_job']['gateway'];
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

}
