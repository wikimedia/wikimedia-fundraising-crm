<?php

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
		$wgDonationInterfaceForbiddenCountries = array();
		$wgDonationInterfacePriceFloor = 1;
		$wgDonationInterfacePriceCeiling = 10000;

		$this->subscriptionId = 'SUB-FOO-' . mt_rand();
		$this->amount = '1.12';

		$this->contributions = array();

		$result = civicrm_api3( 'Contact', 'create', array(
			'first_name' => 'Testes',
			'contact_type' => 'Individual',
		) );
		$this->contactId = $result['id'];

		$result = civicrm_api3( 'ContributionRecur', 'create', array(
			'contact_id' => $this->contactId,
			'amount' => $this->amount,
			'frequency_interval' => 1,
			'frequency_unit' => 'month',
			'next_sched_contribution' => wmf_common_date_unix_to_civicrm(strtotime('+1 month')),
			'installments' => 0,
			'processor_id' => 1,
			'currency' => 'USD',
			'trxn_id' => "RECURRING GLOBALCOLLECT {$this->subscriptionId}",
		) );
		$this->contributionRecurId = $result['id'];

		$result = civicrm_api3( 'Contribution', 'create', array(
			'contact_id' => $this->contactId,
			'contribution_recur_id' => $this->contributionRecurId,
			'currency' => 'USD',
			'total_amount' => $this->amount,
			'contribution_type' => 'Cash',
			'payment_instrument' => 'Credit Card',
			'trxn_id' => 'RECURRING GLOBALCOLLECT STUB_ORIG_CONTRIB-' . mt_rand(),
		) );
		$this->contributions[] = $result['id'];
		$tracking = array(
			'utm_source' => '..rcc',
			'utm_medium' => 'civicrm',
			'ts' => wmf_common_date_unix_to_sql( strtotime( 'now' ) ),
			'contribution_id' => $result['id'],
		);
		TestingGlobalCollectAdapter::setDummyGatewayResponseCode( 'recurring-OK' );
		wmf_civicrm_insert_contribution_tracking( $tracking );
	}

	public function tearDown() {
		parent::tearDown();
		$this->cleanUpContact( $this->contactId );
		TestingGlobalCollectAdapter::setDummyGatewayResponseCode( null );
	}

	function testChargeRecorded() {
		recurring_globalcollect_charge( $this->contributionRecurId );

		$result = civicrm_api3( 'Contribution', 'get', array(
			'contact_id' => $this->contactId,
		) );
		$this->assertEquals( 2, count( $result['values'] ) );
		foreach ( $result['values'] as $contribution ) {
			if ( $contribution['id'] == $this->contributions[0] ) {
				// Skip assertions on the synthetic original contribution
				continue;
			}

			$this->assertEquals( 1,
				preg_match( "/^RECURRING GLOBALCOLLECT {$this->subscriptionId}-2\$/", $contribution['trxn_id'] ) );
		}
	}

	public function testRecurringCharge() {
		$init = array(
			'contribution_tracking_id' => mt_rand(),
			'amount' => '2345',
			'effort_id' => 2,
			'order_id' => '9998890004',
			'currency' => 'EUR',
			'payment_method' => 'cc',
		);
		$gateway = DonationInterfaceFactory::createAdapter( 'globalcollect', $init );

		$result = $gateway->do_transaction( 'Recurring_Charge' );

		$this->assertTrue( $result->getCommunicationStatus() );
		$this->assertRegExp( '/SET_PAYMENT/', $result->getRawResponse() );
	}

	/**
	 * Can make a recurring payment
	 *
	 * @covers GlobalCollectAdapter::transactionRecurring_Charge
	 */
	public function testDeclinedRecurringCharge() {
		$init = array(
			'contribution_tracking_id' => mt_rand(),
			'amount' => '2345',
			'effort_id' => 2,
			'order_id' => '9998890004',
			'currency' => 'EUR',
			'payment_method' => 'cc',
		);
		$gateway = DonationInterfaceFactory::createAdapter( 'globalcollect', $init );

		TestingGlobalCollectAdapter::setDummyGatewayResponseCode( 'recurring-declined' );

		$result = $gateway->do_transaction( 'Recurring_Charge' );

		$this->assertRegExp( '/GET_ORDERSTATUS/', $result->getRawResponse(),
			'Stopped after GET_ORDERSTATUS.' );
		$this->assertEquals( 2, count( $gateway->curled ),
			'Expected 2 API calls' );
		$this->assertEquals( FinalStatus::FAILED, $gateway->getFinalStatus() );
	}

	/**
	 * Throw errors if the payment is incomplete
	 *
	 * @covers GlobalCollectAdapter::transactionRecurring_Charge
	 */
	public function testRecurringTimeout() {
		$init = array(
			'contribution_tracking_id' => mt_rand(),
			'amount' => '2345',
			'effort_id' => 2,
			'order_id' => '9998890004',
			'currency' => 'EUR',
			'payment_method' => 'cc',
		);
		$gateway = DonationInterfaceFactory::createAdapter( 'globalcollect', $init );

		TestingGlobalCollectAdapter::setDummyGatewayResponseCode( 'recurring-timeout' );

		$result = $gateway->do_transaction( 'Recurring_Charge' );

		$this->assertFalse( $result->getCommunicationStatus() );
		$this->assertRegExp( '/GET_ORDERSTATUS/', $result->getRawResponse() );
		// FIXME: This is a little funky--the transaction is actually pending-poke.
		$this->assertEquals( FinalStatus::FAILED, $gateway->getFinalStatus() );
	}

	/**
	 * Can resume a recurring payment
	 *
	 * @covers GlobalCollectAdapter::transactionRecurring_Charge
	 */
	public function testRecurringResume() {
		$init = array(
			'contribution_tracking_id' => mt_rand(),
			'amount' => '2345',
			'effort_id' => 2,
			'order_id' => '9998890004',
			'currency' => 'EUR',
			'payment_method' => 'cc',
		);
		$gateway = DonationInterfaceFactory::createAdapter( 'globalcollect', $init );

		TestingGlobalCollectAdapter::setDummyGatewayResponseCode( 'recurring-resume' );

		$result = $gateway->do_transaction( 'Recurring_Charge' );

		$this->assertTrue( $result->getCommunicationStatus() );
		$this->assertRegExp( '/SET_PAYMENT/', $result->getRawResponse() );
	}

	/**
	 * Recover from missing ct_ids on all associated contributions
	 */
	public function testBackfillContributionTracking() {
		$id_list = implode( ',', $this->contributions );

		$dbs = wmf_civicrm_get_dbs();
		$dbs->push( 'donations' );
		$query = "DELETE FROM {contribution_tracking} WHERE contribution_id IN( $id_list )";
		db_query( $query );
		$contribution_tracking_id = recurring_get_contribution_tracking_id( array(
			'txn_type' => 'subscr_payment',
			'subscr_id' => $this->subscriptionId,
			'payment_date' => strtotime( "now" ),
		) );
		$this->assertNotEmpty( $contribution_tracking_id );
	}

	/**
	 * Tests to make sure that certain error codes returned from GC will
	 * trigger subscription cancellation, even if retryable errors also exist.
	 *
	 * @dataProvider mcNoRetryCodeProvider
	 */
	public function testNoMastercardFinesForRepeatOnBadCodes( $code ) {
		TestingGlobalCollectAdapter::setDummyGatewayResponseCode( array(
			'recurring-declined', // for the DO_PAYMENT call
			$code // for the GET_ORDERSTATUS call
		) );

		$exceptioned = false;
		try {
			recurring_globalcollect_charge( $this->contributionRecurId );
		} catch ( WmfException $e ) {
			$this->assertEquals( 'PAYMENT_FAILED', $e->type );
			$exceptioned = true;
		}
		$this->assertTrue( $exceptioned );

		$contributions = civicrm_api3( 'Contribution', 'get', array(
			'contact_id' => $this->contactId,
		) );
		// Should still just have the 1 from setUp
		$this->assertEquals( 1, count( $contributions['values'] ) );
		$contributionRecur = civicrm_api3( 'ContributionRecur', 'getSingle',
			array( 'contact_id' => $this->contactId )
		);
		$cancelledStatus = civicrm_api_contribution_status('Cancelled');
		$this->assertEquals(
			$cancelledStatus, $contributionRecur['contribution_status_id']
		);
		$this->assertNotEmpty( $contributionRecur['cancel_date'] );
		$this->assertTrue( empty( $contributionRecur['next_sched_contribution_date'] ) );
		$this->assertTrue( empty( $contributionRecur['failure_retry_date'] ) );
	}

	/**
	 * Transaction codes for GC and GC orphan adapters not to be retried
	 * on pain of $1000+ fines by Mastercard
	 */
	public function mcNoRetryCodeProvider() {
		return array(
			array( '430260' ),
			array( '430306' ),
			array( '430330' ),
			array( '430354' ),
			array( '430357' ),
		);
	}
}
