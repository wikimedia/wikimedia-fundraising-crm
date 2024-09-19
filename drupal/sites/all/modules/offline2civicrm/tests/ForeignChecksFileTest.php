<?php

require_once __DIR__ . "/includes/BaseChecksFileTest.php";

/**
 * @group Import
 * @group Offline2Civicrm
 */
class ForeignChecksFileTest extends BaseChecksFileTest {

  public function setUp(): void {
    parent::setUp();
    $this->epochtime = strtotime('2017-02-28');
    $this->setExchangeRates($this->epochtime, array('USD' => 1, 'GBP' => 2));

    require_once __DIR__ . "/includes/ForeignChecksFileProbe.php";
  }

  public function testParseRow(): void {
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
      'gateway_txn_id' => 'd59d0c548641c95784e294c8537a30cd',
      'financial_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Cash'),
      'restrictions' => 'Unrestricted - General'
    );

    $importer = new ForeignChecksFileProbe();
    $output = $importer->_parseRow($data);

    $this->stripSourceData($output);
    $this->assertEquals($expected_normal, $output);
  }

  /**
   * Test that all imports fail if the organization does not pre-exist.
   *
   * @throws \League\Csv\Exception
   */
  public function testImportForeignCheckes(): void {
    $importer = new ForeignChecksFile($this->getCsvDirectory() . "foreign_checks_trilogy.csv");
    $importer->import();
    $messages = $importer->getMessages();
    $this->assertEquals('All rows were imported', $messages['Result']);
  }

}
