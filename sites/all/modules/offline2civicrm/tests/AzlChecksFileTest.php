<?php

class AzlChecksFileTest extends BaseChecksFileTest {
    function setUp() {
        parent::setUp();

        require_once __DIR__ . "/includes/AzlChecksFileProbe.php";
    }

    function testParseRow_Individual() {
        $data = array(
            'Batch' => '1234',
            'Contribution Type' => 'Arizona Lockbox',
            'Total Amount' => '50',
            'Source' => 'USD 50.00',
            'Postmark Date' => '',
            'Received Date' => '4/1/14',
            'Payment Instrument' => 'Check',
            'Check Number' => '2020',
            'Restrictions' => 'Unrestricted - General',
            'Gift Source' => 'Community Gift',
            'Direct Mail Appeal' => 'White Mail',
            'Prefix' => 'Mrs.',
            'First Name' => 'Sub',
            'Last Name' => 'Tell',
            'Suffix' => '',
            'Street Address' => '1000 Markdown Markov',
            'Additional Address 1' => '',
            'Additional Address 2' => '',
            'City' => 'Best St. Louis',
            'State' => 'MA',
            'Postal Code' => '2468',
            'Country' => '',
            'Phone' => '(123) 456-0000',
            'Email' => '',
            'Thank You Letter Date' => '5/1/14',
            'AC Flag' => 'Y',
        );
        $expected_normal = array(
            'check_number' => '2020',
            'city' => 'Best St. Louis',
            'contact_source' => 'check',
            'contact_type' => 'Individual',
            'contribution_source' => 'USD 50.00',
            'country' => 'US',
            'currency' => 'USD',
            'date' => 1396335600,
            'direct_mail_appeal' => 'White Mail',
            'email' => 'nobody@wikimedia.org',
            'first_name' => 'Sub',
            'gateway' => 'arizonalockbox',
            'gateway_txn_id' => 'e59ed825ea04516fb2abf1c130d47525',
            'gift_source' => 'Community Gift',
            'gross' => '50.00',
            'import_batch_number' => '1234',
            'last_name' => 'Tell',
            'original_currency' => 'USD',
            'original_gross' => '50.00',
            'payment_method' => 'Check',
            'postal_code' => '02468',
            'raw_contribution_type' => 'Arizona Lockbox',
            'restrictions' => 'Unrestricted - General',
            'state_province' => 'MA',
            'street_address' => '1000 Markdown Markov',
            'thankyou_date' => 1398927600,
        );

        $importer = new AzlChecksFileProbe( "null URI" );
        $output = $importer->_parseRow( $data );

        $this->stripSourceData( $output );
        $this->assertEquals( $expected_normal, $output );
    }

    function testParseRow_Organization() {
        $data = array(
            'Batch' => '1235',
            'Contribution Type' => 'Arizona Lockbox',
            'Total Amount' => '51',
            'Source' => 'USD 51.00',
            'Postmark Date' => '',
            'Received Date' => '4/1/14',
            'Payment Instrument' => 'Check',
            'Check Number' => '202000001',
            'Restrictions' => 'Restricted-Foundation',
            'Gift Source' => 'Foundation Gift',
            'Direct Mail Appeal' => 'White Mail',
            'Organization Name' => 'One Pacific Entitlement',
            'Street Address' => '1000 Markdown Markov',
            'Additional Address 1' => '',
            'Additional Address 2' => '',
            'City' => 'Best St. Louis',
            'State' => 'MA',
            'Postal Code' => '123-LAX',
            'Country' => 'FR',
            'Phone' => '+357 (123) 456-0000',
            'Email' => '',
            'Thank You Letter Date' => '5/1/14',
            'AC Flag' => '',
        );
        $expected_normal = array(
            'check_number' => '202000001',
            'city' => 'Best St. Louis',
            'contact_source' => 'check',
            'contact_type' => 'Organization',
            'contribution_source' => 'USD 51.00',
            'country' => 'FR',
            'currency' => 'USD',
            'date' => 1396335600,
            'direct_mail_appeal' => 'White Mail',
            'email' => 'nobody@wikimedia.org',
            'gateway' => 'arizonalockbox',
            'gateway_txn_id' => '6dbb8d844c7509076e2a275fb76d0130',
            'gift_source' => 'Foundation Gift',
            'gross' => 51.00,
            'import_batch_number' => '1235',
            'organization_name' => 'One Pacific Entitlement',
            'original_currency' => 'USD',
            'original_gross' => 51.00,
            'payment_method' => 'Check',
            'postal_code' => '123-LAX',
            'raw_contribution_type' => 'Arizona Lockbox',
            'restrictions' => 'Restricted-Foundation',
            'state_province' => 'MA',
            'street_address' => '1000 Markdown Markov',
            'thankyou_date' => 1398927600,
        );

        $importer = new AzlChecksFileProbe( "null URI" );
        $output = $importer->_parseRow( $data );

        $this->stripSourceData( $output );
        $this->assertEquals( $expected_normal, $output );
    }
}
