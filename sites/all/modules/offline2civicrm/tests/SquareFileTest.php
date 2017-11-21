<?php

/**
 * @group Import
 * @group Offline2Civicrm
 */
class SquareFileTest extends BaseChecksFileTest {

  function setUp() {
    parent::setUp();

    require_once __DIR__ . "/includes/SquareFileProbe.php";
  }

  function testParseRow() {
    $data = array(
      'Currency' => 'USD',
      'Email Address' => 'Max@gmail.com',
      'Gross Amount' => '$35.00',
      'Name' => 'Max Normal',
      'Net Amount' => '$35.00',
      'Payment ID' => 'abc123',
      'Phone Number' => '3333333333',
      'Status' => 'Completed',
      'Timestamp' => 1426129877,
      'Zip Code' => '94103',
    );
    $expected_normal = array(
      'contact_source' => 'check',
      'contact_type' => 'Individual',
      'contribution_type' => 'cash',
      'country' => 'US',
      'currency' => 'USD',
      'date' => 1426129877,
      'email' => 'Max@gmail.com',
      'first_name' => 'Max',
      'full_name' => 'Max Normal',
      'gateway' => 'square',
      'gateway_status_raw' => 'Completed',
      'gateway_txn_id' => 'abc123',
      'gross' => '35.00',
      'last_name' => 'Normal',
      'net' => '35.00',
      'phone' => '3333333333',
      'postal_code' => '94103',
    );

    $importer = new SquareFileProbe("no URI");
    $output = $importer->_parseRow($data);

    $this->stripSourceData($output);
    $this->assertEquals($expected_normal, $output);
  }

  function testParseRow_Refund() {
    $data = array(
      'Currency' => 'USD',
      'Email Address' => 'Max@gmail.com',
      'Gross Amount' => '$35.00',
      'Name' => 'Max Normal',
      'Net Amount' => '$0',
      'Payment ID' => 'abc123',
      'Phone Number' => '3333333333',
      'Status' => 'Refunded',
      'Timestamp' => 1426129877,
      'Zip Code' => '94103',
    );
    $expected_normal = array(
      'contact_source' => 'check',
      'contact_type' => 'Individual',
      'contribution_type' => 'cash',
      'country' => 'US',
      'currency' => 'USD',
      'date' => 1426129877,
      'email' => 'Max@gmail.com',
      'first_name' => 'Max',
      'full_name' => 'Max Normal',
      'gateway' => 'square',
      'gateway_status_raw' => 'Refunded',
      'gateway_txn_id' => 'abc123',
      'gross' => '35.00',
      'last_name' => 'Normal',
      'net' => '35.00',
      'phone' => '3333333333',
      'postal_code' => '94103',
    );

    $importer = new SquareFileProbe("no URI");
    $output = $importer->_parseRow($data);

    $this->stripSourceData($output);
    $this->assertEquals($expected_normal, $output);
  }
}
