<?php
namespace Civi\Api4\Action\MatchingGift;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionSoft;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\WMFException\DuplicateContactException;
use Civi\WMFHelper\Contact;
use Civi\WMFHelper\ContributionSoft as ContributionSoftHelper;
use SmashPig\Core\Helpers\CurrencyRoundingHelper;

/**
 *
 */
class Save extends \Civi\Api4\Action\OfflineGift\Save {

  protected $_entityName = 'MatchingGift';

  /**
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function _run( Result $result ): void {
    foreach ($this->records as $record) {
      $record += $this->defaults;
      $this->formatWriteValues($record);

      $matchingGiftOrganizationID = $this->getOrCreateMatchingGiftOrganization($record);
      $individualID = $this->getOrCreateIndividual($record, $matchingGiftOrganizationID, $record['matching_gift_organization'] ?? NULL);
      if (!empty($record['original_individual_gift_total_amount'])) {
        $individualGiftRatio = $record['original_individual_gift_total_amount'] / $record['original_total_amount'];
        $contributionValues = [
          'Gift_Data.Channel' => 'Workplace Giving',
          'Gift_Data.Campaign' => 'Employee Giving',
          'fee_amount' => $record['original_individual_gift_fee_amount'],
          'contribution_settlement.settled_fee_amount' => $record['original_individual_gift_fee_amount'],
          'contribution_settlement.settled_net_amount' => $record['original_individual_gift_net_amount'],
          'contribution_extra.no_thank_you' => 'Sent by portal (matching gift/ workplace giving)',
        ];
        if ($record['backend_processor'] === 'benevity') {
          $contributionValues['Gift_Data.Appeal'] = 'Benevity';
        }
        try {
          $contribution = $this->saveContribution($record, $contributionValues, $individualID, $individualGiftRatio);
        }
        catch (\CRM_Core_Exception $e) {
          // Try fetching - maybe it got created already OK - if so we can continue.
          $contribution = Contribution::get(FALSE)
            ->addWhere('contribution_extra.backend_processor', '=', $record['backend_processor'])
            ->addWhere('contribution_extra.backend_processor_txn_id' , '=', $record['backend_processor_txn_id'])
            ->execute()->first();
          if (!$contribution) {
            throw $e;
          }
        }
        $result[] = $contribution;

        if ($matchingGiftOrganizationID) {
          ContributionSoft::create($this->checkPermissions)
            ->setValues([
              'contribution_id' => $contribution['id'],
              'soft_credit_type_id:name' => 'workplace',
              'contact_id' => $matchingGiftOrganizationID,
              'amount' => $this->getProportionalGiftAmountInReportingCurrency($record['settled_total_amount'], $individualGiftRatio),
            ])
            ->execute();
        }
      }
      if (!empty($record['original_matching_gift_total_amount'])) {
        if (!$matchingGiftOrganizationID) {
          $matchingGiftOrganizationID = \Civi\Api4\Contact::create(FALSE)
            ->setValues([
              'contact_type' => 'Organization',
              'organization_name' => $record['matching_gift_organization'],
            ])->execute()->single()['id'];
        }
        $matchingGiftRatio = $record['original_matching_gift_total_amount'] / $record['original_total_amount'];
        $record['gateway_txn_id'] .= '_MATCHED';
        $record['backend_processor_txn_id'] .= '_MATCHED';
        $contribution = $this->saveContribution($record, [
          'Gift_Data.Channel' => 'Workplace Giving',
          'Gift_Data.Campaign' => 'Matching Gift',
          'contribution_extra.no_thank_you' => 'Sent by portal (matching gift/ workplace giving)',
          'fee_amount' => $record['settled_matching_gift_fee_amount'],
          'contribution_settlement.settled_fee_amount' => $record['settled_matching_gift_fee_amount'],
          'contribution_settlement.settled_net_amount' => $record['settled_matching_gift_net_amount'],

        ], $matchingGiftOrganizationID, $matchingGiftRatio);

        if ($individualID) {
          ContributionSoft::create($this->checkPermissions)
            ->setValues([
              'contribution_id' => $contribution['id'],
              'soft_credit_type_id:name' => 'matched_gift',
              'contact_id' => $individualID,
              'amount' => $this->getProportionalGiftAmountInReportingCurrency($record['settled_total_amount'], $matchingGiftRatio),
            ])
            ->execute();
        }
        $result[] = $contribution;
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
  public function getOrCreateMatchingGiftOrganization(array $record): ?int {
    $duplicateContacts = [];
    $organizationID = NULL;
    try {
      $organizationID = $this->getMatchingGiftOrganizationID($record);
    }
    catch (DuplicateContactException $e) {
      $duplicateContacts = $e->getDuplicateContacts();
    }
    if (!$organizationID) {
      $organizationID = $this->createOrganization($record, $record['matching_gift_organization']);
    }
    if ($duplicateContacts) {
      $this->organizationDuplicateAlert($organizationID, $duplicateContacts, $record['matching_gift_organization']);
    }
    return $organizationID;
  }

}
