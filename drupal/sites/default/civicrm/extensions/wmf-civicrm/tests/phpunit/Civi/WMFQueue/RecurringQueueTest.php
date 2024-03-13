<?php

namespace Civi\WMFQueue;

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Test\Api3TestTrait;
use Civi\Test\ContactTestTrait;
use Civi\Api4\Contribution;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFHelper\ContributionTracking as WMFHelper;
use SmashPig\Core\SequenceGenerators\Factory;
use Civi\WMFException\WMFException;

/**
 * @group queues
 * @group Recurring
 */
class RecurringQueueTest extends BaseQueue {

  use ContactTestTrait;
  use Api3TestTrait;

  protected string $queueName = 'recurring';

  protected string $queueConsumer = 'Recurring';

  /**
   * @throws \CRM_Core_Exception
   */
  public function testDeclineRecurringUpgrade(): void {
    $testRecurring = $this->createContributionRecur();
    $msg = [
      'txn_type' => "recurring_upgrade_decline",
      'contribution_recur_id' => $testRecurring['id'],
      'contact_id' => $testRecurring['contact_id'],
    ];
    $this->processMessage($msg);
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id', '=', RecurringQueueConsumer::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID)
      ->execute()
      ->last();
    $this->assertEquals("Decline recurring update", $activity['subject']);
  }

  public function testNoSubscrId(): void {
    $this->expectExceptionCode(WMFException::INVALID_RECURRING);
    $this->expectException(WMFException::class);
    $message = $this->getRecurringPaymentMessage();
    $message['subscr_id'] = NULL;
    $this->processMessageWithoutQueuing($message);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringUpgrade(): void {
    $testRecurring = $this->createContributionRecur();
    $additionalAmount = 5;
    $msg = [
      'txn_type' => "recurring_upgrade",
      'contribution_recur_id' => $testRecurring['id'],
      'amount' => $testRecurring['amount'] + $additionalAmount,
      'currency' => $testRecurring['currency'],
    ];
    $amountDetails = [
      "native_currency" => $msg['currency'],
      "native_original_amount" => $testRecurring['amount'],
      "usd_original_amount" => $testRecurring['amount'],
      "native_amount_added" => $additionalAmount,
      "usd_amount_added" => $additionalAmount,
    ];

    $this->processMessage($msg);
    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount')
      ->addWhere('id', '=', $testRecurring['id'])
      ->execute()
      ->first();
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id', '=', RecurringQueueConsumer::RECURRING_UPGRADE_ACCEPT_ACTIVITY_TYPE_ID)
      ->execute()
      ->last();
    $this->assertEquals($testRecurring['amount'] + $additionalAmount, $updatedRecurring['amount']);
    $this->assertEquals($activity['subject'], "Added " . $additionalAmount . " " . $msg['currency']);
    $this->assertEquals($activity['details'], json_encode($amountDetails));
    $this->ids['ContributionRecur'][$testRecurring['id']] = $testRecurring['id'];
    $this->ids['Activity'][$activity['id']] = $activity['id'];
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringDowngrade(): void {
    $testRecurringContributionFor15Dollars = $this->createContributionRecur([
      'amount' => 15,
    ]);

    // The recurring donation has been reduced by 10 dollars
    $newRecurringDonationAmount = 5;
    $changeAmount = ($testRecurringContributionFor15Dollars['amount'] - $newRecurringDonationAmount);

    $recurringQueueMessage = [
      'txn_type' => "recurring_downgrade",
      'contribution_recur_id' => $testRecurringContributionFor15Dollars['id'],
      'amount' => $newRecurringDonationAmount,
      'currency' => $testRecurringContributionFor15Dollars['currency'],
    ];

    $amountDetails = [
      "native_currency" => 'USD',
      "native_original_amount" => 15.0,
      "usd_original_amount" => 15.0,
      "native_amount_removed" => 10.0,
      "usd_amount_removed" => 10.0,
    ];

    $this->processMessage($recurringQueueMessage);

    $updatedRecurring = ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount')
      ->addWhere('id', '=', $testRecurringContributionFor15Dollars['id'])
      ->execute()
      ->first();

    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $testRecurringContributionFor15Dollars['id'])
      ->addWhere('activity_type_id', '=', RecurringQueueConsumer::RECURRING_DOWNGRADE_ACTIVITY_TYPE_ID)
      ->execute()
      ->last();

    $this->assertEquals($newRecurringDonationAmount, $updatedRecurring['amount']);

    $this->assertEquals("Recurring amount reduced by " . abs($changeAmount) . " " . $recurringQueueMessage['currency'], $activity['subject']);

    $this->assertEquals(json_encode($amountDetails), $activity['details']);

    // clean up fixture data
    $this->ids['ContributionRecur'][$testRecurringContributionFor15Dollars['id']] = $testRecurringContributionFor15Dollars['id'];
    $this->ids['Activity'][$activity['id']] = $activity['id'];
  }

  /**
   * Test use of API4 in Contribution Tracking in recurring module
   *
   * @throws \CRM_Core_Exception
   */
  public function testApi4CTinRecurringGet(): void {
    $recur = $this->createContributionRecur();
    $contribution = $this->createRecurringContribution([
      'contribution_recur_id' => $recur['id'],
      'contact_id' => $recur['contact_id'],
      'trxn_id' => $recur['trxn_id'],
    ]);
    $generator = Factory::getSequenceGenerator('contribution-tracking');
    $contribution_tracking_id = $generator->getNext();
    $createTestCT = ContributionTracking::save(FALSE)->addRecord(WMFHelper::getContributionTrackingParameters([
      'utm_source' => '..rpp',
      'utm_medium' => 'civicrm',
      'ts' => '',
      'contribution_id' => $contribution['id'],
      'id' => $contribution_tracking_id,
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

  /**
   * Create a contribution_recur table row for a test
   *
   * @param array $values
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createRecurringContribution(array $values = []): array {
    return Contribution::create(FALSE)->setValues(array_merge([
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
      'total_amount' => 60,
      'receive_date' => 'now',
    ], $values))->execute()->first();
  }

  /**
   * Ensure non-USD PayPal synthetic start message gets the right
   * currency imported
   *
   * @throws \CRM_Core_Exception
   * @throws \Random\RandomException
   */
  public function testPayPalMissingPredecessorNonUSD(): void {
    $email = random_int(0, 1000) . 'not-in-the-database@example.com';
    $message = $this->getRecurringPaymentMessage(
      [
        'currency' => 'CAD',
        'amount' => 10.00,
        'gateway' => 'paypal_ec',
        'subscr_id' => 'I-123456',
        'email' => $email,
      ]
    );

    $this->processMessage($message);
    $contact = Contact::get(FALSE)
      ->addWhere('email_primary.email', '=', $email)
      ->execute()->single();
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute()->single();
    // ...and it should have the correct currency
    $this->assertEquals('CAD', $contributionRecur['currency']);
  }

  /**
   * Deal with a bad situation caused by PayPal's botched subscr_id migration.
   * See comment on RecurringQueueConsumer::importSubscriptionPayment.
   *
   * @throws \CRM_Core_Exception
   */
  public function testScrewySubscrId(): void {
    $email = 'test_recur_' . mt_rand() . '@example.org';
    // Set up an old-style PayPal recurring subscription with S-XXXX subscr_id
    $subscr_id = 'S-' . mt_rand();
    $ctId = $this->addContributionTrackingRecord();
    $values = $this->processRecurringSignup($subscr_id, [
      'gateway' => 'paypal',
      'email' => $email,
      'contribution_tracking_id' => $ctId,
    ]);

    // Import an initial payment with consistent gateway and subscr_id
    $values['email'] = $email;
    $values['gateway'] = 'paypal';
    $oldStyleMessage = $this->getRecurringPaymentMessage($values);

    $this->processMessage($oldStyleMessage);

    // New payment comes in with subscr ID format that we associate
    // with paypal_ec, so we mis-tag the gateway.
    $new_subscr_id = 'I-' . mt_rand();
    $values['subscr_id'] = $new_subscr_id;
    $values['gateway'] = 'paypal_ec';
    $values['gateway_txn_id'] = 456789;
    $newStyleMessage = $this->getRecurringPaymentMessage($values);

    $this->processMessage($newStyleMessage);
    // It should be imported as a paypal donation, not paypal_ec
    $contribution = $this->getContributionForMessage(['gateway' => 'paypal'] + $newStyleMessage);

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $contribution['contribution_recur_id'])
      ->execute()->single();

    // Finally, we should have stuck the new ID in the processor_id field
    $this->assertEquals($new_subscr_id, $contributionRecur['processor_id']);
  }

  /**
   * Process the original recurring sign up message.
   *
   * @param string $subscr_id
   * @param array $overrides
   *
   * @return array
   */
  private function processRecurringSignup(string $subscr_id, array $overrides = []): array {
    $values = $overrides + ['subscr_id' => $subscr_id];
    $this->processMessage($this->getRecurringSignupMessage($values));
    return $values;
  }

}
