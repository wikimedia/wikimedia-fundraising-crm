<?php

use queue2civicrm\DonationQueueConsumer;
use SmashPig\Core\DataStores\DamagedDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Core\DataStores\QueueWrapper;

/**
 * @group Pipeline
 * @group DonationQueue
 * @group Queue2Civicrm
 */
class DonationQueueTest extends BaseWmfDrupalPhpUnitTestCase {
	/**
	 * @var PendingDatabase
	 */
	protected $pendingDb;

	/**
	 * @var DamagedDatabase
	 */
	protected $damagedDb;

	/**
	 * @var DonationQueueConsumer
	 */
	protected $queueConsumer;

	public function setUp() {
		parent::setUp();
		$this->pendingDb = PendingDatabase::get();
		$this->damagedDb = DamagedDatabase::get();
		$this->queueConsumer = new DonationQueueConsumer( 'test' );
	}

	/**
	 * Process an ordinary (one-time) donation message
	 */
	public function testDonation() {
		$message = new TransactionMessage(
			array( 'gross' => 400, 'original_gross' => 400, 'original_currency' => 'USD' )
		);
		$message2 = new TransactionMessage();

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody() );
		$this->queueConsumer->processMessage( $message2->getBody() );

		$campaignField = wmf_civicrm_get_custom_field_name( 'campaign' );

		$expected = array(
			'contact_type' => 'Individual',
			'sort_name' => 'laast, firrst',
			'display_name' => 'firrst laast',
			'first_name' => 'firrst',
			'last_name' => 'laast',
			'currency' => 'USD',
			'total_amount' => '400.00',
			'fee_amount' => '0.00',
			'net_amount' => '400.00',
			'trxn_id' => 'GLOBALCOLLECT ' . $message->getGatewayTxnId(),
			'contribution_source' => 'USD 400',
			'financial_type' => 'Cash',
			'contribution_status' => 'Completed',
			'payment_instrument' => 'Credit Card: Visa',
			'invoice_id' => $message->get('order_id'),
			$campaignField => '',
		);
		$returnFields = array_keys( $expected );

		$contribution = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				wmf_civicrm_get_custom_field_name( 'gateway_txn_id' ) => $message->getGatewayTxnId(),
				'return' => $returnFields
			)
		);

		$this->assertArraySubset( $expected, $contribution );

		$contribution2 = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				wmf_civicrm_get_custom_field_name( 'gateway_txn_id' ) => $message2->getGatewayTxnId(),
				'return' => $returnFields
			)
		);

		$expected = array(
			'contact_type' => 'Individual',
			'sort_name' => 'laast, firrst',
			'display_name' => 'firrst laast',
			'first_name' => 'firrst',
			'last_name' => 'laast',
			'currency' => 'USD',
			'total_amount' => '2857.02',
			'fee_amount' => '0.00',
			'net_amount' => '2857.02',
			'trxn_id' => 'GLOBALCOLLECT ' . $message2->getGatewayTxnId(),
			'contribution_source' => 'PLN 952.34',
			'financial_type' => 'Cash',
			'contribution_status' => 'Completed',
			'payment_instrument' => 'Credit Card: Visa',
			'invoice_id' => $message2->get('order_id'),
			$campaignField => 'Benefactor Gift',
		);
		$this->assertArraySubset( $expected, $contribution2 );
		$this->assertNotEquals( $contribution['contact_id'], $contribution2['contact_id'] );
	}

	/**
	 * If 'invoice_id' is in the message, don't stuff that field with order_id
	 */
	public function testDonationInvoiceId() {
		$message = new TransactionMessage(
			array( 'invoice_id' => mt_rand() )
		);

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody() );

		$campaignField = wmf_civicrm_get_custom_field_name( 'campaign' );

		$expected = array(
			'contact_type' => 'Individual',
			'sort_name' => 'laast, firrst',
			'display_name' => 'firrst laast',
			'first_name' => 'firrst',
			'last_name' => 'laast',
			'currency' => 'USD',
			'total_amount' => '2857.02',
			'fee_amount' => '0.00',
			'net_amount' => '2857.02',
			'trxn_id' => 'GLOBALCOLLECT ' . $message->getGatewayTxnId(),
			'contribution_source' => 'PLN 952.34',
			'financial_type' => 'Cash',
			'contribution_status' => 'Completed',
			'payment_instrument' => 'Credit Card: Visa',
			'invoice_id' => $message->get( 'invoice_id' ),
			$campaignField => 'Benefactor Gift',
		);
		$returnFields = array_keys( $expected );

		$contribution = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				wmf_civicrm_get_custom_field_name( 'gateway_txn_id' ) => $message->getGatewayTxnId(),
				'return' => $returnFields
			)
		);

		$this->assertArraySubset( $expected, $contribution );
	}

	/**
	 * Process an ordinary (one-time) donation message with an UTF campaign.
	 */
	public function testDonationWithUTFCampaignOption() {
		$message = new TransactionMessage( array( 'utm_campaign' => 'EmailCampaign1' ) );
		$appealFieldID = $this->createCustomOption( 'Appeal', 'EmailCampaign1' );

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody() );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$contribution = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				'id' => $contributions[0]['id'],
				'return' => 'custom_' . $appealFieldID,
			)
		);
		$this->assertEquals( 'EmailCampaign1', $contribution['custom_' . $appealFieldID] );
		$this->deleteCustomOption( 'Appeal', 'EmailCampaign1' );
	}

	/**
	 * Process an ordinary (one-time) donation message with an UTF campaign not already existing.
	 */
	public function testDonationWithInvalidUTFCampaignOption() {
		civicrm_initialize();
		$optionValue = uniqid();
		$message = new TransactionMessage( array( 'utm_campaign' => $optionValue ) );
		$appealField = civicrm_api3( 'custom_field', 'getsingle', array( 'name' => 'Appeal' ) );

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody() );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$contribution = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				'id' => $contributions[0]['id'],
				'return' => 'custom_' . $appealField['id'],
			)
		);
		$this->assertEquals( $optionValue, $contribution['custom_' . $appealField['id']] );
		$this->deleteCustomOption( 'Appeal', $optionValue );
	}

	/**
	 * Process an ordinary (one-time) donation message with an UTF campaign previously disabled.
	 */
	public function testDonationWithDisabledUTFCampaignOption() {
		civicrm_initialize();
		$optionValue = uniqid();
		$message = new TransactionMessage( array( 'utm_campaign' => $optionValue ) );
		$appealFieldID = $this->createCustomOption( 'Appeal', $optionValue, FALSE );

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody() );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$contribution = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				'id' => $contributions[0]['id'],
				'return' => 'custom_' . $appealFieldID,
			)
		);
		$this->assertEquals( $optionValue, $contribution['custom_' . $appealFieldID] );
		$this->deleteCustomOption( 'Appeal', $optionValue );
	}

	/**
	 * Process an ordinary (one-time) donation message with an UTF campaign with a different label.
	 */
	public function testDonationWithDifferentLabelUTFCampaignOption() {
		civicrm_initialize();
		$optionValue = uniqid();
		$message = new TransactionMessage( array( 'utm_campaign' => $optionValue ) );
		$appealFieldID = $this->createCustomOption( 'Appeal', $optionValue, TRUE, uniqid() );

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody() );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$contribution = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				'id' => $contributions[0]['id'],
				'return' => 'custom_' . $appealFieldID,
			)
		);
		$this->assertEquals( $optionValue, $contribution['custom_' . $appealFieldID] );
		$values = $this->callAPISuccess( 'OptionValue', 'get', array( 'value' => $optionValue ) );
		$this->assertEquals( 1, $values['count'] );
		$this->deleteCustomOption( 'Appeal', $optionValue );
	}

	/**
	 * Create a custom option for the given field.
	 *
	 * @param string $fieldName
	 *
	 * @param string $optionValue
	 * @param bool $is_active
	 *   Is the option value enabled.
	 *
	 * @return mixed
	 * @throws \CiviCRM_API3_Exception
	 */
	public function createCustomOption( $fieldName, $optionValue, $is_active = 1, $label = NULL ) {
		if ( !$label ) {
			$label = $optionValue;
		}
		$appealField = civicrm_api3( 'custom_field', 'getsingle', array( 'name' => $fieldName ) );
		civicrm_api3(
			'OptionValue',
			'create',
			array(
				'name' => $label,
				'value' => $optionValue,
				'option_group_id' => $appealField['option_group_id'],
				'is_active' => $is_active,
			)
		);
		wmf_civicrm_flush_cached_options();
		return $appealField['id'];
	}

	/**
	 * Cleanup custom field option after test.
	 *
	 * @param string $fieldName
	 *
	 * @param string $optionValue
	 *
	 * @return mixed
	 * @throws \CiviCRM_API3_Exception
	 */
	public function deleteCustomOption( $fieldName, $optionValue ) {
		$appealField = civicrm_api3( 'custom_field', 'getsingle', array( 'name' => $fieldName ) );
		return $appealField['id'];
	}

	/**
	 * Process a donation message with some info from pending db
	 * @dataProvider getSparseMessages
	 * @param TransactionMessage $message
	 * @param array $pendingMessage
	 */
	public function testDonationSparseMessages( $message, $pendingMessage ) {
		$pendingMessage['order_id'] = $message->get( 'order_id' );
		$this->pendingDb->storeMessage( $pendingMessage );
		$appealFieldID = $this->createCustomOption(
			'Appeal',
			$pendingMessage['utm_campaign'],
			false
		);

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody() );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id(
			$message->getGateway(),
			$message->getGatewayTxnId()
		);
		$contribution = civicrm_api3(
			'Contribution',
			'getsingle',
			array(
				'id' => $contributions[0]['id'],
				'return' => 'custom_' . $appealFieldID,
			)
		);
		$this->assertEquals( $pendingMessage['utm_campaign'], $contribution['custom_' . $appealFieldID] );
		$this->deleteCustomOption( 'Appeal', $pendingMessage['utm_campaign'] );
		$pendingEntry = $this->pendingDb->fetchMessageByGatewayOrderId(
			$message->get( 'gateway' ),
			$pendingMessage['order_id']
		);
		$this->assertNull( $pendingEntry, 'Should have deleted pending DB entry' );
		civicrm_api3( 'Contribution', 'delete', array( 'id' => $contributions[0]['id'] ) );
		civicrm_api3( 'Contact', 'delete', array( 'id' => $contributions[0]['contact_id'] ) );
	}

	public function getSparseMessages() {
		module_load_include('php', 'queue2civicrm', 'tests/includes/Message');
		return array(
			array(
				new AmazonDonationMessage(),
				json_decode(
					file_get_contents( __DIR__ . '/../data/pending_amazon.json' ),
					true
				)
			),
			array(
				new AstroPayDonationMessage(),
				json_decode(
					file_get_contents( __DIR__ . '/../data/pending_astropay.json' ),
					true
				)
			),
		);
	}

	public function testDuplicateHandling() {
		$message = new TransactionMessage();
		$message2 = new TransactionMessage(
			array(
				'contribution_tracking_id' => $message->get( 'contribution_tracking_id' ),
				'order_id' => $message->get( 'order_id' ),
				'date' => time(),
			)
		);

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );
		exchange_rate_cache_set( 'USD', $message2->get( 'date' ), 1 );
		exchange_rate_cache_set( $message2->get( 'currency' ), $message2->get( 'date' ), 3 );

		QueueWrapper::getQueue( 'test' )->push( $message->getBody() );
		QueueWrapper::getQueue( 'test' )->push( $message2->getBody() );

		$this->queueConsumer->dequeueMessages();

		$this->callAPISuccessGetSingle( 'Contribution', array(
			'invoice_id' => $message->get( 'order_id' )
		) );
		$originalOrderId = $message2->get( 'order_id' );
		$damagedPDO = $this->damagedDb->getDatabase();
		$result = $damagedPDO->query( "
			SELECT * FROM damaged
			WHERE gateway = '{$message2->getGateway()}'
			AND order_id = '{$originalOrderId}'" );
		$rows = $result->fetchAll( PDO::FETCH_ASSOC );
		$this->assertEquals( 1, count( $rows ),
			'One row stored and retrieved.' );
		$expected = array(
			// NOTE: This is a db-specific string, sqlite3 in this case, and
			// you'll have different formatting if using any other database.
			'original_date' => wmf_common_date_unix_to_sql( $message2->get('date') ),
			'gateway' => $message2->getGateway(),
			'order_id' => $originalOrderId,
			'gateway_txn_id' => "{$message2->get('gateway_txn_id')}",
			'original_queue' => 'test',
		);
		$this->assertArraySubset( $expected, $rows[0],
			'Stored message had expected contents' );
		$this->assertNotNull( $rows[0]['retry_date'], 'Should retry' );
		$storedMessage = json_decode( $rows[0]['message'], true );
		$storedInvoiceId = $storedMessage['invoice_id'];
		$storedTags = $storedMessage['contribution_tags'];
		unset( $storedMessage['invoice_id'] );
		unset( $storedMessage['contribution_tags'] );
		$this->assertEquals( $message2->getBody(), $storedMessage );

		$invoiceIdLen = strlen( strval( $originalOrderId ) );
		$this->assertEquals(
			"$originalOrderId|dup-",
			substr( $storedInvoiceId, 0, $invoiceIdLen + 5 )
		);
		$this->assertEquals( array( 'DuplicateInvoiceId' ), $storedTags );
	}
}
