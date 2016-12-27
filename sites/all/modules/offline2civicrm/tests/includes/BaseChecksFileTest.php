<?php

class BaseChecksFileTest extends BaseWmfDrupalPhpUnitTestCase {
  /**
   * Gateway.
   *
   * eg. jpmorgan, paypal etc.
   *
   * @var string
   */
    protected $gateway;

  /**
   * Transaction id being worked with. This is combined with the gateway for the civi trxn_id.
   *
   * @var string
   */
    protected $trxn_id;
    /**
     * Test and remove some dynamic fields, to simplify test fixtures.
     */
    function stripSourceData( &$msg ) {
        $this->assertEquals( 'direct', $msg['source_type'] );
        $importerClass = str_replace( 'Test', 'Probe', get_class( $this ) );
        $this->assertEquals( "Offline importer: {$importerClass}", $msg['source_name'] );
        $this->assertNotNull( $msg['source_host'] );
        $this->assertGreaterThan( 0, $msg['source_run_id'] );
        $this->assertNotNull( $msg['source_version'] );
        $this->assertGreaterThan( 0, $msg['source_enqueued_time'] );

        unset( $msg['source_type'] );
        unset( $msg['source_name'] );
        unset( $msg['source_host'] );
        unset( $msg['source_run_id'] );
        unset( $msg['source_version'] );
        unset( $msg['source_enqueued_time'] );
    }

    /**
     * Clean up transactions from previous test runs.
     */
    function doCleanUp() {
      $contributions = wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $this->trxn_id);
      if ($contributions) {
        foreach ($contributions as $contribution) {
          $this->callAPISuccess('Contribution', 'delete', array('id' => $contribution['id']));
        }
      }
    }
}
