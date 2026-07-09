<?php
namespace Civi\Api4\Action\DAFGift;

use Civi\Api4\ContributionSoft;
use Civi\Api4\Generic\Result;
use Civi\WMFException\DuplicateContactException;
use Civi\WMFHelper\Contact;

/**
 *
 */
class Save extends \Civi\Api4\Action\OfflineGift\Save {
  protected $_entityName = 'DAFGift';
  /**
   * @throws \CRM_Core_Exception
   */
  public function _run( Result $result ): void {
    foreach ($this->records as $record) {
      $record += $this->defaults;
      $this->formatWriteValues($record);
      // In some cases our DAF-donors do not disclose the fund_name to us.
      // In this scenario we have to record the gift against the individual.
      $isRecordDaf = !empty($record['donor_advised_fund_name']);
      $dafOrganizationID = NULL;
      if ($isRecordDaf) {
        $dafOrganizationID = $this->getOrCreateDAFOrganization($record);
      }
      $individualID = $this->getOrCreateIndividual($record, $dafOrganizationID, $record['donor_advised_fund_name'] ?? '');
      $contribution = $this->saveContribution($record, [
        // gift_source is something Melanie can manipulate by setting rules within Intacct.
        // She is able to set the Gift Type property based on rules around the text of the donation.
        'Gift_Data.Campaign' => ($record['gift_source'] ?? '') === 'Employee Giving' ? 'Employee Giving' : 'Donor Advised Fund',
      ], $dafOrganizationID ?? $individualID, 1);
      $result[] = $contribution;

      if ($isRecordDaf && $individualID !== Contact::getAnonymousContactID()) {
        ContributionSoft::create($this->checkPermissions)
          ->setValues([
            'contribution_id' => $contribution['id'],
            'soft_credit_type_id:name' => 'donor-advised_fund',
            'contact_id' => $individualID,
            'amount' => $record['original_individual_gift_total_amount'],
          ])
          ->execute();
      }

      $this->createBankingInstitutionSoftCredit($record, $contribution['id']);
    }
  }

  /**
   * @param array $record
   *
   * @return int|null
   * @throws \CRM_Core_Exception
   */
  public function getOrCreateDAFOrganization(array $record): ?int {
    $duplicateContacts = [];
    $organizationID = NULL;
    try {
      $organizationID = $this->getDAFOrganizationID($record);
    }
    catch (DuplicateContactException $e) {
      $duplicateContacts = $e->getDuplicateContacts();
    }
    if (!$organizationID) {
      $organizationID = $this->createOrganization($record, $record['donor_advised_fund_name']);
    }
    if ($duplicateContacts) {
      $this->organizationDuplicateAlert($organizationID, $duplicateContacts, $record['donor_advised_fund_name']);
    }
    return $organizationID;
  }

  protected function getOrganizationLocationValues(array $record): array {
    return $this->getLocationValues($record);
  }

}
