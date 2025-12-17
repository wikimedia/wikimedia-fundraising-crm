<?php

namespace Civi\WMFQueue;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Email;
use Civi\Api4\MessageTemplate;
use Civi\Api4\PaymentToken;
use Civi\Test\Api3TestTrait;
use Civi\Test\ContactTestTrait;
use Civi\WMFException\WMFException;

/**
 * @group queues
 * @group Recurring
 */
class RecurringQueueTest extends BaseQueueTestCase {

  use ContactTestTrait;
  use Api3TestTrait;

  protected string $queueName = 'recurring';

  protected string $queueConsumer = 'Recurring';

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
    $message = $this->processRecurringPaymentMessage($values);
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
    $message = $this->getRecurringPaymentMessage();
    $message['subscr_id'] = NULL;
    $this->processMessageWithoutQueuing($message);
  }

  /**
   * If the payment does not have a subscr
   * @return void
   */
  public function testRecurringPaymentPaypalMissingPredecessor(): void {
    $this->expectExceptionCode(WMFException::MISSING_PREDECESSOR);
    $this->expectException(WMFException::class);
    $message = $this->getRecurringPaymentMessage([
      'subscr_id' => mt_rand(),
      'email' => 'notinthedb@example.com',
    ]);
    $this->processMessageWithoutQueuing($message);
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
    $this->processRecurringPaymentMessage([
      'gateway' => 'paypal_ec',
      'subscr_id' => 'I-' . mt_rand(),
      'email' => $email,
      'gateway_txn_id' => 123,
    ]);
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
    $overrides = [
      'email' => $email,
      'amount' => 10.00,
      'gateway' => 'paypal_ec',
      'subscr_id' => 'I-123456',
      'currency' => 'CAD',
    ];
    $this->processRecurringPaymentMessage($overrides);
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
    $this->processRecurringPaymentMessage($values);
    $contributionRecur = $this->getContributionRecurForMessage($values);
    $this->assertNotEmpty($contributionRecur['payment_processor_id']);
    $this->assertEmpty($contributionRecur['failure_retry_date']);
    $this->assertEquals('In Progress', $contributionRecur['contribution_status_id:name']);
  }

  /**
   *  Test ingenico recurring donation payment.
   *
   *  A token & and a recurring contribution should be created.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPaymentIngenicoToken(): void {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $contributionTrackingID = $this->addContributionTrackingRecord([
      'form_amount' => 4,
      'utm_source' => 'testy-test',
      'language' => 'en',
      'country' => 'US',
    ]);

    $this->processDonationMessage([
      'gateway' => 'ingenico',
      'gross' => 400,
      'original_gross' => 400,
      'original_currency' => 'USD',
      'contribution_tracking_id' => $contributionTrackingID,
    ]);
    $this->processContributionTrackingQueue();

    // Set up token specific values
    $signupMessage['recurring_payment_token'] = mt_rand();
    $signupMessage['gateway_txn_id'] = $subscr_id;
    $signupMessage['user_ip'] = '1.1.1.1';
    $signupMessage['gateway'] = 'ingenico';
    $signupMessage['payment_method'] = 'cc';
    $signupMessage['payment_submethod'] = 'visa';
    $signupMessage['create_date'] = 1564068649;
    $signupMessage['start_date'] = 1566732720;
    $signupMessage['contribution_tracking_id'] = $contributionTrackingID;

    $this->processRecurringSignup($signupMessage);
    // Check the token was created successfully
    $token = $this->getTokenFromSignupMessage($signupMessage);
    $this->assertEquals($token['token'], $signupMessage['recurring_payment_token']);

    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', 'RECURRING ' . strtoupper(($signupMessage['gateway'])) . ' ' . $subscr_id)
      ->addSelect('*', 'contribution_status_id:name')
      ->execute()->single();
    // The first contribution should be on the start_date
    $this->assertEquals($contributionRecur['next_sched_contribution_date'], $contributionRecur['start_date']);

    // Check cycle_day matches the start date
    $this->assertEquals($contributionRecur['cycle_day'], date('j', $signupMessage['start_date']));
  }

  /**
   * Test that the notification email is sent when a donation is a monthly convert
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPaymentMonthlyConvertNotificationEmailSend(): void {
    \Civi::settings()->set('thank_you_add_civimail_records', FALSE);

    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $contributionTrackingRecordID = $this->addContributionTrackingRecord();
    $donationMessage = $this->processDonationMessage([
      'gateway' => 'adyen',
      'gross' => 400,
      'original_gross' => 400,
      'original_currency' => 'CAD',
      'contribution_tracking_id' => $contributionTrackingRecordID,
    ]);
    $this->processContributionTrackingQueue();

    // Set up token specific values
    $signupMessage['recurring_payment_token'] = mt_rand();
    $signupMessage['currency'] = 'CAD';
    $signupMessage['gateway_txn_id'] = $subscr_id;
    $signupMessage['user_ip'] = '1.1.1.1';
    $signupMessage['gateway'] = 'adyen';
    $signupMessage['payment_method'] = 'cc';
    $signupMessage['payment_submethod'] = 'visa';
    $signupMessage['create_date'] = 1564068649;
    $signupMessage['start_date'] = 1566732720;
    $signupMessage['contribution_tracking_id'] = $contributionTrackingRecordID;

    $this->processRecurringSignup($signupMessage);

    $this->assertEquals(1, $this->getMailingCount());
    $sent = $this->getMailing(0);

    // Check the right email
    $this->assertEquals($donationMessage['email'], $sent['to_address']);

    // Check right email content
    $this->assertMatchesRegularExpression('/you donated, and then decided to set up an additional/', $sent['html']);

    // Check the right donation amount
    $this->assertMatchesRegularExpression('/3.00/', $sent['html']);

    // Check the right donation currency, original currency is CAD
    $this->assertMatchesRegularExpression('/CA\$/', $sent['html']);
    // Check the subject.
    $expectedSubject = MessageTemplate::get(FALSE)
      ->addWhere('workflow_name', '=', 'monthly_convert')
      ->addWhere('is_default', '=', TRUE)
      ->execute()->first()['msg_subject'];
    $this->assertEquals($expectedSubject, $sent['subject']);
  }

  /**
   * Test that a recurring donation created after a one-time donation with the
   * same contribution tracking ID is assigned to the same donor
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringSignupAfterOnePayment(): void {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();
    $contributionTrackingID = $this->addContributionTrackingRecord();

    $donationMessage = $this->processDonationMessage([
      'gateway' => 'adyen',
      'gross' => 400,
      'original_gross' => 400,
      'original_currency' => 'USD',
      'contribution_tracking_id' => $contributionTrackingID,
    ]);
    $this->processContributionTrackingQueue();
    $firstContribution = $this->getContributionForMessage($donationMessage);

    // Set up token specific values
    $signupMessage['currency'] = 'USD';
    $signupMessage['recurring_payment_token'] = mt_rand();
    $signupMessage['gateway_txn_id'] = $subscr_id;
    $signupMessage['user_ip'] = '1.1.1.1';
    $signupMessage['gateway'] = 'adyen';
    $signupMessage['payment_method'] = 'cc';
    $signupMessage['payment_submethod'] = 'visa';
    $signupMessage['create_date'] = 1564068649;
    $signupMessage['start_date'] = 1566732720;
    $signupMessage['contribution_tracking_id'] = $contributionTrackingID;

    $this->processRecurringSignup($signupMessage);
    $token = $this->getTokenFromSignupMessage($signupMessage);
    // Check that the token belongs to the same donor as the first donation
    $this->assertEquals($firstContribution['contact_id'], $token['contact_id']);

    // Check that the recur record belongs to the same donor
    $contributionRecur = ContributionRecur::get(FALSE)
      ->addWhere('trxn_id', '=', 'RECURRING ' . strtoupper(($signupMessage['gateway'])) . ' ' . $subscr_id)
      ->addSelect('*', 'contribution_status_id:name')
      ->execute()->single();
    $this->assertEquals($firstContribution['contact_id'], $contributionRecur['contact_id']);
  }

  /**
   * Test handling of deadlock exception in function that handles recurring signup
   */
  public function testHandleDeadlocksInRecurringSignupMessage(): void {
    $signupMessage = $this->getRecurringSignupMessage();
    // Consume the recurring signup with deadlock exception
    $this->processMessage($signupMessage, 'RecurDeadlock');
    $rows = $this->getDamagedRows($signupMessage);
    $this->assertCount(1, $rows, 'No rows in damaged db for deadlock');
    $this->assertNotNull($rows[0]['retry_date'], 'Damaged message should have a retry date');
  }

  /**
   * Test deadlock results in re-queuing in function that expires recurring contributions.
   */
  public function testHandleDeadlocksInEOTMessage(): void {
    $subscr_id = mt_rand();
    $values = ['subscr_id' => $subscr_id];
    $this->processRecurringSignup($values);
    $values['source_enqueued_time'] = time();
    $message = $this->getRecurringEOTMessage($values);
    $this->processMessage($message, 'RecurDeadlock');
    $this->assertDamagedRowExists($message);
  }

  /**
   * Test deadlock handling in function that cancels recurring contributions.
   */
  public function testHandleDeadlocksInCancelMessage(): void {
    $signupMessage = $this->processRecurringSignup();
    $message = $this->getRecurringCancelMessage([
      'source_enqueued_time' => time(),
      'subscr_id' => $signupMessage['subscr_id'],
    ]);
    $this->processMessage($message, 'RecurDeadlock');
    $this->assertDamagedRowExists($message);
  }

  /**
   * Test processing a recurring cancel method.
   */
  public function testRecurringCancelMessage(): void {
    $values = ['subscr_id' => mt_rand()];
    $this->processRecurringSignup($values);
    $this->processMessage($this->getRecurringCancelMessage($values));
    $recur_record = $this->getContributionRecurForMessage($values);
    $this->assertEquals('(auto) User Cancelled via Gateway', $recur_record['cancel_reason']);
    $this->assertEquals('2013-11-01 23:07:05', $recur_record['cancel_date']);
    $this->assertEquals('2013-11-01 23:07:05', $recur_record['end_date']);
    $this->assertNotEmpty($recur_record['payment_processor_id']);
    $this->assertEmpty($recur_record['failure_retry_date']);
    $this->assertEquals('Cancelled', $recur_record['contribution_status_id:name']);
  }

  /**
   * Test processing a recurring cancel message when the recurring is already cancelled
   */
  public function testRecurringCancelMessageAlreadyCancelled(): void {
    $values = ['subscr_id' => mt_rand()];
    $this->processRecurringSignup($values);
    ContributionRecur::update(FALSE)
      ->addWhere('trxn_id', '=', $values['subscr_id'])
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->addValue('cancel_reason', 'Financial concerns')
      ->addValue('cancel_date', '2025-12-17 01:23:45')
      ->addValue('end_date', '2025-12-17 01:23:45')
      ->execute();

    $this->processMessage($this->getRecurringCancelMessage($values));

    $recur_record = $this->getContributionRecurForMessage($values);
    $this->assertEquals('Cancelled', $recur_record['contribution_status_id:name']);
    $this->assertEquals('Financial concerns', $recur_record['cancel_reason']);
    $this->assertEquals('2025-12-17 01:23:45', $recur_record['cancel_date']);
    $this->assertEquals('2025-12-17 01:23:45', $recur_record['end_date']);
  }

  /**
   * Test function adds reason to the recur row.
   */
  public function testRecurringCancelMessageWithReason(): void {
    $signupMessage = $this->processRecurringSignup();
    $values = [
      'cancel_reason' => 'Failed: Card declined',
      'subscr_id' => $signupMessage['subscr_id'],
    ];
    $this->processMessage($this->getRecurringCancelMessage($values));

    $contributionRecur = $this->getContributionRecurForMessage($values);
    $this->assertEquals('Failed: Card declined', $contributionRecur['cancel_reason']);
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);
  }

  /**
   * Test cancellation by the ID rather than by the subscr_id
   */
  public function testRecurringCancelMessageWithRecurringContributionID(): void {
    $signupMessage = $this->processRecurringSignup();
    $contributionRecur = $this->getContributionRecurForMessage($signupMessage);
    $this->processMessage($this->getRecurringCancelMessage([
      'contribution_recur_id' => $contributionRecur['id'],
    ]));
    $contributionRecur = $this->getContributionRecurForMessage($signupMessage);
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);
  }

  /**
   * Test processing a Gravy PayPal recurring cancel message.
   */
  public function testRecurringCancelGravyPaypalMessage(): void {
    // Create a Gravy PayPal recurring signup to have something to cancel
    $signupMessage = $this->processRecurringSignup([
      'gateway' => 'gravy',
      'payment_method' => 'paypal',
      'subscr_id' => '48df827f-93bd-4c33-8186-2da6fc02c878',
    ]);

    // Now process the cancellation message using your sample data
    $cancelMessage = [
      'gateway' => 'gravy',
      'txn_type' => 'subscr_cancel',
      'subscr_id' => '48df827f-93bd-4c33-8186-2da6fc02c878',
      'payment_method' => 'paypal',
      'date' => 1719773621,
      'cancel_date' => 1719773621,
      'recurring' => '1',
      'raw_response' => [
        'type' => 'payment-method',
        'id' => '48df827f-93bd-4c33-8186-2da6fc02c878',
        'method' => 'paypal',
        'status' => 'succeeded',
        'created_at' => '2024-06-30T18:51:10Z',
        'updated_at' => '2024-06-30T18:53:41Z',
        'external_identifier' => NULL,
      ],
    ];

    $this->processMessage($cancelMessage);

    // Verify the cancellation was processed correctly
    $recur_record = $this->getContributionRecurForMessage($signupMessage);
    $this->assertEquals('48df827f-93bd-4c33-8186-2da6fc02c878', $recur_record['trxn_id']);
    $this->assertEquals('(auto) User Cancelled via Gateway', $recur_record['cancel_reason']);
    $this->assertEquals('2024-06-30 18:53:41', $recur_record['cancel_date']);
    $this->assertEquals('2024-06-30 18:53:41', $recur_record['end_date']);
    $this->assertNotEmpty($recur_record['payment_processor_id']);
    $this->assertEmpty($recur_record['failure_retry_date']);
    $this->assertEquals('Cancelled', $recur_record['contribution_status_id:name']);
  }

  /**
   * Test record of failed recurring payment rather than by the subscr_id
   */
  public function testRecurringFailedMessageWithRecurringContributionID(): void {
    $failed_trxn_message = $this->getRecurringFailedMessage();
    $failed_trxn_message['failure_retry_date'] = strtotime('03:00:00 May 27, 2024 PDT');
    $signupMessage = $this->processRecurringSignup([
      'subscr_id' => $failed_trxn_message['subscr_id'],
    ]);
    $this->getContributionRecurForMessage($signupMessage);
    $this->processMessage($failed_trxn_message);
    $contributionRecur = $this->getContributionRecurForMessage($signupMessage);

    $this->assertEquals(1, $contributionRecur['failure_count']);
    $this->assertEquals('2024-05-27 10:00:00', $contributionRecur['failure_retry_date']);
    $this->assertEquals('Failing', $contributionRecur['contribution_status_id:name']);
  }

  /**
   * Test processing EOT (end of term) message from paypal.
   */
  public function testRecurringEOTPaypalMessage(): void {
    $signupMessage = $this->processRecurringSignup();
    $this->processMessage($this->getRecurringEOTMessage(['subscr_id' => $signupMessage['subscr_id']]));
    $contributionRecur = $this->getContributionRecurForMessage($signupMessage);
    $this->assertEquals('(auto) Expiration notification', $contributionRecur['cancel_reason']);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($contributionRecur['end_date'])));
    $this->assertNotEmpty($contributionRecur['payment_processor_id']);
    $this->assertEmpty($contributionRecur['failure_retry_date']);
    $this->assertEmpty($contributionRecur['next_sched_contribution_date']);
    $this->assertEquals('Completed', $contributionRecur['contribution_status_id:name']);
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
