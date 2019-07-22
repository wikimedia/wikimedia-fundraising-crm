<?php

use queue2civicrm\recurring\RecurringQueueConsumer;

/**
 * @group Queue2Civicrm
 */
class RecurringQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * @var RecurringQueueConsumer
   */
  protected $consumer;

  protected $contributions = [];

  protected $recurring_contributions = [];

  protected $ctIds = [];

  public function setUp() {
    parent::setUp();
    $this->consumer = new RecurringQueueConsumer(
      'recurring'
    );
  }

  // TODO: other queue import tests need to clean up like this!
  public function tearDown() {
    foreach ($this->ctIds as $ctId) {
      db_delete('contribution_tracking')
        ->condition('id', $ctId)
        ->execute();
    }
    foreach ($this->contributions as $contribution) {
      if (!empty($contribution['contribution_recur_id'])) {
        CRM_Core_DAO::executeQuery(
          "
        DELETE FROM civicrm_contribution_recur
        WHERE id = %1",
          [1 => [$contribution['contribution_recur_id'], 'Positive']]
        );
      }
      CRM_Core_DAO::executeQuery(
        "
      DELETE FROM civicrm_contribution
      WHERE id = %1",
        [1 => [$contribution['id'], 'Positive']]
      );
      CRM_Core_DAO::executeQuery(
        "
      DELETE FROM civicrm_contact
      WHERE id = %1",
        [1 => [$contribution['contact_id'], 'Positive']]
      );
    }

    foreach ($this->recurring_contributions as $recurring) {
      CRM_Core_DAO::executeQuery(
        "
      DELETE FROM civicrm_contact
      WHERE id = %1",
        [1 => [$recurring->contact_id, 'Positive']]
      );
    }
    parent::tearDown();
  }

  protected function addContributionTracking($ctId) {
    $this->ctIds[] = $ctId;
    db_insert('contribution_tracking')
      ->fields(['id' => $ctId])
      ->execute();
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
      $this->contributions[] = $contributions[0];
    }
    return $contributions;
  }

  public function testCreateDistinctContributions() {
    civicrm_initialize();
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);

    $message = new RecurringPaymentMessage($values);
    $message2 = new RecurringPaymentMessage($values);

    $msg = $message->getBody();
    $this->addContributionTracking($msg['contribution_tracking_id']);

    $contributions = $this->importMessage($message);
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
  public function testExpireContributions() {
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);
    $this->importMessage(new RecurringEOTMessage($values));

    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
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
    $values = $this->processRecurringSignup($subscr_id);

    $message = new RecurringPaymentMessage($values);

    $this->addContributionTracking($message->get('contribution_tracking_id'));

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
    $values = $this->processRecurringSignup($subscr_id);

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

    $this->addContributionTracking($messageBody['contribution_tracking_id']);

    $this->consumer->processMessage($messageBody);

    $contributions = wmf_civicrm_get_contributions_from_gateway_id(
      $message->getGateway(),
      $message->getGatewayTxnId()
    );
    $this->contributions[] = $contributions[0];
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
   * Deal with a bad situation caused by PayPal's botched subscr_id migration.
   * See comment on RecurringQueueConsumer::importSubscriptionPayment.
   */
  public function testScrewySubscrId() {
    civicrm_initialize();
    $email = 'test_recur_' . mt_rand() . '@example.org';
    // Set up an old-style PayPal recurring subscription with S-XXXX subscr_id
    $subscr_id = 'S-' . mt_rand();
    $values = $this->processRecurringSignup($subscr_id, [
      'gateway' => 'paypal',
      'email' => $email,
    ]);

    // Import an initial payment with consistent gateway and subscr_id
    $values['email'] = $email;
    $values['gateway'] = 'paypal';
    $oldStyleMessage = new RecurringPaymentMessage($values);
    $this->addContributionTracking($oldStyleMessage->get('contribution_tracking_id'));
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
    $this->contributions[] = $contributions[0];

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

    // Set up token specific values
    $overrides['recurring_payment_token']= mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;

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

    // Clean up
    $this->recurring_contributions[] = $recur_record;
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
}
