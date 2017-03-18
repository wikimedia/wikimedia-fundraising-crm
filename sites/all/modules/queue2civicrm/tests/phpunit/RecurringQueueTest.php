<?php
use queue2civicrm\recurring\RecurringQueueConsumer;
use SmashPig\Core\Context;
use SmashPig\Core\QueueConsumers\BaseQueueConsumer;

/**
 * @group Queue2Civicrm
 */
class RecurringQueueTest extends BaseWmfDrupalPhpUnitTestCase {

	/**
	 * @var RecurringQueueConsumer
	 */
	protected $consumer;

	public function setUp() {
		parent::setUp();
		$config = TestingSmashPigDbQueueConfiguration::instance();
		Context::initWithLogger( $config );
		$queue = BaseQueueConsumer::getQueue( 'test' );
		$queue->createTable( 'test' );
		$this->consumer = new RecurringQueueConsumer(
			'test'
		);
	}


	public function testCreateDistinctContributions() {
		civicrm_initialize();
		$subscr_id = mt_rand();
		$values = $this->processRecurringSignup( $subscr_id );

		$message = new RecurringPaymentMessage( $values );
		$message2 = new RecurringPaymentMessage( $values );

		$payment_time = strtotime( $message->get( 'payment_date' ) );
		exchange_rate_cache_set( 'USD', $payment_time, 1 );
		exchange_rate_cache_set( $message->get( 'mc_currency' ), $payment_time, 3 );

		$msg = $message->getBody();
		db_insert( 'contribution_tracking' )
			->fields( array( 'id' => $msg['custom'] ) )
			->execute();

		$this->consumer->processMessage( $msg );
		$this->consumer->processMessage( $message2->getBody() );

		$recur_record = wmf_civicrm_get_recur_record( $subscr_id );
		$this->assertNotEquals( false, $recur_record );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$this->assertEquals( 1, count( $contributions ) );
		$this->assertEquals( $recur_record->id, $contributions[0]['contribution_recur_id'] );

		$contributions2 = wmf_civicrm_get_contributions_from_gateway_id(
			$message2->getGateway(),
			$message2->getGatewayTxnId()
		);
		$this->assertEquals( 1, count( $contributions2 ) );
		$this->assertEquals( $recur_record->id, $contributions2[0]['contribution_recur_id'] );

		$this->assertEquals( $contributions[0]['contact_id'], $contributions2[0]['contact_id'] );
		$addresses = $this->callAPISuccess(
			'Address',
			'get',
			array( 'contact_id' => $contributions2[0]['contact_id'] )
		);
		$this->assertEquals( 1, $addresses['count'] );
		// The address comes from the recurring_payment.json not the recurring_signup.json as it
		// has been overwritten. This is perhaps not a valid scenario in production but it is
		// the scenario the code works to. In production they would probably always be the same.
		$this->assertEquals( '1211122 132 st', $addresses['values'][$addresses['id']]['street_address'] );

		$emails = $this->callAPISuccess( 'Email', 'get', array( 'contact_id' => $contributions2[0]['contact_id'] ) );
		$this->assertEquals( 1, $addresses['count'] );
		$this->assertEquals( 'test+fr@wikimedia.org', $emails['values'][$emails['id']]['email'] );

		db_delete( 'contribution_tracking' )
			->condition( 'id', $msg['custom'] )
			->execute();
	}

	public function testNormalizedMessages() {
		civicrm_initialize();
		$subscr_id = mt_rand();
		$values = $this->processNormalizedRecurringSignup( $subscr_id );

		$message = new NormalizedSubscriptionPaymentMessage( $values );

		$payment_time = $message->get( 'date' );
		exchange_rate_cache_set( 'USD', $payment_time, 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $payment_time, 2 );

		db_insert( 'contribution_tracking' )
			->fields( array( 'id' => $message->get( 'contribution_tracking_id' ) ) )
			->execute();

		$this->consumer->processMessage( $message->getBody() );

		$recur_record = wmf_civicrm_get_recur_record( $subscr_id );
		$this->assertNotEquals( false, $recur_record );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$this->assertEquals( 1, count( $contributions ) );
		$this->assertEquals( $recur_record->id, $contributions[0]['contribution_recur_id'] );

		$addresses = $this->callAPISuccess(
			'Address',
			'get',
			array( 'contact_id' => $contributions[0]['contact_id'] )
		);
		$this->assertEquals( 1, $addresses['count'] );

		$emails = $this->callAPISuccess( 'Email', 'get', array( 'contact_id' => $contributions[0]['contact_id'] ) );
		$this->assertEquals( 1, $addresses['count'] );
		$this->assertEquals( 'test+fr@wikimedia.org', $emails['values'][$emails['id']]['email'] );

		db_delete( 'contribution_tracking' )
			->condition( 'id', $message->get( 'contribution_tracking_id' ) )
			->execute();
		CRM_Core_DAO::executeQuery(
			"
			DELETE FROM civicrm_contribution
			WHERE id = %1",
			array( 1 => array( $contributions[0]['id'], 'Positive' ) )
		);
		CRM_Core_DAO::executeQuery(
			"
			DELETE FROM civicrm_contact
			WHERE id = %1",
			array( 1 => array( $contributions[0]['contact_id'], 'Positive' ) )
		);
	}

	/**
	 *  Test that the a blank address is not written to the DB.
	 */
	public function testBlankEmail() {
		civicrm_initialize();
		$subscr_id = mt_rand();
		$values = $this->processRecurringSignup( $subscr_id );

		$message = new RecurringPaymentMessage( $values );
		$this->setExchangeRates(
			$message->get( 'payment_date' ),
			array( 'USD' => 1, $message->get( 'mc_currency' ) => 3 )
		);
		$messageBody = $message->getBody();

		$addressFields = array( 'address_city', "address_country_code", "address_country", "address_state", "address_street", "address_zip" );
		foreach ( $addressFields as $addressField ) {
			$messageBody[$addressField] = '';
		}

		db_insert( 'contribution_tracking' )
			->fields( array( 'id' => $messageBody['custom'] ) )
			->execute();

		$this->consumer->processMessage( $messageBody );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$addresses = $this->callAPISuccess(
			'Address',
			'get',
			array( 'contact_id' => $contributions[0]['contact_id'], 'sequential' => 1 )
		);
		$this->assertEquals( 1, $addresses['count'] );
		// The address created by the sign up (Lockwood Rd) should not have been overwritten by the blank.
		$this->assertEquals( '5109 Lockwood Rd', $addresses['values'][0]['street_address'] );
		db_delete( 'contribution_tracking' )
			->condition( 'id', $messageBody['custom'] )
			->execute();
	}

	/**
	 * @expectedException WmfException
	 * @expectedExceptionCode WmfException::MISSING_PREDECESSOR
	 */
	public function testMissingPredecessor() {
		$message = new RecurringPaymentMessage(
			array(
				'subscr_id' => mt_rand(),
			)
		);

		$payment_time = strtotime( $message->get( 'payment_date' ) );
		exchange_rate_cache_set( 'USD', $payment_time, 1 );
		exchange_rate_cache_set( $message->get( 'mc_currency' ), $payment_time, 3 );

		$this->consumer->processMessage( $message->getBody() );
	}

	/**
	 * @expectedException WmfException
	 * @expectedExceptionCode WmfException::INVALID_RECURRING
	 */
	public function testNoSubscrId() {
		$message = new RecurringPaymentMessage(
			array(
				'subscr_id' => null,
			)
		);

		$payment_time = strtotime( $message->get( 'payment_date' ) );
		exchange_rate_cache_set( 'USD', $payment_time, 1 );
		exchange_rate_cache_set( $message->get( 'mc_currency' ), $payment_time, 3 );

		$this->consumer->processMessage( $message->getBody() );
	}

	/**
	 * Process the original recurring sign up message.
	 *
	 * @param string $subscr_id
	 * @return array
	 */
	private function processRecurringSignup( $subscr_id ) {
		$values = array( 'subscr_id' => $subscr_id );
		$signup_message = new RecurringSignupMessage( $values );
		$subscr_time = strtotime( $signup_message->get( 'subscr_date' ) );
		exchange_rate_cache_set( 'USD', $subscr_time, 1 );
		exchange_rate_cache_set( $signup_message->get( 'mc_currency' ), $subscr_time, 3 );
		$this->consumer->processMessage( $signup_message->getBody() );
		return $values;
	}

	/**
	 * Process the original recurring sign up message.
	 *
	 * @param string $subscr_id
	 * @return array
	 */
	private function processNormalizedRecurringSignup( $subscr_id ) {
		$values = array( 'subscr_id' => $subscr_id );
		$signup_message = new NormalizedRecurringSignupMessage( $values );
		$subscr_time = $signup_message->get( 'date' );
		exchange_rate_cache_set( 'USD', $subscr_time, 1 );
		exchange_rate_cache_set( $signup_message->get( 'currency' ), $subscr_time, 2 );
		$this->consumer->processMessage( $signup_message->getBody() );
		return $values;
	}
}
