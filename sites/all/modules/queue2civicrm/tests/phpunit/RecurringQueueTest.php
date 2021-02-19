<?php

use queue2civicrm\recurring\RecurringQueueConsumer;
use wmf_communication\TestMailer;

/**
 * @group Queue2Civicrm
 * @group Recurring
 */
class RecurringQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var RecurringQueueConsumer
   */
  protected $consumer;

  public function setUp() {
    parent::setUp();
    $this->consumer = new RecurringQueueConsumer(
      'recurring'
    );

    // Set up for TestMailer
    if ( !defined( 'WMF_UNSUB_SALT' ) ) {
      define( 'WMF_UNSUB_SALT', 'abc123' );
    }
  }

  protected function importMessage(TransactionMessage $message) {
    $payment_time = $message->get('date');
    exchange_rate_cache_set('USD', $payment_time, 1);
    $currency = $message->get('currency');
    if ($currency !== 'USD') {
      exchange_rate_cache_set($currency, $payment_time, 3);
    }
    $this->consumer->processMessage($message->getBody());
    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $message->getGateway(),
      $message->getGatewayTxnId()
    );
    if (!empty($contributions[0])) {
      $this->addToCleanup($contributions[0]);
    }
    return $contributions;
  }

  public function testCreateDistinctContributions() {
    civicrm_initialize();
    $subscr_id = mt_rand();
    $ctId = $this->addContributionTracking();

    $values = $this->processRecurringSignup(
      $subscr_id,
      ['contribution_tracking_id' => $ctId]
    );

    $message = new RecurringPaymentMessage($values);
    $message2 = new RecurringPaymentMessage($values);

    $msg = $message->getBody();

    $contributions = $this->importMessage($message);
    $this->consumeCtQueue();
    $ctRecord = db_select('contribution_tracking', 'ct')
      ->fields('ct')
      ->condition('id', $msg['contribution_tracking_id'], '=')
      ->execute()
      ->fetchAssoc();

    $this->assertEquals(
      $contributions[0]['id'],
      $ctRecord['contribution_id']
    );
    $contributions2 = $this->importMessage($message2);
    $this->consumeCtQueue();

    $ctRecord2 = db_select('contribution_tracking', 'ct')
      ->fields('ct')
      ->condition('id', $msg['contribution_tracking_id'], '=')
      ->execute()
      ->fetchAssoc();

    // The ct_id record should still link to the first contribution
    $this->assertEquals(
      $contributions[0]['id'],
      $ctRecord2['contribution_id']
    );
    $recur_record = wmf_civicrm_get_recur_record($subscr_id);

    $this->assertNotEquals(FALSE, $recur_record);

    $this->assertEquals(1, count($contributions));
    $this->assertEquals($recur_record->id, $contributions[0]['contribution_recur_id']);
    $this->assertEquals(1, count($contributions2));
    $this->assertEquals($recur_record->id, $contributions2[0]['contribution_recur_id']);

    $this->assertEquals($contributions[0]['contact_id'], $contributions2[0]['contact_id']);
    $addresses = $this->callAPISuccess(
      'Address',
      'get',
      ['contact_id' => $contributions2[0]['contact_id']]
    );
    $this->assertEquals(1, $addresses['count']);
    // The address comes from the recurring_payment.json not the recurring_signup.json as it
    // has been overwritten. This is perhaps not a valid scenario in production but it is
    // the scenario the code works to. In production they would probably always be the same.
    $this->assertEquals('1211122 132 st', $addresses['values'][$addresses['id']]['street_address']);

    $emails = $this->callAPISuccess('Email', 'get', ['contact_id' => $contributions2[0]['contact_id']]);
    $this->assertEquals(1, $addresses['count']);
    $this->assertEquals('test+fr@wikimedia.org', $emails['values'][$emails['id']]['email']);
  }

  /**
   * Test function that cancels recurrings.
   */
  public function testCancelContributions() {
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);
    $this->importMessage(new RecurringCancelMessage($values));

    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->ids['Contact'][] = $recur_record['contact_id'];
    $this->assertEquals('(auto) User Cancelled via Gateway', $recur_record['cancel_reason']);
    $this->assertEquals('2013-11-01 23:07:05', $recur_record['cancel_date']);
    $this->assertEquals('2013-11-01 23:07:05', $recur_record['end_date']);
    $this->assertNotEmpty($recur_record['payment_processor_id']);
    $this->assertTrue(empty($recur_record['failure_retry_date']));
    $this->assertTrue(empty($recur_record['next_sched_contribution_date']));
    $this->assertEquals('Cancelled', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur_record['contribution_status_id']));
  }

  /**
   * Test function that expires recurrings.
   */
  public function testExpireContributions(): void {
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);
    $this->importMessage(new RecurringEOTMessage($values));

    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->ids['Contact'][] = $recur_record['contact_id'];
    $this->assertEquals('(auto) Expiration notification', $recur_record['cancel_reason']);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($recur_record['end_date'])));
    $this->assertNotEmpty($recur_record['payment_processor_id']);
    $this->assertTrue(empty($recur_record['failure_retry_date']));
    $this->assertTrue(empty($recur_record['next_sched_contribution_date']));
    $this->assertEquals('Completed', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur_record['contribution_status_id']));
  }

  public function testNormalizedMessages() {
    civicrm_initialize();
    $subscr_id = mt_rand();
    $ctId = $this->addContributionTracking();
    $values = $this->processRecurringSignup(
      $subscr_id,
      ['contribution_tracking_id' => $ctId]
    );

    $message = new RecurringPaymentMessage($values);

    $contributions = $this->importMessage($message);

    $recur_record = wmf_civicrm_get_recur_record($subscr_id);
    $this->assertNotEquals(FALSE, $recur_record);
    $this->assertTrue(is_numeric($recur_record->payment_processor_id));

    $this->assertEquals(1, count($contributions));
    $this->assertEquals($recur_record->id, $contributions[0]['contribution_recur_id']);

    $addresses = $this->callAPISuccess(
      'Address',
      'get',
      ['contact_id' => $contributions[0]['contact_id']]
    );
    $this->assertEquals(1, $addresses['count']);

    $emails = $this->callAPISuccess('Email', 'get', ['contact_id' => $contributions[0]['contact_id']]);
    $this->assertEquals(1, $addresses['count']);
    $this->assertEquals('test+fr@wikimedia.org', $emails['values'][$emails['id']]['email']);
  }

  /**
   *  Test that a blank address is not written to the DB.
   */
  public function testBlankEmail() {
    civicrm_initialize();
    $subscr_id = mt_rand();
    $ctId = $this->addContributionTracking();
    $values = $this->processRecurringSignup(
      $subscr_id,
      ['contribution_tracking_id' => $ctId]
    );
    $message = new RecurringPaymentMessage($values);
    $messageBody = $message->getBody();

    $addressFields = [
      'city',
      'country',
      'state_province',
      'street_address',
      'postal_code',
    ];
    foreach ($addressFields as $addressField) {
      $messageBody[$addressField] = '';
    }

    $this->consumer->processMessage($messageBody);

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $message->getGateway(),
      $message->getGatewayTxnId()
    );
    $this->addToCleanup($contributions[0]);
    $addresses = $this->callAPISuccess(
      'Address',
      'get',
      ['contact_id' => $contributions[0]['contact_id'], 'sequential' => 1]
    );
    $this->assertEquals(1, $addresses['count']);
    // The address created by the sign up (Lockwood Rd) should not have been overwritten by the blank.
    $this->assertEquals('5109 Lockwood Rd', $addresses['values'][0]['street_address']);
  }

  /**
   * @expectedException WmfException
   * @expectedExceptionCode WmfException::MISSING_PREDECESSOR
   */
  public function testMissingPredecessor() {
    $message = new RecurringPaymentMessage(
      [
        'subscr_id' => mt_rand(),
        'email' => 'notinthedb@example.com',
      ]
    );

    $this->importMessage($message);
  }

  /**
   * With PayPal, we don't reliably get subscr_signup messages before the
   * first payment message. Fortunately the payment messages have enough
   * data to insert the recur record.
   */
  public function testPayPalMissingPredecessor() {
    $email = 'notinthedb' . (string)mt_rand() . '@example.com';
    $message = new RecurringPaymentMessage(
      [
        'gateway' => 'paypal_ec',
        'subscr_id' => 'I-' . (string)mt_rand(),
        'email' => $email,
      ]
    );

    $contributions = $this->importMessage($message);

    // We should have inserted one contribution_recur record
    $recur_records = wmf_civicrm_dao_to_list(CRM_Core_DAO::executeQuery("
      SELECT ccr.*
      FROM civicrm_contribution_recur ccr
      INNER JOIN civicrm_email e on ccr.contact_id = e.contact_id
      WHERE e.email = '$email'
    "));
    $this->assertEquals(1, count($recur_records));

    // ...and it should be associated with the contribution
    $this->assertEquals(
      $recur_records[0]['id'],
      $contributions[0]['contribution_recur_id'],
      'New recurring record not associated with newly inserted payment.'
    );
  }

  /**
   * Deal with a bad situation caused by PayPal's botched subscr_id migration.
   * See comment on RecurringQueueConsumer::importSubscriptionPayment.
   */
  public function testScrewySubscrId() {
    civicrm_initialize();
    $email = 'test_recur_' . mt_rand() . '@example.org';
    // Set up an old-style PayPal recurring subscription with S-XXXX subscr_id
    $subscr_id = 'S-' . mt_rand();
    $ctId = $this->addContributionTracking();
    $values = $this->processRecurringSignup($subscr_id, [
      'gateway' => 'paypal',
      'email' => $email,
      'contribution_tracking_id' => $ctId
    ]);

    // Import an initial payment with consistent gateway and subscr_id
    $values['email'] = $email;
    $values['gateway'] = 'paypal';
    $oldStyleMessage = new RecurringPaymentMessage($values);

    $this->importMessage($oldStyleMessage);

    // New payment comes in with subscr ID format that we associate
    // with paypal_ec, so we mis-tag the gateway.
    $new_subscr_id = 'I-' . mt_rand();
    $values['subscr_id'] = $new_subscr_id;
    $values['gateway'] = 'paypal_ec';
    $newStyleMessage = new RecurringPaymentMessage($values);

    $this->consumer->processMessage($newStyleMessage->getBody());
    // It should be imported as a paypal donation, not paypal_ec
    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      'paypal',
      $newStyleMessage->getGatewayTxnId()
    );
    // New record should have created a new contribution
    $this->assertEquals(1, count($contributions));

    // Add the contribution to our tearDown array, since we didn't go through
    // $this->importMessage for this one.
    $this->addToCleanup($contributions[0]);

    // There should still only be one contribution_recur record
    $recur_records = wmf_civicrm_dao_to_list(CRM_Core_DAO::executeQuery("
      SELECT ccr.*
      FROM civicrm_contribution_recur ccr
      INNER JOIN civicrm_email e on ccr.contact_id = e.contact_id
      WHERE e.email = '$email'
    "));
    $this->assertEquals(1, count($recur_records));

    // ...and it should be associated with the contribution
    $this->assertEquals($recur_records[0]['id'], $contributions[0]['contribution_recur_id']);

    // Finally, we should have stuck the new ID in the processor_id field
    $this->assertEquals($new_subscr_id, $recur_records[0]['processor_id']);
  }

  /**
   * @expectedException WmfException
   * @expectedExceptionCode WmfException::INVALID_RECURRING
   */
  public function testNoSubscrId() {
    $message = new RecurringPaymentMessage(
      [
        'subscr_id' => NULL,
      ]
    );

    $this->importMessage($message);
  }

  /**
   *  Test that a token is created for a new ingenico recurring donation and a recurring contribution
   *  is created correctly
   */
  public function testRecurringTokenIngenico() {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $ct_id = $this->addContributionTracking([
        'form_amount' => 4,
        'utm_source' => 'testytest',
        'language' => 'en',
        'country' => 'US'
    ]);

    $message = new TransactionMessage([
            'gateway' => 'ingenico',
            'gross' => 400,
            'original_gross' => 400,
            'original_currency' => 'USD',
            'contribution_tracking_id' => $ct_id,
        ]
    );

    $messageBody = $message->getBody();
    exchange_rate_cache_set('USD', $messageBody['date'], 1);
    $firstContribution = wmf_civicrm_contribution_message_import($messageBody);
    $this->addToCleanup($firstContribution);
    $this->consumeCtQueue();

    // Set up token specific values
    $overrides['recurring_payment_token']= mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id,$overrides);

    // Get the new token
    $token = wmf_civicrm_get_recurring_payment_token($overrides['gateway'],$overrides['recurring_payment_token']);
    // Check the token was created successfully
    $this->assertEquals($token['token'], $overrides['recurring_payment_token']);

    // Create matching trxn_id
    $trxn_id = 'RECURRING ' . strtoupper(($overrides['gateway'])) . ' ' . $subscr_id;
    $recur_record = wmf_civicrm_get_recur_record($trxn_id);
    // Check the record was created successfully
    $this->assertEquals($recur_record->trxn_id, $trxn_id);
    // The first contribution should be on the start_date
    $this->assertEquals($recur_record->next_sched_contribution_date,$recur_record->start_date);

    // Check cycle_day matches the start date
    $this->assertEquals($recur_record->cycle_day, date('j',$overrides['start_date']));

    // Clean up
    $this->ids['ContributionRecur'][$recur_record->id] = $recur_record->id;
    $this->ids['Contact'][$recur_record->contact_id] = $recur_record->contact_id;
  }

  /**
   * Test that a recurring donation created after a one-time donation with the
   * same ct_id is assigned to the same donor
   */
  public function testRecurringSignupAfterOneTime() {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();
    $ct_id = $this->addContributionTracking([
      'form_amount' => 4,
      'utm_source' => 'testytest',
      'language' => 'en',
      'country' => 'US'
    ]);

    $message = new TransactionMessage([
        'gateway' => 'ingenico',
        'gross' => 400,
        'original_gross' => 400,
        'original_currency' => 'USD',
        'contribution_tracking_id' => $ct_id,
      ]
    );

    $messageBody = $message->getBody();
    exchange_rate_cache_set('USD', $messageBody['date'], 1);
    $firstContribution = wmf_civicrm_contribution_message_import($messageBody);
    $this->addToCleanup($firstContribution);
    $this->consumeCtQueue();

    // Set up token specific values
    $overrides['currency'] = 'USD';
    $overrides['recurring_payment_token']= mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id,$overrides);

    // Get the new token
    $token = wmf_civicrm_get_recurring_payment_token($overrides['gateway'],$overrides['recurring_payment_token']);
    // Check that the token belongs to the same donor as the first donation
    $this->assertEquals($firstContribution['contact_id'], $token['contact_id']);
    // Create matching trxn_id
    $trxn_id = 'RECURRING ' . strtoupper(($overrides['gateway'])) . ' ' . $subscr_id;
    $recurRecord = wmf_civicrm_get_recur_record($trxn_id);
    // Check that the recur record belongs to the same donor
    $this->assertEquals($firstContribution['contact_id'], $recurRecord->contact_id);

    // Clean up
    $this->recurring_contributions[] = $recurRecord;
  }

  /**
   * Test that the notification email is sent when a updonate recurring subscription is started
   */
  public function testRecurringNotificationEmailSend() {
    variable_set( 'thank_you_add_civimail_records', 'false' );

    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $ct_id = $this->addContributionTracking([
        'form_amount' => 4,
        'utm_source' => 'testytest',
        'language' => 'en',
        'country' => 'US'
    ]);

    $message = new TransactionMessage([
            'gateway' => 'ingenico',
            'gross' => 400,
            'original_gross' => 400,
            'original_currency' => 'CAD',
            'contribution_tracking_id' => $ct_id,
        ]
    );

    $messageBody = $message->getBody();
    exchange_rate_cache_set('USD', $messageBody['date'], 1);
    exchange_rate_cache_set('CAD', $messageBody['date'], 2);
    $firstContribution = wmf_civicrm_contribution_message_import($messageBody);
    $this->addToCleanup($firstContribution);
    $this->consumeCtQueue();

    // Setup here to only generate a notification email
    TestMailer::setup();

    // Set up token specific values
    $overrides['recurring_payment_token']= mt_rand();
    $overrides['currency'] = 'CAD';
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id,$overrides);

    $this->assertEquals( 1, TestMailer::countMailings() );
    $sent = TestMailer::getMailing( 0 );

    // Check the right email
    $this->assertEquals( $messageBody['email'], $sent['to_address'] );

    // Check right email content
    $this->assertRegExp( '/you donated, and then decided to set up an additional/', $sent['html'] );

    // Check the right donation amount
    $this->assertRegExp( '/3.00/', $sent['html'] );

    // Check the right donation currency, original currency is CAD
    $this->assertRegExp('/C\$/',$sent['html']);

    // Check the subject
    $expectedSubject = trim(file_get_contents(
        __DIR__ .
        "/../../../thank_you/templates/subject/recurring_notification.en.subject"
    ));
    $this->assertEquals( $expectedSubject, $sent['subject']);
  }

  /**
   * Process the original recurring sign up message.
   *
   * @param string $subscr_id
   *
   * @return array
   */
  private function processRecurringSignup($subscr_id, $overrides = []) {
    $values = $overrides + ['subscr_id' => $subscr_id];
    $signup_message = new RecurringSignupMessage($values);
    $subscr_time = $signup_message->get('date');
    exchange_rate_cache_set('USD', $subscr_time, 1);
    exchange_rate_cache_set($signup_message->get('currency'), $subscr_time, 2);
    $this->consumer->processMessage($signup_message->getBody());
    return $values;
  }

  private function addToCleanup($contribution) {
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];
    $this->ids['Contact'][$contribution['contact_id']] = $contribution['contact_id'];
    if (!empty($contribution['contribution_recur_id'])) {
      $this->ids['ContributionRecur'][$contribution['contribution_recur_id']] = $contribution['contribution_recur_id'];
    }
  }
}
