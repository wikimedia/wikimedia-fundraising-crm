<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Address;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Email;
use Civi\Test\Api3TestTrait;
use Civi\Test\ContactTestTrait;

/**
 * @group queues
 * @group Recurring
 */
class RecurringModifyQueueTest extends BaseQueueTestCase {

  use ContactTestTrait;
  use Api3TestTrait;

  protected string $queueName = 'recurring-modify';

  protected string $queueConsumer = 'RecurringModify';

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
      ->addWhere('activity_type_id:name', '=', $this->getActivityTypeID('decline'))
      ->execute()
      ->last();
    $this->assertEquals('Decline recurring upgrade', $activity['subject']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPause(): void {
    $testRecurring = $this->createContributionRecur(
      [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2025-07-06 14:16:40'
      ]
    );
    $date = $testRecurring['next_sched_contribution_date'];
    $msg = [
      'txn_type' => 'recurring_paused',
      'contribution_recur_id' => $testRecurring['id'],
      'duration' => '60 days',
      'source_type' => 'emailpreferences',
      'campaign' => 'Blast1',
    ];

    $this->processMessage($msg);
    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'next_sched_contribution_date')
      ->addWhere('id', '=', $testRecurring['id'])
      ->execute()
      ->first();
    $activity = Activity::get(FALSE)
      ->addSelect('subject')
      ->addSelect('activity_tracking.*')
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id:name', '=', $this->getActivityTypeID('paused'))
      ->execute()
      ->last();

    $new_date = date_add(date_create($date), date_interval_create_from_date_string($msg['duration']));
    $formatDate = date_format($new_date, 'Y-m-d H:i:s');

    $this->assertEquals($formatDate, $updatedRecurring['next_sched_contribution_date']);
    $this->assertEquals("Paused recurring till {$formatDate}", $activity['subject']);
    $this->assertEquals(TRUE, $activity['activity_tracking.activity_is_from_donor_portal']);
    $this->assertEquals('Blast1', $activity['activity_tracking.activity_campaign']);
  }

  public function testRecurringCancel(): void {
    $testRecurring = $this->createContributionRecur(
      [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => '2025-07-06 14:16:40'
      ]
    );
    $msg = [
      'txn_type' => 'recurring_cancel',
      'contribution_recur_id' => $testRecurring['id'],
      'cancel_reason' => 'Financial reason',
      'cancel_date' => date('Y-m-d H:i:s'),
      'source_type' => 'emailpreferences',
      'medium' => 'email',
    ];

    $this->processMessage($msg);
    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'cancel_date', 'end_date')
      ->addWhere('id', '=', $testRecurring['id'])
      ->execute()
      ->first();
    $activity = Activity::get(FALSE)
      ->addSelect('activity_tracking.*')
      ->addSelect('subject')
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id:name', '=', $this->getActivityTypeID('cancelled'))
      ->execute()
      ->last();

    $this->assertEquals($msg['cancel_date'], $updatedRecurring['cancel_date']);
    $this->assertEquals($msg['cancel_date'], $updatedRecurring['end_date']);
    $this->assertEquals('Cancelled', $updatedRecurring['contribution_status_id:name']);
    $this->assertEquals("Donor cancelled recurring through the Donor Portal on {$msg['cancel_date']}", $activity['subject']);
    $this->assertEquals(TRUE, $activity['activity_tracking.activity_is_from_donor_portal']);
    $this->assertEquals('email', $activity['activity_tracking.activity_medium']);
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
      'is_from_save_flow' => FALSE,
      'source_type' => 'emailpreferences',
      'source' => 'direct'
    ];
    $amountDetails = [
      'native_currency' => $msg['currency'],
      'native_original_amount' => '10.00',
      'usd_original_amount' => '10.00',
      'native_amount_added' => '5.00',
      'usd_amount_added' => '5.00',
      'is_from_save_flow' => FALSE,
    ];

    $this->processMessage($msg);
    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount')
      ->addWhere('id', '=', $testRecurring['id'])
      ->execute()
      ->first();
    $activity = Activity::get(FALSE)
      ->addSelect('activity_tracking.*')
      ->addSelect('subject')
      ->addSelect('details')
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id:name', '=', $this->getActivityTypeID('accept'))
      ->execute()
      ->last();

    $this->assertEquals($testRecurring['amount'] + $additionalAmount, $updatedRecurring['amount']);
    $this->assertEquals('Added 5.00 USD', $activity['subject']);
    $this->assertEquals(json_encode($amountDetails), $activity['details']);
    $this->assertEquals(TRUE, $activity['activity_tracking.activity_is_from_donor_portal']);
    $this->assertEquals('direct', $activity['activity_tracking.activity_source']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringExternalModification(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => 'old_mouse@wikimedia.org',
      'street_address' => 'Yellow Brick Road',
      'country_id:name' => 'US',
    ]);
    $testRecurring = $this->createContributionRecur([
      'contact_id' => $contactID,
    ]);
    $additionalAmount = 5.00;
    $msg = [
      'txn_type' => 'external_recurring_modification',
      'contribution_recur_id' => $testRecurring['id'],
      'amount' => $testRecurring['amount'] + $additionalAmount,
      'currency' => $testRecurring['currency'],
      'email' => 'mouse@wikimedia.org',
      'country' => 'US',
      'street_address' => 'Sesame Street',
      'city' => 'PleasantVille',
      'postal_code' => 90210,
      'first_name' => 'Safe',
      'last_name' => 'Mouse',
      'gateway' => 'fundraiseup',
    ];

    $this->processMessage($msg);
    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount', 'contact_id')
      ->addWhere('id', '=', $testRecurring['id'])
      ->execute()
      ->first();

    $this->assertEquals($testRecurring['amount'] + $additionalAmount, $updatedRecurring['amount']);

    $email = Email::get(FALSE)->addSelect('email')
      ->addWhere('contact_id', '=', $updatedRecurring['contact_id'])->execute()->first()['email'];
    $this->assertEquals('mouse@wikimedia.org', $email);

    $address = Address::get(FALSE)->addSelect('street_address', 'country_id.iso_code')
      ->addWhere('contact_id', '=', $updatedRecurring['contact_id'])->execute()->first();
    $this->assertEquals('Sesame Street', $address['street_address']);
    $this->assertEquals('US', $address['country_id.iso_code']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function getActivityTypeID(string $type): string {
    switch ($type) {
      case 'accept':
        return RecurringModifyQueueConsumer::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_NAME;

      case 'decline':
        return RecurringModifyQueueConsumer::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_NAME;

      case 'downgrade':
        return RecurringModifyQueueConsumer::RECURRING_DOWNGRADE_ACTIVITY_TYPE_NAME;

      case 'paused':
        return RecurringModifyQueueConsumer::RECURRING_PAUSED_ACTIVITY_TYPE_NAME;

      case 'cancelled':
        return RecurringModifyQueueConsumer::RECURRING_CANCELLED_ACTIVITY_TYPE_NAME;

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
      'is_from_save_flow' => TRUE,
      'source_type' => 'emailpreferences',
    ];

    $amountDetails = [
      'native_currency' => 'USD',
      'native_original_amount' => '15.00',
      'usd_original_amount' => '15.00',
      'native_amount_removed' => '10.00',
      'usd_amount_removed' => '10.00',
      'is_from_save_flow' => TRUE,
    ];

    $this->processMessage($recurringQueueMessage);

    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount')
      ->addWhere('id', '=', $testRecurringContributionFor15Dollars['id'])
      ->execute()
      ->first();

    $activity = Activity::get(FALSE)
      ->addSelect('activity_tracking.*')
      ->addSelect('subject')
      ->addSelect('details')
      ->addWhere('source_record_id', '=', $testRecurringContributionFor15Dollars['id'])
      ->addWhere('activity_type_id:name', '=', $this->getActivityTypeID('downgrade'))
      ->execute()
      ->last();

    $this->assertEquals($newRecurringDonationAmount, $updatedRecurring['amount']);

    $this->assertEquals('Recurring amount reduced by 10.00 USD', $activity['subject']);

    $this->assertEquals(json_encode($amountDetails), $activity['details']);

    $this->assertEquals(TRUE, $activity['activity_tracking.activity_is_from_donor_portal']);

    // clean up fixture data
    $this->ids['ContributionRecur'][$testRecurringContributionFor15Dollars['id']] = $testRecurringContributionFor15Dollars['id'];
    $this->ids['Activity'][$activity['id']] = $activity['id'];
  }

}
