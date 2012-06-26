<?php

$wgGlobalCollectGatewayMerchantID = '6570';
$wgGlobalCollectGatewayURL = 'https://ps.gcsip.nl/wdl/wdl';

require_once '../gateway.adapter.php';
require_once '../globalcollect.adapter.php';

class GlobalCollectTest extends PHPUnit_Framework_TestCase
{
	function setUp()
	{
		$this->adapter = new GlobalCollectAdapter();
	}

	function testConnect()
	{
		$result = $this->adapter->do_transaction('TEST_CONNECTION');
		$this->assertTrue($result['status']);
	}

	function testCreditCard()
	{
		$data = array(
			'amount' => "35",
			'amountOther' => '',
			'email' => 'test@example.com',
			'fname' => 'Tester',
			'mname' => 'T.',
			'lname' => 'Testington',
			'street' => '548 Market St.',
			'street_supplemental' => '3rd floor',
			'city' => 'San Francisco',
			'state' => 'CA',
			'zip' => '94104',
			'country' => 'US',
			'fname2' => 'Testy',
			'lname2' => 'Testerson',
			'street2' => '123 Telegraph Ave.',
			'city2' => 'Berkeley',
			'state2' => 'CA',
			'zip2' => '94703',
			'country2' => 'US',
			'size' => 'small',
			'premium_language' => 'es',
			'card_num' => 378282246310005,
			'card_type' => 'american',
			'expiration' => date( 'my', strtotime( '+1 year 1 month' ) ),
			'cvv' => '001',
			'currency_code' => 'USD',
			'payment_method' => 'cc',
			'payment_submethod' => '', //cards have no payment submethods. 
			'issuer_id' => '',
			'order_id' => '1234567890',
			'i_order_id' => '1234567890',
			'numAttempt' => 0,
			'referrer' => 'http://www.baz.test.com/index.php?action=foo&action=bar',
			'utm_source' => 'test_src',
			'utm_source_id' => null,
			'utm_medium' => 'test_medium',
			'utm_campaign' => 'test_campaign',
			'language' => 'en',
			'comment-option' => 0,
			'comment' => 0,
			'email-opt' => 0,
			'token' => '',
			'contribution_tracking_id' => '',
			'data_hash' => '',
			'action' => '',
			'gateway' => 'payflowpro',
			'owa_session' => '',
			'owa_ref' => 'http://localhost/defaultTestData',
			'user_ip' => '12.12.12.12',
		);

		$this->adapter->load_request_data($data);
		$result = $this->adapter->do_transaction( 'INSERT_ORDERWITHPAYMENT' );
		$this->assertTrue($result['status'],
			"Successful INSERT_ORDERWITHPAYMENT");
		$result = $this->adapter->do_transaction( 'SET_PAYMENT' );
		$this->assertTrue($result['status'],
			"Successful SET_PAYMENT");
	}
}
