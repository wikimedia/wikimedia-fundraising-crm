<?php

use queue2civicrm\DonationQueueConsumer;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\PendingDatabase;

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
	 * @var DonationQueueConsumer
	 */
	protected $queueConsumer;

	public function setUp() {
		parent::setUp();
		$this->pendingDb = PendingDatabase::get();
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
}
