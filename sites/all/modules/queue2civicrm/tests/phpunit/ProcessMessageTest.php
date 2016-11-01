<?php

use queue2civicrm\DonationQueueConsumer;
use queue2civicrm\refund\RefundQueueConsumer;
use queue2civicrm\recurring\RecurringQueueConsumer;
use SmashPig\Core\Context;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Tests\SmashPigDatabaseTestConfiguration;

/**
 * @group Pipeline
 * @group Queue2Civicrm
 */
class ProcessMessageTest extends BaseWmfDrupalPhpUnitTestCase {
	/**
	 * @var PendingDatabase
	 */
	protected $pendingDb;

	/**
	 * @var DonationQueueConsumer
	 */
	protected $queueConsumer;

	/**
	 * @var RefundQueueConsumer
	 */
	protected $refundConsumer;

	/**
	 * @var RecurringQueueConsumer
	 */
	protected $recurringConsumer;

	public function setUp() {
		parent::setUp();
		$config = SmashPigDatabaseTestConfiguration::instance();
		// FIXME: Use all-purpose SmashPig test config when ready
		$config->override( array(
			'data-store' => array(
				'donations' => array(
					'class' => 'PHPQueue\Backend\PDO',
					'constructor-parameters' => array( array(
						'connection_string' => 'sqlite::memory:'
					) )
				),
				'refund-new' => array(
					'class' => 'PHPQueue\Backend\PDO',
					'constructor-parameters' => array( array(
						'connection_string' => 'sqlite::memory:'
					) )
				),
				'recurring-new' => array(
					'class' => 'PHPQueue\Backend\PDO',
					'constructor-parameters' => array( array(
						'connection_string' => 'sqlite::memory:'
					) )
				),
			)
		) );
		Context::initWithLogger( $config );
		$this->pendingDb = PendingDatabase::get();
		$this->pendingDb->createTable();
		$this->queueConsumer = new DonationQueueConsumer( 'donations' );
		$this->refundConsumer = new RefundQueueConsumer( 'refund-new' );
		$this->recurringConsumer = new RecurringQueueConsumer( 'recurring-new' );
	}

    /**
     * Process an ordinary (one-time) donation message
     */
    public function testDonation() {
        $message = new TransactionMessage(array('gross' => 400, 'original_gross' => 400, 'original_currency' => 'USD'));
        $message2 = new TransactionMessage();

        exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
        exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

        $this->queueConsumer->processMessage( $message->getBody() );
        $this->queueConsumer->processMessage( $message2->getBody() );

        $contribution = civicrm_api3('Contribution', 'getsingle', array(
          wmf_civicrm_get_custom_field_name('gateway_txn_id') => $message->getGatewayTxnId(),
          'return' => array(wmf_civicrm_get_custom_field_name('Campaign'), 'total_amount'),
        ));
        $this->assertEmpty($contribution[wmf_civicrm_get_custom_field_name('campaign')] );

        $contribution2 = civicrm_api3('Contribution', 'getsingle', array(
          wmf_civicrm_get_custom_field_name('gateway_txn_id') => $message2->getGatewayTxnId(),
          'return' => array(wmf_civicrm_get_custom_field_name('Campaign'), 'total_amount'),
        ));

        $this->assertEquals('Benefactor Gift', $contribution2[wmf_civicrm_get_custom_field_name('campaign')] );

        $this->assertNotEquals( $contribution['contact_id'], $contribution2['contact_id'] );
    }

    /**
     * Process an ordinary (one-time) donation message with an UTF campaign.
     */
    public function testDonationWithUTFCampaignOption() {
        $message = new TransactionMessage(array('utm_campaign' => 'EmailCampaign1'));
        $appealFieldID = $this->createCustomOption('Appeal', 'EmailCampaign1');

        exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
        exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

        $this->queueConsumer->processMessage( $message->getBody()  );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
        $contribution = civicrm_api3('Contribution', 'getsingle', array(
            'id' => $contributions[0]['id'],
            'return' => 'custom_' . $appealFieldID,
        ));
      $this->assertEquals('EmailCampaign1', $contribution['custom_' . $appealFieldID]);
        $this->deleteCustomOption('Appeal', 'EmailCampaign1');
    }

  /**
   * Process an ordinary (one-time) donation message with an UTF campaign not already existing.
   */
  public function testDonationWithInvalidUTFCampaignOption() {
      civicrm_initialize();
      $optionValue = uniqid();
      $message = new TransactionMessage(array('utm_campaign' => $optionValue));
      $appealField = civicrm_api3('custom_field', 'getsingle', array('name' => 'Appeal'));

      exchange_rate_cache_set('USD', $message->get('date'), 1);
      exchange_rate_cache_set($message->get('currency'), $message->get('date'), 3);

      $this->queueConsumer->processMessage( $message->getBody()  );

      $contributions = wmf_civicrm_get_contributions_from_gateway_id($message->getGateway(), $message->getGatewayTxnId());
      $contribution = civicrm_api3('Contribution', 'getsingle', array(
        'id' => $contributions[0]['id'],
        'return' => 'custom_' . $appealField['id'],
      ));
      $this->assertEquals($optionValue, $contribution['custom_' . $appealField['id']]);
      $this->deleteCustomOption('Appeal', $optionValue);
  }

  /**
   * Process an ordinary (one-time) donation message with an UTF campaign previously disabled.
   */
  public function testDonationWithDisabledUTFCampaignOption() {
    civicrm_initialize();
    $optionValue = uniqid();
    $message = new TransactionMessage(array('utm_campaign' => $optionValue));
    $appealFieldID = $this->createCustomOption('Appeal', $optionValue, FALSE);

    exchange_rate_cache_set('USD', $message->get('date'), 1);
    exchange_rate_cache_set($message->get('currency'), $message->get('date'), 3);

    $this->queueConsumer->processMessage( $message->getBody() );

    $contributions = wmf_civicrm_get_contributions_from_gateway_id($message->getGateway(), $message->getGatewayTxnId());
    $contribution = civicrm_api3('Contribution', 'getsingle', array(
      'id' => $contributions[0]['id'],
      'return' => 'custom_' . $appealFieldID,
    ));
    $this->assertEquals($optionValue, $contribution['custom_' . $appealFieldID]);
    $this->deleteCustomOption('Appeal', $optionValue);
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
    public function createCustomOption($fieldName, $optionValue, $is_active = 1) {
        $appealField = civicrm_api3('custom_field', 'getsingle', array('name' => $fieldName));
        civicrm_api3('OptionValue', 'create', array(
          'name' => $optionValue,
          'value' => $optionValue,
          'option_group_id' => $appealField['option_group_id'],
          'is_active' => $is_active,
        ));
        civicrm_api_option_group(wmf_civicrm_get_direct_mail_field_option_name(), null, TRUE);
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
    public function deleteCustomOption($fieldName, $optionValue) {
        $appealField = civicrm_api3('custom_field', 'getsingle', array('name' => $fieldName));
        return $appealField['id'];
    }

    public function testRecurring() {
        $subscr_id = mt_rand();
        $values = array( 'subscr_id' => $subscr_id );
        $signup_message = new RecurringSignupMessage( $values );
        $message = new RecurringPaymentMessage( $values );
        $message2 = new RecurringPaymentMessage( $values );

        $subscr_time = strtotime( $signup_message->get( 'subscr_date' ) );
        exchange_rate_cache_set( 'USD', $subscr_time, 1 );
        exchange_rate_cache_set( $signup_message->get('mc_currency'), $subscr_time, 3 );
        $payment_time = strtotime( $message->get( 'payment_date' ) );
        exchange_rate_cache_set( 'USD', $payment_time, 1 );
        exchange_rate_cache_set( $message->get('mc_currency'), $payment_time, 3 );

        $this->recurringConsumer->processMessage( $signup_message->getBody() );
        $this->recurringConsumer->processMessage( $message->getBody() );
        $this->recurringConsumer->processMessage( $message2->getBody() );

        $recur_record = wmf_civicrm_get_recur_record( $subscr_id );
        $this->assertNotEquals( false, $recur_record );

        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
        $this->assertEquals( $recur_record->id, $contributions[0]['contribution_recur_id']);

        $contributions2 = wmf_civicrm_get_contributions_from_gateway_id( $message2->getGateway(), $message2->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions2 ) );
        $this->assertEquals( $recur_record->id, $contributions2[0]['contribution_recur_id']);

        $this->assertEquals( $contributions[0]['contact_id'], $contributions2[0]['contact_id'] );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::MISSING_PREDECESSOR
     */
    public function testRecurringNoPredecessor() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => mt_rand(),
        ) );

        $payment_time = strtotime( $message->get( 'payment_date' ) );
        exchange_rate_cache_set( 'USD', $payment_time, 1 );
        exchange_rate_cache_set( $message->get('mc_currency'), $payment_time, 3 );

        $this->recurringConsumer->processMessage( $message->getBody() );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::INVALID_RECURRING
     */
    public function testRecurringNoSubscrId() {
        $message = new RecurringPaymentMessage( array(
            'subscr_id' => null,
        ) );

        $payment_time = strtotime( $message->get( 'payment_date' ) );
        exchange_rate_cache_set( 'USD', $payment_time, 1 );
        exchange_rate_cache_set( $message->get('mc_currency'), $payment_time, 3 );

        $this->recurringConsumer->processMessage( $message->getBody() );
    }

    public function testRefund() {
        $donation_message = new TransactionMessage();
        $refund_message = new RefundMessage( array(
            'gateway' => $donation_message->getGateway(),
            'gateway_parent_id' => $donation_message->getGatewayTxnId(),
            'gateway_refund_id' => mt_rand(),
            'gross' => $donation_message->get( 'original_gross' ),
            'gross_currency' => $donation_message->get( 'original_currency' ),
        ) );

        exchange_rate_cache_set( 'USD', $donation_message->get('date'), 1 );
        exchange_rate_cache_set( $donation_message->get('currency'), $donation_message->get('date'), 3 );

        $this->queueConsumer->processMessage( $donation_message->getBody() );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $donation_message->getGateway(), $donation_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );

	$this->refundConsumer->processMessage( $refund_message->getBody() );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $refund_message->getGateway(), $refund_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WmfException::MISSING_PREDECESSOR
     */
    public function testRefundNoPredecessor() {
        $refund_message = new RefundMessage();

	$this->refundConsumer->processMessage( $refund_message->getBody() );
    }

    /**
     * Test refunding a mismatched amount.
     *
     * Note that we were checking against an exception - but it turned out the exception
     * could be thrown in this fn $this->queueConsumer->processMessage if the exchange rate does not
     * exist - which is not what we are testing for.
     */
    public function testRefundMismatched() {
        $this->setExchangeRates(1234567, array( 'USD' => 1, 'PLN' => 0.5 ) );
        $donation_message = new TransactionMessage( array(
            'gateway' => 'test_gateway',
            'gateway_txn_id' => mt_rand(),
        ) );
        $refund_message = new RefundMessage( array(
            'gateway' => 'test_gateway',
            'gateway_parent_id' => $donation_message->getGatewayTxnId(),
            'gateway_refund_id' => mt_rand(),
            'gross' => $donation_message->get( 'original_gross' ) + 1,
            'gross_currency' => $donation_message->get( 'original_currency' ),
        ) );

        $this->queueConsumer->processMessage( $donation_message->getBody() );
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( $donation_message->getGateway(), $donation_message->getGatewayTxnId() );
        $this->assertEquals( 1, count( $contributions ) );

	$this->refundConsumer->processMessage( $refund_message->getBody() );
        $contributions = $this->callAPISuccess('Contribution', 'get', array('contact_id' => $contributions[0]['contact_id'], 'sequential' => 1));
        $this->assertEquals(2, count($contributions['values']));
        $this->assertEquals('Chargeback', CRM_Contribute_PseudoConstant::contributionStatus($contributions['values'][0]['contribution_status_id']));
        $this->assertEquals('-.5', $contributions['values'][1]['total_amount']);
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
			'Appeal', $pendingMessage['utm_campaign'], false
		);

		exchange_rate_cache_set( 'USD', $message->get( 'date' ), 1 );
		exchange_rate_cache_set( $message->get( 'currency' ), $message->get( 'date' ), 3 );

		$this->queueConsumer->processMessage( $message->getBody()  );

		$contributions = wmf_civicrm_get_contributions_from_gateway_id( $message->getGateway(), $message->getGatewayTxnId() );
		$contribution = civicrm_api3('Contribution', 'getsingle', array(
			'id' => $contributions[0]['id'],
			'return' => 'custom_' . $appealFieldID,
		));
		$this->assertEquals( $pendingMessage['utm_campaign'], $contribution['custom_' . $appealFieldID]);
		$this->deleteCustomOption('Appeal', $pendingMessage['utm_campaign']);
		$pendingEntry = $this->pendingDb->fetchMessageByGatewayOrderId(
			$message->get( 'gateway' ), $pendingMessage['order_id']
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
					file_get_contents( __DIR__ . '/../data/pending_amazon.json'), true
				)
			),
			array(
				new AstroPayDonationMessage(),
				json_decode(
					file_get_contents( __DIR__ . '/../data/pending_astropay.json'), true
				)
			),
		);
	}
}
