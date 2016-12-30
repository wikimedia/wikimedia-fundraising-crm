<?php

/**
 * @group Import
 * @group Offline2Civicrm
 */
class CoinBaseTest extends BaseChecksFileTest {
    protected $epochtime;

    function setUp() {
        parent::setUp();

        $this->epochtime = wmf_common_date_parse_string('2016-09-15');
        $this->setExchangeRates($this->epochtime, array('USD' => 1, 'BTC' => 3));
        $this->gateway = 'coinbase';
    }

    function testImport() {
        civicrm_initialize();
        $this->trxn_id = 'Pluto';
        $this->doCleanUp();

        $importer = new CoinbaseFile( __DIR__ . "/data/coinbase.csv" );
        $importer->import();

        $contribution = wmf_civicrm_get_contributions_from_gateway_id($this->gateway, $this->trxn_id);
        $this->assertEquals(1, count($contribution));
        $this->assertEquals('COINBASE PLUTO', $contribution[0]['trxn_id']);
        $this->assertEquals('online', db_query("SELECT {utm_medium} from {contribution_tracking} WHERE contribution_id = {$contribution[0]['id']}")->fetchField());
    }

}
