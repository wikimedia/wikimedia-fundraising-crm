<?php

namespace Civi\WMFQueue;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Email;
use Civi\Test\Api3TestTrait;
use Civi\Test\ContactTestTrait;
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
  public function testRecurringPaymentNormalizedMessages(): void {
    $subscr_id = mt_rand();
    $values = [
      'contribution_tracking_id' => $this->addContributionTrackingRecord(),
      'subscr_id' => $subscr_id,
    ];
    $this->processRecurringSignup($values);

    $message = $this->processRecurringPaymentMessage($values);
    $contribution = $this->getContributionForMessage($message);

    $recur_record = $this->getContributionRecurForMessage($message);
    $this->assertIsNumeric($recur_record['payment_processor_id']);

    $this->assertEquals($recur_record['id'], $contribution['contribution_recur_id']);

    // The ->single() means these will fail if there is not exactly 1.
    Address::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('test+fr@wikimedia.org', $email['email']);
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
    $email = 'not-in-the-db' . (string) mt_rand() . '@example.com';
    $this->processRecurringPaymentMessage([
      'gateway' => 'paypal_ec',
      'subscr_id' => 'I-' . (string) mt_rand(),
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
   * Deal with a bad situation caused by PayPal's botched subscr_id migration.
   * See comment on RecurringQueueConsumer::importSubscriptionPayment.
   *
   * @throws \CRM_Core_Exception
   */
  public function testRecurringPaymentPaypalScrewySubscrId(): void {
    $email = 'test_recur_' . mt_rand() . '@example.org';
    // Set up an old-style PayPal recurring subscription with S-XXXX subscr_id
    $subscr_id = 'S-' . mt_rand();
    $values = [
      'gateway' => 'paypal',
      'email' => $email,
      'contribution_tracking_id' => $this->addContributionTrackingRecord(),
      'subscr_id' => $subscr_id,
    ];
    $this->processRecurringSignup($values);

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

    $this->processRecurringSignup($values);

    $message = $this->processRecurringPaymentMessage($values);
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
    // The address comes from the recurring_payment.json not the recurring_signup.json as it
    // has been overwritten. This is perhaps not a valid scenario in production but it is
    // the scenario the code works to. In production they would probably always be the same.
    $this->assertEquals('1211122 132 st', $address['street_address']);

    $email = Email::get(FALSE)
      ->addWhere('contact_id', '=', $contribution['contact_id'])
      ->execute()->single();
    $this->assertEquals('test+fr@wikimedia.org', $email['email']);
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
    $message = $this->processRecurringPaymentMessage($values);
    $contributionRecur = $this->getContributionRecurForMessage($values);
    $this->assertNotEmpty($contributionRecur['payment_processor_id']);
    $this->assertEmpty($contributionRecur['failure_retry_date']);
    $this->assertEquals('In Progress', $contributionRecur['contribution_status_id:name']);
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
    $signup = $this->processRecurringSignup();
    $message = $this->getRecurringCancelMessage([
      'source_enqueued_time' => time(),
      'subscr_id' => $signup['subscr_id'],
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
   * Test function adds reason to the recur row.
   */
  public function testRecurringCancelMessageWithReason(): void {
    $signup = $this->processRecurringSignup();
    $values = [
      'cancel_reason' => 'Failed: Card declined',
      'subscr_id' => $signup['subscr_id'],
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
    $signup = $this->processRecurringSignup();
    $contributionRecur = $this->getContributionRecurForMessage($signup);
    $this->processMessage($this->getRecurringCancelMessage([
      'contribution_recur_id' => $contributionRecur['id'],
    ]));
    $contributionRecur = $this->getContributionRecurForMessage($signup);
    $this->assertEquals('Cancelled', $contributionRecur['contribution_status_id:name']);
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
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function processContributionTrackingQueue(): void {
    $this->processQueue('contribution-tracking', 'ContributionTracking');
  }

  /**
   * @param array $message
   *
   * @return void
   */
  public function assertDamagedRowExists(array $message): void {
    $rows = $this->getDamagedRows($message);
    $this->assertCount(1, $rows, 'No rows in damaged db for deadlock');
    $this->assertNotNull($rows[0]['retry_date'], 'Damaged message should have a retry date');
  }

}
