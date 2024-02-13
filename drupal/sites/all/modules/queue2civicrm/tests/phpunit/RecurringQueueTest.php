<?php

use Civi\Api4\Activity;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Contribution;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFHelper\ContributionTracking as WMFHelper;
use Civi\WMFQueue\RecurringQueueConsumer;
use Civi\WMFException\WMFException;
use SmashPig\Core\DataStores\DamagedDatabase;
use SmashPig\Core\SequenceGenerators\Factory;
use SmashPig\Core\UtcDate;

/**
 * @group Queue2Civicrm
 * @group Recurring
 */
class RecurringQueueTest extends BaseWmfDrupalPhpUnitTestCase {

  use \Civi\Test\ContactTestTrait;

  /**
   * @var RecurringQueueConsumer
   */
  protected $consumer;

  /**
   * @var DamagedDatabase
   */
  protected $damagedDb;

  public function setUp(): void {
    parent::setUp();
    $this->consumer = new RecurringQueueConsumer(
      'recurring'
    );

    $this->damagedDb = DamagedDatabase::get();

    // Set up for TestMailer
    if (!defined('WMF_UNSUB_SALT')) {
      define('WMF_UNSUB_SALT', 'abc123');
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

  protected function importMessageProcessWithMockConsumer(TransactionMessage $message): void {
    $payment_time = $message->get('date');
    exchange_rate_cache_set('USD', $payment_time, 1);
    $currency = $message->get('currency');
    if ($currency !== 'USD') {
      exchange_rate_cache_set($currency, $payment_time, 3);
    }
    $consumer = $this->getTestRecurringQueueConsumerWithContributionRecurExceptions();
    $consumer->processMessageWithErrorHandling($message->getBody());
  }

  public function testDeclineRecurringUpgrade() {
    $testRecurring = $this->getTestContributionRecurRecords();
    $msg = [
      'txn_type' => "recurring_upgrade_decline",
      'contribution_recur_id' => $testRecurring['id'],
      'contact_id' => $testRecurring['contact_id'],
    ];
    $this->consumer->processMessage($msg);
    $activity = Activity::get(FALSE)
      ->addWhere('source_record_id', '=', $testRecurring['id'])
      ->addWhere('activity_type_id', '=', RecurringQueueConsumer::RECURRING_UPGRADE_DECLINE_ACTIVITY_TYPE_ID)
      ->execute()
      ->last();
    $this->assertEquals($activity['subject'], "Decline recurring update");
  }

  public function testRecurringUpgrade() {
    $testRecurring = $this->getTestContributionRecurRecords();
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
      "usd_original_amount" => round(exchange_rate_convert($msg['currency'], $testRecurring['amount']), 2),
      "native_amount_added" => $additionalAmount,
      "usd_amount_added" => round(exchange_rate_convert($msg['currency'], $additionalAmount), 2),
    ];

    $this->consumer->processMessage($msg);
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

  public function testRecurringDowngrade(): void {
    $testRecurringContributionFor15Dollars = $this->getTestContributionRecurRecords([
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
      "native_currency" => $recurringQueueMessage['currency'],
      "native_original_amount" => $testRecurringContributionFor15Dollars['amount'],
      "usd_original_amount" => round(exchange_rate_convert($recurringQueueMessage['currency'], $testRecurringContributionFor15Dollars['amount']), 2),
      "native_amount_removed" => $changeAmount,
      "usd_amount_removed" => round(exchange_rate_convert($recurringQueueMessage['currency'], $changeAmount), 2),
    ];

    $this->consumer->processMessage($recurringQueueMessage);

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

  public function testCreateDistinctContributions(): void {
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
    $recur_record = RecurHelper::getByGatewaySubscriptionId($msg['gateway'], $subscr_id);

    $this->assertNotEquals(FALSE, $recur_record);

    $this->assertCount(1, $contributions);
    $this->assertEquals($recur_record['id'], $contributions[0]['contribution_recur_id']);
    $this->assertCount(1, $contributions2);
    $this->assertEquals($recur_record['id'], $contributions2[0]['contribution_recur_id']);

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
  public function testCancelContributions(): void {
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
    $this->assertEquals('Cancelled', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur_record['contribution_status_id']));
  }

  /**
   * Test deadlock handling in function that cancels recurrings.
   */
  public function testHandleDeadlockDuringCancelContributions(): void {
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);
    $values['source_enqueued_time'] = UtcDate::getUtcTimestamp();
    $message = new RecurringCancelMessage($values);
    $this->importMessageProcessWithMockConsumer($message);

    $damagedPDO = $this->damagedDb->getDatabase();

    $result = $damagedPDO->query("
    SELECT * FROM damaged
    WHERE gateway = '{$message->getGateway()}'
    AND gateway_txn_id = '{$message->getGatewayTxnId()}'");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    $this->assertCount(1, $rows, 'No rows in damaged db for deadlock');
    $this->assertNotNull($rows[0]['retry_date'], 'Damaged message should have a retry date');
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

  /**
   * Test function adds reason to the recur row.
   */
  public function testCancelPaymentWithReason(): void {
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);
    $this->importMessage(new RecurringCancelWithReasonMessage($values));

    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->ids['Contact'][] = $recur_record['contact_id'];
    $this->assertEquals('Failed: Card declined', $recur_record['cancel_reason']);
    $this->assertEquals('Cancelled', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur_record['contribution_status_id']));
  }

  /**
   * Test cancellation by the ID rather than by the subscr_id
   */
  public function testCancelWithRecurringContributionId(): void {
    $subscr_id = mt_rand();
    $this->processRecurringSignup($subscr_id);
    $recurRecord = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->ids['Contact'][] = $recurRecord['contact_id'];

    $message = new RecurringCancelMessage(['contribution_recur_id' => $recurRecord['id']]);
    $message->unset('subscr_id');
    $this->importMessage($message);

    $recurRecord = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->assertEquals(
      'Cancelled',
      CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recurRecord['contribution_status_id'])
    );
  }

  /**
   * Test deadlock exception in function that expires recurrings.
   */
  public function testHandleDeadlocksInExpireContributions(): void {
    $subscr_id = mt_rand();
    $values = $this->processRecurringSignup($subscr_id);
    $values['source_enqueued_time'] = UtcDate::getUtcTimestamp();
    $message = new RecurringEOTMessage($values);
    $this->importMessageProcessWithMockConsumer($message);

    $damagedPDO = $this->damagedDb->getDatabase();

    $result = $damagedPDO->query("
    SELECT * FROM damaged
    WHERE gateway = '{$message->getGateway()}'
    AND gateway_txn_id = '{$message->getGatewayTxnId()}'");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    $this->assertCount(1, $rows, 'No rows in damaged db for deadlock');
    $this->assertNotNull($rows[0]['retry_date'], 'Damaged message should have a retry date');
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

    $recur_record = RecurHelper::getByGatewaySubscriptionId($contributions[0]['gateway'], $subscr_id);
    $this->assertNotEquals(FALSE, $recur_record);
    $this->assertTrue(is_numeric($recur_record['payment_processor_id']));

    $this->assertEquals(1, count($contributions));
    $this->assertEquals($recur_record['id'], $contributions[0]['contribution_recur_id']);

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

  public function testMissingPredecessor(): void {
    $this->expectExceptionCode(WMFException::MISSING_PREDECESSOR);
    $this->expectException(WMFException::class);
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
    $email = 'notinthedb' . (string) mt_rand() . '@example.com';
    $message = new RecurringPaymentMessage(
      [
        'gateway' => 'paypal_ec',
        'subscr_id' => 'I-' . (string) mt_rand(),
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
   * Ensure non-USD PayPal synthetic start message gets the right
   * currency imported
   */
  public function testPayPalMissingPredecessorNonUSD() {
    $email = 'notinthedb' . (string) mt_rand() . '@example.com';
    $message = new RecurringPaymentMessage(
      [
        'currency' => 'CAD',
        'amount' => 10.00,
        'gateway' => 'paypal_ec',
        'subscr_id' => 'I-' . (string) mt_rand(),
        'email' => $email,
      ]
    );

    $this->importMessage($message);

    // We should have inserted one contribution_recur record
    $recur_records = wmf_civicrm_dao_to_list(CRM_Core_DAO::executeQuery("
      SELECT ccr.*
      FROM civicrm_contribution_recur ccr
      INNER JOIN civicrm_email e on ccr.contact_id = e.contact_id
      WHERE e.email = '$email'
    "));
    $this->assertEquals(1, count($recur_records));

    // ...and it should have the correct currency
    $this->assertEquals('CAD', $recur_records[0]['currency']);
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
      'contribution_tracking_id' => $ctId,
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
   * Test use of API4 in Contribution Tracking in recurring module
   */
  public function testApi4CTinRecurringGet(): void {
    $email = 'test_recur_' . mt_rand() . '@example.org';
    $recur = $this->getTestContributionRecurRecords();
    $contribution = $this->getContribution([
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
    $ctFromResponse = recurring_get_contribution_tracking_id([
      'txn_type' => 'subscr_payment',
      'subscr_id' => $recur['trxn_id'],
      'gateway' => 'paypal',
      'email' => $email,
      'contribution_tracking_id' => NULL,
      'date' => 1564068649,
    ]);

    $this->assertEquals($createTestCT['id'], $ctFromResponse);
    $this->ids['Contribution'][$contribution['id']] = $contribution['id'];
    $this->ids['ContributionRecur'][$contribution['contribution_recur_id']] = $contribution['contribution_recur_id'];
    $this->ids['ContributionTracking'][$ctFromResponse] = $ctFromResponse;
  }

  public function testNoSubscrId(): void {
    $this->expectExceptionCode(WMFException::INVALID_RECURRING);
    $this->expectException(WMFException::class);
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
  public function testRecurringTokenIngenico(): void {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $ct_id = $this->addContributionTracking([
      'form_amount' => 4,
      'utm_source' => 'testytest',
      'language' => 'en',
      'country' => 'US',
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
    $overrides['recurring_payment_token'] = mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['payment_submethod'] = 'visa';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id, $overrides);

    // Get the new token
    $token = wmf_civicrm_get_recurring_payment_token($overrides['gateway'], $overrides['recurring_payment_token']);
    // Check the token was created successfully
    $this->assertEquals($token['token'], $overrides['recurring_payment_token']);

    // Create matching trxn_id
    $trxn_id = 'RECURRING ' . strtoupper(($overrides['gateway'])) . ' ' . $subscr_id;
    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $trxn_id]);
    // Check the record was created successfully
    $this->assertEquals($recur_record['trxn_id'], $trxn_id);
    // The first contribution should be on the start_date
    $this->assertEquals($recur_record['next_sched_contribution_date'], $recur_record['start_date']);

    // Check cycle_day matches the start date
    $this->assertEquals($recur_record['cycle_day'], date('j', $overrides['start_date']));

    // Clean up
    $this->ids['ContributionRecur'][$recur_record['id']] = $recur_record['id'];
    $this->ids['Contact'][$recur_record['contact_id']] = $recur_record['contact_id'];
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
      'country' => 'US',
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
    $overrides['recurring_payment_token'] = mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['payment_submethod'] = 'visa';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id, $overrides);

    // Get the new token
    $token = wmf_civicrm_get_recurring_payment_token($overrides['gateway'], $overrides['recurring_payment_token']);
    // Check that the token belongs to the same donor as the first donation
    $this->assertEquals($firstContribution['contact_id'], $token['contact_id']);
    // Create matching trxn_id
    $trxn_id = 'RECURRING ' . strtoupper(($overrides['gateway'])) . ' ' . $subscr_id;
    $recurRecord = RecurHelper::getByGatewaySubscriptionId($overrides['gateway'], $trxn_id);
    // Check that the recur record belongs to the same donor
    $this->assertEquals($firstContribution['contact_id'], $recurRecord['contact_id']);

    // Clean up
    $this->ids['ContributionRecur'][] = $recurRecord['id'];
  }

  /**
   * Test handling of deadlock exception in function that handles recurring signup
   */
  public function testHandleDeadlockDuringRecurringSignup() {
    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();
    $ct_id = $this->addContributionTracking([
      'form_amount' => 4,
      'utm_source' => 'testytest',
      'language' => 'en',
      'country' => 'US',
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
    $overrides['recurring_payment_token'] = mt_rand();
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['payment_submethod'] = 'visa';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['source_enqueued_time'] = UtcDate::getUtcTimestamp();
    $overrides['contribution_tracking_id'] = $ct_id;

    $values = $overrides + ['subscr_id' => $subscr_id];
    $signup_message = new RecurringSignupMessage($values);
    $subscr_time = $signup_message->get('date');
    exchange_rate_cache_set('USD', $subscr_time, 1);
    exchange_rate_cache_set($signup_message->get('currency'), $subscr_time, 2);

    // Consume the recurring signup with deadlock exception
    $consumer = $this->getTestRecurringQueueConsumerWithContributionRecurExceptions();
    $consumer->processMessageWithErrorHandling($signup_message->getBody());
    $damagedPDO = $this->damagedDb->getDatabase();

    $result = $damagedPDO->query("
    SELECT * FROM damaged
    WHERE gateway = '{$signup_message->getGateway()}'
    AND gateway_txn_id = '{$subscr_id}'");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    $this->assertCount(1, $rows, 'No rows in damaged db for deadlock');
    $this->assertNotNull($rows[0]['retry_date'], 'Damaged message should have a retry date');
  }

  /**
   * Test that the notification email is sent when a updonate recurring subscription is started
   */
  public function testRecurringNotificationEmailSend(): void {
    \Civi::settings()->set('thank_you_add_civimail_records', FALSE);

    // Subscr_id is the same as gateway_txn_id
    $subscr_id = mt_rand();

    // Create the first donation
    $ct_id = $this->addContributionTracking([
      'form_amount' => 4,
      'utm_source' => 'testytest',
      'language' => 'en',
      'country' => 'US',
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

    // Set up token specific values
    $overrides['recurring_payment_token'] = mt_rand();
    $overrides['currency'] = 'CAD';
    $overrides['gateway_txn_id'] = $subscr_id;
    $overrides['user_ip'] = '1.1.1.1';
    $overrides['gateway'] = 'ingenico';
    $overrides['payment_method'] = 'cc';
    $overrides['payment_submethod'] = 'visa';
    $overrides['create_date'] = 1564068649;
    $overrides['start_date'] = 1566732720;
    $overrides['contribution_tracking_id'] = $ct_id;

    $this->processRecurringSignup($subscr_id, $overrides);

    $this->assertEquals(1, $this->getMailingCount());
    $sent = $this->getMailing(0);

    // Check the right email
    $this->assertEquals($messageBody['email'], $sent['to_address']);

    // Check right email content
    $this->assertMatchesRegularExpression('/you donated, and then decided to set up an additional/', $sent['html']);

    // Check the right donation amount
    $this->assertMatchesRegularExpression('/3.00/', $sent['html']);

    // Check the right donation currency, original currency is CAD
    $this->assertMatchesRegularExpression('/CA\$/', $sent['html']);
    // Check the subject.
    // Note this test will move to an extension, at which point this relative path will change.
    $expectedSubject = trim(file_get_contents(
      __DIR__ .
      '/../../../../../default/civicrm/extensions/wmf-civicrm/msg_templates/monthly_convert/monthly_convert.en.subject.txt'
    ));
    $this->assertEquals($expectedSubject, $sent['subject']);
  }

  /**
   * Test use of Auto Rescue message consumption
   */
  public function testRecurringQueueConsumeAutoRescueMessage() {
    $rescueReference = 'MT6S49RV4HNG5S82';
    $orderId = "279.2";
    $recur = $this->getTestContributionRecurRecords([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'contribution_recur_smashpig.rescue_reference' => $rescueReference,
      'invoice_id' => $orderId,
    ]);
    $this->ids['ContributionRecur'][$recur['id']] = $recur['id'];

    $this->getContribution([
      'total_amount' => $recur['amount'],
      'payment_instrument_id:name' => "Credit Card: Visa",
      'contribution_recur_id' => $recur['id'],
      'amount' => $recur['amount'],
      'currency' => $recur['currency'],
      'contact_id' => $recur['contact_id'],
      'receive_date' => date('Y-m-d H:i:s', strtotime('-1 month')),
      'gateway' => 'adyen',
      'trxn_id' => $recur['trxn_id'],
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
    ]);

    $date = time();
    $message = new RecurringPaymentMessage(
      [
        'txn_type' => 'subscr_payment',
        'gateway' => 'adyen',
        'gateway_txn_id' => 'L4X6T3WDS8NKGK82',
        'date' => $date,
        'is_successful_autorescue' => TRUE,
        'rescue_reference' => $rescueReference,
        'currency' => 'USD',
        'amount' => 10,
        'order_id' => $orderId,
        'source_name' => 'CiviCRM',
        'source_type' => 'direct',
        'source_host' => '051a7ac1b08d',
        'source_run_id' => 10315,
        'source_version' => 'unknown',
        'source_enqueued_time' => 1694530827,
      ]
    );
    $contributionsAfterRecurring = $this->importMessage($message);

    // importMessage adds the id to cleanup but it had already been added in getTestContributionRecurRecords
    unset($this->ids['Contact'][$recur['contact_id']]);

    $updatedRecur = ContributionRecur::get(FALSE)
      ->addSelect('*', 'contribution_status_id:name')
      ->addWhere('id', '=', $recur['id'])
      ->execute()
      ->first();

    $this->assertEquals('In Progress', $updatedRecur['contribution_status_id:name']);

    // check that the generated next_sched date is between 27 and 31 days away
    $today = DateTime::createFromFormat("U", $date);
    $nextMonth = new DateTime($updatedRecur['next_sched_contribution_date']);
    $difference = $nextMonth->diff($today)->days;
    $this->assertGreaterThanOrEqual(27, $difference);
    $this->assertLessThanOrEqual(31, $difference);

    $this->assertStringContainsString($orderId, $contributionsAfterRecurring[0]['invoice_id']);
  }

  /**
   * Test handling of deadlock exception in function that imports subscription payment
   */
  public function testHandleDeadlockExceptionSubscriptionPayment() {
    $recur = $this->getTestContributionRecurRecords([
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
    ]);

    $date = time();
    $orderId = "279.2";
    $message = new RecurringPaymentMessage(
      [
        'txn_type' => 'subscr_payment',
        'subscr_id' => $recur['trxn_id'],
        'order_id' => $orderId,
        'contact_id' => $recur['contact_id'],
        'gateway' => 'adyen',
        'gateway_txn_id' => 'L4X6T3WDS8NKGK82',
        'date' => $date,
        'is_auto_rescue_retry' => TRUE,
        'currency' => 'USD',
        'amount' => 10,
        'contribution_recur_id' => 39,
        'payment_instrument_id' => 1,
        'source_name' => 'CiviCRM',
        'source_type' => 'direct',
        'source_host' => '051a7ac1b08d',
        'source_run_id' => 10315,
        'source_version' => 'unknown',
        'source_enqueued_time' => UtcDate::getUtcTimestamp(),
      ]
    );
    $this->importMessageProcessWithMockConsumer($message);

    $damagedPDO = $this->damagedDb->getDatabase();

    $result = $damagedPDO->query("
    SELECT * FROM damaged
    WHERE gateway = '{$message->getGateway()}'
    AND gateway_txn_id = '{$message->getGatewayTxnId()}'");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    $this->assertCount(1, $rows, 'No rows in damaged db for deadlock');
    $this->assertNotNull($rows[0]['retry_date'], 'Damaged message should have a retry date');
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

  /**
   * Create a contribution_recur table row for a test
   *
   * @param array $recurParams
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getTestContributionRecurRecords($recurParams = []): array {
    $contactID = $recurParams['contact_id'] ?? $this->individualCreate();
    $recur = ContributionRecur::create(FALSE)
      ->setValues(array_merge([
        'contact_id' => $contactID,
        'amount' => 10,
        'frequency_interval' => 'month',
        'cycle_day' => date('d'),
        'start_date' => 'now',
        'is_active' => TRUE,
        'contribution_status_id:name' => 'Pending',
        'trxn_id' => 1234,
      ], $recurParams))
      ->execute()
      ->first();
    return $recur;
  }

  /**
   * Create a contribution_recur table row for a test
   *
   * @param array $recurParams
   *
   * @return array
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getContribution(array $recurParams = []): array {
    return Contribution::create(FALSE)->setValues(array_merge([
      'financial_type_id' => RecurHelper::getFinancialTypeForFirstContribution(),
      'total_amount' => 60,
      'receive_date' => 'now',
    ], $recurParams))->execute()->first();
  }

  protected function getTestRecurringQueueConsumerWithContributionRecurExceptions(): RecurringQueueConsumer {
    return new class extends RecurringQueueConsumer {

      public function __construct() {
        parent::__construct('recurring');
      }

      protected function createContributionRecur($params) {
        throw new CRM_Core_Exception('DBException error', 123, ['error_code' => 'deadlock']);
      }

      protected function updateContributionRecur($params) {
        throw new CRM_Core_Exception('DBException error', 123, ['error_code' => 'deadlock']);
      }

    };
  }

}
