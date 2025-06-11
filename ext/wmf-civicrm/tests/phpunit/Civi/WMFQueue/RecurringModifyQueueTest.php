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
      ->addWhere('activity_type_id', '=', $this->getActivityTypeID('decline'))
      ->execute()
      ->last();
    $this->assertEquals('Decline recurring upgrade', $activity['subject']);
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
  protected function getActivityTypeID(string $type): int {
    switch ($type) {
      case 'accept':
        return RecurringModifyQueueConsumer::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID;

      case 'decline':
        return RecurringModifyQueueConsumer::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID;

      case 'downgrade':
        return RecurringModifyQueueConsumer::RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID;

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
