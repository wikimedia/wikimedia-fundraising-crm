<?php

class JpMorganFileTest extends BaseChecksFileTest {
    function setUp() {
        parent::setUp();

        require_once __DIR__ . "/includes/JpMorganFileProbe.php";
    }

    function testParseRow() {
        $data = array(
            'ACCOUNT NAME' => 'Testes EUR_Public',
            'CURRENCY' => 'EUR',
            'REFERENCE' => 'UNAVAILABLE',
            'Bank Ref Number' => '1234TEST',
            'TRANSACTION DATE' => '04/01/2000',
            'TRANSACTION TYPE' => 'FOO CREDIT RECEIVED',
            'VALUE DATE' => '04/02/2000',
            'CREDITS' => '5.50',
        );
        $expected_normal = array(
            'contact_type' => 'Individual',
            'date' => 954576000,
            'direct_mail_appeal' => 'White Mail',
            'email' => 'nobody@wikimedia.org',
            'gateway_account' => 'Testes EUR_Public',
            'gateway' => 'jpmorgan',
            'gateway_txn_id' => '1234TEST',
            'gift_source' => 'Community Gift',
            //'gross' => 7.1874
            'no_thank_you' => 'No Contact Details',
            'original_currency' => 'EUR',
            'original_gross' => '5.50',
            'payment_instrument' => 'JP Morgan EUR',
            'restrictions' => 'Unrestricted - General',
            'settlement_date' => 954662400,
        );

        $importer = new JpMorganFileProbe( "no URI" );
        $output = $importer->_parseRow( $data );

        // FIXME: exchange rate conversion cannot be mocked yet, so just make sure it is present
        $this->assertTrue( $output['gross'] > 0 );
        unset( $output['gross'] );

        $this->stripSourceData( $output );
        $this->assertEquals( $expected_normal, $output );
    }

    function testImport() {
        //FIXME
        $_GET['q'] = '';
        //FIXME
        civicrm_initialize();

        $importer = new JpMorganFileProbe( __DIR__ . "/data/jpmorgan.csv" );
        $importer->import();

        $contribution = wmf_civicrm_get_contributions_from_gateway_id( 'jpmorgan', '1234TEST' );
        $this->assertEquals( 1, count( $contribution ) );
        $this->assertEquals( $contribution[0]['trxn_id'], 'JPMORGAN 1234TEST 1399363947' );
    }

    /**
     * @expectedException WmfException
     * @expectedExceptionCode WMFException::INVALID_FILE_FORMAT
     * @expectedExceptionMessage Duplicate column headers: CURRENCY, reference
     */
    function testImportDuplicateHeaders() {
        //FIXME
        $_GET['q'] = '';
        //FIXME
        civicrm_initialize();

        $importer = new JpMorganFileProbe( __DIR__ . "/data/duplicate_header.csv" );
        $importer->import();
    }
}
