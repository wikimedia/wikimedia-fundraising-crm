<?php

use SmashPig\Core\DataStores\QueueWrapper;
use SmashPig\CrmLink\FinalStatus;
use SmashPig\CrmLink\Messages\SourceFields;

/**
 * @group GlobalCollect
 */
class RecurringGlobalCollectTest extends BaseWmfDrupalPhpUnitTestCase {

  protected $subscriptionId;

  protected $amount;

  protected $contributions;

  protected $contactId;

  protected $contributionRecurId;

  function setUp() {
    parent::setUp();
    civicrm_initialize();

    global $wgDonationInterfaceGatewayAdapters,
           $wgDonationInterfaceForbiddenCountries,
           $wgDonationInterfacePriceFloor,
           $wgDonationInterfacePriceCeiling;

    $wgDonationInterfaceGatewayAdapters['globalcollect'] = 'TestingGlobalCollectAdapter';
    $wgDonationInterfaceForbiddenCountries = [];
    $wgDonationInterfacePriceFloor = 1;
    $wgDonationInterfacePriceCeiling = 10000;

    $this->subscriptionId = 'SUB-FOO-' . mt_rand();
    $this->amount = '1.12';

    $this->contributions = [];

    $result = civicrm_api3('Contact', 'create', [
      'first_name' => 'Testes',
      'contact_type' => 'Individual',
    ]);
    $this->contactId = $result['id'];

    $result = civicrm_api3('ContributionRecur', 'create', [
      'contact_id' => $this->contactId,
      'amount' => $this->amount,
      'frequency_interval' => 1,
      'frequency_unit' => 'month',
      'next_sched_contribution' => wmf_common_date_unix_to_civicrm(strtotime('+1 month')),
      'installments' => 0,
      'processor_id' => 1,
      'currency' => 'USD',
      'trxn_id' => "RECURRING GLOBALCOLLECT {$this->subscriptionId}",
    ]);
    $this->contributionRecurId = $result['id'];

    $result = civicrm_api3('Contribution', 'create', [
      'contact_id' => $this->contactId,
      'contribution_recur_id' => $this->contributionRecurId,
      'currency' => 'USD',
      'total_amount' => $this->amount,
      'contribution_type' => 'Cash',
      'payment_instrument' => 'Credit Card',
      'trxn_id' => 'RECURRING GLOBALCOLLECT STUB_ORIG_CONTRIB-' . mt_rand(),
    ]);
    $this->contributions[] = $result['id'];
    $tracking = [
      'utm_source' => '..rcc',
      'utm_medium' => 'civicrm',
      'ts' => wmf_common_date_unix_to_sql(strtotime('now')),
      'contribution_id' => $result['id'],
    ];
    TestingGlobalCollectAdapter::setDummyGatewayResponseCode('recurring-OK');
    wmf_civicrm_insert_contribution_tracking($tracking);
  }

  public function tearDown() {
    parent::tearDown();
    $this->cleanUpContact($this->contactId);
    TestingGlobalCollectAdapter::setDummyGatewayResponseCode(NULL);
  }

  function testMessageSent() {
    recurring_globalcollect_charge($this->contributionRecurId);

    $message = QueueWrapper::getQueue('donations')->pop();
    SourceFields::removeFromMessage($message);
    $this->assertNotNull($message);
    $expected = [
      'amount' => '1.12',
      'effort_id' => '2',
      'order_id' => $this->subscriptionId,
      'currency_code' => 'USD',
      'financial_type_id' => '5',
      'contribution_type_id' => '5',
      'payment_instrument_id' => '1',
      'gateway' => 'globalcollect',
      'gross' => '1.12',
      'currency' => 'USD',
      'gateway_txn_id' => $this->subscriptionId . '-2',
      'payment_method' => 'cc',
      'contribution_recur_id' => $this->contributionRecurId,
      'recurring' => TRUE,
    ];
    $this->assertArraySubset($expected, $message);
    // Now try consuming the message and make sure it looks good
    wmf_civicrm_contribution_message_import($message);
    $contributions = civicrm_api3('Contribution', 'get', [
      'contact_id' => $this->contactId,
    ]);
    // Should have 1 from setup plus the new one
    $this->assertEquals(2, count($contributions['values']));
    foreach ($contributions['values'] as $contribution) {
      if ($contribution['id'] == $this->contributions[0]) {
        // Skip assertions on the synthetic original contribution
        continue;
      }
      $this->assertEquals(1,
        preg_match("/^RECURRING GLOBALCOLLECT {$this->subscriptionId}-2\$/", $contribution['trxn_id']));
    }
  }

  public function testRecurringCharge() {
    $init = [
      'contribution_tracking_id' => mt_rand(),
      'amount' => '2345',
      'effort_id' => 2,
      'order_id' => '9998890004',
      'currency' => 'EUR',
      'payment_method' => 'cc',
    ];
    $gateway = DonationInterfaceFactory::createAdapter('globalcollect', $init);

    $result = $gateway->do_transaction('Recurring_Charge');

    $this->assertTrue($result->getCommunicationStatus());
    $this->assertRegExp('/SET_PAYMENT/', $result->getRawResponse());
  }

  /**
   * Can make a recurring payment
   *
   * @covers GlobalCollectAdapter::transactionRecurring_Charge
   */
  public function testDeclinedRecurringCharge() {
    $init = [
      'contribution_tracking_id' => mt_rand(),
      'amount' => '2345',
      'effort_id' => 2,
      'order_id' => '9998890004',
      'currency' => 'EUR',
      'payment_method' => 'cc',
    ];
    $gateway = DonationInterfaceFactory::createAdapter('globalcollect', $init);

    TestingGlobalCollectAdapter::setDummyGatewayResponseCode('recurring-declined');

    $result = $gateway->do_transaction('Recurring_Charge');

    $this->assertRegExp('/GET_ORDERSTATUS/', $result->getRawResponse(),
      'Stopped after GET_ORDERSTATUS.');
    $this->assertEquals(2, count($gateway->curled),
      'Expected 2 API calls');
    $this->assertEquals(FinalStatus::FAILED, $gateway->getFinalStatus());
  }

  /**
   * Throw errors if the payment is incomplete
   *
   * @covers GlobalCollectAdapter::transactionRecurring_Charge
   */
  public function testRecurringTimeout() {
    $init = [
      'contribution_tracking_id' => mt_rand(),
      'amount' => '2345',
      'effort_id' => 2,
      'order_id' => '9998890004',
      'currency' => 'EUR',
      'payment_method' => 'cc',
    ];
    $gateway = DonationInterfaceFactory::createAdapter('globalcollect', $init);

    TestingGlobalCollectAdapter::setDummyGatewayResponseCode('recurring-timeout');

    $result = $gateway->do_transaction('Recurring_Charge');

    $this->assertFalse($result->getCommunicationStatus());
    $this->assertRegExp('/GET_ORDERSTATUS/', $result->getRawResponse());
    // FIXME: This is a little funky--the transaction is actually pending-poke.
    $this->assertEquals(FinalStatus::FAILED, $gateway->getFinalStatus());
  }

  /**
   * Can resume a recurring payment
   *
   * @covers GlobalCollectAdapter::transactionRecurring_Charge
   */
  public function testRecurringResume() {
    $init = [
      'contribution_tracking_id' => mt_rand(),
      'amount' => '2345',
      'effort_id' => 2,
      'order_id' => '9998890004',
      'currency' => 'EUR',
      'payment_method' => 'cc',
    ];
    $gateway = DonationInterfaceFactory::createAdapter('globalcollect', $init);

    TestingGlobalCollectAdapter::setDummyGatewayResponseCode('recurring-resume');

    $result = $gateway->do_transaction('Recurring_Charge');

    $this->assertTrue($result->getCommunicationStatus());
    $this->assertRegExp('/SET_PAYMENT/', $result->getRawResponse());
  }

  /**
   * Recover from missing ct_ids on all associated contributions
   */
  public function testBackfillContributionTracking() {
    $id_list = implode(',', $this->contributions);

    $dbs = wmf_civicrm_get_dbs();
    $dbs->push('default');
    $query = "DELETE FROM {contribution_tracking} WHERE contribution_id IN( $id_list )";
    db_query($query);
    $contribution_tracking_id = recurring_get_contribution_tracking_id([
      'txn_type' => 'subscr_payment',
      'subscr_id' => $this->subscriptionId,
      'payment_date' => strtotime("now"),
    ]);
    $this->assertNotEmpty($contribution_tracking_id);
  }

  /**
   * Tests to make sure that certain error codes returned from GC will
   * trigger subscription cancellation, even if retryable errors also exist.
   *
   * @dataProvider mcNoRetryCodeProvider
   */
  public function testNoMastercardFinesForRepeatOnBadCodes($code) {
    TestingGlobalCollectAdapter::setDummyGatewayResponseCode([
      'recurring-declined', // for the DO_PAYMENT call
      $code // for the GET_ORDERSTATUS call
    ]);

    $exceptioned = FALSE;
    try {
      recurring_globalcollect_charge($this->contributionRecurId);
    } catch (WmfException $e) {
      $this->assertEquals('PAYMENT_FAILED', $e->type);
      $exceptioned = TRUE;
    }
    $this->assertTrue($exceptioned);

    $contributions = civicrm_api3('Contribution', 'get', [
      'contact_id' => $this->contactId,
    ]);
    // Should still just have the 1 from setUp
    $this->assertEquals(1, count($contributions['values']));
    $contributionRecur = civicrm_api3('ContributionRecur', 'getSingle',
      ['contact_id' => $this->contactId]
    );
    $cancelledStatus = civicrm_api_contribution_status('Cancelled');
    $this->assertEquals(
      $cancelledStatus, $contributionRecur['contribution_status_id']
    );
    $this->assertNotEmpty($contributionRecur['cancel_date']);
    $this->assertTrue(empty($contributionRecur['next_sched_contribution_date']));
    $this->assertTrue(empty($contributionRecur['failure_retry_date']));
  }

  /**
   * Transaction codes for GC and GC orphan adapters not to be retried
   * on pain of $1000+ fines by Mastercard
   */
  public function mcNoRetryCodeProvider() {
    return [
      ['430260'],
      ['430306'],
      ['430330'],
      ['430354'],
      ['430357'],
    ];
  }
}
