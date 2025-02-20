<?php

namespace phpunit\Civi\WMFQueue;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Email;
use Civi\Api4\PaymentToken;
use Civi\Test\Api3TestTrait;
use Civi\Test\ContactTestTrait;
use Civi\WMFException\WMFException;
use Civi\WMFQueue\BaseQueueTestCase;

/**
 * This test suite verifies that PayPal recurring payment charges
 * can be sent to the donations queue.
 *
 * Initially, both recurring PayPal subscriptions and recurring PayPal payments
 * were handled solely by the recurring queue consumer. Now, recurring
 * subscriptions are processed by the recurring queue consumer while recurring
 * payment charges are managed by the donations queue consumer. This test suite
 * ensures the correct integration and behavior of these two queue consumers.
 *
 * Note: I deliberately did not use processDonationMessage() so that I could
 * test out sending recurring payment messages without any of the donation
 * message defaults being set - so that we can see how the Donation Queue
 * Consumer handles a "raw" recurring payment message.
 *
 * @group queues
 * @group Recurring
 * @group PayPal
 */
class PayPalRecurringDonationsQueueTest extends BaseQueueTestCase {

  use ContactTestTrait;
  use Api3TestTrait;


  protected string $donationQueueName = 'donations';
  protected string $donationQueueConsumer = 'Donation';

  public function setUp() : void {
    parent::setUp();
    $this->queueName = 'recurring';
    $this->queueConsumer = 'Recurring';
  }

  public function testRecurringPaymentSentToDonationQueue(): void {
    $values = [
      'contribution_tracking_id' => $this->addContributionTrackingRecord(),
      'subscr_id' => 2048343366,
      'gateway_txn_id' => 1234567890,
      'first_name' => 'Test',
      'last_name' => 'McTest',
      'street_address' => '5109 Lockwood Rd',
      'city' => 'New York',
      'postal_code' => '12345',
      'country' => 'US',
      'email' => 'test@example.org',
      'gross' => 20.00,
      'fee' => 0,
      'net' => 20.00,
      'currency' => 'USD',
      'gateway' => 'paypal',
    ];

    // create subscription. process 'subscr_signup' message
    $this->processRecurringSignup($values);

    // create a 'subscr_payment' message ready to be sent to donations queue
    $message = $this->getRecurringPaymentMessage($values);

    // HI! here's where we redirect the recurring payment to the donation queue
    $this->processMessage($message, $this->donationQueueConsumer, $this->donationQueueName);

    $this->processQueue('contribution-tracking', 'ContributionTracking');

    // retrieve contribution to inspect
    $contribution = $this->getContributionForMessage($message);

    // confirm the basics
    $this->assertEquals('RECURRING PAYPAL 1234567890', $contribution['trxn_id']);
    $this->assertEquals(20.00, $contribution['total_amount']);
    $this->assertEquals('USD', $contribution['currency']);

    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('test@example.org', $email['email']);

    $address = Address::get(FALSE)
      ->addSelect('*', 'contact_id.display_name')
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('5109 Lockwood Rd', $address['street_address']);
    $this->assertEquals('Test McTest', $address['contact_id.display_name']);

    $recur_record = $this->getContributionRecurForMessage($message);
    $this->assertIsNumeric($recur_record['payment_processor_id']);
    $this->assertEquals($recur_record['id'], $contribution['contribution_recur_id']);
  }

  /**
   * I'm not sure if we'd ever do this, but it looks like we test for it in
   * other test suites e.g.
   * RecurringQueueTest::testRecurringPaymentPaypalNoSubscrId so let's add
   * coverage here too.
   */
  public function testRecurringPaymentSentDirectlyToDonationQueueConsumer(): void {

    $values = [
      'contribution_tracking_id' => $this->addContributionTrackingRecord(),
      'subscr_id' => 2048343366,
      'gateway_txn_id' => 1234567890,
      'first_name' => 'Test',
      'last_name' => 'McTest',
      'street_address' => '5109 Lockwood Rd',
      'city' => 'New York',
      'postal_code' => '12345',
      'country' => 'US',
      'email' => 'test@example.org',
      'gross' => 20.00,
      'fee' => 0,
      'net' => 20.00,
      'currency' => 'USD',
      'gateway' => 'paypal',
    ];

    // create subscription. process 'subscr_signup' message
    $this->processRecurringSignup($values);

    // create a 'subscr_payment' message ready to be sent to donations queue
    $message = $this->getRecurringPaymentMessage($values);

    // HI! here's where we redirect the recurring payment to the donation queue consumer
    $this->processMessageWithoutQueuing($message, $this->donationQueueConsumer);

    $this->processQueue('contribution-tracking', 'ContributionTracking');

    // retrieve contribution to inspect
    $contribution = $this->getContributionForMessage($message);

    // confirm the basics
    $this->assertEquals('RECURRING PAYPAL 1234567890', $contribution['trxn_id']);
    $this->assertEquals(20.00, $contribution['total_amount']);
    $this->assertEquals('USD', $contribution['currency']);

    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('test@example.org', $email['email']);

    $address = Address::get(FALSE)
      ->addSelect('*', 'contact_id.display_name')
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('5109 Lockwood Rd', $address['street_address']);
    $this->assertEquals('Test McTest', $address['contact_id.display_name']);

    $recur_record = $this->getContributionRecurForMessage($message);
    $this->assertIsNumeric($recur_record['payment_processor_id']);
    $this->assertEquals($recur_record['id'], $contribution['contribution_recur_id']);
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPaymentNormalizedMessages(): void {
    // Do the original sign-up with a low-information donor.
    $values = [
      'contribution_tracking_id' => $this->addContributionTrackingRecord(),
      'subscr_id' => 456799,
      'first_name' => '',
      'last_name' => 'Mouse',
      'street_address' => '',
      'city' => '',
      'postal_code' => '',
      'country' => '',
      'state_province' => '',
      'email' => 'bob-the-mouse@example.org',
      'gateway' => 'paypal',
    ];
    $this->processRecurringSignup($values);

    // We want to try the update on a contact with no name but, it adds the name 'Anonymous
    // if we leave blank above - so use a query to wipe it out.
    \CRM_Core_DAO::executeQuery('UPDATE civicrm_contact SET last_name = "" WHERE last_name = "Mouse"');

    // Now process a message with more information.
    // Our expectations on update are
    // 1) The contact's name is updated as we did not have one.
    // 2) The contact's email will not be updated, as it exists.
    // 3) The contact's address will be created, as it does not exist.
    $values = [
      'first_name' => 'Bob',
      'last_name' => 'Mouse',
      'street_address' => '5109 Lockwood Rd',
      'country' => 'US',
      'email' => 'bob-the-mouse@example.org',
    ] + $values;

    // create a 'subscr_payment' message ready to be sent to donations queue
    $message = $this->getRecurringPaymentMessage($values);

    // HI! here's where we redirect the recurring payment to the donation queue
    $this->processMessage($message, $this->donationQueueConsumer, $this->donationQueueName);

    $this->processQueue('contribution-tracking', 'ContributionTracking');

    // retrieve contribution to inspect
    $contribution = $this->getContributionForMessage($message);

    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('bob-the-mouse@example.org', $email['email']);

    $address = Address::get(FALSE)
      ->addSelect('*', 'contact_id.display_name')
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('5109 Lockwood Rd', $address['street_address']);
    $this->assertEquals('Bob Mouse', $address['contact_id.display_name']);

    // Now delete the donation & try again, passing in updated email, address, name values.
    Contribution::delete(FALSE)->addWhere('id', '=', $contribution['id'])
      ->execute();
    // Our expectations on update are
    // 1) The contact's name is not updated as we already have first_name, last_name
    // 2) The contact's email will not be updated, as it exists.
    // 3) The contact's address will not be updated, as it exists.
    $values['first_name'] = 'Robert';
    $values['street_address'] = '5019 Bendy Road';
    $values['country'] = 'US';
    $values['email'] = 'RussTheGreat@example.com';
    $message = $this->processRecurringPaymentMessage($values);
    $contribution = $this->getContributionForMessage($message);

    $email = Email::get(FALSE)
      ->addWhere('contact_id.last_name', '=', 'Mouse')
      ->execute()->single();
    $this->assertEquals('bob-the-mouse@example.org', $email['email'], 'Email should be unchanged');

    $address = Address::get(FALSE)
      ->addSelect('*', 'contact_id.display_name')
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('5109 Lockwood Rd', $address['street_address'], 'Address should be unchanged');
    $this->assertEquals($contribution['contact_id'], $address['contact_id']);
    $this->assertEquals('Bob Mouse', $address['contact_id.display_name']);

    $recur_record = $this->getContributionRecurForMessage($message);
    $this->assertIsNumeric($recur_record['payment_processor_id']);

    $this->assertEquals($recur_record['id'], $contribution['contribution_recur_id']);
  }

  /**
   *  Test that a blank address is not written to the DB.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPaymentBlankAddress(): void {
    $subscr_id = mt_rand();
    $values = [
      'contribution_tracking_id' => $this->addContributionTrackingRecord(),
      'subscr_id' => $subscr_id,
    ];
    $this->processRecurringSignup($values);
    $values['city'] = '';
    $values['country'] = '';
    $values['state_province'] = '';
    $values['street_address'] = '';
    $values['postal_code'] = '';

    $message = $this->processRecurringPaymentMessage($values);

    // HI! here's where we redirect the recurring payment to the donation queue
    $this->processMessage($message, $this->donationQueueConsumer, $this->donationQueueName);

    $this->processQueue('contribution-tracking', 'ContributionTracking');

    $contribution = $this->getContributionForMessage($message);
    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    // The address created by the sign up (Lockwood Rd) should not have been overwritten by the blank.
    $this->assertEquals('5109 Lockwood Rd', $address['street_address']);
  }

  public function testRecurringPaymentPaypalNoSubscrId(): void {
    $this->expectExceptionCode(WMFException::INVALID_RECURRING);
    $this->expectException(WMFException::class);

    // create a 'subscr_payment' message ready to be sent to donations queue
    $message = $this->getRecurringPaymentMessage();

    // HI! here's where we redirect the recurring payment to the donation queue consumer
    $this->processMessageWithoutQueuing($message, $this->donationQueueConsumer);
  }

  /**
   * If the payment does not have an associated subscription
   * we should make one.
   *
   * TODO: This test is failing because the subscription is not being created.
   * @return void
   */
  public function testRecurringPaymentPaypalMissingPredecessor(): void {
    // $this->markTestIncomplete("this one is failing due to the issue documented here https://phabricator.wikimedia.org/T240581#9353558");

    $this->expectExceptionCode(WMFException::MISSING_PREDECESSOR);
    $this->expectException(WMFException::class);

    // this is setting an intentionally random subscr_id to trigger the missing predecessor exception
    $message = $this->getRecurringPaymentMessage([
      'subscr_id' => mt_rand(),
      'email' => 'notinthedb@example.com',
    ]);

    // HI! here's where we redirect the recurring payment to the donation queue consumer
    $this->processMessageWithoutQueuing($message, $this->donationQueueConsumer);
  }

  /**
   * With PayPal, we don't reliably get subscr_signup messages before the
   * first payment message. Fortunately the payment messages have enough
   * data to insert the recur record.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPaymentPayPalECMissingPredecessor(): void {
    $email = 'not-in-the-db' . mt_rand() . '@example.com';
    $values = [
      'gateway' => 'paypal_ec',
      'subscr_id' => 'I-' . mt_rand(),
      'email' => $email,
      'gateway_txn_id' => 123,
    ];

    // create a 'subscr_payment' message ready to be sent to donations queue
    $message = $this->getRecurringPaymentMessage($values);

    // HI! here's where we redirect the recurring payment to the donation queue
    $this->processMessage($message, $this->donationQueueConsumer, $this->donationQueueName);

    $recurRecord = ContributionRecur::get(FALSE)
      ->addWhere('contact_id.email_primary.email', '=', $email)
      ->execute()->single();

    $contribution = $this->getContributionForMessage([
      'gateway' => 'paypal_ec',
      'gateway_txn_id' => 123,
    ]);

    // ...and it should be associated with the contribution
    $this->assertEquals(
      $recurRecord['id'],
      $contribution['contribution_recur_id'],
      'New recurring record not associated with newly inserted payment.'
    );

  }

  /**
   * Ensure non-USD PayPal synthetic start message gets the right
   * currency imported
   *
   * @throws \CRM_Core_Exception
   * @throws \Random\RandomException
   */
  public function testRecurringPaymentPayPalECMissingPredecessorNonUSD(): void {
    $email = random_int(0, 1000) . 'not-in-the-database@example.com';
    $values = [
      'email' => $email,
      'amount' => 10.00,
      'gateway' => 'paypal_ec',
      'subscr_id' => 'I-123456',
      'currency' => 'CAD',
    ];

    // create a 'subscr_payment' message ready to be sent to donations queue
    $message = $this->getRecurringPaymentMessage($values);

    // HI! here's where we redirect the recurring payment to the donation queue
    $this->processMessage($message, $this->donationQueueConsumer, $this->donationQueueName);

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
   * Test that processing more than one recurring payment creates separate contributions.
   *
   * This is to ensure that (e.g.) monthly payments each get their own records.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPaymentDistinctContributions(): void {
    $subscr_id = mt_rand();
    $ctId = $this->addContributionTrackingRecord();

    $values = [
      'contribution_tracking_id' => $ctId,
      'subscr_id' => $subscr_id,
    ];

    $message = $this->processRecurringSignup($values);
    $nextScheduledDate = $this->getContributionRecurForMessage($message)['next_sched_contribution_date'];

    $message = $this->processRecurringPaymentMessage($values);

    $nextScheduledDateAfterPayment = $this->getContributionRecurForMessage($message)['next_sched_contribution_date'];
    $this->assertGreaterThan(strtotime($nextScheduledDate), strtotime($nextScheduledDateAfterPayment));
    $contribution = $this->getContributionForMessage($message);
    $contributionTracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $message['contribution_tracking_id'])
      ->execute()->first();
    $this->assertEquals(
      $contribution['id'],
      $contributionTracking['contribution_id']
    );
    $message2 = $this->processRecurringPaymentMessage($values);

    $contributionTracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $message['contribution_tracking_id'])
      ->execute()->first();

    // The ct_id record should still link to the first contribution
    $this->assertEquals(
      $contribution['id'],
      $contributionTracking['contribution_id']
    );
    $recur_record = $this->getContributionRecurForMessage($message);
    $this->assertEquals($recur_record['id'], $contribution['contribution_recur_id']);
    $contribution2 = $this->getContributionForMessage($message2);
    $this->assertEquals($recur_record['id'], $contribution2['contribution_recur_id']);

    $this->assertEquals($contribution['contact_id'], $contribution2['contact_id']);
    $address = Address::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('5109 Lockwood Rd', $address['street_address']);

    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('test+recur_fr@wikimedia.org', $email['email']);
  }

  /**
   * Test reactivating recurring contribution when a payment comes in.
   */
  public function testRecurringPaymentAfterCancelContributions(): void {
    $values = ['subscr_id' => mt_rand()];
    $this->processRecurringSignup($values);
    $this->processMessage($this->getRecurringCancelMessage($values));
    $contributionRecur = $this->getContributionRecurForMessage($values);
    // Verify record is cancelled
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);

    // Import new Subscription payment on cancelled recur record
    $message = $this->getRecurringPaymentMessage($values);
    // HI! here's where we redirect the recurring payment to the donation queue
    $this->processMessage($message, $this->donationQueueConsumer, $this->donationQueueName);

    $this->processQueue('contribution-tracking', 'ContributionTracking');
    $contributionRecur = $this->getContributionRecurForMessage($values);
    $this->assertNotEmpty($contributionRecur['payment_processor_id']);
    $this->assertEmpty($contributionRecur['failure_retry_date']);
    $this->assertEquals('In Progress', $contributionRecur['contribution_status_id:name']);
  }

  /**
   * Process the original recurring sign up message.
   *
   * @param array $overrides
   *
   * @return array
   */
  private function processRecurringSignup(array $overrides = []): array {
    $message = $this->getRecurringSignupMessage($overrides);
    $this->processMessage($message);
    return $message;
  }

  /**
   * @param array $signupMessage
   *
   * @return array|null
   */
  public function getTokenFromSignupMessage(array $signupMessage): ?array {
    try {
      return PaymentToken::get(FALSE)
        ->addWhere('payment_processor_id.name', '=', $signupMessage['gateway'])
        ->addWhere('token', '=', $signupMessage['recurring_payment_token'])
        ->addOrderBy('created_date', 'DESC')
        ->execute()->first();
    }
    catch (\CRM_Core_Exception $e) {
      $this->fail('failed to load PaymentToken :' . $e->getMessage());
    }
  }

}
