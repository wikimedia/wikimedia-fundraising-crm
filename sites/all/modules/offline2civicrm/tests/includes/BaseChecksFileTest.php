<?php

class BaseChecksFileTest extends BaseWmfDrupalPhpUnitTestCase {
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
}
