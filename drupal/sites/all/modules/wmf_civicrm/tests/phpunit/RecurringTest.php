<?php


/**
 * @group Pipeline
 * @group WmfCivicrm
 * @group Recurring
 */
class RecurringTest extends BaseWmfDrupalPhpUnitTestCase {

  public static function getInfo() {
    return [
      'name' => 'Recurring',
      'group' => 'Pipeline',
      'description' => 'Checks for recurring functionality',
    ];
  }

  public function setUp(): void {
    parent::setUp();
    civicrm_initialize();
  }

  /**
   * Test next_sched_contribution calculation
   *
   * @dataProvider nextSchedProvider
   */
  public function testNextScheduled($now, $cycle_day, $expected_next_sched) {
    $msg = [
      'cycle_day' => $cycle_day,
      'frequency_interval' => 1,
    ];
    $nowstamp = strtotime($now);
    $calculated_next_sched = wmf_civicrm_get_next_sched_contribution_date_for_month($msg, $nowstamp);

    $this->assertEquals($expected_next_sched, $calculated_next_sched);
  }

  public function nextSchedProvider() {
    return [
      ['2014-06-01T00:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T01:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T02:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T03:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T04:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T05:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T06:59:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T07:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T07:01:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T08:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T09:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T13:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T14:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T15:00:00Z', '1', '2014-07-01 00:00:00'],
      ['2014-06-01T16:00:00Z', '1', '2014-07-01 00:00:00'],
    ];
  }

  public function testGetGatewaySubscription() {
    $contactID = $this->createTestContact([
      'first_name' => 'Testes',
      'contact_type' => 'Individual',
    ]);
    $this->contact_id = $contactID;

    $subscription_id_1 = 'SUB_FOO-' . mt_rand();
    $recur_values = [
      'contact_id' => $this->contact_id,
      'amount' => '1.21',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
      'next_sched_contribution' => wmf_common_date_unix_to_civicrm(strtotime('+1 month')),
      'installments' => 0,
      'processor_id' => 1,
      'currency' => 'USD',
      'trxn_id' => "RECURRING TESTGATEWAY {$subscription_id_1}",
    ];
    $this->callAPISuccess('ContributionRecur', 'create', $recur_values);

    $record = wmf_civicrm_get_gateway_subscription('TESTGATEWAY', $subscription_id_1);

    $this->assertTrue(is_object($record),
      'Will match on full unique subscription ID');
    $this->assertEquals($recur_values['trxn_id'], $record->trxn_id);

    $subscription_id_2 = 'SUB_FOO-' . mt_rand();
    $recur_values['trxn_id'] = $subscription_id_2;
    $result = civicrm_api3('ContributionRecur', 'create', $recur_values);

    $record = wmf_civicrm_get_gateway_subscription('TESTGATEWAY', $subscription_id_2);

    $this->assertTrue(is_object($record),
      'Will match raw subscription ID');
    $this->assertEquals($recur_values['trxn_id'], $record->trxn_id);
  }

  public function testRecurringContributionWithoutPaymentToken() {
    // Bypass all the stuff generated in create()
    $fixture = new CiviFixtures();

    $msg = [
      'first_name' => 'Peter',
      'last_name' => 'Abernathy',
      'email' => 'abernathy@sweetwater.org',
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'subscr_id' => 'aslkdjalksd123123',
      'recurring' => 1,
      'financial_type_id' => 'Cash',
    ];

    // import old-style recurring contribution message
    // this should result in a new contribution and recurring contribution.
    $contribution = wmf_civicrm_contribution_message_import($msg);

    $this->assertEquals($msg['gross'], $contribution['total_amount']);
    $this->assertNotEmpty($contribution['contribution_recur_id']);
    $this->assertEquals(
      strtoupper("RECURRING {$msg['gateway']} {$msg['gateway_txn_id']}"),
      $contribution['trxn_id']
    );

    // confirm recurring contribution record was created correctly
    $recurring_record = wmf_civicrm_get_gateway_subscription(
      'test_gateway', $msg['subscr_id']
    );
    $this->assertEquals(1, $recurring_record->processor_id);
    $this->assertEquals($msg['subscr_id'], $recurring_record->trxn_id);
    $this->assertEquals($contribution['contact_id'], $recurring_record->contact_id);
    $this->assertEquals($msg['gross'], $recurring_record->amount);
    $this->assertEquals($msg['currency'], $recurring_record->currency);

    // clean up records using fixture tear down destruct process
    $fixture->contact_id = $contribution['contact_id'];
    $fixture->contribution_id = $contribution['id'];
    $fixture->contribution_recur_id = $recurring_record->id;
  }

  public function testRecurringContributionWithPaymentToken() {
    $fixture = CiviFixtures::createContact();
    $this->createPaymentProcessor();

    $msg = [
      'contact_id' => $fixture->contact_id,
      'contact_hash' => $fixture->contact_hash,
      'currency' => 'USD',
      'date' => time(),
      'gateway' => "test_gateway",
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      // recurring contribution payment token fields below
      'recurring_payment_token' => 'TEST-RECURRING-TOKEN-' . mt_rand(),
      'recurring' => 1,
      'user_ip' => '12.34.56.78',
      'financial_type_id' => 'Cash',
    ];

    //import contribution message containing populated recurring and recurring_payment_token fields
    //this should result in a new contribution, recurring contribution and payment token record.
    $contribution = wmf_civicrm_contribution_message_import($msg);

    $this->assertEquals($fixture->contact_id, $contribution['contact_id']);
    $this->assertEquals($msg['gross'], $contribution['total_amount']);
    $this->assertNotEmpty($contribution['contribution_recur_id']);
    $this->assertEquals(strtoupper("RECURRING {$msg['gateway']} {$msg['gateway_txn_id']}"),
      $contribution['trxn_id']);

    //confirm recurring contribution record was created with associated payment token record
    $recurring_record = wmf_civicrm_get_gateway_subscription("test_gateway", $msg['gateway_txn_id']);
    $this->assertNotEmpty($recurring_record->payment_token_id);
    $this->assertNotEmpty($recurring_record->payment_processor_id);

    //confirm payment token persisted matches original $msg token
    $payment_token_id = $recurring_record->payment_token_id;
    $payment_token_result = civicrm_api3('PaymentToken', 'getSingle', [
      'id' => $payment_token_id,
    ]);
    $this->assertEquals($msg['recurring_payment_token'], $payment_token_result['token']);
    $this->assertEquals(
      $recurring_record->payment_processor_id,
      $payment_token_result['payment_processor_id']
    );
    $this->assertEquals($msg['user_ip'], $payment_token_result['ip_address']);

    //clean up recurring contribution records using fixture tear down destruct process
    $fixture->contribution_id = $contribution['id'];
    $fixture->contribution_recur_id = $recurring_record->id;

    //clean up test payment token record
    civicrm_api3('PaymentToken', 'delete', [
      'id' => $payment_token_id,
    ]);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\WMFException\WMFException
   * @throws \Statistics\Exception\StatisticsCollectorException
   */
  public function testSecondRecurringContributionWithPaymentToken(): void {
    $fixture = CiviFixtures::createContact();
    $this->createPaymentProcessor();
    $token = 'TEST-RECURRING-TOKEN-' . mt_rand();

    $firstMessage = [
      'contact_id' => $fixture->contact_id,
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      // recurring contribution payment token fields below
      'recurring_payment_token' => $token,
      'recurring' => 1,
      'user_ip' => '12.34.56.78',
      'financial_type_id' => 'Cash',
    ];

    //import contribution message containing populated recurring and recurring_payment_token fields
    //this should result in a new contribution, recurring contribution and payment token record.
    $firstContribution = wmf_civicrm_contribution_message_import($firstMessage);

    $secondMessage = [
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '2.34',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'recurring_payment_token' => $token,
      'recurring' => 1,
      'user_ip' => '12.34.56.78',
      'financial_type_id' => 'Cash',
    ];

    $secondContribution = wmf_civicrm_contribution_message_import($secondMessage);

    $this->assertEquals($fixture->contact_id, $secondContribution['contact_id']);
    $this->assertEquals($secondMessage['gross'], $secondContribution['total_amount']);
    $this->assertNotEmpty($secondContribution['contribution_recur_id']);
    $this->assertEquals(strtoupper("RECURRING {$secondMessage['gateway']} {$secondMessage['gateway_txn_id']}"),
      $secondContribution['trxn_id']);

    //confirm recurring contribution record was created with same payment token record
    $firstRecurringRecord = wmf_civicrm_get_gateway_subscription(
      'test_gateway', $firstMessage['gateway_txn_id']
    );
    $secondRecurringRecord = wmf_civicrm_get_gateway_subscription(
      'test_gateway', $secondMessage['gateway_txn_id']
    );
    $this->assertNotEquals($firstRecurringRecord->id, $secondRecurringRecord->id);
    $this->assertEquals(
      $firstRecurringRecord->payment_token_id,
      $secondRecurringRecord->payment_token_id
    );

    $this->assertEquals(
      $firstRecurringRecord->payment_processor_id,
      $secondRecurringRecord->payment_processor_id
    );
    $this->assertEquals('Cash', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'financial_type_id', $firstRecurringRecord->financial_type_id));
    //clean up recurring contribution records using fixture tear down destruct process
    $fixture->contribution_id = $firstContribution['id'];
    $fixture->contribution_recur_id = $firstRecurringRecord->id;

    civicrm_api3('Contribution', 'delete', [
      'id' => $secondContribution['id'],
    ]);

    civicrm_api3('ContributionRecur', 'delete', [
      'id' => $secondRecurringRecord->id,
    ]);

    //clean up test payment token record
    civicrm_api3('PaymentToken', 'delete', [
      'id' => $firstRecurringRecord->payment_token_id,
    ]);
  }

  /**
   * Test no_thank_you field being set for recurring after first payment
   *
   * @group nothankyou
   */
  public function testRecurringNoThankYou(): void {
    $contactID = $this->createIndividual();
    $this->createPaymentProcessor();
    $token = 'TEST-RECURRING-TOKEN-' . mt_rand();

    // create the recurring payment
    $firstMessage = [
      'contact_id' => $contactID,
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      // recurring contribution payment token fields below
      'recurring_payment_token' => $token,
      'recurring' => 1,
      'user_ip' => '12.34.56.78',
      'financial_type_id' => 'Cash',
    ];

    //import contribution message containing populated recurring and recurring_payment_token fields
    //this should result in a new contribution, recurring contribution and payment token record.
    $firstContribution = $this->messageImport($firstMessage);

    $firstContributionExtra =
      wmf_civicrm_get_contributions_from_contribution_id($firstContribution['id']);

    //check that no_thank_you is not set to recurring for the first payment
    $this->assertNotEquals($firstContributionExtra[0]['no_thank_you'],
      'recurring');

    $firstRecurringRecord =
      wmf_civicrm_get_gateway_subscription('test_gateway',
        $firstMessage['gateway_txn_id']);

    //charge the second payment
    $secondMessage = [
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '2.34',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'contribution_recur_id' => $firstRecurringRecord->id,
      'recurring' => 1,
    ];

    $secondContribution = $this->messageImport($secondMessage);
    $this->ids['Contact'][$secondContribution['contact_id']] = $secondContribution['contact_id'];

    $secondContributionExtra =
      wmf_civicrm_get_contributions_from_contribution_id($secondContribution['id']);

    //check that no_thank_you is set to recurring for the second payment
    $this->assertEquals($secondContributionExtra[0]['no_thank_you'],
      'recurring');

    civicrm_api3('Contribution', 'delete', [
      'id' => $secondContribution['id'],
    ]);

    //clean up test payment token record
    civicrm_api3('PaymentToken', 'delete', [
      'id' => $firstRecurringRecord->payment_token_id,
    ]);
  }

  /**
   * Test confirming that a recurring payment leads to a financial type of "Recurring Gift"
   *
   * @group recurring
   */
  public function testFirstRecurringHasFinancialType(): void {
    $contactID = $this->createIndividual();
    $this->createPaymentProcessor();
    $token = 'TEST-RECURRING-TOKEN-' . mt_rand();

    // create the recurring payment
    $firstMessage = [
      'contact_id' => $contactID,
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
    // recurring contribution payment token fields below
      'recurring_payment_token' => $token,
      'recurring' => 1,
      'user_ip' => '12.34.56.78',
    ];

    // Normalize a recurring payment initiation message, this should lead to the resulting message
    // having a Financial Type of "Recurring Gift"
    $msg = wmf_civicrm_normalize_msg($firstMessage);

    $this->assertEquals($msg['financial_type_id'], CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', "Recurring Gift"));
  }

  /**
   * Ensure the test payment processor exists.
   */
  protected function createPaymentProcessor(): void {
    $existing = $this->callAPISuccess('PaymentProcessor', 'get', ['name' => 'test_gateway']);
    if (!$existing['count']) {
      $this->ids['PaymentProcessor'][0] = $this->callAPISuccess('PaymentProcessor', 'create', [
        'payment_processor_type_id' => 1,
        'name' => 'test_gateway',
        'domain_id' => CRM_Core_Config::domainID(),
        'is_default' => 1,
        'is_active' => 1,
      ])['id'];
    }
  }

}
