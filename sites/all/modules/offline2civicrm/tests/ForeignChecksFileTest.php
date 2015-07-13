<?php

require_once __DIR__ . "/includes/BaseChecksFileTest.php";

/**
 * @group Import
 * @group Offline2Civicrm
 */
class ForeignChecksFileTest extends BaseChecksFileTest {
    function setUp() {
        parent::setUp();

        require_once __DIR__ . "/includes/ForeignChecksFileProbe.php";
    }

    function testParseRow() {
        $data = array(
            'Batch Number' => '1234',
            'Original Amount' => '50.00',
            'Original Currency' => 'GBP',
            'Received Date' => '4/1/14',
            'Payment Instrument' => 'Check',
            'Check Number' => '2020',
            'First Name' => 'Gen',
            'Last Name' => 'Russ',
            'Street Address' => '1000 Markdown Markov',
            'Additional Address' => '',
            'City' => 'Chocolate City',
            'State' => 'ND',
            'Postal Code' => '13131',
            'Country' => 'Nonexistent Rock Candy Country',
            'Email' => '',
            'Phone' => '',
            'Thank You Letter Date' => '',
            'No Thank You' => '',
            'Direct Mail Appeal' => '',
            'AC Flag' => '',
            'Restrictions' => '',
            'Gift Source' => '',
            'Notes' => '',
        );
        $expected_normal = array(
            'check_number' => '2020',
            'city' => 'Chocolate City',
            'country' => 'Nonexistent Rock Candy Country',
            'first_name' => 'Gen',
            'last_name' => 'Russ',
            'gateway' => 'check',
            'gross' => '50.00',
            'currency' => 'GBP',
            'payment_method' => 'Check',
            'postal_code' => '13131',
            'date' => 1396310400,
            'state_province' => 'ND',
            'street_address' => '1000 Markdown Markov',
            'contact_source' => 'check',
            'contact_type' => 'Individual',
            'gateway_txn_id' => '3333f8fd5703c6a319c4a9d2b5a2d8c6',
        );

        $importer = new ForeignChecksFileProbe( "null URI" );
        $output = $importer->_parseRow( $data );

        $this->stripSourceData( $output );
        $this->assertEquals( $expected_normal, $output );
    }
}
