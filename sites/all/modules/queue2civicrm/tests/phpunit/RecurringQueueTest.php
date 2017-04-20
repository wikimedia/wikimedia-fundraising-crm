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

	protected $contributions = array();
	protected $ctIds = array();

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

	// TODO: other queue import tests need to clean up like this!
	public function tearDown() {
		foreach ( $this->ctIds as $ctId ) {
			db_delete( 'contribution_tracking' )
				->condition( 'id', $ctId )
				->execute();
		}
		foreach( $this->contributions as $contribution ) {
			CRM_Core_DAO::executeQuery(
				"
			DELETE FROM civicrm_contribution
			WHERE id = %1",
				array( 1 => array( $contribution['id'], 'Positive' ) )
			);
			CRM_Core_DAO::executeQuery(
				"
			DELETE FROM civicrm_contact
			WHERE id = %1",
				array( 1 => array( $contribution['contact_id'], 'Positive' ) )
			);
		}
		parent::tearDown();
	}

	protected function addContributionTracking( $ctId ) {
		$this->ctIds[] = $ctId;
		db_insert( 'contribution_tracking' )
			->fields( array( 'id' => $ctId ) )
			->execute();
	}

	protected function importMessage( TransactionMessage $message ) {
		$payment_time = $message->get( 'date' );
		exchange_rate_cache_set( 'USD', $payment_time, 1 );
		$currency = $message->get( 'currency' );
		if ( $currency !== 'USD' ) {
			exchange_rate_cache_set( $currency, $payment_time, 3 );
		}
		$this->consumer->processMessage( $message->getBody() );
		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$this->contributions[] = $contributions[0];
		return $contributions;
	}

	public function testCreateDistinctContributions() {
		civicrm_initialize();
		$subscr_id = mt_rand();
		$values = $this->processRecurringSignup( $subscr_id );

		$message = new RecurringPaymentMessage( $values );
		$message2 = new RecurringPaymentMessage( $values );

		$msg = $message->getBody();
		$this->addContributionTracking( $msg['contribution_tracking_id'] );

		$contributions = $this->importMessage( $message );
		$contributions2 = $this->importMessage( $message2 );

		$recur_record = wmf_civicrm_get_recur_record( $subscr_id );

		$this->assertNotEquals( false, $recur_record );

		$this->assertEquals( 1, count( $contributions ) );
		$this->assertEquals( $recur_record->id, $contributions[0]['contribution_recur_id'] );
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
	}

	public function testNormalizedMessages() {
		civicrm_initialize();
		$subscr_id = mt_rand();
		$values = $this->processRecurringSignup( $subscr_id );

		$message = new RecurringPaymentMessage( $values );

		$this->addContributionTracking( $message->get( 'contribution_tracking_id' ) );

		$contributions = $this->importMessage( $message );

		$recur_record = wmf_civicrm_get_recur_record( $subscr_id );
		$this->assertNotEquals( false, $recur_record );

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
	}

	/**
	 *  Test that a blank address is not written to the DB.
	 */
	public function testBlankEmail() {
		civicrm_initialize();
		$subscr_id = mt_rand();
		$values = $this->processRecurringSignup( $subscr_id );

		$message = new RecurringPaymentMessage( $values );
		$messageBody = $message->getBody();

		$addressFields = array( 'city', 'country', 'state_province', 'street_address', 'postal_code' );
		foreach ( $addressFields as $addressField ) {
			$messageBody[$addressField] = '';
		}

		$this->addContributionTracking( $messageBody['contribution_tracking_id'] );

		$this->consumer->processMessage( $messageBody );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$this->contributions[] = $contributions[0];
		$addresses = $this->callAPISuccess(
			'Address',
			'get',
			array( 'contact_id' => $contributions[0]['contact_id'], 'sequential' => 1 )
		);
		$this->assertEquals( 1, $addresses['count'] );
		// The address created by the sign up (Lockwood Rd) should not have been overwritten by the blank.
		$this->assertEquals( '5109 Lockwood Rd', $addresses['values'][0]['street_address'] );
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

		$this->importMessage( $message );
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

		$this->importMessage( $message );
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
		$subscr_time = $signup_message->get( 'date' );
		exchange_rate_cache_set( 'USD', $subscr_time, 1 );
		exchange_rate_cache_set( $signup_message->get( 'currency' ), $subscr_time, 2 );
		$this->consumer->processMessage( $signup_message->getBody() );
		return $values;
	}
}
