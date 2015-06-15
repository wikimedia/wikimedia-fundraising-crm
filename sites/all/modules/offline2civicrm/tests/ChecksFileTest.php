<?php

class ChecksFileTest extends BaseChecksFileTest {
    function setUp() {
        parent::setUp();

        require_once __DIR__ . "/includes/ChecksFileProbe.php";
    }

	/**
	 * @expectedException EmptyRowException
	 */
    function testEmptyRow() {
		// A few kinds of empty.
        $data = array(
            'Orignal Currency' => '',
            '' => '700',
            '' => '',
        );

        $importer = new ChecksFileProbe( "no URI" );
        $output = $importer->_parseRow( $data );
    }
}
