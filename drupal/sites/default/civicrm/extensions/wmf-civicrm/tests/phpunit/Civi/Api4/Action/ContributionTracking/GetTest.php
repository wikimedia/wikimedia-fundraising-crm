<?php

namespace Civi\Api4\Action\ContributionTracking;

use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Test\EntityTrait;
use Civi\Test\TransactionalInterface;
use Civi\WMFEnvironmentTrait;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFHelper\ContributionTracking as WMFHelper;
use PHPUnit\Framework\TestCase;

/**
 * This is a generic test class for the extension (implemented with PHPUnit).
 */
class GetTest extends TestCase {
  use EntityTrait;
  use WMFEnvironmentTrait;

  /**
   * Test use of API4 in Contribution Tracking in recurring module
   *
   * @throws \CRM_Core_Exception
   */
  public function testApiRecurringGetJoin(): void {
    $contact = $this->createTestEntity('Contact', ['contact_type' => 'Individual', 'first_name' => 'Danger', 'last_name' => 'Mouse'], 'danger');
    $recur = $this->createTestEntity('ContributionRecur', [
      'amount' => 10,
      'frequency_interval' => 'month',
      'cycle_day' => date('d'),
      'start_date' => 'now',
      'is_active' => TRUE,
      'contribution_status_id:name' => 'Pending',
      'trxn_id' => 1234,
      'contact_id' => $contact['id'],
    ]);
    $contribution = $this->createTestEntity('Contribution', [
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
      'total_amount' => 60,
      'receive_date' => 'now',
      'contribution_recur_id' => $recur['id'],
      'contact_id' => $contact['id'],
      'trxn_id' => $recur['trxn_id'],
    ]);
    $createTestCT = ContributionTracking::save(FALSE)->addRecord(WMFHelper::getContributionTrackingParameters([
      'utm_source' => '..rpp',
      'utm_medium' => 'civicrm',
      'ts' => '',
      'contribution_id' => $contribution['id'],
      'id' => 12345678,
    ]))->execute()->first();

    $ctFromResponse = ContributionRecur::get(FALSE)
      ->addSelect('MIN(contribution_tracking.id) AS contribution_tracking_id', 'MIN(contribution.id) AS contribution_id')
      ->addJoin('Contribution AS contribution', 'INNER')
      ->addJoin('ContributionTracking AS contribution_tracking', 'LEFT', ['contribution_tracking.contribution_id', '=', 'contribution.id'])
      ->addGroupBy('id')
      ->addWhere('trxn_id', '=', $recur['trxn_id'])
      ->setLimit(1)
      ->execute()
      ->first()['contribution_tracking_id'];

    $this->assertEquals($createTestCT['id'], $ctFromResponse);
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];
    $this->ids['ContributionRecur'][$contribution['contribution_recur_id']] = $contribution['contribution_recur_id'];
    $this->ids['ContributionTracking'][$ctFromResponse] = $ctFromResponse;
  }

}
