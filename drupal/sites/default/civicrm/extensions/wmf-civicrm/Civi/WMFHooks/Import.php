<?php
// Class to hold wmf functionality that alters data.

namespace Civi\WMFHooks;

use Civi\WMFHelpers\Contact;

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
      // For now we only have one gateway...
      $mappedRow['Contribution']['contribution_extra.gateway'] = 'Matching Gifts';
    }
  }

  /**
 * @param array $mappedRow
 *
 * @return array
 * @throws \CRM_Core_Exception
 */
  private static function resolveOrganization(array &$organizationContact): string {
    $organizationName = Contact::resolveOrganizationName($organizationContact['organization_name']);
    $organizationContact['id'] = Contact::getOrganizationID($organizationContact['organization_name']);
    // We don't want to over-write the organization_name as we might have matched on nick name.
    unset($organizationContact['organization_name']);
    return $organizationName;
  }

}
