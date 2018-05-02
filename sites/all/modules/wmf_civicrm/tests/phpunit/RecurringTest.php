<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 */
class RecurringTest extends BaseWmfDrupalPhpUnitTestCase {

  public static function getInfo() {
    return [
      'name' => 'Recurring',
      'group' => 'Pipeline',
      'description' => 'Checks for recurring functionality',
    ];
  }

  public function setUp() {
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
    // TODO: fixtures
    $result = civicrm_api3('Contact', 'create', [
      'first_name' => 'Testes',
      'contact_type' => 'Individual',
    ]);
    $this->contact_id = $result['id'];

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
    $result = civicrm_api3('ContributionRecur', 'create', $recur_values);

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

  public function testRecurringContributionWithPaymentToken() {
    $fixture = CiviFixtures::createContact();
    CiviFixtures::createPaymentProcessor("test_gateway", $fixture);

    $msg = [
      'contact_id' => $fixture->contact_id,
      'contact_hash' => $fixture->contact_hash,
      'currency' => 'USD',
      'date' => time(),
      'gateway' => "test_gateway",
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      // recurring contribution payment token fields below
      'recurring_payment_token' => 'TEST-RECURRING-TOKEN-' . mt_rand(),
      'recurring' => 1,
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

    //confirm payment token persisted matches original $msg token
    $payment_token_id = $recurring_record->payment_token_id;
    $payment_token_result = civicrm_api3('PaymentToken', 'getSingle', [
      'id' => $payment_token_id,
    ]);
    $this->assertEquals($msg['recurring_payment_token'], $payment_token_result['token']);

    //clean up recurring contribution records using fixture tear down destruct process
    $fixture->contribution_id = $contribution['id'];
    $fixture->contribution_recur_id = $recurring_record->id;

    //clean up test payment token record
    civicrm_api3('PaymentToken', 'delete', [
      'id' => $payment_token_id,
    ]);
  }
}
