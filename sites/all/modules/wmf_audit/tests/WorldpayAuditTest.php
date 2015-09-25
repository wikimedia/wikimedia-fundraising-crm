<?php

function worldpay_audit_watchdog( $entry ) {
    WorldpayAuditTest::receiveLogline( $entry );
}

/**
 * @group WmfAudit
 * @group Worldpay
 */
class WorldpayAuditTest extends BaseWmfDrupalPhpUnitTestCase {
    static protected $messages;
    static protected $loglines;

    public static function getInfo() {
        return array(
            'name' => 'Worldpay Audit',
            'group' => 'Audit',
            'description' => 'Parse audit files and match with logs.',
        );
    }

    public function setUp() {
	parent::setUp();
	self::$messages = array();
	$dirs = array(
	    'wmf_audit_log_archive_dir' => __DIR__ . '/data/logs',
	    'worldpay_audit_recon_completed_dir' => $this->getTempDir() . '/completed',
	    'worldpay_audit_working_log_dir' => $this->getTempDir() . '/working',
	);

	foreach ( $dirs as $var => $dir ) {
	    if ( !is_dir( $dir ) ) {
		mkdir( $dir );
	    }
	    variable_set( $var, $dir );
	}

	variable_set( 'worldpay_audit_log_search_past_days', 7 );
    }

    public function auditTestProvider() {
	return array(
	    array( __DIR__ . '/data/TransactionReconciliationFile', array(
		'main' => array(
		    array(
		      'utm_source' => 'worldpay_audit',
		      'utm_medium' => 'worldpay_audit',
		      'utm_campaign' => 'worldpay_audit',
		      'date' => 1411723740,
		      'gateway_txn_id' => '19330306.0',
		      'gross' => '1',
		      'user_ip' => '1.2.3.4',
		      'first_name' => 'Test',
		      'last_name' => 'PErson',
		      'country' => 'FR',
		      'email' => 'fr-tech+testperson@wikimedia.org',
		      'contribution_tracking_id' => '19330306',
		      'currency' => 'EUR',
		      'gateway' => 'worldpay',
		      'payment_method' => 'cc',
		      'payment_submethod' => 'visa',
		    ),
		    array(
		      'utm_source' => 'worldpay_audit',
		      'utm_medium' => 'worldpay_audit',
		      'utm_campaign' => 'worldpay_audit',
		      'date' => 1411729200,
		      'gateway_txn_id' => '19343862.0',
		      'gross' => '1.33',
		      'user_ip' => '2.3.4.5',
		      'first_name' => 'Other',
		      'last_name' => 'Friend',
		      'country' => 'FR',
		      'email' => 'fr-tech+other@wikimedia.org',
		      'contribution_tracking_id' => '19343862',
		      'currency' => 'EUR',
		      'gateway' => 'worldpay',
		      'payment_method' => 'cc',
		      'payment_submethod' => 'visa',
		    ),
		),
	    ), array() ),
	    /* FIXME: broken, see T113782
	     * array( __DIR__ . '/data/LynkReconciliationFile/', array(
	     *   'main' => array(
	     *     array(
	     *       'utm_source' => 'worldpay_audit',
	     *       'utm_medium' => 'worldpay_audit',
	     *       'utm_campaign' => 'worldpay_audit',
	     *       'date' => 1409263836,
	     *       'gateway_txn_id' => '50555555',
	     *       'gross' => '1',
	     *       'user_ip' => '5.4.3.2',
	     *       'first_name' => 'Bøld',
	     *       'last_name' => 'Bot?ton',
	     *       'street_address' => '123 Sesame St',
	     *       'postal_code' => '02480',
	     *       'country' => 'US',
	     *       'email' => 'fr-tech+testbold@wikimedia.org',
	     *       'contribution_tracking_id' => '18955555',
	     *       'currency' => 'USD',
	     *       'gateway' => 'worldpay',
	     *       'payment_method' => 'cc',
	     *       'payment_submethod' => 'visa',
	     *     ),
	     *   ),
	     * ), array() ),
	     */
	);
    }

    /**
     * @dataProvider auditTestProvider
     */
    public function testParseFiles( $path, $expectedMessages, $expectedLoglines ) {
	variable_set( 'worldpay_audit_recon_files_dir', $path );

	$this->runAuditor();

	$this->assertEquals( $expectedMessages, self::$messages );
	$this->assertLoglinesPresent( $expectedLoglines );
    }

    protected function runAuditor() {
	$options = array(
	    'fakedb' => true,
	    'quiet' => true,
	    'test' => true,
	    'test_callback' => array( 'WorldpayAuditTest', 'receiveMessages' ),
	    #'verbose' => 'true', # Uncomment to debug.
	);
	$audit = new WorldpayAuditProcessor( $options );
	$audit->run();
    }

    protected function assertLoglinesPresent( $expectedLines ) {
	$notFound = array();

	foreach ( $expectedLines as $expectedEntry ) {
	    foreach ( self::$loglines as $entry ) {
		if ( $entry['type'] === $expectedEntry['type']
		    && $entry['message'] === $expectedEntry['message'] )
		{
		    // Skip to next expected line.
		    continue 2;
		}
	    }
	    // Not found.
	    $notFound[] = $expectedEntry;
	}
	if ( $notFound ) {
	    $this->fail( "Did not see these loglines, " . json_encode( $notFound ) );
	}
    }

    static public function receiveMessages( $msg, $type ) {
	self::$messages[$type][] = $msg;
    }

    static public function receiveLogline( $entry ) {
	self::$loglines[] = $entry;
    }
}
