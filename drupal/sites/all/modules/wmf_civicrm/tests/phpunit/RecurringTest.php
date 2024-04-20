<?php

use Civi\Api4\PaymentToken;
use Civi\WMFException\WMFException;
use Civi\WMFHelper\ContributionRecur;
use Civi\WMFHelper\ContributionRecur as RecurHelper;
use Civi\WMFQueueMessage\RecurDonationMessage;
use Statistics\Exception\StatisticsCollectorException;

/**
 * @group Pipeline
 * @group WmfCivicrm
 * @group Recurring
 */
class RecurringTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Test next_sched_contribution calculation
   *
   * @dataProvider nextSchedProvider
   */
  public function testNextScheduled($now, $cycle_day, $expected_next_sched): void {
    $msg = [
      'cycle_day' => $cycle_day,
      'frequency_interval' => 1,
    ];
    $nowstamp = strtotime($now);
    $calculated_next_sched = wmf_civicrm_get_next_sched_contribution_date_for_month($msg, $nowstamp);

    $this->assertEquals($expected_next_sched, $calculated_next_sched);
  }

  public function nextSchedProvider(): array {
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

  /**
   * Test functionality in RecurHelper::getByGatewaySubscriptionId.
   *
   * @return void
   */
  public function testGetGatewaySubscription(): void {
    $contactID = $this->createIndividual();

    $subscription_id_1 = 'SUB_FOO-' . mt_rand();
    $recurValues = [
      'contact_id' => $contactID,
      'amount' => '1.21',
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
      'next_sched_contribution' => date('Y-m-d', strtotime('+1 month')),
      'installments' => 0,
      'processor_id' => 1,
      'currency' => 'USD',
      'trxn_id' => "RECURRING TESTGATEWAY {$subscription_id_1}",
    ];
    $this->callAPISuccess('ContributionRecur', 'create', $recurValues);

    $record = RecurHelper::getByGatewaySubscriptionId('TESTGATEWAY', $subscription_id_1);

    $this->assertTrue(is_array($record), 'Will match on full unique subscription ID');
    $this->assertEquals($recurValues['trxn_id'], $record['trxn_id']);

    $subscription_id_2 = 'SUB_FOO-' . mt_rand();
    $recurValues['trxn_id'] = $subscription_id_2;
    $this->createTestEntity('ContributionRecur', $recurValues);

    $record = RecurHelper::getByGatewaySubscriptionId('TESTGATEWAY', $subscription_id_2);

    $this->assertTrue(is_array($record),
      'Will match raw subscription ID');
    $this->assertEquals($recurValues['trxn_id'], $record['trxn_id']);
  }

  public function testRecurringContributionWithoutPaymentToken(): void {
    $msg = [
      'first_name' => 'Peter',
      'last_name' => 'Mouse',
      'email' => 'abernathy@sweetwater.org',
      'currency' => 'USD',
      'date' => time(),
      'gateway' => 'test_gateway',
      'gateway_txn_id' => mt_rand(),
      'gross' => '1.23',
      'payment_method' => 'cc',
      'payment_submethod' => 'visa',
      'subscr_id' => 'abc123123',
      'recurring' => 1,
      'financial_type_id' => ContributionRecur::getFinancialTypeForFirstContribution(),
    ];

    // import old-style recurring contribution message
    // this should result in a new contribution and recurring contribution.
    $this->processMessage($msg, 'Donation', 'test');
    $contribution = $this->getContributionForMessage($msg);

    $this->assertEquals($msg['gross'], $contribution['total_amount']);
    $this->assertNotEmpty($contribution['contribution_recur_id']);
    $this->assertEquals(
      strtoupper("RECURRING {$msg['gateway']} {$msg['gateway_txn_id']}"),
      $contribution['trxn_id']
    );

    // confirm recurring contribution record was created correctly
    $recurring_record = $this->getRecurringContribution($msg['subscr_id']);
    $this->assertEquals(1, $recurring_record['processor_id']);
    $this->assertEquals($msg['subscr_id'], $recurring_record['trxn_id']);
    $this->assertEquals($contribution['contact_id'], $recurring_record['contact_id']);
    $this->assertEquals($msg['gross'], $recurring_record['amount']);
    $this->assertEquals($msg['currency'], $recurring_record['currency']);
  }

  /**
   * @throws CRM_Core_Exception
   */
  public function testRecurringContributionWithPaymentToken(): void {
    $this->createIndividual(['hash' => 'mousy_mouse']);
    $this->createPaymentProcessor();

    $msg = [
      'contact_id' => $this->ids['Contact']['default'],
      'contact_hash' => 'mousy_mouse',
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
    ];

    //import contribution message containing populated recurring and recurring_payment_token fields
    //this should result in a new contribution, recurring contribution and payment token record.
    $this->processMessage($msg, 'Donation', 'test');
    $contribution = $this->getContributionForMessage($msg);

    $this->assertEquals($this->ids['Contact']['default'], $contribution['contact_id']);
    $this->assertEquals($msg['gross'], $contribution['total_amount']);
    $this->assertNotEmpty($contribution['contribution_recur_id']);
    $this->assertEquals(strtoupper("RECURRING {$msg['gateway']} {$msg['gateway_txn_id']}"),
      $contribution['trxn_id']);

    //confirm recurring contribution record was created with associated payment token record
    $recurringContribution = $this->getRecurringContribution($msg['gateway_txn_id']);
    $this->assertNotEmpty($recurringContribution['payment_token_id']);
    $this->assertNotEmpty($recurringContribution['payment_processor_id']);

    //confirm payment token persisted matches original $msg token
    $paymentToken = PaymentToken::get(FALSE)
      ->addWhere('id', '=', $recurringContribution['payment_token_id'])
      ->execute()->first();
    $this->assertEquals($msg['recurring_payment_token'], $paymentToken['token']);
    $this->assertEquals(
      $recurringContribution['payment_processor_id'],
      $paymentToken['payment_processor_id']
    );
    $this->assertEquals($msg['user_ip'], $paymentToken['ip_address']);
  }

  /**
   */
  public function testSecondRecurringContributionWithPaymentToken(): void {
    $this->createIndividual();
    $this->createPaymentProcessor();
    $token = 'TEST-RECURRING-TOKEN-' . mt_rand();

    $firstMessage = [
      'contact_id' => $this->ids['Contact']['default'],
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

    //import contribution message containing populated recurring and recurring_payment_token fields
    //this should result in a new contribution, recurring contribution and payment token record.
    $this->processDonationMessage($firstMessage);

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
    ];

    $this->processDonationMessage($secondMessage);
    $secondContribution = $this->getContributionForMessage($secondMessage);

    $this->assertEquals($this->ids['Contact']['default'], $secondContribution['contact_id']);
    $this->assertEquals($secondMessage['gross'], $secondContribution['total_amount']);
    $this->assertNotEmpty($secondContribution['contribution_recur_id']);
    $this->assertEquals(strtoupper("RECURRING {$secondMessage['gateway']} {$secondMessage['gateway_txn_id']}"),
      $secondContribution['trxn_id']);

    //confirm recurring contribution record was created with same payment token record
    $firstRecurringRecord = $this->getRecurringContribution($firstMessage['gateway_txn_id']);
    $secondRecurringRecord = $this->getRecurringContribution($secondMessage['gateway_txn_id']);
    $this->assertNotEquals($firstRecurringRecord['id'], $secondRecurringRecord['id']);
    $this->assertEquals(
      $firstRecurringRecord['payment_token_id'],
      $secondRecurringRecord['payment_token_id']
    );

    $this->assertEquals(
      $firstRecurringRecord['payment_processor_id'],
      $secondRecurringRecord['payment_processor_id']
    );
    $this->assertEquals('Cash', CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'financial_type_id', $firstRecurringRecord['financial_type_id']));
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
      'financial_type_id' => ContributionRecur::getFinancialTypeForFirstContribution(),
    ];

    //import contribution message containing populated recurring and recurring_payment_token fields
    //this should result in a new contribution, recurring contribution and payment token record.
    $this->processDonationMessage($firstMessage);
    $firstContribution = $this->getContributionForMessage($firstMessage);

    //check that no_thank_you is not set to recurring for the first payment
    $this->assertNotEquals('recurring', $firstContribution['contribution_extra.no_thank_you']);

    $firstRecurringRecord =
      RecurHelper::getByGatewaySubscriptionId('test_gateway',
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
      'contribution_recur_id' => $firstRecurringRecord['id'],
      'recurring' => 1,
    ];

    $secondContribution = $this->messageImport($secondMessage);
    $this->ids['Contact'][$secondContribution['contact_id']] = $secondContribution['contact_id'];

    $secondContributionExtra =
      wmf_civicrm_get_contributions_from_contribution_id($secondContribution['id']);

    //check that no_thank_you is set to recurring for the second payment
    $this->assertEquals('recurring', $secondContributionExtra[0]['no_thank_you']);
  }

  /**
   * Test confirming that a recurring payment leads to a financial type of "Recurring Gift"
   *
   * @throws CRM_Core_Exception
   * @throws WMFException
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
    $message = new RecurDonationMessage($firstMessage);
    $msg = $message->normalize();

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

  /**
   * @param string $gatewayTxnID
   * @return array|null
   */
  public function getRecurringContribution(string $gatewayTxnID): ?array {
    try {
      return \Civi\Api4\ContributionRecur::get(FALSE)
        ->addWhere('trxn_id', '=', $gatewayTxnID)
        ->execute()->first();
    }
    catch (CRM_Core_Exception $e) {
      $this->fail('failed recurring contribution lookup');
    }
  }

}
