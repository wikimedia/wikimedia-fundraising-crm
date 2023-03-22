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
   *
   * @param string $importType
   * @param string $context
   * @param array $mappedRow
   * @param array $rowValues
   * @param int $userJobID
   *
   * @throws \CRM_Core_Exception
   */
  public static function alterMappedRow(string $importType, string $context, array &$mappedRow, array $rowValues, int $userJobID): void {
    if ($context === 'import' && $importType === 'contribution_import' && !empty($mappedRow['Contact']['organization_name'])) {
      $mappedRow['Contact']['organization_name'] = Contact::resolveOrganizationName($mappedRow['Contact']['organization_name']);
      $originalCurrency = $mappedRow['Contribution']['currency'] ?? 'USD';
      // @todo handle conversion here but for now we are only dealing with US donations.
      // Question - should we map the custom currency field or the currency field?
      // Also, can we move this to the Contribution pre hook.
      $mappedRow['Contribution']['source'] = $originalCurrency . ' ' . \Civi::format()->machineMoney($mappedRow['Contribution']['total_amount']);
      // For now we only have one gateway...
      $mappedRow['Contribution']['contribution_extra.gateway'] = 'Matching Gifts';
    }
  }

}
