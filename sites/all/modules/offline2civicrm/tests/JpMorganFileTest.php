<?php

class JpMorganFileTest extends BaseChecksFileTest {
    protected $epochtime;
    protected $strtime;

    function setUp() {
        parent::setUp();

        require_once __DIR__ . "/includes/JpMorganFileProbe.php";

        $this->strtime = '04/02/2000';
        $this->epochtime = wmf_common_date_parse_string('2000-04-02');
        $this->setExchangeRates( $this->epochtime, array( 'USD' => 1, 'EUR' => 3 ) );
    }

    function testParseRow() {
        $data = array(
            'ACCOUNT NAME' => 'Testes EUR_Public',
            'CURRENCY' => 'EUR',
            'REFERENCE' => 'UNAVAILABLE',
            'Bank Ref Number' => '1234TEST',
            'TRANSACTION DATE' => '04/01/2000',
            'TRANSACTION TYPE' => 'FOO CREDIT RECEIVED',
            'VALUE DATE' => $this->strtime,
            'CREDITS' => '5.50',
        );
        $expected_normal = array(
            'contact_source' => 'check',
            'contact_type' => 'Individual',
            'country' => 'US',
            'currency' => 'EUR',
            'date' => 954547200,
            'direct_mail_appeal' => 'White Mail',
            'email' => 'nobody@wikimedia.org',
            'gateway_account' => 'Testes EUR_Public',
            'gateway' => 'jpmorgan',
            'gateway_txn_id' => '1234TEST',
            'gift_source' => 'Community Gift',
            'gross' => '5.50',
            'no_thank_you' => 'No Contact Details',
            'payment_instrument' => 'JP Morgan EUR',
            'restrictions' => 'Unrestricted - General',
            'settlement_date' => $this->epochtime,
        );

        $importer = new JpMorganFileProbe( "no URI" );
        $output = $importer->_parseRow( $data );

        $this->stripSourceData( $output );
        $this->assertEquals( $expected_normal, $output );
    }

    function testImport() {
        //FIXME
        $_GET['q'] = '';
        //FIXME
        civicrm_initialize();

        // Clean slate.
        $contributions = wmf_civicrm_get_contributions_from_gateway_id( 'jpmorgan', '1234TEST' );
        if ( $contributions ) {
            foreach ( $contributions as $existing ) {
                $success = civicrm_api_classapi()->Contribution->Delete( array(
                    'id' => $existing['id'],
                    'version' => 3,
                ) );
                $this->assertTrue( $success );
            }
        }

        $this->setExchangeRates( wmf_common_date_parse_string( '2000-04-01' ), array( 'USD' => 1, 'EUR' => 3 ) );

        $importer = new JpMorganFileProbe( __DIR__ . "/data/jpmorgan.csv" );
        $importer->import();

        $contribution = wmf_civicrm_get_contributions_from_gateway_id( 'jpmorgan', '1234TEST' );
        $this->assertEquals( 1, count( $contribution ) );
        $this->assertEquals( $contribution[0]['trxn_id'], 'JPMORGAN 1234TEST' );
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
