<?php

use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionTracking;
use Civi\Api4\Contribution;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFQueue\RecurringQueueConsumer;
use Civi\WMFException\WMFException;
use SmashPig\Core\DataStores\DamagedDatabase;
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
    $contributionTracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_tracking_id'])
      ->execute()->first();
    $this->assertEquals(
      $contributions[0]['id'],
      $contributionTracking['contribution_id']
    );
    $contributions2 = $this->importMessage($message2);
    $this->consumeCtQueue();

    $contributionTracking = ContributionTracking::get(FALSE)
      ->addWhere('id', '=', $msg['contribution_tracking_id'])
      ->execute()->first();

    // The ct_id record should still link to the first contribution
    $this->assertEquals(
      $contributions[0]['id'],
      $contributionTracking['contribution_id']
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
    $this->assertEquals(1, $emails['count']);
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
        'contribution_recur_id' => $recur['id'],
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
   * Test reactivating recurrings.
   */
  public function testPaymentAfterCancelContributions(): void {
    $subscr_id = mt_rand();

    // Create recur record
    $values = $this->processRecurringSignup($subscr_id);
    // Cancel recur record
    $this->importMessage(new RecurringCancelMessage($values));
    // Verify record is cancelled
    $cancelled_recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->assertEquals('Cancelled', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $cancelled_recur_record['contribution_status_id']));
    // Import new Subscription payment on cancelled recur record
    $this->importMessage(new RecurringPaymentMessage($values));
    $recur_record = $this->callAPISuccessGetSingle('ContributionRecur', ['trxn_id' => $subscr_id]);
    $this->assertNotEmpty($recur_record['payment_processor_id']);
    $this->assertTrue(empty($recur_record['failure_retry_date']));
    $this->assertEquals('In Progress', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', $recur_record['contribution_status_id']));
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
    $contactID = $recurParams['contact_id'] ?? $this->individualCreate(['preferred_language' => 'fr_FR']);
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
