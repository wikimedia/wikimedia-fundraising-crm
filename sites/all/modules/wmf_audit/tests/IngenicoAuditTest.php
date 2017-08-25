<?php

/**
 * @group Ingenico
 * @group WmfAudit
 */
class IngenicoAuditTest extends BaseWmfDrupalPhpUnitTestCase {
	static protected $messages;

	protected $contact_ids = array();
	protected $contribution_ids = array();

	public function setUp() {
		parent::setUp();
		self::$messages = array();

		$dirs = array(
			'wmf_audit_log_archive_dir' => __DIR__ . '/data/logs/',
			'ingenico_audit_recon_completed_dir' => $this->getTempDir(),
			'ingenico_audit_working_log_dir' => $this->getTempDir(),
		);

		foreach ( $dirs as $var => $dir ) {
			if ( !is_dir( $dir ) ) {
				mkdir( $dir );
			}
			variable_set( $var, $dir );
		}

		$old_working = glob( $dirs['ingenico_audit_working_log_dir'] . '*' );
		foreach ( $old_working as $zap ) {
			if ( is_file( $zap ) ) {
				unlink( $zap );
			}
		}

		variable_set( 'ingenico_audit_log_search_past_days', 7 );

		// Fakedb doesn't fake the original txn for refunds, so add one here
		$existing = wmf_civicrm_get_contributions_from_gateway_id( 'globalcollect', '11992288' );
		if ( $existing ) {
			// Previous test run may have crashed before cleaning up
			$contribution = $existing[0];
		} else {
			$msg = array(
				'contribution_tracking_id' => 8675309,
				'currency' => 'USD',
				'date' => 1455825706,
				'email' => 'nun@flying.com',
				'gateway' => 'globalcollect',
				'gateway_txn_id' => '11992288',
				'gross' => 100.00,
				'payment_method' => 'cc',
				'payment_submethod' => 'visa',
			);
			$contribution = wmf_civicrm_contribution_message_import( $msg );
		}
		$this->contact_ids[] = $contribution['contact_id'];

		// and another for the chargeback
		$existing = wmf_civicrm_get_contributions_from_gateway_id( 'globalcollect', '55500002' );
		if ( $existing ) {
			// Previous test run may have crashed before cleaning up
			$contribution = $existing[0];
		} else {
			$msg = array(
				'contribution_tracking_id' => 5318008,
				'currency' => 'USD',
				'date' => 1443724034,
				'email' => 'lovelyspam@python.com',
				'gateway' => 'globalcollect',
				'gateway_txn_id' => '55500002',
				'gross' => 200.00,
				'payment_method' => 'cc',
				'payment_submethod' => 'visa',
			);
			$contribution = wmf_civicrm_contribution_message_import( $msg );
		}
		$this->contact_ids[] = $contribution['contact_id'];

		// and another for the sparse refund
		$this->setExchangeRates( 1443724034, array( 'EUR' => 1.5, 'USD' => 1 ) );
		$existing = wmf_civicrm_get_contributions_from_gateway_id( 'globalcollect', '1111662235' );
		if ( $existing ) {
			// Previous test run may have crashed before cleaning up
			$contribution = $existing[0];
		} else {
			db_merge( 'contribution_tracking' )->key( array(
				'id' => 48987654
			) )->fields( array(
				'country' => 'IT',
				'utm_source' => 'something',
				'utm_medium' => 'another_thing',
				'utm_campaign' => 'campaign_thing',
				'language' => 'it'
			) )->execute();
			$msg = array(
				'contribution_tracking_id' => 48987654,
				'currency' => 'EUR',
				'date' => 1443724034,
				'email' => 'lovelyspam@python.com',
				'gateway' => 'globalcollect',
				'gateway_txn_id' => '1111662235',
				'gross' => 15.00,
				'payment_method' => 'cc',
				'payment_submethod' => 'visa',
			);
			$contribution = wmf_civicrm_contribution_message_import( $msg );
		}
		$this->contact_ids[] = $contribution['contact_id'];
	}

	public function tearDown() {
		foreach( $this->contact_ids as $contact_id ) {
			$this->cleanUpContact( $contact_id );
		}
	}

	public function auditTestProvider() {
		return array(
			array( __DIR__ . '/data/Ingenico/donation/', array(
				'main' => array(
					array(
						'contribution_tracking_id' => '5551212',
						'country' => 'US',
						'currency' => 'USD',
						'date' => 1501368968,
						'email' => 'dutchman@flying.net',
						'first_name' => 'Arthur',
						'gateway' => 'globalcollect', // TODO: Connect donations get 'ingenico'
						'gateway_txn_id' => '987654321',
						'gross' => '3.00',
						'installment' => 1,
						'last_name' => 'Aardvark',
						'order_id' => '987654321',
						'payment_method' => 'cc',
						'payment_submethod' => 'visa',
						'user_ip' => '111.222.33.44',
						'utm_campaign' => 'ingenico_audit',
						'utm_medium' => 'ingenico_audit',
						'utm_source' => 'ingenico_audit',
						'street_address' => '1111 Fake St',
						'city' => 'Denver',
						'state_province' => 'CO',
						'postal_code' => '87654',
					),
				),
			) ),
			array( __DIR__ . '/data/Ingenico/refund/', array(
				'negative' => array(
					array(
						'date' => 1500942220,
						'gateway' => 'globalcollect',
						'gateway_parent_id' => '11992288',
						'gateway_refund_id' => '11992288',
						'gross' => '100.00',
						'gross_currency' => 'USD',
						'type' => 'refund',
					),
				),
			) ),
			array( __DIR__ . '/data/Ingenico/sparseRefund/', array(
				'negative' => array(
					array(
						'date' => 1503964800,
						'gateway' => 'globalcollect',
						'gateway_parent_id' => '1111662235',
						'gateway_refund_id' => '1111662235',
						'gross' => '15.00',
						'gross_currency' => 'EUR',
						'type' => 'refund',
					),
				),
			) ),
			array( __DIR__ . '/data/Ingenico/chargeback/', array(
				'negative' => array(
					array(
						'date' => 1495023569,
						'gateway' => 'globalcollect',
						'gateway_parent_id' => '55500002',
						'gateway_refund_id' => '55500002',
						'gross' => '200.00',
						'gross_currency' => 'USD',
						'type' => 'chargeback',
					),
				),
			) ),
		);
	}

	/**
	 * @dataProvider auditTestProvider
	 */
	public function testParseFiles( $path, $expectedMessages ) {
		variable_set( 'ingenico_audit_recon_files_dir', $path );

		$this->runAuditor();

		$this->assertEquals( $expectedMessages, self::$messages );
	}

	protected function runAuditor() {
		$options = array(
			'fakedb' => true,
			'quiet' => true,
			'test' => true,
			'test_callback' => array( 'IngenicoAuditTest', 'receiveMessages' ),
			#'verbose' => 'true', # Uncomment to debug.
		);
		$audit = new IngenicoAuditProcessor( $options );
		$audit->run();
	}

	static public function receiveMessages( $msg, $type ) {
		self::$messages[$type][] = $msg;
	}
}
