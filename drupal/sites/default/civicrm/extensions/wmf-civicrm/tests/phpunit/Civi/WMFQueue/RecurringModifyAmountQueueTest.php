<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Test\Api3TestTrait;
use Civi\Test\ContactTestTrait;

/**
 * @group queues
 * @group Recurring
 */
class RecurringModifyAmountQueueTest extends BaseQueueTestCase {

  use ContactTestTrait;
  use Api3TestTrait;

  protected string $queueName = 'recurring-modify';

  protected string $queueConsumer = 'RecurringModifyAmount';

  /**
   * @throws \CRM_Core_Exception
   */
  public function testDeclineRecurringUpgrade(): void {
    $testRecurring = $this->createContributionRecur();
    $msg = [
      'txn_type' => 'recurring_upgrade_decline',
      'contribution_recur_id' => $testRecurring['id'],
      'contact_id' => $testRecurring['contact_id'],
    ];
    $this->processMessage($msg);
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id', '=', $this->getActivityTypeID('decline'))
      ->execute()
      ->last();
    $this->assertEquals('Decline recurring update', $activity['subject']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringUpgrade(): void {
    $testRecurring = $this->createContributionRecur();
    $additionalAmount = 5.00;
    $msg = [
      'txn_type' => 'recurring_upgrade',
      'contribution_recur_id' => $testRecurring['id'],
      'amount' => $testRecurring['amount'] + $additionalAmount,
      'currency' => $testRecurring['currency'],
    ];
    $amountDetails = [
      'native_currency' => $msg['currency'],
      'native_original_amount' => '10.00',
      'usd_original_amount' => '10.00',
      'native_amount_added' => '5.00',
      'usd_amount_added' => '5.00',
    ];

    $this->processMessage($msg);
    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount')
      ->addWhere('id', '=', $testRecurring['id'])
      ->execute()
      ->first();
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id', '=', $this->getActivityTypeID('accept'))
      ->execute()
      ->last();

    $this->assertEquals($testRecurring['amount'] + $additionalAmount, $updatedRecurring['amount']);
    $this->assertEquals('Added 5.00 USD', $activity['subject']);
    $this->assertEquals(json_encode($amountDetails), $activity['details']);

    $this->ids['ContributionRecur'][$testRecurring['id']] = $testRecurring['id'];
    $this->ids['Activity'][$activity['id']] = $activity['id'];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function getActivityTypeID(string $type): int {
    switch ($type) {
      case 'accept':
        return RecurringModifyAmountQueueConsumer::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID;

      case 'decline':
        return RecurringModifyAmountQueueConsumer::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID;

      case 'downgrade':
        return RecurringModifyAmountQueueConsumer::RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID;

      default:
        throw new \CRM_Core_Exception('invalid type');
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringDowngrade(): void {
    $testRecurringContributionFor15Dollars = $this->createContributionRecur([
      'amount' => 15.00,
    ]);

    // The recurring donation has been reduced by 10 dollars
    $newRecurringDonationAmount = 5.00;

    $recurringQueueMessage = [
      'txn_type' => 'recurring_downgrade',
      'contribution_recur_id' => $testRecurringContributionFor15Dollars['id'],
      'amount' => $newRecurringDonationAmount,
      'currency' => $testRecurringContributionFor15Dollars['currency'],
    ];

    $amountDetails = [
      'native_currency' => 'USD',
      'native_original_amount' => '15.00',
      'usd_original_amount' => '15.00',
      'native_amount_removed' => '10.00',
      'usd_amount_removed' => '10.00',
    ];

    $this->processMessage($recurringQueueMessage);

    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount')
      ->addWhere('id', '=', $testRecurringContributionFor15Dollars['id'])
      ->execute()
      ->first();

    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $testRecurringContributionFor15Dollars['id'])
      ->addWhere('activity_type_id', '=', $this->getActivityTypeID('downgrade'))
      ->execute()
      ->last();

    $this->assertEquals($newRecurringDonationAmount, $updatedRecurring['amount']);

    $this->assertEquals('Recurring amount reduced by 10.00 USD', $activity['subject']);

    $this->assertEquals(json_encode($amountDetails), $activity['details']);

    // clean up fixture data
    $this->ids['ContributionRecur'][$testRecurringContributionFor15Dollars['id']] = $testRecurringContributionFor15Dollars['id'];
    $this->ids['Activity'][$activity['id']] = $activity['id'];
  }

}
