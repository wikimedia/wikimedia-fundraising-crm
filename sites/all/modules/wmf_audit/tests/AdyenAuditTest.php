<?php

use SmashPig\Core\Configuration;
use SmashPig\Core\Context;

/**
 * @group Adyen
 * @group WmfAudit
 */
class AdyenAuditTest extends BaseWmfDrupalPhpUnitTestCase {
	static protected $messages;

	protected $contact_id;
	protected $contribution_ids = array();

	public function setUp() {
		parent::setUp();
		self::$messages = array();

		$dirs = array(
			'wmf_audit_log_archive_dir' => __DIR__ . '/data/logs/',
			'adyen_audit_recon_completed_dir' => $this->getTempDir(),
			'adyen_audit_working_log_dir' => $this->getTempDir(),
		);

		foreach ( $dirs as $var => $dir ) {
			if ( !is_dir( $dir ) ) {
				mkdir( $dir );
			}
			variable_set( $var, $dir );
		}

		$old_working = glob( $dirs['adyen_audit_working_log_dir'] . '*' );
		foreach ( $old_working as $zap ) {
			if ( is_file( $zap ) ) {
				unlink( $zap );
			}
		}

		variable_set( 'adyen_audit_log_search_past_days', 7 );

		// Fakedb doesn't fake the original txn for refunds, so add one here
		$existing = wmf_civicrm_get_contributions_from_gateway_id( 'adyen', '4522268860022701' );
		if ( $existing ) {
			// Previous test run may have crashed before cleaning up
			$contribution = $existing[0];
		} else {
			$msg = array(
				'contribution_tracking_id' => 92598312,
				'currency' => 'USD',
				'date' => 1455825706,
				'email' => 'asdf@asdf.com',
				'gateway' => 'adyen',
				'gateway_txn_id' => '4522268860022701',
				'gross' => 1.00,
				'payment_method' => 'cc',
			);
			$contribution = wmf_civicrm_contribution_message_import( $msg );
		}
		$this->contact_id = $contribution['contact_id'];
		$this->contribution_ids[] = $contribution['id'];

		// and another for the chargeback
		$existing = wmf_civicrm_get_contributions_from_gateway_id( 'adyen', '4555568860022701' );
		if ( $existing ) {
			// Previous test run may have crashed before cleaning up
			$contribution = $existing[0];
		} else {
			$msg = array(
				'contribution_tracking_id' => 92598318,
				'currency' => 'USD',
				'date' => 1443724034,
				'email' => 'asdf@asdf.org',
				'gateway' => 'adyen',
				'gateway_txn_id' => '4555568860022701',
				'gross' => 1.00,
				'payment_method' => 'cc',
			);
			$contribution = wmf_civicrm_contribution_message_import( $msg );
		}
		$this->contact_id = $contribution['contact_id'];
		$this->contribution_ids[] = $contribution['id'];
	}

	public function tearDown() {
		$api = civicrm_api_classapi();
		foreach( $this->contribution_ids as $id ) {
			$api->Contribution->Delete( array(
				'id' => $id,
				'version' => 3,
			) );
		}
		$api->Contact->Delete( array(
			'id' => $this->contact_id,
			'version' => 3,
		) );
		parent::tearDown();
	}

	public function auditTestProvider() {
		return array(
			array( __DIR__ . '/data/Adyen/donation/', array(
				'main' => array(
					array(
						'contribution_tracking_id' => '33992337',
						'country' => 'US',
						'currency' => 'USD',
						'date' => 1455862251,
						'email' => 'asdf@asdf.com',
						'fee' => '0.24',
						'first_name' => 'asdf',
						'gateway' => 'adyen',
						'gateway_account' => 'TestMerchant',
						'gateway_txn_id' => '5364893193133131',
						'gross' => '1.00',
						'language' => 'en',
						'last_name' => 'asdff',
						'order_id' => '33992337.0',
						'payment_method' => 'cc',
						'payment_submethod' => 'visa',
						'user_ip' => '77.177.177.77',
						'utm_campaign' => 'C13_en.wikipedia.org',
						'utm_medium' => 'sidebar',
						'utm_source' => '..cc',
						'settled_gross' => '0.76',
						'settled_currency' => 'USD',
						'settled_fee' => '0.24',
					),
				),
			) ),
		 array( __DIR__ . '/data/Adyen/refund/', array(
				'negative' => array(
					array(
						'date' => 1455128736,
						'gateway' => 'adyen',
						'gateway_parent_id' => '4522268860022701',
						'gateway_refund_id' => '4522268869855336',
						'gross' => '1.00',
						'gross_currency' => 'USD',
						'type' => 'refund',
					),
				),
			) ),
		 array( __DIR__ . '/data/Adyen/chargeback/', array(
				'negative' => array(
					array(
						'date' => 1455128736,
						'gateway' => 'adyen',
						'gateway_parent_id' => '4555568860022701',
						'gateway_refund_id' => '4555568869855336',
						'gross' => '1.00',
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
		variable_set( 'adyen_audit_recon_files_dir', $path );

		$this->runAuditor();

		$this->assertEquals( $expectedMessages, self::$messages );
	}

	protected function runAuditor() {
		$options = array(
			'fakedb' => true,
			'quiet' => true,
			'test' => true,
			'test_callback' => array( 'AdyenAuditTest', 'receiveMessages' ),
			#'verbose' => 'true', # Uncomment to debug.
		);
		$audit = new AdyenAuditProcessor( $options );
		$audit->run();
	}

	static public function receiveMessages( $msg, $type ) {
		self::$messages[$type][] = $msg;
	}
}
