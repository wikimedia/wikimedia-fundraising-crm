<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHooks;

use Civi\Api4\UserJob;
use Civi\WMFHelpers\Contact;
use Civi\WMFHelpers\Contribution as WMFContribution;

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
   * @noinspection PhpUnusedParameterInspection
   */
  public static function alterMappedRow(string $importType, string $context, array &$mappedRow, array $rowValues, int $userJobID): void {
    if ($context === 'import' && $importType === 'contribution_import') {
      // Provide a default, allowing the import to be configured to override.
      if (!array_key_exists('contribution_extra.no_thank_you', $mappedRow['Contribution'])) {
        $mappedRow['Contribution']['contribution_extra.no_thank_you'] = 'Sent by portal';
      }
      // Assume matching gifts if there is a soft credit involved...
      if (empty($mappedRow['Contribution']['contribution_extra.gateway'])) {
        $mappedRow['Contribution']['contribution_extra.gateway'] = !empty($mappedRow['SoftCreditContact']) ? 'Matching Gifts' : 'CiviCRM Import';
      }
      if (
        !empty($mappedRow['Contribution']['contact_id'])
        && (
          empty($mappedRow['Contact'])
         || ($mappedRow['Contact']['contact_type'] === 'Organization' && empty($mappedRow['Contact']['organization_name']))
        )
      ) {
        // We have just an ID so it is in ['Contribution']['contact_id']
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
        $mappedRow['Contribution']['contribution_extra.gateway_txn_id'] = WMFContribution::generateTransactionReference($mappedRow['Contact'], $mappedRow['Contribution']['receive_date'] ?? date('Y-m-d'), $mappedRow['Contribution']['check_number'] ?? NULL, $rowValues[array_key_last($rowValues)]);
      }

      if (empty($mappedRow['Contribution']['contribution_extra.gateway'])) {
        $mappedRow['Contribution']['contribution_extra.gateway'] = self::getGateway($userJobID);
      }
      $existingContributionID = WMFContribution::exists($mappedRow['Contribution']['contribution_extra.gateway'], $mappedRow['Contribution']['contribution_extra.gateway_txn_id']);
      if ($existingContributionID) {
        throw new \CRM_Core_Exception('This contribution appears to be a duplicate of contribution id ' . $existingContributionID);
      }

      $organizationName = $organizationID = NULL;

      if (($mappedRow['Contact']['contact_type'] ?? NULL) === 'Organization') {
        $organizationName = self::resolveOrganization($mappedRow['Contact']);
        $mappedRow['Contribution']['contact_id'] = $mappedRow['Contact']['id'];
        foreach ($mappedRow['SoftCreditContact'] ?? [] as $index => $softCreditContact) {
          $mappedRow['SoftCreditContact'][$index]['Contact']['id'] = Contact::getIndividualID(
            $softCreditContact['Contact']['email_primary.email'] ?? NULL,
            $softCreditContact['Contact']['first_name'] ?? NULL,
            $softCreditContact['Contact']['last_name'] ?? NULL,
            $organizationName
          );
        }
      }
      elseif ($mappedRow['Contact']['contact_type'] === 'Individual') {
        foreach ($mappedRow['SoftCreditContact'] ?? [] as $index => $softCreditContact) {
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

      $originalCurrency = $mappedRow['Contribution']['currency'] ?? 'USD';
      // @todo handle conversion here but for now we are only dealing with US donations.
      // Question - should we map the custom currency field or the currency field?
      // Also, can we move this to the Contribution pre hook.
      $mappedRow['Contribution']['source'] = $originalCurrency . ' ' . \Civi::format()->machineMoney($mappedRow['Contribution']['total_amount']);
    }
  }

  /**
 * @param array $mappedRow
 *
 * @return array
 * @throws \CRM_Core_Exception
 */
  private static function resolveOrganization(array &$organizationContact): string {
    if (empty($organizationContact['id'])) {
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
   * @throws \Civi\API\Exception\UnauthorizedException
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

}
