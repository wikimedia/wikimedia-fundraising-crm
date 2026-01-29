<?php

namespace Civi\Api4\Action\GrantTransaction;

use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Get Matching Contributions for Grant transactions.
 *
 * @method $this setDaysToLookBack(int $numberOfDays)
 * @method $this setIsDryRun(bool $isDryRun)
 */
class GetMatches extends AbstractAction {

  /**
   * Number of days in the past to consider.
   */
  protected int $daysToLookBack = 5;

  /**
   * Is dry run.
   *
   * In dry run mode the api commands will be generated but not run.
   *
   * @var bool
   */
  protected bool $isDryRun = TRUE;

  /**
   * This function updates the settled transaction with new fee & currency conversion data.
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    // This join is a bit awkward via api & probably not worth the effort.
    $transactions = \CRM_Core_DAO::executeQuery(
      "SELECT t.* FROM civicrm_grant_transaction t
            LEFT JOIN wmf_contribution_extra x ON x.gateway_txn_id = t.gateway_txn_id
            AND x.gateway = 'Paypal DAF'
            WHERE x.id IS NULL
            ORDER BY t.settled_date DESC
            "
    )->fetchAll();
    $byOrganization = [];
    foreach ($transactions as $transaction) {
      // Group by Grant provider and Amount
      $byOrganization[$transaction['grant_provider']][date('Y-m-d', strtotime($transaction['settled_date']))][$transaction['settled_total_amount']][] = $transaction;
    }
   foreach ($byOrganization as $organization => $transactionsByOrganization) {
      foreach ($transactionsByOrganization as $date => $transactionsByDate) {
        foreach ($transactionsByDate as $amount => $transactionsByAmount) {
          $matches = [];
          $dateRangeEnd = "{$date} 23:59:59";
          $lookBackDays = 0;
          while ($lookBackDays < $this->daysToLookBack && empty($matches)) {
            $dateRangeStart = date("Y-m-d H:i:s", strtotime("-{$lookBackDays} day", strtotime($date)));
            $matches = $this->getMatches($dateRangeStart, $dateRangeEnd, $organization, $amount, $transactionsByAmount);
            $lookBackDays++;
          }
          foreach ($matches as $match) {
            $result[] = $match;
          }
        }
      }
    }
    foreach ($result as $pair) {
      $command = "wmf-cv api4 Contribution.update +w id={$pair['id']} ";
      foreach ($pair as $key => $value) {
        if ($key === 'id') {
          continue;
        }
        $command .= " +v $key='{$value}'";
      }
      \Civi::log('debug')->info("Link command :\n $command"
      );
      if (!$this->isDryRun) {
        Contribution::update(FALSE)
          ->addWhere('id', '=', $pair['id'])
          ->setValues($pair)->execute();
      }
    }
  }

  /**
   * @param string $dateRangeStart
   * @param string $dateRangeEnd
   * @param string $organization
   * @param string $amount
   * @param mixed $transactionsByAmount
   *
   * @return array
   * @throws \Civi\Core\Exception\DBQueryException
   */
  private function getMatches(string $dateRangeStart, string $dateRangeEnd, string $organization, string $amount, array $transactionsByAmount): array {
    $softCreditTypeID = (int) \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionSoft', 'soft_credit_type_id', 'Banking Institution');
    $matches = [];
    $existing = \CRM_Core_DAO::executeQuery(
      "SELECT gateway, gateway_txn_id, receive_date, contribution_id,
       soft.contact_id, total_amount, soft_credit_type_id
     FROM civicrm_contribution_soft soft
       INNER JOIN civicrm_contact c ON c.id = soft.contact_id
       LEFT JOIN civicrm_contribution cont ON cont.id = soft.contribution_id
     LEFT JOIN wmf_contribution_extra x ON x.entity_id = cont.id
       WHERE (organization_name = %1 OR legal_name = %1)
         AND is_deleted = 0 AND contact_type = 'Organization' AND gateway = 'Paypal DAF'
         AND cont.total_amount = %2
         AND cont.contribution_status_id = 1
         AND soft_credit_type_id = $softCreditTypeID AND (gateway_txn_id IS NULL OR gateway_txn_id = '')
         AND receive_date BETWEEN '{$dateRangeStart}' AND '{$dateRangeEnd} 23:59:59'
       ORDER BY receive_date DESC
         ",
      [1 => [$organization, 'String'], 2 => [$amount, 'Float']]
    )->fetchAll();
    if (count($existing) === count($transactionsByAmount)) {
      foreach ($transactionsByAmount as $index => $transaction) {
        $matches[] = [
          'id' => $existing[$index]['contribution_id'],
          'contribution_settlement.settlement_date' => $transaction['settled_date'],
          'contribution_extra.gateway_txn_id' => $transaction['gateway_txn_id'],
          'contribution_settlement.settlement_batch_reference' => $transaction['settlement_batch_reference'],
          'contribution_settlement.settlement_currency' => $transaction['settled_currency'],
          'contribution_settlement.settled_donation_amount' => $transaction['settled_total_amount'],
          'contribution_settlement.settled_fee_amount' => $transaction['settled_fee_amount'],
        ];
      }
    }
    return $matches;
  }

}
